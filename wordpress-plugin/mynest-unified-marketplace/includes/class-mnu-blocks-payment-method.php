<?php
/**
 * v3.7.35.5 — MyNest Marketplace payment method registration for
 * WooCommerce Blocks (Cart & Checkout Block).
 *
 * SERVER-FIRST flow (v3.7.35.5):
 *   1. The block payment method mounts a deferred-mode Stripe Payment
 *      Element (no client_secret at mount) so the card form appears
 *      without any server round-trip.
 *   2. On Place Order, WC Blocks calls its Store API /wc/store/v1/checkout
 *      which creates the WC_Order and invokes MNU_Woo_Gateway::process_payment.
 *   3. process_payment creates a fresh PaymentIntent for THIS order and
 *      returns a mnu_confirm:// sentinel redirect.
 *   4. blocks-checkout.js intercepts the sentinel, runs
 *      stripe.confirmPayment({ elements, clientSecret, redirect: 'if_required' }),
 *      then POSTs /wp-json/mnu/v1/finalize-order which verifies the
 *      intent status directly with Stripe and completes the order.
 *
 * The `name` here MUST match the classic gateway id ("mnu_marketplace")
 * so both checkout renderers submit to the same server-side
 * process_payment().
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Automattic\\WooCommerce\\Blocks\\Payments\\Integrations\\AbstractPaymentMethodType' ) ) {
	return;
}

final class MNU_Blocks_Payment_Method extends \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType {

	protected $name = 'mnu_marketplace';

	/** @var array|null Cached settings from wp_options. */
	private ?array $gateway_settings = null;

	public function initialize(): void {
		$this->gateway_settings = (array) get_option( 'woocommerce_mnu_marketplace_settings', array() );
	}

	public function is_active(): bool {
		// Match the classic gateway default: enabled unless explicitly set to
		// 'no'. If the settings row is empty (never saved through the admin
		// form) treat it as enabled.
		$settings = is_array( $this->gateway_settings ) ? $this->gateway_settings : array();
		$enabled  = $settings['enabled'] ?? 'yes';
		return 'yes' === $enabled;
	}

	/**
	 * Register the block-checkout JS.
	 */
	public function get_payment_method_script_handles(): array {
		$handle = 'mnu-blocks-integration';
		$src    = MNU_URL . 'assets/js/blocks-checkout.js';
		wp_register_script(
			$handle,
			$src,
			array(
				'wc-blocks-registry',
				'wc-settings',
				'wp-element',
				'wp-html-entities',
				'wp-i18n',
			),
			MNU_VERSION,
			true
		);
		wp_register_script( 'stripe-js', 'https://js.stripe.com/v3/', array(), null, true );
		wp_enqueue_script( 'stripe-js' );
		return array( $handle );
	}

	/**
	 * Data exposed to the front-end block via
	 * wc.wcSettings.getSetting( 'mnu_marketplace_data' ).
	 */
	public function get_payment_method_data(): array {
		$s = function_exists( 'mnu_native_get_settings' ) ? mnu_native_get_settings() : array();
		return array(
			'title'          => (string) ( $this->gateway_settings['title'] ?? 'Credit / Debit Card' ),
			'description'    => (string) ( $this->gateway_settings['description'] ?? 'Pay securely with your card. Your payment is split automatically between MyNest sellers.' ),
			'publishableKey' => (string) ( $s['publishable_key'] ?? '' ),
			'currency'       => strtolower( (string) get_woocommerce_currency() ),
			'finalizeUrl'    => esc_url_raw( rest_url( 'mnu/v1/finalize-order' ) ),
			'restNonce'      => wp_create_nonce( 'wp_rest' ),
			'checkoutUrl'    => wc_get_checkout_url(),
			'supports'       => array( 'products' ),
		);
	}
}
