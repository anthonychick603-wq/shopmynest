<?php
/**
 * MNU Lifecycle — cascade cleanup for marketplace custom tables.
 *
 * When a shop_order, product, or user is trashed or permanently deleted,
 * WordPress does NOT touch marketplace-specific rows in mnu_ledger,
 * mnu_payouts, mnu_follows, mnu_notifications, mnu_messages, mnu_reviews,
 * or mnu_import_jobs. Left alone these rows accumulate as orphans that
 * skew seller earnings, notifications, and support queries.
 *
 * This class:
 *   1. Registers delete hooks that cascade into the marketplace tables
 *      whenever WordPress trashes or hard-deletes a shop_order / product
 *      or when a user account is deleted.
 *   2. Runs a one-time sweep on activation/upgrade that removes rows
 *      already orphaned by past trashings before this file existed.
 *
 * Financial-safety rules:
 *   - mnu_ledger rows with status IN (paid, reserved) are NEVER deleted.
 *     They're audit records for money that already moved. On cascade, they
 *     are annotated with a note instead.
 *   - mnu_payouts with status = 'paid' are likewise preserved.
 *
 * @package MyNest_Unified_Marketplace
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MNU_Lifecycle {

	/** Sweep version — bump to re-run the one-time cleanup on upgrade. */
	private const SWEEP_VERSION = 1;

	/** Option key that records the last sweep version run against this DB. */
	private const SWEEP_OPTION = 'mnu_lifecycle_sweep_version';

	public static function init(): void {
		// Cascade on trash: WordPress moves the post to trash but keeps it in
		// wp_posts. We still clean marketplace rows because pending earnings
		// tied to a trashed order should not accrue as if the order were live.
		add_action( 'wp_trash_post', array( __CLASS__, 'cascade_post_trashed' ), 20 );

		// Cascade on hard delete: after this the post is gone from wp_posts,
		// so any surviving rows referencing it become permanent orphans.
		add_action( 'before_delete_post', array( __CLASS__, 'cascade_post_deleted' ), 20 );

		// Cascade on user deletion. Runs BEFORE the user row is dropped so
		// that reassignments in mnu_ledger etc. still resolve.
		add_action( 'delete_user', array( __CLASS__, 'cascade_user_deleted' ), 20, 1 );

		// One-time sweep for pre-existing orphans, gated on SWEEP_VERSION.
		add_action( 'init', array( __CLASS__, 'maybe_run_sweep' ), 50 );
	}

	/* ------------------------------------------------------------------
	 * Cascade entry points
	 * ------------------------------------------------------------------ */

	public static function cascade_post_trashed( int $post_id ): void {
		$type = get_post_type( $post_id );
		if ( 'shop_order' === $type ) {
			self::cascade_order( $post_id, false );
		} elseif ( 'product' === $type ) {
			self::cascade_product( $post_id, false );
		}
	}

	public static function cascade_post_deleted( int $post_id ): void {
		$type = get_post_type( $post_id );
		if ( 'shop_order' === $type ) {
			self::cascade_order( $post_id, true );
		} elseif ( 'product' === $type ) {
			self::cascade_product( $post_id, true );
		}
	}

	public static function cascade_user_deleted( int $user_id ): void {
		if ( $user_id <= 0 ) {
			return;
		}
		global $wpdb;

		// Ledger — retain paid/reserved rows for audit, drop everything else.
		self::safe_query(
			$wpdb->prepare(
				'DELETE FROM ' . tnm_table( 'ledger' ) . " WHERE seller_id=%d AND status NOT IN ('paid','reserved')",
				$user_id
			)
		);

		// Payouts — retain paid rows.
		self::safe_query(
			$wpdb->prepare(
				'DELETE FROM ' . tnm_table( 'payouts' ) . " WHERE seller_id=%d AND status <> 'paid'",
				$user_id
			)
		);

		// Follows — remove edges in both directions.
		self::safe_query(
			$wpdb->prepare(
				'DELETE FROM ' . tnm_table( 'follows' ) . ' WHERE follower_id=%d OR following_id=%d',
				$user_id,
				$user_id
			)
		);

		// Notifications — user is recipient or actor.
		self::safe_query(
			$wpdb->prepare(
				'DELETE FROM ' . tnm_table( 'notifications' ) . ' WHERE user_id=%d OR actor_id=%d',
				$user_id,
				$user_id
			)
		);

		// Messages — either party.
		self::safe_query(
			$wpdb->prepare(
				'DELETE FROM ' . tnm_table( 'messages' ) . ' WHERE sender_id=%d OR recipient_id=%d',
				$user_id,
				$user_id
			)
		);

		// Reviews — either party.
		self::safe_query(
			$wpdb->prepare(
				'DELETE FROM ' . tnm_table( 'reviews' ) . ' WHERE reviewer_id=%d OR seller_id=%d',
				$user_id,
				$user_id
			)
		);

		// Import jobs.
		self::safe_query(
			$wpdb->prepare(
				'DELETE FROM ' . $wpdb->prefix . 'mnu_import_jobs WHERE seller_id=%d',
				$user_id
			)
		);
	}

	/* ------------------------------------------------------------------
	 * Order + product cascades
	 * ------------------------------------------------------------------ */

	/**
	 * @param bool $hard True when the order is being permanently deleted.
	 *                   Trash-only cascades leave ledger data alone unless the
	 *                   entry is still in a pre-earnings state.
	 */
	private static function cascade_order( int $order_id, bool $hard ): void {
		global $wpdb;

		// Ledger — always retain paid/reserved. On hard delete, also drop
		// available/pending/void/refunded rows since the order will no longer
		// exist. On trash, drop only pending rows (never accrued to seller yet).
		$statuses_to_drop = $hard
			? "'pending','available','void','refunded'"
			: "'pending'";
		self::safe_query(
			$wpdb->prepare(
				'DELETE FROM ' . tnm_table( 'ledger' ) . ' WHERE order_id=%d AND status IN (' . $statuses_to_drop . ')',
				$order_id
			)
		);

		// Annotate any surviving ledger rows so the audit trail records the
		// underlying order is gone/trashed and support can find it later.
		$note = $hard
			? 'Order permanently deleted at ' . current_time( 'mysql', true )
			: 'Order trashed at ' . current_time( 'mysql', true );
		self::safe_query(
			$wpdb->prepare(
				'UPDATE ' . tnm_table( 'ledger' ) . " SET note = CONCAT(COALESCE(note,''), %s), updated_at=%s WHERE order_id=%d AND status IN ('paid','reserved','available','refunded','void')",
				"\n" . $note,
				current_time( 'mysql', true ),
				$order_id
			)
		);

		// Notifications tied to the order.
		self::safe_query(
			$wpdb->prepare(
				'DELETE FROM ' . tnm_table( 'notifications' ) . " WHERE object_id=%d AND object_type IN ('order','shop_order')",
				$order_id
			)
		);

		// Reviews are anchored to (reviewer, seller, order). Removing the
		// order removes the ability to authenticate the review.
		self::safe_query(
			$wpdb->prepare(
				'DELETE FROM ' . tnm_table( 'reviews' ) . ' WHERE order_id=%d',
				$order_id
			)
		);
	}

	private static function cascade_product( int $product_id, bool $hard ): void {
		global $wpdb;

		// Notifications tied to the product.
		self::safe_query(
			$wpdb->prepare(
				'DELETE FROM ' . tnm_table( 'notifications' ) . " WHERE object_id=%d AND object_type='product'",
				$product_id
			)
		);

		// Ledger keys off order_id + order_item_id, not product_id, so nothing
		// to do there — an already-created ledger entry survives even when the
		// underlying product is deleted, which matches historical WooCommerce
		// behavior (line items keep the product snapshot).
	}

	/* ------------------------------------------------------------------
	 * One-time sweep for pre-existing orphans
	 * ------------------------------------------------------------------ */

	public static function maybe_run_sweep(): void {
		if ( ! function_exists( 'tnm_table' ) ) {
			return;
		}
		$last = (int) get_option( self::SWEEP_OPTION, 0 );
		if ( $last >= self::SWEEP_VERSION ) {
			return;
		}
		self::run_sweep();
		update_option( self::SWEEP_OPTION, self::SWEEP_VERSION, false );
	}

	/**
	 * Remove rows in marketplace tables whose referenced order/product/user
	 * no longer exists in WordPress. Safe to run repeatedly.
	 */
	private static function run_sweep(): void {
		global $wpdb;

		$posts    = $wpdb->posts;
		$users    = $wpdb->users;
		$ledger   = tnm_table( 'ledger' );
		$payouts  = tnm_table( 'payouts' );
		$follows  = tnm_table( 'follows' );
		$notifs   = tnm_table( 'notifications' );
		$msgs     = tnm_table( 'messages' );
		$reviews  = tnm_table( 'reviews' );
		$imports  = $wpdb->prefix . 'mnu_import_jobs';

		// Ledger — non-financial rows tied to orders that no longer exist.
		self::safe_query(
			"DELETE l FROM {$ledger} l
			 LEFT JOIN {$posts} p ON p.ID = l.order_id
			 WHERE p.ID IS NULL AND l.status NOT IN ('paid','reserved')"
		);

		// Reviews — anchored to orders that no longer exist.
		self::safe_query(
			"DELETE r FROM {$reviews} r
			 LEFT JOIN {$posts} p ON p.ID = r.order_id
			 WHERE p.ID IS NULL"
		);

		// Notifications tied to gone orders.
		self::safe_query(
			"DELETE n FROM {$notifs} n
			 LEFT JOIN {$posts} p ON p.ID = n.object_id
			 WHERE n.object_type IN ('order','shop_order') AND p.ID IS NULL"
		);
		// Notifications tied to gone products.
		self::safe_query(
			"DELETE n FROM {$notifs} n
			 LEFT JOIN {$posts} p ON p.ID = n.object_id
			 WHERE n.object_type='product' AND p.ID IS NULL"
		);

		// Follows — either side references a user that no longer exists.
		self::safe_query(
			"DELETE f FROM {$follows} f
			 LEFT JOIN {$users} u1 ON u1.ID = f.follower_id
			 LEFT JOIN {$users} u2 ON u2.ID = f.following_id
			 WHERE u1.ID IS NULL OR u2.ID IS NULL"
		);

		// Messages — either party gone.
		self::safe_query(
			"DELETE m FROM {$msgs} m
			 LEFT JOIN {$users} u1 ON u1.ID = m.sender_id
			 LEFT JOIN {$users} u2 ON u2.ID = m.recipient_id
			 WHERE u1.ID IS NULL OR u2.ID IS NULL"
		);

		// Payouts — seller gone AND not paid.
		self::safe_query(
			"DELETE po FROM {$payouts} po
			 LEFT JOIN {$users} u ON u.ID = po.seller_id
			 WHERE u.ID IS NULL AND po.status <> 'paid'"
		);

		// Ledger — seller gone AND not paid/reserved.
		self::safe_query(
			"DELETE l FROM {$ledger} l
			 LEFT JOIN {$users} u ON u.ID = l.seller_id
			 WHERE u.ID IS NULL AND l.status NOT IN ('paid','reserved')"
		);

		// Import jobs — seller gone.
		self::safe_query(
			"DELETE ij FROM {$imports} ij
			 LEFT JOIN {$users} u ON u.ID = ij.seller_id
			 WHERE u.ID IS NULL"
		);
	}

	/* ------------------------------------------------------------------
	 * Utility
	 * ------------------------------------------------------------------ */

	private static function safe_query( string $sql ): void {
		global $wpdb;
		// Suppress errors to keep post/user deletion from failing if a query
		// hits an unexpected schema state; the failure is still visible to
		// developers via the WPDB last_error attribute.
		$wpdb->hide_errors();
		$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL
	}
}
