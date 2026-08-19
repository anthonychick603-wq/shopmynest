<?php
/**
 * v3.7.120 (Build #14) — Wishlist price-drop alerts.
 *
 * The favorites table (`wp_tnm_trust_favorites`) already exists in the
 * Trust Suite plugin, but it only stores (user_id, product_id). To detect
 * a price drop we need to remember what the buyer saw when they favorited
 * the product, so we can compare against the current price on any save.
 *
 * Storage: a per-user meta key `mnu_fav_price_map` — an associative array
 * of product_id => last_seen_price. Kept in user_meta (not a new table)
 * because the working set per user is small (favorites cap in practice
 * is a few hundred rows) and it lets us reuse WP's built-in autoload cache.
 *
 * Behaviour:
 *  - On `tnm_favorite_added`: record the product's current price in the
 *    buyer's price map. If already recorded (buyer re-favorited after
 *    unfavoriting) we OVERWRITE — the intent is "watch from this price
 *    onward", not "remember what you saw six months ago".
 *  - On `tnm_favorite_removed`: drop the entry from the map. No point
 *    keeping stale price history for products the buyer no longer cares
 *    about.
 *  - On `woocommerce_product_object_updated_props` (fires when a product
 *    is saved via the app or admin): find every user who has favorited
 *    this product AND has a recorded last_seen_price higher than the new
 *    price. Send a push + inbox notification, then update their stored
 *    last_seen_price so we don't alert repeatedly for the same drop.
 *
 * Per-user opt-out: users can disable price-drop alerts by setting
 * `mnu_price_drop_alerts` to '0' in user_meta. Default (unset or '1')
 * = enabled. The mobile client exposes a toggle on the Favorites screen.
 *
 * @package MyNest_Unified_Marketplace
 */

defined( 'ABSPATH' ) || exit;

final class MNU_Price_Drop {

	const USER_META_MAP    = 'mnu_fav_price_map';
	const USER_META_OPT_IN = 'mnu_price_drop_alerts';

	public static function init(): void {
		add_action( 'tnm_favorite_added',   array( __CLASS__, 'on_favorite_added' ), 10, 2 );
		add_action( 'tnm_favorite_removed', array( __CLASS__, 'on_favorite_removed' ), 10, 2 );
		// woocommerce_product_object_updated_props fires after WC_Product
		// finishes saving updated properties, which is where price changes
		// are actually committed. Cheaper than hooking save_post_product.
		add_action( 'woocommerce_product_object_updated_props', array( __CLASS__, 'on_product_updated' ), 20, 2 );
	}

	public static function on_favorite_added( int $buyer_id, int $product_id ): void {
		if ( ! $buyer_id || ! $product_id ) {
			return;
		}
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return;
		}
		$price = self::current_price( $product );
		if ( $price <= 0 ) {
			return;
		}
		$map                    = self::get_map( $buyer_id );
		$map[ $product_id ]     = $price;
		update_user_meta( $buyer_id, self::USER_META_MAP, $map );
	}

	public static function on_favorite_removed( int $buyer_id, int $product_id ): void {
		if ( ! $buyer_id || ! $product_id ) {
			return;
		}
		$map = self::get_map( $buyer_id );
		if ( isset( $map[ $product_id ] ) ) {
			unset( $map[ $product_id ] );
			update_user_meta( $buyer_id, self::USER_META_MAP, $map );
		}
	}

	/**
	 * Product save fan-out. WooCommerce passes the WC_Product plus an
	 * array of updated prop names; if _price wasn't touched, we skip.
	 */
	public static function on_product_updated( $product, array $updated_props = array() ): void {
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		// Cheap guard: only run when a price prop was actually updated.
		$price_props = array( 'price', 'regular_price', 'sale_price' );
		if ( $updated_props && ! array_intersect( $price_props, $updated_props ) ) {
			return;
		}
		$new_price = self::current_price( $product );
		if ( $new_price <= 0 ) {
			return;
		}
		$product_id = $product->get_id();
		$buyers     = self::favorited_by( $product_id );
		if ( ! $buyers ) {
			return;
		}
		$seller_id = (int) get_post_meta( $product_id, '_tnm_seller_id', true );
		foreach ( $buyers as $buyer_id ) {
			// Respect the opt-out toggle. Default is enabled.
			$opt = get_user_meta( $buyer_id, self::USER_META_OPT_IN, true );
			if ( '0' === (string) $opt ) {
				continue;
			}
			$map = self::get_map( $buyer_id );
			// If we never recorded a baseline (favorited before this
			// class existed), seed it now — no notification, but the
			// next drop will be caught.
			if ( ! isset( $map[ $product_id ] ) ) {
				$map[ $product_id ] = $new_price;
				update_user_meta( $buyer_id, self::USER_META_MAP, $map );
				continue;
			}
			$last = (float) $map[ $product_id ];
			// Only alert on a MEANINGFUL drop — anything under 1% is
			// probably a rounding tweak on a sale ending, and buyers
			// will resent being pinged for a $0.05 change.
			if ( $new_price >= $last * 0.99 ) {
				continue;
			}
			$pct   = (int) round( ( ( $last - $new_price ) / $last ) * 100 );
			$title = 'Price dropped on your favorite';
			$body  = sprintf(
				'%s is now $%s (was $%s) — %d%% off.',
				wp_trim_words( $product->get_name(), 10, '' ),
				number_format( $new_price, 2 ),
				number_format( $last, 2 ),
				$pct
			);
			// Inbox row (deep-link into the product screen).
			if ( function_exists( 'tnm_notify' ) ) {
				tnm_notify( $buyer_id, $seller_id, 'price_drop', $title, $body, $product_id, 'product' );
			}
			// Push (best effort — silently no-ops if the user has no
			// registered tokens).
			if ( class_exists( 'MNU_Ops' ) && method_exists( 'MNU_Ops', 'notify_user' ) ) {
				MNU_Ops::notify_user( $buyer_id, $title, $body, array(
					'type'       => 'price_drop',
					'category'   => 'price_drop_alerts', // v3.7.121 (Build #17b)
					'product_id' => $product_id,
					'deep_link'  => '/product/' . $product_id,
				) );
			}
			$map[ $product_id ] = $new_price;
			update_user_meta( $buyer_id, self::USER_META_MAP, $map );
		}
	}

	/**
	 * Return the list of user IDs who currently favorite a product.
	 * Reads directly from the Trust Suite favorites table to avoid a
	 * cross-plugin API dependency (the table shape has been stable
	 * since Trust Suite v1.2.0 and is documented in the README).
	 */
	protected static function favorited_by( int $product_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'tnm_trust_favorites';
		$rows  = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT user_id FROM {$table} WHERE product_id = %d", $product_id ) );
		return array_map( 'intval', (array) $rows );
	}

	protected static function get_map( int $user_id ): array {
		$map = get_user_meta( $user_id, self::USER_META_MAP, true );
		return is_array( $map ) ? $map : array();
	}

	/**
	 * Effective current price. WC_Product::get_price() already returns
	 * the sale price when active, so we don't need to branch on sale
	 * status here.
	 */
	protected static function current_price( WC_Product $product ): float {
		return (float) $product->get_price();
	}
}

MNU_Price_Drop::init();
