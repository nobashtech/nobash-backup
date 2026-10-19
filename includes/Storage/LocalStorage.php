<?php
/**
 * Hardened local storage for backup archives.
 *
 * @package Nobash\Backup
 */

namespace Nobash\Backup\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Class LocalStorage
 */
class LocalStorage {

	/** @var string|null */
	private static $resolved_directory = null;

	const DIR_NAME = 'nobash-backups';
	const IMPORTS_DIR = 'imports';
	const STORAGE_TOKEN_OPTION = 'nobash_backup_storage_token_v1';

	/**
	 * Ensure WP_Filesystem is initialized and return the direct filesystem API.
	 *
	 * @return \WP_Filesystem_Base
	 */
	private static function filesystem() {
		$wp_filesystem = \Nobash\Backup\Filesystem\DirectFilesystem::get();
		return $wp_filesystem;
	}

	/**
	 * Ensure storage directory exists and is hardened.
	 *
	 * @return string Absolute path to storage directory.
	 */
	public static function ensure_directory() {
		$dir = self::get_directory();

		if ( is_link( $dir ) ) {
			throw new \RuntimeException( esc_html__( 'The Nobash Backup storage directory cannot be a symbolic link.', 'nobash-backup' ) );
		}
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			throw new \RuntimeException( esc_html__( 'Nobash Backup could not create a private storage directory.', 'nobash-backup' ) );
		}
		$wp_filesystem = self::filesystem();
		if ( ! $wp_filesystem->is_writable( $dir ) ) {
			throw new \RuntimeException( esc_html__( 'The Nobash Backup storage directory is not writable.', 'nobash-backup' ) );
		}

		$wp_filesystem->chmod( $dir, 0750 );
		self::harden( $dir );

		$tmp = trailingslashit( $dir ) . 'tmp';
		if ( is_link( $tmp ) ) {
			throw new \RuntimeException( esc_html__( 'The Nobash Backup temporary directory cannot be a symbolic link.', 'nobash-backup' ) );
		}
		if ( ! is_dir( $tmp ) && ! wp_mkdir_p( $tmp ) ) {
			throw new \RuntimeException( esc_html__( 'Nobash Backup could not create its temporary directory.', 'nobash-backup' ) );
		}
		$wp_filesystem->chmod( $tmp, 0700 );
		self::harden( $tmp );

		$imports = trailingslashit( $dir ) . self::IMPORTS_DIR;
		if ( is_link( $imports ) ) {
			throw new \RuntimeException( esc_html__( 'The Nobash Backup import directory cannot be a symbolic link.', 'nobash-backup' ) );
		}
		if ( ! is_dir( $imports ) && ! wp_mkdir_p( $imports ) ) {
			throw new \RuntimeException( esc_html__( 'Nobash Backup could not create its import directory.', 'nobash-backup' ) );
		}
		$wp_filesystem->chmod( $imports, 0700 );
		self::harden( $imports );

		return $dir;
	}


	/**
	 * Allocate a short, monotonically increasing archive filename.
	 *
	 * Examples: full-backup-1.nbh, database-backup-2.nbh, media-backup-3.nbh.
	 * The sequence is global to this site's private backup directory so names stay
	 * compact while remaining collision-safe across concurrent jobs.
	 *
	 * @param string $profile Backup profile/type.
	 * @return string
	 */
	public static function allocate_archive_filename( $profile ) {
		$dir  = self::ensure_directory();
		self::assert_write_safe();
		$type = self::filename_type( $profile );
		$seq_path = trailingslashit( $dir ) . '.archive-sequence';

		if ( is_link( $seq_path ) || ( file_exists( $seq_path ) && ! is_file( $seq_path ) ) ) {
			throw new \RuntimeException( esc_html__( 'The backup filename sequence path is unsafe.', 'nobash-backup' ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Advisory-locked (flock), seek/truncate/rewind counter file; WP_Filesystem has no locking or random-access primitives needed for a safe concurrent sequence counter.
		$fh = fopen( $seq_path, 'c+' );
		if ( ! $fh ) {
			return self::allocate_archive_filename_with_db_lock( $dir, $type );
		}

		try {
			if ( ! flock( $fh, LOCK_EX ) ) {
				// Some shared/NFS filesystems expose PHP flock() but do not provide a
				// usable advisory lock. Fall back to WordPress' database uniqueness.
				return self::allocate_archive_filename_with_db_lock( $dir, $type );
			}
			self::filesystem()->chmod( $seq_path, 0600 );
			rewind( $fh );
			$raw = trim( (string) stream_get_contents( $fh ) );
			$current = ctype_digit( $raw ) ? (int) $raw : 0;

			// Recover safely if the counter was removed while short-name archives exist.
			if ( $current < 1 ) {
				$files = glob( trailingslashit( $dir ) . '*-backup-*.nbh' );
				foreach ( is_array( $files ) ? $files : array() as $file ) {
					if ( preg_match( '/^[a-z0-9-]+-backup-(\d+)\.nbh$/i', basename( $file ), $m ) ) {
						$current = max( $current, (int) $m[1] );
					}
				}
			}

			$next = $current + 1;
			do {
				$filename = $type . '-backup-' . $next . '.nbh';
				$path = trailingslashit( $dir ) . $filename;
				++$next;
			} while ( file_exists( $path ) || is_link( $path ) || file_exists( $path . '.json' ) || is_link( $path . '.json' ) );
			$allocated = $next - 1;

			$sequence_text = (string) $allocated;
			if ( ! ftruncate( $fh, 0 ) || 0 !== fseek( $fh, 0, SEEK_SET ) ) {
				throw new \RuntimeException( __( 'Nobash Backup could not persist the backup filename sequence.', 'nobash-backup' ) );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Writes into the already flock()'d, seeked sequence-counter handle above; must stay on the same direct handle.
			$written = fwrite( $fh, $sequence_text );
			if ( false === $written || strlen( $sequence_text ) !== $written ) {
				throw new \RuntimeException( __( 'Nobash Backup could not persist the backup filename sequence.', 'nobash-backup' ) );
			}
			fflush( $fh );
			if ( function_exists( 'fsync' ) ) {
				@fsync( $fh ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
			flock( $fh, LOCK_UN );
			return $filename;
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the direct handle opened above for the locked sequence counter.
			fclose( $fh );
		}
	}


	/**
	 * Cross-filesystem fallback for hosts where flock() is unavailable/unreliable.
	 * Uses an atomic add_option() mutex and collision-checks the real archive names.
	 *
	 * @param string $dir  Storage directory.
	 * @param string $type Public filename type.
	 * @return string
	 */
	private static function allocate_archive_filename_with_db_lock( $dir, $type ) {
		$lock_name = 'nobash_backup_archive_sequence_lock_v1';
		$counter_name = 'nobash_backup_archive_sequence_v1';
		$token = wp_generate_password( 32, false, false );
		$payload = $token . '|' . time();
		$acquired = false;

		for ( $attempt = 0; $attempt < 80; ++$attempt ) {
			if ( add_option( $lock_name, $payload, '', 'no' ) ) {
				$acquired = true;
				break;
			}
			$existing = (string) get_option( $lock_name, '' );
			$parts = explode( '|', $existing );
			$locked_at = isset( $parts[1] ) ? (int) $parts[1] : 0;
			if ( $locked_at > 0 && $locked_at < time() - 30 ) {
				self::compare_delete_option( $lock_name, $existing );
			}
			usleep( 25000 );
		}
		if ( ! $acquired ) {
			throw new \RuntimeException( esc_html__( 'Nobash Backup could not acquire a cross-host backup filename lock.', 'nobash-backup' ) );
		}

		try {
			$current = max( 0, (int) get_option( $counter_name, 0 ) );
			$next = $current + 1;
			// The real filesystem is authoritative. This also reconciles the DB
			// fallback counter with archives previously allocated by flock().
			do {
				$filename = $type . '-backup-' . $next . '.nbh';
				$path = trailingslashit( $dir ) . $filename;
				++$next;
			} while ( file_exists( $path ) || is_link( $path ) || file_exists( $path . '.json' ) || is_link( $path . '.json' ) );
			$allocated = $next - 1;
			update_option( $counter_name, $allocated, false );
			return $filename;
		} finally {
			self::compare_delete_option( $lock_name, $payload );
		}
	}

	/** Delete an option only when its value still matches the caller's token. @return bool */
	private static function compare_delete_option( $name, $expected ) {
		global $wpdb;
		// Direct compare-and-delete avoids a stale worker deleting a newer lock.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s AND option_value = %s', $wpdb->options, (string) $name, (string) $expected ) );
		if ( $deleted ) {
			wp_cache_delete( (string) $name, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			return true;
		}
		return false;
	}

	/**
	 * Convert a profile key to the public filename type.
	 *
	 * @param string $profile Profile key.
	 * @return string
	 */
	private static function filename_type( $profile ) {
		$profile = sanitize_key( (string) $profile );
		$map = array(
			'full'     => 'full',
			'database' => 'database',
			'files'    => 'files',
			'uploads'  => 'media',
			'media'    => 'media',
			'plugins'  => 'plugins',
			'themes'   => 'themes',
			'custom'   => 'custom',
		);
		return isset( $map[ $profile ] ) ? $map[ $profile ] : 'full';
	}

	/**
	 * Infer a backup profile from the current filename format.
	 *
	 * @param string $filename Filename.
	 * @return string
	 */
	public static function profile_from_filename( $filename ) {
		$filename = basename( sanitize_file_name( (string) $filename ) );
		if ( preg_match( '/^(full|database|files|media|uploads|plugins|themes|custom)-backup-\d+\.nbh$/i', $filename, $m ) ) {
			$key = strtolower( $m[1] );
			return 'media' === $key ? 'uploads' : $key;
		}
		return '';
	}

	/**
	 * Return a stable, installation-specific storage token.
	 *
	 * The token is not an authentication credential. It makes the local archive
	 * directory non-predictable as an additional layer behind server deny rules
	 * and authenticated WordPress download endpoints.
	 *
	 * @return string
	 */
	private static function storage_token() {
		$token = strtolower( (string) get_option( self::STORAGE_TOKEN_OPTION, '' ) );
		if ( ! preg_match( '/^[a-f0-9]{24}$/', $token ) ) {
			try {
				$token = bin2hex( random_bytes( 12 ) );
			} catch ( \Throwable $e ) {
				$token = substr( hash( 'sha256', wp_generate_password( 64, true, true ) . '|' . microtime( true ) ), 0, 24 );
			}
			update_option( self::STORAGE_TOKEN_OPTION, $token, false );
		}
		return $token;
	}

	/**
	 * Get storage directory path.
	 *
	 * @return string
	 */
	public static function get_directory() {
		if ( null !== self::$resolved_directory ) {
			return self::$resolved_directory;
		}

		$uploads_root = trailingslashit( \Nobash\Backup\Compat\Paths::uploads_root() );
		$token        = self::storage_token();
		$default      = $uploads_root . self::DIR_NAME . '-' . $token;
		$legacy       = $uploads_root . self::DIR_NAME;
		$explicit     = false;
		if ( defined( 'NOBASH_BACKUP_STORAGE_DIR' ) && is_string( NOBASH_BACKUP_STORAGE_DIR ) && '' !== trim( NOBASH_BACKUP_STORAGE_DIR ) ) {
			$default  = (string) NOBASH_BACKUP_STORAGE_DIR;
			$explicit = true;
		}

		/**
		 * Filter the local backup storage directory.
		 *
		 * The default is an installation-specific directory beneath the WordPress
		 * uploads root. Define NOBASH_BACKUP_STORAGE_DIR in wp-config.php for an
		 * explicit server-managed override.
		 *
		 * @param string $dir Absolute directory path.
		 */
		$preferred = (string) apply_filters( 'nobash_backup_storage_directory', $default );
		$candidates = array( $preferred );

		// Transparently migrate the legacy predictable folder when no explicit/custom
		// storage path was configured. Moving the whole directory preserves archives,
		// import sessions, sidecars and hardening files atomically on normal local
		// filesystems. If the host cannot rename it safely, keep using the legacy
		// folder so existing recovery points never disappear from the UI.
		if ( ! $explicit && $preferred === $default && is_dir( $legacy ) && ! is_link( $legacy ) && $legacy !== $preferred ) {
			$migrated = false;
			if ( ! file_exists( $preferred ) && ! is_link( $preferred ) ) {
				$migrated = (bool) self::filesystem()->move( $legacy, $preferred, false );
			}
			if ( ! $migrated && ! is_dir( $preferred ) ) {
				$candidates = array( $legacy, $preferred );
			}
		}

		foreach ( array_unique( array_filter( array_map( 'strval', $candidates ) ) ) as $candidate ) {
			$candidate = untrailingslashit( $candidate );
			if ( '' === $candidate || is_link( $candidate ) ) {
				continue;
			}
			if ( ! is_dir( $candidate ) && ! wp_mkdir_p( $candidate ) ) {
				continue;
			}
			if ( ! self::filesystem()->is_writable( $candidate ) ) {
				continue;
			}
			$probe = trailingslashit( $candidate ) . '.nbh-write-test-' . wp_generate_password( 10, false, false );
			if ( false === self::filesystem()->put_contents( $probe, 'ok' ) ) {
				continue;
			}
			wp_delete_file( $probe );
			self::$resolved_directory = $candidate;
			return self::$resolved_directory;
		}

		throw new \RuntimeException( esc_html__( 'The Nobash Backup storage directory is unavailable or not writable. Define NOBASH_BACKUP_STORAGE_DIR in wp-config.php only when an explicit override is required.', 'nobash-backup' ) );
	}

	/**
	 * Apply storage hardening and record best-effort HTTP reachability diagnostics.
	 *
	 * Protective files (.htaccess deny-all, blank index.html, web.config and server
	 * guidance) are written by harden(). New installations also use an
	 * installation-specific non-predictable directory. The loopback canary is a
	 * diagnostic layer only and never disables core backup/export functionality.
	 *
	 * @return void
	 */
	public static function assert_write_safe() {
		$dir = self::ensure_directory();
		$url = self::storage_url( $dir );
		if ( null === $url ) {
			return;
		}

		// Server deny files are always written by ensure_directory()/harden(). The
		// HTTP canary below is diagnostic only: some managed hosts intentionally do
		// not allow loopback requests, and Nginx does not consume per-directory
		// .htaccess files. Backups therefore use an installation-specific,
		// non-predictable directory and are downloaded only through authenticated
		// WordPress endpoints rather than blocking core backup functionality.
		$key = 'nobash_backup_storage_http_' . md5( $dir . '|' . $url );
		if ( false !== get_transient( $key ) ) {
			return;
		}

		$state = 'inconclusive';
		$verified_not_exposed = false;
		foreach ( array( '.nbh', '.json', 'tmp/.sql', 'tmp/.part', 'imports/.nbh' ) as $probe ) {
			$folder = dirname( $probe );
			$member = ( '.' === $folder ? '' : $folder . '/' ) . 'nbh-access-check-' . wp_generate_password( 24, false, false ) . basename( $probe );
			$path = trailingslashit( $dir ) . $member;
			$marker = wp_generate_password( 48, false, false );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged -- Exclusive create avoids following a pre-existing link for the non-sensitive canary; failure is handled below. WP_Filesystem has no exclusive-create primitive.
			$handle = @fopen( $path, 'x' );
			if ( false === $handle ) {
				continue;
			}
			try {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Random, non-sensitive canary content only.
				$written = fwrite( $handle, $marker );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close before the loopback request.
				fclose( $handle );
				$handle = null;
				if ( strlen( $marker ) !== $written ) {
					continue;
				}
				$response = wp_remote_get( trailingslashit( $url ) . $member, array(
					'timeout' => 2, 'redirection' => 0, 'sslverify' => true,
					'limit_response_size' => 128, 'headers' => array( 'Cache-Control' => 'no-cache' ),
				) );
				if ( is_wp_error( $response ) ) {
					continue;
				}
				$code = (int) wp_remote_retrieve_response_code( $response );
				if ( false !== strpos( (string) wp_remote_retrieve_body( $response ), $marker ) ) {
					$state = 'reachable';
					break;
				}
				if ( $code >= 200 && $code < 500 && ! in_array( $code, array( 408, 425, 429 ), true ) ) {
					$verified_not_exposed = true;
				}
			} finally {
				if ( is_resource( $handle ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					fclose( $handle );
				}
				wp_delete_file( $path );
			}
		}

		if ( 'reachable' !== $state && $verified_not_exposed ) {
			$state = 'blocked';
		}
		set_transient( $key, $state, 12 * HOUR_IN_SECONDS );
		update_option( 'nobash_backup_storage_http_state', $state, false );
	}

	/** Resolve a configured local storage URL, or null for an off-webroot path. */
	private static function storage_url( $dir ) {
		$dir = rtrim( wp_normalize_path( $dir ), '/' );
		$roots = array();
		try {
			$roots[ \Nobash\Backup\Compat\Paths::uploads_root() ] = \Nobash\Backup\Compat\Paths::uploads_url();
		} catch ( \Throwable $e ) {
			// Upload path resolution is best-effort for the exposure probe only.
		}
		$roots[ \Nobash\Backup\Compat\Paths::wordpress_root() ] = home_url();
		if ( ! empty( $_SERVER['DOCUMENT_ROOT'] ) ) {
			$origin = wp_parse_url( home_url() );
			$roots[ sanitize_text_field( wp_unslash( (string) $_SERVER['DOCUMENT_ROOT'] ) ) ] = $origin['scheme'] . '://' . $origin['host'] . ( isset( $origin['port'] ) ? ':' . $origin['port'] : '' );
		}
		foreach ( $roots as $root => $base_url ) {
			$root = rtrim( wp_normalize_path( $root ), '/' );
			if ( $dir === $root || 0 === strpos( $dir . '/', $root . '/' ) ) {
				return trailingslashit( $base_url ) . ltrim( substr( $dir, strlen( $root ) ), '/' );
			}
		}
		return null;
	}

	/**
	 * Resolve an existing isolated import-set directory.
	 *
	 * @param string $package_id Package identifier.
	 * @return string|null
	 */
	private static function import_set_directory( $package_id ) {
		$package_id = (string) $package_id;
		if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9_-]{0,127}$/', $package_id ) ) {
			return null;
		}

		$base = trailingslashit( self::ensure_directory() ) . self::IMPORTS_DIR;
		$dir  = trailingslashit( $base ) . $package_id;
		if ( is_link( $base ) || is_link( $dir ) || ! is_dir( $dir ) ) {
			return null;
		}

		$real_base = realpath( $base );
		$real_dir  = realpath( $dir );
		if ( false === $real_base || false === $real_dir ) {
			return null;
		}

		$base_normalized = trailingslashit( wp_normalize_path( $real_base ) );
		$dir_normalized  = wp_normalize_path( $real_dir );
		if ( 0 !== strpos( trailingslashit( $dir_normalized ), $base_normalized ) ) {
			return null;
		}

		return $real_dir;
	}

	/**
	 * Resolve a package member inside its isolated import-set directory.
	 *
	 * @param string $package_id Package identifier.
	 * @param string $filename   Stored filename.
	 * @return string|null
	 */
	public static function resolve_import_package( $package_id, $filename ) {
		$dir = self::import_set_directory( $package_id );
		if ( null === $dir ) {
			return null;
		}
		$requested = (string) $filename;
		$filename  = basename( $requested );
		if ( $requested !== $filename || '' === $filename || 'package.json' === $filename ) {
			return null;
		}
		$path      = trailingslashit( $dir ) . $filename;
		if ( is_link( $path ) || ! is_file( $path ) ) {
			return null;
		}
		$real_dir  = realpath( $dir );
		$real_file = realpath( $path );
		if ( false === $real_dir || false === $real_file || 0 !== strpos( wp_normalize_path( $real_file ), trailingslashit( wp_normalize_path( $real_dir ) ) ) ) {
			return null;
		}
		return $real_file;
	}

	/**
	 * Write denial files into a directory.
	 *
	 * @param string $dir Directory path.
	 * @return void
	 */
	public static function harden( $dir ) {
		$dir = untrailingslashit( (string) $dir );
		if ( '' === $dir || is_link( $dir ) || ! is_dir( $dir ) ) {
			throw new \RuntimeException( esc_html__( 'Nobash Backup cannot harden an invalid or symbolic-link directory.', 'nobash-backup' ) );
		}

		self::write_marker_file( trailingslashit( $dir ) . 'index.html', '' );
		self::write_marker_file(
			trailingslashit( $dir ) . '.htaccess',
			"Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n"
		);
		self::write_marker_file(
			trailingslashit( $dir ) . 'web.config',
			"<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n  <system.webServer>\n    <authorization>\n      <deny users=\"*\" />\n    </authorization>\n  </system.webServer>\n</configuration>\n"
		);

		$relative = self::web_relative_location( $dir );
		$location = '/' === $relative ? '/' : (string) $relative . '/';
		$nginx = null === $relative
			? "# Nobash Backup storage is outside the detected public document root.\n# No public Nginx location should map to this filesystem directory.\n"
			: "# Nobash Backup — include from the matching Nginx server{} block and reload Nginx.\nlocation ^~ \"" . $location . "\" {\n    deny all;\n    return 403;\n}\n";
		self::write_marker_file( trailingslashit( $dir ) . 'nginx.conf', $nginx );
		self::write_marker_file( trailingslashit( $dir ) . '.litespeed_deny', "deny\n" );
	}

	/**
	 * Create a hardening marker without following an existing symbolic link.
	 *
	 * Existing regular files are preserved so hosting-specific rules are not
	 * overwritten. Missing files are created with exclusive-create semantics.
	 *
	 * @param string $path    Marker path.
	 * @param string $content Marker content.
	 * @return void
	 */
	private static function write_marker_file( $path, $content ) {
		if ( is_link( $path ) ) {
			throw new \RuntimeException( esc_html__( 'A storage hardening file cannot be a symbolic link.', 'nobash-backup' ) );
		}
		if ( file_exists( $path ) ) {
			if ( ! is_file( $path ) ) {
				throw new \RuntimeException( esc_html__( 'A storage hardening path is not a regular file.', 'nobash-backup' ) );
			}
			return;
		}

		// Exclusive-create ('xb'/O_EXCL): must atomically fail instead of overwriting an
		// existing file or following a symlink-swap race. WP_Filesystem::put_contents()
		// has no exclusive-create mode, so the handle-based create/write/close stays direct.
		$handle = @fopen( $path, 'xb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			if ( is_link( $path ) || ! is_file( $path ) ) {
				throw new \RuntimeException( esc_html__( 'Nobash Backup could not create a storage hardening file.', 'nobash-backup' ) );
			}
			return;
		}

		$remaining = (string) $content;
		while ( '' !== $remaining ) {
			$written = fwrite( $handle, $remaining ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			if ( false === $written || 0 === $written ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				wp_delete_file( $path );
				throw new \RuntimeException( esc_html__( 'Nobash Backup could not write a storage hardening file.', 'nobash-backup' ) );
			}
			$remaining = (string) substr( $remaining, $written );
		}
		fflush( $handle );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		self::filesystem()->chmod( $path, 0640 );
	}

	/**
	 * Return a URL location for a filesystem directory under a detected webroot.
	 *
	 * @param string $dir Filesystem directory.
	 * @return string|null
	 */
	private static function web_relative_location( $dir ) {
		$dir = rtrim( wp_normalize_path( (string) $dir ), '/' );
		$roots = array();
		if ( ! empty( $_SERVER['DOCUMENT_ROOT'] ) ) {
			$roots[] = sanitize_text_field( wp_unslash( (string) $_SERVER['DOCUMENT_ROOT'] ) );
		}
		$roots[] = \Nobash\Backup\Compat\Paths::wordpress_root();
		foreach ( array_unique( $roots ) as $root ) {
			$root = rtrim( wp_normalize_path( (string) $root ), '/' );
			if ( '' === $root || ( $dir !== $root && 0 !== strpos( $dir . '/', $root . '/' ) ) ) {
				continue;
			}
			$relative = '/' . ltrim( substr( $dir, strlen( $root ) ), '/' );
			$relative = preg_replace( '/[\r\n\"]+/', '', $relative );
			return rtrim( (string) $relative, '/' );
		}
		return null;
	}

	/**
	 * List .nbh archives in storage.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list_archives() {
		$dir  = self::ensure_directory();
		$files = glob( trailingslashit( $dir ) . '*.nbh' );
		if ( ! is_array( $files ) ) {
			return array();
		}

		$items = array();
		foreach ( $files as $file ) {
			$resolved = $this->resolve_archive( basename( $file ) );
			if ( null !== $resolved ) {
				$items[] = $this->archive_meta( $resolved );
			}
		}

		usort(
			$items,
			static function ( $a, $b ) {
				return $b['modified'] <=> $a['modified'];
			}
		);

		return $items;
	}

	/** Count the same local files as Backups without reading archive headers or sidecars. */
	public function count_archives() {
		$dir = self::ensure_directory();
		$real_dir = realpath( $dir );
		if ( false === $real_dir ) { throw new \RuntimeException( esc_html__( 'Backup storage is unavailable.', 'nobash-backup' ) ); }
		$base = trailingslashit( wp_normalize_path( $real_dir ) );
		$files = glob( trailingslashit( $dir ) . '*.nbh' );
		if ( false === $files ) { throw new \RuntimeException( esc_html__( 'Backup storage could not be listed.', 'nobash-backup' ) ); }
		$count = 0;
		foreach ( $files as $path ) {
			if ( ! preg_match( '/^[a-zA-Z0-9._-]+\.nbh$/', basename( $path ) ) || is_link( $path ) || ! is_file( $path ) ) { continue; }
			$resolved = realpath( $path );
			if ( false !== $resolved && 0 === strpos( wp_normalize_path( $resolved ), $base ) ) { ++$count; }
		}
		return $count;
	}

	/**
	 * Meta for one archive.
	 *
	 * @param string $path Absolute file path.
	 * @return array<string, mixed>
	 */
	public function archive_meta( $path ) {
		$filename          = basename( $path );
		$encrypted         = false;
		$password_required = false;
		$regular           = ! is_link( $path ) && is_file( $path );
		if ( $regular ) {
			$password_required = \Nobash\Backup\Security\PasswordArchive::is_password_protected( $path );
			$encrypted         = $password_required;
		}

		$index   = $this->read_archive_index( $filename );
		$profile = isset( $index['profile'] ) ? sanitize_key( (string) $index['profile'] ) : $this->guess_profile_from_filename( $filename );
		if ( '' === $profile && ! empty( $index['original_name'] ) ) {
			$profile = $this->guess_profile_from_filename( sanitize_file_name( (string) $index['original_name'] ) );
		}
		$components = isset( $index['components'] ) && is_array( $index['components'] )
			? array_values( array_intersect( array( 'database', 'files', 'uploads', 'plugins', 'themes' ), array_map( 'sanitize_key', $index['components'] ) ) )
			: array();
		if ( empty( $components ) ) {
			switch ( $profile ) {
				case 'database': $components = array( 'database' ); break;
				case 'uploads': $components = array( 'uploads' ); break;
				case 'plugins': $components = array( 'plugins' ); break;
				case 'themes': $components = array( 'themes' ); break;
				case 'files': $components = array( 'files' ); break;
				default: $components = array( 'database', 'files' ); break;
			}
		}

		$integrity = sanitize_key( (string) ( $index['integrity_status'] ?? '' ) );
		if ( '' === $integrity ) {
			$integrity = ! empty( $index['verified_at'] ) ? 'verified' : ( $encrypted ? 'password_required' : 'ready_for_verification' );
		}

		return array(
			'id'               => $filename,
			'filename'         => $filename,
			'path'             => $path,
			'size'             => $regular ? (int) filesize( $path ) : 0,
			'modified'         => $regular ? (int) filemtime( $path ) : 0,
			'created'          => isset( $index['created'] ) ? (int) $index['created'] : ( $regular ? (int) filemtime( $path ) : 0 ),
			'encrypted'        => $encrypted,
			'password_required'=> $password_required,
			'profile'          => $profile,
			'profile_label'    => self::profile_label( $profile ),
			'components'       => $components,
			'backup_mode'      => sanitize_key( (string) ( $index['backup_mode'] ?? 'full' ) ),
			'chain_id'         => sanitize_text_field( (string) ( $index['chain_id'] ?? '' ) ),
			'point_id'         => sanitize_text_field( (string) ( $index['point_id'] ?? '' ) ),
			'source'           => sanitize_key( (string) ( $index['source'] ?? 'local' ) ),
			'original_name'    => sanitize_file_name( (string) ( $index['original_name'] ?? '' ) ),
			'sha256'           => preg_match( '/^[a-f0-9]{64}$/i', (string) ( $index['sha256'] ?? '' ) ) ? strtolower( (string) $index['sha256'] ) : '',
			'integrity_status' => $integrity,
			'verified_at'      => sanitize_text_field( (string) ( $index['verified_at'] ?? '' ) ),
		);
	}

	/**
	 * Short display label for a profile (full / media / database / …).
	 *
	 * @param string $profile Profile key.
	 * @return string
	 */
	public static function profile_label( $profile ) {
		$map = array(
			'full'     => 'full',
			'database' => 'database',
			'files'    => 'files',
			'uploads'  => 'media',
			'plugins'  => 'plugins',
			'themes'   => 'themes',
		);
		$profile = sanitize_key( (string) $profile );
		return isset( $map[ $profile ] ) ? $map[ $profile ] : ( $profile ? $profile : 'unknown' );
	}

	/**
	 * Guess profile from filename pattern nbh-{profile}-….
	 *
	 * @param string $filename Filename.
	 * @return string
	 */
	private function guess_profile_from_filename( $filename ) {
		return self::profile_from_filename( $filename );
	}

	/**
	 * Sidecar index path for an archive.
	 *
	 * @param string $filename Archive filename.
	 * @return string
	 */
	private function archive_index_path( $filename ) {
		$requested = (string) $filename;
		$filename  = basename( $requested );
		if ( $requested !== $filename || ! preg_match( '/^[a-zA-Z0-9._-]+\.nbh$/', $filename ) ) {
			throw new \InvalidArgumentException( esc_html__( 'Invalid backup archive filename.', 'nobash-backup' ) );
		}
		return trailingslashit( self::ensure_directory() ) . $filename . '.json';
	}

	/**
	 * Persist lightweight index next to the archive (profile, scope, …).
	 *
	 * @param string               $filename Archive filename.
	 * @param array<string, mixed> $data     Meta.
	 * @return void
	 */
	public function write_archive_index( $filename, array $data ) {
		$path = $this->archive_index_path( $filename );
		if ( is_link( $path ) || ( file_exists( $path ) && ! is_file( $path ) ) ) {
			throw new \RuntimeException( esc_html__( 'The backup metadata path is unsafe.', 'nobash-backup' ) );
		}

		$json = wp_json_encode( $data );
		if ( false === $json ) {
			throw new \RuntimeException( esc_html__( 'Nobash Backup could not encode archive metadata.', 'nobash-backup' ) );
		}
		$tmp = trailingslashit( dirname( $path ) ) . '.' . basename( $path ) . '.tmp-' . wp_generate_password( 16, false, false );
		// Exclusive-create ('xb'/O_EXCL) on a randomized temp name, then atomically
		// published via rename below. WP_Filesystem::put_contents() has no exclusive-create
		// mode, so the handle-based create/write/close stays direct.
		$handle = @fopen( $tmp, 'xb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			throw new \RuntimeException( esc_html__( 'Nobash Backup could not create temporary archive metadata.', 'nobash-backup' ) );
		}
		$remaining = $json;
		try {
			while ( '' !== $remaining ) {
				$written = fwrite( $handle, $remaining ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
				if ( false === $written || 0 === $written ) {
					throw new \RuntimeException( __( 'Nobash Backup could not write archive metadata.', 'nobash-backup' ) );
				}
				$remaining = (string) substr( $remaining, $written );
			}
			if ( ! fflush( $handle ) ) {
				throw new \RuntimeException( __( 'Nobash Backup could not flush archive metadata.', 'nobash-backup' ) );
			}
		} catch ( \Throwable $e ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			wp_delete_file( $tmp );
			throw $e;
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		self::filesystem()->chmod( $tmp, 0640 );

		clearstatcache( true, $path );
		if ( is_link( $path ) || ( file_exists( $path ) && ! is_file( $path ) ) || ! self::filesystem()->move( $tmp, $path, true ) ) {
			wp_delete_file( $tmp );
			throw new \RuntimeException( esc_html__( 'Nobash Backup could not publish archive metadata safely.', 'nobash-backup' ) );
		}
	}

	/**
	 * Read archive index sidecar.
	 *
	 * @param string $filename Archive filename.
	 * @return array<string, mixed>
	 */
	public function read_archive_index( $filename ) {
		try {
			$path = $this->archive_index_path( $filename );
		} catch ( \InvalidArgumentException $e ) {
			return array();
		}
		if ( is_link( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) {
			return array();
		}
		$size = filesize( $path );
		if ( false === $size || $size > 1048576 ) {
			return array();
		}
		$real_dir  = realpath( self::ensure_directory() );
		$real_file = realpath( $path );
		if ( false === $real_dir || false === $real_file || 0 !== strpos( wp_normalize_path( $real_file ), trailingslashit( wp_normalize_path( $real_dir ) ) ) ) {
			return array();
		}
		$raw = \Nobash\Backup\Filesystem\DirectFilesystem::get()->get_contents( $real_file );
		if ( false === $raw ) {
			return array();
		}
		$data = json_decode( (string) $raw, true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Resolve a safe archive path by filename.
	 *
	 * @param string $filename Filename only.
	 * @return string|null
	 */
	public function resolve_archive( $filename ) {
		$requested = (string) $filename;
		$filename  = basename( $requested );
		if ( $requested !== $filename || ! preg_match( '/^[a-zA-Z0-9._-]+\.nbh$/', $filename ) ) {
			return null;
		}

		$path = trailingslashit( self::ensure_directory() ) . $filename;
		if ( is_link( $path ) || ! is_file( $path ) ) {
			return null;
		}

		$real_dir  = realpath( self::get_directory() );
		$real_file = realpath( $path );
		$base      = false !== $real_dir ? trailingslashit( wp_normalize_path( $real_dir ) ) : '';
		$file      = false !== $real_file ? wp_normalize_path( $real_file ) : '';
		if ( '' === $base || '' === $file || 0 !== strpos( $file, $base ) ) {
			return null;
		}

		return $real_file;
	}

	/**
	 * Delete an archive.
	 *
	 * @param string $filename Filename.
	 * @return bool
	 */
	public function delete_archive( $filename, $mark_chain_broken = true ) {
		$path = $this->resolve_archive( $filename );
		if ( null === $path ) {
			return false;
		}

		$index_data = $this->read_archive_index( $filename );
		$index = $this->archive_index_path( $filename );

		// wp_delete_file() does not provide a reliable boolean deletion result across
		// supported WordPress versions. Verify the filesystem state after deletion.
		wp_delete_file( $path );
		clearstatcache( true, $path );
		$deleted = ! is_file( $path ) && ! is_link( $path );
		if ( ! $deleted ) {
			return false;
		}

		if ( is_link( $index ) || is_file( $index ) ) {
			wp_delete_file( $index );
		}
		if ( $mark_chain_broken && ! empty( $index_data['chain_id'] ) ) {
			( new \Nobash\Backup\Incremental\ChainRepository() )->mark_broken( (string) $index_data['chain_id'], __( 'A required archive was deleted.', 'nobash-backup' ) );
		}
		return true;
	}

	/**
	 * Temporary working directory for a job.
	 *
	 * @param int $job_id Job ID.
	 * @return string
	 */
	public function job_temp_dir( $job_id ) {
		self::assert_write_safe();
		$dir = trailingslashit( self::ensure_directory() ) . 'tmp/job-' . absint( $job_id );
		if ( is_link( $dir ) ) {
			throw new \RuntimeException( esc_html__( 'A job temporary directory cannot be a symbolic link.', 'nobash-backup' ) );
		}
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			throw new \RuntimeException( esc_html__( 'Could not create the job temporary directory.', 'nobash-backup' ) );
		}
		self::filesystem()->chmod( $dir, 0700 );
		self::harden( $dir );

		return $dir;
	}

	/**
	 * Remove a job temp directory.
	 *
	 * @param int $job_id Job ID.
	 * @return void
	 */
	public function cleanup_job_temp( $job_id ) {
		$storage = self::ensure_directory();
		$tmp     = trailingslashit( $storage ) . 'tmp';
		$dir     = trailingslashit( $tmp ) . 'job-' . absint( $job_id );
		if ( file_exists( $dir ) || is_link( $dir ) ) {
			\Nobash\Backup\Security\SafeTreeRemoval::remove( $dir, $tmp );
		}
	}

	/**
	 * Recursively remove directory.
	 *
	 * @param string $dir Directory.
	 * @return void
	 */
	public function rmdir_recursive( $dir ) {
		$dir = untrailingslashit( (string) $dir );
		if ( '' === $dir || ( ! file_exists( $dir ) && ! is_link( $dir ) ) ) {
			return;
		}
		$storage = self::ensure_directory();
		\Nobash\Backup\Security\SafeTreeRemoval::remove( $dir, $storage );
	}

	/**
	 * Disk free space in bytes for storage partition.
	 *
	 * @return int|false
	 */
	public function free_space() {
		return disk_free_space( self::get_directory() );
	}
}
