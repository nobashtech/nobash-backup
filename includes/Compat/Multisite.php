<?php
/**
 * Multisite backup scope helpers.
 *
 * @package Nobash\Backup
 */

namespace Nobash\Backup\Compat;

defined( 'ABSPATH' ) || exit;

/**
 * Class Multisite
 */
class Multisite {

	/**
	 * Normalize backup scope.
	 *
	 * @param string $scope Requested scope.
	 * @return string site|network
	 */
	public static function normalize_scope( $scope ) {
		$scope = sanitize_key( (string) $scope );
		if ( 'network' === $scope && is_multisite() ) {
			// This is a pure scope normalizer, also used by unauthenticated cron
			// workers. Permission is checked at the job-creation REST/CLI boundary.
			return 'network';
		}
		return 'site';
	}

	/**
	 * Base table prefix for the install.
	 *
	 * @return string
	 */
	public static function base_prefix() {
		global $wpdb;
		return isset( $wpdb->base_prefix ) ? (string) $wpdb->base_prefix : (string) $wpdb->prefix;
	}

	/**
	 * Whether a table belongs to the current site (or network when scoped).
	 *
	 * @param string $table Full table name.
	 * @param string $scope site|network.
	 * @return bool
	 */
	public static function table_in_scope( $table, $scope = 'site' ) {
		global $wpdb;

		$scope = self::normalize_scope( $scope );
		$base  = self::base_prefix();

		if ( 'network' === $scope ) {
			return 0 === strpos( $table, $base );
		}

		// Current blog tables. The main site's base prefix also prefixes every
		// numbered subsite, so explicitly reject wp_2_*, wp_3_* and similar.
		if ( 0 === strpos( $table, $wpdb->prefix ) ) {
			if ( is_multisite() && is_main_site() && preg_match( '/^' . preg_quote( $base, '/' ) . '\d+_/', $table ) ) { return false; }
			return true;
		}

		// On the main site, also include network-global tables (users, blogs, sitemeta, …).
		if ( is_multisite() && is_main_site() ) {
			$globals = self::global_table_names();
			return in_array( $table, $globals, true );
		}

		return false;
	}

	/**
	 * Global (network) table full names.
	 *
	 * @return array<int, string>
	 */
	public static function global_table_names() {
		global $wpdb;
		$base = self::base_prefix();
		$names = array(
			$base . 'users',
			$base . 'usermeta',
			$base . 'blogs',
			$base . 'blogmeta',
			$base . 'site',
			$base . 'sitemeta',
			$base . 'signups',
			$base . 'registration_log',
		);

		/**
		 * Filter multisite global table names included with main-site / network backups.
		 *
		 * @param array<int, string> $names Full table names.
		 */
		return (array) apply_filters( 'nobash_backup_multisite_global_tables', $names );
	}

	/**
	 * Upload directories for the current scope.
	 *
	 * @param string $scope site|network.
	 * @return array<int, string>
	 */
	public static function upload_roots( $scope = 'site' ) {
		$scope = self::normalize_scope( $scope );
		$roots = array();

		if ( 'network' !== $scope || ! is_multisite() ) {
			$upload = wp_upload_dir();
			if ( empty( $upload['error'] ) && ! empty( $upload['basedir'] ) ) {
				$roots[] = $upload['basedir'];
			}
			return $roots;
		}

		$sites = get_sites(
			array(
				'number' => 0,
				'fields' => 'ids',
			)
		);
		if ( ! is_array( $sites ) ) {
			$sites = array();
		}

		foreach ( $sites as $blog_id ) {
			switch_to_blog( (int) $blog_id );
			$upload = wp_upload_dir();
			restore_current_blog();
			if ( empty( $upload['error'] ) && ! empty( $upload['basedir'] ) && is_dir( $upload['basedir'] ) ) {
				$roots[] = $upload['basedir'];
			}
		}

		return array_values( array_unique( $roots ) );
	}

	/**
	 * Metadata for manifest.
	 *
	 * @param string $scope Scope.
	 * @return array<string, mixed>
	 */
	public static function manifest_meta( $scope = 'site', array $extra = array() ) {
		// The job scope was already authorized and normalized during creation/init.
		// Do not re-check the logged-in user during a later cron slice.
		$scope = 'network' === sanitize_key( (string) $scope ) && is_multisite() ? 'network' : 'site';
		$meta  = array(
			'multisite'   => is_multisite(),
			'scope'       => $scope,
			'blog_id'     => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1,
			'base_prefix' => self::base_prefix(),
			'site_count'  => 1,
		);

		if ( is_multisite() ) {
			$meta['site_count'] = (int) get_blog_count();
			if ( 'network' === $scope ) {
				$ids = get_sites( array( 'number' => 0, 'fields' => 'ids' ) );
				$meta['blog_ids'] = is_array( $ids ) ? array_map( 'intval', $ids ) : array();
			}
		}

		// Optional extensions may supply portable identity metadata here.
		// Keep the payload bounded and explicitly typed because it is written into
		// the archive manifest and later trusted by restore reconciliation.
		$allowed = array(
			'archive_type', 'source_network_id', 'source_blog_id', 'domain', 'path',
			'home', 'siteurl', 'uploads_relative', 'shared_users', 'site_users',
			'site_users_total', 'site_users_truncated', 'subdomain_install', 'site_ids',
		);
		foreach ( $allowed as $key ) {
			if ( ! array_key_exists( $key, $extra ) ) { continue; }
			$value = $extra[ $key ];
			switch ( $key ) {
				case 'source_network_id':
				case 'source_blog_id':
				case 'site_users_total':
					$meta[ $key ] = max( 0, (int) $value );
					break;
				case 'site_users_truncated':
				case 'subdomain_install':
					$meta[ $key ] = (bool) $value;
					break;
				case 'site_ids':
					$meta[ $key ] = array_slice( array_values( array_unique( array_map( 'intval', (array) $value ) ) ), 0, 10000 );
					break;
				case 'site_users':
					$users = array();
					foreach ( array_slice( (array) $value, 0, 5000 ) as $user ) {
						if ( ! is_array( $user ) ) { continue; }
						$users[] = array(
							'source_id'       => max( 0, (int) ( $user['source_id'] ?? 0 ) ),
							'user_login'      => sanitize_user( (string) ( $user['user_login'] ?? '' ), true ),
							'user_email'      => sanitize_email( (string) ( $user['user_email'] ?? '' ) ),
							'display_name'    => sanitize_text_field( (string) ( $user['display_name'] ?? '' ) ),
							'user_registered' => sanitize_text_field( (string) ( $user['user_registered'] ?? '' ) ),
							'roles'           => array_values( array_unique( array_map( 'sanitize_key', (array) ( $user['roles'] ?? array() ) ) ) ),
						);
					}
					$meta[ $key ] = $users;
					break;
				case 'archive_type':
				case 'shared_users':
					$meta[ $key ] = sanitize_key( (string) $value );
					break;
				case 'domain':
					$meta[ $key ] = strtolower( sanitize_text_field( (string) $value ) );
					break;
				case 'path':
				case 'uploads_relative':
					$meta[ $key ] = trim( str_replace( '\\', '/', sanitize_text_field( (string) $value ) ), '/' );
					if ( 'path' === $key ) { $meta[ $key ] = '/' . $meta[ $key ] . ( '' === $meta[ $key ] ? '' : '/' ); }
					break;
				case 'home':
				case 'siteurl':
					$meta[ $key ] = esc_url_raw( (string) $value );
					break;
			}
		}

		return $meta;
	}
}
