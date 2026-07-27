<?php
/**
 * Native Stripe PaymentSheet helper.
 *
 * Builds the same PaymentSheet payload the marketplace cart checkout returns
 * (see mnu_native_create_intent() in the "MyNest Unified Marketplace" plugin),
 * so trust-suite flows (accepted-offer checkout, boost purchase) can drive the
 * mobile app's native Stripe PaymentSheet instead of a browser checkout URL.
 *
 * The intent is created with metadata[wc_order_id] and the order meta
 * _thenest_stripe_payment_intent set identically to the marketplace, so the
 * marketplace's generic webhook (mnu_native_webhook) settles these orders with
 * no changes. All Stripe config/customer/ephemeral-key logic is reused from the
 * marketplace's global functions (guarded by function_exists) so there is a
 * single source of Stripe credentials and mode (test/live).
 *
 * @package MyNest_Trust_Suite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Create (or reuse) a Stripe PaymentIntent for an existing WC order and return
 * the PaymentSheet payload the mobile app expects.
 *
 * @param WC_Order $order   The order to charge.
 * @param int      $user_id The buyer/seller WP user ID (order customer).
 * @return array|WP_Error {publishable_key, client_secret, payment_intent_id,
 *                         customer_id, ephemeral_key_secret, order_id, amount, currency}
 */
function tnm_trust_create_payment_intent_for_order( WC_Order $order, int $user_id ) {
	$required = array(
		'mnu_native_get_settings',
		'mnu_native_get_or_create_customer',
		'mnu_native_create_ephemeral_key',
		'mnu_native_stripe_request',
		'mnu_native_stripe_get',
		'mnu_native_cents',
	);
	foreach ( $required as $fn ) {
		if ( ! function_exists( $fn ) ) {
			return new WP_Error(
				'tnm_trust_native_pay_unavailable',
				__( 'Native checkout is unavailable. The MyNest Unified Marketplace plugin must be active.', 'nest-trust' ),
				array( 'status' => 503 )
			);
		}
	}

	$settings = mnu_native_get_settings();
	if ( empty( $settings['secret_key'] ) ) {
		return new WP_Error(
			'tnm_trust_stripe_not_configured',
			__( 'Stripe is not configured for native checkout.', 'nest-trust' ),
			array( 'status' => 503 )
		);
	}

	$stripe_customer_id = mnu_native_get_or_create_customer( $user_id, $settings );
	if ( is_wp_error( $stripe_customer_id ) ) {
		return $stripe_customer_id;
	}

	// Reuse an existing intent if this order already has one, otherwise create.
	$intent_id = (string) $order->get_meta( '_thenest_stripe_payment_intent', true );
	if ( $intent_id ) {
		$intent = mnu_native_stripe_get( '/payment_intents/' . rawurlencode( $intent_id ) );
	} else {
		$intent = mnu_native_stripe_request(
			'/payment_intents',
			array(
				'amount'                             => mnu_native_cents( $order->get_total() ),
				'currency'                           => strtolower( $settings['currency'] ),
				'customer'                           => $stripe_customer_id,
				'automatic_payment_methods[enabled]' => 'true',
				'metadata[wc_order_id]'              => $order->get_id(),
				'metadata[customer_id]'              => $user_id,
				'metadata[source]'                   => 'the_nest_native_app',
				'description'                        => 'MyNest order #' . $order->get_order_number(),
			),
			'mynest_order_' . $order->get_id()
		);
	}
	if ( is_wp_error( $intent ) ) {
		return $intent;
	}
	if ( empty( $intent['id'] ) || empty( $intent['client_secret'] ) ) {
		return new WP_Error( 'tnm_trust_stripe_intent_error', __( 'Could not create a payment intent.', 'nest-trust' ), array( 'status' => 502 ) );
	}

	$ephemeral_key = mnu_native_create_ephemeral_key( $stripe_customer_id );
	if ( is_wp_error( $ephemeral_key ) ) {
		return $ephemeral_key;
	}

	$order->update_meta_data( '_thenest_stripe_payment_intent', sanitize_text_field( $intent['id'] ) );
	$order->save();

	return array(
		'publishable_key'      => $settings['publishable_key'],
		'client_secret'        => $intent['client_secret'],
		'payment_intent_id'    => $intent['id'],
		'customer_id'          => $stripe_customer_id,
		'ephemeral_key_secret' => $ephemeral_key['secret'] ?? '',
		'order_id'             => $order->get_id(),
		'amount'               => (float) $order->get_total(),
		'currency'             => strtolower( $settings['currency'] ),
	);
}
