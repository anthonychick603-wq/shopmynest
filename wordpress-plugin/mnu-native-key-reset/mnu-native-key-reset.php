<?php
/**
 * Plugin Name: MyNest Native Checkout Key Reset
 * Description: One-time reset of thenest_native_checkout_settings so the native checkout inherits Stripe keys from the WC Stripe Gateway. Also exposes admin-only endpoints for clearing stale per-user Stripe customer IDs and inspecting the effective native-checkout settings.
 * Version:     1.2.0
 * Author:      MyNest
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_init', function () {
	if ( get_option( 'mnu_native_key_reset_v1_done' ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}
	$stored = (array) get_option( 'thenest_native_checkout_settings', array() );
	$stored['publishable_key'] = '';
	$stored['secret_key']      = '';
	$stored['webhook_secret']  = '';
	update_option( 'thenest_native_checkout_settings', $stored, false );
	update_option( 'mnu_native_key_reset_v1_done', 1 );
}, 20 );

add_action( 'rest_api_init', function () {
	$admin_only = function () { return current_user_can( 'manage_options' ); };

	register_rest_route( 'mnu-native-reset/v1', '/run', array(
		'methods'             => 'POST',
		'permission_callback' => $admin_only,
		'callback'            => function () {
			$stored = (array) get_option( 'thenest_native_checkout_settings', array() );
			$before = array(
				'publishable_key_prefix' => substr( (string) ( $stored['publishable_key'] ?? '' ), 0, 14 ),
				'secret_key_set'         => ! empty( $stored['secret_key'] ),
				'webhook_secret_set'     => ! empty( $stored['webhook_secret'] ),
			);
			$stored['publishable_key'] = '';
			$stored['secret_key']      = '';
			$stored['webhook_secret']  = '';
			update_option( 'thenest_native_checkout_settings', $stored, false );
			update_option( 'mnu_native_key_reset_v1_done', 1 );
			return array( 'ok' => true, 'before' => $before );
		},
	) );

	register_rest_route( 'mnu-native-reset/v1', '/effective-settings', array(
		'methods'             => 'GET',
		'permission_callback' => $admin_only,
		'callback'            => function () {
			$stored  = (array) get_option( 'thenest_native_checkout_settings', array() );
			$wc      = (array) get_option( 'woocommerce_stripe_settings', array() );
			$mode    = 'yes' === ( $wc['testmode'] ?? 'no' ) ? 'test' : 'live';
			$eff_pk  = ! empty( $stored['publishable_key'] ) ? (string) $stored['publishable_key'] : (string) ( 'test' === $mode ? ( $wc['test_publishable_key'] ?? '' ) : ( $wc['publishable_key'] ?? '' ) );
			$eff_sk  = ! empty( $stored['secret_key'] ) ? (string) $stored['secret_key'] : (string) ( 'test' === $mode ? ( $wc['test_secret_key'] ?? '' ) : ( $wc['secret_key'] ?? '' ) );
			$eff_whs = ! empty( $stored['webhook_secret'] ) ? (string) $stored['webhook_secret'] : (string) ( 'test' === $mode ? ( $wc['test_webhook_secret'] ?? '' ) : ( $wc['webhook_secret'] ?? '' ) );
			return array(
				'ok'                        => true,
				'mode'                      => $mode,
				'stored_publishable_prefix' => substr( (string) ( $stored['publishable_key'] ?? '' ), 0, 14 ),
				'stored_secret_set'         => ! empty( $stored['secret_key'] ),
				'stored_webhook_set'        => ! empty( $stored['webhook_secret'] ),
				'effective_publishable_prefix' => substr( $eff_pk, 0, 14 ),
				'effective_secret_set'      => (bool) $eff_sk,
				'effective_secret_prefix'   => substr( $eff_sk, 0, 14 ),
				'effective_webhook_set'     => (bool) $eff_whs,
			);
		},
	) );

	register_rest_route( 'mnu-native-reset/v1', '/clear-user-customers', array(
		'methods'             => 'POST',
		'permission_callback' => $admin_only,
		'args'                => array(
			'user_id' => array(
				'type'     => 'integer',
				'required' => true,
			),
			'mode'    => array(
				'type'    => 'string',
				'enum'    => array( 'test', 'live', 'both' ),
				'default' => 'both',
			),
		),
		'callback'            => function ( WP_REST_Request $req ) {
			$user_id = (int) $req->get_param( 'user_id' );
			$mode    = (string) $req->get_param( 'mode' );
			if ( ! get_userdata( $user_id ) ) {
				return new WP_Error( 'no_such_user', 'User not found.', array( 'status' => 404 ) );
			}
			$out = array();
			$keys = array();
			if ( 'test' === $mode || 'both' === $mode ) $keys[] = '_thenest_stripe_customer_id_test';
			if ( 'live' === $mode || 'both' === $mode ) $keys[] = '_thenest_stripe_customer_id_live';
			foreach ( $keys as $k ) {
				$before        = (string) get_user_meta( $user_id, $k, true );
				$out[ $k ]     = array(
					'before' => $before,
					'cleared'=> $before !== '' ? delete_user_meta( $user_id, $k ) : true,
				);
			}
			return array( 'ok' => true, 'user_id' => $user_id, 'mode' => $mode, 'results' => $out );
		},
	) );

	// Admin-only helper: confirm a Stripe PaymentIntent server-side with a test PaymentMethod token.
	// Uses the effective secret key from the native-checkout inheritance chain (native > WC Gateway).
	// Intended for headless E2E test purchases only; safe because caller must have manage_options.
	register_rest_route( 'mnu-native-reset/v1', '/confirm-intent', array(
		'methods'             => 'POST',
		'permission_callback' => $admin_only,
		'args'                => array(
			'intent_id'         => array( 'type' => 'string', 'required' => true ),
			'payment_method'    => array( 'type' => 'string', 'default' => 'pm_card_visa' ),
			'return_url'        => array( 'type' => 'string', 'default' => '' ),
		),
		'callback'            => function ( WP_REST_Request $req ) {
			$intent_id      = (string) $req->get_param( 'intent_id' );
			$payment_method = (string) $req->get_param( 'payment_method' );
			$return_url     = (string) $req->get_param( 'return_url' );

			$stored = (array) get_option( 'thenest_native_checkout_settings', array() );
			$wc     = (array) get_option( 'woocommerce_stripe_settings', array() );
			$mode   = 'yes' === ( $wc['testmode'] ?? 'no' ) ? 'test' : 'live';
			$sk     = ! empty( $stored['secret_key'] ) ? (string) $stored['secret_key'] : (string) ( 'test' === $mode ? ( $wc['test_secret_key'] ?? '' ) : ( $wc['secret_key'] ?? '' ) );
			if ( '' === $sk ) {
				return new WP_Error( 'no_key', 'No Stripe secret key configured.', array( 'status' => 500 ) );
			}
			if ( 0 !== strpos( $intent_id, 'pi_' ) ) {
				return new WP_Error( 'bad_intent', 'intent_id must start with pi_.', array( 'status' => 400 ) );
			}

			$body = array( 'payment_method' => $payment_method );
			if ( '' !== $return_url ) {
				$body['return_url'] = $return_url;
			}
			$response = wp_remote_post(
				'https://api.stripe.com/v1/payment_intents/' . rawurlencode( $intent_id ) . '/confirm',
				array(
					'timeout' => 30,
					'headers' => array(
						'Authorization' => 'Bearer ' . $sk,
						'Content-Type'  => 'application/x-www-form-urlencoded',
					),
					'body'    => $body,
				)
			);
			if ( is_wp_error( $response ) ) {
				return new WP_Error( 'http_error', $response->get_error_message(), array( 'status' => 502 ) );
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			return array(
				'ok'          => $code >= 200 && $code < 300,
				'http_status' => $code,
				'mode'        => $mode,
				'key_prefix'  => substr( $sk, 0, 14 ),
				'stripe'      => $data,
			);
		},
	) );
} );
