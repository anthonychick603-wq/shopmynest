<?php
/**
 * MNU_Seller_Readiness
 *
 * One place that describes what a seller needs to complete before they
 * can list, ship, and get paid. Consolidates:
 *
 *   - Stripe Connect connected + charges_enabled + payouts_enabled + details_submitted
 *   - Ship-from address + package defaults (via mnu_seller_ship_from_missing_field)
 *   - Store display name
 *   - Has published at least one product
 *
 * Exposes GET /the-nest/v1/seller/readiness which returns a checklist
 * that the mobile app renders on the seller dashboard.
 *
 * @package MyNest_Unified_Marketplace
 * @since 3.7.93
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MNU_Seller_Readiness {

	public const NS = 'the-nest/v1';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route(
			self::NS,
			'/seller/readiness',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'rest_readiness' ),
				'permission_callback' => static function () {
					return is_user_logged_in();
				},
			)
		);
	}

	public static function rest_readiness( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return new WP_Error( 'not_logged_in', 'You must be signed in.', array( 'status' => 401 ) );
		}
		// Allow admins to inspect any seller via ?seller_id=.
		$target = $user_id;
		$asked  = (int) $request->get_param( 'seller_id' );
		if ( $asked > 0 && current_user_can( 'manage_woocommerce' ) ) {
			$target = $asked;
		}
		if ( ! self::is_seller_user( $target ) ) {
			return new WP_Error(
				'not_a_seller',
				'The user is not a marketplace seller.',
				array( 'status' => 422, 'user_id' => $target )
			);
		}
		return rest_ensure_response( self::build( $target ) );
	}

	/**
	 * Compute the readiness checklist for the given seller.
	 *
	 * @return array{
	 *   seller_id:int,
	 *   ready_to_sell:bool,
	 *   completed:int,
	 *   total:int,
	 *   steps:array<int,array{
	 *     key:string,
	 *     label:string,
	 *     description:string,
	 *     ok:bool,
	 *     blocking:bool,
	 *     action_url:string,
	 *     action_label:string,
	 *     detail:string
	 *   }>
	 * }
	 */
	public static function build( int $seller_id ): array {
		$steps = array();

		// 1) Store display name.
		$display_name = function_exists( 'tnm_seller_display_name' )
			? (string) tnm_seller_display_name( $seller_id )
			: '';
		$user = get_userdata( $seller_id );
		$login = $user ? $user->user_login : '';
		$has_name = ( '' !== $display_name && $display_name !== $login );
		$steps[] = array(
			'key'          => 'store_name',
			'label'        => 'Set your shop name',
			'description'  => 'Choose the name buyers will see on your listings and profile.',
			'ok'           => $has_name,
			'blocking'     => false,
			// v3.7.95 - approved sellers who never set a display store name
			// used to be routed back into /seller/apply, which detects
			// "approved" and dead-ends. Point at the new shop settings screen.
			'action_url'   => '/(tabs)/(more)/seller/shop-settings',
			'action_label' => $has_name ? '' : 'Add name',
			'detail'       => $has_name ? $display_name : '',
		);

		// 2) Bank account on file (v3.8.0 replacement for the four Stripe
		// Connect steps). Sellers now enter a routing + account number
		// directly; the platform ACHs their share from a business checking
		// account after the holding window. Charges/payouts/details_submitted
		// concepts no longer apply since there is no Connect account.
		$has_bank = class_exists( 'MNU_Bank_Account' )
			? MNU_Bank_Account::has_bank_account( $seller_id )
			: false;
		$bank_last4 = (string) get_user_meta( $seller_id, '_mnu_bank_last4', true );
		$steps[] = array(
			'key'          => 'bank_account',
			'label'        => 'Add your bank account',
			'description'  => 'ShopMyNest pays your earnings by ACH to this account after the 7-day holding window.',
			'ok'           => $has_bank,
			'blocking'     => true,
			'action_url'   => '/(tabs)/(more)/seller/connect',
			'action_label' => $has_bank ? 'Edit' : 'Add bank',
			'detail'       => $has_bank && '' !== $bank_last4 ? 'Account ending in ' . $bank_last4 : '',
		);

		// 6) Ship-from address complete.
		$missing_ship = function_exists( 'mnu_seller_ship_from_missing_field' )
			? (string) mnu_seller_ship_from_missing_field( $seller_id )
			: '';
		$ship_ok = ( '' === $missing_ship );
		$steps[] = array(
			'key'          => 'ship_from_complete',
			'label'        => 'Complete ship-from address',
			'description'  => 'ShopMyNest quotes shipping from your address, so buyers see accurate rates at checkout.',
			'ok'           => $ship_ok,
			'blocking'     => true,
			'action_url'   => '/(tabs)/(more)/seller/shippo',
			'action_label' => $ship_ok ? 'Edit' : 'Add address',
			'detail'       => $ship_ok ? '' : sprintf( 'Missing: %s', $missing_ship ),
		);

		// v3.9.2 — Shippo connect step removed. The platform's own Shippo token
		// covers every seller, so per-seller Shippo onboarding is no longer part
		// of the readiness checklist. Sellers can still connect their own token
		// via the seller dashboard → Shipping (Shippo) screen if they want to.

		// 7) First product published.
		$has_product = self::has_published_product( $seller_id );
		$steps[] = array(
			'key'          => 'first_product',
			'label'        => 'List your first product',
			'description'  => 'A shop with at least one live listing shows up in Browse and category feeds.',
			'ok'           => $has_product,
			'blocking'     => false,
			'action_url'   => '/(tabs)/(more)/seller/product-form',
			'action_label' => $has_product ? 'Add another' : 'Create listing',
			'detail'       => '',
		);

		$completed = 0;
		foreach ( $steps as $s ) {
			if ( ! empty( $s['ok'] ) ) {
				$completed++;
			}
		}
		$blocking_incomplete = false;
		foreach ( $steps as $s ) {
			if ( ! empty( $s['blocking'] ) && empty( $s['ok'] ) ) {
				$blocking_incomplete = true;
				break;
			}
		}

		return array(
			'seller_id'     => $seller_id,
			'ready_to_sell' => ! $blocking_incomplete,
			'completed'     => $completed,
			'total'         => count( $steps ),
			'steps'         => $steps,
		);
	}

	private static function is_seller_user( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		if ( function_exists( 'tnm_is_seller' ) ) {
			return (bool) tnm_is_seller( $user_id );
		}
		$user = get_userdata( $user_id );
		if ( ! $user || empty( $user->roles ) ) {
			return false;
		}
		return in_array( 'tnm_seller', (array) $user->roles, true )
			|| in_array( 'mynest_seller', (array) $user->roles, true );
	}

	private static function has_published_product( int $seller_id ): bool {
		global $wpdb;
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm
				   ON pm.post_id = p.ID
				  AND pm.meta_key = '_tnm_seller_id'
				  AND pm.meta_value = %d
				 WHERE p.post_type = 'product'
				   AND p.post_status = 'publish'
				 LIMIT 1",
				$seller_id
			)
		);
		return (bool) $id;
	}
}

MNU_Seller_Readiness::init();
