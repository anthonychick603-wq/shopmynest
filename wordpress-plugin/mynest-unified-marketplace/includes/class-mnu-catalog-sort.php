<?php
/**
 * Force shop / category / tag archives to sort newest-first.
 *
 * Overrides the customizer default ("Default sorting") to date DESC. Explicit
 * user choices (price, popularity, rating) still work — only the neutral
 * "menu_order" bucket is remapped.
 */
defined( 'ABSPATH' ) || exit;

final class MNU_Catalog_Sort {
	public static function init(): void {
		add_filter( 'woocommerce_default_catalog_orderby', array( __CLASS__, 'default_orderby' ) );
		add_filter( 'woocommerce_get_catalog_ordering_args', array( __CLASS__, 'ordering_args' ), 10, 3 );
		add_filter( 'woocommerce_shortcode_products_query', array( __CLASS__, 'shortcode_query' ), 10, 3 );
	}

	public static function default_orderby( $orderby ) {
		return 'date';
	}

	public static function ordering_args( $args, $orderby = '', $order = '' ) {
		$requested = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : '';
		if ( '' !== $requested && 'menu_order' !== $requested ) {
			return $args;
		}
		$args['orderby']  = 'date';
		$args['order']    = 'DESC';
		$args['meta_key'] = '';
		return $args;
	}

	public static function shortcode_query( $query_args, $attributes, $type ) {
		if ( empty( $query_args['orderby'] ) || 'menu_order' === $query_args['orderby'] ) {
			$query_args['orderby'] = 'date';
			$query_args['order']   = 'DESC';
		}
		return $query_args;
	}
}
