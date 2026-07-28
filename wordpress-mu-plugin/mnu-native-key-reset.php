<?php
/**
 * Plugin Name: MyNest Native Checkout Key Reset
 * Description: One-time reset of thenest_native_checkout_settings so the native checkout inherits Stripe keys from the WC Stripe Gateway instead of holding stale dedicated keys. Self-deactivates after running.
 * Version:     1.0.0
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

	// Preserve non-key settings; clear the fields that override the WC Gateway.
	$stored['publishable_key'] = '';
	$stored['secret_key']      = '';
	$stored['webhook_secret']  = '';

	update_option( 'thenest_native_checkout_settings', $stored, false );
	update_option( 'mnu_native_key_reset_v1_done', 1 );
}, 20 );

// Also expose a REST endpoint so the reset can be triggered without loading wp-admin.
add_action( 'rest_api_init', function () {
	register_rest_route(
		'mnu-native-reset/v1',
		'/run',
		array(
			'methods'             => 'POST',
			'permission_callback' => function () { return current_user_can( 'manage_options' ); },
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
		)
	);
} );
