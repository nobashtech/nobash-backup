<?php
/**
 * Plugin Name:       Nobash Backup
 * Plugin URI:        https://backup.nobash.com/
 * Description:       Reliable WordPress backup and restore with advanced scheduling, password-protected backups, and resumable large-site processing.
 * Version:           1.0.0.1
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Nobash
 * Author URI:        https://nobash.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       nobash-backup
 * Domain Path:       /languages
 *
 * @package Nobash\Backup
 */

defined( 'ABSPATH' ) || exit;

define( 'NOBASH_BACKUP_VERSION', '1.0.0.1' );
define( 'NOBASH_BACKUP_FILE', __FILE__ );
define( 'NOBASH_BACKUP_PATH', plugin_dir_path( __FILE__ ) );
define( 'NOBASH_BACKUP_URL', plugin_dir_url( __FILE__ ) );
define( 'NOBASH_BACKUP_BASENAME', plugin_basename( __FILE__ ) );
define( 'NOBASH_BACKUP_EXTENSION_API_VERSION', '1.0.0' );

require_once NOBASH_BACKUP_PATH . 'includes/Autoloader.php';
Nobash\Backup\Autoloader::register();

require_once NOBASH_BACKUP_PATH . 'includes/Extension/functions.php';

register_activation_hook( __FILE__, array( 'Nobash\\Backup\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Nobash\\Backup\\Plugin', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		Nobash\Backup\Plugin::instance()->boot();
	},
	5
);
