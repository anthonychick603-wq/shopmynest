<?php
/**
 * Feature 3b — Personalized Feed Ranking.
 *
 * New endpoint only — does NOT override the other plugin's existing
 * `/feed` route. Sites/apps should switch to this URL to get personalization.
 *
 * @package MyNest_Trust_Suite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TNM_Trust_Feed {

	/**
	 * Hook registration.
	 */
	public static function init() {
		// No hooks needed beyond REST registration (handled in class-tnm-trust-rest.php)
		// and the shortcode (handled in class-tnm-trust-shortcodes.php).
	}

	/**
	 * Default feed ranking weights, filterable via `tnm_trust_feed_weights`.
	 *
	 * @return array
	 */
	public static function get_weights() {
		$defaults = get_option(
			'tnm_trust_feed_weights',
			array(
				'recency'   => 1.0,
				'favorites' => 1.5,
				'follows'   => 2.0,
				'badge'     => 2.5,
				'sales'     => 1.0,
				'boost'     => 5.0,
			)
		);

		if ( ! is_array( $defaults ) ) {
			$defaults = array();
		}

		/**
		 * Filter the personalized feed ranking weights.
		 *
		 * @param array $weights Associative array of weight name => float weight.
		 */
		return apply_filters( 'tnm_trust_feed_weights', $defaults );
	}

	/**
	 * Build a ranked, paginated product feed for a given (optional) user.
	 *
	 * @param array $args {
	 *     @type int    $user_id  Requesting user ID (0 if guest).
	 *     @type int    $page     Page number (1-indexed).
	 *     @type int    $per_page Items per page.
	 *     @type string $category Optional category slug filter.
	 * }
	 * @return array{items: array, page: int, per_page: int, total: int}
	 */
	public static function get_feed( $args ) {
		$user_id  = isset( $args['user_id'] ) ? absint( $args['user_id'] ) : 0;
		$page     = isset( $args['page'] ) ? max( 1, absint( $args['page'] ) ) : 1;
		$per_page = isset( $args['per_page'] ) ? max( 1, min( 50, absint( $args['per_page'] ) ) ) : 20;
		$category = isset( $args['category'] ) ? sanitize_title( $args['category'] ) : '';

		$candidate_pool_size = 300;

		$query_args = array(
			'status'    => 'publish',
			'limit'     => $candidate_pool_size,
			'orderby'   => 'date',
			'order'     => 'DESC',
			'return'    => 'ids',
		);

		if ( $category ) {
			$query_args['category'] = array( $category );
		}

		$product_ids = wc_get_products( $query_args );

		if ( empty( $product_ids ) ) {
			return array(
				'items'    => array(),
				'page'     => $page,
				'per_page' => $per_page,
				'total'    => 0,
			);
		}

		$scored = self::score_products( $product_ids, $user_id );

		usort(
			$scored,
			function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);

		$total  = count( $scored );
		$offset = ( $page - 1 ) * $per_page;
		$page_items = array_slice( $scored, $offset, $per_page );

		$items = array();
		foreach ( $page_items as $entry ) {
			$product = wc_get_product( $entry['product_id'] );
			if ( ! $product ) {
				continue;
			}
			$items[] = self::format_product_for_feed( $product, $entry['score'] );
		}

		return array(
			'items'    => $items,
			'page'     => $page,
			'per_page' => $per_page,
			'total'    => $total,
		);
	}

	/**
	 * Score a set of product IDs for the given user.
	 *
	 * @param int[] $product_ids Product IDs.
	 * @param int   $user_id     Requesting user (0 = guest).
	 * @return array List of array{product_id, score}.
	 */
	protected static function score_products( $product_ids, $user_id ) {
		$weights = self::get_weights();

		$favorites_counts = TNM_Trust_Favorites::get_favorites_counts_bulk( $product_ids );
		$followed_sellers  = $user_id ? self::get_followed_sellers( $user_id ) : array();
		$boosted_products  = self::get_actively_boosted_product_ids( $product_ids );

		$max_favorites = max( 1, ! empty( $favorites_counts ) ? max( $favorites_counts ) : 1 );

		$scored = array();

		foreach ( $product_ids as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				continue;
			}

			$score = 0.0;

			// Recency (0-1 normalized over 90 days).
			$created_ts = $product->get_date_created() ? $product->get_date_created()->getTimestamp() : 0;
			$age_days   = $created_ts ? ( time() - $created_ts ) / DAY_IN_SECONDS : 90;
			$recency    = max( 0, 1 - ( $age_days / 90 ) );
			$score      += $recency * (float) ( $weights['recency'] ?? 0 );

			// Favorites (normalized).
			$fav_count = isset( $favorites_counts[ $product_id ] ) ? $favorites_counts[ $product_id ] : 0;
			$score     += ( $fav_count / $max_favorites ) * (float) ( $weights['favorites'] ?? 0 );

			// Follows: does the requesting user follow this product's seller?
			$seller_id = TNM_Trust_Compat::get_product_seller_id( $product_id );
			if ( $seller_id && in_array( $seller_id, $followed_sellers, true ) ) {
				$score += (float) ( $weights['follows'] ?? 0 );
			}

			// Seller badge tier.
			if ( $seller_id ) {
				$badge = TNM_Trust_Seller_Badge::get_badge( $seller_id );
				if ( 'trusted_seller' === $badge['tier'] ) {
					$score += (float) ( $weights['badge'] ?? 0 );
				} elseif ( 'rising_seller' === $badge['tier'] ) {
					$score += 0.5 * (float) ( $weights['badge'] ?? 0 );
				}
			}

			// Total sales (normalized via log scale to avoid runaway bestsellers dominating).
			$total_sales = (int) $product->get_total_sales();
			$score       += ( log( 1 + $total_sales ) / 10 ) * (float) ( $weights['sales'] ?? 0 );

			// Active boost.
			if ( in_array( $product_id, $boosted_products, true ) ) {
				$score += (float) ( $weights['boost'] ?? 0 );
			}

			$scored[] = array(
				'product_id' => $product_id,
				'score'      => $score,
			);
		}

		return $scored;
	}

	/**
	 * Defensively read the other plugin's `follows` table to find which
	 * seller IDs a user follows.
	 *
	 * @param int $user_id User ID.
	 * @return int[] Seller IDs followed.
	 */
	protected static function get_followed_sellers( $user_id ) {
		global $wpdb;

		$follows_table = TNM_Trust_Compat::get_other_plugin_table( 'follows' );
		if ( null === $follows_table ) {
			return array();
		}

		$columns = $wpdb->get_col( "DESCRIBE {$follows_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$follower_column = null;
		foreach ( array( 'user_id', 'follower_id', 'buyer_id' ) as $candidate ) {
			if ( in_array( $candidate, $columns, true ) ) {
				$follower_column = $candidate;
				break;
			}
		}

		$seller_column = null;
		foreach ( array( 'seller_id', 'followed_id', 'followee_id' ) as $candidate ) {
			if ( in_array( $candidate, $columns, true ) ) {
				$seller_column = $candidate;
				break;
			}
		}

		if ( null === $follower_column || null === $seller_column ) {
			TNM_Trust_Compat::log( 'Follows table missing recognizable columns — skipping follows weight.' );
			return array();
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT {$seller_column} FROM {$follows_table} WHERE {$follower_column} = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				absint( $user_id )
			)
		);

		return array_map( 'absint', (array) $rows );
	}

	/**
	 * Get the subset of the given product IDs that currently have an
	 * active boost (Feature 6), from this plugin's own boosts table.
	 *
	 * @param int[] $product_ids Product IDs to check.
	 * @return int[]
	 */
	protected static function get_actively_boosted_product_ids( $product_ids ) {
		global $wpdb;

		$product_ids = array_filter( array_map( 'absint', (array) $product_ids ) );
		if ( empty( $product_ids ) ) {
			return array();
		}

		$table        = TNM_Trust_DB::table( 'boosts' );
		$placeholders = implode( ',', array_fill( 0, count( $product_ids ), '%d' ) );

		$values = array_merge( array( 'active' ), $product_ids, array( current_time( 'mysql', true ) ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT product_id FROM {$table} WHERE status = %s AND product_id IN ({$placeholders}) AND (expires_at IS NULL OR expires_at >= %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$values
			)
		);

		return array_map( 'absint', (array) $rows );
	}

	/**
	 * Format a WC_Product for feed JSON output.
	 *
	 * @param \WC_Product $product Product.
	 * @param float       $score   Computed ranking score.
	 * @return array
	 */
	protected static function format_product_for_feed( $product, $score ) {
		$image_id  = $product->get_image_id();
		$image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'medium' ) : wc_placeholder_img_src();

		return array(
			'id'              => $product->get_id(),
			'name'            => $product->get_name(),
			'permalink'       => get_permalink( $product->get_id() ),
			'price_html'      => $product->get_price_html(),
			'price'           => $product->get_price(),
			'image'           => $image_url,
			'favorites_count' => TNM_Trust_Favorites::get_favorites_count( $product->get_id() ),
			'seller_id'       => TNM_Trust_Compat::get_product_seller_id( $product->get_id() ),
			'score'           => round( (float) $score, 4 ),
		);
	}
}
