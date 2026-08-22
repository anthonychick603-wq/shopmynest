<?php
/**
 * v3.13.1 — Password reset via 6-digit code.
 *
 * The mobile app used to punt users to wp-login.php?action=lostpassword
 * inside an in-app browser. That WebView flow was brittle (session
 * cookies, redirect chains, keyboards) and looked nothing like the rest
 * of the app. This class replaces it with a fully native three-step
 * flow that mirrors MNU_Signup's code-based signup verification.
 *
 *   1. POST /auth/password-reset/request  { email }
 *      - Always returns { sent: true } to avoid leaking whether an
 *        email exists. If the email does resolve to a user, a 6-digit
 *        code is stored (hashed) in user meta with a 15-minute TTL,
 *        the attempt counter is reset, and the code is emailed.
 *      - Enforces a 60-second resend cooldown per user via last_sent
 *        meta. Non-existent emails still pretend to succeed.
 *
 *   2. POST /auth/password-reset/verify  { email, code }
 *      - Timing-safe compare of the code hash. Increments an attempt
 *        counter; after MAX_ATTEMPTS the code is invalidated so
 *        brute force needs a fresh /request round.
 *      - Returns { valid: true } on success without changing the
 *        password. The mobile app then advances to the new-password
 *        screen carrying the verified code in-memory.
 *
 *   3. POST /auth/password-reset/confirm  { email, code, new_password }
 *      - Re-verifies the code + attempt window, calls wp_set_password,
 *        clears the reset meta, and issues a fresh JWT so the app can
 *        log the user straight in without a second /auth/login round.
 *
 * All routes are public (no auth) because the user is by definition
 * signed out at this point.
 *
 * @package MyNestUnifiedMarketplace
 */

defined( 'ABSPATH' ) || exit;

final class MNU_Password_Reset {

	const NS                = 'the-nest/v1';
	const META_CODE_HASH    = 'mnu_pw_reset_code_hash';
	const META_CODE_EXP     = 'mnu_pw_reset_code_exp';
	const META_ATTEMPTS     = 'mnu_pw_reset_attempts';
	const META_LAST_SENT    = 'mnu_pw_reset_last_sent';
	const CODE_TTL          = 15 * MINUTE_IN_SECONDS;
	const RESEND_COOLDOWN   = 60; // seconds
	const MAX_ATTEMPTS      = 5;
	const MIN_PASSWORD_LEN  = 8;

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route(
			self::NS,
			'/auth/password-reset/request',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'request_reset' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'email' => array( 'required' => true, 'type' => 'string' ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/auth/password-reset/verify',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'verify_code' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'email' => array( 'required' => true, 'type' => 'string' ),
					'code'  => array( 'required' => true, 'type' => 'string' ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/auth/password-reset/confirm',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'confirm_reset' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'email'        => array( 'required' => true, 'type' => 'string' ),
					'code'         => array( 'required' => true, 'type' => 'string' ),
					'new_password' => array( 'required' => true, 'type' => 'string' ),
				),
			)
		);
	}

	/* -------------------------------------------------------------------- *
	 * Helpers                                                              *
	 * -------------------------------------------------------------------- */

	private static function generate_code(): string {
		// 6 digits, zero-padded. Uses wp_rand for cryptographic-grade
		// entropy — do not swap for mt_rand.
		return str_pad( (string) wp_rand( 0, 999999 ), 6, '0', STR_PAD_LEFT );
	}

	private static function clear_state( int $user_id ): void {
		delete_user_meta( $user_id, self::META_CODE_HASH );
		delete_user_meta( $user_id, self::META_CODE_EXP );
		delete_user_meta( $user_id, self::META_ATTEMPTS );
	}

	private static function code_is_valid( int $user_id, string $code ): bool {
		$hash = (string) get_user_meta( $user_id, self::META_CODE_HASH, true );
		$exp  = (int) get_user_meta( $user_id, self::META_CODE_EXP, true );
		if ( '' === $hash || $exp <= 0 || $exp < time() ) {
			return false;
		}
		$attempts = (int) get_user_meta( $user_id, self::META_ATTEMPTS, true );
		if ( $attempts >= self::MAX_ATTEMPTS ) {
			return false;
		}
		// Only accept 6-digit numeric codes so the compare is not tricked
		// by unicode digits or whitespace.
		$code = preg_replace( '/\D/', '', (string) $code );
		if ( strlen( $code ) !== 6 ) {
			// Consume an attempt even for malformed codes so brute force
			// against a longer alphabet still has to rotate through them.
			update_user_meta( $user_id, self::META_ATTEMPTS, $attempts + 1 );
			return false;
		}
		return hash_equals( $hash, wp_hash( $code ) );
	}

	/**
	 * Locate a user by email OR user_login. Password-reset requests come
	 * in with whatever the user typed into a single "email" field, so we
	 * accept a username too — matching the /auth/login behavior.
	 */
	private static function find_user( string $identifier ): ?WP_User {
		$identifier = trim( $identifier );
		if ( '' === $identifier ) {
			return null;
		}
		$user = is_email( $identifier ) ? get_user_by( 'email', $identifier ) : null;
		if ( ! $user ) {
			$user = get_user_by( 'login', $identifier );
		}
		return $user ? $user : null;
	}

	/* -------------------------------------------------------------------- *
	 * Route: /auth/password-reset/request                                  *
	 * -------------------------------------------------------------------- */

	public static function request_reset( WP_REST_Request $request ) {
		$identifier = (string) $request->get_param( 'email' );
		$identifier = trim( sanitize_text_field( wp_unslash( $identifier ) ) );

		if ( '' === $identifier ) {
			return new WP_Error(
				'invalid_email',
				__( 'Please enter the email address on your account.', 'mynest-unified-marketplace' ),
				array( 'status' => 422 )
			);
		}

		$user = self::find_user( $identifier );

		// Existence-oblivious response — always return the same shape
		// regardless of whether we found a user, so an attacker cannot
		// use this endpoint to enumerate accounts.
		$oblivious = array(
			'sent'       => true,
			'expires_in' => self::CODE_TTL,
		);

		if ( ! $user ) {
			return new WP_REST_Response( $oblivious, 200 );
		}

		$user_id = (int) $user->ID;

		// Resend cooldown — silently succeed to preserve the oblivious
		// response, but do not send a fresh email if we sent one within
		// the cooldown window. The already-issued code is still valid.
		$last_sent = (int) get_user_meta( $user_id, self::META_LAST_SENT, true );
		if ( $last_sent > 0 && ( time() - $last_sent ) < self::RESEND_COOLDOWN ) {
			return new WP_REST_Response( $oblivious, 200 );
		}

		$code = self::generate_code();
		update_user_meta( $user_id, self::META_CODE_HASH, wp_hash( $code ) );
		update_user_meta( $user_id, self::META_CODE_EXP, time() + self::CODE_TTL );
		update_user_meta( $user_id, self::META_ATTEMPTS, 0 );
		update_user_meta( $user_id, self::META_LAST_SENT, time() );

		$site  = get_bloginfo( 'name' );
		$to    = $user->user_email;
		$mins  = (int) round( self::CODE_TTL / 60 );
		$name  = $user->display_name ?: $user->user_login;
		$subject = sprintf(
			/* translators: %s: site name */
			__( 'Your %s password reset code', 'mynest-unified-marketplace' ),
			$site
		);
		$body = sprintf(
			/* translators: 1: display name, 2: 6-digit code, 3: minutes, 4: site name */
			__( "Hi %1\$s,\n\nYour %4\$s password reset code is:\n\n    %2\$s\n\nEnter it in the app within the next %3\$d minutes. If you did not request a password reset, you can safely ignore this message — your password will not be changed.\n\n– The %4\$s team", 'mynest-unified-marketplace' ),
			$name,
			$code,
			$mins,
			$site
		);

		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
		wp_mail( $to, $subject, $body, $headers );

		return new WP_REST_Response( $oblivious, 200 );
	}

	/* -------------------------------------------------------------------- *
	 * Route: /auth/password-reset/verify                                   *
	 * -------------------------------------------------------------------- */

	public static function verify_code( WP_REST_Request $request ) {
		$identifier = (string) $request->get_param( 'email' );
		$identifier = trim( sanitize_text_field( wp_unslash( $identifier ) ) );
		$code       = (string) $request->get_param( 'code' );

		if ( '' === $identifier || '' === trim( $code ) ) {
			return new WP_Error( 'invalid_input', __( 'Email and code are required.', 'mynest-unified-marketplace' ), array( 'status' => 422 ) );
		}

		$user = self::find_user( $identifier );
		if ( ! $user ) {
			// Same generic error whether the user exists or the code is
			// wrong — do not leak existence at verify time either.
			return new WP_Error( 'invalid_code', __( 'That code is incorrect or has expired. Request a new one from the previous screen.', 'mynest-unified-marketplace' ), array( 'status' => 400 ) );
		}

		$user_id  = (int) $user->ID;
		$attempts = (int) get_user_meta( $user_id, self::META_ATTEMPTS, true );

		if ( ! self::code_is_valid( $user_id, $code ) ) {
			// Bump attempt counter on real failed attempts too (code_is_valid
			// only bumps for malformed input; increment here for wrong-but-
			// well-formed codes).
			$attempts_after = (int) get_user_meta( $user_id, self::META_ATTEMPTS, true );
			if ( $attempts_after === $attempts ) {
				update_user_meta( $user_id, self::META_ATTEMPTS, $attempts + 1 );
			}
			return new WP_Error( 'invalid_code', __( 'That code is incorrect or has expired. Request a new one from the previous screen.', 'mynest-unified-marketplace' ), array( 'status' => 400 ) );
		}

		return new WP_REST_Response(
			array(
				'valid'      => true,
				// Echo the resolved email so the mobile app can display
				// the canonical address on the new-password screen (the
				// user may have typed a username in the first field).
				'email'      => $user->user_email,
				'expires_in' => max( 0, (int) get_user_meta( $user_id, self::META_CODE_EXP, true ) - time() ),
			),
			200
		);
	}

	/* -------------------------------------------------------------------- *
	 * Route: /auth/password-reset/confirm                                  *
	 * -------------------------------------------------------------------- */

	public static function confirm_reset( WP_REST_Request $request ) {
		$identifier   = (string) $request->get_param( 'email' );
		$identifier   = trim( sanitize_text_field( wp_unslash( $identifier ) ) );
		$code         = (string) $request->get_param( 'code' );
		$new_password = (string) $request->get_param( 'new_password' );

		if ( '' === $identifier || '' === trim( $code ) || '' === $new_password ) {
			return new WP_Error( 'invalid_input', __( 'Email, code, and new password are required.', 'mynest-unified-marketplace' ), array( 'status' => 422 ) );
		}
		if ( strlen( $new_password ) < self::MIN_PASSWORD_LEN ) {
			return new WP_Error(
				'weak_password',
				sprintf(
					/* translators: %d: min password length */
					__( 'Password must be at least %d characters.', 'mynest-unified-marketplace' ),
					self::MIN_PASSWORD_LEN
				),
				array( 'status' => 422 )
			);
		}

		$user = self::find_user( $identifier );
		if ( ! $user || ! self::code_is_valid( (int) $user->ID, $code ) ) {
			return new WP_Error( 'invalid_code', __( 'That code is incorrect or has expired. Request a new one from the previous screen.', 'mynest-unified-marketplace' ), array( 'status' => 400 ) );
		}

		$user_id = (int) $user->ID;
		wp_set_password( $new_password, $user_id );
		self::clear_state( $user_id );
		delete_user_meta( $user_id, self::META_LAST_SENT );

		// Best-effort notification email so the user sees a confirmation
		// their password changed. Non-fatal if delivery fails.
		$site    = get_bloginfo( 'name' );
		$subject = sprintf(
			/* translators: %s: site name */
			__( 'Your %s password was changed', 'mynest-unified-marketplace' ),
			$site
		);
		$body = sprintf(
			/* translators: 1: display name, 2: site name */
			__( "Hi %1\$s,\n\nYour %2\$s password was just changed. If this wasn't you, contact support right away.\n\n– The %2\$s team", 'mynest-unified-marketplace' ),
			$user->display_name ?: $user->user_login,
			$site
		);
		wp_mail( $user->user_email, $subject, $body );

		// Mint a token so the mobile app can log the user straight in with
		// the same shape /auth/login returns. If either helper is missing
		// (defensive — both ship in the same plugin) we return success and
		// let the app fall back to signing in with the new password.
		$response = array(
			'success' => true,
			'email'   => $user->user_email,
		);
		if ( class_exists( 'TNM_Auth' ) && function_exists( 'tnm_rest_user_data' ) ) {
			$response['token'] = (string) TNM_Auth::issue_token( $user_id );
			$response['user']  = tnm_rest_user_data( $user );
		}

		return new WP_REST_Response( $response, 200 );
	}
}

MNU_Password_Reset::init();
