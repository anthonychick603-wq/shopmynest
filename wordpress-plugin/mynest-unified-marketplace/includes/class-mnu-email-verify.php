<?php
/**
 * Email verification for sellers.
 *
 * On user_register (or when an admin approves a seller application), a
 * verification token is generated and emailed. Clicking the link -- or POSTing
 * to /wp-json/the-nest/v1/verify-email -- sets the `tnm_email_verified` user
 * meta to "1". This meta is consulted by MNU_Payout_Gate before allowing
 * payouts to release.
 *
 * @package MyNestUnifiedMarketplace
 */

defined( 'ABSPATH' ) || exit;

final class MNU_Email_Verify {

	const META_VERIFIED    = 'tnm_email_verified';
	const META_TOKEN       = 'tnm_email_verify_token';
	const META_TOKEN_EXP   = 'tnm_email_verify_token_exp';
	const META_LAST_SENT   = 'tnm_email_verify_last_sent';
	const TOKEN_TTL        = 7 * DAY_IN_SECONDS;
	const RESEND_COOLDOWN  = 5 * MINUTE_IN_SECONDS;
	const VERIFY_PAGE_SLUG = 'verify-email';

	public static function init(): void {
		add_action( 'user_register', array( __CLASS__, 'on_user_register' ), 20 );
		add_action( 'tnm_seller_application_approved', array( __CLASS__, 'on_seller_approved' ), 20, 2 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_handle_verify_query' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_admin_notice' ) );
		add_action( 'init', array( __CLASS__, 'maybe_grandfather_existing_sellers' ), 30 );
	}

	/**
	 * Existing sellers who signed up before this feature shipped must not be
	 * locked out of payouts. On plugin upgrade to a version that includes
	 * email verification, mark every existing user with a seller role as
	 * already verified. Runs exactly once via the tnm_verify_grandfathered
	 * option flag.
	 */
	public static function maybe_grandfather_existing_sellers(): void {
		if ( get_option( 'tnm_verify_grandfathered' ) === '1' ) {
			return;
		}
		$seller_roles = array( 'mynest_seller', 'tnm_seller', 'administrator' );
		$user_query   = new WP_User_Query(
			array(
				'role__in' => $seller_roles,
				'fields'   => 'ID',
				'number'   => -1,
			)
		);
		foreach ( (array) $user_query->get_results() as $user_id ) {
			if ( ! self::is_verified( (int) $user_id ) ) {
				update_user_meta( (int) $user_id, self::META_VERIFIED, '1' );
			}
		}
		update_option( 'tnm_verify_grandfathered', '1', false );
	}

	/* -------------------------------------------------------------------- *
	 * Public helpers used by other classes (payout gate, seller dashboard) *
	 * -------------------------------------------------------------------- */

	public static function is_verified( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		return '1' === (string) get_user_meta( $user_id, self::META_VERIFIED, true );
	}

	public static function requires_verification( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		// Only sellers must verify -- customers can shop without it.
		return function_exists( 'tnm_is_seller' ) && tnm_is_seller( $user_id );
	}

	/**
	 * Mark a user as verified. Used by the token handler and by the
	 * grandfathering routine that runs on plugin activation to protect
	 * pre-existing sellers from being locked out.
	 */
	public static function mark_verified( int $user_id, string $source = 'link' ): void {
		if ( $user_id <= 0 ) {
			return;
		}
		update_user_meta( $user_id, self::META_VERIFIED, '1' );
		delete_user_meta( $user_id, self::META_TOKEN );
		delete_user_meta( $user_id, self::META_TOKEN_EXP );
		if ( function_exists( 'tnm_notify' ) ) {
			tnm_notify(
				$user_id,
				0,
				'email_verified',
				__( 'Email verified', 'mynest-unified-marketplace' ),
				__( 'Thanks for verifying your email address. Save a bank account in the mobile app and payouts will run automatically by ACH after the 2-day holding window.', 'mynest-unified-marketplace' ),
				0,
				'',
				function_exists( 'tnm_page_url' ) ? tnm_page_url( 'seller_dashboard' ) : ''
			);
		}
	}

	/* --------------------- *
	 * Registration triggers *
	 * --------------------- */

	public static function on_user_register( int $user_id ): void {
		self::send_verification_email( $user_id );
	}

	public static function on_seller_approved( int $application_id, int $user_id ): void {
		self::send_verification_email( $user_id );
	}

	/* --------------- *
	 * Token machinery *
	 * --------------- */

	private static function generate_token( int $user_id ): string {
		$token = wp_generate_password( 32, false, false );
		$hash  = wp_hash( $token );
		update_user_meta( $user_id, self::META_TOKEN, $hash );
		update_user_meta( $user_id, self::META_TOKEN_EXP, time() + self::TOKEN_TTL );
		return $token;
	}

	private static function verify_token( int $user_id, string $token ): bool {
		$hash = (string) get_user_meta( $user_id, self::META_TOKEN, true );
		$exp  = (int) get_user_meta( $user_id, self::META_TOKEN_EXP, true );
		if ( ! $hash || ! $exp || $exp < time() ) {
			return false;
		}
		return hash_equals( $hash, wp_hash( $token ) );
	}

	public static function send_verification_email( int $user_id, bool $bypass_cooldown = false ): bool {
		if ( $user_id <= 0 || self::is_verified( $user_id ) ) {
			return false;
		}
		$user = get_userdata( $user_id );
		if ( ! $user || ! is_email( $user->user_email ) ) {
			return false;
		}
		if ( ! $bypass_cooldown ) {
			$last_sent = (int) get_user_meta( $user_id, self::META_LAST_SENT, true );
			if ( $last_sent && ( time() - $last_sent ) < self::RESEND_COOLDOWN ) {
				return false;
			}
		}

		$token = self::generate_token( $user_id );
		$link  = add_query_arg(
			array(
				'tnm_verify_email' => rawurlencode( $token ),
				'user'             => $user_id,
			),
			home_url( '/' )
		);

		$subject = sprintf(
			/* translators: %s: site name */
			__( 'Verify your %s email address', 'mynest-unified-marketplace' ),
			get_bloginfo( 'name' )
		);
		$body = sprintf(
			/* translators: 1: display name, 2: site name, 3: verification link, 4: TTL in days */
			__( "Hi %1\$s,\n\nWelcome to %2\$s. Please verify your email address by clicking the link below within %4\$d days:\n\n%3\$s\n\nIf you did not create an account, you can safely ignore this message.\n\n– The %2\$s team", 'mynest-unified-marketplace' ),
			$user->display_name ?: $user->user_login,
			get_bloginfo( 'name' ),
			$link,
			self::TOKEN_TTL / DAY_IN_SECONDS
		);

		$sent = wp_mail( $user->user_email, $subject, $body );
		if ( $sent ) {
			update_user_meta( $user_id, self::META_LAST_SENT, time() );
		}
		return (bool) $sent;
	}

	/* ----------------------- *
	 * Front-end verify handler *
	 * ----------------------- */

	public static function maybe_handle_verify_query(): void {
		if ( empty( $_GET['tnm_verify_email'] ) || empty( $_GET['user'] ) ) {
			return;
		}
		$user_id = (int) $_GET['user'];
		$token   = sanitize_text_field( wp_unslash( $_GET['tnm_verify_email'] ) );
		if ( self::verify_token( $user_id, $token ) ) {
			self::mark_verified( $user_id, 'link' );
			$redirect = add_query_arg( 'tnm_email_verified', '1', function_exists( 'tnm_page_url' ) ? tnm_page_url( 'seller_dashboard' ) : home_url( '/' ) );
		} else {
			$redirect = add_query_arg( 'tnm_email_verified', 'expired', home_url( '/' ) );
		}
		wp_safe_redirect( $redirect );
		exit;
	}

	/* ----------- *
	 * REST routes *
	 * ----------- */

	public static function register_routes(): void {
		register_rest_route(
			'the-nest/v1',
			'/verify-email',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'rest_verify' ),
				'args'                => array(
					'user'  => array( 'required' => true, 'type' => 'integer' ),
					'token' => array( 'required' => true, 'type' => 'string' ),
				),
			)
		);
		register_rest_route(
			'the-nest/v1',
			'/resend-verification',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => 'is_user_logged_in',
				'callback'            => array( __CLASS__, 'rest_resend' ),
			)
		);
		register_rest_route(
			'the-nest/v1',
			'/verify-status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => function () { return current_user_can( 'manage_options' ); },
				'callback'            => array( __CLASS__, 'rest_status' ),
			)
		);
	}

	public static function rest_status( WP_REST_Request $request ): WP_REST_Response {
		$out = array(
			'grandfather_flag' => get_option( 'tnm_verify_grandfathered', '(unset)' ),
			'users'            => array(),
		);
		$q = new WP_User_Query( array( 'role__in' => array( 'mynest_seller', 'tnm_seller', 'administrator' ), 'fields' => array( 'ID', 'user_login' ), 'number' => -1 ) );
		foreach ( (array) $q->get_results() as $u ) {
			$out['users'][] = array(
				'id'       => (int) $u->ID,
				'login'    => $u->user_login,
				'verified' => (string) get_user_meta( (int) $u->ID, self::META_VERIFIED, true ),
			);
		}
		return new WP_REST_Response( $out, 200 );
	}

	public static function rest_verify( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id = (int) $request['user'];
		$token   = (string) $request['token'];
		if ( ! self::verify_token( $user_id, $token ) ) {
			return new WP_Error( 'invalid_token', __( 'Verification link is invalid or expired.', 'mynest-unified-marketplace' ), array( 'status' => 400 ) );
		}
		self::mark_verified( $user_id, 'rest' );
		return new WP_REST_Response( array( 'verified' => true ), 200 );
	}

	public static function rest_resend( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id = get_current_user_id();
		if ( self::is_verified( $user_id ) ) {
			return new WP_REST_Response( array( 'already_verified' => true ), 200 );
		}
		$sent = self::send_verification_email( $user_id );
		if ( ! $sent ) {
			return new WP_Error( 'resend_cooldown', __( 'A verification email was just sent -- please check your inbox and wait a few minutes before requesting another.', 'mynest-unified-marketplace' ), array( 'status' => 429 ) );
		}
		return new WP_REST_Response( array( 'sent' => true ), 200 );
	}

	/* ------------------ *
	 * wp-admin dashboard *
	 * ------------------ */

	public static function render_admin_notice(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}
		$user_id = get_current_user_id();
		if ( ! self::requires_verification( $user_id ) || self::is_verified( $user_id ) ) {
			return;
		}
		$resend_url = wp_nonce_url( admin_url( 'admin-post.php?action=tnm_resend_verification' ), 'tnm_resend_verification' );
		echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Verify your email address', 'mynest-unified-marketplace' ) . '</strong><br>' .
			esc_html__( 'Payouts are held until you verify the email address on your account. Check your inbox for the verification link.', 'mynest-unified-marketplace' ) .
			' <a href="' . esc_url( $resend_url ) . '">' . esc_html__( 'Resend verification email', 'mynest-unified-marketplace' ) . '</a></p></div>';
	}
}

// Also register a lightweight admin-post handler for the "Resend" link so it
// works without JavaScript.
add_action( 'admin_post_tnm_resend_verification', function () {
	if ( ! is_user_logged_in() || ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'tnm_resend_verification' ) ) {
		wp_die( 'Bad request', 400 );
	}
	MNU_Email_Verify::send_verification_email( get_current_user_id() );
	wp_safe_redirect( wp_get_referer() ?: admin_url() );
	exit;
} );

MNU_Email_Verify::init();
