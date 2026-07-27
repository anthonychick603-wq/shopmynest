<?php
/**
 * Feature 6 — Boosts + Pro Seller Tier.
 *
 * @package MyNest_Trust_Suite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TNM_Trust_Boosts {

	const TIERS = array( '3day', '7day' );

	const ORDER_ITEM_META_PRODUCT_ID = '_tnm_trust_boost_product_id';
	const ORDER_ITEM_META_TIER       = '_tnm_trust_boost_tier';

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'maybe_activate_boost' ), 10, 4 );
		add_action( 'tnm_trust_hourly_event', array( __CLASS__, 'expire_stale_boosts' ) );
		add_filter( 'tnm_trust_seller_fee_percent', array( __CLASS__, 'filter_pro_seller_fee_percent' ), 10, 2 );
	}

	/**
	 * Create (once) the virtual "Listing Boost" WooCommerce product used
	 * to sell boosts. Idempotent — safe to call on every activation.
	 */
	public static function ensure_boost_product_exists() {
		$existing_id = absint( get_option( 'tnm_trust_boost_product_id', 0 ) );

		if ( $existing_id && get_post( $existing_id ) && 'product' === get_post_type( $existing_id ) ) {
			return $existing_id;
		}

		if ( ! class_exists( 'WC_Product_Simple' ) ) {
			TNM_Trust_Compat::log( 'WC_Product_Simple not available — could not create Listing Boost product yet.' );
			return 0;
		}

		$product = new WC_Product_Simple();
		$product->set_name( __( 'Listing Boost', 'nest-trust' ) );
		$product->set_status( 'private' );
		$product->set_catalog_visibility( 'hidden' );
		$product->set_virtual( true );
		$product->set_price( (string) get_option( 'tnm_trust_boost_price_3day', 5.00 ) );
		$product->set_regular_price( (string) get_option( 'tnm_trust_boost_price_3day', 5.00 ) );
		$product->set_sold_individually( false );
		$product->save();

		$product_id = $product->get_id();

		update_option( 'tnm_trust_boost_product_id', $product_id );

		return $product_id;
	}

	/**
	 * Get the price for a given boost tier from settings.
	 *
	 * @param string $tier '3day' or '7day'.
	 * @return float
	 */
	public static function get_tier_price( $tier ) {
		if ( '7day' === $tier ) {
			return (float) get_option( 'tnm_trust_boost_price_7day', 9.00 );
		}
		return (float) get_option( 'tnm_trust_boost_price_3day', 5.00 );
	}

	/**
	 * Create a WooCommerce order for a boost purchase and return the native
	 * Stripe PaymentSheet payload for the mobile app.
	 *
	 * @param int    $seller_id  Seller user ID.
	 * @param int    $product_id Listing (product) to boost.
	 * @param string $tier       '3day' or '7day'.
	 * @return array|WP_Error Native PaymentSheet payload plus order_id and boost_id.
	 */
	public static function create_boost_order( $seller_id, $product_id, $tier ) {
		global $wpdb;

		$product_id = absint( $product_id );
		$tier        = in_array( $tier, self::TIERS, true ) ? $tier : '3day';

		if ( ! get_post( $product_id ) || 'product' !== get_post_type( $product_id ) ) {
			return new WP_Error( 'tnm_trust_invalid_product', __( 'Listing not found.', 'nest-trust' ), array( 'status' => 404 ) );
		}

		$listing_seller_id = TNM_Trust_Compat::get_product_seller_id( $product_id );
		if ( $listing_seller_id && absint( $listing_seller_id ) !== absint( $seller_id ) && ! TNM_Trust_Compat::current_user_is_admin() ) {
			return new WP_Error( 'tnm_trust_forbidden', __( 'You may only boost your own listings.', 'nest-trust' ), array( 'status' => 403 ) );
		}

		$boost_product_id = self::ensure_boost_product_exists();
		if ( ! $boost_product_id ) {
			return new WP_Error( 'tnm_trust_boost_product_missing', __( 'The Listing Boost product could not be created.', 'nest-trust' ), array( 'status' => 500 ) );
		}

		$boost_wc_product = wc_get_product( $boost_product_id );
		if ( ! $boost_wc_product ) {
			return new WP_Error( 'tnm_trust_boost_product_missing', __( 'The Listing Boost product could not be loaded.', 'nest-trust' ), array( 'status' => 500 ) );
		}

		$price = self::get_tier_price( $tier );

		try {
			$order = wc_create_order(
				array(
					'customer_id' => absint( $seller_id ),
				)
			);

			$item = new WC_Order_Item_Product();
			$item->set_product( $boost_wc_product );
			$item->set_name( sprintf( '%s (%s)', $boost_wc_product->get_name(), $tier ) );
			$item->set_quantity( 1 );
			$item->set_subtotal( $price );
			$item->set_total( $price );
			$item->add_meta_data( self::ORDER_ITEM_META_PRODUCT_ID, $product_id );
			$item->add_meta_data( self::ORDER_ITEM_META_TIER, $tier );
			$item->save();

			$order->add_item( $item );
			$order->calculate_totals();
			$order->set_status( 'pending' );
			$order->save();
		} catch ( Exception $e ) {
			TNM_Trust_Compat::log( 'Failed to create boost order: ' . $e->getMessage() );
			return new WP_Error( 'tnm_trust_order_error', __( 'Could not create the boost order.', 'nest-trust' ), array( 'status' => 500 ) );
		}

		$table = TNM_Trust_DB::table( 'boosts' );

		$now = current_time( 'mysql', true );

		// Boost row starts in a 'pending_payment' placeholder status (not yet
		// 'active') until the order is actually paid — see maybe_activate_boost(),
		// which flips it to 'active' with real starts_at/expires_at values.
		$wpdb->insert(
			$table,
			array(
				'product_id'  => $product_id,
				'seller_id'   => absint( $seller_id ),
				'tier'        => $tier,
				'price_paid'  => $price,
				'wc_order_id' => $order->get_id(),
				'status'      => 'pending_payment',
				'created_at'  => $now,
				'updated_at'  => $now,
			),
			array( '%d', '%d', '%s', '%f', '%d', '%s', '%s', '%s' )
		);

		$payment = tnm_trust_create_payment_intent_for_order( $order, absint( $seller_id ) );
		if ( is_wp_error( $payment ) ) {
			return $payment;
		}

		return array_merge(
			array(
				'order_id' => $order->get_id(),
				'boost_id' => $wpdb->insert_id,
			),
			$payment
		);
	}

	/**
	 * `woocommerce_order_status_changed` — if the order contains the boost
	 * product with boost metadata, activate the corresponding boost row
	 * once the order reaches 'completed' or 'processing'.
	 *
	 * @param int      $order_id   Order ID.
	 * @param string   $old_status Old status.
	 * @param string   $new_status New status.
	 * @param \WC_Order $order      Order object.
	 */
	public static function maybe_activate_boost( $order_id, $old_status, $new_status, $order ) {
		if ( ! in_array( $new_status, array( 'completed', 'processing' ), true ) ) {
			return;
		}

		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			$order = wc_get_order( $order_id );
		}

		if ( ! $order ) {
			return;
		}

		foreach ( $order->get_items() as $item ) {
			if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) {
				continue;
			}

			$boost_product_id = $item->get_meta( self::ORDER_ITEM_META_PRODUCT_ID, true );
			$tier               = $item->get_meta( self::ORDER_ITEM_META_TIER, true );

			if ( empty( $boost_product_id ) || empty( $tier ) ) {
				continue;
			}

			self::activate_boost_for_order( absint( $order_id ), absint( $boost_product_id ), sanitize_key( $tier ) );
		}
	}

	/**
	 * Activate (or re-activate) the boost row tied to a given order + listing.
	 *
	 * @param int    $order_id   WC order ID.
	 * @param int    $product_id Boosted listing product ID.
	 * @param string $tier       '3day' or '7day'.
	 */
	protected static function activate_boost_for_order( $order_id, $product_id, $tier ) {
		global $wpdb;

		$table = TNM_Trust_DB::table( 'boosts' );

		$existing_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE wc_order_id = %d AND product_id = %d ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$order_id,
				$product_id
			)
		);

		if ( ! $existing_id ) {
			return;
		}

		// Avoid double-activating (idempotent across repeated status-change hooks).
		$current_status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$table} WHERE id = %d", absint( $existing_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( 'active' === $current_status ) {
			return;
		}

		$days       = ( '7day' === $tier ) ? 7 : 3;
		$starts_at  = current_time( 'mysql', true );
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + ( $days * DAY_IN_SECONDS ) );

		$wpdb->update(
			$table,
			array(
				'status'     => 'active',
				'starts_at'  => $starts_at,
				'expires_at' => $expires_at,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => absint( $existing_id ) ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * WP-Cron hourly: mark expired boosts.
	 */
	public static function expire_stale_boosts() {
		global $wpdb;

		$table = TNM_Trust_DB::table( 'boosts' );

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'expired', updated_at = %s WHERE status = 'active' AND expires_at IS NOT NULL AND expires_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				current_time( 'mysql', true ),
				current_time( 'mysql', true )
			)
		);
	}

	/**
	 * Whether a seller is flagged as a Pro Seller.
	 *
	 * @param int $seller_id Seller user ID.
	 * @return bool
	 */
	public static function is_pro_seller( $seller_id ) {
		return (bool) get_user_meta( absint( $seller_id ), '_tnm_trust_pro_seller', true );
	}

	/**
	 * Admin toggle: set/unset the Pro Seller flag for a user.
	 *
	 * @param int  $seller_id Seller user ID.
	 * @param bool $is_pro    Whether they should be Pro.
	 * @return bool
	 */
	public static function set_pro_seller( $seller_id, $is_pro ) {
		$seller_id = absint( $seller_id );
		if ( ! $seller_id || ! get_userdata( $seller_id ) ) {
			return false;
		}

		if ( $is_pro ) {
			update_user_meta( $seller_id, '_tnm_trust_pro_seller', 1 );
		} else {
			delete_user_meta( $seller_id, '_tnm_trust_pro_seller' );
		}

		return true;
	}

	/**
	 * Filter callback for `tnm_trust_seller_fee_percent` — this plugin
	 * PROVIDES this filter for the other plugin to optionally consume
	 * (see README for the one-line integration snippet). Reduces the fee
	 * for Pro sellers by a configurable number of percentage points.
	 *
	 * @param float $default_fee_percent Default platform fee percent.
	 * @param int   $seller_id           Seller user ID.
	 * @return float
	 */
	public static function filter_pro_seller_fee_percent( $default_fee_percent, $seller_id ) {
		if ( ! self::is_pro_seller( $seller_id ) ) {
			return $default_fee_percent;
		}

		$discount = (float) get_option( 'tnm_trust_pro_seller_fee_discount_points', 3.5 );

		return max( 0, (float) $default_fee_percent - $discount );
	}
}
