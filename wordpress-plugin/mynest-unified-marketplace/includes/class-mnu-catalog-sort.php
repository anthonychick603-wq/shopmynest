<?php
/**
 * Force shop / category / tag archives to sort newest-first, and add a
 * marketplace-specific "In stock first" sort option so buyers see purchasable
 * products before out-of-stock listings.
 *
 * Overrides the customizer default ("Default sorting") to date DESC. Explicit
 * user choices (price, popularity, rating) still work — only the neutral
 * "menu_order" bucket is remapped. The new "in_stock" option is offered in
 * the catalog dropdown and pins stock_status = 'instock' rows to the top,
 * then falls back to newest-first inside each bucket.
 */
defined( 'ABSPATH' ) || exit;

final class MNU_Catalog_Sort {
	const IN_STOCK_KEY = 'in_stock';

	public static function init(): void {
		add_filter( 'woocommerce_default_catalog_orderby', array( __CLASS__, 'default_orderby' ) );
		add_filter( 'woocommerce_catalog_orderby', array( __CLASS__, 'catalog_options' ) );
		add_filter( 'woocommerce_default_catalog_orderby_options', array( __CLASS__, 'catalog_options' ) );
		add_filter( 'woocommerce_get_catalog_ordering_args', array( __CLASS__, 'ordering_args' ), 10, 3 );
		add_filter( 'woocommerce_shortcode_products_query', array( __CLASS__, 'shortcode_query' ), 10, 3 );
		add_filter( 'posts_clauses', array( __CLASS__, 'apply_in_stock_clauses' ), 20, 2 );
	}

	/**
	 * Default sort when the shopper hasn't chosen anything: in-stock first,
	 * newest inside each bucket. Buyers should never land on a page whose
	 * first row is out of stock.
	 */
	public static function default_orderby( $orderby ) {
		return self::IN_STOCK_KEY;
	}

	/**
	 * Register the "In stock first" option in the Woo catalog dropdown. The
	 * filter is called twice — for the visible options and for the "default"
	 * customizer selector — so we register the same label for both. Keep the
	 * new option at the top so it reads as the primary browse mode.
	 *
	 * @param array<string,string> $options
	 * @return array<string,string>
	 */
	public static function catalog_options( $options ) {
		if ( ! is_array( $options ) ) {
			return $options;
		}
		return array_merge(
			array( self::IN_STOCK_KEY => __( 'In stock first', 'mynest-unified-marketplace' ) ),
			$options
		);
	}

	/**
	 * Map orderby → WP_Query args. Explicit price/popularity/rating requests
	 * pass through untouched. Our IN_STOCK_KEY and the neutral menu_order
	 * bucket both route through the stock-aware branch; posts_clauses does
	 * the final ORDER BY rewrite because WP_Query alone can't express
	 * "stock_status ASC, then post_date DESC".
	 */
	public static function ordering_args( $args, $orderby = '', $order = '' ) {
		$requested = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : '';
		if ( '' !== $requested && 'menu_order' !== $requested && self::IN_STOCK_KEY !== $requested ) {
			return $args;
		}
		// Signal downstream (posts_clauses filter) that this query should be
		// sorted stock-first, newest-second. We keep orderby set to 'date'
		// so any theme code inspecting $args still sees a sensible value.
		$args['orderby']         = 'date';
		$args['order']           = 'DESC';
		$args['meta_key']        = '';
		$args['mnu_stock_first'] = true;
		return $args;
	}

	public static function shortcode_query( $query_args, $attributes, $type ) {
		if ( empty( $query_args['orderby'] ) || 'menu_order' === $query_args['orderby'] || self::IN_STOCK_KEY === $query_args['orderby'] ) {
			$query_args['orderby']         = 'date';
			$query_args['order']           = 'DESC';
			$query_args['mnu_stock_first'] = true;
		}
		return $query_args;
	}

	/**
	 * Rewrite the SQL ORDER BY when the query has been flagged as stock-first.
	 * We join the WooCommerce product lookup table when it's available (fast,
	 * indexed on stock_status), and fall back to the _stock_status postmeta
	 * for hosts that haven't materialized the lookup yet.
	 */
	public static function apply_in_stock_clauses( $clauses, $query ) {
		global $wpdb;
		if ( ! ( $query instanceof WP_Query ) ) {
			return $clauses;
		}
		if ( ! $query->get( 'mnu_stock_first' ) ) {
			return $clauses;
		}
		$post_types = (array) $query->get( 'post_type' );
		if ( $post_types && ! in_array( 'product', $post_types, true ) ) {
			return $clauses;
		}

		$lookup = $wpdb->prefix . 'wc_product_meta_lookup';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		$has_lookup = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $lookup ) );
		// phpcs:enable

		if ( $has_lookup ) {
			$clauses['join']   .= " LEFT JOIN {$lookup} AS mnu_stock_lookup ON mnu_stock_lookup.product_id = {$wpdb->posts}.ID ";
			// stock_status 'instock' | 'onbackorder' come before 'outofstock' when sorted ASC.
			$clauses['orderby'] = " CASE mnu_stock_lookup.stock_status WHEN 'instock' THEN 0 WHEN 'onbackorder' THEN 1 ELSE 2 END ASC, {$wpdb->posts}.post_date DESC ";
		} else {
			$clauses['join']   .= " LEFT JOIN {$wpdb->postmeta} AS mnu_stock_meta ON mnu_stock_meta.post_id = {$wpdb->posts}.ID AND mnu_stock_meta.meta_key = '_stock_status' ";
			$clauses['orderby'] = " CASE mnu_stock_meta.meta_value WHEN 'instock' THEN 0 WHEN 'onbackorder' THEN 1 ELSE 2 END ASC, {$wpdb->posts}.post_date DESC ";
			// LEFT JOIN can multiply rows if a product has two stock rows (shouldn't happen but guard).
			if ( false === strpos( $clauses['groupby'], "{$wpdb->posts}.ID" ) ) {
				$clauses['groupby'] = "{$wpdb->posts}.ID";
			}
		}

		return $clauses;
	}
}
