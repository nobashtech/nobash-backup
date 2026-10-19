<?php
/**
 * Plugin uninstall cleanup.
 *
 * @package Nobash\Backup
 */

namespace Nobash\Backup\Lifecycle;

defined( 'ABSPATH' ) || exit;

/**
 * Removes opted-in plugin data during native WordPress uninstall.
 */
final class Uninstall {
	/**
	 * Run the opted-in uninstall cleanup.
	 *
	 * Recovery archives are deliberately preserved. Administrators must remove
	 * archive files explicitly from the Backups screen or storage provider.
	 *
	 * @return void
	 */
	public static function run() {
		if ( ! is_multisite() ) { self::cleanup_site(); return; }
		// Each site's explicit retention choice applies to its own options/tables.
		for ( $offset = 0; ; $offset += 100 ) {
			$ids = get_sites( array( 'fields' => 'ids', 'number' => 100, 'offset' => $offset ) );
			foreach ( $ids as $id ) {
				switch_to_blog( (int) $id );
				try { self::cleanup_site(); } finally { restore_current_blog(); }
			}
			if ( count( $ids ) < 100 ) { break; }
		}
	}

	/** Remove only this blog's opted-in data and all of its scheduled callbacks. */
	private static function cleanup_site() {
		foreach ( array( 'nobash_backup_run_scheduled', 'nobash_backup_run_retention', 'nobash_backup_run_job_slice', 'nobash_backup_recover_stale_jobs' ) as $hook ) {
			wp_unschedule_hook( $hook );
			if ( function_exists( 'as_unschedule_all_actions' ) ) { as_unschedule_all_actions( $hook ); }
		}
		$settings = get_option( 'nobash_backup_settings', array() );
		if ( empty( $settings['delete_data_on_uninstall'] ) ) {
			return;
		}

		global $wpdb;
		$network_cleanup = ! is_multisite() || get_current_blog_id() === get_main_site_id();

		$option_keys = array(
			'nobash_backup_settings',
			'nobash_backup_db_version',
			'nobash_backup_last_run',
			'nobash_backup_active_restore',
			'nobash_backup_archive_sequence_v1',
			'nobash_backup_archive_sequence_lock_v1',
			'nobash_backup_storage_credentials',
			'nobash_backup_credential_master_key',
			'nobash_backup_transfer_token_key',
			'nobash_backup_storage_http_state',
		);

		foreach ( $option_keys as $option_key ) {
			delete_option( $option_key );
			if ( $network_cleanup ) { delete_site_option( $option_key ); }
		}

		$tables = array(
			$wpdb->prefix . 'nobash_jobs',
			$wpdb->prefix . 'nobash_job_events',
			$wpdb->prefix . 'nobash_file_inventory',
			$wpdb->prefix . 'nobash_transfers',
			$wpdb->prefix . 'nobash_schedules',
			$wpdb->prefix . 'nobash_backup_profiles',
			$wpdb->prefix . 'nobash_backup_chains',
			$wpdb->prefix . 'nobash_restore_points',
			$wpdb->prefix . 'nobash_file_snapshots',
			$wpdb->prefix . 'nobash_addons',
			$wpdb->prefix . 'nobash_audit_log',
		);

		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned uninstall table; identifier is prepared.
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
		}


	}
}
