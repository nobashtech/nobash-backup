<?php
/**
 * Runtime WordPress path helpers.
 *
 * @package Nobash\Backup
 */

namespace Nobash\Backup\Compat;

defined( 'ABSPATH' ) || exit;

/** Resolve installation paths through WordPress APIs instead of hard-coded layout assumptions. */
class Paths {
	/**
	 * Filesystem root for the current WordPress installation.
	 *
	 * @return string
	 */
	public static function wordpress_root() {
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		return trailingslashit( wp_normalize_path( get_home_path() ) );
	}

	/**
	 * Active uploads base directory.
	 *
	 * @return string
	 */
	public static function uploads_root() {
		$upload = wp_upload_dir();
		if ( ! empty( $upload['error'] ) || empty( $upload['basedir'] ) ) {
			throw new \RuntimeException( esc_html__( 'WordPress could not resolve the uploads directory.', 'nobash-backup' ) );
		}
		return untrailingslashit( wp_normalize_path( (string) $upload['basedir'] ) );
	}

	/**
	 * Active uploads base URL.
	 *
	 * @return string
	 */
	public static function uploads_url() {
		$upload = wp_upload_dir();
		if ( ! empty( $upload['error'] ) || empty( $upload['baseurl'] ) ) {
			throw new \RuntimeException( esc_html__( 'WordPress could not resolve the uploads URL.', 'nobash-backup' ) );
		}
		return untrailingslashit( (string) $upload['baseurl'] );
	}

	/**
	 * Directory containing standard plugins, derived from this plugin's runtime path.
	 *
	 * @return string
	 */
	public static function plugins_root() {
		return untrailingslashit( wp_normalize_path( dirname( NOBASH_BACKUP_PATH ) ) );
	}

	/**
	 * Convert an absolute path to an archive-relative path beneath WordPress root.
	 *
	 * @return string Empty when the path is outside the WordPress installation root.
	 */
	public static function relative_to_wordpress( $absolute ) {
		$root = trailingslashit( wp_normalize_path( self::wordpress_root() ) );
		$path = wp_normalize_path( (string) $absolute );
		if ( untrailingslashit( $path ) === untrailingslashit( $root ) ) { return ''; }
		if ( 0 !== strpos( trailingslashit( $path ), $root ) ) { return ''; }
		return trim( substr( $path, strlen( $root ) ), '/' );
	}

	/** Reserved archive namespace for WordPress-managed roots outside the installation root. */
	const EXTERNAL_ROOT_PREFIX = '__nobash_external_roots_v1__';

	/**
	 * Convert a runtime path to a portable archive-relative path.
	 *
	 * Standard layouts keep their ordinary WordPress-relative paths. When uploads,
	 * plugins or themes live outside the installation root, they are represented by a
	 * reserved virtual prefix so a restore never turns an absolute server path into a
	 * path beneath the destination site's root.
	 *
	 * @param string $absolute Absolute runtime path.
	 * @return string Empty only for the WordPress root itself or an unsupported path.
	 */
	public static function archive_relative( $absolute ) {
		$path = untrailingslashit( wp_normalize_path( (string) $absolute ) );
		$root = untrailingslashit( wp_normalize_path( self::wordpress_root() ) );
		if ( '' === $path ) { return ''; }
		if ( $path === $root ) { return ''; }
		if ( 0 === strpos( $path . '/', $root . '/' ) ) {
			return trim( substr( $path, strlen( $root ) ), '/' );
		}

		$managed = array(
			'uploads' => self::uploads_root(),
			'plugins' => self::plugins_root(),
			'themes'  => get_theme_root(),
		);
		foreach ( $managed as $type => $base ) {
			$base = untrailingslashit( wp_normalize_path( (string) $base ) );
			if ( '' === $base || ( $path !== $base && 0 !== strpos( $path . '/', $base . '/' ) ) ) { continue; }
			$collision = trailingslashit( $root ) . self::EXTERNAL_ROOT_PREFIX;
			if ( file_exists( $collision ) || is_link( $collision ) ) {
				throw new \RuntimeException( esc_html__( 'Backup cannot map an external WordPress directory because the reserved archive path already exists in the site root.', 'nobash-backup' ) );
			}
			$suffix = $path === $base ? '' : ltrim( substr( $path, strlen( $base ) ), '/' );
			return self::EXTERNAL_ROOT_PREFIX . '/' . $type . ( '' !== $suffix ? '/' . $suffix : '' );
		}
		return '';
	}

	/** Resolve one archive-relative file path to the correct runtime WordPress root. */
	public static function resolve_archive_path( $relative ) {
		$relative = trim( str_replace( '\\', '/', (string) $relative ), '/' );
		$prefix = self::EXTERNAL_ROOT_PREFIX . '/';
		if ( 0 === strpos( $relative, $prefix ) ) {
			$rest = substr( $relative, strlen( $prefix ) );
			$parts = explode( '/', $rest, 2 );
			$type = sanitize_key( (string) ( $parts[0] ?? '' ) );
			$suffix = (string) ( $parts[1] ?? '' );
			$roots = array(
				'uploads' => self::uploads_root(),
				'plugins' => self::plugins_root(),
				'themes'  => get_theme_root(),
			);
			if ( ! isset( $roots[ $type ] ) || '' === $suffix ) {
				throw new \RuntimeException( esc_html__( 'Invalid external WordPress archive path.', 'nobash-backup' ) );
			}
			return \Nobash\Backup\Security\PathBoundary::resolve( $roots[ $type ], $suffix );
		}
		return \Nobash\Backup\Security\PathBoundary::resolve( self::wordpress_root(), $relative );
	}

	/** @return string */
	public static function uploads_relative() { return self::archive_relative( self::uploads_root() ); }

	/** @return string */
	public static function plugins_relative() { return self::archive_relative( self::plugins_root() ); }

	/** @return string */
	public static function themes_relative() { return self::archive_relative( get_theme_root() ); }
}
