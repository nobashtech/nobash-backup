<?php
/**
 * Safe reset operations for Nobash Backup-owned data only.
 *
 * @package Nobash\Backup
 */
namespace Nobash\Backup\System;

use Nobash\Backup\Database\Installer;
use Nobash\Backup\Security\AuditLog;
use Nobash\Backup\Security\SafeTreeRemoval;
use Nobash\Backup\Storage\LocalStorage;

 defined( 'ABSPATH' ) || exit;

class ResetManager {
	/** @return array<string,int|bool> */
	public function run( $action ) {
		$action = sanitize_key( (string) $action );
		switch ( $action ) {
			case 'temporary_data':
				return $this->temporary_data();
			case 'local_backups':
				return $this->local_backups();
			case 'plugin_settings':
				return $this->plugin_settings();
			case 'full_plugin_reset':
				$result = array_merge( $this->temporary_data(), $this->local_backups() );
				$this->truncate_plugin_tables();
				$this->delete_plugin_options();
				Installer::install();
				$result['reset'] = true;
				AuditLog::record( 'plugin_reset_completed', 'reset', 'nobash-backup' );
				return $result;
			default:
				throw new \InvalidArgumentException( esc_html__( 'Unknown reset operation.', 'nobash-backup' ) );
		}
	}

	/** @return array<string,int> */
	private function temporary_data() {
		$root = trailingslashit( LocalStorage::ensure_directory() ) . 'tmp';
		$count = $this->clear_children( $root );
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $wpdb->prefix . 'nobash_file_inventory' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table; identifier is prepared.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $wpdb->prefix . 'nobash_transfers' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table; identifier is prepared.
		AuditLog::record( 'temporary_data_reset', 'reset', 'temporary-data', array( 'items' => $count ) );
		return array( 'temporary_items' => $count );
	}

	/** @return array<string,int> */
	private function local_backups() {
		$storage = new LocalStorage();
		$count   = 0;
		foreach ( $storage->list_archives() as $archive ) {
			if ( ! empty( $archive['filename'] ) && $storage->delete_archive( (string) $archive['filename'] ) ) {
				$count++;
			}
		}
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $wpdb->prefix . 'nobash_restore_points' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table; identifier is prepared.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $wpdb->prefix . 'nobash_file_snapshots' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table; identifier is prepared.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $wpdb->prefix . 'nobash_backup_chains' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table; identifier is prepared.
		AuditLog::record( 'local_backups_reset', 'reset', 'local-backups', array( 'archives' => $count ) );
		return array( 'archives' => $count );
	}


	/** @return array<string,bool> */
	private function plugin_settings() {
		$this->delete_plugin_options();
		Installer::install();
		AuditLog::record( 'settings_reset', 'reset', 'settings' );
		return array( 'settings_reset' => true );
	}

	private function truncate_plugin_tables() {
		global $wpdb;
		$tables = array( 'nobash_jobs', 'nobash_job_events', 'nobash_file_inventory', 'nobash_transfers', 'nobash_schedules', 'nobash_backup_profiles', 'nobash_backup_chains', 'nobash_restore_points', 'nobash_file_snapshots', 'nobash_addons', 'nobash_audit_log' );
		foreach ( $tables as $suffix ) {
			$table = $wpdb->prefix . $suffix;
			$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Plugin-owned table; identifier is prepared.
		}
	}

	private function delete_plugin_options() {
		global $wpdb;
		$like = $wpdb->esc_like( 'nobash_backup_' ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Resetting plugin options via a prepared LIKE query; not cacheable.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s', $wpdb->options, $like ) );
	}

	private function clear_children( $root ) {
		if ( is_link( $root ) ) {
			throw new \RuntimeException( esc_html__( 'The temporary reset root cannot be a symbolic link.', 'nobash-backup' ) );
		}
		$root_real = realpath( $root );
		if ( false === $root_real || ! is_dir( $root_real ) ) {
			return 0;
		}
		$wp_filesystem = \Nobash\Backup\Filesystem\DirectFilesystem::get();
		$count = 0;
		$items = scandir( $root_real );
		foreach ( is_array( $items ) ? $items : array() as $item ) {
			if ( '.' === $item || '..' === $item ) { continue; }
			$path = $root_real . DIRECTORY_SEPARATOR . $item;
			if ( is_link( $path ) || is_file( $path ) ) {
				if ( ! $wp_filesystem->delete( $path, false, 'f' ) ) { throw new \RuntimeException( esc_html__( 'A temporary file could not be removed.', 'nobash-backup' ) ); }
			} elseif ( is_dir( $path ) ) {
				SafeTreeRemoval::remove( $path, $root_real );
			} elseif ( file_exists( $path ) ) {
				throw new \RuntimeException( esc_html__( 'An unsupported temporary filesystem object could not be removed.', 'nobash-backup' ) );
			}
			$count++;
		}
		return $count;
	}
}
