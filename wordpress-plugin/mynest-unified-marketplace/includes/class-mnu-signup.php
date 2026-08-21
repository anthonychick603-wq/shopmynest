<?php
/**
 * v3.7.122.16 \u2014 two-step signup with email verification.
 *
 * The old /auth/register endpoint created a wp_users row immediately
 * and only emailed a "verify to unlock payouts" link that most buyers
 * never clicked. That let anyone (including Google Play's pre-launch
 * bot at testuser123@example.com) create accounts with fake emails
 * and appear in the user directory forever.
 *
 * This class replaces it with a two-step flow:
 *
 *   1. POST /auth/signup/start  { name, username, email, password }
 *      - Validates all four inputs are present and well-formed.
 *      - Rejects if email/username already exist as a real user OR as
 *        another pending signup.
 *      - Stores the desired credentials in wp_tnm_pending_signups with
 *        a 6-digit numeric code AND a random 32-byte hex token.
 *      - Emails the user two things: the code (for manual entry) and
 *        a magic link (for one-tap verify on the same device).
 *      - Returns { pending_id, email, expires_in } \u2014 NO auth token
 *        because the user record does not exist yet.
 *
 *   2. POST /auth/signup/verify { pending_id, code }
 *      - Looks up the pending row, checks code, expiry, attempt count.
 *      - Calls wp_create_user, marks tnm_email_verified=1 immediately,
 *        adds the customer role, sets display_name.
 *      - Deletes the pending row.
 *      - Returns { token, user } like /auth/register did before.
 *
 *   3. POST /auth/signup/resend { pending_id }
 *      - 5-minute cooldown, resets attempts.
 *
 *   4. GET /verify-signup?token=<hex>&pending=<id>
 *      - Magic-link entry point. Verifies the token and issues the
 *        same auth token the app would get from /signup/verify, then
 *        redirects to thenest://auth/signup/verified?token=<jwt>
 *        so the mobile app picks up the session.
 *
 * The daily cron in maybe_purge_expired() removes rows past
 * expires_at.
 *
 * @package MyNestUnifiedMarketplace
 */

defined( 'ABSPATH' ) || exit;

final class MNU_Signup {

	const NS                 = 'the-nest/v1';
	const CODE_TTL           = 24 * HOUR_IN_SECONDS;
	const RESEND_COOLDOWN    = 5 * MINUTE_IN_SECONDS;
	const MAX_CODE_ATTEMPTS  = 6;
	// Must stay in sync with the Expo `scheme` in app.json. The Stripe
	// Connect bridge page (`/mnu-connect-bridge/`) already enforces a
	// strict `thenest://` allowlist — our signup deep-link uses the
	// same scheme.
	const APP_SCHEME         = 'thenest';
	const PURGE_HOOK         = 'mnu_purge_expired_pending_signups';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_handle_magic_link' ) );
		add_action( self::PURGE_HOOK, array( __CLASS__, 'purge_expired' ) );
		add_action( 'init', array( __CLASS__, 'maybe_schedule_purge' ) );
	}

	public static function maybe_schedule_purge(): void {
		if ( ! wp_next_scheduled( self::PURGE_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::PURGE_HOOK );
		}
	}

	public static function register_routes(): void {
		register_rest_route(
			self::NS,
			'/auth/signup/start',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'start' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/auth/signup/verify',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'verify' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/auth/signup/resend',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'resend' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/* ------------------------------------------------------------------ *
	 *  Step 1 \u2014 start                                                    *
	 * ------------------------------------------------------------------ */
	public static function start( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( 'yes' !== tnm_get_option( 'allow_buyer_registration', 'yes' ) ) {
			return tnm_json_error( 'registration_disabled', 'Registration is currently disabled.', 403 );
		}

		$name     = sanitize_text_field( (string) $request->get_param( 'name' ) );
		if ( '' === $name ) {
			$name = sanitize_text_field( (string) $request->get_param( 'display_name' ) );
		}
		$username = sanitize_user( (string) $request->get_param( 'username' ), true );
		$email    = sanitize_email( (string) $request->get_param( 'email' ) );
		$password = (string) $request->get_param( 'password' );

		// v3.7.122.16 \u2014 full-name required on every signup. testuser123-style
		// accounts landed here with only username + throwaway email.
		if ( '' === $name ) {
			return tnm_json_error( 'missing_name', 'Please enter your full name.', 422 );
		}
		if ( strlen( $name ) < 2 ) {
			return tnm_json_error( 'invalid_name', 'Please enter your full name.', 422 );
		}
		if ( '' === $username ) {
			return tnm_json_error( 'missing_username', 'Please choose a username.', 422 );
		}
		if ( ! is_email( $email ) ) {
			return tnm_json_error( 'invalid_email', 'Please enter a valid email address.', 422 );
		}
		if ( strlen( $password ) < 8 ) {
			return tnm_json_error( 'weak_password', 'Password must be at least 8 characters.', 422 );
		}

		if ( email_exists( $email ) ) {
			return tnm_json_error( 'email_exists', 'An account with that email already exists. Try signing in instead.', 409 );
		}
		if ( username_exists( $username ) ) {
			return tnm_json_error( 'username_taken', 'That username is already taken. Please pick another.', 409 );
		}

		global $wpdb;
		$table = tnm_table( 'pending_signups' );
		$now   = time();

		// Housekeeping: kill any pending row for this email/username that has
		// expired, so the user can restart the flow cleanly.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE (email=%s OR username=%s) AND expires_at < %d", $email, $username, $now ) );

		// Reject only if a NON-expired row still exists.
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id, last_sent_at FROM {$table} WHERE (email=%s OR username=%s) AND expires_at >= %d LIMIT 1", $email, $username, $now ), ARRAY_A );
		if ( $existing ) {
			return tnm_json_error( 'signup_in_progress', 'A verification code was already sent. Check your inbox, or wait a few minutes and try again.', 409 );
		}

		$code  = self::random_code();
		$token = bin2hex( random_bytes( 32 ) );
		$hash  = wp_hash_password( $password );

		$ok = $wpdb->insert(
			$table,
			array(
				'email'         => $email,
				'username'      => $username,
				'display_name'  => $name,
				'password_hash' => $hash,
				'code'          => $code,
				'token'         => $token,
				'attempts'      => 0,
				'last_sent_at'  => $now,
				'created_at'    => $now,
				'expires_at'    => $now + self::CODE_TTL,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d' )
		);
		if ( false === $ok ) {
			return tnm_json_error( 'signup_failed', 'Something went wrong creating your account. Please try again.', 500 );
		}
		$pending_id = (int) $wpdb->insert_id;

		self::send_verification_email( $email, $name, $code, $token, $pending_id );

		return rest_ensure_response(
			array(
				'pending_id' => $pending_id,
				'email'      => $email,
				'expires_in' => self::CODE_TTL,
			)
		);
	}

	/* ------------------------------------------------------------------ *
	 *  Step 2 \u2014 verify                                                   *
	 * ------------------------------------------------------------------ */
	public static function verify( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$pending_id = (int) $request->get_param( 'pending_id' );
		$code       = preg_replace( '/\D+/', '', (string) $request->get_param( 'code' ) );
		if ( $pending_id <= 0 || strlen( $code ) !== 6 ) {
			return tnm_json_error( 'invalid_code', 'Enter the 6-digit code from your email.', 422 );
		}

		global $wpdb;
		$table = tnm_table( 'pending_signups' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d", $pending_id ), ARRAY_A );
		if ( ! $row ) {
			return tnm_json_error( 'not_found', 'This signup has expired. Please start again.', 404 );
		}
		if ( time() >= (int) $row['expires_at'] ) {
			$wpdb->delete( $table, array( 'id' => $pending_id ), array( '%d' ) );
			return tnm_json_error( 'expired', 'This code has expired. Please start signup again.', 410 );
		}
		if ( (int) $row['attempts'] >= self::MAX_CODE_ATTEMPTS ) {
			$wpdb->delete( $table, array( 'id' => $pending_id ), array( '%d' ) );
			return tnm_json_error( 'too_many_attempts', 'Too many incorrect codes. Please start signup again.', 429 );
		}
		if ( ! hash_equals( (string) $row['code'], $code ) ) {
			$wpdb->update( $table, array( 'attempts' => (int) $row['attempts'] + 1 ), array( 'id' => $pending_id ), array( '%d' ), array( '%d' ) );
			return tnm_json_error( 'incorrect_code', 'That code did not match. Please check your email and try again.', 422 );
		}

		return self::promote_pending( $row );
	}

	/* ------------------------------------------------------------------ *
	 *  Step 3 \u2014 resend                                                   *
	 * ------------------------------------------------------------------ */
	public static function resend( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$pending_id = (int) $request->get_param( 'pending_id' );
		if ( $pending_id <= 0 ) {
			return tnm_json_error( 'not_found', 'This signup has expired. Please start again.', 404 );
		}
		global $wpdb;
		$table = tnm_table( 'pending_signups' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d", $pending_id ), ARRAY_A );
		if ( ! $row ) {
			return tnm_json_error( 'not_found', 'This signup has expired. Please start again.', 404 );
		}
		$now = time();
		if ( $now < (int) $row['last_sent_at'] + self::RESEND_COOLDOWN ) {
			$wait = ( (int) $row['last_sent_at'] + self::RESEND_COOLDOWN ) - $now;
			return tnm_json_error( 'resend_cooldown', sprintf( 'Please wait %d seconds before requesting a new code.', $wait ), 429 );
		}
		$code = self::random_code();
		$wpdb->update(
			$table,
			array(
				'code'         => $code,
				'attempts'     => 0,
				'last_sent_at' => $now,
				'expires_at'   => $now + self::CODE_TTL,
			),
			array( 'id' => $pending_id ),
			array( '%s', '%d', '%d', '%d' ),
			array( '%d' )
		);
		self::send_verification_email( (string) $row['email'], (string) $row['display_name'], $code, (string) $row['token'], $pending_id );
		return rest_ensure_response( array( 'sent' => true, 'expires_in' => self::CODE_TTL ) );
	}

	/* ------------------------------------------------------------------ *
	 *  Magic-link handler                                                *
	 * ------------------------------------------------------------------ */
	public static function maybe_handle_magic_link(): void {
		if ( empty( $_GET['tnm_verify_signup'] ) || empty( $_GET['pending'] ) ) {
			return;
		}
		$token      = sanitize_text_field( wp_unslash( (string) $_GET['tnm_verify_signup'] ) );
		$pending_id = (int) $_GET['pending'];

		global $wpdb;
		$table = tnm_table( 'pending_signups' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d", $pending_id ), ARRAY_A );
		if ( ! $row || ! hash_equals( (string) $row['token'], $token ) ) {
			wp_safe_redirect( home_url( '/verify-email/?status=invalid' ) );
			exit;
		}
		if ( time() >= (int) $row['expires_at'] ) {
			$wpdb->delete( $table, array( 'id' => $pending_id ), array( '%d' ) );
			wp_safe_redirect( home_url( '/verify-email/?status=expired' ) );
			exit;
		}
		$result = self::promote_pending( $row );
		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( home_url( '/verify-email/?status=error' ) );
			exit;
		}
		$data = $result->get_data();
		// Deep-link back to the app carrying the JWT so the app resumes
		// as a signed-in user. The web fallback (below) just tells the
		// user the account is ready.
		$deep_link = self::APP_SCHEME . '://auth/signup/verified?token=' . rawurlencode( (string) ( $data['token'] ?? '' ) );
		$html      = self::magic_link_landing_html( $deep_link );
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		echo $html;
		exit;
	}

	/* ------------------------------------------------------------------ *
	 *  Housekeeping                                                      *
	 * ------------------------------------------------------------------ */
	public static function purge_expired(): void {
		global $wpdb;
		$table = tnm_table( 'pending_signups' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE expires_at < %d", time() ) );
	}

	/* ------------------------------------------------------------------ *
	 *  Internal helpers                                                  *
	 * ------------------------------------------------------------------ */

	/**
	 * Promote a pending row into a real WordPress user, mark verified,
	 * issue an auth token, and return the same shape /auth/register did.
	 */
	private static function promote_pending( array $row ): WP_REST_Response|WP_Error {
		// Race-check: another request may have promoted this row.
		if ( email_exists( (string) $row['email'] ) ) {
			return tnm_json_error( 'email_exists', 'An account with that email already exists.', 409 );
		}
		if ( username_exists( (string) $row['username'] ) ) {
			return tnm_json_error( 'username_taken', 'That username is already taken.', 409 );
		}

		// wp_create_user hashes its own password argument, but we already
		// have the hash \u2014 so create with a random password first, then
		// overwrite user_pass with the stored hash directly. This preserves
		// the password the user entered in Step 1.
		$temp_password = wp_generate_password( 32, true, true );

		// Same wp_pre_insert_user_data workaround the legacy /auth/register
		// endpoint uses \u2014 keeps WP.com Atomic mu-plugins from clobbering
		// user data on REST creates.
		$original_hook = null;
		if ( isset( $GLOBALS['wp_filter']['wp_pre_insert_user_data'] ) ) {
			$original_hook = $GLOBALS['wp_filter']['wp_pre_insert_user_data'];
			unset( $GLOBALS['wp_filter']['wp_pre_insert_user_data'] );
		}
		$user_id = wp_create_user( (string) $row['username'], $temp_password, (string) $row['email'] );
		if ( null !== $original_hook ) {
			$GLOBALS['wp_filter']['wp_pre_insert_user_data'] = $original_hook;
		}
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}
		$user_id = (int) $user_id;

		// Swap the temp hash for the one the user actually typed in Step 1.
		global $wpdb;
		$wpdb->update( $wpdb->users, array( 'user_pass' => (string) $row['password_hash'] ), array( 'ID' => $user_id ), array( '%s' ), array( '%d' ) );
		clean_user_cache( $user_id );

		$user = new WP_User( $user_id );
		if ( ! in_array( 'customer', (array) $user->roles, true ) ) {
			$user->add_role( 'customer' );
		}
		if ( in_array( 'subscriber', (array) $user->roles, true ) ) {
			$user->remove_role( 'subscriber' );
		}

		wp_update_user(
			array(
				'ID'           => $user_id,
				'display_name' => (string) $row['display_name'],
				'first_name'   => self::first_word( (string) $row['display_name'] ),
				'last_name'    => self::last_words( (string) $row['display_name'] ),
			)
		);

		// The whole point of this flow: the user is already verified.
		update_user_meta( $user_id, 'tnm_email_verified', '1' );

		// Cleanup pending row.
		$wpdb->delete( tnm_table( 'pending_signups' ), array( 'id' => (int) $row['id'] ), array( '%d' ) );

		$token = TNM_Auth::issue_token( $user_id );
		return rest_ensure_response(
			array(
				'token' => $token,
				'user'  => tnm_rest_user_data( get_userdata( $user_id ) ),
			)
		);
	}

	private static function random_code(): string {
		return str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );
	}

	private static function first_word( string $name ): string {
		$parts = preg_split( '/\s+/', trim( $name ) );
		return $parts ? (string) $parts[0] : '';
	}

	private static function last_words( string $name ): string {
		$parts = preg_split( '/\s+/', trim( $name ) );
		if ( ! $parts || count( $parts ) < 2 ) {
			return '';
		}
		array_shift( $parts );
		return implode( ' ', $parts );
	}

	private static function send_verification_email( string $email, string $display_name, string $code, string $token, int $pending_id ): void {
		$site_name = wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
		$magic     = add_query_arg(
			array(
				'tnm_verify_signup' => rawurlencode( $token ),
				'pending'           => (int) $pending_id,
			),
			home_url( '/' )
		);
		$subject = sprintf( '[%s] Your verification code: %s', $site_name, $code );
		$body    = sprintf(
			"Hi %s,\n\nWelcome to %s. Your verification code is:\n\n    %s\n\nYou can also verify with one tap by opening this link on your phone:\n\n%s\n\nThis code expires in 24 hours. If you did not request this, you can safely ignore this email.\n\n\u2013 The %s team",
			$display_name ?: 'there',
			$site_name,
			$code,
			$magic,
			$site_name
		);
		wp_mail( $email, $subject, $body );
	}

	private static function magic_link_landing_html( string $deep_link ): string {
		$deep_esc = esc_attr( $deep_link );
		$deep_txt = esc_html( $deep_link );
		return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Email verified</title><style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#F7F6F2;color:#28251D;margin:0;padding:40px 20px;text-align:center}h1{font-size:24px;margin:0 0 8px}p{color:#5A5957;margin:6px 0}a.btn{display:inline-block;margin-top:20px;background:#01696F;color:#fff;text-decoration:none;padding:12px 20px;border-radius:8px;font-weight:600}</style></head><body><h1>Email verified</h1><p>Your ShopMyNest account is ready. Opening the app now\u2026</p><a class="btn" href="' . $deep_esc . '">Open ShopMyNest</a><script>setTimeout(function(){window.location=' . wp_json_encode( $deep_link ) . ';},250);</script></body></html>';
	}
}

MNU_Signup::init();
