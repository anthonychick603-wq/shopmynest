<?php
/**
 * Database table creation for MyNest Trust & Growth Suite.
 * All tables are prefixed {$wpdb->prefix}tnm_trust_ and are owned
 * exclusively by this plugin (never written to by, and never assumed
 * to exist in, the other plugin).
 *
 * @package MyNest_Trust_Suite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TNM_Trust_DB {

	/**
	 * Return the fully-prefixed table name for a short name owned by this plugin.
	 *
	 * @param string $short_name e.g. 'disputes', 'favorites', 'offers', 'boosts'.
	 * @return string
	 */
	public static function table( $short_name ) {
		global $wpdb;
		$short_name = preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $short_name ) );
		return $wpdb->prefix . TNM_TRUST_TABLE_PREFIX . $short_name;
	}

	/**
	 * Create (or upgrade) all tables owned by this plugin using dbDelta.
	 */
	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$disputes_table  = self::table( 'disputes' );
		$favorites_table = self::table( 'favorites' );
		$offers_table    = self::table( 'offers' );
		$boosts_table    = self::table( 'boosts' );

		$sql = array();

		$sql[] = "CREATE TABLE {$disputes_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			order_id BIGINT UNSIGNED NOT NULL,
			order_item_id BIGINT UNSIGNED NULL,
			buyer_id BIGINT UNSIGNED NOT NULL,
			seller_id BIGINT UNSIGNED NOT NULL,
			reason VARCHAR(32) NOT NULL DEFAULT 'other',
			description LONGTEXT NULL,
			evidence LONGTEXT NULL,
			status VARCHAR(32) NOT NULL DEFAULT 'open',
			resolution_note LONGTEXT NULL,
			refund_amount DECIMAL(18,4) NULL,
			contacted_seller_at DATETIME NULL,
			escalated_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			resolved_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY order_id (order_id),
			KEY buyer_id (buyer_id),
			KEY seller_id (seller_id),
			KEY status (status)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$favorites_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			product_id BIGINT UNSIGNED NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY user_product (user_id, product_id),
			KEY product_id (product_id)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$offers_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			buyer_id BIGINT UNSIGNED NOT NULL,
			seller_id BIGINT UNSIGNED NOT NULL,
			type VARCHAR(16) NOT NULL DEFAULT 'single',
			product_ids LONGTEXT NOT NULL,
			offer_price DECIMAL(18,4) NOT NULL,
			status VARCHAR(16) NOT NULL DEFAULT 'pending',
			counter_price DECIMAL(18,4) NULL,
			checkout_token VARCHAR(64) NULL,
			token_used TINYINT(1) NOT NULL DEFAULT 0,
			token_expires_at DATETIME NULL,
			expires_at DATETIME NOT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY buyer_id (buyer_id),
			KEY seller_id (seller_id),
			KEY status (status),
			KEY checkout_token (checkout_token)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$boosts_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			product_id BIGINT UNSIGNED NOT NULL,
			seller_id BIGINT UNSIGNED NOT NULL,
			tier VARCHAR(16) NOT NULL DEFAULT '3day',
			starts_at DATETIME NULL,
			expires_at DATETIME NULL,
			price_paid DECIMAL(18,4) NULL,
			wc_order_id BIGINT UNSIGNED NULL,
			status VARCHAR(16) NOT NULL DEFAULT 'active',
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY product_id (product_id),
			KEY seller_id (seller_id),
			KEY status (status),
			KEY wc_order_id (wc_order_id)
		) {$charset_collate};";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}
	}

	/**
	 * Seed default option values on activation (does not overwrite existing values).
	 */
	public static function add_default_options() {
		$defaults = array(
			'tnm_trust_dispute_claim_window_days'        => 100,
			'tnm_trust_dispute_min_wait_hours'            => 48,
			'tnm_trust_dispute_sla_days'                  => 5,
			'tnm_trust_badge_min_orders'                  => 5,
			'tnm_trust_badge_min_gmv'                      => 300,
			'tnm_trust_badge_ontime_threshold'            => 95,
			'tnm_trust_badge_rating_threshold'            => 4.8,
			'tnm_trust_badge_response_threshold'          => 95,
			'tnm_trust_badge_default_processing_days'     => 3,
			'tnm_trust_bundle_first_item_discount_pct'    => 0,
			'tnm_trust_bundle_additional_item_discount_pct' => 20,
			'tnm_trust_boost_price_3day'                   => 5.00,
			'tnm_trust_boost_price_7day'                    => 9.00,
			'tnm_trust_pro_seller_fee_discount_points'     => 3.5,
			'tnm_trust_boost_product_id'                    => 0,
			'tnm_trust_feed_weights'                         => array(
				'recency'    => 1.0,
				'favorites'  => 1.5,
				'follows'    => 2.0,
				'badge'      => 2.5,
				'sales'      => 1.0,
				'boost'      => 5.0,
			),
		);

		foreach ( $defaults as $key => $value ) {
			if ( false === get_option( $key, false ) ) {
				add_option( $key, $value );
			}
		}
	}
}
