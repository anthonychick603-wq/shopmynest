<?php
/**
 * v3.7.35.5 — MNU_Woo_Gateway (WC_Payment_Gateway subclass).
 *
 * Loaded lazily by MNU_Woo_Gateway_Loader once WooCommerce is available.
 *
 * SERVER-FIRST payment flow (v3.7.35.5):
 *   1. Shopper clicks Place Order in Block or Classic checkout.
 *   2. WooCommerce creates the WC_Order via WC_Checkout::create_order().
 *   3. process_payment() runs on the SERVER — creates a fresh PaymentIntent
 *      that already carries wc_order_id metadata and Connect destination
 *      params, then returns:
 *          { result: 'success',
 *            redirect: 'mnu_confirm://<intent_id>|<client_secret>|<order_id>' }
 *   4. The block-checkout JS intercepts the sentinel redirect, calls
 *      stripe.confirmPayment({ elements, clientSecret, redirect:'if_required' })
 *      client-side, then POSTs to /wp-json/mnu/v1/finalize-order which
 *      verifies the intent server-side and completes the order.
 *   5. The classic-checkout JS also handles the sentinel and follows the
 *      same finalize flow.
 *
 * Why: previously the card was confirmed client-side BEFORE process_payment
 * ran on the server, which meant a Store API failure between order create
 * and process_payment (e.g. tax validation) charged the card without ever
 * linking a WC order. Now the intent is only created for a real WC order,
 * and the card is only ever confirmed AFTER the server has that order in
 * hand.
 *
 * @see class-mnu-woo-gateway.php for the loader + AJAX endpoint
 * @see class-mnu-checkout-finalize.php for /mnu/v1/finalize-order
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
	return;
}

final class MNU_Woo_Gateway extends WC_Payment_Gateway {

	public function __construct() {
		$this->id                 = 'mnu_marketplace';
		$this->method_title       = __( 'MyNest Marketplace (Stripe)', 'mynest-unified-marketplace' );
		$this->method_description = __( 'Accept credit and debit cards through the MyNest Marketplace Stripe Connect platform. Application fees and per-seller transfers are handled automatically.', 'mynest-unified-marketplace' );
		$this->has_fields         = true;
		$this->supports           = array( 'products', 'refunds' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = (string) $this->get_option( 'title', __( 'Credit / Debit Card', 'mynest-unified-marketplace' ) );
		$this->description = (string) $this->get_option( 'description', __( 'Pay securely with your card. Your payment is split automatically between MyNest sellers.', 'mynest-unified-marketplace' ) );
		$this->enabled     = (string) $this->get_option( 'enabled', 'yes' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ) );
	}

	public function init_form_fields(): void {
		$this->form_fields = array(
			'enabled'     => array(
				'title'   => __( 'Enable/Disable', 'mynest-unified-marketplace' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable MyNest Marketplace payments', 'mynest-unified-marketplace' ),
				'default' => 'yes',
			),
			'title'       => array(
				'title'       => __( 'Title', 'mynest-unified-marketplace' ),
				'type'        => 'text',
				'description' => __( 'Label shown to buyers at checkout.', 'mynest-unified-marketplace' ),
				'default'     => __( 'Credit / Debit Card', 'mynest-unified-marketplace' ),
			),
			'description' => array(
				'title'       => __( 'Description', 'mynest-unified-marketplace' ),
				'type'        => 'textarea',
				'description' => __( 'Description shown under the label at checkout.', 'mynest-unified-marketplace' ),
				'default'     => __( 'Pay securely with your card. Your payment is split automatically between MyNest sellers.', 'mynest-unified-marketplace' ),
			),
			'keys_notice' => array(
				'title'       => __( 'Platform keys', 'mynest-unified-marketplace' ),
				'type'        => 'title',
				'description' => sprintf(
					/* translators: %s: admin URL */
					__( 'Stripe API keys are managed in <a href="%s">MyNest → Native Checkout</a>. This gateway reuses the same platform account as the mobile app.', 'mynest-unified-marketplace' ),
					esc_url( admin_url( 'admin.php?page=mnu-native-checkout' ) )
				),
			),
		);
	}

	/**
	 * Available at checkout only when platform keys are configured. Prevents
	 * WooCommerce from offering the method on a site whose Stripe integration
	 * hasn't been set up yet.
	 */
	public function is_available(): bool {
		if ( 'yes' !== $this->enabled ) {
			return false;
		}
		if ( ! function_exists( 'mnu_native_get_settings' ) ) {
			return false;
		}
		$settings = mnu_native_get_settings();
		if ( empty( $settings['publishable_key'] ) || empty( $settings['secret_key'] ) ) {
			return false;
		}
		return parent::is_available();
	}

	/**
	 * Renders the payment fields shown on the classic checkout page beneath
	 * the gateway's radio button. This is where Stripe Elements mounts in
	 * classic (shortcode) checkout. Block checkout mounts via
	 * assets/js/blocks-checkout.js instead — this method is not called by
	 * the block renderer.
	 */
	public function payment_fields(): void {
		if ( $this->description ) {
			echo wp_kses_post( wpautop( trim( $this->description ) ) );
		}
		?>
		<div id="mnu-stripe-elements-mount" style="margin-top: .75rem; padding: .75rem; border: 1px solid #d4c9b6; border-radius: 8px; background: #fffdf7;">
			<div id="mnu-stripe-payment-element"><em style="color:#666">Loading secure payment form…</em></div>
			<div id="mnu-stripe-errors" role="alert" style="color:#c0392b; margin-top: .5rem; font-size: .9rem;"></div>
		</div>
		<?php
	}

	public function maybe_enqueue(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() ) {
			return;
		}
		if ( 'yes' !== $this->enabled ) {
			return;
		}
		wp_enqueue_script( 'stripe-js', 'https://js.stripe.com/v3/', array(), null, true );

		$s = function_exists( 'mnu_native_get_settings' ) ? mnu_native_get_settings() : array();
		$handle = 'mnu-gateway-checkout';
		wp_register_script( $handle, '', array( 'stripe-js', 'jquery', 'wc-checkout' ), MNU_VERSION, true );
		wp_enqueue_script( $handle );

		$config = array(
			'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
			'nonce'           => wp_create_nonce( 'mnu_gateway_intent' ),
			'gatewayId'       => $this->id,
			'publishableKey'  => (string) ( $s['publishable_key'] ?? '' ),
			'finalizeUrl'     => esc_url_raw( rest_url( 'mnu/v1/finalize-order' ) ),
			'restNonce'       => wp_create_nonce( 'wp_rest' ),
			'currency'        => strtolower( (string) get_woocommerce_currency() ),
			'checkoutUrl'     => wc_get_checkout_url(),
		);
		wp_add_inline_script( $handle, 'window.MNUGateway = ' . wp_json_encode( $config ) . ';', 'before' );
		wp_add_inline_script( $handle, self::inline_js() );
		wp_add_inline_style( 'woocommerce-general', '#mnu-stripe-elements-mount .StripeElement{background:#fff;padding:.5rem;border-radius:6px;border:1px solid #ecdfcd;}' );
	}

	/**
	 * SERVER-FIRST process_payment:
	 *
	 * Called after WooCommerce has created the WC_Order (both classic
	 * shortcode and Blocks Store API go through here).
	 *
	 * We create a fresh PaymentIntent that carries the WC order id in
	 * metadata + Connect destination params, then return a sentinel
	 * redirect that our client-side JS intercepts to run
	 * stripe.confirmPayment() and finalize via REST.
	 *
	 * If intent creation fails, the WC_Order stays in `pending` and no
	 * card is ever charged — we simply return failure and WC shows the
	 * error to the shopper.
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wc_add_notice( __( 'We could not find your order. Please try again.', 'mynest-unified-marketplace' ), 'error' );
			return array( 'result' => 'failure' );
		}

		if ( ! function_exists( 'mnu_native_get_settings' ) || ! function_exists( 'mnu_native_stripe_request' ) ) {
			wc_add_notice( __( 'The marketplace payment engine is not loaded. Please try again in a moment.', 'mynest-unified-marketplace' ), 'error' );
			return array( 'result' => 'failure' );
		}
		$settings = mnu_native_get_settings();
		if ( empty( $settings['publishable_key'] ) || empty( $settings['secret_key'] ) ) {
			wc_add_notice( __( 'Payments are not configured. Please contact support.', 'mynest-unified-marketplace' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$currency = strtolower( (string) $order->get_currency() );
		$amount   = (int) round( (float) $order->get_total() * 100 );
		if ( $amount < 50 ) {
			wc_add_notice( __( 'Order total is below the Stripe minimum charge.', 'mynest-unified-marketplace' ), 'error' );
			return array( 'result' => 'failure' );
		}

		// Get or create a Stripe Customer id for this buyer.
		$user_id            = (int) $order->get_customer_id();
		$stripe_customer_id = '';
		if ( $user_id && function_exists( 'mnu_native_get_or_create_customer' ) ) {
			$cust = mnu_native_get_or_create_customer( $user_id, $settings );
			if ( ! is_wp_error( $cust ) ) {
				$stripe_customer_id = (string) $cust;
			}
		}

		// Build PaymentIntent params. wc_order_id in metadata is CRITICAL —
		// the finalize endpoint uses it to detect intent/order mismatches.
		$params = array(
			'amount'                             => (string) $amount,
			'currency'                           => $currency,
			'automatic_payment_methods[enabled]' => 'true',
			'description'                        => 'MyNest order #' . $order->get_order_number(),
			'metadata[source]'                   => 'mnu_web_checkout',
			'metadata[user_id]'                  => (string) $user_id,
			'metadata[wc_order_id]'              => (string) $order->get_id(),
			'metadata[order_number]'             => (string) $order->get_order_number(),
		);
		if ( $stripe_customer_id ) {
			$params['customer'] = $stripe_customer_id;
		}
		if ( function_exists( 'mnu_native_connect_intent_params' ) ) {
			$params = array_merge( $params, mnu_native_connect_intent_params( $order ) );
		}

		// v3.13.28 — Stamp the v3.8 platform-charge model BEFORE the PaymentIntent
		// is created. Without this stamp, TNM_Ledger::create_seller_transfers()
		// will fire a legacy /transfers call for any seller still carrying a
		// legacy Connect account on their profile. That would double-pay them
		// (Stripe transfer + Bluevine ACH). Sellers are OFF Stripe entirely; this
		// stamp is what the ledger checks to keep them off.
		if ( function_exists( 'mnu_native_stamp_v380_intent_meta' ) ) {
			mnu_native_stamp_v380_intent_meta( $order );
		}

		// Idempotency key is derived from order id + amount so retries for the
		// same order reuse the same intent. If the amount changes (e.g. shopper
		// updates cart and comes back) Stripe issues a fresh intent.
		$idem = 'mnu_order_' . $order->get_id() . '_' . $amount . '_' . $currency;

		$intent = mnu_native_stripe_request( '/payment_intents', $params, $idem );
		if ( is_wp_error( $intent ) ) {
			$err = $intent->get_error_message();
			$order->add_order_note( 'Stripe intent creation failed: ' . $err );
			wc_add_notice( __( 'We could not initialise your payment. Please try again.', 'mynest-unified-marketplace' ) . ' (' . esc_html( $err ) . ')', 'error' );
			return array( 'result' => 'failure' );
		}
		if ( empty( $intent['client_secret'] ) || empty( $intent['id'] ) ) {
			$order->add_order_note( 'Stripe intent creation returned no client secret.' );
			wc_add_notice( __( 'Payment could not be initialised. Please try again.', 'mynest-unified-marketplace' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$intent_id     = (string) $intent['id'];
		$client_secret = (string) $intent['client_secret'];

		// Attach intent id to the order BEFORE returning to the client. The
		// finalize endpoint uses this as its trust anchor.
		$order->set_payment_method( $this->id );
		$order->set_payment_method_title( $this->title );
		$order->update_meta_data( '_thenest_stripe_payment_intent', $intent_id );
		$order->update_meta_data( '_thenest_stripe_client_secret', $client_secret );
		$order->update_meta_data( '_thenest_stripe_pending_at', current_time( 'mysql', true ) );
		$order->add_order_note( sprintf(
			'Stripe PaymentIntent created (%s). Awaiting client-side confirmation.',
			$intent_id
		) );
		$order->save();

		// Clear any stale session state left over from earlier flows.
		if ( is_object( WC()->session ) ) {
			WC()->session->set( 'mnu_gateway_intent', null );
		}

		// The sentinel redirect. Our block/classic JS parses this and runs
		// stripe.confirmPayment() with the client_secret. On success it
		// POSTs /wp-json/mnu/v1/finalize-order which returns the real
		// thankyou-page redirect.
		$nonce    = wp_create_nonce( 'mnu_finalize_order_' . $order->get_id() );
		$sentinel = sprintf(
			'mnu_confirm://%s|%s|%d|%s',
			rawurlencode( $intent_id ),
			rawurlencode( $client_secret ),
			(int) $order->get_id(),
			rawurlencode( $nonce )
		);

		// WooCommerce Blocks Store API strips non-http(s) redirect strings
		// before exposing them to onCheckoutSuccess. To keep the sentinel
		// visible to the client we ALSO include the intent details under
		// payment_details, which Blocks passes through untouched via
		// ctx.processingResponse.paymentDetails. The classic shortcode
		// checkout continues to use the `redirect` key.
		return array(
			'result'          => 'success',
			'redirect'        => $sentinel,
			'payment_details' => array(
				'mnu_confirm'       => '1',
				'mnu_intent_id'     => $intent_id,
				'mnu_client_secret' => $client_secret,
				'mnu_order_id'      => (string) $order->get_id(),
				'mnu_nonce'         => $nonce,
			),
		);
	}

	/**
	 * Handle admin-initiated refunds. For single-seller destination charges
	 * Stripe automatically reverses the transfer. For multi-seller carts we
	 * recorded transfer ids in _mnu_seller_transfers and must reverse them
	 * proportionally.
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new WP_Error( 'mnu_refund_no_order', __( 'Order not found.', 'mynest-unified-marketplace' ) );
		}
		$intent_id = (string) $order->get_meta( '_thenest_stripe_payment_intent', true );
		if ( '' === $intent_id ) {
			return new WP_Error( 'mnu_refund_no_intent', __( 'This order has no Stripe payment intent.', 'mynest-unified-marketplace' ) );
		}
		$amount = ( null === $amount ) ? $order->get_total() : $amount;
		$cents  = (int) round( (float) $amount * 100 );
		if ( $cents <= 0 ) {
			return new WP_Error( 'mnu_refund_zero', __( 'Refund amount must be greater than zero.', 'mynest-unified-marketplace' ) );
		}

		// Detect whether the charge used destination-charge routing (single
		// seller Connect) or Separate Charges and Transfers (multi-seller).
		// SCT charges reject reverse_transfer / refund_application_fee — we
		// must reverse each recorded seller transfer explicitly.
		$transfers_raw = $order->get_meta( '_mnu_seller_transfers', true );
		$transfers     = is_string( $transfers_raw ) ? json_decode( $transfers_raw, true ) : $transfers_raw;
		$has_sct       = is_array( $transfers ) && ! empty( $transfers );

		$params = array(
			'payment_intent'        => $intent_id,
			'amount'                => (string) $cents,
			'metadata[wc_order_id]' => (string) $order->get_id(),
		);
		if ( ! $has_sct ) {
			$params['reverse_transfer']       = 'true';
			$params['refund_application_fee'] = 'true';
		}
		if ( $reason ) {
			$params['metadata[reason]'] = substr( sanitize_text_field( $reason ), 0, 250 );
		}
		// Idempotency key includes a wall-clock component so a retry after a
		// prior failed refund attempt (e.g. old code sending reverse_transfer
		// on an SCT charge) is not blocked by Stripe's replay protection.
		$idem_key = 'mnu_refund_' . $order->get_id() . '_' . $cents . '_' . time();
		$refund   = mnu_native_stripe_request( '/refunds', $params, $idem_key );
		if ( is_wp_error( $refund ) ) {
			return $refund;
		}
		$refund_id = (string) ( $refund['id'] ?? '' );

		if ( $has_sct ) {
			$order_total_cents = (int) round( (float) $order->get_total() * 100 );
			$is_full           = $order_total_cents > 0 && $cents >= $order_total_cents;
			$updated           = $transfers;
			foreach ( $transfers as $seller_id => $t ) {
				if ( ! is_array( $t ) || 'sent' !== ( $t['status'] ?? '' ) ) {
					continue;
				}
				$tr_id     = (string) ( $t['transfer_id'] ?? '' );
				$net_cents = (int) ( $t['net_cents'] ?? 0 );
				if ( '' === $tr_id || $net_cents <= 0 ) {
					continue;
				}
				$reverse_cents = $is_full
					? $net_cents
					: (int) round( $net_cents * ( $cents / max( 1, $order_total_cents ) ) );
				$reverse_cents = max( 1, min( $net_cents, $reverse_cents ) );
				$rev = mnu_native_stripe_request(
					'/transfers/' . rawurlencode( $tr_id ) . '/reversals',
					array(
						'amount'                => (string) $reverse_cents,
						'metadata[wc_order_id]' => (string) $order->get_id(),
						'metadata[refund_id]'   => $refund_id,
					),
					'mnu_rev_' . $order->get_id() . '_' . $tr_id . '_' . $reverse_cents
				);
				if ( is_wp_error( $rev ) ) {
					$order->add_order_note( sprintf( 'Transfer reversal FAILED for seller %s (%s): %s', (string) $seller_id, $tr_id, $rev->get_error_message() ) );
					continue;
				}
				$updated[ $seller_id ]['reversal_id']    = (string) ( $rev['id'] ?? '' );
				$updated[ $seller_id ]['reversed_cents'] = ( (int) ( $updated[ $seller_id ]['reversed_cents'] ?? 0 ) ) + $reverse_cents;
				if ( ( (int) $updated[ $seller_id ]['reversed_cents'] ) >= $net_cents ) {
					$updated[ $seller_id ]['status'] = 'reversed';
				}
				$order->add_order_note( sprintf( 'Reversed %d cents from transfer %s (seller %s) for refund %s.', $reverse_cents, $tr_id, (string) $seller_id, $refund_id ) );
			}
			$order->update_meta_data( '_mnu_seller_transfers', wp_json_encode( $updated ) );
			$order->save();
		}

		$order->add_order_note( sprintf( 'Stripe refund %s issued for $%.2f.', $refund_id, $cents / 100 ) );
		return true;
	}

	/**
	 * Inline Stripe Elements JS for the CLASSIC (shortcode) checkout. Mounts
	 * a deferred-mode Payment Element (no intent created yet), then on
	 * `checkout_place_order_mnu_marketplace` allows the form to submit so
	 * process_payment() runs on the server. process_payment() returns a
	 * mnu_confirm:// sentinel; the WC AJAX submit-order response handler
	 * below intercepts that sentinel to run stripe.confirmPayment() and
	 * then finalize via REST.
	 */
	private static function inline_js(): string {
		return <<<'JS'
		(function($){
		if (typeof MNUGateway === 'undefined') { return; }
		var stripe = null, elements = null, paymentElement = null, mounted = false;

		function log(err){ var el = document.getElementById('mnu-stripe-errors'); if (el) { el.textContent = err || ''; } }

		function ensureStripe(){
			if (stripe) return stripe;
			if (typeof Stripe !== 'function') return null;
			if (!MNUGateway.publishableKey) { log('Stripe is not configured on this site.'); return null; }
			stripe = Stripe(MNUGateway.publishableKey);
			return stripe;
		}

		function mountElements(){
			if (mounted) return;
			if (!ensureStripe()) return;
			var mountEl = document.getElementById('mnu-stripe-payment-element');
			if (!mountEl) return;
			var cartTotal = 0;
			try {
				var $ct = $('.order-total .amount, .order-total .woocommerce-Price-amount').first();
				if ($ct.length) {
					cartTotal = Math.round(parseFloat($ct.text().replace(/[^0-9.]/g,'')) * 100);
				}
			} catch(e) {}
			// Deferred-mode elements: no client_secret yet. The intent is
			// only created server-side by process_payment().
			elements = stripe.elements({
				mode: 'payment',
				amount: cartTotal > 0 ? cartTotal : 100,
				currency: MNUGateway.currency || 'usd',
				appearance: { theme: 'flat' },
				paymentMethodCreation: 'manual'
			});
			paymentElement = elements.create('payment', { layout: 'tabs' });
			paymentElement.on('loaderror', function(ev){
				var msg = (ev && ev.error && ev.error.message) || 'Card entry form failed to load.';
				log(msg);
			});
			paymentElement.mount('#mnu-stripe-payment-element');
			mounted = true;
		}

		function maybeMount(){
			var selected = $('input[name="payment_method"]:checked').val();
			if (selected === MNUGateway.gatewayId) mountElements();
		}
		$(document).on('updated_checkout', function(){ mounted = false; maybeMount(); });
		$(document).on('change', 'input[name="payment_method"]', maybeMount);
		$(document).ready(maybeMount);

		// Before the form submits: validate the Payment Element locally so
		// the shopper sees inline card errors. If validation passes, allow
		// the submit to proceed; the server will then create the intent.
		$('form.checkout').on('checkout_place_order_' + MNUGateway.gatewayId, function(){
			if (!elements) { log('Payment form is not ready. Please wait a moment.'); return false; }
			return true;
		});

		// Intercept the WC AJAX submit-order response. WooCommerce fires
		// checkout_place_order with an AJAX POST; on success WC redirects
		// window.location to result.redirect. We hijack that to catch our
		// mnu_confirm:// sentinel.
		$(document.body).on('checkout_error', function(){ log('Checkout error. See the notice above.'); });

		// Patch $.fn.redirect isn't a thing; instead we hook the AJAX
		// completion by listening to updated_checkout is not enough. WC 8+
		// uses form.checkout submit -> jQuery.ajax; we can intercept the
		// response via a global ajaxSuccess filter targeting the submit
		// action.
		$(document).ajaxSuccess(function(evt, xhr, settings){
			if (!settings || !settings.data) return;
			var isSubmitOrder = (typeof settings.data === 'string' && settings.data.indexOf('wc-ajax=checkout') >= 0)
				|| (typeof settings.url === 'string' && settings.url.indexOf('wc-ajax=checkout') >= 0);
			if (!isSubmitOrder) return;
			var resp = xhr.responseJSON;
			if (!resp || resp.result !== 'success' || !resp.redirect) return;
			if (resp.redirect.indexOf('mnu_confirm://') !== 0) return;
			// Intercept: prevent WC's default window.location redirect.
			// WC has already scheduled the redirect via `window.location =
			// result.redirect`. We race by immediately triggering our own
			// flow and hope to beat it — safer path: parse and take over.
			try { history.pushState({}, '', window.location.pathname); } catch(e) {}
			handleSentinel(resp.redirect);
		});

		function handleSentinel(sentinel){
			var body = sentinel.slice('mnu_confirm://'.length);
			var parts = body.split('|');
			var intentId     = decodeURIComponent(parts[0] || '');
			var clientSecret = decodeURIComponent(parts[1] || '');
			var orderId      = parseInt(parts[2] || '0', 10);
			var nonce        = decodeURIComponent(parts[3] || '');

			if (!intentId || !clientSecret || !orderId) {
				log('Could not read payment challenge from server.');
				return;
			}

			var $form = $('form.checkout');
			$form.addClass('processing').block({ message: null, overlayCSS: { background: '#fff', opacity: 0.6 } });

			ensureStripe();
			if (!stripe || !elements) { log('Payment form is not ready.'); $form.removeClass('processing').unblock(); return; }

			elements.submit().then(function(subRes){
				if (subRes && subRes.error) throw new Error(subRes.error.message || 'Card details are incomplete.');
				return stripe.confirmPayment({
					elements: elements,
					clientSecret: clientSecret,
					confirmParams: { return_url: MNUGateway.checkoutUrl + (MNUGateway.checkoutUrl.indexOf('?')>=0?'&':'?') + 'mnu_pi_return=1&order=' + orderId },
					redirect: 'if_required'
				});
			}).then(function(result){
				if (!result) return; // redirect flow took over
				if (result.error) throw new Error(result.error.message || 'Card was declined.');
				var pi = result.paymentIntent;
				var ok = ['succeeded','processing','requires_capture'];
				if (!pi || ok.indexOf(pi.status) < 0) throw new Error('Payment status: ' + (pi && pi.status));
				return finalize(orderId, intentId, nonce);
			}).then(function(fin){
				if (!fin) return;
				if (fin.result === 'success' && fin.redirect) {
					window.location = fin.redirect;
				} else {
					throw new Error(fin.message || 'Order could not be finalised.');
				}
			}).catch(function(err){
				log(err.message || 'Payment failed.');
				$form.removeClass('processing').unblock();
			});
		}

		function finalize(orderId, intentId, nonce){
			return fetch(MNUGateway.finalizeUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': MNUGateway.restNonce },
				body: JSON.stringify({ order_id: orderId, intent_id: intentId, nonce: nonce })
			}).then(function(r){ return r.json(); });
		}

		})(jQuery);
JS;
	}
}
