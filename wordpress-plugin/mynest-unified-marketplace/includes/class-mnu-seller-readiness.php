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
			'action_url'   => '/(tabs)/(more)/seller/apply',
			'action_label' => $has_name ? '' : 'Add name',
			'detail'       => $has_name ? $display_name : '',
		);

		// 2) Stripe Connect account.
		$account_id = class_exists( 'MNU_Connect' )
			? (string) MNU_Connect::account_id( $seller_id )
			: (string) get_user_meta( $seller_id, 'tnm_stripe_account_id', true );
		$stripe_connected = ( '' !== $account_id );
		$stripe_cache = class_exists( 'MNU_Connect' )
			? MNU_Connect::cached_status( $seller_id )
			: array(
				'connected'         => $stripe_connected,
				'charges_enabled'   => (bool) get_user_meta( $seller_id, 'tnm_stripe_charges_enabled', true ),
				'payouts_enabled'   => (bool) get_user_meta( $seller_id, 'tnm_stripe_payouts_enabled', true ),
				'details_submitted' => (bool) get_user_meta( $seller_id, 'tnm_stripe_details_submitted', true ),
			);
		$steps[] = array(
			'key'          => 'stripe_connected',
			'label'        => 'Connect Stripe',
			'description'  => 'ShopMyNest routes payouts through Stripe. You will land on Stripe to link a bank account.',
			'ok'           => $stripe_connected,
			'blocking'     => true,
			'action_url'   => '/(tabs)/(more)/seller/connect',
			'action_label' => $stripe_connected ? 'Manage' : 'Connect',
			'detail'       => $stripe_connected ? substr( $account_id, 0, 12 ) . '...' : '',
		);

		// 3) Stripe details submitted (the "you finished onboarding" gate).
		$details_ok = ! empty( $stripe_cache['details_submitted'] );
		$steps[] = array(
			'key'          => 'stripe_details_submitted',
			'label'        => 'Finish Stripe onboarding',
			'description'  => 'Provide the business or personal details Stripe needs to release payouts.',
			'ok'           => $details_ok,
			'blocking'     => true,
			'action_url'   => '/(tabs)/(more)/seller/connect',
			'action_label' => $details_ok ? 'Open' : 'Continue',
			'detail'       => '',
		);

		// 4) Charges enabled.
		$charges_ok = ! empty( $stripe_cache['charges_enabled'] );
		$steps[] = array(
			'key'          => 'stripe_charges_enabled',
			'label'        => 'Stripe can accept charges',
			'description'  => 'Stripe must have your account cleared for charges so buyers can pay you.',
			'ok'           => $charges_ok,
			'blocking'     => true,
			'action_url'   => '/(tabs)/(more)/seller/connect',
			'action_label' => 'Check status',
			'detail'       => $charges_ok ? '' : 'Stripe has not enabled charges yet. If you just onboarded, allow a few minutes and refresh.',
		);

		// 5) Payouts enabled.
		$payouts_ok = ! empty( $stripe_cache['payouts_enabled'] );
		$steps[] = array(
			'key'          => 'stripe_payouts_enabled',
			'label'        => 'Stripe can send payouts',
			'description'  => 'Payouts must be enabled so your earnings can leave the ShopMyNest platform balance.',
			'ok'           => $payouts_ok,
			'blocking'     => true,
			'action_url'   => '/(tabs)/(more)/seller/connect',
			'action_label' => 'Check status',
			'detail'       => $payouts_ok ? '' : 'Stripe has not enabled payouts yet. Complete any remaining requirements in the Stripe dashboard.',
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
