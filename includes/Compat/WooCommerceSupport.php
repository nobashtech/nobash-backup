<?php
/**
 * WooCommerce compatibility helpers.
 *
 * @package Nobash\Backup
 */

namespace Nobash\Backup\Compat;

defined( 'ABSPATH' ) || exit;

/**
 * Class WooCommerceSupport
 */
class WooCommerceSupport {

	/**
	 * Whether WooCommerce appears active.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return class_exists( 'WooCommerce', false )
			|| class_exists( '\\WooCommerce', false )
			|| in_array( 'woocommerce/woocommerce.php', (array) apply_filters( 'active_plugins', get_option( 'active_plugins', array() ) ), true ) // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core's existing active_plugins compatibility filter; changing its name would bypass activation filters.
			|| ( is_multisite() && function_exists( 'is_plugin_active_for_network' ) && is_plugin_active_for_network( 'woocommerce/woocommerce.php' ) );
	}

	/**
	 * Status payload for UI / manifest.
	 *
	 * @return array<string, mixed>
	 */
	public static function status() {
		$active = self::is_active();
		$out    = array(
			'active'  => $active,
			'version' => $active && defined( 'WC_VERSION' ) ? WC_VERSION : '',
			'hpos'    => false,
		);

		if ( $active && class_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil' ) ) {
			try {
				$out['hpos'] = (bool) \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
			} catch ( \Throwable $e ) {
				$out['hpos'] = false;
			}
		}

		return $out;
	}

	/**
	 * Table name suffixes (without prefix) that are WooCommerce-related.
	 * Used for reporting — tables are still included via normal prefix dump.
	 *
	 * @return array<int, string>
	 */
	public static function related_table_suffixes() {
		return array(
			'woocommerce_sessions',
			'woocommerce_api_keys',
			'woocommerce_attribute_taxonomies',
			'woocommerce_downloadable_product_permissions',
			'woocommerce_order_items',
			'woocommerce_order_itemmeta',
			'woocommerce_tax_rates',
			'woocommerce_tax_rate_locations',
			'woocommerce_shipping_zones',
			'woocommerce_shipping_zone_locations',
			'woocommerce_shipping_zone_methods',
			'woocommerce_payment_tokens',
			'woocommerce_payment_tokenmeta',
			'woocommerce_log',
			'wc_orders',
			'wc_orders_meta',
			'wc_order_addresses',
			'wc_order_operational_data',
			'wc_product_meta_lookup',
			'wc_order_product_lookup',
			'wc_order_stats',
			'wc_order_coupon_lookup',
			'wc_order_tax_lookup',
			'wc_customer_lookup',
			'wc_category_lookup',
			'wc_tax_rate_classes',
			'wc_webhooks',
			'wc_download_log',
			'actionscheduler_actions',
			'actionscheduler_claims',
			'actionscheduler_groups',
			'actionscheduler_logs',
		);
	}

	/** Resolve existing WooCommerce-owned tables, including extension tables declared through a filter.
	 * @return array<int,string>
	 */
	public static function resolved_related_tables() {
		global $wpdb;
		$tables = array();
		foreach ( self::related_table_suffixes() as $suffix ) {
			$name = $wpdb->prefix . $suffix;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( $name === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) ) ) { $tables[] = $name; }
		}
		/** Filter additional WooCommerce extension tables. */
		return array_values( array_unique( (array) apply_filters( 'nobash_backup_woocommerce_tables', $tables ) ) );
	}

	/** Build a source consistency marker for post-migration verification.
	 * @return array<string,mixed>
	 */
	public static function consistency_marker() {
		global $wpdb;
		$status = self::status();
		$out = array( 'active'=>(bool)$status['active'], 'hpos'=>(bool)$status['hpos'], 'captured_at'=>gmdate('c'), 'latest_order_id'=>0, 'order_count'=>0 );
		if ( empty( $status['active'] ) ) { return $out; }
		if ( ! empty( $status['hpos'] ) ) {
			$table = $wpdb->prefix . 'wc_orders';
			$out['latest_order_id'] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(id),0) FROM %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$out['order_count'] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		} else {
			$out['latest_order_id'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(ID),0) FROM %i WHERE post_type IN ('shop_order','shop_order_refund')", $wpdb->posts ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$out['order_count'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE post_type IN ('shop_order','shop_order_refund')", $wpdb->posts ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		}
		return $out;
	}

	/**
	 * After restore: flush WC caches / rewrite state when WooCommerce is present.
	 *
	 * @return void
	 */
	public static function after_restore() {
		if ( ! self::is_active() ) {
			return;
		}

		if ( function_exists( 'wc_clear_template_cache' ) ) {
			wc_clear_template_cache();
		}
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients();
		}

		delete_transient( 'wc_attribute_taxonomies' );
		delete_transient( 'woocommerce_cache_excluded_uris' );

		if ( class_exists( '\\WC_Cache_Helper' ) && method_exists( '\\WC_Cache_Helper', 'invalidate_cache_group' ) ) {
			\WC_Cache_Helper::invalidate_cache_group( 'woocommerce' );
		}

		/**
		 * Fires after Nobash Backup restore when WooCommerce is active.
		 */
		do_action( 'nobash_backup_woocommerce_after_restore' );
	}
}
