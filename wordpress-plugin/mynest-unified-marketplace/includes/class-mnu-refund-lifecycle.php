<?php
/**
 * MNU_Refund_Lifecycle
 *
 * Turns WooCommerce's binary "refunded / not refunded" reality into a five-step
 * lifecycle a buyer can understand:
 *
 *   none      — no refund activity yet
 *   requested — buyer asked for a refund (or admin marked as requested)
 *   approved  — admin approved but Stripe refund not fired yet
 *   processing — Stripe refund created, waiting on refund.succeeded
 *   completed — Stripe refund succeeded (or Woo refund exists with no Stripe intent)
 *   denied    — admin denied the request
 *
 * Persists a compact timeline on the order in `_mnu_refund_lifecycle` so
 * both the buyer app and the admin UI read the same source of truth.
 *
 * Also owns:
 *   - Buyer eligibility check against the 14-day + unused policy
 *     (concepts/shopmynest-shipping-and-labels).
 *   - Buyer REST endpoint POST /orders/{id}/refund-request
 *   - Admin REST endpoints POST /admin/orders/{id}/refund/{approve|deny}
 *   - Handling of stripe refund.updated webhook events
 *   - Hooking woocommerce_order_refunded to mark lifecycle as completed
 *
 * @package MyNest_Unified_Marketplace
 * @since 3.7.90
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MNU_Refund_Lifecycle {

	public const META_KEY   = '_mnu_refund_lifecycle';
	public const NS         = 'the-nest/v1';
	public const POLICY_DAYS = 14;

	public const STATE_NONE       = 'none';
	public const STATE_REQUESTED  = 'requested';
	public const STATE_APPROVED   = 'approved';
	public const STATE_PROCESSING = 'processing';
	public const STATE_COMPLETED  = 'completed';
	public const STATE_DENIED     = 'denied';

	/**
	 * Wire hooks.
	 */
	public static function init(): void {
		add_action( 'rest_api_init',            array( __CLASS__, 'register_routes' ) );
		add_action( 'woocommerce_order_refunded', array( __CLASS__, 'on_woo_refunded' ), 20, 2 );
	}

	/**
	 * REST routes.
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::NS,
			'/orders/(?P<id>\d+)/refund-request',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'rest_buyer_request' ),
				'permission_callback' => array( __CLASS__, 'perm_buyer' ),
				'args'                => array(
					'reason'  => array( 'type' => 'string', 'required' => false ),
					'details' => array( 'type' => 'string', 'required' => false ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/orders/(?P<id>\d+)/refund',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'rest_refund_view' ),
				'permission_callback' => array( __CLASS__, 'perm_view_order' ),
			)
		);
		register_rest_route(
			self::NS,
			'/admin/orders/(?P<id>\d+)/refund/approve',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'rest_admin_approve' ),
				'permission_callback' => array( __CLASS__, 'perm_admin' ),
				'args'                => array(
					'amount' => array( 'type' => 'number', 'required' => false ),
					'note'   => array( 'type' => 'string', 'required' => false ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/admin/orders/(?P<id>\d+)/refund/deny',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'rest_admin_deny' ),
				'permission_callback' => array( __CLASS__, 'perm_admin' ),
				'args'                => array(
					'note' => array( 'type' => 'string', 'required' => false ),
				),
			)
		);
	}

	/* ---------------- Permissions ---------------- */

	public static function perm_admin(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	public static function perm_buyer( WP_REST_Request $request ): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		$order = wc_get_order( absint( $request['id'] ) );
		if ( ! $order ) {
			return false;
		}
		return (int) $order->get_customer_id() === get_current_user_id();
	}

	public static function perm_view_order( WP_REST_Request $request ): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		$order = wc_get_order( absint( $request['id'] ) );
		if ( ! $order ) {
			return false;
		}
		$user_id = get_current_user_id();
		if ( (int) $order->get_customer_id() === $user_id ) {
			return true;
		}
		if ( user_can( $user_id, 'manage_woocommerce' ) ) {
			return true;
		}
		// Seller with an item on the order can also read the refund view.
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$seller_id = function_exists( 'tnm_get_order_item_seller_id' )
				? (int) tnm_get_order_item_seller_id( $item ) : 0;
			if ( $seller_id === $user_id ) {
				return true;
			}
		}
		return false;
	}

	/* ---------------- Persistence ---------------- */

	/**
	 * Read the lifecycle record for an order. Always returns an array shape.
	 *
	 * @param WC_Order $order
	 * @return array{state:string,timeline:array,requested_amount:float,refunded_amount:float,reason:string,details:string,denial_note:string,stripe_refund_id:string}
	 */
	public static function get( WC_Order $order ): array {
		$raw    = (string) $order->get_meta( self::META_KEY, true );
		$parsed = $raw ? json_decode( $raw, true ) : array();
		if ( ! is_array( $parsed ) ) {
			$parsed = array();
		}
		return wp_parse_args(
			$parsed,
			array(
				'state'            => self::STATE_NONE,
				'timeline'         => array(),
				'requested_amount' => 0.0,
				'refunded_amount'  => 0.0,
				'reason'           => '',
				'details'          => '',
				'denial_note'      => '',
				'stripe_refund_id' => '',
			)
		);
	}

	/**
	 * Save the lifecycle record and append a timeline entry.
	 */
	public static function set( WC_Order $order, array $update, string $event_actor, string $event_label ): array {
		$current = self::get( $order );
		$next    = array_merge( $current, $update );
		$next['timeline'][] = array(
			'at'    => current_time( 'mysql', true ),
			'state' => (string) $next['state'],
			'actor' => $event_actor,
			'label' => $event_label,
		);
		$order->update_meta_data( self::META_KEY, wp_json_encode( $next ) );
		$order->save();
		return $next;
	}

	/* ---------------- Eligibility ---------------- */

	/**
	 * Check the "unused AND <14 days old" refund policy. Returns
	 * eligibility + list of specific blockers so the UI can show them.
	 *
	 * @return array{eligible:bool,blockers:array<int,string>,days_since_delivery:?int,days_since_purchase:int,delivered:bool}
	 */
	public static function eligibility( WC_Order $order ): array {
		$blockers            = array();
		$now                 = current_time( 'timestamp', true );
		$date_created        = $order->get_date_created();
		$purchase_ts         = $date_created ? $date_created->getTimestamp() : $now;
		$days_since_purchase = (int) floor( ( $now - $purchase_ts ) / DAY_IN_SECONDS );

		// Delivered date: prefer per-seller tracking (all sellers delivered),
		// fall back to date_completed, else null.
		$delivered   = false;
		$delivered_at = null;
		$sellers      = array();
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$sid = function_exists( 'tnm_get_order_item_seller_id' )
				? (int) tnm_get_order_item_seller_id( $item ) : 0;
			if ( $sid > 0 ) {
				$sellers[ $sid ] = true;
			}
		}
		$all_delivered = ! empty( $sellers );
		foreach ( array_keys( $sellers ) as $sid ) {
			$status = (string) $order->get_meta( '_tnm_seller_status_' . $sid, true );
			if ( 'delivered' === $status ) {
				$ts = (int) $order->get_meta( '_tnm_seller_delivered_at_' . $sid, true );
				if ( $ts > 0 && ( null === $delivered_at || $ts > $delivered_at ) ) {
					$delivered_at = $ts;
				}
			} else {
				$all_delivered = false;
			}
		}
		if ( $all_delivered && $delivered_at ) {
			$delivered = true;
		} elseif ( 'completed' === $order->get_status() ) {
			$completed = $order->get_date_completed();
			if ( $completed ) {
				$delivered    = true;
				$delivered_at = $completed->getTimestamp();
			}
		}
		$days_since_delivery = ( $delivered && $delivered_at )
			? (int) floor( ( $now - $delivered_at ) / DAY_IN_SECONDS )
			: null;

		// Refund window: 14 days measured from delivery when available, from
		// purchase otherwise (this matches the customer-facing policy page).
		$reference_days = null !== $days_since_delivery ? $days_since_delivery : $days_since_purchase;
		if ( $reference_days > self::POLICY_DAYS ) {
			$blockers[] = sprintf(
				'It has been %d days since %s. Refunds are only available within %d days.',
				$reference_days,
				null !== $days_since_delivery ? 'delivery' : 'purchase',
				self::POLICY_DAYS
			);
		}

		// Status guard: cancelled, failed, or already fully-refunded orders
		// have nothing to refund from the buyer's side.
		$status = $order->get_status();
		if ( in_array( $status, array( 'cancelled', 'failed' ), true ) ) {
			$blockers[] = sprintf( 'This order is %s and cannot be refunded.', $status );
		}
		$existing = self::get( $order );
		if ( self::STATE_COMPLETED === $existing['state'] && $existing['refunded_amount'] >= (float) $order->get_total() ) {
			$blockers[] = 'This order has already been refunded in full.';
		}

		return array(
			'eligible'            => empty( $blockers ),
			'blockers'            => $blockers,
			'days_since_delivery' => $days_since_delivery,
			'days_since_purchase' => $days_since_purchase,
			'delivered'           => $delivered,
		);
	}

	/* ---------------- Buyer request ---------------- */

	public static function rest_buyer_request( WP_REST_Request $request ) {
		$order = wc_get_order( absint( $request['id'] ) );
		if ( ! $order ) {
			return new WP_Error( 'order_not_found', 'Order not found.', array( 'status' => 404 ) );
		}
		if ( class_exists( 'MNU_Trust' ) && MNU_Trust::has_active_for_order( $order->get_id() ) ) {
			return new WP_Error( 'dispute_already_open', 'A buyer-protection case is already open for this order.', array( 'status' => 409 ) );
		}

		$current = self::get( $order );
		if ( in_array( $current['state'], array( self::STATE_REQUESTED, self::STATE_APPROVED, self::STATE_PROCESSING ), true ) ) {
			return new WP_Error( 'refund_already_open', 'A refund request is already open for this order.', array( 'status' => 409 ) );
		}
		if ( self::STATE_COMPLETED === $current['state'] && $current['refunded_amount'] >= (float) $order->get_total() ) {
			return new WP_Error( 'refund_already_completed', 'This order has already been refunded in full.', array( 'status' => 409 ) );
		}

		$eligibility = self::eligibility( $order );
		if ( ! $eligibility['eligible'] ) {
			return new WP_Error(
				'refund_not_eligible',
				implode( ' ', $eligibility['blockers'] ),
				array( 'status' => 422, 'blockers' => $eligibility['blockers'] )
			);
		}

		$reason  = sanitize_text_field( (string) $request->get_param( 'reason' ) );
		$details = sanitize_textarea_field( (string) $request->get_param( 'details' ) );
		if ( '' === $reason ) {
			return new WP_Error( 'refund_reason_required', 'Choose a reason for the refund.', array( 'status' => 422 ) );
		}

		$user = wp_get_current_user();
		$lifecycle = self::set(
			$order,
			array(
				'state'            => self::STATE_REQUESTED,
				'requested_amount' => (float) $order->get_total(),
				'reason'           => $reason,
				'details'          => $details,
			),
			'buyer:' . $user->user_login,
			'Refund requested'
		);

		$order->add_order_note( sprintf( 'Buyer requested a refund: %s%s',
			$reason,
			$details ? ' — ' . $details : ''
		) );

		// Notify admin. Fire-and-forget: template can be expanded later.
		self::notify_admin(
			$order,
			'New refund request',
			sprintf(
				"Order #%d from %s.\nReason: %s\n%s\nReview at %s",
				$order->get_id(),
				$order->get_billing_email(),
				$reason,
				$details ? "Details: {$details}\n" : '',
				admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' )
			)
		);

		return rest_ensure_response( self::view_payload( $order, $lifecycle, $eligibility ) );
	}

	/* ---------------- Admin approve/deny ---------------- */

	public static function rest_admin_approve( WP_REST_Request $request ) {
		$order = wc_get_order( absint( $request['id'] ) );
		if ( ! $order ) {
			return new WP_Error( 'order_not_found', 'Order not found.', array( 'status' => 404 ) );
		}
		$current = self::get( $order );
		$amount  = (float) $request->get_param( 'amount' );
		if ( $amount <= 0 ) {
			$amount = $current['requested_amount'] > 0
				? (float) $current['requested_amount']
				: (float) $order->get_total();
		}
		$note = sanitize_textarea_field( (string) $request->get_param( 'note' ) );
		$user = wp_get_current_user();

		// Step 1: mark approved.
		self::set(
			$order,
			array(
				'state'            => self::STATE_APPROVED,
				'requested_amount' => $amount,
			),
			'admin:' . $user->user_login,
			sprintf( 'Refund approved for $%.2f', $amount ) . ( $note ? ' — ' . $note : '' )
		);

		// Step 2: fire Woo refund which routes through MNU_Woo_Gateway_Impl::process_refund
		// (already handles multi-seller SCT transfer reversals). This also
		// triggers woocommerce_order_refunded -> ledger + our on_woo_refunded.
		$refund = wc_create_refund(
			array(
				'order_id'   => $order->get_id(),
				'amount'     => $amount,
				'reason'     => $current['reason'] ?: 'Buyer refund request approved.',
				'api_refund' => true, // ask gateway to talk to Stripe
			)
		);
		if ( is_wp_error( $refund ) ) {
			// Roll back to requested so admin can retry after fixing the cause.
			self::set(
				$order,
				array( 'state' => self::STATE_REQUESTED ),
				'system',
				'Refund approval failed at gateway: ' . $refund->get_error_message()
			);
			return $refund;
		}

		$lifecycle = self::get( $order );
		if ( self::STATE_APPROVED === $lifecycle['state'] ) {
			// Gateway didn't emit refund.succeeded synchronously — mark processing
			// and let the webhook (or on_woo_refunded already-fired) advance it.
			$lifecycle = self::set(
				$order,
				array( 'state' => self::STATE_PROCESSING ),
				'system',
				'Stripe refund created, waiting for confirmation.'
			);
		}
		$eligibility = self::eligibility( $order );
		return rest_ensure_response( self::view_payload( $order, $lifecycle, $eligibility ) );
	}

	public static function rest_admin_deny( WP_REST_Request $request ) {
		$order = wc_get_order( absint( $request['id'] ) );
		if ( ! $order ) {
			return new WP_Error( 'order_not_found', 'Order not found.', array( 'status' => 404 ) );
		}
		$note = sanitize_textarea_field( (string) $request->get_param( 'note' ) );
		$user = wp_get_current_user();
		$lifecycle = self::set(
			$order,
			array(
				'state'       => self::STATE_DENIED,
				'denial_note' => $note,
			),
			'admin:' . $user->user_login,
			'Refund denied' . ( $note ? ' — ' . $note : '' )
		);
		$order->add_order_note( 'Refund request denied by admin' . ( $note ? ': ' . $note : '.' ) );

		if ( $order->get_billing_email() ) {
			wp_mail(
				$order->get_billing_email(),
				sprintf( 'Update on your refund request for order #%d', $order->get_id() ),
				sprintf(
					"Hi %s,\n\nAfter review we're unable to approve your refund for order #%d.%s\n\nIf you have questions reply to this email or contact support.\n\nShopMyNest",
					$order->get_billing_first_name() ?: 'there',
					$order->get_id(),
					$note ? "\n\nNote from our team: {$note}" : ''
				)
			);
		}
		$eligibility = self::eligibility( $order );
		return rest_ensure_response( self::view_payload( $order, $lifecycle, $eligibility ) );
	}

	/* ---------------- Buyer view ---------------- */

	public static function rest_refund_view( WP_REST_Request $request ) {
		$order = wc_get_order( absint( $request['id'] ) );
		if ( ! $order ) {
			return new WP_Error( 'order_not_found', 'Order not found.', array( 'status' => 404 ) );
		}
		$lifecycle   = self::get( $order );
		$eligibility = self::eligibility( $order );
		return rest_ensure_response( self::view_payload( $order, $lifecycle, $eligibility ) );
	}

	/**
	 * Buyer-safe payload used by the mobile refund card.
	 */
	public static function view_payload( WC_Order $order, array $lifecycle, array $eligibility ): array {
		return array(
			'order_id'         => $order->get_id(),
			'currency'         => $order->get_currency(),
			'order_total'      => (float) $order->get_total(),
			'state'            => (string) $lifecycle['state'],
			'label'            => self::state_label( (string) $lifecycle['state'] ),
			'requested_amount' => (float) $lifecycle['requested_amount'],
			'refunded_amount'  => (float) $lifecycle['refunded_amount'],
			'reason'           => (string) $lifecycle['reason'],
			'details'          => (string) $lifecycle['details'],
			'denial_note'      => (string) $lifecycle['denial_note'],
			'timeline'         => array_map(
				static function ( $entry ) {
					return array(
						'at'    => (string) ( $entry['at']    ?? '' ),
						'state' => (string) ( $entry['state'] ?? '' ),
						'label' => (string) ( $entry['label'] ?? '' ),
					);
				},
				(array) $lifecycle['timeline']
			),
			'eligibility'      => array(
				'can_request' => (bool) $eligibility['eligible']
					&& ! in_array( (string) $lifecycle['state'], array( self::STATE_REQUESTED, self::STATE_APPROVED, self::STATE_PROCESSING ), true )
					&& ( self::STATE_COMPLETED !== (string) $lifecycle['state'] || (float) $lifecycle['refunded_amount'] < (float) $order->get_total() ),
				'blockers'    => array_values( (array) $eligibility['blockers'] ),
				'policy_days' => self::POLICY_DAYS,
			),
		);
	}

	public static function state_label( string $state ): string {
		switch ( $state ) {
			case self::STATE_REQUESTED:  return 'Refund requested';
			case self::STATE_APPROVED:   return 'Refund approved';
			case self::STATE_PROCESSING: return 'Refund processing';
			case self::STATE_COMPLETED:  return 'Refund completed';
			case self::STATE_DENIED:     return 'Refund denied';
			case self::STATE_NONE:
			default:                     return 'No refund activity';
		}
	}

	/* ---------------- Woo/Stripe wire-in ---------------- */

	/**
	 * Advance lifecycle when a WooCommerce refund is created (regardless of
	 * whether it originated from an approved request, an admin quick-refund,
	 * or Stripe dashboard flow syncing back through the gateway).
	 */
	public static function on_woo_refunded( int $order_id, int $refund_id ): void {
		$order  = wc_get_order( $order_id );
		$refund = wc_get_order( $refund_id );
		if ( ! $order || ! $refund || ! is_a( $refund, 'WC_Order_Refund' ) ) {
			return;
		}
		$current  = self::get( $order );
		$total_refunded = 0.0;
		foreach ( $order->get_refunds() as $r ) {
			$total_refunded += abs( (float) $r->get_amount() );
		}
		$stripe_refund_id = (string) $refund->get_refunded_payment();
		self::set(
			$order,
			array(
				'state'            => self::STATE_COMPLETED,
				'refunded_amount'  => $total_refunded,
				'stripe_refund_id' => $stripe_refund_id ?: (string) $current['stripe_refund_id'],
			),
			'system',
			sprintf( 'Refund completed: $%.2f', abs( (float) $refund->get_amount() ) )
		);
		if ( $order->get_billing_email() ) {
			wp_mail(
				$order->get_billing_email(),
				sprintf( 'Your refund for order #%d has been issued', $order->get_id() ),
				sprintf(
					"Hi %s,\n\nYour refund of $%.2f for order #%d has been issued and should appear on your card within 5-10 business days.\n\nShopMyNest",
					$order->get_billing_first_name() ?: 'there',
					abs( (float) $refund->get_amount() ),
					$order->get_id()
				)
			);
		}
	}

	/**
	 * Called from the Stripe webhook handler for refund.updated / refund.succeeded.
	 */
	public static function handle_stripe_refund_event( array $refund_object ): void {
		$intent_id = (string) ( $refund_object['payment_intent'] ?? '' );
		if ( '' === $intent_id ) {
			return;
		}
		$orders = wc_get_orders( array(
			'meta_key'   => '_thenest_stripe_payment_intent',
			'meta_value' => $intent_id,
			'limit'      => 1,
			'return'     => 'objects',
		) );
		if ( empty( $orders ) ) {
			return;
		}
		$order   = $orders[0];
		$current = self::get( $order );

		$status         = (string) ( $refund_object['status'] ?? '' );
		$amount_cents   = (int)    ( $refund_object['amount'] ?? 0 );
		$amount_dollars = $amount_cents / 100;
		$stripe_id      = (string) ( $refund_object['id'] ?? '' );

		if ( 'succeeded' === $status ) {
			// v3.13.28 — previously we only updated a state meta here. That
			// meant a refund issued from the Stripe Dashboard confirmed on our
			// side WITHOUT creating a Woo refund or a ledger clawback, leaving
			// the seller's earnings still available or already paid. Now we
			// idempotently create a matching Woo refund keyed by the Stripe
			// refund id. wc_create_refund() fires woocommerce_order_refunded,
			// which TNM_Ledger::on_woo_refunded() hooks to write the negative
			// adjustment row.
			$already_recorded = false;
			$existing_refunds = $order->get_refunds();
			foreach ( $existing_refunds as $r ) {
				$recorded_stripe = (string) $r->get_meta( '_mnu_stripe_refund_id', true );
				if ( $recorded_stripe && hash_equals( $recorded_stripe, $stripe_id ) ) {
					$already_recorded = true;
					break;
				}
			}

			if ( ! $already_recorded && $amount_dollars > 0 ) {
				$refund_result = wc_create_refund( array(
					'order_id'   => $order->get_id(),
					'amount'     => $amount_dollars,
					'reason'     => sprintf( 'Refund issued from Stripe (%s)', $stripe_id ),
					// api_refund=false because Stripe has ALREADY refunded the buyer.
					// If we let Woo call the gateway again it would try to refund
					// a second time on top of the already-issued Dashboard refund.
					'api_refund' => false,
				) );
				if ( ! is_wp_error( $refund_result ) && $refund_result instanceof \WC_Order_Refund ) {
					$refund_result->update_meta_data( '_mnu_stripe_refund_id', $stripe_id );
					$refund_result->update_meta_data( '_mnu_refund_source', 'stripe_dashboard' );
					$refund_result->save();
					$order->add_order_note( sprintf(
						'Recorded Stripe Dashboard refund %s ($%.2f) as Woo refund #%d.',
						$stripe_id,
						$amount_dollars,
						$refund_result->get_id()
					) );
				} else {
					$err = is_wp_error( $refund_result ) ? $refund_result->get_error_message() : 'unknown';
					$order->add_order_note( sprintf(
						'FAILED to record Stripe refund %s locally: %s. Ledger will be out of sync until reconciled manually.',
						$stripe_id,
						$err
					) );
				}
			}

			$total_refunded = 0.0;
			foreach ( $order->get_refunds() as $r ) {
				$total_refunded += abs( (float) $r->get_amount() );
			}
			if ( 0.0 === $total_refunded && $amount_dollars > 0 ) {
				$total_refunded = $amount_dollars;
			}
			self::set(
				$order,
				array(
					'state'            => self::STATE_COMPLETED,
					'refunded_amount'  => $total_refunded,
					'stripe_refund_id' => $stripe_id,
				),
				'stripe',
				sprintf( 'Stripe confirmed refund of $%.2f', $amount_dollars )
			);
		} elseif ( in_array( $status, array( 'pending', 'requires_action' ), true ) ) {
			if ( self::STATE_PROCESSING !== $current['state'] ) {
				self::set(
					$order,
					array(
						'state'            => self::STATE_PROCESSING,
						'stripe_refund_id' => $stripe_id,
					),
					'stripe',
					'Stripe reported refund is processing.'
				);
			}
		} elseif ( 'failed' === $status || 'canceled' === $status ) {
			self::set(
				$order,
				array(
					'state'       => self::STATE_APPROVED, // let admin retry
					'denial_note' => sprintf( 'Stripe refund %s: %s', $status, (string) ( $refund_object['failure_reason'] ?? '' ) ),
				),
				'stripe',
				sprintf( 'Stripe refund %s.', $status )
			);
		}
	}

	/* ---------------- Helpers ---------------- */

	private static function notify_admin( WC_Order $order, string $subject, string $body ): void {
		$to = get_option( 'admin_email' );
		if ( ! $to ) {
			return;
		}
		wp_mail( $to, $subject, $body );
	}
}

MNU_Refund_Lifecycle::init();
