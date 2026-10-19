<?php
/**
 * Path exclusion helpers.
 *
 * @package Nobash\Backup
 */

namespace Nobash\Backup\Filesystem;

use Nobash\Backup\Storage\LocalStorage;

defined( 'ABSPATH' ) || exit;

/**
 * Class Exclusions
 */
class Exclusions {

	/**
	 * Compiled exclusion rules for the current request.
	 *
	 * @var array<string,array<int,string>>|null
	 */
	private static $compiled_rules = null;

	/**
	 * Default path exclusions relative to the current WordPress installation root, plus the active NBH storage path.
	 *
	 * The list is intentionally conservative: it excludes generated backup archives,
	 * caches, logs and temporary workspaces, but not plugin/theme code or user uploads.
	 *
	 * @return array<int, string>
	 */
	public static function default_paths() {
		$paths = array(
			'.git', '.svn', '.hg', '.idea', '.vscode', 'node_modules', '__MACOSX',
			'.DS_Store', 'Thumbs.db', 'desktop.ini', '.env', 'error_log', 'php_errorlog',
		);

		$uploads_rel = \Nobash\Backup\Compat\Paths::uploads_relative();
		$plugins_rel = \Nobash\Backup\Compat\Paths::plugins_relative();
		$themes_rel  = \Nobash\Backup\Compat\Paths::themes_relative();
		$content_rel = '';
		$external_prefix = \Nobash\Backup\Compat\Paths::EXTERNAL_ROOT_PREFIX . '/';
		foreach ( array( $plugins_rel, $themes_rel ) as $candidate ) {
			$candidate = trim( str_replace( '\\', '/', (string) $candidate ), '/' );
			if ( '' !== $candidate && 0 !== strpos( $candidate, $external_prefix ) && false !== strpos( $candidate, '/' ) ) {
				$content_rel = trim( dirname( $candidate ), './' );
				break;
			}
		}

		$add = static function ( &$list, $base, $suffix ) {
			$base = trim( str_replace( '\\', '/', (string) $base ), '/' );
			if ( '' !== $base ) { $list[] = $base . '/' . ltrim( (string) $suffix, '/' ); }
		};

		// Generated content beneath the runtime-resolved content directory.
		foreach ( array( 'cache', 'litespeed', 'wflogs', 'upgrade', 'upgrade-temp-backup', 'debug.log', 'error_log', 'updraft', 'ai1wm-backups', 'wpvividbackups', 'backups-dup-lite', 'backups-dup-pro', 'wp-clone', 'boldgrid-backup' ) as $suffix ) {
			$add( $paths, $content_rel, $suffix );
		}

		// Generated upload subdirectories and backup-plugin data beneath the active upload root.
		foreach ( array( 'cache', 'wc-logs', 'woocommerce_transient_files', 'nobash-backups', 'nobash-backup', 'wpvividbackups', 'backupbuddy_backups', 'backupbuddy_temp', 'solid-backups', 'backup-guard', 'wp-staging', 'wp-staging-pro', 'wp-clone', 'total-upkeep', 'database-backups', 'ithemes-security/backups', 'snapshots', 'wp-time-capsule', 'xcloner-backups', 'wp-file-manager-pro/fm_backup' ) as $suffix ) {
			$add( $paths, $uploads_rel, $suffix );
		}
		$paths[] = 'wp-snapshots';

		// Always exclude the real active .nbh archive directory, including a custom path.
		if ( class_exists( LocalStorage::class ) ) {
			$storage = LocalStorage::get_directory();
			if ( '' !== trim( (string) $storage ) ) { $paths[] = $storage; }
		} else {
			$paths[] = trailingslashit( \Nobash\Backup\Compat\Paths::uploads_root() ) . 'nobash-backups';
		}

		$settings = get_option( 'nobash_backup_settings', array() );
		if ( ! empty( $settings['exclude_paths'] ) && is_array( $settings['exclude_paths'] ) ) {
			foreach ( $settings['exclude_paths'] as $extra ) {
				$extra = trim( (string) $extra );
				if ( '' !== $extra ) { $paths[] = $extra; }
			}
		}

		/**
		 * Filter excluded paths for backups.
		 *
		 * @param array<int, string> $paths Paths.
		 */
		$paths = (array) apply_filters( 'nobash_backup_exclude_paths', $paths );
		return self::clean_rules( $paths );
	}

	/**
	 * Default path globs relative to the current WordPress installation root.
	 *
	 * These cover backup plugins that create per-job or hash-named directories.
	 *
	 * @return array<int,string>
	 */
	public static function default_globs() {
		$globs = array( 'nbh-stg-*' );
		$uploads_rel = \Nobash\Backup\Compat\Paths::uploads_relative();
		$plugins_rel = \Nobash\Backup\Compat\Paths::plugins_relative();
		$content_rel = '';
		$plugins_rel = trim( str_replace( '\\', '/', (string) $plugins_rel ), '/' );
		$external_prefix = \Nobash\Backup\Compat\Paths::EXTERNAL_ROOT_PREFIX . '/';
		if ( '' !== $plugins_rel && 0 !== strpos( $plugins_rel, $external_prefix ) && false !== strpos( $plugins_rel, '/' ) ) { $content_rel = trim( dirname( $plugins_rel ), './' ); }
		foreach ( array( 'backwpup-*-backups', 'backwpup-*-logs', 'backwpup-*-temp' ) as $suffix ) {
			if ( '' !== $content_rel ) { $globs[] = $content_rel . '/' . $suffix; }
			if ( '' !== $uploads_rel ) { $globs[] = trim( $uploads_rel, '/' ) . '/' . $suffix; }
		}

		/** @param array<int,string> $globs Glob patterns relative to the current WordPress installation root. */
		return self::clean_rules( (array) apply_filters( 'nobash_backup_exclude_globs', $globs ) );
	}

	/**
	 * Default exact basenames excluded anywhere in the scanned tree.
	 *
	 * @return array<int,string>
	 */
	public static function default_basenames() {
		$names = array(
			'.DS_Store',
			'Thumbs.db',
			'desktop.ini',
			'debug.log',
			'error_log',
			'php_errorlog',
		);

		/**
		 * Filter exact filename exclusions for backups.
		 *
		 * @param array<int,string> $names Basenames.
		 */
		return self::clean_rules( (array) apply_filters( 'nobash_backup_exclude_basenames', $names ) );
	}

	/**
	 * Reset the per-request compiled rule cache.
	 *
	 * Useful after settings are updated and for deterministic tests.
	 *
	 * @return void
	 */
	public static function reset_cache() {
		self::$compiled_rules = null;
	}

	/**
	 * Default excluded tables (without prefix).
	 *
	 * @return array<int, string>
	 */
	public static function default_tables() {
		$tables = array(
			'nobash_jobs',
			'nobash_job_events',
			'nobash_file_inventory',
			'nobash_transfers',
			'nobash_schedules',
			'nobash_backup_profiles',
			'nobash_backup_chains',
			'nobash_restore_points',
			'nobash_file_snapshots',
			'nobash_addons',
			'actionscheduler_actions',
			'actionscheduler_claims',
			'actionscheduler_groups',
			'actionscheduler_logs',
		);

		$settings = get_option( 'nobash_backup_settings', array() );
		if ( ! empty( $settings['exclude_tables'] ) && is_array( $settings['exclude_tables'] ) ) {
			foreach ( $settings['exclude_tables'] as $extra ) {
				$extra = sanitize_key( (string) $extra );
				if ( '' !== $extra ) {
					$tables[] = $extra;
				}
			}
		}

		/**
		 * Filter excluded table names (without prefix).
		 *
		 * @param array<int, string> $tables Tables.
		 */
		return (array) apply_filters( 'nobash_backup_exclude_tables', $tables );
	}

	/**
	 * Whether a path should be excluded.
	 *
	 * @param string $absolute Absolute path.
	 * @return bool
	 */
	public static function is_excluded( $absolute ) {
		$normalized = rtrim( wp_normalize_path( (string) $absolute ), '/' );
		if ( ServerFilePolicy::is_excluded( $normalized ) ) { return true; }
		if ( '' === $normalized ) {
			return false;
		}

		$rules = self::compiled_rules();

		foreach ( $rules['absolute_prefixes'] as $prefix ) {
			if ( self::path_has_prefix( $normalized, $prefix ) ) {
				return true;
			}
		}

		$root = $rules['root'];
		try {
			$relative = \Nobash\Backup\Compat\Paths::archive_relative( $normalized );
		} catch ( \Throwable $e ) {
			// Keep exclusion checks available even if a custom layout collides with
			// the reserved portable archive namespace. Backup creation will surface
			// the mapping error separately when the collector reaches that path.
			$relative = self::path_has_prefix( $normalized, $root )
				? ltrim( substr( $normalized, strlen( $root ) ), '/' )
				: '';
		}

		if ( '' !== $relative ) {
			$slash = strpos( $relative, '/' );
			$first = false === $slash ? $relative : substr( $relative, 0, $slash );
			foreach ( (array) ( $rules['relative_prefixes_by_first'][ $first ] ?? array() ) as $prefix ) {
				if ( self::path_has_prefix( $relative, $prefix ) ) {
					return true;
				}
			}

			$segments = explode( '/', $relative );
			foreach ( $segments as $segment ) {
				if ( isset( $rules['segments_set'][ $segment ] ) ) {
					return true;
				}
			}

			if ( isset( $rules['basenames_set'][ basename( $relative ) ] ) ) {
				return true;
			}

			if ( self::matches_path_or_parent_glob( $relative, $rules['relative_globs'] ) ) {
				return true;
			}
		}

		if ( self::matches_path_or_parent_glob( $normalized, $rules['absolute_globs'] ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Compile and cache rules once per request.
	 *
	 * @return array<string,array<int,string>|string>
	 */
	private static function compiled_rules() {
		if ( null !== self::$compiled_rules ) {
			return self::$compiled_rules;
		}

		$root = rtrim( wp_normalize_path( \Nobash\Backup\Compat\Paths::wordpress_root() ), '/' );
		$compiled = array(
			'root'              => $root,
			'absolute_prefixes' => array(),
			'relative_prefixes' => array(),
			'absolute_globs'    => array(),
			'relative_globs'    => array(),
			'segments'          => array(),
			'basenames'         => self::default_basenames(),
			'relative_prefixes_by_first' => array(),
			'segments_set'      => array(),
			'basenames_set'     => array(),
		);

		foreach ( self::default_paths() as $rule ) {
			$rule = wp_normalize_path( $rule );
			if ( self::has_wildcard( $rule ) ) {
				if ( self::is_absolute_path( $rule ) ) {
					$compiled['absolute_globs'][] = rtrim( $rule, '/' );
				} else {
					$compiled['relative_globs'][] = ltrim( rtrim( $rule, '/' ), '/' );
				}
				continue;
			}

			if ( self::is_absolute_path( $rule ) ) {
				$compiled['absolute_prefixes'][] = rtrim( $rule, '/' );
			} elseif ( false === strpos( $rule, '/' ) ) {
				$compiled['segments'][] = $rule;
			} else {
				$compiled['relative_prefixes'][] = ltrim( rtrim( $rule, '/' ), '/' );
			}
		}

		foreach ( self::default_globs() as $glob ) {
			$glob = wp_normalize_path( $glob );
			if ( self::is_absolute_path( $glob ) ) {
				$compiled['absolute_globs'][] = rtrim( $glob, '/' );
			} else {
				$compiled['relative_globs'][] = ltrim( rtrim( $glob, '/' ), '/' );
			}
		}

		foreach ( array( 'absolute_prefixes', 'relative_prefixes', 'absolute_globs', 'relative_globs', 'segments', 'basenames' ) as $key ) {
			$compiled[ $key ] = array_values( array_unique( (array) $compiled[ $key ] ) );
		}
		foreach ( $compiled['relative_prefixes'] as $prefix ) {
			$slash = strpos( $prefix, '/' );
			$first = false === $slash ? $prefix : substr( $prefix, 0, $slash );
			if ( ! isset( $compiled['relative_prefixes_by_first'][ $first ] ) ) {
				$compiled['relative_prefixes_by_first'][ $first ] = array();
			}
			$compiled['relative_prefixes_by_first'][ $first ][] = $prefix;
		}
		$compiled['segments_set'] = array_fill_keys( $compiled['segments'], true );
		$compiled['basenames_set'] = array_fill_keys( $compiled['basenames'], true );

		self::$compiled_rules = $compiled;
		return self::$compiled_rules;
	}

	/**
	 * Normalize, trim and deduplicate string rules.
	 *
	 * @param array<int,mixed> $rules Rules.
	 * @return array<int,string>
	 */
	private static function clean_rules( array $rules ) {
		$clean = array();
		foreach ( $rules as $rule ) {
			$rule = trim( (string) $rule );
			if ( '' !== $rule ) {
				$clean[] = $rule;
			}
		}
		return array_values( array_unique( $clean ) );
	}

	/**
	 * Boundary-safe prefix check.
	 *
	 * @param string $path Path.
	 * @param string $prefix Prefix.
	 * @return bool
	 */
	private static function path_has_prefix( $path, $prefix ) {
		$path   = rtrim( (string) $path, '/' );
		$prefix = rtrim( (string) $prefix, '/' );
		return $path === $prefix || ( '' !== $prefix && 0 === strpos( $path, $prefix . '/' ) );
	}

	/**
	 * Whether a path is absolute on Unix or Windows.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	private static function is_absolute_path( $path ) {
		return 0 === strpos( $path, '/' ) || 1 === preg_match( '#^[A-Za-z]:/#', $path );
	}

	/**
	 * Whether a string contains a supported wildcard.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	private static function has_wildcard( $value ) {
		return false !== strpos( $value, '*' ) || false !== strpos( $value, '?' );
	}

	/**
	 * Match a path or any parent directory against globs.
	 *
	 * @param string            $path Path.
	 * @param array<int,string> $globs Globs.
	 * @return bool
	 */
	private static function matches_path_or_parent_glob( $path, array $globs ) {
		$probe = rtrim( wp_normalize_path( $path ), '/' );
		while ( '' !== $probe && '.' !== $probe && '/' !== $probe ) {
			foreach ( $globs as $glob ) {
				if ( self::glob_match( $glob, $probe ) ) {
					return true;
				}
			}
			$parent = wp_normalize_path( dirname( $probe ) );
			if ( $parent === $probe || '.' === $parent ) {
				break;
			}
			$probe = $parent;
		}
		return false;
	}

	/**
	 * Match one normalized path against a simple glob.
	 *
	 * @param string $pattern Pattern.
	 * @param string $path Path.
	 * @return bool
	 */
	private static function glob_match( $pattern, $path ) {
		if ( function_exists( 'fnmatch' ) ) {
			$flags = defined( 'FNM_PATHNAME' ) ? FNM_PATHNAME : 0;
			return fnmatch( $pattern, $path, $flags );
		}

		$quoted = preg_quote( $pattern, '#' );
		$quoted = str_replace( array( '\\*', '\\?' ), array( '[^/]*', '[^/]' ), $quoted );
		return 1 === preg_match( '#^' . $quoted . '$#', $path );
	}
}
