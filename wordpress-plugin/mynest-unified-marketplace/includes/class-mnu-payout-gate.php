<?php
/**
 * Payout gate: block a seller from requesting a payout until they have both
 *   1. Verified their email address (via MNU_Email_Verify)
 *   2. Completed Stripe Connect KYC (payouts_enabled = true)
 *
 * TNM_Payouts::request() applies the `tnm_payout_pre_request` filter before
 * doing anything (see class-tnm-payouts.php v3.7.9). Returning a WP_Error from
 * this filter aborts the payout and surfaces the message to the seller.
 *
 * Admins and marketplace managers bypass the gate so they can still create
 * manual payouts if needed for corrections.
 *
 * @package MyNestUnifiedMarketplace
 */

defined( 'ABSPATH' ) || exit;

final class MNU_Payout_Gate {

	public static function init(): void {
		add_filter( 'tnm_payout_pre_request', array( __CLASS__, 'gate' ), 10, 3 );
	}

	/**
	 * @param mixed $result    Existing filter result (WP_Error|null|true).
	 * @param int   $seller_id Seller being paid out.
	 * @param bool  $automatic Whether the payout was generated automatically.
	 * @return mixed WP_Error to abort, else the passed-through $result.
	 */
	public static function gate( $result, int $seller_id, bool $automatic ) {
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( self::bypass_for_admin( $automatic ) ) {
			return $result;
		}

		// Email verification
		if ( class_exists( 'MNU_Email_Verify' ) && ! MNU_Email_Verify::is_verified( $seller_id ) ) {
			return new WP_Error(
				'payout_email_unverified',
				__( 'Verify the email address on your account before requesting a payout. Check your inbox for the verification link (or resend it from your dashboard).', 'mynest-unified-marketplace' ),
				array( 'status' => 403 )
			);
		}

		// Stripe Connect KYC
		if ( class_exists( 'MNU_Connect' ) ) {
			$status = MNU_Connect::get_status( $seller_id );
			if ( ! is_array( $status ) || empty( $status['payouts_enabled'] ) ) {
				return new WP_Error(
					'payout_stripe_kyc_incomplete',
					__( 'Finish your Stripe Connect setup before requesting a payout. Your bank details must be verified before we can release funds.', 'mynest-unified-marketplace' ),
					array( 'status' => 403 )
				);
			}
		}

		return $result;
	}

	private static function bypass_for_admin( bool $automatic ): bool {
		if ( $automatic ) {
			return false;
		}
		if ( function_exists( 'tnm_is_admin_or_manager' ) && tnm_is_admin_or_manager() ) {
			return true;
		}
		return current_user_can( 'manage_woocommerce' );
	}
}

MNU_Payout_Gate::init();
