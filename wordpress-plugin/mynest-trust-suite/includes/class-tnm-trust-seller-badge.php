<?php
/**
 * Feature 2 — Seller Performance Badge.
 * Computes on demand and caches per-seller in a 1-hour transient.
 *
 * @package MyNest_Trust_Suite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TNM_Trust_Seller_Badge {

	const WINDOW_DAYS = 90;

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'tnm_trust_hourly_event', array( __CLASS__, 'noop_cron_hook' ) );
	}

	/**
	 * Placeholder to keep the hourly cron event referenced (badge cache
	 * naturally expires via transient TTL; nothing to actively do here).
	 */
	public static function noop_cron_hook() {
		// Intentionally left blank — badge metrics are computed on demand
		// and cached in a 1-hour transient per seller (see get_badge()).
	}

	/**
	 * Get (and cache) the full badge payload for a seller.
	 *
	 * @param int $seller_id Seller user ID.
	 * @return array
	 */
	public static function get_badge( $seller_id ) {
		$seller_id = absint( $seller_id );

		$cache_key = 'tnm_trust_badge_' . $seller_id;
		$cached    = get_transient( $cache_key );

		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$payload = self::compute_badge( $seller_id );

		set_transient( $cache_key, $payload, HOUR_IN_SECONDS );

		return $payload;
	}

	/**
	 * Compute badge metrics fresh (no cache).
	 *
	 * @param int $seller_id Seller user ID.
	 * @return array
	 */
	protected static function compute_badge( $seller_id ) {
		$metrics = array(
			'on_time_rate'     => null,
			'avg_rating'       => null,
			'response_rate'    => null,
			'completed_orders' => 0,
			'gmv'              => 0.0,
		);

		$orders = self::get_seller_orders( $seller_id, self::WINDOW_DAYS );

		$metrics['completed_orders'] = count( $orders['completed'] );
		$metrics['gmv']               = $orders['gmv'];
		$metrics['on_time_rate']      = self::compute_on_time_rate( $orders['completed'], $seller_id );
		$metrics['avg_rating']        = self::compute_avg_rating( $seller_id );
		$metrics['response_rate']     = self::compute_response_rate( $seller_id );

		$min_orders = absint( get_option( 'tnm_trust_badge_min_orders', 5 ) );
		$min_gmv    = (float) get_option( 'tnm_trust_badge_min_gmv', 300 );

		$meets_minimum_volume = ( $metrics['completed_orders'] >= $min_orders && $metrics['gmv'] >= $min_gmv );

		$tier = 'none';

		if ( $meets_minimum_volume ) {
			$ontime_threshold   = (float) get_option( 'tnm_trust_badge_ontime_threshold', 95 );
			$rating_threshold   = (float) get_option( 'tnm_trust_badge_rating_threshold', 4.8 );
			$response_threshold = (float) get_option( 'tnm_trust_badge_response_threshold', 95 );

			$ontime_ok   = ( null !== $metrics['on_time_rate'] && $metrics['on_time_rate'] >= $ontime_threshold );
			$rating_ok   = ( null !== $metrics['avg_rating'] && $metrics['avg_rating'] >= $rating_threshold );
			$response_ok = ( null === $metrics['response_rate'] || $metrics['response_rate'] >= $response_threshold );

			if ( $ontime_ok && $rating_ok && $response_ok ) {
				$tier = 'trusted_seller';
			} elseif ( ( null !== $metrics['on_time_rate'] && $metrics['on_time_rate'] >= 80 ) || ( null !== $metrics['avg_rating'] && $metrics['avg_rating'] >= 4.3 ) ) {
				$tier = 'rising_seller';
			}
		}

		return array(
			'tier'                  => $tier,
			'tier_label'             => self::tier_label( $tier ),
			'metrics'                => $metrics,
			'meets_minimum_volume'  => $meets_minimum_volume,
		);
	}

	/**
	 * Human-readable label for a tier slug.
	 *
	 * @param string $tier Tier slug.
	 * @return string
	 */
	public static function tier_label( $tier ) {
		switch ( $tier ) {
			case 'trusted_seller':
				return __( 'Trusted Seller', 'nest-trust' );
			case 'rising_seller':
				return __( 'Rising Seller', 'nest-trust' );
			default:
				return __( 'None', 'nest-trust' );
		}
	}

	/**
	 * Fetch orders assigned to a seller within the rolling window, split
	 * into completed vs all, plus GMV total (completed order line totals
	 * attributable to the seller).
	 *
	 * @param int $seller_id  Seller ID.
	 * @param int $window_days Rolling window in days.
	 * @return array{completed: array, all: array, gmv: float}
	 */
	protected static function get_seller_orders( $seller_id, $window_days ) {
		$after = gmdate( 'Y-m-d\TH:i:s', time() - ( $window_days * DAY_IN_SECONDS ) );

		$query_args = array(
			'status'       => array_keys( wc_get_order_statuses() ),
			'date_created' => '>' . strtotime( $after ),
			'limit'        => -1,
			'return'       => 'objects',
		);

		$order_ids = self::query_orders_for_seller_meta( $seller_id, $query_args );

		$completed = array();
		$all       = array();
		$gmv       = 0.0;

		foreach ( $order_ids as $order ) {
			$all[] = $order;

			if ( $order->has_status( array( 'completed', 'processing' ) ) ) {
				$completed[] = $order;
				$gmv        += (float) $order->get_total();
			}
		}

		return array(
			'completed' => $completed,
			'all'       => $all,
			'gmv'       => $gmv,
		);
	}

	/**
	 * Query WC orders and filter to those attributable to a given seller,
	 * using the same defensive meta-key fallback as TNM_Trust_Compat.
	 *
	 * @param int   $seller_id  Seller ID.
	 * @param array $query_args wc_get_orders args.
	 * @return \WC_Order[]
	 */
	protected static function query_orders_for_seller_meta( $seller_id, $query_args ) {
		$matches = array();

		// Try a direct meta_query first for efficiency (works if convention matches).
		foreach ( array( '_seller_id', 'seller_id', '_tnm_seller_id' ) as $meta_key ) {
			$args               = $query_args;
			$args['meta_key']   = $meta_key; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			$args['meta_value'] = $seller_id; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			$args['limit']      = 500;

			$found = wc_get_orders( $args );
			if ( ! empty( $found ) ) {
				foreach ( $found as $order ) {
					$matches[ $order->get_id() ] = $order;
				}
			}
		}

		// Fallback: scan recent orders' line items for a product authored by the seller.
		if ( empty( $matches ) ) {
			$recent_args           = $query_args;
			$recent_args['limit']  = 200;
			$recent_orders          = wc_get_orders( $recent_args );

			foreach ( $recent_orders as $order ) {
				if ( absint( TNM_Trust_Compat::get_order_seller_id( $order ) ) === absint( $seller_id ) ) {
					$matches[ $order->get_id() ] = $order;
				}
			}
		}

		return array_values( $matches );
	}

	/**
	 * On-time shipping rate: % of orders marked complete/shipped within
	 * the seller's stated processing time.
	 *
	 * @param \WC_Order[] $completed_orders Completed orders for this seller.
	 * @param int         $seller_id        Seller ID (unused directly, kept for clarity/future use).
	 * @return float|null Percentage 0-100, or null if no data.
	 */
	protected static function compute_on_time_rate( $completed_orders, $seller_id ) {
		if ( empty( $completed_orders ) ) {
			return null;
		}

		$default_days = absint( get_option( 'tnm_trust_badge_default_processing_days', 3 ) );

		$on_time = 0;
		$total   = 0;

		foreach ( $completed_orders as $order ) {
			$created = $order->get_date_created();
			$paid    = $order->get_date_paid() ? $order->get_date_paid() : $created;
			$completed_date = $order->get_date_completed();

			if ( ! $paid || ! $completed_date ) {
				continue;
			}

			$processing_days = $default_days;

			foreach ( $order->get_items() as $item ) {
				if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) {
					continue;
				}
				$product_id = $item->get_product_id();
				$meta_val   = get_post_meta( $product_id, '_processing_time_days', true );
				if ( is_numeric( $meta_val ) && $meta_val > 0 ) {
					$processing_days = (int) $meta_val;
					break;
				}
			}

			$total++;

			$deadline = $paid->getTimestamp() + ( $processing_days * DAY_IN_SECONDS );
			if ( $completed_date->getTimestamp() <= $deadline ) {
				$on_time++;
			}
		}

		if ( 0 === $total ) {
			return null;
		}

		return round( ( $on_time / $total ) * 100, 2 );
	}

	/**
	 * Average review rating — from the other plugin's reviews table if
	 * present, else WooCommerce product review/comment rating meta.
	 *
	 * @param int $seller_id Seller ID.
	 * @return float|null
	 */
	protected static function compute_avg_rating( $seller_id ) {
		global $wpdb;

		$reviews_table = TNM_Trust_Compat::get_other_plugin_table( 'reviews' );

		if ( null !== $reviews_table ) {
			$columns = $wpdb->get_col( "DESCRIBE {$reviews_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			if ( in_array( 'rating', $columns, true ) && in_array( 'seller_id', $columns, true ) ) {
				$status_clause = '';
				if ( in_array( 'status', $columns, true ) ) {
					$status_clause = "AND status = 'approved'";
				}

				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$avg = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT AVG(rating) FROM {$reviews_table} WHERE seller_id = %d {$status_clause}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$seller_id
					)
				);

				if ( null !== $avg ) {
					return round( (float) $avg, 2 );
				}
			}
		}

		// Fallback: average WooCommerce product ratings across the seller's products.
		$product_ids = self::get_seller_product_ids( $seller_id );
		if ( empty( $product_ids ) ) {
			return null;
		}

		$total_rating = 0.0;
		$total_count  = 0;

		foreach ( $product_ids as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				continue;
			}
			$count = (int) $product->get_review_count();
			if ( $count > 0 ) {
				$total_rating += (float) $product->get_average_rating() * $count;
				$total_count  += $count;
			}
		}

		if ( 0 === $total_count ) {
			return null;
		}

		return round( $total_rating / $total_count, 2 );
	}

	/**
	 * Response rate/time — from the other plugin's messages table if
	 * present, else omitted gracefully (returns null).
	 *
	 * @param int $seller_id Seller ID.
	 * @return float|null Percentage of threads with a first-reply within 24h, or null.
	 */
	protected static function compute_response_rate( $seller_id ) {
		global $wpdb;

		$messages_table = TNM_Trust_Compat::get_other_plugin_table( 'messages' );

		if ( null === $messages_table ) {
			return null;
		}

		$columns = $wpdb->get_col( "DESCRIBE {$messages_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$required = array( 'seller_id', 'created_at' );
		foreach ( $required as $column ) {
			if ( ! in_array( $column, $columns, true ) ) {
				TNM_Trust_Compat::log( "Messages table missing expected column '{$column}' — response rate metric omitted." );
				return null;
			}
		}

		// We can't know the exact thread/sender schema, so this is best-effort:
		// look for a 'thread_id' and a 'sender_id' or 'is_seller' style column.
		if ( ! in_array( 'thread_id', $columns, true ) ) {
			TNM_Trust_Compat::log( 'Messages table missing thread_id column — response rate metric omitted.' );
			return null;
		}

		$sender_column = null;
		foreach ( array( 'sender_id', 'from_user_id', 'user_id' ) as $candidate ) {
			if ( in_array( $candidate, $columns, true ) ) {
				$sender_column = $candidate;
				break;
			}
		}

		if ( null === $sender_column ) {
			TNM_Trust_Compat::log( 'Messages table missing a recognizable sender column — response rate metric omitted.' );
			return null;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$threads = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT thread_id, {$sender_column} AS sender_id, created_at FROM {$messages_table} WHERE seller_id = %d ORDER BY thread_id ASC, created_at ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$seller_id
			),
			ARRAY_A
		);

		if ( empty( $threads ) ) {
			return null;
		}

		$thread_first_buyer_msg = array();
		$thread_first_seller_reply = array();

		foreach ( $threads as $message ) {
			$thread_id = $message['thread_id'];
			$is_seller_sender = ( absint( $message['sender_id'] ) === absint( $seller_id ) );

			if ( ! $is_seller_sender && ! isset( $thread_first_buyer_msg[ $thread_id ] ) ) {
				$thread_first_buyer_msg[ $thread_id ] = strtotime( $message['created_at'] );
			}

			if ( $is_seller_sender && ! isset( $thread_first_seller_reply[ $thread_id ] ) && isset( $thread_first_buyer_msg[ $thread_id ] ) ) {
				$thread_first_seller_reply[ $thread_id ] = strtotime( $message['created_at'] );
			}
		}

		if ( empty( $thread_first_buyer_msg ) ) {
			return null;
		}

		$within_24h = 0;
		$total      = 0;

		foreach ( $thread_first_buyer_msg as $thread_id => $buyer_time ) {
			$total++;
			if ( isset( $thread_first_seller_reply[ $thread_id ] ) ) {
				$reply_delay_hours = ( $thread_first_seller_reply[ $thread_id ] - $buyer_time ) / HOUR_IN_SECONDS;
				if ( $reply_delay_hours >= 0 && $reply_delay_hours <= 24 ) {
					$within_24h++;
				}
			}
		}

		if ( 0 === $total ) {
			return null;
		}

		return round( ( $within_24h / $total ) * 100, 2 );
	}

	/**
	 * Get product IDs authored by / assigned to a seller (defensive lookup).
	 *
	 * @param int $seller_id Seller ID.
	 * @return int[]
	 */
	protected static function get_seller_product_ids( $seller_id ) {
		$seller_id = absint( $seller_id );

		$ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'author'         => $seller_id,
				'fields'         => 'ids',
				'posts_per_page' => 200,
			)
		);

		if ( ! empty( $ids ) ) {
			return $ids;
		}

		foreach ( array( '_seller_id', 'seller_id', '_tnm_seller_id' ) as $meta_key ) {
			$meta_ids = get_posts(
				array(
					'post_type'      => 'product',
					'post_status'    => 'publish',
					'fields'         => 'ids',
					'posts_per_page' => 200,
					'meta_key'       => $meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'     => $seller_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			);
			if ( ! empty( $meta_ids ) ) {
				return $meta_ids;
			}
		}

		return array();
	}

	/**
	 * Flush the cached badge for a seller (e.g. after an admin threshold change).
	 *
	 * @param int $seller_id Seller ID.
	 */
	public static function flush_cache( $seller_id ) {
		delete_transient( 'tnm_trust_badge_' . absint( $seller_id ) );
	}
}
