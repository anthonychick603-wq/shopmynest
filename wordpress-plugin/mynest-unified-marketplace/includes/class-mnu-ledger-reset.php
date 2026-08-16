<?php
/**
 * v3.7.54 — MyNest Marketplace one-shot ledger reset REST endpoint.
 *
 * Pre-launch maintenance route for wiping the marketplace's custom ledger
 * tables. Locked to users with `manage_woocommerce`. Not a general-purpose
 * admin tool: it's here so the launch reset can be triggered from an
 * authenticated request instead of requiring direct DB access.
 *
 * Route:  POST /wp-json/mnu/v1/ledger-reset
 *   Body: { "confirm": "yes-truncate-ledgers" }
 *
 * Truncates:
 *   - {prefix}tnm_ledger
 *   - {prefix}tnm_payouts
 *
 * Does NOT touch: mnu_follows, mnu_notifications, mnu_messages, mnu_reviews,
 * mnu_import_jobs, WooCommerce orders, Stripe, products, users, or options.
 *
 * Returns per-table row counts (before/after) for verification.
 */

defined( 'ABSPATH' ) || exit;

final class MNU_Ledger_Reset {

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route(
			'mnu/v1',
			'/ledger-reset',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'reset' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
				'args'                => array(
					'confirm' => array( 'type' => 'string', 'required' => true ),
				),
			)
		);
	}

	public static function permission(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	public static function reset( \WP_REST_Request $req ) {
		$confirm = sanitize_text_field( (string) $req->get_param( 'confirm' ) );
		if ( 'yes-truncate-ledgers' !== $confirm ) {
			return new \WP_Error(
				'mnu_reset_bad_confirm',
				'Missing or wrong confirm token. Send { "confirm": "yes-truncate-ledgers" }.',
				array( 'status' => 400 )
			);
		}

		global $wpdb;
		$targets = array( 'tnm_ledger', 'tnm_payouts' );
		$result  = array();
		foreach ( $targets as $suffix ) {
			$table = $wpdb->prefix . $suffix;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- maintenance route
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( ! $exists ) {
				$result[ $suffix ] = array( 'exists' => false );
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( "TRUNCATE TABLE `{$table}`" );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
			$result[ $suffix ] = array(
				'exists' => true,
				'before' => $before,
				'after'  => $after,
			);
		}

		return array(
			'ok'      => true,
			'tables'  => $result,
			'user_id' => get_current_user_id(),
			'time'    => current_time( 'mysql', true ),
		);
	}
}

MNU_Ledger_Reset::init();
