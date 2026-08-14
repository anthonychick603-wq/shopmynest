<?php
/**
 * v3.7.35.5 — MyNest Marketplace checkout finalize REST endpoint.
 *
 * Server-first Blocks integration: after the shopper's browser has confirmed
 * a PaymentIntent that was created by MNU_Woo_Gateway::process_payment(),
 * this endpoint verifies the intent state directly with Stripe (never
 * trusting the browser) and finalizes the order.
 *
 * Route:  POST /wp-json/mnu/v1/finalize-order
 *   { "order_id": 2729, "intent_id": "pi_...", "nonce": "..." }
 *
 * On success it:
 *   - Verifies the intent is succeeded/processing/requires_capture and its
 *     wc_order_id metadata matches the posted order_id (defence against a
 *     malicious client passing someone else's intent id).
 *   - Attaches the intent id to the order as _thenest_stripe_payment_intent.
 *   - Calls $order->payment_complete( $intent_id ) which transitions status
 *     to processing/completed and fires the payment_complete hooks that
 *     downstream code (stock deduction, seller notifications, native push)
 *     already listens to.
 *   - Returns { result: "success", redirect: <thankyou_url> } for the
 *     browser to redirect to.
 *
 * On failure (intent not succeeded, order mismatch, etc.) it returns a
 * structured error. The Blocks JS layer surfaces the message and leaves
 * the order in pending so it can be inspected in wp-admin.
 */

defined( 'ABSPATH' ) || exit;

final class MNU_Checkout_Finalize {

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route(
			'mnu/v1',
			'/finalize-order',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'finalize_order' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'order_id'  => array( 'type' => 'integer', 'required' => true ),
					'intent_id' => array( 'type' => 'string',  'required' => true ),
					'nonce'     => array( 'type' => 'string',  'required' => true ),
				),
			)
		);
	}

	public static function finalize_order( \WP_REST_Request $req ) {
		$order_id  = (int) $req->get_param( 'order_id' );
		$intent_id = sanitize_text_field( (string) $req->get_param( 'intent_id' ) );
		$nonce     = sanitize_text_field( (string) $req->get_param( 'nonce' ) );

		if ( ! wp_verify_nonce( $nonce, 'mnu_finalize_order_' . $order_id ) ) {
			return new \WP_Error( 'mnu_bad_nonce', 'Invalid nonce.', array( 'status' => 403 ) );
		}
		if ( $order_id <= 0 || '' === $intent_id ) {
			return new \WP_Error( 'mnu_bad_input', 'Missing order_id or intent_id.', array( 'status' => 400 ) );
		}
		if ( 0 !== strpos( $intent_id, 'pi_' ) ) {
			return new \WP_Error( 'mnu_bad_intent', 'Invalid intent id.', array( 'status' => 400 ) );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new \WP_Error( 'mnu_no_order', 'Order not found.', array( 'status' => 404 ) );
		}

		// Only permit finalization on orders that our gateway created and are
		// still awaiting payment. Prevents double-finalization or hijacking a
		// completed order.
		if ( 'mnu_marketplace' !== (string) $order->get_payment_method() ) {
			return new \WP_Error( 'mnu_wrong_gateway', 'Order is not a MyNest gateway order.', array( 'status' => 400 ) );
		}
		$status = (string) $order->get_status();
		if ( ! in_array( $status, array( 'pending', 'failed' ), true ) ) {
			// Already finalized — idempotent success.
			return array(
				'result'   => 'success',
				'redirect' => wc_get_endpoint_url( 'order-received', (string) $order->get_id(), wc_get_page_permalink( 'checkout' ) )
					. ( strpos( wc_get_page_permalink( 'checkout' ), '?' ) === false ? '?' : '&' ) . 'key=' . $order->get_order_key(),
				'note'     => 'already_finalized',
			);
		}

		// Read the stored intent id we wrote in process_payment. This is our
		// trust anchor — the browser's posted intent_id must match it.
		$stored_intent = (string) $order->get_meta( '_thenest_stripe_payment_intent', true );
		if ( '' === $stored_intent ) {
			return new \WP_Error( 'mnu_no_stored_intent', 'Order has no pending intent (process_payment did not run).', array( 'status' => 400 ) );
		}
		if ( $stored_intent !== $intent_id ) {
			return new \WP_Error( 'mnu_intent_mismatch', 'Posted intent id does not match the order.', array( 'status' => 400 ) );
		}

		// Fetch the intent directly from Stripe. Never trust the browser
		// about payment status.
		if ( ! function_exists( 'mnu_native_stripe_get' ) ) {
			return new \WP_Error( 'mnu_no_stripe', 'Stripe helper unavailable.', array( 'status' => 503 ) );
		}
		$intent = mnu_native_stripe_get( '/payment_intents/' . rawurlencode( $intent_id ) );
		if ( is_wp_error( $intent ) ) {
			return new \WP_Error( 'mnu_stripe_error', 'Could not verify payment with Stripe: ' . $intent->get_error_message(), array( 'status' => 502 ) );
		}

		$intent_status = (string) ( $intent['status'] ?? '' );
		$paid_ok       = array( 'succeeded', 'requires_capture', 'processing' );
		if ( ! in_array( $intent_status, $paid_ok, true ) ) {
			return new \WP_Error(
				'mnu_intent_not_paid',
				sprintf( 'Payment intent is in status "%s"; expected succeeded/processing.', $intent_status ),
				array( 'status' => 402 )
			);
		}

		// Double-check that the intent's wc_order_id metadata points at this
		// same order. If we ever return an intent that was created for a
		// different order id (session bleed, etc.) this catches it.
		$intent_order_meta = (string) ( $intent['metadata']['wc_order_id'] ?? '' );
		if ( '' !== $intent_order_meta && (string) $order->get_id() !== $intent_order_meta ) {
			return new \WP_Error( 'mnu_intent_order_mismatch', 'Intent belongs to a different order.', array( 'status' => 400 ) );
		}

		// Persist transaction id and finalize. payment_complete() transitions
		// the order and fires downstream hooks (stock deduction, seller
		// notifications, push notifications, native app sync).
		$order->set_transaction_id( $intent_id );
		if ( ! empty( $intent['latest_charge'] ) ) {
			$order->update_meta_data( '_thenest_stripe_latest_charge', (string) $intent['latest_charge'] );
		}
		$order->add_order_note( sprintf(
			'Payment confirmed. Stripe intent %s (%s), charge %s.',
			$intent_id,
			$intent_status,
			(string) ( $intent['latest_charge'] ?? 'n/a' )
		) );
		$order->save();
		$order->payment_complete( $intent_id );

		// Clear the WC session's cached intent so the next order starts
		// fresh (defensive; process_payment already does this).
		if ( function_exists( 'WC' ) && WC() && is_object( WC()->session ) ) {
			WC()->session->set( 'mnu_gateway_intent', null );
			WC()->session->set( 'mnu_gateway_idem_salt', substr( wp_hash( uniqid( 'mnu', true ) ), 0, 8 ) );
		}

		$return_url = $order->get_checkout_order_received_url();
		return array(
			'result'   => 'success',
			'redirect' => $return_url,
		);
	}
}

MNU_Checkout_Finalize::init();
