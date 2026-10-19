<?php
/**
 * Database installer / upgrades.
 *
 * @package Nobash\Backup
 */

namespace Nobash\Backup\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Class Installer
 */
class Installer {

	const DB_VERSION = '2.1.0';

	/**
	 * Install tables and options.
	 *
	 * @return void
	 */
	public static function install() {
		self::create_tables();
		\Nobash\Backup\Security\AuditLog::install();
		update_option( 'nobash_backup_db_version', self::DB_VERSION );

		if ( false === get_option( 'nobash_backup_settings', false ) ) {
			update_option(
				'nobash_backup_settings',
				array(
					'retention_count'   => 10,
					'retention_days'    => 30,
					'retention_min_free_mb' => 1024,
					'temporary_retention_hours' => 48,
					'exclude_paths'     => array(),
					'exclude_tables'    => array(),
					'notify_email'      => '',
					'notify_on_success' => false,
					'notify_on_failure' => true,
					'webhook_url'       => '',
					'onboarding_done'           => false,
					'delete_data_on_uninstall' => false,
					'default_profile' => 'full',
					'default_storage_provider' => 'local',
					'missed_schedule_action' => 'run_next',
					'resource_mode' => 'balanced',
					'worker_memory_mb' => 192,
					'job_slice_seconds' => 25,
					'slice_pause_ms' => 50,
					'auto_verify_archives' => true,
					'show_admin_bar_action' => true,
					'confirm_destructive_actions' => true,
					'protect_manual_backups' => false,
					'exclude_cache_directories' => true,
					'exclude_log_files' => true,
					'pause_on_high_load' => true,
					'resume_interrupted_jobs' => true,
					'require_restore_backup' => true,
					'verify_before_restore' => true,
					'restrict_downloads_to_admins' => true,
					'notify_on_warning' => true,
					'debug_logging' => false,
				)
			);
		}
	}

	/**
	 * Maybe run upgrades.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		$current = get_option( 'nobash_backup_db_version', '' );
		if ( version_compare( (string) $current, self::DB_VERSION, '<' ) ) {
			self::install();
		}
	}

	/**
	 * Create custom tables.
	 *
	 * @return void
	 */
	public static function create_tables() {
		global $wpdb;

		$jobs            = $wpdb->prefix . 'nobash_jobs';
		$events          = $wpdb->prefix . 'nobash_job_events';
		$inventory       = $wpdb->prefix . 'nobash_file_inventory';
		$transfers       = $wpdb->prefix . 'nobash_transfers';
		$schedules       = $wpdb->prefix . 'nobash_schedules';
		$profiles        = $wpdb->prefix . 'nobash_backup_profiles';
		$chains          = $wpdb->prefix . 'nobash_backup_chains';
		$points          = $wpdb->prefix . 'nobash_restore_points';
		$snapshots       = $wpdb->prefix . 'nobash_file_snapshots';
		$addons          = $wpdb->prefix . 'nobash_addons';
		$charset_collate = $wpdb->get_charset_collate();

		$jobs_sql = $wpdb->prepare( "CREATE TABLE %i (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			operation_id char(36) NOT NULL DEFAULT '',
			type varchar(32) NOT NULL DEFAULT '',
			status varchar(32) NOT NULL DEFAULT 'created',
			current_step varchar(64) NOT NULL DEFAULT 'init',
			progress tinyint(3) unsigned NOT NULL DEFAULT 0,
			attempt_count smallint(5) unsigned NOT NULL DEFAULT 0,
			max_attempts smallint(5) unsigned NOT NULL DEFAULT 5,
			available_at datetime NULL,
			started_at datetime NULL,
			completed_at datetime NULL,
			heartbeat_at datetime NULL,
			lease_owner varchar(64) NULL,
			lease_expires_at datetime NULL,
			runner varchar(32) NOT NULL DEFAULT '',
			pause_requested tinyint(1) unsigned NOT NULL DEFAULT 0,
			cancel_requested tinyint(1) unsigned NOT NULL DEFAULT 0,
			processed_items bigint(20) unsigned NOT NULL DEFAULT 0,
			total_items bigint(20) unsigned NOT NULL DEFAULT 0,
			processed_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
			total_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
			cursor_data longtext NULL,
			result_data longtext NULL,
			error_code varchar(64) NULL,
			error_message text NULL,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY operation_id (operation_id),
			KEY status_available (status,available_at),
			KEY type (type),
			KEY lease_expires (lease_expires_at),
			KEY heartbeat (heartbeat_at)
		) {$charset_collate};", $jobs );

		$inventory_sql = $wpdb->prepare( "CREATE TABLE %i (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			job_id bigint(20) unsigned NOT NULL,
			path_hash char(64) NOT NULL,
			entry_type varchar(16) NOT NULL DEFAULT 'file',
			state varchar(16) NOT NULL DEFAULT 'ready',
			absolute_path longtext NOT NULL,
			relative_path longtext NOT NULL,
			size_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
			modified_at bigint(20) unsigned NOT NULL DEFAULT 0,
			checksum_sha256 char(64) NULL,
			error_message text NULL,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY (id),
			UNIQUE KEY job_path (job_id,path_hash),
			KEY job_type_state_id (job_id,entry_type,state,id),
			KEY job_state (job_id,state)
		) {$charset_collate};", $inventory );


		$transfers_sql = $wpdb->prepare( "CREATE TABLE %i (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			transfer_id char(32) NOT NULL,
			mode varchar(24) NOT NULL DEFAULT 'upload',
			filename varchar(255) NOT NULL DEFAULT '',
			expected_size bigint(20) unsigned NOT NULL DEFAULT 0,
			received_size bigint(20) unsigned NOT NULL DEFAULT 0,
			sha256 char(64) NOT NULL DEFAULT '',
			status varchar(24) NOT NULL DEFAULT 'receiving',
			published_filename varchar(255) NOT NULL DEFAULT '',
			expires_at datetime NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY transfer_id (transfer_id),
			KEY status_expires (status,expires_at)
		) {$charset_collate};", $transfers );


		$schedules_sql = $wpdb->prepare( "CREATE TABLE %i (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			schedule_key varchar(64) NOT NULL,
			name varchar(191) NOT NULL DEFAULT '',
			enabled tinyint(1) unsigned NOT NULL DEFAULT 0,
			recurrence varchar(24) NOT NULL DEFAULT 'daily',
			profile varchar(24) NOT NULL DEFAULT 'full',
			profile_id bigint(20) unsigned NOT NULL DEFAULT 0,
			backup_mode varchar(16) NOT NULL DEFAULT 'full',
			chain_id char(36) NOT NULL DEFAULT '',
			storage_provider varchar(64) NOT NULL DEFAULT 'local',
			run_time char(5) NOT NULL DEFAULT '02:00',
			weekday tinyint(3) unsigned NOT NULL DEFAULT 0,
			monthday tinyint(3) unsigned NOT NULL DEFAULT 1,
			timezone varchar(64) NOT NULL DEFAULT 'UTC',
			overlap_policy varchar(12) NOT NULL DEFAULT 'skip',
			retention_count smallint(5) unsigned NULL,
			retention_days smallint(5) unsigned NULL,
			next_run_at datetime NULL,
			last_run_at datetime NULL,
			last_job_id bigint(20) unsigned NULL,
			last_status varchar(32) NOT NULL DEFAULT '',
			last_error text NULL,
			lock_expires_at datetime NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY schedule_key (schedule_key),
			KEY due (enabled,next_run_at),
			KEY profile_id (profile_id),
			KEY lock_expires (lock_expires_at)
		) {$charset_collate};", $schedules );

		$profiles_sql = $wpdb->prepare( "CREATE TABLE %i (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			profile_key varchar(64) NOT NULL,
			name varchar(191) NOT NULL DEFAULT '',
			content_type varchar(24) NOT NULL DEFAULT 'full',
			backup_mode varchar(16) NOT NULL DEFAULT 'full',
			include_paths_json longtext NULL,
			include_tables_json longtext NULL,
			exclusions_json longtext NULL,
			enabled tinyint(1) unsigned NOT NULL DEFAULT 1,
			is_system tinyint(1) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY profile_key (profile_key),
			KEY enabled (enabled),
			KEY is_system (is_system)
		) {$charset_collate};", $profiles );


		$chains_sql = $wpdb->prepare( "CREATE TABLE %i (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			chain_id char(36) NOT NULL,
			profile varchar(24) NOT NULL DEFAULT 'full',
			status varchar(16) NOT NULL DEFAULT 'active',
			base_point_id char(36) NOT NULL DEFAULT '',
			latest_point_id char(36) NOT NULL DEFAULT '',
			point_count int(10) unsigned NOT NULL DEFAULT 0,
			broken_reason text NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY (id), UNIQUE KEY chain_id (chain_id), KEY status (status)
		) {$charset_collate};", $chains );

		$points_sql = $wpdb->prepare( "CREATE TABLE %i (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			point_id char(36) NOT NULL, chain_id char(36) NOT NULL, parent_point_id char(36) NOT NULL DEFAULT '',
			point_type varchar(16) NOT NULL DEFAULT 'full', filename varchar(255) NOT NULL, sha256 char(64) NOT NULL,
			status varchar(16) NOT NULL DEFAULT 'verified', meta_json longtext NULL, created_at datetime NOT NULL,
			PRIMARY KEY (id), UNIQUE KEY point_id (point_id), KEY chain_created (chain_id,created_at), KEY parent_point (parent_point_id)
		) {$charset_collate};", $points );

		$snapshots_sql = $wpdb->prepare( "CREATE TABLE %i (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT, point_id char(36) NOT NULL, path_hash char(64) NOT NULL,
			relative_path longtext NOT NULL, size_bytes bigint(20) unsigned NOT NULL DEFAULT 0, modified_at bigint(20) unsigned NOT NULL DEFAULT 0,
			checksum_sha256 char(64) NOT NULL DEFAULT '', PRIMARY KEY (id), UNIQUE KEY point_path (point_id,path_hash), KEY point_id (point_id)
		) {$charset_collate};", $snapshots );

		$addons_sql = $wpdb->prepare( "CREATE TABLE %i (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			addon_id varchar(64) NOT NULL,
			status varchar(24) NOT NULL DEFAULT 'active',
			version varchar(32) NOT NULL DEFAULT '',
			api_version varchar(32) NOT NULL DEFAULT '',
			migration_version varchar(32) NOT NULL DEFAULT '',
			last_error text NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY addon_id (addon_id),
			KEY status (status)
		) {$charset_collate};", $addons );

		$events_sql = $wpdb->prepare( "CREATE TABLE %i (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			job_id bigint(20) unsigned NOT NULL,
			event_type varchar(48) NOT NULL DEFAULT '',
			status varchar(32) NOT NULL DEFAULT '',
			step varchar(64) NOT NULL DEFAULT '',
			attempt smallint(5) unsigned NOT NULL DEFAULT 0,
			message text NULL,
			context_data text NULL,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY job_created (job_id,created_at),
			KEY event_type (event_type)
		) {$charset_collate};", $events );

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $jobs_sql );
		dbDelta( $events_sql );
		dbDelta( $inventory_sql );
		dbDelta( $transfers_sql );
		dbDelta( $schedules_sql );
		dbDelta( $profiles_sql );
		dbDelta( $chains_sql );
		dbDelta( $points_sql );
		dbDelta( $snapshots_sql );
		dbDelta( $addons_sql );
		( new \Nobash\Backup\BackupProfile\ProfileRepository() )->ensure_defaults();
	}
}
