<?php
/**
 * TNM_Review_Nudge
 *
 * v3.7.108 - Once per day, look for orders whose earliest seller-ship
 * timestamp falls in the 3-7 day window and send the buyer a single "How
 * was your order?" push nudge. We stamp `_tnm_review_push_sent = 1` on the
 * order so the buyer never gets more than one review nudge per order.
 *
 * Hooked to the existing `tnm_daily_maintenance` daily cron. This runs after
 * the ledger sweep and any other daily hooks that are already registered
 * against the same action; we don't need our own event.
 *
 * @package MyNest_Unified_Marketplace
 */

defined( 'ABSPATH' ) || exit;

final class TNM_Review_Nudge {

	public const META_KEY = '_tnm_review_push_sent';

	public static function init(): void {
		add_action( 'tnm_daily_maintenance', array( __CLASS__, 'run' ) );
	}

	/**
	 * Iterate recent orders and push a nudge for any that qualify.
	 *
	 * @param int $max Optional cap for a single run (safety net so a big
	 *                 backlog cannot fan out thousands of push calls at once).
	 */
	public static function run( int $max = 200 ): void {
		if ( ! class_exists( 'MNU_Ops' ) || ! function_exists( 'tnm_get_order_item_seller_id' ) || ! function_exists( 'tnm_table' ) ) {
			return;
		}

		$sent  = 0;
		$now   = time();
		$since = $now - ( 8 * DAY_IN_SECONDS ); // Look back a day past the window so we catch orders paid just before the boundary.

		$order_ids = wc_get_orders(
			array(
				'status'       => array( 'processing', 'completed' ),
				'limit'        => 300,
				'orderby'      => 'date',
				'order'        => 'DESC',
				'date_created' => '>' . gmdate( 'Y-m-d', $since ),
				'return'       => 'ids',
			)
		);
		if ( empty( $order_ids ) ) {
			return;
		}

		foreach ( $order_ids as $order_id ) {
			if ( $sent >= $max ) {
				break;
			}
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			// One-shot dedup.
			if ( '1' === (string) $order->get_meta( self::META_KEY, true ) ) {
				continue;
			}

			$buyer_id = (int) $order->get_customer_id();
			if ( ! $buyer_id ) {
				continue;
			}

			$seller_ids = self::order_seller_ids( $order );
			if ( empty( $seller_ids ) ) {
				continue;
			}

			// Earliest per-seller shipped_at across the order.
			$shipped_at = self::earliest_shipped_at( $order, $seller_ids );
			if ( ! $shipped_at ) {
				continue;
			}
			$age_days = ( $now - $shipped_at ) / DAY_IN_SECONDS;
			if ( $age_days < 3 || $age_days > 7 ) {
				continue;
			}

			// Skip when the buyer has already reviewed every seller on the order.
			if ( ! self::has_unreviewed_sellers( $buyer_id, (int) $order->get_id(), $seller_ids ) ) {
				$order->update_meta_data( self::META_KEY, '1' );
				$order->save();
				continue;
			}

			$primary_seller_id = (int) $seller_ids[0];
			$shop_name         = function_exists( 'tnm_seller_display_name' ) ? tnm_seller_display_name( $primary_seller_id ) : 'your seller';
			$title             = 'Rate your order';
			$body              = count( $seller_ids ) > 1
				? 'How was your recent MyNest order? Leave a quick review for each seller.'
				: sprintf( 'How was your order from %s? Leave a quick review.', $shop_name );

			MNU_Ops::notify_user(
				$buyer_id,
				$title,
				$body,
				array(
					'type'     => 'review_prompt',
					'order_id' => (int) $order->get_id(),
				)
			);

			$order->update_meta_data( self::META_KEY, '1' );
			$order->save();
			$sent++;
		}
	}

	/**
	 * @param WC_Order $order
	 * @return int[]
	 */
	private static function order_seller_ids( WC_Order $order ): array {
		$out = array();
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$sid = (int) tnm_get_order_item_seller_id( $item );
			if ( $sid > 0 ) {
				$out[ $sid ] = true;
			}
		}
		return array_map( 'intval', array_keys( $out ) );
	}

	/**
	 * Earliest per-seller shipped_at across all sellers on the order, as a
	 * unix timestamp, or 0 when no seller has stamped a shipped_at yet.
	 *
	 * @param WC_Order $order
	 * @param int[]    $seller_ids
	 */
	private static function earliest_shipped_at( WC_Order $order, array $seller_ids ): int {
		$earliest = 0;
		foreach ( $seller_ids as $sid ) {
			$raw = (string) $order->get_meta( '_tnm_seller_shipped_at_' . $sid, true );
			if ( '' === $raw ) {
				continue;
			}
			$ts = strtotime( $raw . ' UTC' );
			if ( $ts && ( ! $earliest || $ts < $earliest ) ) {
				$earliest = $ts;
			}
		}
		return $earliest;
	}

	/**
	 * True when the buyer has not yet reviewed at least one seller on the
	 * order. Uses the same table + uniqueness key as TNM_Social.
	 *
	 * @param int   $buyer_id
	 * @param int   $order_id
	 * @param int[] $seller_ids
	 */
	private static function has_unreviewed_sellers( int $buyer_id, int $order_id, array $seller_ids ): bool {
		if ( empty( $seller_ids ) ) {
			return false;
		}
		global $wpdb;
		$table        = tnm_table( 'reviews' );
		$placeholders = implode( ',', array_fill( 0, count( $seller_ids ), '%d' ) );
		$params       = array_merge( array( $buyer_id, $order_id ), array_map( 'intval', $seller_ids ) );
		$sql          = "SELECT COUNT(DISTINCT seller_id) FROM {$table} WHERE reviewer_id = %d AND order_id = %d AND seller_id IN ({$placeholders})";
		$reviewed     = (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
		return $reviewed < count( $seller_ids );
	}
}
