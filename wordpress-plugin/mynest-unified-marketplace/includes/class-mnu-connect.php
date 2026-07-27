<?php
/**
 * Stripe Connect Standard onboarding for sellers.
 *
 * The platform's Stripe Connect profile uses the "Platform" business model, so
 * sellers are onboarded as Standard connected accounts: they own their Stripe
 * account, manage their own capabilities/dashboard, and bear their own loss
 * liability.
 *
 * Funds flow uses the "separate charges and transfers" pattern (compatible with
 * Standard accounts): the platform charges the buyer in full at checkout, then
 * transfers each seller's net earnings to their connected account when the
 * ledger row becomes available (see TNM_Ledger::create_seller_transfers) — which
 * is why the account still requests the `transfers` capability. This class owns
 * onboarding, status, and the dashboard link, plus the cached account state used
 * to gate selling and checkout.
 */

defined( 'ABSPATH' ) || exit;

final class MNU_Connect {

	const NS            = 'nest-connect/v1';
	const META_ACCOUNT  = 'tnm_stripe_account_id';
	const META_CHARGES  = 'tnm_stripe_charges_enabled';
	const META_PAYOUTS  = 'tnm_stripe_payouts_enabled';
	const META_DETAILS  = 'tnm_stripe_details_submitted';
	const IDEMPOTENCY_TRANSIENT = 'tnm_connect_acct_idem_';

	// HTTPS bridge path used to hand Stripe's Account Links return/refresh
	// redirects back to the app's custom scheme. Stripe rejects `thenest://`
	// URLs outright, so we send it this same-site HTTPS page which then bounces
	// the browser to the embedded `thenest://` deep link.
	const BRIDGE_PATH = '/mnu-connect-bridge/';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		// Served as a plain, unauthenticated browser page. Handled on `init`
		// (matching the plugin's REQUEST_URI convention in MNU_Compat) so it
		// needs no rewrite rule and therefore no rewrite-rule flush.
		add_action( 'init', array( __CLASS__, 'maybe_render_bridge' ) );
	}

	public static function register_routes(): void {
		register_rest_route(
			self::NS,
			'/onboard-link',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'onboard_link' ),
				'permission_callback' => array( __CLASS__, 'seller' ),
			)
		);
		register_rest_route(
			self::NS,
			'/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'status' ),
				'permission_callback' => array( __CLASS__, 'seller' ),
			)
		);
		register_rest_route(
			self::NS,
			'/dashboard-link',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'dashboard_link' ),
				'permission_callback' => array( __CLASS__, 'seller' ),
			)
		);
	}

	/**
	 * Mirrors TNM_REST::seller() so existing seller bearer tokens work unchanged.
	 */
	public static function seller(): bool|WP_Error {
		if ( ! get_current_user_id() ) {
			return tnm_json_error( 'rest_login_required', 'Authentication is required.', 401 );
		}
		return tnm_is_seller() || tnm_is_admin_or_manager() ? true : tnm_json_error( 'rest_seller_required', 'An approved seller account is required.', 403 );
	}

	public static function account_id( int $user_id ): string {
		return (string) get_user_meta( $user_id, self::META_ACCOUNT, true );
	}

	public static function seller_can_sell( int $user_id ): bool {
		return '1' === (string) get_user_meta( $user_id, self::META_PAYOUTS, true );
	}

	/**
	 * Return the cached Connect state for a seller without hitting Stripe.
	 *
	 * @return array{connected:bool,charges_enabled:bool,payouts_enabled:bool,details_submitted:bool}
	 */
	public static function cached_status( int $user_id ): array {
		return array(
			'connected'         => '' !== self::account_id( $user_id ),
			'charges_enabled'   => '1' === (string) get_user_meta( $user_id, self::META_CHARGES, true ),
			'payouts_enabled'   => '1' === (string) get_user_meta( $user_id, self::META_PAYOUTS, true ),
			'details_submitted' => '1' === (string) get_user_meta( $user_id, self::META_DETAILS, true ),
		);
	}

	/**
	 * Persist the Connect booleans from a Stripe account object into user meta.
	 *
	 * @param array<string,mixed> $account
	 * @return array{connected:bool,charges_enabled:bool,payouts_enabled:bool,details_submitted:bool}
	 */
	public static function refresh_status_cache( int $user_id, array $account ): array {
		$charges  = ! empty( $account['charges_enabled'] );
		$payouts  = ! empty( $account['payouts_enabled'] );
		$details  = ! empty( $account['details_submitted'] );
		update_user_meta( $user_id, self::META_CHARGES, $charges ? '1' : '0' );
		update_user_meta( $user_id, self::META_PAYOUTS, $payouts ? '1' : '0' );
		update_user_meta( $user_id, self::META_DETAILS, $details ? '1' : '0' );
		return array(
			'connected'         => true,
			'charges_enabled'   => $charges,
			'payouts_enabled'   => $payouts,
			'details_submitted' => $details,
		);
	}

	/**
	 * Return the Stripe idempotency key for this user's account-creation attempt.
	 *
	 * The key is stored in a short-lived (60s) per-user transient: a rapid
	 * double-tap of the connect button reuses the same key and collapses onto one
	 * Stripe request, but the key expires quickly so a later retry never collides.
	 * Because it is not a permanent per-user constant, a first attempt that errored
	 * (transient network issue, validation error, since-fixed bug) no longer locks
	 * the user out until Stripe's ~24h idempotency cache expires.
	 */
	private static function account_idempotency_key( int $user_id ): string {
		$key = get_transient( self::IDEMPOTENCY_TRANSIENT . $user_id );
		if ( ! is_string( $key ) || '' === $key ) {
			$key = 'tnm_connect_acct_' . $user_id . '_' . wp_generate_password( 16, false );
			set_transient( self::IDEMPOTENCY_TRANSIENT . $user_id, $key, 60 );
		}
		return $key;
	}

	public static function onboard_link( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( ! function_exists( 'mnu_native_stripe_request' ) ) {
			return tnm_json_error( 'stripe_unavailable', 'Stripe is not available on this site.', 503 );
		}
		$user_id    = get_current_user_id();
		// The app returns to a custom deep-link scheme (thenest://), which is not
		// in WordPress's default allowed protocols — esc_url_raw() would strip it
		// to an empty string and make valid URLs look missing. Allow the app
		// scheme (alongside the standard web protocols) when sanitizing.
		$allowed_protocols = array_merge( wp_allowed_protocols(), array( 'thenest' ) );
		$return_url  = esc_url_raw( (string) $request->get_param( 'return_url' ), $allowed_protocols );
		$refresh_url = esc_url_raw( (string) $request->get_param( 'refresh_url' ), $allowed_protocols );
		if ( '' === $return_url || '' === $refresh_url ) {
			return tnm_json_error( 'missing_urls', 'return_url and refresh_url are both required.', 422 );
		}

		$account_id = self::account_id( $user_id );
		if ( '' === $account_id ) {
			$user   = get_userdata( $user_id );
			$params = array(
				// Standard accounts run under the full/stripe_dashboard controller
				// type, and Stripe requires `card_payments` and `transfers` to be
				// requested together for such accounts — you cannot request `transfers`
				// alone. We still use separate charges and transfers (the platform, not
				// the seller, actually charges the buyer's card on the platform
				// account); requesting `card_payments` here does not change which
				// account processes the charge, it only satisfies the Stripe Connect
				// account-capability requirement so the connected account can onboard.
				'type'                                    => 'standard',
				// ShopMyNest sellers are homemade makers / sole proprietors, not
				// registered companies. Prefilling `business_type=individual` tells
				// Stripe Connect Onboarding to skip the "Individual vs Company"
				// selection step and, crucially, never prompt the seller for an EIN
				// (which they don't have). Stripe collects personal name/DOB/address
				// and SSN last-4 instead, which every US individual seller can
				// provide. Sellers who later cross IRS 1099-K reporting thresholds
				// will still be asked for their full SSN by Stripe at that point;
				// that is federal tax-reporting law, not a Connect setting.
				'business_type'                           => 'individual',
				'metadata[wp_user_id]'                    => (string) $user_id,
				'capabilities[card_payments][requested]'  => 'true',
				'capabilities[transfers][requested]'      => 'true',
			);
			if ( $user && is_email( $user->user_email ) ) {
				$params['email'] = $user->user_email;
			}
			$account = mnu_native_stripe_request( '/accounts', $params, self::account_idempotency_key( $user_id ) );
			if ( is_wp_error( $account ) ) {
				// A definitive failure means the parameters Stripe cached against
				// this key are stale (e.g. a since-fixed bug). Drop the short-lived
				// key so the very next retry is a fresh request instead of colliding
				// with the failed one until Stripe's ~24h cache expires.
				delete_transient( self::IDEMPOTENCY_TRANSIENT . $user_id );
				return $account;
			}
			// Successful creation is guarded by the stored account id above, so the
			// key has served its purpose; drop it rather than leave it lingering.
			delete_transient( self::IDEMPOTENCY_TRANSIENT . $user_id );
			$account_id = sanitize_text_field( (string) ( $account['id'] ?? '' ) );
			if ( '' === $account_id ) {
				return tnm_json_error( 'account_create_failed', 'Stripe did not return a Connect account id.', 502 );
			}
			update_user_meta( $user_id, self::META_ACCOUNT, $account_id );
		}

		// Stripe's Account Links API only accepts HTTP/HTTPS (or platform
		// app/universal links) for return_url/refresh_url — the app's raw
		// `thenest://` scheme is rejected with "Not a valid URL". So we hand
		// Stripe same-site HTTPS bridge URLs that embed the original deep link,
		// and self::maybe_render_bridge() bounces the browser back to the app.
		$return_bridge  = home_url( self::BRIDGE_PATH . '?redirect=' . rawurlencode( $return_url ) );
		$refresh_bridge = home_url( self::BRIDGE_PATH . '?redirect=' . rawurlencode( $refresh_url ) );
		$link = mnu_native_stripe_request(
			'/account_links',
			array(
				'account'     => $account_id,
				'return_url'  => $return_bridge,
				'refresh_url' => $refresh_bridge,
				'type'        => 'account_onboarding',
			)
		);
		if ( is_wp_error( $link ) ) {
			return $link;
		}
		$url = esc_url_raw( (string) ( $link['url'] ?? '' ) );
		if ( '' === $url ) {
			return tnm_json_error( 'account_link_failed', 'Stripe did not return an onboarding link.', 502 );
		}
		return rest_ensure_response( array( 'url' => $url ) );
	}

	/**
	 * Serve the HTTPS -> app-scheme bridge page when the current request targets
	 * self::BRIDGE_PATH. Runs on `init`, before the main query/404 handling, so
	 * no rewrite rule (and thus no rewrite flush) is required, and it works on
	 * any permalink setting. Requires no auth, session, nonce, or REST token —
	 * the visitor is a fresh browser returning from Stripe onboarding.
	 */
	public static function maybe_render_bridge(): void {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : '';
		if ( '' === $uri ) {
			return;
		}
		$bridge_path = (string) wp_parse_url( home_url( self::BRIDGE_PATH ), PHP_URL_PATH );
		if ( untrailingslashit( $uri ) !== untrailingslashit( $bridge_path ) ) {
			return;
		}

		// Reuse the onboard_link() allow-list approach, then hard-restrict to the
		// app's own scheme so this can never become an open redirect to http(s).
		$allowed_protocols = array_merge( wp_allowed_protocols(), array( 'thenest' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public browser redirect bridge; target is validated below, not a state change.
		$raw      = isset( $_GET['redirect'] ) ? (string) wp_unslash( $_GET['redirect'] ) : '';
		$redirect = esc_url_raw( $raw, $allowed_protocols );
		$scheme   = strtolower( (string) wp_parse_url( $redirect, PHP_URL_SCHEME ) );
		$valid    = ( '' !== $redirect && 'thenest' === $scheme );

		nocache_headers();
		status_header( $valid ? 200 : 400 );
		header( 'Content-Type: text/html; charset=utf-8' );
		echo self::bridge_html( $valid ? $redirect : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside bridge_html().
		exit;
	}

	/**
	 * Build the bridge HTML. With an empty $redirect it renders a friendly error
	 * page (no redirect attempt); otherwise it auto-redirects to the deep link
	 * and offers a tappable fallback for browsers that block scripted redirects
	 * to custom schemes (some in-app browsers / Custom Tabs).
	 */
	private static function bridge_html( string $redirect ): string {
		$style = 'margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#faf6f2;color:#2b2b2b;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;';
		$card  = 'max-width:22rem;margin:1.5rem;padding:2rem 1.75rem;background:#fff;border-radius:16px;box-shadow:0 8px 30px rgba(0,0,0,.08);text-align:center;';

		if ( '' === $redirect ) {
			return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
				. '<meta name="viewport" content="width=device-width,initial-scale=1">'
				. '<meta name="robots" content="noindex">'
				. '<title>MyNest</title></head>'
				. '<body style="' . esc_attr( $style ) . '"><div style="' . esc_attr( $card ) . '">'
				. '<h1 style="font-size:1.15rem;margin:0 0 .5rem;">Something went wrong</h1>'
				. '<p style="margin:0;color:#6b6b6b;line-height:1.5;">We couldn\'t return you to the MyNest app. Please reopen the app and try again.</p>'
				. '</div></body></html>';
		}

		$href = esc_url( $redirect, array( 'thenest' ) );
		// wp_json_encode yields a safely-quoted, JS-escaped string literal for the
		// custom-scheme URL — correct for embedding directly in the <script>.
		$js  = wp_json_encode( $redirect );
		$btn = 'display:inline-block;margin-top:1.25rem;padding:.75rem 1.5rem;background:#2b2b2b;color:#fff;text-decoration:none;border-radius:999px;font-weight:600;';

		return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width,initial-scale=1">'
			. '<meta name="robots" content="noindex">'
			. '<title>Returning to MyNest…</title></head>'
			. '<body style="' . esc_attr( $style ) . '"><div style="' . esc_attr( $card ) . '">'
			. '<h1 style="font-size:1.15rem;margin:0 0 .5rem;">Redirecting you back to the app…</h1>'
			. '<p style="margin:0;color:#6b6b6b;line-height:1.5;">If nothing happens, tap the button below.</p>'
			. '<a href="' . $href . '" style="' . esc_attr( $btn ) . '">Return to the app</a>'
			. '<script>window.location.replace(' . $js . ');</script>'
			. '</div></body></html>';
	}

	public static function status( WP_REST_Request $request ): WP_REST_Response {
		$user_id    = get_current_user_id();
		$account_id = self::account_id( $user_id );
		if ( '' === $account_id || ! function_exists( 'mnu_native_stripe_get' ) ) {
			return rest_ensure_response(
				array(
					'connected'         => '' !== $account_id,
					'charges_enabled'   => false,
					'payouts_enabled'   => false,
					'details_submitted' => false,
				)
			);
		}
		$account = mnu_native_stripe_get( '/accounts/' . rawurlencode( $account_id ) );
		if ( is_wp_error( $account ) ) {
			// Keep the cached view rather than reporting a hard failure to the app.
			return rest_ensure_response( self::cached_status( $user_id ) );
		}
		return rest_ensure_response( self::refresh_status_cache( $user_id, (array) $account ) );
	}

	public static function dashboard_link( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id    = get_current_user_id();
		$account_id = self::account_id( $user_id );
		if ( '' === $account_id ) {
			return tnm_json_error( 'no_connected_account', 'Connect your bank account with Stripe before opening the Stripe dashboard.', 409 );
		}
		// Standard connected accounts own their Stripe account and sign in at the
		// normal Stripe Dashboard with their own credentials. The platform cannot
		// mint a login link for them: POST /v1/accounts/{id}/login_links is only
		// valid for Express/Custom accounts and returns an error for Standard
		// accounts. So we simply point the seller at dashboard.stripe.com.
		return rest_ensure_response( array( 'url' => 'https://dashboard.stripe.com/' ) );
	}

	/**
	 * Handle an account.updated webhook: refresh the cached booleans for the
	 * seller who owns the given Stripe account object.
	 *
	 * @param array<string,mixed> $account
	 */
	public static function handle_account_updated( array $account ): void {
		$account_id = sanitize_text_field( (string) ( $account['id'] ?? '' ) );
		if ( '' === $account_id ) {
			return;
		}
		$user_id = absint( $account['metadata']['wp_user_id'] ?? 0 );
		if ( $user_id > 0 && hash_equals( self::account_id( $user_id ), $account_id ) ) {
			self::refresh_status_cache( $user_id, $account );
			return;
		}
		$users = get_users(
			array(
				'meta_key'   => self::META_ACCOUNT,
				'meta_value' => $account_id,
				'number'     => 1,
				'fields'     => 'ID',
			)
		);
		if ( ! empty( $users ) ) {
			self::refresh_status_cache( (int) $users[0], $account );
		}
	}
}
