<?php
/**
 * Capability helper.
 *
 * @package Nobash\Backup
 */

namespace Nobash\Backup\Security;

use Nobash\Backup\Compat\Environment;

defined( 'ABSPATH' ) || exit;

/**
 * Class Capabilities
 */
class Capabilities {

	/**
	 * Required capability for managing backups.
	 *
	 * @return string
	 */
	public static function required() {
		// Archives and restore targets include the network's shared filesystem.
		// Site administrators must not gain access through a site-screen REST URL.
		$cap = is_multisite() ? 'manage_network_options' : 'manage_options';

		/**
		 * Filter the capability required to manage Nobash Backup.
		 *
		 * @param string $capability Capability slug.
		 */
		return (string) apply_filters( 'nobash_backup_capability', $cap );
	}

	/**
	 * Current user can manage.
	 *
	 * @return bool
	 */
	public static function current_user_can() {
		if ( is_multisite() && ! current_user_can( 'manage_network_options' ) ) {
			return false;
		}
		if ( current_user_can( self::required() ) ) {
			return true;
		}
		// Multisite: network admins can manage from site screens too.
		if ( is_multisite() && Environment::can_network_backup() ) {
			return true;
		}
		return false;
	}
}
