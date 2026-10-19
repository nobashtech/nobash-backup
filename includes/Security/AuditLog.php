<?php
/**
 * Enterprise audit trail.
 *
 * @package Nobash\Backup
 */

namespace Nobash\Backup\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Class AuditLog
 */
class AuditLog {

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'nobash_audit_log';
	}

	/**
	 * Create table.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;
		$table           = self::table();
		$charset_collate = $wpdb->get_charset_collate();
		$sql             = $wpdb->prepare( "CREATE TABLE %i (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			action varchar(64) NOT NULL DEFAULT '',
			object_type varchar(64) NOT NULL DEFAULT '',
			object_id varchar(191) NOT NULL DEFAULT '',
			meta longtext NULL,
			ip varchar(45) NOT NULL DEFAULT '',
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY action_time (action, created_at),
			KEY user_id (user_id)
		) {$charset_collate};", $table );

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Record an event.
	 *
	 * @param string               $action Action slug.
	 * @param string               $object_type Object type.
	 * @param string               $object_id   Object id.
	 * @param array<string, mixed> $meta        Meta.
	 * @return void
	 */
	public static function record( $action, $object_type = '', $object_id = '', array $meta = array() ) {
		global $wpdb;

		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$table,
			array(
				'user_id'     => get_current_user_id(),
				'action'      => substr( sanitize_key( $action ), 0, 64 ),
				'object_type' => substr( sanitize_key( $object_type ), 0, 64 ),
				'object_id'   => substr( sanitize_text_field( $object_id ), 0, 191 ),
				'meta'        => wp_json_encode( SecretRedactor::redact( $meta ) ),
				'ip'          => self::client_ip(),
				'created_at'  => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Recent events.
	 *
	 * @param int $limit Limit.
	 * @return array<int, array<string, mixed>>
	 */
	public static function recent( $limit = 25 ) {
		global $wpdb;
		$table = self::table();
		$limit = max( 1, min( 100, (int) $limit ) );
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d', $table, $limit ),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Permanently delete all audit trail rows.
	 *
	 * @return int Number of deleted rows.
	 */
	public static function clear() {
		global $wpdb;
		$table = self::table();
		$result = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return false === $result ? 0 : (int) $result;
	}

	/**
	 * @return string
	 */
	private static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
		return substr( $ip, 0, 45 );
	}
}
