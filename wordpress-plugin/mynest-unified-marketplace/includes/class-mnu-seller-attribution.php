<?php
/**
 * Seller attribution diagnostics & backfill.
 *
 * Item 2 of the Top 25 polish list: make sure every product carries an
 * unambiguous _tnm_seller_id BEFORE it can appear on an order, and give ops
 * an admin surface to spot orders where seller resolution collapsed on the
 * checkout path.
 *
 * @package MyNest_Unified_Marketplace
 * @since 3.7.56
 */

defined( 'ABSPATH' ) || exit;

final class MNU_Seller_Attribution {

	private const MENU_PARENT = 'tnm-marketplace';
	private const MENU_SLUG   = 'mnu-seller-attribution';
	private const CAP         = 'manage_woocommerce';
	private const NONCE_KEY   = 'mnu_seller_attribution_action';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 20 );
		add_action( 'admin_post_mnu_seller_attribution_backfill', array( __CLASS__, 'handle_backfill' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest' ) );
	}

	/**
	 * Admin-only REST endpoint that returns everything we know about an order
	 * from the seller-attribution point of view: line stamping, seller-transfer
	 * order meta, ledger rows, and any collapsed-resolution flags. Used from
	 * the two-seller verification flow.
	 */
	public static function register_rest(): void {
		register_rest_route(
			'mnu/v1',
			'/admin/ledger',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_ledger' ),
				'permission_callback' => static function () {
					return current_user_can( self::CAP );
				},
				'args'                => array(
					'order_id' => array( 'required' => true, 'type' => 'integer' ),
				),
			)
		);

		register_rest_route(
			'mnu/v1',
			'/admin/ledger_rebuild',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_ledger_rebuild' ),
				'permission_callback' => static function () {
					return current_user_can( self::CAP );
				},
				'args'                => array(
					'order_id' => array( 'required' => true, 'type' => 'integer' ),
				),
			)
		);

		register_rest_route(
			'mnu/v1',
			'/admin/split_holds',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_split_holds' ),
				'permission_callback' => static function () {
					return current_user_can( self::CAP );
				},
			)
		);

		register_rest_route(
			'mnu/v1',
			'/admin/split_holds/resolve',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_split_hold_resolve' ),
				'permission_callback' => static function () {
					return current_user_can( self::CAP );
				},
				'args'                => array(
					'order_id' => array( 'required' => true, 'type' => 'integer' ),
					'retry'    => array( 'required' => false, 'type' => 'boolean' ),
				),
			)
		);

		register_rest_route(
			'mnu/v1',
			'/admin/shippo_state',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_shippo_state' ),
				'permission_callback' => static function () {
					return current_user_can( self::CAP );
				},
			)
		);

		// v3.7.77 — flip every published product with no featured image and
		// no gallery to 'draft' so sellers must upload a photo before it can
		// be seen again. Idempotent; safe to re-run.
		register_rest_route(
			'mnu/v1',
			'/admin/draft_photoless_products',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_draft_photoless_products' ),
				'permission_callback' => static function () {
					return current_user_can( self::CAP );
				},
				'args'                => array(
					'dry_run' => array( 'required' => false, 'type' => 'boolean', 'default' => false ),
				),
			)
		);

		// v3.7.76 — marketplace-wide ledger reset. Wipes every row of
		// tnm_ledger and tnm_payouts and (optionally) cancels the linked
		// WooCommerce orders. Destructive; requires manage_options + an
		// explicit confirm=RESET_ALL_LEDGERS body param so no accidental
		// call from another script can trigger it.
		register_rest_route(
			'mnu/v1',
			'/admin/reset_all_ledgers',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_reset_all_ledgers' ),
				'permission_callback' => static function () {
					return current_user_can( self::CAP );
				},
				'args'                => array(
					'confirm'       => array( 'required' => true,  'type' => 'string' ),
					'cancel_orders' => array( 'required' => false, 'type' => 'boolean', 'default' => true ),
				),
			)
		);

			// v3.7.78 — admin shipping diagnostic. Runs the live per-seller
			// Shippo rate lookup against a test destination and returns a
			// per-seller breakdown so we can prove multi-seller carts quote
			// distinct rates instead of failing with 'no shipping options'.
			register_rest_route(
				'mnu/v1',
				'/admin/shipping_diagnose',
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'rest_shipping_diagnose' ),
					'permission_callback' => static function () {
						return current_user_can( self::CAP );
					},
					'args'                => array(
						'order_id'    => array( 'required' => false, 'type' => 'integer' ),
						'product_ids' => array( 'required' => false, 'type' => 'array' ),
						'to'          => array( 'required' => false, 'type' => 'object' ),
					),
				)
			);
	}

	/**
	 * v3.7.77 — flip every published (or pending-review) product with no
	 * featured image and no gallery to 'draft'. Sets _mnu_needs_photo=1 so
	 * the seller-facing follow-up notice can highlight them.
	 */
	public static function rest_draft_photoless_products( WP_REST_Request $req ): WP_REST_Response {
		global $wpdb;
		$dry_run = (bool) $req->get_param( 'dry_run' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT p.ID, p.post_title, p.post_status, p.post_author
			   FROM {$wpdb->posts} p
			  WHERE p.post_type = 'product'
				AND p.post_status IN ('publish','pending')
				AND NOT EXISTS (
					SELECT 1 FROM {$wpdb->postmeta} tm
					 WHERE tm.post_id = p.ID AND tm.meta_key = '_thumbnail_id' AND tm.meta_value <> '' AND tm.meta_value <> '0'
				)
				AND NOT EXISTS (
					SELECT 1 FROM {$wpdb->postmeta} gm
					 WHERE gm.post_id = p.ID AND gm.meta_key = '_product_image_gallery' AND gm.meta_value <> '' AND gm.meta_value <> '0'
				)"
		);
		// phpcs:enable

		$affected = array();
		$failed   = array();
		foreach ( (array) $rows as $r ) {
			$affected[] = array(
				'id'         => (int) $r->ID,
				'title'      => (string) $r->post_title,
				'was_status' => (string) $r->post_status,
				'seller_id'  => (int) $r->post_author,
			);
			if ( ! $dry_run ) {
				$ok = wp_update_post( array( 'ID' => (int) $r->ID, 'post_status' => 'draft' ), true );
				if ( is_wp_error( $ok ) ) {
					$failed[] = array( 'id' => (int) $r->ID, 'reason' => $ok->get_error_message() );
					continue;
				}
				update_post_meta( (int) $r->ID, '_mnu_needs_photo', 1 );
				update_post_meta( (int) $r->ID, '_mnu_needs_photo_at', current_time( 'mysql' ) );
			}
		}

		return new WP_REST_Response(
			array(
				'ok'             => true,
				'dry_run'        => $dry_run,
				'affected_count' => count( $affected ),
				'failed_count'   => count( $failed ),
				'affected'       => $affected,
				'failed'         => $failed,
			)
		);
	}

	/**
	 * v3.7.76 — wipe every ledger row and every payout row, and (optionally)
	 * cancel every WooCommerce order that had ledger rows attached. Returns
	 * counts and the list of cancelled order ids so the reset is auditable
	 * from the response alone.
	 */
	public static function rest_reset_all_ledgers( WP_REST_Request $req ): WP_REST_Response {
		global $wpdb;
		if ( 'RESET_ALL_LEDGERS' !== (string) $req->get_param( 'confirm' ) ) {
			return new WP_REST_Response( array( 'error' => 'missing_confirmation' ), 400 );
		}
		$cancel_orders = (bool) $req->get_param( 'cancel_orders' );

		$ledger_table  = function_exists( 'tnm_table' ) ? tnm_table( 'ledger' )  : $wpdb->prefix . 'tnm_ledger';
		$payouts_table = function_exists( 'tnm_table' ) ? tnm_table( 'payouts' ) : $wpdb->prefix . 'tnm_payouts';

		$order_ids = array();
		if ( $cancel_orders ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_col( "SELECT DISTINCT order_id FROM {$ledger_table} WHERE order_id > 0" );
			// phpcs:enable
			$order_ids = array_values( array_unique( array_map( 'intval', (array) $rows ) ) );
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange
		$ledger_before  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$ledger_table}" );
		$payouts_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$payouts_table}" );
		$wpdb->query( "TRUNCATE TABLE {$ledger_table}" );
		$wpdb->query( "TRUNCATE TABLE {$payouts_table}" );
		// phpcs:enable

		$cancelled = array();
		$failed    = array();
		if ( $cancel_orders && $order_ids && function_exists( 'wc_get_order' ) ) {
			foreach ( $order_ids as $oid ) {
				$order = wc_get_order( $oid );
				if ( ! $order ) {
					$failed[] = array( 'order_id' => $oid, 'reason' => 'order_not_found' );
					continue;
				}
				if ( in_array( $order->get_status(), array( 'cancelled', 'refunded', 'failed' ), true ) ) {
					$cancelled[] = $oid;
					continue;
				}
				try {
					$order->update_status( 'cancelled', 'Ledger reset (v3.7.76)' );
					$cancelled[] = $oid;
				} catch ( \Throwable $e ) {
					$failed[] = array( 'order_id' => $oid, 'reason' => $e->getMessage() );
				}
			}
		}

		return new WP_REST_Response(
			array(
				'ok'               => true,
				'ledger_deleted'   => $ledger_before,
				'payouts_deleted'  => $payouts_before,
				'orders_seen'      => count( $order_ids ),
				'orders_cancelled' => count( $cancelled ),
				'cancelled_ids'    => $cancelled,
				'failed'           => $failed,
			)
		);
	}

	/**
	 * Diagnostic: report what mnu_labels_settings() currently sees for the
	 * Shippo token, without echoing the token itself.
	 */
	public static function rest_shippo_state( WP_REST_Request $request ): WP_REST_Response {
		$raw_labels_option = (array) get_option( 'thenest_shipping_labels_settings', array() );
		$raw_legacy_token  = (string) get_option( 'thenest_shippo_api_token', '' );

		$settings = function_exists( 'mnu_labels_settings' ) ? mnu_labels_settings() : array();
		$token    = (string) ( $settings['shippo_token'] ?? '' );

		return rest_ensure_response(
			array(
				'has_settings_option'   => ! empty( $raw_labels_option ),
				'settings_option_keys'  => array_keys( $raw_labels_option ),
				'settings_has_token'    => ! empty( $raw_labels_option['shippo_token'] ),
				'legacy_token_present'  => '' !== $raw_legacy_token,
				'legacy_token_length'   => strlen( $raw_legacy_token ),
				'legacy_token_prefix'   => '' !== $raw_legacy_token ? substr( $raw_legacy_token, 0, 12 ) : '',
				'resolved_token_length' => strlen( $token ),
				'resolved_token_prefix' => '' !== $token ? substr( $token, 0, 12 ) : '',
				'resolved_test_mode'    => (int) ( $settings['test_mode'] ?? 0 ),
				'labels_settings_fn'    => function_exists( 'mnu_labels_settings' ),
			)
		);
	}

	/**
	 * List multi-seller orders currently held by the split-payment guardrail.
	 * Each row includes the compact guardrail snapshot so admin can eyeball
	 * why the hold triggered without opening every order.
	 */
	public static function rest_split_holds( WP_REST_Request $req ): WP_REST_Response {
		global $wpdb;
		$sql = "SELECT p.ID as order_id,
					   p.post_date as created,
					   MAX(CASE WHEN pm.meta_key='_mnu_split_guardrail' THEN pm.meta_value END) as snapshot,
					   MAX(CASE WHEN pm.meta_key='_tnm_seller_ids' THEN pm.meta_value END) as seller_ids,
					   MAX(CASE WHEN pm.meta_key='_order_total' THEN pm.meta_value END) as total
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
				WHERE p.post_type = 'shop_order'
				  AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} h WHERE h.post_id = p.ID AND h.meta_key = '_mnu_split_hold' AND h.meta_value = '1')
				GROUP BY p.ID
				ORDER BY p.post_date DESC";
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		// Also check HPOS orders table if it exists.
		$hpos_rows = array();
		$hpos_table = $wpdb->prefix . 'wc_orders';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $hpos_table ) ) === $hpos_table ) {
			$hpos_sql = "SELECT o.id as order_id,
								o.date_created_gmt as created,
								MAX(CASE WHEN m.meta_key='_mnu_split_guardrail' THEN m.meta_value END) as snapshot,
								MAX(CASE WHEN m.meta_key='_tnm_seller_ids' THEN m.meta_value END) as seller_ids,
								o.total_amount as total
						 FROM {$hpos_table} o
						 INNER JOIN {$wpdb->prefix}wc_orders_meta m ON m.order_id = o.id
						 WHERE EXISTS (SELECT 1 FROM {$wpdb->prefix}wc_orders_meta h WHERE h.order_id = o.id AND h.meta_key = '_mnu_split_hold' AND h.meta_value = '1')
						 GROUP BY o.id
						 ORDER BY o.date_created_gmt DESC";
			$hpos_rows = $wpdb->get_results( $hpos_sql, ARRAY_A );
		}
		$merged  = array();
		$seen_id = array();
		foreach ( array_merge( $rows ?: array(), $hpos_rows ?: array() ) as $r ) {
			$oid = (int) $r['order_id'];
			if ( isset( $seen_id[ $oid ] ) ) {
				continue;
			}
			$seen_id[ $oid ] = true;
			$snap            = $r['snapshot'] ? json_decode( (string) $r['snapshot'], true ) : null;
			$merged[] = array(
				'order_id'   => $oid,
				'created'    => $r['created'],
				'total'      => (float) $r['total'],
				'seller_ids' => trim( (string) $r['seller_ids'], ',' ),
				'snapshot'   => $snap,
			);
		}
		return rest_ensure_response( array( 'count' => count( $merged ), 'orders' => $merged ) );
	}

	/**
	 * Resolve (or retry) a split-payment hold. When retry=1 and the order has a
	 * Stripe payment intent + latest charge, re-run mnu_native_issue_seller_transfers()
	 * for any seller in {held, failed, missing}. Then re-evaluate the guardrail.
	 */
	public static function rest_split_hold_resolve( WP_REST_Request $req ): WP_REST_Response {
		try {
			return self::do_resolve( $req );
		} catch ( \Throwable $e ) {
			return rest_ensure_response( array(
				'ok'    => false,
				'error' => 'exception',
				'type'  => get_class( $e ),
				'msg'   => $e->getMessage(),
				'file'  => basename( $e->getFile() ),
				'line'  => $e->getLine(),
			) );
		}
	}

	private static function do_resolve( WP_REST_Request $req ): WP_REST_Response {
		$order_id = (int) $req->get_param( 'order_id' );
		$retry    = (bool) $req->get_param( 'retry' );
		$order    = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( ! $order ) {
			return rest_ensure_response( array( 'ok' => false, 'error' => 'order_not_found' ) );
		}
		$out = array( 'ok' => true, 'order_id' => $order_id, 'retried' => false );

		if ( $retry && function_exists( 'mnu_native_issue_seller_transfers' ) ) {
			$pi_id = (string) $order->get_meta( '_thenest_stripe_payment_intent', true );
			if ( '' !== $pi_id && function_exists( 'mnu_native_stripe_get' ) ) {
				$intent = mnu_native_stripe_get( '/payment_intents/' . rawurlencode( $pi_id ) );
				if ( ! is_wp_error( $intent ) ) {
					$charge_id = (string) ( $intent['latest_charge'] ?? '' );
					if ( '' === $charge_id && ! empty( $intent['charges']['data'][0]['id'] ) ) {
						$charge_id = (string) $intent['charges']['data'][0]['id'];
					}
					if ( '' !== $charge_id ) {
						// Clear the existing transfers meta so the issue function
						// retries the un-sent rows. Preserve entries with a real
						// transfer_id so we don't double-send.
						$raw   = (string) $order->get_meta( '_mnu_seller_transfers', true );
						$curr  = '' !== $raw ? (array) json_decode( $raw, true ) : array();
						$keep  = array();
						foreach ( $curr as $sid => $entry ) {
							if ( is_array( $entry ) && ! empty( $entry['transfer_id'] ) ) {
								$keep[ $sid ] = $entry;
							}
						}
						$order->update_meta_data( '_mnu_seller_transfers', wp_json_encode( $keep ) );
						$order->save();
						mnu_native_issue_seller_transfers( $order, $charge_id );
						$out['retried'] = true;
					} else {
						$out['retry_error'] = 'no_charge_on_intent';
					}
				} else {
					$out['retry_error'] = $intent->get_error_message();
				}
			} else {
				$out['retry_error'] = 'no_intent_meta';
			}
		}

		if ( function_exists( 'mnu_native_apply_split_guardrail' ) ) {
			$eval = mnu_native_apply_split_guardrail( $order );
			$out['guardrail'] = $eval;
		}
		return rest_ensure_response( $out );
	}

	public static function rest_ledger_rebuild( WP_REST_Request $req ): WP_REST_Response {
		try {
			return self::do_ledger_rebuild( $req );
		} catch ( \Throwable $e ) {
			return rest_ensure_response( array(
				'ok'    => false,
				'error' => 'exception',
				'type'  => get_class( $e ),
				'msg'   => $e->getMessage(),
				'file'  => basename( $e->getFile() ),
				'line'  => $e->getLine(),
				'trace' => array_slice( explode( "\n", $e->getTraceAsString() ), 0, 10 ),
			) );
		}
	}

	private static function do_ledger_rebuild( WP_REST_Request $req ): WP_REST_Response {
		global $wpdb;
		$order_id = (int) $req->get_param( 'order_id' );
		$order    = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( ! $order ) {
			return rest_ensure_response( array( 'ok' => false, 'error' => 'order_not_found', 'order_id' => $order_id ) );
		}

		$out = array( 'ok' => true, 'order_id' => $order_id, 'steps' => array() );

		$ship_addr = $order->get_address( 'shipping' );
		if ( empty( $ship_addr['address_1'] ) ) {
			$ship_addr = $order->get_address( 'billing' );
		}
		$lines = array();
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}
			$lines[] = array(
				'product'    => $product,
				'product_id' => $product->get_id(),
				'quantity'   => (int) $item->get_quantity(),
				'line_total' => (float) $item->get_total(),
				'name'       => $product->get_name(),
			);
		}

		if ( function_exists( 'mnu_native_shipping_breakdown_by_seller' ) ) {
			$breakdown = mnu_native_shipping_breakdown_by_seller( $lines, $ship_addr );
			if ( is_wp_error( $breakdown ) ) {
				$out['steps'][] = array( 'shipping_breakdown' => 'error', 'error' => $breakdown->get_error_message() );
			} elseif ( ! empty( $breakdown ) ) {
				$shipping_total = (float) $order->get_shipping_total();
				$sum            = array_sum( $breakdown );
				if ( $sum > 0 && $shipping_total > 0 && abs( $sum - $shipping_total ) > 0.001 ) {
					$scale = $shipping_total / $sum;
					foreach ( $breakdown as $sid => $amt ) {
						$breakdown[ $sid ] = round( (float) $amt * $scale, wc_get_price_decimals() + 2 );
					}
				}
				$order->update_meta_data( '_mnu_shipping_by_seller', wp_json_encode( $breakdown ) );
				$order->save();
				$out['steps'][] = array( 'shipping_breakdown' => $breakdown );
			}
		} else {
			$out['steps'][] = array( 'shipping_breakdown' => 'helper_missing' );
		}

		$table   = function_exists( 'tnm_table' ) ? tnm_table( 'ledger' ) : $wpdb->prefix . 'tnm_ledger';
		$deleted = $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $table . ' WHERE order_id = %d', $order_id ) );
		$out['steps'][] = array( 'rows_deleted' => (int) $deleted );

		if ( class_exists( 'TNM_Ledger' ) ) {
			\TNM_Ledger::create_order_rows( $order );
			$out['steps'][] = array( 'earnings_rebuilt' => true );

			$refunds = $order->get_refunds();
			foreach ( $refunds as $refund ) {
				\TNM_Ledger::record_refund( $order_id, (int) $refund->get_id() );
			}
			$out['steps'][] = array( 'refunds_replayed' => count( $refunds ) );
		}

		$out['ledger_rows'] = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $table . ' WHERE order_id = %d ORDER BY id ASC', $order_id ), ARRAY_A );
		return rest_ensure_response( $out );
	}

	public static function rest_ledger( WP_REST_Request $req ): WP_REST_Response {
		global $wpdb;
		$order_id = (int) $req->get_param( 'order_id' );
		$out      = array( 'order_id' => $order_id );

		$table = function_exists( 'tnm_table' ) ? tnm_table( 'ledger' ) : $wpdb->prefix . 'tnm_ledger';
		$rows  = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . $table . ' WHERE order_id = %d ORDER BY id ASC', $order_id ),
			ARRAY_A
		);
		$out['ledger_rows'] = $rows ?: array();

		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( $order ) {
			$transfers_raw       = $order->get_meta( '_mnu_seller_transfers', true );
			$out['transfers']    = is_string( $transfers_raw ) ? json_decode( $transfers_raw, true ) : $transfers_raw;
			$out['seller_ids']   = $order->get_meta( '_tnm_seller_ids', true );
			$out['collapsed']    = (bool) $order->get_meta( '_tnm_seller_resolution_collapsed', true );
			$out['stripe_pi']    = $order->get_meta( '_thenest_stripe_payment_intent', true );
			$out['status']       = $order->get_status();
			$out['total']        = $order->get_total();
			$out['line_items']   = array();
			foreach ( $order->get_items() as $item_id => $item ) {
				$out['line_items'][] = array(
					'item_id'       => $item_id,
					'product_id'    => $item->get_product_id(),
					'seller_id'     => (int) $item->get_meta( '_tnm_seller_id', true ),
					'store_name'    => $item->get_meta( '_tnm_store_name', true ),
					'subtotal'      => $item->get_subtotal(),
					'total'         => $item->get_total(),
					'fee_percent'   => $item->get_meta( '_tnm_fee_percent', true ),
					'platform_fee'  => $item->get_meta( '_tnm_platform_fee', true ),
					'net_before_sh' => $item->get_meta( '_tnm_seller_net_before_shipping', true ),
				);
			}
		}

		return new WP_REST_Response( $out, 200 );
	}

	public static function register_menu(): void {
		add_submenu_page(
			self::MENU_PARENT,
			'Seller Attribution',
			'Seller Attribution',
			self::CAP,
			self::MENU_SLUG,
			array( __CLASS__, 'render_screen' )
		);
	}

	/**
	 * Return counters describing seller-attribution coverage across the
	 * product catalog. All queries hit wp_postmeta directly so no cached
	 * WC_Product objects can hide drift.
	 *
	 * @return array<string,int>
	 */
	public static function catalog_stats(): array {
		global $wpdb;
		$stats = array(
			'total_products'         => 0,
			'products_with_meta'     => 0,
			'products_no_meta'       => 0,
			'products_author_only'   => 0,
			'products_orphaned'      => 0,
		);
		$stats['total_products'] = (int) $wpdb->get_var(
			"SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type='product' AND post_status IN ('publish','private','draft','pending')"
		);
		if ( 0 === $stats['total_products'] ) {
			return $stats;
		}
		$stats['products_with_meta'] = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT p.ID)
			 FROM {$wpdb->posts} p
			 JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
			 WHERE p.post_type='product'
			   AND p.post_status IN ('publish','private','draft','pending')
			   AND pm.meta_key IN ('_tnm_seller_id','_mynest_seller_id','_wcv_vendor_id','_dokan_vendor_id')
			   AND pm.meta_value > 0"
		);
		$stats['products_no_meta']     = $stats['total_products'] - $stats['products_with_meta'];
		$stats['products_author_only'] = (int) $wpdb->get_var(
			"SELECT COUNT(p.ID)
			 FROM {$wpdb->posts} p
			 WHERE p.post_type='product'
			   AND p.post_status IN ('publish','private','draft','pending')
			   AND p.post_author > 0
			   AND NOT EXISTS (
				SELECT 1 FROM {$wpdb->postmeta} pm
				WHERE pm.post_id = p.ID
				  AND pm.meta_key IN ('_tnm_seller_id','_mynest_seller_id','_wcv_vendor_id','_dokan_vendor_id')
				  AND pm.meta_value > 0
			   )"
		);
		$stats['products_orphaned'] = (int) $wpdb->get_var(
			"SELECT COUNT(p.ID)
			 FROM {$wpdb->posts} p
			 WHERE p.post_type='product'
			   AND p.post_status IN ('publish','private','draft','pending')
			   AND ( p.post_author = 0 OR p.post_author IS NULL )
			   AND NOT EXISTS (
				SELECT 1 FROM {$wpdb->postmeta} pm
				WHERE pm.post_id = p.ID
				  AND pm.meta_key IN ('_tnm_seller_id','_mynest_seller_id','_wcv_vendor_id','_dokan_vendor_id')
				  AND pm.meta_value > 0
			   )"
		);
		return $stats;
	}

	/**
	 * Return the product ids that would be touched by backfill: those
	 * without a seller meta but with a valid post_author.
	 *
	 * @param int $limit
	 * @return array<int,array<string,int>>
	 */
	public static function backfill_candidates( int $limit = 500 ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID AS id, p.post_author AS author, p.post_title AS title
				 FROM {$wpdb->posts} p
				 WHERE p.post_type='product'
				   AND p.post_status IN ('publish','private','draft','pending')
				   AND p.post_author > 0
				   AND NOT EXISTS (
					SELECT 1 FROM {$wpdb->postmeta} pm
					WHERE pm.post_id = p.ID
					  AND pm.meta_key IN ('_tnm_seller_id','_mynest_seller_id','_wcv_vendor_id','_dokan_vendor_id')
					  AND pm.meta_value > 0
				   )
				 ORDER BY p.ID ASC
				 LIMIT %d",
				$limit
			),
			ARRAY_A
		);
		return $rows ?: array();
	}

	/**
	 * Return orders where the checkout stamping detected seller-resolution
	 * collapse (see TNM_Marketplace::stamp_order_sellers()).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function collapsed_orders( int $limit = 50 ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.post_id AS order_id, p.post_date AS created, p.post_status AS status
				 FROM {$wpdb->postmeta} pm
				 JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = '_tnm_seller_resolution_collapsed'
				   AND pm.meta_value = '1'
				 ORDER BY p.post_date DESC
				 LIMIT %d",
				$limit
			),
			ARRAY_A
		);
		return $rows ?: array();
	}

	/**
	 * Backfill missing _tnm_seller_id from post_author for every eligible
	 * product. Called from the admin screen behind a capability check and
	 * a nonce.
	 *
	 * @return int Number of products stamped.
	 */
	public static function run_backfill(): int {
		$candidates = self::backfill_candidates( 2000 );
		$stamped    = 0;
		foreach ( $candidates as $row ) {
			$pid    = (int) $row['id'];
			$author = (int) $row['author'];
			if ( $pid <= 0 || $author <= 0 ) {
				continue;
			}
			if ( update_post_meta( $pid, '_tnm_seller_id', $author ) ) {
				update_post_meta( $pid, '_mynest_seller_id', $author );
				$stamped++;
			}
		}
		return $stamped;
	}

	public static function handle_backfill(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Not allowed.', 403 );
		}
		check_admin_referer( self::NONCE_KEY );
		$stamped = self::run_backfill();
		set_transient(
			'mnu_seller_attribution_notice_' . get_current_user_id(),
			array(
				'type'    => 'success',
				'message' => sprintf( 'Stamped _tnm_seller_id on %d product(s) from post_author.', $stamped ),
			),
			60
		);
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
		exit;
	}

	public static function render_screen(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Not allowed.', 403 );
		}
		$stats      = self::catalog_stats();
		$candidates = self::backfill_candidates( 25 );
		$collapsed  = self::collapsed_orders( 25 );
		$notice     = get_transient( 'mnu_seller_attribution_notice_' . get_current_user_id() );
		if ( is_array( $notice ) ) {
			delete_transient( 'mnu_seller_attribution_notice_' . get_current_user_id() );
		}
		?>
		<div class="wrap">
			<h1>Seller Attribution</h1>
			<p style="max-width:780px;">
				Coverage and drift for <code>_tnm_seller_id</code> across the product catalog.
				Backfill stamps <code>_tnm_seller_id</code> and <code>_mynest_seller_id</code>
				from <code>post_author</code> so nothing can fall through to the
				checkout-time <code>post_author</code> fallback and end up on the wrong seller
				after a user swap.
			</p>

			<?php if ( is_array( $notice ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible">
					<p><?php echo esc_html( $notice['message'] ); ?></p>
				</div>
			<?php endif; ?>

			<h2>Catalog coverage</h2>
			<table class="widefat striped" style="max-width:640px;">
				<tbody>
					<tr><th>Total products</th><td><?php echo (int) $stats['total_products']; ?></td></tr>
					<tr><th>Products with a seller meta</th><td><?php echo (int) $stats['products_with_meta']; ?></td></tr>
					<tr><th>Products relying on <code>post_author</code> only</th><td><?php echo (int) $stats['products_author_only']; ?></td></tr>
					<tr><th>Orphaned products (no meta, no author)</th><td><?php echo (int) $stats['products_orphaned']; ?></td></tr>
				</tbody>
			</table>

			<?php if ( $candidates ) : ?>
				<h2>Backfill candidates (first <?php echo count( $candidates ); ?>)</h2>
				<p>These products would be stamped <code>_tnm_seller_id = post_author</code>.</p>
				<table class="widefat striped">
					<thead><tr><th>Product ID</th><th>Title</th><th>post_author</th></tr></thead>
					<tbody>
					<?php foreach ( $candidates as $row ) : ?>
						<tr>
							<td>#<?php echo (int) $row['id']; ?></td>
							<td><?php echo esc_html( $row['title'] ?: '(no title)' ); ?></td>
							<td><?php echo (int) $row['author']; ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:16px;">
					<?php wp_nonce_field( self::NONCE_KEY ); ?>
					<input type="hidden" name="action" value="mnu_seller_attribution_backfill" />
					<button type="submit" class="button button-primary">Backfill <?php echo (int) $stats['products_author_only']; ?> product(s) now</button>
				</form>
			<?php else : ?>
				<h2>Catalog is clean</h2>
				<p>Every eligible product already carries an explicit seller meta.</p>
			<?php endif; ?>

			<h2 style="margin-top:32px;">Recent orders with collapsed seller resolution</h2>
			<p>Orders flagged with <code>_tnm_seller_resolution_collapsed = 1</code>
			   at checkout stamping time. Empty is what you want.</p>
			<?php if ( $collapsed ) : ?>
				<table class="widefat striped" style="max-width:720px;">
					<thead><tr><th>Order</th><th>Status</th><th>Created</th></tr></thead>
					<tbody>
					<?php foreach ( $collapsed as $row ) : ?>
						<tr>
							<td><a href="<?php echo esc_url( admin_url( 'post.php?post=' . (int) $row['order_id'] . '&action=edit' ) ); ?>">#<?php echo (int) $row['order_id']; ?></a></td>
							<td><?php echo esc_html( $row['status'] ); ?></td>
							<td><?php echo esc_html( $row['created'] ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p><em>No collapsed-resolution orders on record.</em></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * v3.7.78 — Admin shipping diagnostic.
	 *
	 * Accepts EITHER an existing order_id (uses its line items + shipping
	 * address) OR a list of product_ids + a destination { street1, city,
	 * state, zip, country }. Groups the lines by seller, runs the live
	 * per-seller Shippo rate lookup independently for each seller, and
	 * returns a per-seller breakdown so we can prove distinct rates on a
	 * multi-seller cart instead of returning a single blended "no options"
	 * error the way the checkout does.
	 */
	public static function rest_shipping_diagnose( WP_REST_Request $req ): WP_REST_Response {
		$order_id    = (int) $req->get_param( 'order_id' );
		$product_ids = (array) ( $req->get_param( 'product_ids' ) ?: array() );
		$to_addr     = (array) ( $req->get_param( 'to' ) ?: array() );

		$lines = array();
		$address = array();

		if ( $order_id > 0 ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				return new WP_REST_Response( array( 'error' => 'order_not_found', 'order_id' => $order_id ), 404 );
			}
			foreach ( $order->get_items() as $item ) {
				$product = $item->get_product();
				if ( ! $product instanceof WC_Product ) {
					continue;
				}
				$lines[] = array(
					'product_id' => (int) $product->get_id(),
					'quantity'   => max( 1, (int) $item->get_quantity() ),
					'product'    => $product,
				);
			}
			$address = array(
				'address_1' => $order->get_shipping_address_1() ?: $order->get_billing_address_1(),
				'address_2' => $order->get_shipping_address_2() ?: $order->get_billing_address_2(),
				'city'      => $order->get_shipping_city() ?: $order->get_billing_city(),
				'state'     => $order->get_shipping_state() ?: $order->get_billing_state(),
				'postcode'  => $order->get_shipping_postcode() ?: $order->get_billing_postcode(),
				'country'   => $order->get_shipping_country() ?: $order->get_billing_country() ?: 'US',
			);
		} else {
			foreach ( $product_ids as $pid ) {
				$pid     = (int) $pid;
				$product = $pid > 0 ? wc_get_product( $pid ) : null;
				if ( ! $product instanceof WC_Product ) {
					continue;
				}
				$lines[] = array(
					'product_id' => (int) $product->get_id(),
					'quantity'   => 1,
					'product'    => $product,
				);
			}
			$address = array(
				'address_1' => (string) ( $to_addr['street1']  ?? '' ),
				'address_2' => (string) ( $to_addr['street2']  ?? '' ),
				'city'      => (string) ( $to_addr['city']     ?? '' ),
				'state'     => (string) ( $to_addr['state']    ?? '' ),
				'postcode'  => (string) ( $to_addr['zip']      ?? '' ),
				'country'   => (string) ( $to_addr['country']  ?? 'US' ),
			);
		}

		if ( empty( $lines ) ) {
			return new WP_REST_Response( array( 'error' => 'no_lines' ), 400 );
		}

		// Group by seller.
		$by_seller = array();
		foreach ( $lines as $line ) {
			$product   = $line['product'];
			$seller_id = (int) tnm_get_product_seller_id( $product );
			$by_seller[ $seller_id ][] = $line;
		}

		// Build the Shippo "to" once.
		$to = function_exists( 'mnu_native_shippo_destination' )
			? mnu_native_shippo_destination( $address )
			: array( 'street1' => $address['address_1'] ?? '', 'city' => $address['city'] ?? '', 'state' => $address['state'] ?? '', 'zip' => $address['postcode'] ?? '', 'country' => $address['country'] ?? 'US' );

		$breakdown = array();

		foreach ( $by_seller as $seller_id => $seller_lines ) {
			$row = array(
				'seller_id'      => $seller_id,
				'line_count'     => count( $seller_lines ),
				'product_ids'    => array_map( static function ( $l ) { return (int) $l['product_id']; }, $seller_lines ),
				'ship_from_ok'   => false,
				'parcel_ok'      => false,
				'rates_count'    => 0,
				'cheapest'       => null,
				'rates'          => array(),
				'error'          => null,
				'missing_field'  => null,
			);

			if ( $seller_id <= 0 ) {
				$row['error']         = 'no_seller_attributed';
				$row['missing_field'] = 'seller_id';
				$breakdown[]          = $row;
				continue;
			}

			$missing_from = function_exists( 'mnu_seller_ship_from_missing_field' )
				? mnu_seller_ship_from_missing_field( $seller_id )
				: '';
			if ( '' !== $missing_from ) {
				$row['error']         = 'incomplete_ship_from';
				$row['missing_field'] = $missing_from;
				$breakdown[]          = $row;
				continue;
			}
			$row['ship_from_ok'] = true;

			// Check per-product parcel completeness before firing at Shippo.
			foreach ( $seller_lines as $line ) {
				$missing_pkg = function_exists( 'mnu_product_parcel_missing_field' )
					? mnu_product_parcel_missing_field( (int) $line['product_id'] )
					: '';
				if ( '' !== $missing_pkg ) {
					$row['error']         = 'incomplete_parcel';
					$row['missing_field'] = $missing_pkg . ' (product ' . (int) $line['product_id'] . ')';
					break;
				}
			}
			if ( null !== $row['error'] ) {
				$breakdown[] = $row;
				continue;
			}
			$row['parcel_ok'] = true;

			// Build parcel + call Shippo directly so one seller's failure
			// doesn't short-circuit the others.
			$profile = mnu_ship_get_profile( $seller_id );
			$from    = mnu_native_seller_ship_from( $seller_id );
			$parcel  = mnu_native_parcel_for_lines( $seller_lines, $profile );
			if ( is_wp_error( $parcel ) ) {
				$row['error'] = 'parcel_error:' . $parcel->get_error_code();
				$breakdown[]  = $row;
				continue;
			}

			$shipment = function_exists( 'mnu_labels_shippo_request' )
				? mnu_labels_shippo_request(
					'/shipments/',
					array(
						'address_from' => $from,
						'address_to'   => $to,
						'parcels'      => array( $parcel ),
						'async'        => false,
					)
				)
				: new WP_Error( 'no_shippo_client', 'mnu_labels_shippo_request unavailable' );

			if ( is_wp_error( $shipment ) ) {
				$row['error'] = 'shippo_error:' . $shipment->get_error_code() . ':' . $shipment->get_error_message();
				$breakdown[]  = $row;
				continue;
			}

			$raw_rates = ( isset( $shipment['rates'] ) && is_array( $shipment['rates'] ) ) ? $shipment['rates'] : array();
			$rates     = function_exists( 'mnu_labels_sort_rates' ) ? mnu_labels_sort_rates( $raw_rates ) : $raw_rates;

			if ( empty( $rates ) ) {
				$row['error'] = 'no_live_rates';
				if ( function_exists( 'mnu_labels_shippo_error_message' ) ) {
					$row['error'] .= ':' . mnu_labels_shippo_error_message( $shipment );
				}
				$breakdown[] = $row;
				continue;
			}

			$row['rates_count'] = count( $rates );
			$flat = array();
			foreach ( $rates as $rate ) {
				$flat[] = array(
					'provider' => (string) ( $rate['provider'] ?? '' ),
					'service'  => (string) ( $rate['servicelevel']['name'] ?? $rate['servicelevel_name'] ?? '' ),
					'token'    => (string) ( $rate['servicelevel']['token'] ?? $rate['servicelevel_token'] ?? '' ),
					'amount'   => (string) ( $rate['amount'] ?? '' ),
					'currency' => (string) ( $rate['currency'] ?? 'USD' ),
					'days'     => (string) ( $rate['estimated_days'] ?? '' ),
				);
			}
			$row['rates']    = $flat;
			$row['cheapest'] = $flat[0];
			$breakdown[]     = $row;
		}

		return new WP_REST_Response(
			array(
				'ok'          => true,
				'order_id'    => $order_id,
				'to'          => $to,
				'seller_count'=> count( $by_seller ),
				'breakdown'   => $breakdown,
			),
			200
		);
	}
}

MNU_Seller_Attribution::init();
