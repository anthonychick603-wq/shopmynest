<?php
/**
 * Defensive compatibility helpers for reading data that may (or may not)
 * be provided by the "MyNest Unified Marketplace" plugin.
 *
 * Every method here is written so that it NEVER throws a fatal error if
 * that plugin is missing, deactivated, or has a different schema than
 * assumed — always check-then-read, and degrade gracefully (log + skip).
 *
 * @package MyNest_Trust_Suite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TNM_Trust_Compat {

	/**
	 * Cache of table-existence checks for the current request.
	 *
	 * @var array
	 */
	protected static $table_exists_cache = array();

	/**
	 * Log a warning to the debug log (only if WP_DEBUG_LOG is enabled).
	 *
	 * @param string $message Message to log.
	 */
	public static function log( $message ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[MyNest Trust Suite] ' . $message );
		}
	}

	/**
	 * Whether the other plugin's `tnm_table()` helper is available.
	 *
	 * @return bool
	 */
	public static function has_tnm_table_helper() {
		return function_exists( 'tnm_table' );
	}

	/**
	 * Resolve the other plugin's table name for a given short name
	 * (e.g. 'ledger', 'reviews', 'follows', 'messages', 'payouts').
	 * Falls back to the documented naming convention if the helper
	 * function isn't available, but only returns a name if the table
	 * actually exists.
	 *
	 * @param string $short_name Short table name (no prefix).
	 * @return string|null Fully-prefixed table name, or null if not found/available.
	 */
	public static function get_other_plugin_table( $short_name ) {
		global $wpdb;

		$short_name = preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $short_name ) );
		if ( '' === $short_name ) {
			return null;
		}

		$table_name = null;

		if ( self::has_tnm_table_helper() ) {
			// The other plugin's own helper — trust it if present.
			$maybe_table = call_user_func( 'tnm_table', $short_name );
			if ( is_string( $maybe_table ) && '' !== $maybe_table ) {
				$table_name = $maybe_table;
			}
		}

		if ( null === $table_name ) {
			// Fall back to the documented convention: {$wpdb->prefix}tnm_{name}.
			$table_name = $wpdb->prefix . 'tnm_' . $short_name;
		}

		if ( ! self::table_exists( $table_name ) ) {
			self::log( "Expected table '{$table_name}' (short name '{$short_name}') not found. Skipping read." );
			return null;
		}

		return $table_name;
	}

	/**
	 * Check whether a given table exists in the current database.
	 *
	 * @param string $table_name Fully-qualified table name.
	 * @return bool
	 */
	public static function table_exists( $table_name ) {
		global $wpdb;

		$table_name = (string) $table_name;
		if ( '' === $table_name ) {
			return false;
		}

		if ( isset( self::$table_exists_cache[ $table_name ] ) ) {
			return self::$table_exists_cache[ $table_name ];
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- LIKE value is escaped via $wpdb->esc_like + prepare.
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_name ) ) );

		self::$table_exists_cache[ $table_name ] = ( $found === $table_name );

		return self::$table_exists_cache[ $table_name ];
	}

	/**
	 * Get the platform fee percent from the other plugin if available,
	 * else fall back to a sane default.
	 *
	 * @return float
	 */
	public static function get_fee_percent() {
		if ( function_exists( 'tnm_fee_percent' ) ) {
			$fee = call_user_func( 'tnm_fee_percent' );
			if ( is_numeric( $fee ) ) {
				return (float) $fee;
			}
		}

		$settings = get_option( 'tnm_settings', array() );
		if ( is_array( $settings ) && isset( $settings['fee_percent'] ) && is_numeric( $settings['fee_percent'] ) ) {
			return (float) $settings['fee_percent'];
		}

		return 8.0;
	}

	/**
	 * Defensively resolve the seller (user) ID for a given WC product,
	 * trying the common meta key conventions used by marketplace plugins,
	 * falling back to post_author.
	 *
	 * @param int $product_id Product ID.
	 * @return int 0 if unknown.
	 */
	public static function get_product_seller_id( $product_id ) {
		$product_id = absint( $product_id );
		if ( ! $product_id ) {
			return 0;
		}

		$meta_keys = array( '_seller_id', 'seller_id', '_tnm_seller_id' );
		foreach ( $meta_keys as $meta_key ) {
			$value = get_post_meta( $product_id, $meta_key, true );
			if ( ! empty( $value ) && is_numeric( $value ) ) {
				return absint( $value );
			}
		}

		$post = get_post( $product_id );
		if ( $post && ! empty( $post->post_author ) ) {
			return absint( $post->post_author );
		}

		return 0;
	}

	/**
	 * Defensively resolve the seller ID assigned to a WC order (or order item).
	 * Tries common meta conventions on the order itself, falling back to
	 * inspecting the first line item's product seller.
	 *
	 * @param \WC_Order|int $order Order object or ID.
	 * @return int 0 if unknown.
	 */
	public static function get_order_seller_id( $order ) {
		if ( is_numeric( $order ) ) {
			$order = wc_get_order( $order );
		}

		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return 0;
		}

		$meta_keys = array( '_seller_id', 'seller_id', '_tnm_seller_id' );
		foreach ( $meta_keys as $meta_key ) {
			$value = $order->get_meta( $meta_key, true );
			if ( ! empty( $value ) && is_numeric( $value ) ) {
				return absint( $value );
			}
		}

		foreach ( $order->get_items() as $item ) {
			if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) {
				continue;
			}
			$product_id = $item->get_product_id();
			$seller_id  = self::get_product_seller_id( $product_id );
			if ( $seller_id ) {
				return $seller_id;
			}
		}

		return 0;
	}

	/**
	 * Whether a user holds the seller role from the other plugin
	 * (`tnm_seller` or `mynest_seller`), or is an admin/shop_manager.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function is_seller( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return false;
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}

		$seller_roles = array( 'tnm_seller', 'mynest_seller', 'administrator', 'shop_manager' );

		foreach ( $seller_roles as $role ) {
			if ( in_array( $role, (array) $user->roles, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the current REST/PHP request user may act as a seller.
	 *
	 * @return bool
	 */
	public static function current_user_is_seller() {
		return self::is_seller( get_current_user_id() );
	}

	/**
	 * Whether the current user is an admin/shop manager (marketplace admin caps).
	 *
	 * @return bool
	 */
	public static function current_user_is_admin() {
		return current_user_can( 'manage_woocommerce' ) || current_user_can( 'administrator' );
	}
}
