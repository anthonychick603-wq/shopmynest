<?php
/**
 * Enqueues front-end and admin CSS/JS. No build step — plain files only.
 *
 * @package MyNest_Trust_Suite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TNM_Trust_Assets {

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin' ) );
	}

	/**
	 * Enqueue front-end CSS/JS on every front-end page (shortcodes/buttons
	 * may appear on many templates, so we load site-wide; files are tiny).
	 */
	public static function enqueue_frontend() {
		wp_enqueue_style(
			'nest-trust-frontend',
			TNM_TRUST_URL . 'assets/css/nest-trust-frontend.css',
			array(),
			TNM_TRUST_VERSION
		);

		wp_enqueue_script(
			'nest-trust-frontend',
			TNM_TRUST_URL . 'assets/js/nest-trust-frontend.js',
			array(),
			TNM_TRUST_VERSION,
			true
		);

		wp_localize_script(
			'nest-trust-frontend',
			'NestTrustConfig',
			array(
				'restUrl'      => esc_url_raw( rest_url( TNM_TRUST_REST_NS . '/' ) ),
				'nonce'         => wp_create_nonce( 'wp_rest' ),
				'isLoggedIn'   => is_user_logged_in(),
				'currentUserId' => get_current_user_id(),
				'checkoutUrl'  => function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : '',
				'i18n'          => array(
					'error'   => __( 'Something went wrong. Please try again.', 'nest-trust' ),
					'loading' => __( 'Loading…', 'nest-trust' ),
				),
			)
		);
	}

	/**
	 * Enqueue admin CSS/JS only on this plugin's own admin screens.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public static function enqueue_admin( $hook ) {
		if ( false === strpos( (string) $hook, 'nest-trust' ) ) {
			return;
		}

		wp_enqueue_style(
			'nest-trust-admin',
			TNM_TRUST_URL . 'assets/css/nest-trust-admin.css',
			array(),
			TNM_TRUST_VERSION
		);

		wp_enqueue_script(
			'nest-trust-admin',
			TNM_TRUST_URL . 'assets/js/nest-trust-admin.js',
			array(),
			TNM_TRUST_VERSION,
			true
		);

		wp_localize_script(
			'nest-trust-admin',
			'NestTrustAdminConfig',
			array(
				'restUrl' => esc_url_raw( rest_url( TNM_TRUST_REST_NS . '/' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			)
		);
	}
}
