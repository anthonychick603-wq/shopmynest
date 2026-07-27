<?php
/**
 * Feature 4 — Bundles + Make an Offer.
 *
 * @package MyNest_Trust_Suite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TNM_Trust_Offers {

	const TYPES    = array( 'single', 'bundle' );
	const STATUSES = array( 'pending', 'accepted', 'declined', 'countered', 'expired' );

	const SESSION_TOKEN_KEY  = 'tnm_trust_accepted_offer_token';
	const SESSION_BUNDLE_KEY = 'tnm_trust_bundle_builder';

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'tnm_trust_hourly_event', array( __CLASS__, 'expire_stale_offers' ) );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'apply_accepted_offer_pricing' ) );
		add_filter( 'woocommerce_package_rates', array( __CLASS__, 'apply_bundle_shipping_discount' ), 10, 2 );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'maybe_mark_token_used_on_order' ), 10, 4 );
	}

	/**
	 * Resolve the single seller ID shared by a list of product IDs.
	 * Returns 0/false via WP_Error if products don't share exactly one seller.
	 *
	 * @param int[] $product_ids Product IDs.
	 * @return int|WP_Error
	 */
	public static function resolve_shared_seller( $product_ids ) {
		$product_ids = array_filter( array_map( 'absint', (array) $product_ids ) );

		if ( empty( $product_ids ) ) {
			return new WP_Error( 'tnm_trust_no_products', __( 'At least one product is required.', 'nest-trust' ), array( 'status' => 400 ) );
		}

		$seller_ids = array();
		foreach ( $product_ids as $product_id ) {
			if ( ! get_post( $product_id ) || 'product' !== get_post_type( $product_id ) ) {
				return new WP_Error( 'tnm_trust_invalid_product', __( 'One or more products were not found.', 'nest-trust' ), array( 'status' => 404 ) );
			}
			$seller_ids[] = TNM_Trust_Compat::get_product_seller_id( $product_id );
		}

		$unique = array_unique( $seller_ids );

		if ( count( $unique ) !== 1 || 0 === (int) reset( $unique ) ) {
			return new WP_Error( 'tnm_trust_multiple_sellers', __( 'All products in a single offer/bundle must belong to the same seller.', 'nest-trust' ), array( 'status' => 400 ) );
		}

		return absint( reset( $unique ) );
	}

	/**
	 * Create a new offer.
	 *
	 * @param int   $buyer_id Buyer user ID.
	 * @param array $args     type, product_ids, offer_price.
	 * @return array|WP_Error
	 */
	public static function create_offer( $buyer_id, $args ) {
		global $wpdb;

		$product_ids = isset( $args['product_ids'] ) ? (array) $args['product_ids'] : array();
		$offer_price = isset( $args['offer_price'] ) ? (float) $args['offer_price'] : 0;
		$type        = isset( $args['type'] ) && in_array( $args['type'], self::TYPES, true ) ? $args['type'] : ( count( $product_ids ) > 1 ? 'bundle' : 'single' );

		if ( $offer_price <= 0 ) {
			return new WP_Error( 'tnm_trust_invalid_price', __( 'A valid offer_price greater than zero is required.', 'nest-trust' ), array( 'status' => 400 ) );
		}

		$seller_id = self::resolve_shared_seller( $product_ids );
		if ( is_wp_error( $seller_id ) ) {
			return $seller_id;
		}

		$now        = current_time( 'mysql', true );
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + ( 48 * HOUR_IN_SECONDS ) );

		$table = TNM_Trust_DB::table( 'offers' );

		$inserted = $wpdb->insert(
			$table,
			array(
				'buyer_id'    => absint( $buyer_id ),
				'seller_id'   => $seller_id,
				'type'        => $type,
				'product_ids' => wp_json_encode( array_values( array_map( 'absint', $product_ids ) ) ),
				'offer_price' => $offer_price,
				'status'      => 'pending',
				'expires_at'  => $expires_at,
				'created_at'  => $now,
				'updated_at'  => $now,
			),
			array( '%d', '%d', '%s', '%s', '%f', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return new WP_Error( 'tnm_trust_db_error', __( 'Could not create offer.', 'nest-trust' ), array( 'status' => 500 ) );
		}

		return self::get_offer( $wpdb->insert_id );
	}

	/**
	 * Fetch a single offer, formatted.
	 *
	 * @param int $id Offer ID.
	 * @return array|null
	 */
	public static function get_offer( $id ) {
		global $wpdb;

		$table = TNM_Trust_DB::table( 'offers' );

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $id ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! $row ) {
			return null;
		}

		return self::format_offer_row( $row );
	}

	/**
	 * Format a raw offer row for output. Strips the raw checkout token
	 * unless explicitly requested (it is sensitive/single-use).
	 *
	 * @param array $row       Raw row.
	 * @param bool  $with_token Whether to include the checkout_token value.
	 * @return array
	 */
	protected static function format_offer_row( $row, $with_token = false ) {
		$row['id']            = absint( $row['id'] );
		$row['buyer_id']      = absint( $row['buyer_id'] );
		$row['seller_id']     = absint( $row['seller_id'] );
		$row['offer_price']   = (float) $row['offer_price'];
		$row['counter_price'] = null !== $row['counter_price'] ? (float) $row['counter_price'] : null;
		$decoded_products      = json_decode( (string) $row['product_ids'], true );
		$row['product_ids']   = is_array( $decoded_products ) ? array_map( 'absint', $decoded_products ) : array();
		$row['token_used']    = ! empty( $row['token_used'] );

		if ( ! $with_token ) {
			unset( $row['checkout_token'] );
		}

		return $row;
	}

	/**
	 * List offers visible to a user (buyer sees their own, seller sees theirs).
	 *
	 * @param int   $user_id User ID.
	 * @param array $filters Optional: status.
	 * @return array
	 */
	public static function list_offers_for_user( $user_id, $filters = array() ) {
		global $wpdb;

		$table   = TNM_Trust_DB::table( 'offers' );
		$user_id = absint( $user_id );

		$where  = array( '(buyer_id = %d OR seller_id = %d)' );
		$values = array( $user_id, $user_id );

		if ( ! empty( $filters['status'] ) && in_array( $filters['status'], self::STATUSES, true ) ) {
			$where[]  = 'status = %s';
			$values[] = $filters['status'];
		}

		$sql = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY created_at DESC LIMIT 200';

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		if ( ! $rows ) {
			return array();
		}

		return array_map(
			function ( $row ) {
				return self::format_offer_row( $row );
			},
			$rows
		);
	}

	/**
	 * Update an offer: seller accepts/declines/counters, or buyer
	 * accepts/declines a counter.
	 *
	 * @param int   $id      Offer ID.
	 * @param array $args    action (accept|decline|counter), counter_price (if countering).
	 * @param int   $user_id Acting user ID.
	 * @return array|WP_Error
	 */
	public static function update_offer( $id, $args, $user_id ) {
		global $wpdb;

		$offer = self::get_offer( $id );
		if ( ! $offer ) {
			return new WP_Error( 'tnm_trust_not_found', __( 'Offer not found.', 'nest-trust' ), array( 'status' => 404 ) );
		}

		$user_id   = absint( $user_id );
		$is_seller = ( $offer['seller_id'] === $user_id );
		$is_buyer  = ( $offer['buyer_id'] === $user_id );

		if ( ! $is_seller && ! $is_buyer ) {
			return new WP_Error( 'tnm_trust_forbidden', __( 'You are not a party to this offer.', 'nest-trust' ), array( 'status' => 403 ) );
		}

		$action = isset( $args['action'] ) ? sanitize_key( $args['action'] ) : '';

		if ( 'expired' === $offer['status'] ) {
			return new WP_Error( 'tnm_trust_expired', __( 'This offer has expired.', 'nest-trust' ), array( 'status' => 409 ) );
		}

		$table   = TNM_Trust_DB::table( 'offers' );
		$update  = array( 'updated_at' => current_time( 'mysql', true ) );
		$formats = array( '%s' );

		if ( $is_seller && in_array( $offer['status'], array( 'pending' ), true ) ) {
			if ( 'accept' === $action ) {
				$update['status'] = 'accepted';
				$formats[]          = '%s';
				self::generate_checkout_token( $id );
			} elseif ( 'decline' === $action ) {
				$update['status'] = 'declined';
				$formats[]          = '%s';
			} elseif ( 'counter' === $action ) {
				if ( empty( $args['counter_price'] ) || ! is_numeric( $args['counter_price'] ) || (float) $args['counter_price'] <= 0 ) {
					return new WP_Error( 'tnm_trust_invalid_price', __( 'A valid counter_price is required.', 'nest-trust' ), array( 'status' => 400 ) );
				}
				$update['status']         = 'countered';
				$update['counter_price'] = (float) $args['counter_price'];
				$formats[]                  = '%s';
				$formats[]                  = '%f';
			} else {
				return new WP_Error( 'tnm_trust_invalid_action', __( 'Invalid action for seller.', 'nest-trust' ), array( 'status' => 400 ) );
			}
		} elseif ( $is_buyer && 'countered' === $offer['status'] ) {
			if ( 'accept' === $action ) {
				$update['status'] = 'accepted';
				$formats[]          = '%s';
				self::generate_checkout_token( $id );
			} elseif ( 'decline' === $action ) {
				$update['status'] = 'declined';
				$formats[]          = '%s';
			} else {
				return new WP_Error( 'tnm_trust_invalid_action', __( 'Invalid action for buyer.', 'nest-trust' ), array( 'status' => 400 ) );
			}
		} else {
			return new WP_Error( 'tnm_trust_invalid_state', __( 'This action is not valid for the current offer state.', 'nest-trust' ), array( 'status' => 409 ) );
		}

		$updated = $wpdb->update( $table, $update, array( 'id' => absint( $id ) ), $formats, array( '%d' ) );

		if ( false === $updated ) {
			return new WP_Error( 'tnm_trust_db_error', __( 'Could not update offer.', 'nest-trust' ), array( 'status' => 500 ) );
		}

		return self::get_offer( $id );
	}

	/**
	 * Generate a unique, single-use checkout token for an accepted offer.
	 *
	 * @param int $id Offer ID.
	 */
	protected static function generate_checkout_token( $id ) {
		global $wpdb;

		$table = TNM_Trust_DB::table( 'offers' );

		$token = wp_generate_password( 32, false, false );

		$wpdb->update(
			$table,
			array(
				'checkout_token'   => $token,
				'token_used'       => 0,
				'token_expires_at' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			),
			array( 'id' => absint( $id ) ),
			array( '%s', '%d', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Look up an offer by its checkout token, validating it is accepted,
	 * unused, and not expired.
	 *
	 * @param string $token Checkout token.
	 * @return array|null Formatted offer row (with product_ids/offer price), or null if invalid.
	 */
	public static function get_offer_by_valid_token( $token ) {
		global $wpdb;

		$token = sanitize_text_field( $token );
		if ( '' === $token ) {
			return null;
		}

		$table = TNM_Trust_DB::table( 'offers' );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE checkout_token = %s AND status = 'accepted' AND token_used = 0 AND token_expires_at >= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$token,
				current_time( 'mysql', true )
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		return self::format_offer_row( $row, true );
	}

	/**
	 * Buyer clicks "Buy accepted offer" — sets the WC session flag that
	 * `apply_accepted_offer_pricing()` will use, and adds the product(s)
	 * to the cart at the (unmodified) regular price; the price override
	 * happens at calculate_totals time.
	 *
	 * @param string $token Checkout token.
	 * @return true|WP_Error
	 */
	public static function start_offer_checkout( $token ) {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return new WP_Error( 'tnm_trust_no_session', __( 'WooCommerce session is unavailable.', 'nest-trust' ), array( 'status' => 500 ) );
		}

		$offer = self::get_offer_by_valid_token( $token );
		if ( ! $offer ) {
			return new WP_Error( 'tnm_trust_invalid_token', __( 'This offer checkout link is invalid, already used, or expired.', 'nest-trust' ), array( 'status' => 404 ) );
		}

		foreach ( $offer['product_ids'] as $product_id ) {
			WC()->cart->add_to_cart( $product_id );
		}

		WC()->session->set( self::SESSION_TOKEN_KEY, $offer['checkout_token'] );

		return true;
	}

	/**
	 * Alternative to start_offer_checkout() that does NOT rely on a
	 * WooCommerce cart/session (browser cookies) — creates a real WC_Order
	 * directly at the negotiated price and returns its checkout payment
	 * URL. This is the flow the mobile app (or any non-browser client)
	 * should use, since native payment-intent checkouts can't participate
	 * in a PHP session the way start_offer_checkout()/apply_accepted_offer_pricing()
	 * require. Opening the returned URL in an in-app WebView completes
	 * payment through WooCommerce's normal checkout, same as the
	 * boost-purchase flow in class-tnm-trust-boosts.php.
	 *
	 * @param string $token    Checkout token.
	 * @param int    $buyer_id Buyer user ID (must match the offer's buyer).
	 * @return array|WP_Error Native PaymentSheet payload (order_id, client_secret,
	 *                        payment_intent_id, publishable_key, customer_id,
	 *                        ephemeral_key_secret, amount, currency) on success.
	 */
	public static function create_direct_order_for_offer( $token, $buyer_id ) {
		if ( ! function_exists( 'wc_create_order' ) ) {
			return new WP_Error( 'tnm_trust_no_woocommerce', __( 'WooCommerce is unavailable.', 'nest-trust' ), array( 'status' => 500 ) );
		}

		$offer = self::get_offer_by_valid_token( $token );
		if ( ! $offer ) {
			return new WP_Error( 'tnm_trust_invalid_token', __( 'This offer checkout link is invalid, already used, or expired.', 'nest-trust' ), array( 'status' => 404 ) );
		}

		if ( absint( $offer['buyer_id'] ) !== absint( $buyer_id ) ) {
			return new WP_Error( 'tnm_trust_forbidden', __( 'This offer does not belong to you.', 'nest-trust' ), array( 'status' => 403 ) );
		}

		$product_ids = $offer['product_ids'];
		if ( empty( $product_ids ) ) {
			return new WP_Error( 'tnm_trust_invalid_offer', __( 'This offer has no products attached.', 'nest-trust' ), array( 'status' => 400 ) );
		}

		$price_per_unit = (float) $offer['offer_price'] / max( 1, count( $product_ids ) );

		try {
			$order = wc_create_order(
				array(
					'customer_id' => absint( $buyer_id ),
				)
			);

			foreach ( $product_ids as $product_id ) {
				$wc_product = wc_get_product( $product_id );
				if ( ! $wc_product ) {
					continue;
				}
				$item = new WC_Order_Item_Product();
				$item->set_product( $wc_product );
				$item->set_quantity( 1 );
				$item->set_subtotal( $price_per_unit );
				$item->set_total( $price_per_unit );
				$order->add_item( $item );
			}

			$order->update_meta_data( '_tnm_trust_offer_id', absint( $offer['id'] ) );
			$order->update_meta_data( '_tnm_trust_offer_checkout_token', $token );
			$order->calculate_totals();
			$order->save();
		} catch ( \Exception $e ) {
			return new WP_Error( 'tnm_trust_order_error', $e->getMessage(), array( 'status' => 500 ) );
		}

		$payment = tnm_trust_create_payment_intent_for_order( $order, absint( $buyer_id ) );
		if ( is_wp_error( $payment ) ) {
			return $payment;
		}

		return array_merge( array( 'order_id' => $order->get_id() ), $payment );
	}

	/**
	 * `woocommerce_order_status_changed` — once an order created via
	 * create_direct_order_for_offer() reaches 'completed' or 'processing',
	 * mark its offer's checkout token used so it can't be reused.
	 *
	 * @param int    $order_id   Order ID.
	 * @param string $old_status Old status.
	 * @param string $new_status New status.
	 */
	public static function maybe_mark_token_used_on_order( $order_id, $old_status, $new_status ) {
		if ( ! in_array( $new_status, array( 'completed', 'processing' ), true ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$token = $order->get_meta( '_tnm_trust_offer_checkout_token' );
		if ( $token ) {
			self::mark_token_used( $token );
		}
	}

	/**
	 * `woocommerce_before_calculate_totals` — override cart line item
	 * price(s) to match the accepted offer price when the accepted-offer
	 * token is present in the session. Single use — marks the token used
	 * once the order is actually placed (see mark_token_used_on_order()).
	 *
	 * @param \WC_Cart $cart WooCommerce cart.
	 */
	public static function apply_accepted_offer_pricing( $cart ) {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}

		$token = WC()->session->get( self::SESSION_TOKEN_KEY );
		if ( empty( $token ) ) {
			return;
		}

		$offer = self::get_offer_by_valid_token( $token );
		if ( ! $offer ) {
			WC()->session->set( self::SESSION_TOKEN_KEY, null );
			return;
		}

		$product_ids   = $offer['product_ids'];
		$count_in_offer = count( $product_ids );

		if ( 0 === $count_in_offer ) {
			return;
		}

		// Distribute the negotiated total price evenly across the offer's line items.
		$price_per_item_group = (float) $offer['offer_price'];
		$matched_items          = array();

		foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
			if ( in_array( $cart_item['product_id'], $product_ids, true ) ) {
				$matched_items[ $cart_item_key ] = $cart_item;
			}
		}

		if ( empty( $matched_items ) ) {
			return;
		}

		$total_qty = 0;
		foreach ( $matched_items as $cart_item ) {
			$total_qty += (int) $cart_item['quantity'];
		}

		if ( 0 === $total_qty ) {
			return;
		}

		$price_per_unit = $price_per_item_group / $total_qty;

		foreach ( $matched_items as $cart_item_key => $cart_item ) {
			$cart_item['data']->set_price( $price_per_unit );
		}
	}

	/**
	 * Mark an offer's checkout token as used once an order containing it
	 * is placed. Hooked from includes/class-tnm-trust-rest.php or checkout
	 * action — called explicitly rather than via a generic order hook so we
	 * only consume tokens for orders that actually originated from this flow.
	 *
	 * @param string $token Checkout token.
	 */
	public static function mark_token_used( $token ) {
		global $wpdb;

		$table = TNM_Trust_DB::table( 'offers' );

		$wpdb->update(
			$table,
			array( 'token_used' => 1 ),
			array( 'checkout_token' => sanitize_text_field( $token ) ),
			array( '%d' ),
			array( '%s' )
		);

		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( self::SESSION_TOKEN_KEY, null );
		}
	}

	/**
	 * `woocommerce_package_rates` — apply a configurable bundle shipping
	 * discount when 2+ items from the same seller are in the cart via an
	 * accepted bundle offer.
	 *
	 * @param array $rates   Shipping rates.
	 * @param array $package Shipping package.
	 * @return array
	 */
	public static function apply_bundle_shipping_discount( $rates, $package ) {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return $rates;
		}

		$token = WC()->session->get( self::SESSION_TOKEN_KEY );
		if ( empty( $token ) ) {
			return $rates;
		}

		$offer = self::get_offer_by_valid_token( $token );
		if ( ! $offer || 'bundle' !== $offer['type'] || count( $offer['product_ids'] ) < 2 ) {
			return $rates;
		}

		$first_item_discount_pct      = (float) get_option( 'tnm_trust_bundle_first_item_discount_pct', 0 );
		$additional_item_discount_pct = (float) get_option( 'tnm_trust_bundle_additional_item_discount_pct', 20 );

		$item_count = count( $offer['product_ids'] );

		// Simple blended discount: first item at (100 - first_item_discount_pct)%,
		// remaining items at (100 - additional_item_discount_pct)%.
		$full_price_items       = 1;
		$discounted_price_items = max( 0, $item_count - 1 );

		$blended_multiplier = (
			( $full_price_items * ( 1 - ( $first_item_discount_pct / 100 ) ) )
			+ ( $discounted_price_items * ( 1 - ( $additional_item_discount_pct / 100 ) ) )
		) / max( 1, $item_count );

		foreach ( $rates as $rate_id => $rate ) {
			$rates[ $rate_id ]->cost = round( (float) $rate->cost * $blended_multiplier, 2 );

			if ( ! empty( $rate->taxes ) && is_array( $rate->taxes ) ) {
				foreach ( $rate->taxes as $tax_id => $tax_amount ) {
					$rates[ $rate_id ]->taxes[ $tax_id ] = round( (float) $tax_amount * $blended_multiplier, 2 );
				}
			}
		}

		return $rates;
	}

	/**
	 * WP-Cron hourly: mark stale pending/countered offers as expired.
	 */
	public static function expire_stale_offers() {
		global $wpdb;

		$table = TNM_Trust_DB::table( 'offers' );

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'expired', updated_at = %s WHERE status IN ('pending','countered') AND expires_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				current_time( 'mysql', true ),
				current_time( 'mysql', true )
			)
		);
	}

	/**
	 * Add a product to the session-based bundle builder for a given seller.
	 *
	 * @param int $product_id Product ID.
	 * @return array|WP_Error Current bundle contents for that seller.
	 */
	public static function add_to_bundle_builder( $product_id ) {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return new WP_Error( 'tnm_trust_no_session', __( 'WooCommerce session is unavailable.', 'nest-trust' ), array( 'status' => 500 ) );
		}

		$product_id = absint( $product_id );
		$seller_id  = TNM_Trust_Compat::get_product_seller_id( $product_id );

		if ( ! $seller_id ) {
			return new WP_Error( 'tnm_trust_unknown_seller', __( 'Could not determine the seller for this product.', 'nest-trust' ), array( 'status' => 400 ) );
		}

		$bundles = WC()->session->get( self::SESSION_BUNDLE_KEY );
		if ( ! is_array( $bundles ) ) {
			$bundles = array();
		}

		if ( ! isset( $bundles[ $seller_id ] ) || ! is_array( $bundles[ $seller_id ] ) ) {
			$bundles[ $seller_id ] = array();
		}

		if ( ! in_array( $product_id, $bundles[ $seller_id ], true ) ) {
			$bundles[ $seller_id ][] = $product_id;
		}

		WC()->session->set( self::SESSION_BUNDLE_KEY, $bundles );

		return array(
			'seller_id'   => $seller_id,
			'product_ids' => $bundles[ $seller_id ],
		);
	}

	/**
	 * Get the current session bundle builder contents.
	 *
	 * @return array
	 */
	public static function get_bundle_builder() {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return array();
		}

		$bundles = WC()->session->get( self::SESSION_BUNDLE_KEY );

		return is_array( $bundles ) ? $bundles : array();
	}
}
