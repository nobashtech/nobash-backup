<?php
/**
 * Durable incremental backup-chain repository.
 *
 * @package Nobash\Backup
 */
namespace Nobash\Backup\Incremental;

use Nobash\Backup\Archive\ArchiveFingerprint;

defined( 'ABSPATH' ) || exit;

/** Store and verify full/incremental/differential restore points. */
class ChainRepository {
	/** @return string */
	private function chains_table() { global $wpdb; return $wpdb->prefix . 'nobash_backup_chains'; }
	/** @return string */
	private function points_table() { global $wpdb; return $wpdb->prefix . 'nobash_restore_points'; }

	/** @return string */
	public function create_chain( $profile = 'full' ) {
		global $wpdb;
		$id = wp_generate_uuid4();
		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Plugin-owned table via $wpdb->insert().
		$wpdb->insert( $this->chains_table(), array(
			'chain_id' => $id, 'profile' => sanitize_key( (string) $profile ), 'status' => 'active',
			'base_point_id' => '', 'latest_point_id' => '', 'point_count' => 0,
			'created_at' => $now, 'updated_at' => $now,
		), array( '%s','%s','%s','%s','%s','%d','%s','%s' ) );
		if ( empty( $wpdb->last_error ) && $wpdb->insert_id ) { return $id; }
		throw new \RuntimeException( esc_html__( 'Could not create the backup chain.', 'nobash-backup' ) );
	}

	/** @return array<string,mixed>|null */
	public function get_chain( $chain_id ) {
		global $wpdb;
		$table = $this->chains_table();
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE chain_id = %s', $table, (string) $chain_id ),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return is_array( $row ) ? $row : null;
	}

	/** Find the newest active chain with a verified base for a profile. @return array<string,mixed>|null */
	public function latest_active_chain( $profile ) {
		global $wpdb;
		$table = $this->chains_table();
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM %i WHERE profile=%s AND status='active' AND base_point_id<>'' ORDER BY updated_at DESC,id DESC LIMIT 1", $table, sanitize_key( (string) $profile ) ),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return is_array( $row ) ? $row : null;
	}

	/** @return array<string,mixed>|null */
	public function latest_point( $chain_id ) {
		$chain = $this->get_chain( $chain_id );
		return $chain && ! empty( $chain['latest_point_id'] ) ? $this->get_point( $chain['latest_point_id'] ) : null;
	}

	/** @return array<string,mixed>|null */
	public function get_point( $point_id ) {
		global $wpdb;
		$table = $this->points_table();
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE point_id = %s', $table, (string) $point_id ),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( ! is_array( $row ) ) { return null; }
		$row['meta'] = json_decode( (string) $row['meta_json'], true );
		if ( ! is_array( $row['meta'] ) ) { $row['meta'] = array(); }
		return $row;
	}

	/** Register a verified published restore point. @return string */
	public function add_point( $chain_id, $type, $filename, $sha256, array $meta = array(), $parent_point_id = '' ) {
		global $wpdb;
		$chain = $this->get_chain( $chain_id );
		if ( ! $chain || 'active' !== $chain['status'] ) { throw new \RuntimeException( esc_html__( 'The backup chain is unavailable.', 'nobash-backup' ) ); }
		$type = sanitize_key( (string) $type );
		if ( ! in_array( $type, array( 'full','incremental','differential' ), true ) ) { throw new \InvalidArgumentException( 'Invalid restore point type.' ); }
		if ( 'full' !== $type && '' === $parent_point_id ) { throw new \RuntimeException( esc_html__( 'Incremental restore points require a verified parent.', 'nobash-backup' ) ); }
		$filename = basename( (string) $filename );
		$sha256 = strtolower( (string) $sha256 );
		$points_table = $this->points_table();
		$wpdb->last_error = '';
		$existing = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE chain_id=%s AND filename=%s ORDER BY id ASC LIMIT 1', $points_table, (string) $chain_id, $filename ),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( is_array( $existing ) ) {
			if ( (string) $existing['point_type'] !== $type
				|| (string) $existing['parent_point_id'] !== (string) $parent_point_id
				|| ! hash_equals( strtolower( (string) $existing['sha256'] ), $sha256 ) ) {
				throw new \RuntimeException( esc_html__( 'The published archive conflicts with an existing restore point.', 'nobash-backup' ) );
			}
			$point_id = (string) $existing['point_id'];
			// Repair a worker death between the point insert and chain summary update.
			$count = (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE chain_id=%s', $points_table, (string) $chain_id )
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$update = array( 'point_count' => $count, 'updated_at' => current_time( 'mysql', true ) );
			if ( empty( $chain['latest_point_id'] ) || (string) $chain['latest_point_id'] === (string) $parent_point_id ) { $update['latest_point_id'] = $point_id; }
			if ( 'full' === $type && empty( $chain['base_point_id'] ) ) { $update['base_point_id'] = $point_id; }
			$wpdb->last_error = '';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table via $wpdb->update().
			$reconciled = $wpdb->update( $this->chains_table(), $update, array( 'chain_id' => $chain_id ) );
			if ( false === $reconciled || ! empty( $wpdb->last_error ) ) { throw new \RuntimeException( esc_html__( 'Could not reconcile the backup chain checkpoint.', 'nobash-backup' ) ); }
			return $point_id;
		}
		$point_id = wp_generate_uuid4();
		$now = current_time( 'mysql', true );
		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Plugin-owned table via $wpdb->insert().
		$ok = $wpdb->insert( $points_table, array(
			'point_id'=>$point_id, 'chain_id'=>$chain_id, 'parent_point_id'=>(string)$parent_point_id,
			'point_type'=>$type, 'filename'=>$filename, 'sha256'=>$sha256,
			'status'=>'verified', 'meta_json'=>wp_json_encode($meta), 'created_at'=>$now,
		), array('%s','%s','%s','%s','%s','%s','%s','%s','%s') );
		if ( false === $ok ) { throw new \RuntimeException( esc_html__( 'Could not register the restore point.', 'nobash-backup' ) ); }
		$update = array( 'latest_point_id'=>$point_id, 'point_count'=>(int)$chain['point_count']+1, 'updated_at'=>$now );
		if ( 'full' === $type && empty( $chain['base_point_id'] ) ) { $update['base_point_id'] = $point_id; }
		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table via $wpdb->update().
		$updated = $wpdb->update( $this->chains_table(), $update, array( 'chain_id'=>$chain_id ) );
		if ( false === $updated || ! empty( $wpdb->last_error ) ) { throw new \RuntimeException( esc_html__( 'Could not update the backup chain checkpoint.', 'nobash-backup' ) ); }
		return $point_id;
	}

	/** Return the ordered restore path and fail closed on a broken chain. @return array<int,array<string,mixed>> */
	public function restore_plan( $point_id, \Nobash\Backup\Storage\LocalStorage $storage ) {
		$seen = array(); $reverse = array(); $current = $this->get_point( $point_id );
		while ( $current ) {
			$id = (string) $current['point_id'];
			if ( isset( $seen[$id] ) ) { throw new \RuntimeException( esc_html__( 'The backup chain contains a cycle.', 'nobash-backup' ) ); }
			$seen[$id] = true;
			$path = $storage->resolve_archive( (string) $current['filename'] );
			if ( ! $path || ! is_readable( $path ) ) { throw new \RuntimeException( esc_html__( 'A required backup-chain archive is missing.', 'nobash-backup' ) ); }
			$sha = ArchiveFingerprint::value( $path );
			if ( ! hash_equals( strtolower((string)$current['sha256']), strtolower($sha) ) ) { throw new \RuntimeException( esc_html__( 'A required backup-chain archive failed checksum verification.', 'nobash-backup' ) ); }
			$reverse[] = $current;
			if ( 'full' === $current['point_type'] ) { break; }
			$current = $this->get_point( (string) $current['parent_point_id'] );
		}
		if ( empty( $reverse ) || 'full' !== end( $reverse )['point_type'] ) { throw new \RuntimeException( esc_html__( 'The backup chain has no valid full base.', 'nobash-backup' ) ); }
		return array_reverse( $reverse );
	}

	/** Whether the chain should roll over to a new full base. */
	public function should_compact( array $chain ) {
		$limit = max( 2, min( 100, (int) apply_filters( 'nobash_backup_incremental_max_chain_points', 30, $chain ) ) );
		return (int) ( $chain['point_count'] ?? 0 ) >= $limit;
	}

	/** Retire older chains only after a new verified full base is published. */
	public function supersede_other_chains( $chain_id, $profile ) {
		global $wpdb;
		$table = $this->chains_table();
		$now = current_time( 'mysql', true );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status='superseded', updated_at=%s WHERE profile=%s AND chain_id<>%s AND status='active' AND base_point_id<>''",
				$table, $now, sanitize_key( (string) $profile ), (string) $chain_id
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Retire a one-point full chain after its verified archive has been moved to cloud-only storage.
	 * This is intentionally strict so a local incremental/differential dependency can never be removed.
	 *
	 * @param string $chain_id Chain UUID.
	 * @param string $point_id Restore point UUID.
	 * @return bool
	 */
	public function retire_cloud_only_full( $chain_id, $point_id ) {
		global $wpdb;
		$chain_id = sanitize_text_field( (string) $chain_id );
		$point_id = sanitize_text_field( (string) $point_id );
		if ( '' === $chain_id || '' === $point_id ) { return false; }

		$chain = $this->get_chain( $chain_id );
		$point = $this->get_point( $point_id );
		if ( ! $chain || ! $point ) { return false; }
		if ( (string) $point['chain_id'] !== $chain_id || 'full' !== (string) $point['point_type'] ) { return false; }
		if ( (int) $chain['point_count'] !== 1 || (string) $chain['base_point_id'] !== $point_id || (string) $chain['latest_point_id'] !== $point_id ) { return false; }

		$points = $this->points_table();
		$chains = $this->chains_table();
		$snapshots = $wpdb->prefix . 'nobash_file_snapshots';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Plugin-owned tables; transaction control statements are fixed literals/ternary of two fixed literals, not user input.
		$wpdb->query( 'START TRANSACTION' );
		$snapshots_ok = false !== $wpdb->delete( $snapshots, array( 'point_id' => $point_id ), array( '%s' ) );
		$point_ok = false !== $wpdb->delete( $points, array( 'point_id' => $point_id, 'chain_id' => $chain_id ), array( '%s', '%s' ) );
		$chain_ok = false !== $wpdb->delete( $chains, array( 'chain_id' => $chain_id ), array( '%s' ) );
		$ok = $snapshots_ok && $point_ok && $chain_ok && empty( $wpdb->last_error );
		if ( $ok ) {
			$wpdb->query( 'COMMIT' );
		} else {
			$wpdb->query( 'ROLLBACK' );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return $ok;
	}

	/** Mark a chain unusable when a required point disappears or is corrupt. */
	public function mark_broken( $chain_id, $reason ) {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table via $wpdb->update().
		$wpdb->update( $this->chains_table(), array( 'status'=>'broken', 'broken_reason'=>sanitize_text_field((string)$reason), 'updated_at'=>current_time('mysql',true) ), array( 'chain_id'=>(string)$chain_id ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}
}
