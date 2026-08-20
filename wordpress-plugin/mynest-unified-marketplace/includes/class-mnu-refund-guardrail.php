<?php
/**
 * MNU_Refund_Guardrail
 *
 * Catches Stripe refunds that were issued OUTSIDE the plugin's normal path
 * (typically from the Stripe Dashboard) and automatically issues the missing
 * transfer reversal + application-fee refund so the platform doesn't eat the
 * seller's payout.
 *
 * Background
 * ==========
 * `MNU_Woo_Gateway_Impl::process_refund()` sets `reverse_transfer=true` and
 * `refund_application_fee=true` on single-seller destination charges, and
 * loops `/transfers/{id}/reversals` for multi-seller SCT charges. All of
 * that only happens when Woo's `process_refund` fires — i.e. the refund was
 * initiated from Woo Admin → Order → Refund.
 *
 * A refund created directly in the Stripe Dashboard bypasses Woo entirely:
 * no metadata[wc_order_id] is set, the seller keeps their transfer, and the
 * platform loses the seller's net.
 *
 * This class listens for `charge.refunded` webhook events and detects that
 * exact case, then issues the missing reversal(s) + app-fee refund itself.
 * Idempotent via `_mnu_dashboard_refund_recovered` order meta keyed by
 * refund_id.
 *
 * @package MyNest_Unified_Marketplace
 * @since 3.7.122.10
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MNU_Refund_Guardrail {

	/**
	 * Order meta key. Value is a JSON map of refund_id => recovery record so
	 * we never reverse the same dashboard refund twice.
	 */
	public const META_KEY = '_mnu_dashboard_refund_recovered';

	/**
	 * Entry point wired from mnu_native_webhook() for the `charge.refunded`
	 * event type. The event payload's data.object is a Stripe Charge with a
	 * .refunds.data list of Refund objects.
	 *
	 * We only act on the MOST RECENT refund (the one that just fired the
	 * event). Older refunds on the same charge are either already recovered
	 * or were the ones we ourselves fired via process_refund — in either
	 * case the idempotency guard skips them.
	 */
	public static function handle_charge_refunded( array $charge ): void {
		$intent_id = (string) ( $charge['payment_intent'] ?? '' );
		if ( '' === $intent_id ) {
			return;
		}
		$charge_id = (string) ( $charge['id'] ?? '' );
		if ( '' === $charge_id ) {
			return;
		}

		$order = self::locate_order( $intent_id );
		if ( ! $order ) {
			return;
		}

		$refunds = (array) ( $charge['refunds']['data'] ?? array() );
		if ( empty( $refunds ) ) {
			return;
		}

		// Newest refund first: Stripe returns .refunds.data ordered
		// desc by created. Iterate all in case multiple partial dashboard
		// refunds fired in quick succession — the idempotency guard makes
		// this safe.
		foreach ( $refunds as $refund ) {
			if ( ! is_array( $refund ) ) {
				continue;
			}
			self::maybe_recover_refund( $order, $charge, $refund );
		}
	}

	/**
	 * Recovery logic for a single refund object.
	 *
	 * Skip conditions (in order):
	 *  1. Refund already succeeded via our own process_refund path — detected
	 *     by metadata[wc_order_id] being set (we always write it).
	 *  2. Refund not in `succeeded` status yet.
	 *  3. Refund already recovered by this class (idempotency).
	 *  4. Refund already reversed the transfer natively (transfer_reversal
	 *     present on destination-charge refunds).
	 */
	private static function maybe_recover_refund( WC_Order $order, array $charge, array $refund ): void {
		$refund_id = (string) ( $refund['id'] ?? '' );
		if ( '' === $refund_id ) {
			return;
		}
		$status = (string) ( $refund['status'] ?? '' );
		if ( 'succeeded' !== $status ) {
			return;
		}
		// Our own refunds always carry metadata[wc_order_id]. If it's set,
		// process_refund already reversed everything — nothing to do.
		$refund_metadata = (array) ( $refund['metadata'] ?? array() );
		if ( ! empty( $refund_metadata['wc_order_id'] ) ) {
			return;
		}
		// Idempotency: never recover the same refund twice.
		$recovered = self::get_recovered_map( $order );
		if ( isset( $recovered[ $refund_id ] ) ) {
			return;
		}
		// Refund with a transfer_reversal already means Stripe reversed the
		// destination charge transfer natively (dashboard user must have
		// checked "reverse transfer" — rare but possible).
		if ( ! empty( $refund['transfer_reversal'] ) ) {
			self::record_recovery( $order, $refund_id, array(
				'status'  => 'already_reversed',
				'note'    => 'Dashboard refund already carried transfer_reversal; nothing to recover.',
				'at'      => gmdate( 'c' ),
			) );
			return;
		}

		$refund_cents = (int) ( $refund['amount'] ?? 0 );
		if ( $refund_cents <= 0 ) {
			return;
		}

		// Determine routing path.
		$transfers_raw = $order->get_meta( '_mnu_seller_transfers', true );
		$transfers     = is_string( $transfers_raw ) ? json_decode( $transfers_raw, true ) : $transfers_raw;
		$has_sct       = is_array( $transfers ) && ! empty( $transfers );

		if ( $has_sct ) {
			self::recover_sct( $order, $refund_id, $refund_cents, $transfers );
		} else {
			self::recover_destination_charge( $order, $refund_id, $refund_cents, $charge );
		}
	}

	/**
	 * Single-seller destination-charge recovery. The charge was issued with
	 * transfer_data[destination] + application_fee_amount. To reverse the
	 * seller's transfer and refund the platform fee we call
	 * `/application_fees/{fee}/refunds` — Stripe automatically reverses the
	 * associated destination transfer proportionally.
	 *
	 * If the refund is partial we scale the fee refund to the same
	 * fraction. Stripe reverses the transfer for the (refund_amount − fee)
	 * portion.
	 */
	private static function recover_destination_charge( WC_Order $order, string $refund_id, int $refund_cents, array $charge ): void {
		$fee_id = (string) ( $charge['application_fee'] ?? '' );
		$charge_amount = (int) ( $charge['amount'] ?? 0 );
		if ( '' === $fee_id || $charge_amount <= 0 ) {
			// No application fee — either the fee stamp was 0 or the
			// seller_can_sell fallback fired. Nothing to reverse: the
			// platform charge is refunded, no seller transfer exists.
			self::record_recovery( $order, $refund_id, array(
				'status' => 'no_fee_to_refund',
				'note'   => 'Destination charge had no application_fee; nothing to reverse.',
				'at'     => gmdate( 'c' ),
			) );
			return;
		}

		// Retrieve the fee to learn its size.
		$fee = mnu_native_stripe_get( '/application_fees/' . rawurlencode( $fee_id ) );
		if ( is_wp_error( $fee ) ) {
			$order->add_order_note( sprintf(
				'Refund guardrail: could not retrieve application fee %s to reverse dashboard refund %s: %s',
				$fee_id, $refund_id, $fee->get_error_message()
			) );
			return;
		}
		$fee_amount    = (int) ( $fee['amount'] ?? 0 );
		$fee_refunded  = (int) ( $fee['amount_refunded'] ?? 0 );
		$fee_available = max( 0, $fee_amount - $fee_refunded );
		if ( 0 === $fee_available ) {
			self::record_recovery( $order, $refund_id, array(
				'status' => 'fee_already_refunded',
				'at'     => gmdate( 'c' ),
			) );
			return;
		}

		// Proportional fee refund: refund_cents / charge_amount * fee_amount.
		// Cap to what's still available on the fee.
		$fee_refund_cents = (int) floor( $fee_amount * ( $refund_cents / max( 1, $charge_amount ) ) );
		$fee_refund_cents = max( 1, min( $fee_available, $fee_refund_cents ) );

		$result = mnu_native_stripe_request(
			'/application_fees/' . rawurlencode( $fee_id ) . '/refunds',
			array(
				'amount'                => (string) $fee_refund_cents,
				'metadata[wc_order_id]' => (string) $order->get_id(),
				'metadata[refund_id]'   => $refund_id,
				'metadata[source]'      => 'mnu_refund_guardrail',
			),
			'mnu_grd_' . $order->get_id() . '_' . $refund_id . '_' . $fee_refund_cents
		);
		if ( is_wp_error( $result ) ) {
			$order->add_order_note( sprintf(
				'Refund guardrail: application fee refund failed for dashboard refund %s: %s',
				$refund_id, $result->get_error_message()
			) );
			return;
		}
		$fee_refund_id = (string) ( $result['id'] ?? '' );
		self::record_recovery( $order, $refund_id, array(
			'status'          => 'fee_refunded',
			'path'            => 'destination_charge',
			'fee_id'          => $fee_id,
			'fee_refund_id'   => $fee_refund_id,
			'fee_refund_cents'=> $fee_refund_cents,
			'refund_cents'    => $refund_cents,
			'at'              => gmdate( 'c' ),
		) );
		$order->add_order_note( sprintf(
			'Refund guardrail: recovered dashboard refund %s. Refunded $%.2f of the platform application fee (also reverses the seller destination transfer).',
			$refund_id, $fee_refund_cents / 100
		) );
		$order->save();
	}

	/**
	 * Multi-seller Separate Charges and Transfers recovery. The plugin sent
	 * a `/transfers` call per seller during payment capture and recorded the
	 * transfer ids in `_mnu_seller_transfers`. To recover a dashboard refund
	 * we issue `/transfers/{id}/reversals` for each seller, scaled to the
	 * fraction of the order that was refunded.
	 */
	private static function recover_sct( WC_Order $order, string $refund_id, int $refund_cents, array $transfers ): void {
		$order_total_cents = (int) round( (float) $order->get_total() * 100 );
		if ( $order_total_cents <= 0 ) {
			return;
		}
		$is_full   = $refund_cents >= $order_total_cents;
		$updated   = $transfers;
		$reversed  = array();
		$errors    = array();

		foreach ( $transfers as $seller_id => $t ) {
			if ( ! is_array( $t ) || 'sent' !== ( $t['status'] ?? '' ) ) {
				continue;
			}
			$tr_id     = (string) ( $t['transfer_id'] ?? '' );
			$net_cents = (int) ( $t['net_cents'] ?? 0 );
			if ( '' === $tr_id || $net_cents <= 0 ) {
				continue;
			}
			// Skip if we already fully reversed this transfer.
			$already_reversed = (int) ( $t['reversed_cents'] ?? 0 );
			$available        = max( 0, $net_cents - $already_reversed );
			if ( 0 === $available ) {
				continue;
			}
			$reverse_cents = $is_full
				? $available
				: (int) round( $net_cents * ( $refund_cents / $order_total_cents ) );
			$reverse_cents = max( 1, min( $available, $reverse_cents ) );

			$rev = mnu_native_stripe_request(
				'/transfers/' . rawurlencode( $tr_id ) . '/reversals',
				array(
					'amount'                => (string) $reverse_cents,
					'metadata[wc_order_id]' => (string) $order->get_id(),
					'metadata[refund_id]'   => $refund_id,
					'metadata[source]'      => 'mnu_refund_guardrail',
				),
				'mnu_grd_sct_' . $order->get_id() . '_' . $tr_id . '_' . $refund_id . '_' . $reverse_cents
			);
			if ( is_wp_error( $rev ) ) {
				$errors[ (string) $seller_id ] = $rev->get_error_message();
				continue;
			}
			$reversed[ (string) $seller_id ] = array(
				'reversal_id'   => (string) ( $rev['id'] ?? '' ),
				'reverse_cents' => $reverse_cents,
			);
			$updated[ (string) $seller_id ]['reversed_cents']    = $already_reversed + $reverse_cents;
			$updated[ (string) $seller_id ]['last_reversal_id']  = (string) ( $rev['id'] ?? '' );
		}

		if ( ! empty( $reversed ) ) {
			$order->update_meta_data( '_mnu_seller_transfers', wp_json_encode( $updated ) );
		}
		self::record_recovery( $order, $refund_id, array(
			'status'       => empty( $errors ) ? 'sct_reversed' : 'sct_partial',
			'path'         => 'separate_charges_and_transfers',
			'reversed'     => $reversed,
			'errors'       => $errors,
			'refund_cents' => $refund_cents,
			'is_full'      => $is_full,
			'at'           => gmdate( 'c' ),
		) );

		$count = count( $reversed );
		if ( $count > 0 ) {
			$order->add_order_note( sprintf(
				'Refund guardrail: recovered dashboard refund %s. Reversed transfers for %d seller(s).',
				$refund_id, $count
			) );
		}
		if ( ! empty( $errors ) ) {
			$order->add_order_note( sprintf(
				'Refund guardrail: %d seller transfer reversal(s) failed for dashboard refund %s. Manual reversal required. Details: %s',
				count( $errors ), $refund_id, wp_json_encode( $errors )
			) );
		}
		$order->save();
	}

	/**
	 * Locate the Woo order for a payment intent.
	 */
	private static function locate_order( string $intent_id ): ?WC_Order {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return null;
		}
		$orders = wc_get_orders( array(
			'meta_key'   => '_thenest_stripe_payment_intent',
			'meta_value' => $intent_id,
			'limit'      => 1,
			'return'     => 'objects',
		) );
		if ( empty( $orders ) ) {
			return null;
		}
		return $orders[0] instanceof WC_Order ? $orders[0] : null;
	}

	private static function get_recovered_map( WC_Order $order ): array {
		$raw = $order->get_meta( self::META_KEY, true );
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			return is_array( $decoded ) ? $decoded : array();
		}
		return is_array( $raw ) ? $raw : array();
	}

	private static function record_recovery( WC_Order $order, string $refund_id, array $entry ): void {
		$map = self::get_recovered_map( $order );
		$map[ $refund_id ] = $entry;
		$order->update_meta_data( self::META_KEY, wp_json_encode( $map ) );
		$order->save();
	}
}
