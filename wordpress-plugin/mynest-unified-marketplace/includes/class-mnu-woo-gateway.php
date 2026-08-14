<?php
/**
 * v3.7.34 — MyNest Marketplace WooCommerce payment gateway.
 *
 * This is a thin wrapper around the existing native-checkout Stripe Connect
 * pipeline in class-mnu-native-checkout.php. It registers itself with
 * WooCommerce as a payment method so the web checkout page has something to
 * offer buyers, but under the hood every card charge, application fee, and
 * per-seller transfer flows through the same code paths the mobile app
 * already uses.
 *
 * Why it exists: WooCommerce's checkout page enumerates active gateways and
 * shows "no payment methods available" if none register themselves. MNU's
 * native checkout is REST-only (built for the app), so we needed a WC-facing
 * shell. This class provides that shell without duplicating any Stripe
 * plumbing.
 *
 * Flow:
 *   1. Buyer hits /checkout/ → WooCommerce renders form + our payment method radio.
 *   2. On payment method selected, our JS calls `wc-ajax=mnu_create_intent`
 *      which reuses the native-checkout PaymentIntent creator against the
 *      current WC session (draft order).
 *   3. Stripe Elements mounts using the platform publishable key from
 *      mnu_native_get_settings(). Buyer enters card, submits.
 *   4. `place_order` submits → process_payment() finalises the order and
 *      returns a redirect to the order-received page.
 *   5. Stripe fires `payment_intent.succeeded` webhook to
 *      /wp-json/nest-native/v1/stripe-webhook → mnu_native_webhook() marks
 *      payment_complete and calls mnu_native_issue_seller_transfers() for
 *      multi-seller carts.
 */

defined( 'ABSPATH' ) || exit;

final class MNU_Woo_Gateway_Loader {

	public static function init(): void {
		// MNU_Plugin::boot() already guards on class_exists('WooCommerce'), so by
		// the time we get here WC is loaded and WC_Payment_Gateway is available.
		// Load the gateway impl synchronously so the class exists when
		// WooCommerce enumerates gateways later in the request.
		if ( class_exists( 'WC_Payment_Gateway' ) ) {
			require_once __DIR__ . '/class-mnu-woo-gateway-impl.php';
		}
		add_filter( 'woocommerce_payment_gateways', array( __CLASS__, 'register_gateway' ) );
		add_action( 'wp_ajax_mnu_gateway_create_intent',        array( __CLASS__, 'ajax_create_intent' ) );
		add_action( 'wp_ajax_nopriv_mnu_gateway_create_intent', array( __CLASS__, 'ajax_create_intent' ) );

		// v3.7.35: expose the gateway to the WooCommerce Blocks (Cart & Checkout Block).
		add_action( 'woocommerce_blocks_payment_method_type_registration', array( __CLASS__, 'register_block_payment_method' ) );
	}

	/**
	 * Register the block-checkout integration when WooCommerce Blocks is loaded.
	 *
	 * @param mixed $registry Instance of PaymentMethodRegistry.
	 */
	public static function register_block_payment_method( $registry ): void {
		if ( ! is_object( $registry ) || ! method_exists( $registry, 'register' ) ) {
			return;
		}
		if ( ! class_exists( 'Automattic\\WooCommerce\\Blocks\\Payments\\Integrations\\AbstractPaymentMethodType' ) ) {
			return;
		}
		require_once __DIR__ . '/class-mnu-blocks-payment-method.php';
		if ( class_exists( 'MNU_Blocks_Payment_Method' ) ) {
			$registry->register( new MNU_Blocks_Payment_Method() );
		}
	}

	public static function register_gateway( array $gateways ): array {
		if ( class_exists( 'MNU_Woo_Gateway' ) ) {
			$gateways[] = 'MNU_Woo_Gateway';
		}
		return $gateways;
	}

	/**
	 * AJAX endpoint that creates (or reuses) a PaymentIntent for the current
	 * checkout session. Called by our Stripe Elements JS before the buyer
	 * submits the form so the Elements can mount with a live client_secret.
	 *
	 * Uses the current cart + posted billing/shipping address to build a draft
	 * WC_Order via the standard WC_Checkout->create_order() flow, then hands
	 * that order to the native-checkout PaymentIntent creator so all Connect
	 * fee/split logic applies uniformly.
	 */
	public static function ajax_create_intent(): void {
		check_ajax_referer( 'mnu_gateway_intent', 'nonce' );
		if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
			wp_send_json_error( array( 'message' => 'Your cart is empty.' ), 400 );
		}
		if ( ! function_exists( 'mnu_native_get_settings' ) || ! function_exists( 'mnu_native_stripe_request' ) ) {
			wp_send_json_error( array( 'message' => 'The marketplace payment engine is not loaded. Try again in a moment.' ), 503 );
		}

		$settings = mnu_native_get_settings();
		if ( empty( $settings['publishable_key'] ) || empty( $settings['secret_key'] ) ) {
			wp_send_json_error( array( 'message' => 'Payments are not configured. Please contact support.' ), 503 );
		}

		// Rebuild WC session totals so cart is fresh (address changes etc.).
		WC()->cart->calculate_totals();

		$user_id  = get_current_user_id();
		$currency = strtolower( get_woocommerce_currency() );
		$amount   = (int) round( (float) WC()->cart->get_total( 'edit' ) * 100 );

		if ( $amount < 50 ) {
			wp_send_json_error( array( 'message' => 'Order total is below the Stripe minimum charge.' ), 400 );
		}

		// Get or create a Stripe Customer id for this buyer (reuses native helper).
		$stripe_customer_id = '';
		if ( $user_id && function_exists( 'mnu_native_get_or_create_customer' ) ) {
			$stripe_customer_id = mnu_native_get_or_create_customer( $user_id, $settings );
			if ( is_wp_error( $stripe_customer_id ) ) {
				$stripe_customer_id = '';
			}
		}

		// Reuse an in-flight PaymentIntent stored on the WC session if it
		// still matches the current cart total + currency AND is still in a
		// non-terminal state on Stripe's side. Otherwise create a new one.
		$session      = WC()->session;
		$stored_intent = is_object( $session ) ? (array) $session->get( 'mnu_gateway_intent' ) : array();
		if ( ! empty( $stored_intent['id'] )
			&& (int) ( $stored_intent['amount'] ?? 0 ) === $amount
			&& (string) ( $stored_intent['currency'] ?? '' ) === $currency
			&& time() - (int) ( $stored_intent['created'] ?? 0 ) < 15 * MINUTE_IN_SECONDS ) {
			// Verify the stored intent is still usable on Stripe's side.
			// If terminal (succeeded/canceled/requires_capture) or lookup
			// fails, fall through and create a fresh intent below.
			$stored_id = (string) $stored_intent['id'];
			$fetched   = function_exists( 'mnu_native_stripe_get' )
				? mnu_native_stripe_get( '/payment_intents/' . rawurlencode( $stored_id ) )
				: new \WP_Error( 'no_get_helper', 'GET helper missing' );
			$reusable  = false;
			if ( ! is_wp_error( $fetched ) && is_array( $fetched ) ) {
				$status = (string) ( $fetched['status'] ?? '' );
				// Statuses that still accept new payment_method attachment.
				$ok = array(
					'requires_payment_method' => true,
					'requires_confirmation'   => true,
					'requires_action'         => true,
				);
				$reusable = isset( $ok[ $status ] );
			}
			if ( $reusable ) {
				wp_send_json_success( array(
					'publishable_key' => (string) $settings['publishable_key'],
					'client_secret'   => (string) $stored_intent['client_secret'],
					'intent_id'       => $stored_id,
				) );
			}
			// Not reusable — clear stored intent AND rotate the idempotency
			// salt so Stripe doesn't return the same terminal intent from its
			// idempotency cache on the next create call.
			if ( is_object( $session ) ) {
				$session->set( 'mnu_gateway_intent', array() );
				$session->set( 'mnu_gateway_idem_salt', substr( wp_hash( uniqid( 'mnu', true ) ), 0, 8 ) );
			}
		}

		$params = array(
			'amount'                             => (string) $amount,
			'currency'                           => $currency,
			'automatic_payment_methods[enabled]' => 'true',
			'description'                        => 'MyNest Marketplace order (checkout draft)',
			'metadata[source]'                   => 'mnu_web_checkout',
			'metadata[user_id]'                  => (string) $user_id,
		);
		if ( $stripe_customer_id ) {
			$params['customer'] = $stripe_customer_id;
		}

		// NOTE: Connect destination-charge params are added at the moment we
		// know a real WC_Order id (in process_payment()). At the AJAX stage we
		// only have a cart, so the intent is created on the platform first
		// and then updated with transfer_data / application_fee before
		// capture. This matches Stripe's supported update flow for intents
		// in the requires_confirmation state.

		// Idempotency key includes a per-session rotating salt so that when
		// a prior intent for the same cart total became terminal (and we
		// cleared it above), Stripe issues a NEW intent instead of returning
		// the cached terminal one from the previous idempotency key.
		$idem_salt = is_object( $session ) ? (string) $session->get( 'mnu_gateway_idem_salt' ) : '';
		if ( ! $idem_salt ) {
			$idem_salt = substr( wp_hash( uniqid( 'mnu', true ) ), 0, 8 );
			if ( is_object( $session ) ) {
				$session->set( 'mnu_gateway_idem_salt', $idem_salt );
			}
		}
		$idem   = 'mnu_web_' . ( $user_id ?: WC()->session->get_customer_id() ) . '_' . substr( md5( (string) $amount . $currency ), 0, 12 ) . '_' . $idem_salt;
		$intent = mnu_native_stripe_request( '/payment_intents', $params, $idem );
		if ( is_wp_error( $intent ) ) {
			wp_send_json_error( array( 'message' => $intent->get_error_message() ), 502 );
		}
		if ( empty( $intent['client_secret'] ) ) {
			wp_send_json_error( array( 'message' => 'Stripe did not return a client secret.' ), 502 );
		}

		if ( is_object( $session ) ) {
			$session->set( 'mnu_gateway_intent', array(
				'id'            => (string) $intent['id'],
				'client_secret' => (string) $intent['client_secret'],
				'amount'        => $amount,
				'currency'      => $currency,
				'created'       => time(),
			) );
		}

		wp_send_json_success( array(
			'publishable_key' => (string) $settings['publishable_key'],
			'client_secret'   => (string) $intent['client_secret'],
			'intent_id'       => (string) $intent['id'],
		) );
	}
}
