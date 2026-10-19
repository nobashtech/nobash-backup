<?php
/**
 * Local-disk WP_Filesystem access that works on every host.
 *
 * @package Nobash\Backup
 */

namespace Nobash\Backup\Filesystem;

defined( 'ABSPATH' ) || exit;

/**
 * Hands out a WP_Filesystem_Direct instance for local backup and restore work.
 *
 * WP_Filesystem() with no credentials returns false on hosts where WordPress cannot
 * use the direct method (for example when the PHP user does not own the files), which
 * would leave the global unusable in background jobs that have no way to prompt for
 * FTP details. This plugin only ever touches the local disk, so it always uses the
 * direct method and never depends on the request-scoped $wp_filesystem global (which
 * may be an FTP/SSH transport that expects paths relative to a different root).
 */
final class DirectFilesystem {

	/**
	 * Shared instance.
	 *
	 * @var \WP_Filesystem_Base|null
	 */
	private static $instance = null;

	/**
	 * Get the direct filesystem.
	 *
	 * @return \WP_Filesystem_Base
	 */
	public static function get() {
		if ( null === self::$instance ) {
			if ( ! class_exists( 'WP_Filesystem_Direct', false ) ) {
				require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
				require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
			}
			// WP_Filesystem() defines these only after a successful connect; the direct
			// class reads them when no explicit mode is passed to chmod()/put_contents().
			if ( ! defined( 'FS_CHMOD_DIR' ) ) {
				define( 'FS_CHMOD_DIR', 0755 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Guarded fallback required by WP_Filesystem_Direct.
			}
			if ( ! defined( 'FS_CHMOD_FILE' ) ) {
				define( 'FS_CHMOD_FILE', 0644 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Guarded fallback required by WP_Filesystem_Direct.
			}
			self::$instance = new \WP_Filesystem_Direct( null );
		}
		return self::$instance;
	}
}
