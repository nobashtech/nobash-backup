<?php
/**
 * Runtime environment detection (PHP, DB, server, Multisite, WooCommerce).
 *
 * @package Nobash\Backup
 */

namespace Nobash\Backup\Compat;

defined( 'ABSPATH' ) || exit;

/**
 * Class Environment
 */
class Environment {

	/**
	 * Supported PHP range (inclusive lower, exclusive upper for composer-style).
	 */
	const PHP_MIN = '7.4.0';
	const PHP_MAX = '8.6.99';

	/**
	 * Full compatibility snapshot for REST / Help UI.
	 *
	 * @return array<string, mixed>
	 */
	public static function report() {
		return array(
			'php'           => array(
				'version'   => PHP_VERSION,
				'supported' => self::php_supported(),
				'range'     => '7.4 – 8.6',
			),
			'database'      => array(
				'engine'    => self::db_engine(),
				'version'   => self::db_version(),
				'supported' => self::db_supported(),
			),
			'server'        => array(
				'software'  => self::server_software(),
				'family'    => self::server_family(),
				'hardening' => self::hardening_notes(),
			),
			'hosting'       => HostingEnvironment::snapshot(),
			'multisite'     => array(
				'enabled'     => is_multisite(),
				'network_admin'=> is_multisite() && is_network_admin(),
				'blog_id'     => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1,
				'can_network' => self::can_network_backup(),
			),
			'woocommerce'   => WooCommerceSupport::status(),
			'extensions'    => array(
				'zip'     => class_exists( 'ZipArchive' ),
				'sodium'  => function_exists( 'sodium_crypto_secretstream_xchacha20poly1305_init_push' ),
				'openssl' => function_exists( 'openssl_encrypt' ),
				'mbstring'=> function_exists( 'mb_strlen' ),
			),
		);
	}

	/**
	 * @return bool
	 */
	public static function php_supported() {
		return version_compare( PHP_VERSION, self::PHP_MIN, '>=' )
			&& version_compare( PHP_VERSION, '8.7.0', '<' );
	}

	/**
	 * Database engine slug.
	 *
	 * @return string mysql|mariadb|sqlite|unknown
	 */
	public static function db_engine() {
		global $wpdb;

		if ( defined( 'DB_ENGINE' ) && is_string( DB_ENGINE ) && '' !== DB_ENGINE ) {
			$forced = strtolower( (string) DB_ENGINE );
			if ( in_array( $forced, array( 'mysql', 'mariadb', 'sqlite' ), true ) ) {
				return $forced;
			}
		}

		// Official SQLite integration / drop-in.
		if ( defined( 'DATABASE_TYPE' ) && 'sqlite' === DATABASE_TYPE ) {
			return 'sqlite';
		}
		if ( class_exists( '\\WP_SQLite_DB', false ) || class_exists( '\\WP_SQLite_Driver', false ) ) {
			return 'sqlite';
		}
		// Inspect the WordPress-provided handle only; this does not create a direct database connection.
		// phpcs:ignore WordPress.DB.RestrictedClasses.mysql__PDO
		if ( isset( $wpdb->dbh ) && is_object( $wpdb->dbh ) && class_exists( '\\PDO', false ) && $wpdb->dbh instanceof \PDO ) {
			try {
				// Inspect the existing WordPress handle; never open a second connection.
				// phpcs:ignore WordPress.DB.RestrictedClasses.mysql__PDO
				$driver = $wpdb->dbh->getAttribute( \PDO::ATTR_DRIVER_NAME );
				if ( 'sqlite' === $driver ) {
					return 'sqlite';
				}
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			}
		}

		$ver = self::db_version();
		if ( $ver && false !== stripos( $ver, 'mariadb' ) ) {
			return 'mariadb';
		}
		if ( $ver ) {
			return 'mysql';
		}

		return 'unknown';
	}

	/**
	 * @return string
	 */
	public static function db_version() {
		global $wpdb;
		if ( ! empty( $wpdb->db_version() ) ) {
			return (string) $wpdb->db_version();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$v = $wpdb->get_var( 'SELECT VERSION()' );
		return is_string( $v ) ? $v : '';
	}

	/**
	 * @return bool
	 */
	public static function db_supported() {
		return in_array( self::db_engine(), array( 'mysql', 'mariadb', 'sqlite', 'unknown' ), true );
	}

	/**
	 * @return string
	 */
	public static function server_software() {
		if ( empty( $_SERVER['SERVER_SOFTWARE'] ) ) {
			return 'unknown';
		}
		return sanitize_text_field( wp_unslash( (string) $_SERVER['SERVER_SOFTWARE'] ) );
	}

	/**
	 * @return string apache|nginx|litespeed|iis|unknown
	 */
	public static function server_family() {
		$s = strtolower( self::server_software() );
		if ( false !== strpos( $s, 'nginx' ) ) {
			return 'nginx';
		}
		if ( false !== strpos( $s, 'litespeed' ) || false !== strpos( $s, 'openlitespeed' ) ) {
			return 'litespeed';
		}
		if ( false !== strpos( $s, 'microsoft-iis' ) || false !== strpos( $s, 'iis' ) ) {
			return 'iis';
		}
		if ( false !== strpos( $s, 'apache' ) || false !== strpos( $s, 'httpd' ) ) {
			return 'apache';
		}
		return 'unknown';
	}

	/**
	 * @return string
	 */
	public static function hardening_notes() {
		switch ( self::server_family() ) {
			case 'nginx':
				return __( 'Nginx detected. Nobash Backup uses installation-specific local storage, authenticated archive downloads, and writes an optional deny snippet (nginx.conf) for server administrators.', 'nobash-backup' );
			case 'iis':
				return __( 'IIS web.config deny rules are written into the backup folder.', 'nobash-backup' );
			case 'litespeed':
			case 'apache':
				return __( '.htaccess deny rules are written into the backup folder.', 'nobash-backup' );
			default:
				return __( 'index.html + .htaccess + web.config + nginx.conf hardening files are written into the backup folder.', 'nobash-backup' );
		}
	}

	/**
	 * @return bool
	 */
	public static function can_network_backup() {
		if ( ! is_multisite() ) {
			return false;
		}
		return current_user_can( 'manage_network_options' ) || current_user_can( 'manage_network' );
	}
}
