<?php
/**
 * Uninstall handler for MyNest Trust & Growth Suite.
 * Runs only when the plugin is deleted via wp-admin → Plugins (not on
 * simple deactivation). Removes all tables and options created by this
 * plugin. Never touches any table belonging to the "MyNest Unified
 * Marketplace" plugin or any other plugin.
 *
 * @package MyNest_Trust_Suite
 */

// Exit if accessed directly, or if not triggered via the proper WP uninstall flow.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

/*
 * -----------------------------------------------------------------------
 * Drop this plugin's own tables only.
 * -----------------------------------------------------------------------
 */
$tables = array(
	$wpdb->prefix . 'tnm_trust_disputes',
	$wpdb->prefix . 'tnm_trust_favorites',
	$wpdb->prefix . 'tnm_trust_offers',
	$wpdb->prefix . 'tnm_trust_boosts',
);

foreach ( $tables as $table ) {
	// Table names are hard-coded above (not user input), safe to interpolate directly.
	$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

/*
 * -----------------------------------------------------------------------
 * Remove all plugin options.
 * -----------------------------------------------------------------------
 */
$options = array(
	'tnm_trust_dispute_claim_window_days',
	'tnm_trust_dispute_min_wait_hours',
	'tnm_trust_dispute_sla_days',
	'tnm_trust_badge_min_orders',
	'tnm_trust_badge_min_gmv',
	'tnm_trust_badge_ontime_threshold',
	'tnm_trust_badge_rating_threshold',
	'tnm_trust_badge_response_threshold',
	'tnm_trust_badge_default_processing_days',
	'tnm_trust_bundle_first_item_discount_pct',
	'tnm_trust_bundle_additional_item_discount_pct',
	'tnm_trust_boost_price_3day',
	'tnm_trust_boost_price_7day',
	'tnm_trust_pro_seller_fee_discount_points',
	'tnm_trust_boost_product_id',
	'tnm_trust_feed_weights',
	'tnm_trust_activated_at',
);

foreach ( $options as $option_name ) {
	delete_option( $option_name );
	delete_site_option( $option_name );
}

/*
 * -----------------------------------------------------------------------
 * Remove per-user Pro Seller meta.
 * -----------------------------------------------------------------------
 */
delete_metadata( 'user', 0, '_tnm_trust_pro_seller', '', true );

/*
 * -----------------------------------------------------------------------
 * Clear any transients this plugin created (badge cache).
 * Badge transients are per-seller (`tnm_trust_badge_{id}`); WordPress
 * doesn't provide a wildcard delete, so we query wp_options directly for
 * matching keys — table is wp_options (core, always present).
 * -----------------------------------------------------------------------
 */
$transient_like = $wpdb->esc_like( '_transient_tnm_trust_badge_' ) . '%';
$timeout_like    = $wpdb->esc_like( '_transient_timeout_tnm_trust_badge_' ) . '%';

// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $transient_like ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $timeout_like ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

/*
 * -----------------------------------------------------------------------
 * Unschedule cron event (in case deletion happens without prior deactivation).
 * -----------------------------------------------------------------------
 */
$timestamp = wp_next_scheduled( 'tnm_trust_hourly_event' );
if ( $timestamp ) {
	wp_unschedule_event( $timestamp, 'tnm_trust_hourly_event' );
}
