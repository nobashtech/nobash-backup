<?php
/**
 * Preflight checks before export / import.
 *
 * @package Nobash\Backup
 */

namespace Nobash\Backup\System;

use Nobash\Backup\Security\PasswordArchive;
use Nobash\Backup\Storage\LocalStorage;

defined( 'ABSPATH' ) || exit;

/**
 * Class Preflight
 */
class Preflight {

	/**
	 * Minimum free disk space (bytes) — 64MB soft gate.
	 */
	const MIN_FREE_BYTES = 67108864;

	/**
	 * Run checks for a context.
	 *
	 * @param string               $context  export|import.
	 * @param array<string, mixed> $args     Optional args (filename for import).
	 * @return array{ok:bool, can_proceed:bool, checks:array<int, array<string, mixed>>}
	 */
	public function run( $context = 'export', array $args = array() ) {
		$checks = array();

		$checks[] = $this->check_php_version();
		$checks[] = $this->check_large_file_platform();
		$checks[] = $this->check_encryption();
		$checks[] = $this->check_database();
		$checks[] = $this->check_server();
		$checks[] = $this->check_hosting_environment();
		$checks[] = $this->check_woocommerce();
		$checks[] = $this->check_multisite();
		$checks[] = $this->check_memory();
		$checks[] = $this->check_execution_time();
		$checks[] = $this->check_storage_writable();
		$checks[] = $this->check_disk_space();
		$checks[] = $this->check_large_site_capacity( $context, $args );
		$checks[] = $this->check_permissions();

		if ( 'import' === $context ) {
			$checks[] = $this->check_import_upload_limits();
			if ( ! empty( $args['filename'] ) ) {
				$checks = array_merge( $checks, $this->check_archive( (string) $args['filename'] ) );
			}
		}

		/**
		 * Filter preflight checks.
		 *
		 * @param array<int, array<string, mixed>> $checks  Checks.
		 * @param string                           $context Context.
		 * @param array<string, mixed>             $args    Args.
		 */
		$checks = (array) apply_filters( 'nobash_backup_preflight_checks', $checks, $context, $args );

		$has_error = false;
		foreach ( $checks as $check ) {
			if ( isset( $check['status'] ) && 'fail' === $check['status'] ) {
				$has_error = true;
				break;
			}
		}

		return array(
			'ok'          => ! $has_error,
			'can_proceed' => ! $has_error,
			'context'     => $context,
			'checks'      => array_values( $checks ),
		);
	}

	/**
	 * PHP version.
	 *
	 * @return array<string, mixed>
	 */
	private function check_php_version() {
		$ok = \Nobash\Backup\Compat\Environment::php_supported();
		return $this->item(
			'php_version',
			__( 'PHP version', 'nobash-backup' ),
			$ok ? 'pass' : 'fail',
			sprintf(
				/* translators: %s: PHP version */
				__( 'Detected PHP %s (supported: 7.4 – 8.6).', 'nobash-backup' ),
				PHP_VERSION
			)
		);
	}

	/** Large archive offset support. @return array<string,mixed> */
	private function check_large_file_platform() {
		$ok = PHP_INT_SIZE >= 8;
		return $this->item(
			'large_offsets',
			__( 'Large archive offsets', 'nobash-backup' ),
			$ok ? 'pass' : 'fail',
			$ok ? __( '64-bit PHP file offsets are available for archives larger than 2GB.', 'nobash-backup' ) : __( 'This server uses 32-bit PHP. Reliable backup and restore of archives larger than 2GB requires 64-bit PHP.', 'nobash-backup' )
		);
	}


	/**
	 * Authenticated-container backend availability and limitations.
	 *
	 * @return array<string, mixed>
	 */
	private function check_encryption() {
		if ( PasswordArchive::available() ) {
			return $this->item( 'encryption', __( 'Archive encryption', 'nobash-backup' ), 'pass', __( 'Authenticated password protection is available for portable .nbh archives.', 'nobash-backup' ) );
		}
		return $this->item( 'encryption', __( 'Archive encryption', 'nobash-backup' ), 'warn', __( 'Authenticated encryption is unavailable. Native backup and restore remain available without archive confidentiality.', 'nobash-backup' ) );
	}

	/**
	 * Database connectivity + basic query.
	 *
	 * @return array<string, mixed>
	 */
	private function check_database() {
		global $wpdb;

		if ( ! empty( $wpdb->last_error ) && ! $wpdb->ready ) {
			return $this->item(
				'database',
				__( 'Database connection', 'nobash-backup' ),
				'fail',
				__( 'WordPress could not connect to the database.', 'nobash-backup' )
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->get_var( 'SELECT 1' );
		if ( '1' !== (string) $result ) {
			return $this->item(
				'database',
				__( 'Database connection', 'nobash-backup' ),
				'fail',
				$wpdb->last_error
					? $wpdb->last_error
					: __( 'Database query failed.', 'nobash-backup' )
			);
		}

		$adapter = new \Nobash\Backup\Compat\DatabaseAdapter();
		$tables  = $adapter->list_tables();
		$engine  = \Nobash\Backup\Compat\Environment::db_engine();
		$version = \Nobash\Backup\Compat\Environment::db_version();
		$count   = count( $tables );

		return $this->item(
			'database',
			__( 'Database', 'nobash-backup' ),
			'pass',
			sprintf(
				/* translators: 1: engine 2: version 3: table count 4: prefix */
				__( '%1$s %2$s — %3$d tables (prefix “%4$s”). Supports MySQL, MariaDB, and SQLite.', 'nobash-backup' ),
				$engine,
				$version ? $version : '',
				$count,
				$wpdb->prefix
			)
		);
	}

	/**
	 * Web server hardening awareness.
	 *
	 * @return array<string, mixed>
	 */
	private function check_server() {
		$family = \Nobash\Backup\Compat\Environment::server_family();
		$note   = \Nobash\Backup\Compat\Environment::hardening_notes();
		return $this->item(
			'server',
			__( 'Web server', 'nobash-backup' ),
			'pass',
			sprintf(
				/* translators: 1: server family 2: note */
				__( 'Detected: %1$s. %2$s', 'nobash-backup' ),
				$family,
				$note
			)
		);
	}

	/**
	 * WooCommerce awareness (informational).
	 *
	 * @return array<string, mixed>
	 */

	/** Hosting provider/resource compatibility and scheduling advisory. @return array<string,mixed> */
	private function check_hosting_environment() {
		$hosting = \Nobash\Backup\Compat\HostingEnvironment::snapshot();
		$provider = (string) ( $hosting['provider_name'] ?? __( 'Generic / capability-based', 'nobash-backup' ) );
		$tier = (string) ( $hosting['resource_tier'] ?? 'balanced' );
		$policy = isset( $hosting['policy'] ) && is_array( $hosting['policy'] ) ? $hosting['policy'] : array();
		$status = (string) ( $policy['level'] ?? 'pass' );
		$messages = array();
		$messages[] = sprintf(
			/* translators: 1: hosting provider 2: resource tier */
			__( 'Hosting profile: %1$s; runtime tier: %2$s. Nobash Backup uses capability-based bounded work, so unknown cPanel/Plesk/shared hosts do not require a provider-specific integration.', 'nobash-backup' ),
			$provider,
			$tier
		);
		if ( ! empty( $policy['message'] ) ) {
			$messages[] = (string) $policy['message'];
		}
		if ( ! empty( $hosting['wp_cron_disabled'] ) && empty( $hosting['action_scheduler'] ) ) {
			$status = 'warn';
			$messages[] = __( 'WP-Cron is disabled and Action Scheduler was not detected. Manual jobs remain resumable, but unattended/scheduled continuation requires the host or a real cron job to invoke wp-cron.php.', 'nobash-backup' );
		}
		return $this->item( 'hosting_environment', __( 'Hosting compatibility', 'nobash-backup' ), in_array( $status, array( 'pass', 'warn', 'fail' ), true ) ? $status : 'warn', implode( ' ', $messages ) );
	}

	private function check_woocommerce() {
		$wc = \Nobash\Backup\Compat\WooCommerceSupport::status();
		if ( empty( $wc['active'] ) ) {
			return $this->item(
				'woocommerce',
				__( 'WooCommerce', 'nobash-backup' ),
				'pass',
				__( 'Not active — no special WooCommerce steps required.', 'nobash-backup' )
			);
		}

		return $this->item(
			'woocommerce',
			__( 'WooCommerce', 'nobash-backup' ),
			'warn',
			sprintf(
				/* translators: 1: WooCommerce version 2: HPOS status */
				__( 'Active (v%1$s). HPOS: %2$s. Orders placed while a backup or restore is running are not captured; use a maintenance window for active stores.', 'nobash-backup' ),
				$wc['version'] ? $wc['version'] : '—',
				! empty( $wc['hpos'] ) ? __( 'HPOS enabled', 'nobash-backup' ) : __( 'posts storage', 'nobash-backup' )
			)
		);
	}

	/**
	 * Multisite awareness.
	 *
	 * @return array<string, mixed>
	 */
	private function check_multisite() {
		if ( ! is_multisite() ) {
			return $this->item(
				'multisite',
				__( 'Multisite', 'nobash-backup' ),
				'pass',
				__( 'Single-site install.', 'nobash-backup' )
			);
		}

		$can = \Nobash\Backup\Compat\Environment::can_network_backup();
		return $this->item(
			'multisite',
			__( 'Multisite', 'nobash-backup' ),
			'pass',
			sprintf(
				/* translators: 1: blog id 2: network backup capability */
				__( 'Network enabled (blog ID %1$d). Entire-network export: %2$s.', 'nobash-backup' ),
				get_current_blog_id(),
				$can ? __( 'available', 'nobash-backup' ) : __( 'requires network admin', 'nobash-backup' )
			)
		);
	}

	/**
	 * Memory limit.
	 *
	 * @return array<string, mixed>
	 */
	private function check_memory() {
		$limit = (string) ini_get( 'memory_limit' );
		$bytes = $this->to_bytes( $limit );
		if ( -1 === $bytes ) {
			return $this->item( 'memory', __( 'PHP memory limit', 'nobash-backup' ), 'pass', __( 'Unlimited memory_limit.', 'nobash-backup' ) );
		}
		if ( $bytes < 64 * 1024 * 1024 ) {
			return $this->item(
				'memory',
				__( 'PHP memory limit', 'nobash-backup' ),
				'fail',
				sprintf(
					/* translators: %s memory limit */
					__( 'memory_limit is %s — raise to at least 128M.', 'nobash-backup' ),
					$limit
				)
			);
		}
		if ( $bytes < 128 * 1024 * 1024 ) {
			return $this->item(
				'memory',
				__( 'PHP memory limit', 'nobash-backup' ),
				'warn',
				sprintf(
					/* translators: %s memory limit */
					__( 'memory_limit is %s — 256M+ recommended for large sites.', 'nobash-backup' ),
					$limit
				)
			);
		}
		return $this->item(
			'memory',
			__( 'PHP memory limit', 'nobash-backup' ),
			'pass',
			sprintf(
				/* translators: %s memory limit */
				__( 'memory_limit is %s.', 'nobash-backup' ),
				$limit
			)
		);
	}

	/**
	 * Max execution time.
	 *
	 * @return array<string, mixed>
	 */
	private function check_execution_time() {
		$max = (int) ini_get( 'max_execution_time' );
		if ( 0 === $max ) {
			return $this->item( 'execution', __( 'Max execution time', 'nobash-backup' ), 'pass', __( 'Unlimited max_execution_time.', 'nobash-backup' ) );
		}
		if ( $max > 0 && $max < 30 ) {
			return $this->item(
				'execution',
				__( 'Max execution time', 'nobash-backup' ),
				'warn',
				sprintf(
					/* translators: %d seconds */
					__( 'max_execution_time is %d — chunked jobs will still run, but may be slower.', 'nobash-backup' ),
					$max
				)
			);
		}
		return $this->item(
			'execution',
			__( 'Max execution time', 'nobash-backup' ),
			'pass',
			sprintf(
				/* translators: %d seconds */
				__( 'max_execution_time is %d seconds.', 'nobash-backup' ),
				$max
			)
		);
	}

	/**
	 * Storage directory writable.
	 *
	 * @return array<string, mixed>
	 */
	private function check_storage_writable() {
		try {
			LocalStorage::assert_write_safe();
		} catch ( \RuntimeException $error ) {
			return $this->item( 'storage', __( 'Backup storage folder', 'nobash-backup' ), 'fail', $error->getMessage() );
		}
		$dir = LocalStorage::ensure_directory();
		$wp_filesystem = \Nobash\Backup\Filesystem\DirectFilesystem::get();
		$ok  = is_dir( $dir ) && $wp_filesystem->is_writable( $dir );
		return $this->item(
			'storage',
			__( 'Backup storage folder', 'nobash-backup' ),
			$ok ? 'pass' : 'fail',
			$ok
				? sprintf(
					/* translators: %s path */
					__( 'Writable: %s', 'nobash-backup' ),
					$dir
				)
				: sprintf(
					/* translators: %s path */
					__( 'Not writable: %s', 'nobash-backup' ),
					$dir
				)
		);
	}

	/**
	 * Free disk space.
	 *
	 * @return array<string, mixed>
	 */
	private function check_disk_space() {
		$free = @disk_free_space( LocalStorage::get_directory() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $free ) {
			return $this->item( 'disk', __( 'Free disk space', 'nobash-backup' ), 'warn', __( 'Could not determine free disk space.', 'nobash-backup' ) );
		}
		if ( $free < self::MIN_FREE_BYTES ) {
			return $this->item(
				'disk',
				__( 'Free disk space', 'nobash-backup' ),
				'fail',
				sprintf(
					/* translators: %s size */
					__( 'Only %s free — need at least 64MB.', 'nobash-backup' ),
					size_format( $free )
				)
			);
		}
		if ( $free < 256 * 1024 * 1024 ) {
			return $this->item(
				'disk',
				__( 'Free disk space', 'nobash-backup' ),
				'warn',
				sprintf(
					/* translators: %s size */
					__( '%s free — large sites may need more space.', 'nobash-backup' ),
					size_format( $free )
				)
			);
		}
		return $this->item(
			'disk',
			__( 'Free disk space', 'nobash-backup' ),
			'pass',
			sprintf(
				/* translators: %s size */
				__( '%s available.', 'nobash-backup' ),
				size_format( $free )
			)
		);
	}

	/** Best-effort capacity signal without performing a full filesystem scan. @return array<string,mixed> */
	private function check_large_site_capacity( $context, array $args ) {
		$planner = new LargeSitePlanner();
		$free = $planner->free_bytes();
		if ( false === $free ) { return $this->item( 'large_site_capacity', __( 'Large-site workspace', 'nobash-backup' ), 'warn', __( 'Workspace capacity could not be measured. The job will continue with bounded checkpoints and fail closed if storage becomes unavailable.', 'nobash-backup' ) ); }
		$required = $planner->backup_workspace_bytes();
		if ( 'import' === $context && ! empty( $args['filename'] ) ) {
			$path = ( new LocalStorage() )->resolve_archive( basename( (string) $args['filename'] ) );
			if ( $path ) { $required = max( $required, (int) ceil( filesize( $path ) * 3.85 ) ); }
		}
		$status = $free < $required ? 'warn' : 'pass';
		$message = $free < $required
			? sprintf( /* translators: 1: Estimated required disk space, 2: Available disk space. */ __( 'Approximately %1$s of temporary workspace may be needed, while %2$s is currently free. Exact restore planning will block before destructive work if capacity is insufficient.', 'nobash-backup' ), size_format( $required ), size_format( $free ) )
			: sprintf( /* translators: 1: Available disk space, 2: Estimated required disk space. */ __( '%1$s free; the current conservative large-site workspace estimate is %2$s.', 'nobash-backup' ), size_format( $free ), size_format( $required ) );
		return $this->item( 'large_site_capacity', __( 'Large-site workspace', 'nobash-backup' ), $status, $message );
	}

	/**
	 * Capability / WordPress paths writable for import file restore.
	 *
	 * @return array<string, mixed>
	 */
	private function check_permissions() {
		$wp_filesystem = \Nobash\Backup\Filesystem\DirectFilesystem::get();
		$ok = $wp_filesystem->is_writable( \Nobash\Backup\Compat\Paths::wordpress_root() ) || $wp_filesystem->is_writable( \Nobash\Backup\Compat\Paths::uploads_root() );
		return $this->item(
			'permissions',
			__( 'Filesystem permissions', 'nobash-backup' ),
			$ok ? 'pass' : 'warn',
			$ok
				? __( 'WordPress paths look writable for restore operations.', 'nobash-backup' )
				: __( 'Some paths may not be writable — file restore could fail.', 'nobash-backup' )
		);
	}

	/**
	 * Upload limits relevant to import.
	 *
	 * @return array<string, mixed>
	 */
	private function check_import_upload_limits() {
		$upload = $this->to_bytes( (string) ini_get( 'upload_max_filesize' ) );
		$post   = $this->to_bytes( (string) ini_get( 'post_max_size' ) );
		$min    = min( $upload > 0 ? $upload : PHP_INT_MAX, $post > 0 ? $post : PHP_INT_MAX );

		if ( $min < 8 * 1024 * 1024 ) {
			return $this->item(
				'upload_limits',
				__( 'PHP upload limits', 'nobash-backup' ),
				'warn',
				sprintf(
					/* translators: 1: upload_max 2: post_max */
					__( 'upload_max_filesize=%1$s, post_max_size=%2$s — large imports may fail.', 'nobash-backup' ),
					ini_get( 'upload_max_filesize' ),
					ini_get( 'post_max_size' )
				)
			);
		}

		return $this->item(
			'upload_limits',
			__( 'PHP upload limits', 'nobash-backup' ),
			'pass',
			sprintf(
				/* translators: 1: upload_max 2: post_max */
				__( 'upload_max_filesize=%1$s, post_max_size=%2$s.', 'nobash-backup' ),
				ini_get( 'upload_max_filesize' ),
				ini_get( 'post_max_size' )
			)
		);
	}

	/**
	 * Validate a stored archive before restore.
	 *
	 * @param string $filename Archive filename.
	 * @return array<int, array<string, mixed>>
	 */
	private function check_archive( $filename ) {
		$items = array();
		$storage = new LocalStorage();
		$path = $storage->resolve_archive( $filename );
		if ( null === $path ) {
			return array( $this->item( 'archive', __( 'Backup archive', 'nobash-backup' ), 'fail', __( 'Archive file was not found in storage.', 'nobash-backup' ) ) );
		}
		$items[] = $this->item( 'archive', __( 'Backup archive', 'nobash-backup' ), 'pass', sprintf( /* translators: 1: Archive filename, 2: Formatted file size. */ __( '%1$s (%2$s).', 'nobash-backup' ), basename( $path ), size_format( (int) filesize( $path ) ) ) );
		try {
			if ( \Nobash\Backup\Archive\NativeArchiveReader::is_native( $path ) || PasswordArchive::is_password_protected( $path ) ) {
				$items[] = $this->item( 'archive_format', __( 'Archive format', 'nobash-backup' ), 'pass', __( 'Supported .nbh archive header detected. Encrypted containers are decrypted before every native record and checksum is verified during restore.', 'nobash-backup' ) );
				$index = $storage->read_archive_index( $filename );
				$items[] = $this->item( 'checksums', __( 'Integrity verification', 'nobash-backup' ), empty( $index['sha256'] ) ? 'warn' : 'pass', empty( $index['sha256'] ) ? __( 'The archive sidecar checksum is unavailable; record-level verification will still run during restore.', 'nobash-backup' ) : __( 'Archive checksum metadata is available and every record will be verified during restore.', 'nobash-backup' ) );
			} else {
				$items[] = $this->item( 'archive_format', __( 'Archive format', 'nobash-backup' ), 'fail', __( 'The archive is not a supported native .nbh backup.', 'nobash-backup' ) );
			}
		} catch ( \Throwable $e ) {
			$items[] = $this->item( 'archive_integrity', __( 'Archive integrity', 'nobash-backup' ), 'fail', $e->getMessage() );
		}
		return $items;
	}

	/**
	 * Build a check item.
	 *
	 * @param string $id      Id.
	 * @param string $label   Label.
	 * @param string $status  pass|warn|fail.
	 * @param string $message Message.
	 * @return array<string, mixed>
	 */
	private function item( $id, $label, $status, $message ) {
		return array(
			'id'      => $id,
			'label'   => $label,
			'status'  => $status,
			'message' => $message,
		);
	}

	/**
	 * Convert ini size to bytes.
	 *
	 * @param string $value Ini value.
	 * @return int
	 */
	private function to_bytes( $value ) {
		$value = trim( $value );
		if ( '' === $value || '-1' === $value ) {
			return -1;
		}
		$last = strtolower( substr( $value, -1 ) );
		$num  = (float) $value;
		switch ( $last ) {
			case 'g':
				$num *= 1024;
				// no break.
			case 'm':
				$num *= 1024;
				// no break.
			case 'k':
				$num *= 1024;
		}
		return (int) $num;
	}

	/**
	 * Remove temp dir.
	 *
	 * @param string $dir Directory.
	 * @return void
	 */
	private function cleanup_dir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$storage = new LocalStorage();
		$storage->rmdir_recursive( $dir );
	}
}
