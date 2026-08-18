<?php
/**
 * v3.7.104 — Favorites → seller nudge.
 *
 * Build #5 turns the buyer favorite feature into a growth loop for the
 * seller. The favorites data itself lives in the Trust Suite plugin
 * (wp_tnm_trust_favorites), which as of Trust Suite v1.2.0 fires
 * `tnm_favorite_added` / `tnm_favorite_removed` when a buyer taps the
 * heart. This class listens for those hooks.
 *
 * Behaviour:
 *  - When a buyer favorites a product, we push ONE "someone favorited
 *    your item" notification per (seller, product) per 24h. Otherwise a
 *    trending item on Discovery would generate 30 identical pushes
 *    inside an hour. The suppression is a transient — cheap and it
 *    survives restarts.
 *  - Once per week, the cron rolls up the last 7 days of favorites per
 *    seller into a digest ("3 people favorited your Bumblebee bow this
 *    week — consider boosting it"). If the seller had zero favorites,
 *    no digest is sent. The CTA in the notification deep-links to the
 *    product edit screen, where the mobile app already surfaces the
 *    Boost purchase.
 *  - The self-favorite case (seller likes their own item to test the
 *    UI) is skipped entirely — no notification, no digest count.
 *
 * The digest cadence is weekly, not daily, on purpose: the volume of
 * favorites on a marketplace this size means daily digests would
 * mostly say "1 person favorited X" which reads like noise. A weekly
 * "here's what people liked this week" reads like a report.
 *
 * @package MyNest_Unified_Marketplace
 */

defined( 'ABSPATH' ) || exit;

final class MNU_Favorites_Listener {

	const CRON_HOOK        = 'mnu_favorites_weekly_digest';
	const NUDGE_TRANSIENT  = 'mnu_fav_nudged_'; // + "<seller>_<product>"
	const NUDGE_TTL        = DAY_IN_SECONDS;
	const DIGEST_WINDOW    = WEEK_IN_SECONDS;

	public static function init(): void {
		add_action( 'tnm_favorite_added',   array( __CLASS__, 'on_favorite_added' ), 10, 2 );
		add_action( 'tnm_favorite_removed', array( __CLASS__, 'on_favorite_removed' ), 10, 2 );

		add_action( 'init',           array( __CLASS__, 'schedule_cron' ) );
		add_action( self::CRON_HOOK,  array( __CLASS__, 'run_weekly_digest' ) );
	}

	/**
	 * Handle a favorite-added event. Immediate first-favorite nudge is
	 * rate-limited per (seller, product) via transient so a viral item
	 * doesn't spam the seller.
	 */
	public static function on_favorite_added( int $buyer_id, int $product_id ): void {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return;
		}

		$seller_id = self::product_seller_id( $product );
		if ( ! $seller_id || $seller_id === $buyer_id ) {
			return; // no self-favorite, no orphan products
		}

		$key = self::NUDGE_TRANSIENT . $seller_id . '_' . $product_id;
		if ( get_transient( $key ) ) {
			// Already sent a nudge for this (seller, product) inside the
			// TTL window. The count still increments in the underlying
			// favorites table, and the weekly digest will pick it up.
			return;
		}
		set_transient( $key, 1, self::NUDGE_TTL );

		$count = self::product_favorite_count( $product_id );
		if ( $count < 1 ) {
			// Race with a fast un-favorite. Nothing to nudge about.
			return;
		}

		$title = 'Someone favorited your listing';
		$name  = wp_strip_all_tags( $product->get_name() );
		if ( 1 === $count ) {
			$message = sprintf( 'Your item %s just got its first favorite.', $name );
		} else {
			/* translators: 1: favorite count, 2: product title */
			$message = sprintf( '%1$d people have favorited %2$s. Consider boosting it.', $count, $name );
		}

		$url = function_exists( 'tnm_page_url' ) ? tnm_page_url( 'seller_dashboard' ) : '';

		tnm_notify(
			$seller_id,
			$buyer_id,
			'favorite_added',
			$title,
			$message,
			$product_id,
			'product',
			$url
		);
	}

	/**
	 * Nothing to do on remove today — kept as a public listener so we
	 * can add stats decrement later without another hook rename.
	 */
	public static function on_favorite_removed( int $buyer_id, int $product_id ): void {
		unset( $buyer_id, $product_id );
	}

	/**
	 * Return the seller (product author) ID or 0.
	 */
	private static function product_seller_id( \WC_Product $product ): int {
		$post = get_post( $product->get_id() );
		return $post ? (int) $post->post_author : 0;
	}

	/**
	 * Total favorites for a product, delegated to the Trust Suite when
	 * available and falling back to a direct table read so the unified
	 * marketplace still works if the Trust Suite is deactivated
	 * mid-request.
	 */
	private static function product_favorite_count( int $product_id ): int {
		if ( class_exists( 'TNM_Trust_Favorites' ) ) {
			return (int) TNM_Trust_Favorites::get_favorites_count( $product_id );
		}
		global $wpdb;
		$table = $wpdb->prefix . 'tnm_trust_favorites';
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE product_id = %d", $product_id ) );
	}

	/**
	 * Ensure the weekly digest cron is registered. Runs Monday 09:00
	 * UTC so sellers see the notification when they open the app on a
	 * weekday morning.
	 */
	public static function schedule_cron(): void {
		if ( wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}
		// Next Monday 09:00 UTC.
		$now       = time();
		$next      = strtotime( 'next Monday 09:00 UTC', $now );
		if ( ! $next ) {
			$next = $now + 60;
		}
		wp_schedule_event( $next, 'weekly', self::CRON_HOOK );
	}

	/**
	 * Roll up the last 7 days of favorites per seller and fire one
	 * "your top favorited item this week" notification per seller.
	 */
	public static function run_weekly_digest(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'tnm_trust_favorites';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return; // Trust Suite not installed yet.
		}

		$since = gmdate( 'Y-m-d H:i:s', time() - self::DIGEST_WINDOW );

		// One row per (seller, product) with a week's worth of favorites.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT f.product_id, p.post_author AS seller_id, p.post_title, COUNT(*) AS cnt
				 FROM {$table} f
				 INNER JOIN {$wpdb->posts} p ON p.ID = f.product_id
				 WHERE p.post_type = 'product'
				   AND p.post_status = 'publish'
				   AND f.user_id != p.post_author
				   AND f.created_at >= %s
				 GROUP BY f.product_id, p.post_author
				 ORDER BY seller_id ASC, cnt DESC",
				$since
			),
			ARRAY_A
		);
		if ( ! $rows ) {
			return;
		}

		$per_seller = array();
		foreach ( $rows as $row ) {
			$sid = (int) $row['seller_id'];
			if ( ! $sid ) {
				continue;
			}
			if ( ! isset( $per_seller[ $sid ] ) ) {
				$per_seller[ $sid ] = array( 'total' => 0, 'items' => array() );
			}
			$per_seller[ $sid ]['total']   += (int) $row['cnt'];
			$per_seller[ $sid ]['items'][]  = array(
				'product_id' => (int) $row['product_id'],
				'title'      => (string) $row['post_title'],
				'cnt'        => (int) $row['cnt'],
			);
		}

		$dashboard = function_exists( 'tnm_page_url' ) ? tnm_page_url( 'seller_dashboard' ) : '';

		foreach ( $per_seller as $seller_id => $bucket ) {
			$top   = $bucket['items'][0]; // ordered by cnt DESC per-seller
			$total = $bucket['total'];

			$title = 'Your week on ShopMyNest';
			if ( $total === 1 ) {
				$message = sprintf( '1 person favorited %s this week. Consider boosting it.', wp_strip_all_tags( $top['title'] ) );
			} else {
				$rest = count( $bucket['items'] ) - 1;
				if ( $rest > 0 ) {
					/* translators: 1: total favorites, 2: top product title, 3: other item count */
					$message = sprintf(
						'%1$d favorites this week — most on %2$s. Consider boosting it.',
						$total,
						wp_strip_all_tags( $top['title'] )
					);
				} else {
					$message = sprintf( '%1$d people favorited %2$s this week. Consider boosting it.', $total, wp_strip_all_tags( $top['title'] ) );
				}
			}

			tnm_notify(
				(int) $seller_id,
				0,
				'favorites_digest',
				$title,
				$message,
				(int) $top['product_id'],
				'product',
				$dashboard
			);
		}
	}

	/**
	 * Bulk favorite counts keyed by product_id, safe when the Trust
	 * Suite is missing (returns an empty array).
	 */
	public static function counts_for_products( array $product_ids ): array {
		$product_ids = array_values( array_unique( array_map( 'intval', $product_ids ) ) );
		$product_ids = array_filter( $product_ids );
		if ( ! $product_ids ) {
			return array();
		}
		if ( class_exists( 'TNM_Trust_Favorites' ) ) {
			$raw = TNM_Trust_Favorites::get_favorites_counts_bulk( $product_ids );
			return is_array( $raw ) ? array_map( 'intval', $raw ) : array();
		}
		global $wpdb;
		$table = $wpdb->prefix . 'tnm_trust_favorites';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return array();
		}
		$in   = implode( ',', array_map( 'intval', $product_ids ) );
		$rows = $wpdb->get_results( "SELECT product_id, COUNT(*) AS cnt FROM {$table} WHERE product_id IN ({$in}) GROUP BY product_id", ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r['product_id'] ] = (int) $r['cnt'];
		}
		return $out;
	}
}

MNU_Favorites_Listener::init();
