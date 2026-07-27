<?php
/**
 * Feature 5 — Structured Attributes (condition / size / brand).
 *
 * @package MyNest_Trust_Suite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TNM_Trust_Attributes {

	/**
	 * Attribute slug (without the pa_ prefix) => array of definition data.
	 *
	 * @var array
	 */
	protected static $attribute_defs = array(
		'condition' => array(
			'label' => 'Condition',
			'terms' => array( 'New', 'Like New', 'Good', 'Fair', 'Well Loved' ),
			'type'  => 'select',
		),
		'size'      => array(
			'label' => 'Size',
			'terms' => array(),
			'type'  => 'select',
		),
		'brand'     => array(
			'label' => 'Brand',
			'terms' => array(),
			'type'  => 'select',
		),
	);

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_taxonomies' ), 6 );
		add_action( 'admin_notices', array( __CLASS__, 'render_seller_help_notice' ) );
	}

	/**
	 * Registers the three global WooCommerce product attributes (and, for
	 * `pa_condition`, its fixed term list) if they don't already exist.
	 * Called on plugin activation.
	 */
	public static function register_attributes_on_activation() {
		if ( ! function_exists( 'wc_create_attribute' ) || ! taxonomy_exists( 'product' ) ) {
			// WooCommerce not fully loaded yet — bail defensively, no fatal.
			if ( ! class_exists( 'WooCommerce' ) ) {
				return;
			}
		}

		global $wpdb;

		foreach ( self::$attribute_defs as $slug => $def ) {
			self::ensure_attribute_exists( $slug, $def['label'], $def['type'] );
		}

		// Register the taxonomies immediately so terms can be inserted in the same request.
		self::register_taxonomies();

		// Seed the fixed condition term list only (size/brand stay freeform/seller-managed).
		if ( ! empty( self::$attribute_defs['condition']['terms'] ) && taxonomy_exists( 'pa_condition' ) ) {
			foreach ( self::$attribute_defs['condition']['terms'] as $term_name ) {
				if ( ! term_exists( $term_name, 'pa_condition' ) ) {
					wp_insert_term( $term_name, 'pa_condition' );
				}
			}
		}

		// Flush attribute taxonomy registration + rewrite rules so the new taxonomies are recognized immediately.
		delete_transient( 'wc_attribute_taxonomies' );
		flush_rewrite_rules();
	}

	/**
	 * Ensure a single global attribute row exists in
	 * `{$wpdb->prefix}woocommerce_attribute_taxonomies`.
	 *
	 * @param string $slug  Attribute slug without pa_ prefix (e.g. 'condition').
	 * @param string $label Human label.
	 * @param string $type  Attribute type ('select', etc).
	 */
	protected static function ensure_attribute_exists( $slug, $label, $type ) {
		global $wpdb;

		$slug = sanitize_key( $slug );

		$table_name = $wpdb->prefix . 'woocommerce_attribute_taxonomies';

		if ( ! TNM_Trust_Compat::table_exists( $table_name ) ) {
			TNM_Trust_Compat::log( 'WooCommerce attribute taxonomy table not found — cannot register attributes yet.' );
			return;
		}

		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT attribute_id FROM {$table_name} WHERE attribute_name = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$slug
			)
		);

		if ( $existing ) {
			return;
		}

		$wpdb->insert(
			$table_name,
			array(
				'attribute_name'    => $slug,
				'attribute_label'   => $label,
				'attribute_type'    => $type,
				'attribute_orderby' => 'menu_order',
				'attribute_public'  => 1,
			),
			array( '%s', '%s', '%s', '%s', '%d' )
		);
	}

	/**
	 * Register the pa_* taxonomies for the current request (needed both
	 * on activation and on every normal page load, since WooCommerce only
	 * auto-registers attribute taxonomies it knows about from its own cache).
	 */
	public static function register_taxonomies() {
		if ( ! function_exists( 'wc_attribute_taxonomy_name' ) ) {
			return;
		}

		foreach ( array_keys( self::$attribute_defs ) as $slug ) {
			$taxonomy_name = wc_attribute_taxonomy_name( $slug );

			if ( taxonomy_exists( $taxonomy_name ) ) {
				continue;
			}

			register_taxonomy(
				$taxonomy_name,
				apply_filters( 'woocommerce_taxonomy_objects_' . $taxonomy_name, array( 'product' ) ),
				apply_filters(
					'woocommerce_taxonomy_args_' . $taxonomy_name,
					array(
						'labels'       => array(
							'name' => self::$attribute_defs[ $slug ]['label'],
						),
						'hierarchical' => false,
						'show_ui'      => false,
						'query_var'    => true,
						'rewrite'      => false,
						'public'       => true,
					)
				)
			);
		}
	}

	/**
	 * Admin notice pointing sellers to fill in the new attributes when
	 * editing a product. Only shown on the product edit screen.
	 */
	public static function render_seller_help_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || 'product' !== $screen->post_type || 'post' !== $screen->base ) {
			return;
		}

		if ( ! current_user_can( 'edit_products' ) ) {
			return;
		}

		echo '<div class="notice notice-info"><p>';
		echo esc_html__( 'Tip: Fill in Condition, Size, and Brand under Product data → Attributes to help buyers filter and find your listings.', 'nest-trust' );
		echo '</p></div>';
	}
}
