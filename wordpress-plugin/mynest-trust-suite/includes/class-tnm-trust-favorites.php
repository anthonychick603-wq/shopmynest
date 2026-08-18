<?php
/**
 * Feature 3a — Favorites.
 *
 * @package MyNest_Trust_Suite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TNM_Trust_Favorites {

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'woocommerce_after_shop_loop_item', array( __CLASS__, 'render_auto_favorite_button' ), 15 );
		add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'render_auto_favorite_button' ), 31 );
	}

	/**
	 * Auto-inject the favorite button on shop loop items / single product summary.
	 */
	public static function render_auto_favorite_button() {
		global $product;

		if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
			return;
		}

		echo self::render_button( $product->get_id() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_button() escapes internally.
	}

	/**
	 * Render the favorite heart button markup for a given product.
	 *
	 * @param int $product_id Product ID.
	 * @return string HTML.
	 */
	public static function render_button( $product_id ) {
		$product_id = absint( $product_id );
		if ( ! $product_id ) {
			return '';
		}

		$is_favorited = is_user_logged_in() && self::is_favorited( get_current_user_id(), $product_id );
		$count         = self::get_favorites_count( $product_id );

		ob_start();
		?>
		<button
			type="button"
			class="tnm-trust-favorite-btn<?php echo $is_favorited ? ' is-favorited' : ''; ?>"
			data-product-id="<?php echo esc_attr( $product_id ); ?>"
			aria-pressed="<?php echo $is_favorited ? 'true' : 'false'; ?>"
			aria-label="<?php esc_attr_e( 'Add to favorites', 'nest-trust' ); ?>"
		>
			<svg viewBox="0 0 24 24" width="20" height="20" class="tnm-trust-heart-icon" aria-hidden="true">
				<path d="M12 21s-6.7-4.35-9.33-8.2C.86 10.1 1.4 6.6 4.2 5.02c2.28-1.28 4.87-.6 6.3 1.32.4.53.8 1.16 1.5 1.16.7 0 1.1-.63 1.5-1.16 1.43-1.92 4.02-2.6 6.3-1.32 2.8 1.58 3.34 5.08 1.53 7.78C18.7 16.65 12 21 12 21z" fill="currentColor"></path>
			</svg>
			<span class="tnm-trust-favorite-count"><?php echo esc_html( $count ); ?></span>
		</button>
		<?php
		return ob_get_clean();
	}

	/**
	 * Whether a user has favorited a product.
	 *
	 * @param int $user_id    User ID.
	 * @param int $product_id Product ID.
	 * @return bool
	 */
	public static function is_favorited( $user_id, $product_id ) {
		global $wpdb;

		$table = TNM_Trust_DB::table( 'favorites' );

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND product_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				absint( $user_id ),
				absint( $product_id )
			)
		);

		return absint( $count ) > 0;
	}

	/**
	 * Toggle a favorite for a user/product. Returns the new state.
	 *
	 * @param int $user_id    User ID.
	 * @param int $product_id Product ID.
	 * @return array{favorited: bool, count: int}|WP_Error
	 */
	public static function toggle( $user_id, $product_id ) {
		global $wpdb;

		$user_id    = absint( $user_id );
		$product_id = absint( $product_id );

		if ( ! $user_id || ! $product_id ) {
			return new WP_Error( 'tnm_trust_invalid_request', __( 'A valid product ID is required.', 'nest-trust' ), array( 'status' => 400 ) );
		}

		if ( ! get_post( $product_id ) || 'product' !== get_post_type( $product_id ) ) {
			return new WP_Error( 'tnm_trust_invalid_product', __( 'Product not found.', 'nest-trust' ), array( 'status' => 404 ) );
		}

		$table = TNM_Trust_DB::table( 'favorites' );

		if ( self::is_favorited( $user_id, $product_id ) ) {
			$wpdb->delete(
				$table,
				array(
					'user_id'    => $user_id,
					'product_id' => $product_id,
				),
				array( '%d', '%d' )
			);
			$favorited = false;
			/**
			 * v1.2.0 - Fires when a buyer un-favorites a product.
			 *
			 * Consumed by the unified marketplace for stat rollups. No
			 * notification is emitted on un-favorite.
			 */
			do_action( 'tnm_favorite_removed', $user_id, $product_id );
		} else {
			$wpdb->insert(
				$table,
				array(
					'user_id'    => $user_id,
					'product_id' => $product_id,
					'created_at' => current_time( 'mysql', true ),
				),
				array( '%d', '%d', '%s' )
			);
			$favorited = true;
			/**
			 * v1.2.0 - Fires when a buyer favorites a product.
			 *
			 * The unified marketplace listens for this and pushes a
			 * rate-limited "someone favorited your item" notification
			 * to the seller.
			 */
			do_action( 'tnm_favorite_added', $user_id, $product_id );
		}

		return array(
			'favorited' => $favorited,
			'count'     => self::get_favorites_count( $product_id ),
		);
	}

	/**
	 * Remove a favorite explicitly (DELETE route).
	 *
	 * @param int $user_id    User ID.
	 * @param int $product_id Product ID.
	 * @return array{favorited: bool, count: int}
	 */
	public static function remove( $user_id, $product_id ) {
		global $wpdb;

		$user_id    = absint( $user_id );
		$product_id = absint( $product_id );
		$table      = TNM_Trust_DB::table( 'favorites' );

		$rows = $wpdb->delete(
			$table,
			array(
				'user_id'    => $user_id,
				'product_id' => $product_id,
			),
			array( '%d', '%d' )
		);

		// v1.2.0 - Fire only if we actually removed a row so listeners
		// don't decrement stats that were never incremented.
		if ( $rows ) {
			do_action( 'tnm_favorite_removed', $user_id, $product_id );
		}

		return array(
			'favorited' => false,
			'count'     => self::get_favorites_count( $product_id ),
		);
	}

	/**
	 * Get a user's favorite product IDs (most recent first).
	 *
	 * @param int $user_id User ID.
	 * @return array
	 */
	public static function get_user_favorites( $user_id ) {
		global $wpdb;

		$table = TNM_Trust_DB::table( 'favorites' );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT product_id, created_at FROM {$table} WHERE user_id = %d ORDER BY created_at DESC LIMIT 500", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				absint( $user_id )
			),
			ARRAY_A
		);

		if ( ! $rows ) {
			return array();
		}

		return array_map(
			function ( $row ) {
				return array(
					'product_id' => absint( $row['product_id'] ),
					'created_at' => $row['created_at'],
				);
			},
			$rows
		);
	}

	/**
	 * Get the total favorites count for a product.
	 *
	 * @param int $product_id Product ID.
	 * @return int
	 */
	public static function get_favorites_count( $product_id ) {
		global $wpdb;

		$table = TNM_Trust_DB::table( 'favorites' );

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE product_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				absint( $product_id )
			)
		);

		return absint( $count );
	}

	/**
	 * Get favorites counts for many products at once (used by feed ranking).
	 *
	 * @param int[] $product_ids Product IDs.
	 * @return array<int,int> product_id => count.
	 */
	public static function get_favorites_counts_bulk( $product_ids ) {
		global $wpdb;

		$product_ids = array_filter( array_map( 'absint', (array) $product_ids ) );
		if ( empty( $product_ids ) ) {
			return array();
		}

		$table        = TNM_Trust_DB::table( 'favorites' );
		$placeholders = implode( ',', array_fill( 0, count( $product_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT product_id, COUNT(*) AS cnt FROM {$table} WHERE product_id IN ({$placeholders}) GROUP BY product_id", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$product_ids
			),
			ARRAY_A
		);

		$result = array();
		foreach ( $product_ids as $pid ) {
			$result[ $pid ] = 0;
		}

		if ( $rows ) {
			foreach ( $rows as $row ) {
				$result[ absint( $row['product_id'] ) ] = absint( $row['cnt'] );
			}
		}

		return $result;
	}
}
