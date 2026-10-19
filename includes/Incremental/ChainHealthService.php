<?php
/**
 * Incremental backup-chain verification and safe repair guidance.
 *
 * @package Nobash\Backup
 */
namespace Nobash\Backup\Incremental;

use Nobash\Backup\Storage\LocalStorage;
use Nobash\Backup\Archive\ArchiveFingerprint;

defined( 'ABSPATH' ) || exit;

final class ChainHealthService {
	/** @return array<string,mixed> */
	public function verify( $chain_id ) {
		$repo  = new ChainRepository();
		$chain = $repo->get_chain( sanitize_text_field( (string) $chain_id ) );
		if ( ! $chain ) {
			throw new \RuntimeException( esc_html__( 'Backup chain not found.', 'nobash-backup' ) );
		}
		global $wpdb;
		$table = $wpdb->prefix . 'nobash_restore_points';
		$points = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE chain_id=%s ORDER BY created_at ASC,id ASC', $table, $chain['chain_id'] ),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$storage = new LocalStorage();
		$errors = array();
		$warnings = array();
		$known = array();
		$base_count = 0;
		foreach ( (array) $points as $point ) {
			$id = (string) $point['point_id'];
			$known[ $id ] = true;
			if ( 'full' === $point['point_type'] ) {
				++$base_count;
			}
			if ( 'full' !== $point['point_type'] && ( empty( $point['parent_point_id'] ) || ! isset( $known[ (string) $point['parent_point_id'] ] ) ) ) {
				$errors[] = sprintf( /* translators: %s: Restore point identifier. */ __( 'Restore point %s has no valid earlier parent.', 'nobash-backup' ), $id );
			}
			$path = $storage->resolve_archive( (string) $point['filename'] );
			if ( ! $path || ! is_readable( $path ) ) {
				$errors[] = sprintf( /* translators: %s: Archive filename. */ __( 'Required archive %s is missing.', 'nobash-backup' ), basename( (string) $point['filename'] ) );
				continue;
			}
			$sha = ArchiveFingerprint::value( $path );
			if ( ! hash_equals( strtolower( (string) $point['sha256'] ), strtolower( (string) $sha ) ) ) {
				$errors[] = sprintf( /* translators: %s: Archive filename. */ __( 'Archive %s failed checksum verification.', 'nobash-backup' ), basename( (string) $point['filename'] ) );
			}
		}
		if ( 0 === $base_count ) {
			$errors[] = __( 'The chain has no full base restore point.', 'nobash-backup' );
		} elseif ( $base_count > 1 ) {
			$warnings[] = __( 'The chain contains more than one full point. A new chain is recommended for future incremental backups.', 'nobash-backup' );
		}
		if ( (int) $chain['point_count'] !== count( (array) $points ) ) {
			$warnings[] = __( 'The stored point counter was inconsistent and can be repaired.', 'nobash-backup' );
		}
		return array(
			'chain_id' => (string) $chain['chain_id'],
			'healthy' => empty( $errors ),
			'status' => (string) $chain['status'],
			'point_count' => count( (array) $points ),
			'base_point_id' => (string) $chain['base_point_id'],
			'latest_point_id' => (string) $chain['latest_point_id'],
			'errors' => $errors,
			'warnings' => $warnings,
		);
	}

	/**
	 * Repair only metadata that can be proven from verified rows.
	 * Damaged archives remain fail-closed and force a new full baseline.
	 *
	 * @return array<string,mixed>
	 */
	public function repair( $chain_id ) {
		$result = $this->verify( $chain_id );
		global $wpdb;
		$chains = $wpdb->prefix . 'nobash_backup_chains';
		$points = $wpdb->prefix . 'nobash_restore_points';
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT point_id,point_type FROM %i WHERE chain_id=%s ORDER BY created_at ASC,id ASC', $points, $result['chain_id'] ),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$base = '';
		$latest = '';
		foreach ( (array) $rows as $row ) {
			if ( '' === $base && 'full' === $row['point_type'] ) {
				$base = (string) $row['point_id'];
			}
			$latest = (string) $row['point_id'];
		}
		$healthy = ! empty( $result['healthy'] );
		$healthy_status = 'superseded' === ( $result['status'] ?? '' ) ? 'superseded' : 'active';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$chains,
			array(
				'base_point_id' => $base,
				'latest_point_id' => $latest,
				'point_count' => count( (array) $rows ),
				'status' => $healthy ? $healthy_status : 'broken',
				'broken_reason' => $healthy ? null : sanitize_text_field( implode( ' ', array_slice( $result['errors'], 0, 3 ) ) ),
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'chain_id' => $result['chain_id'] )
		);
		$result['repaired'] = $healthy;
		$result['action'] = $healthy ? 'metadata_rebuilt' : 'new_full_baseline_required';
		return $result;
	}
}
