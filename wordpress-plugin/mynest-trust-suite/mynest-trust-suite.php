<?php
/**
 * Plugin Name:       MyNest Trust & Growth Suite
 * Plugin URI:        https://shopmynest.com/
 * Description:       Adds buyer protection (disputes), seller performance badges, favorites & personalized feed ranking, bundles & offers, structured product attributes, and listing boosts / Pro Seller tier to The Nest marketplace. Designed as a standalone companion to the "MyNest Unified Marketplace" plugin — reads its data defensively but never depends on it.
 * Version:           1.2.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * WC requires at least: 7.0
 * Author:            The Nest
 * Text Domain:       nest-trust
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package MyNest_Trust_Suite
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * -----------------------------------------------------------------------
 * Constants
 * -----------------------------------------------------------------------
 */
define( 'TNM_TRUST_VERSION', '1.2.0' );
define( 'TNM_TRUST_FILE', __FILE__ );
define( 'TNM_TRUST_DIR', plugin_dir_path( __FILE__ ) );
define( 'TNM_TRUST_URL', plugin_dir_url( __FILE__ ) );
define( 'TNM_TRUST_BASENAME', plugin_basename( __FILE__ ) );
define( 'TNM_TRUST_REST_NS', 'nest-trust/v1' );
define( 'TNM_TRUST_TABLE_PREFIX', 'tnm_trust_' );

/**
 * Minimum requirements check. WooCommerce is the ONLY hard dependency.
 * The "MyNest Unified Marketplace" plugin is a soft, defensive integration —
 * this plugin must activate and run correctly even if that plugin is
 * missing, deactivated, or has a different schema than assumed.
 */
function tnm_trust_requirements_met() {
	if ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
		return false;
	}

	if ( ! class_exists( 'WooCommerce' ) ) {
		return false;
	}

	return true;
}

/**
 * Admin notice shown when requirements are not met.
 */
function tnm_trust_requirements_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="notice notice-error"><p>';
	echo esc_html__( 'MyNest Trust & Growth Suite requires WooCommerce to be installed and active, and PHP 8.0 or higher. The plugin has been loaded but its features are disabled until these requirements are met.', 'nest-trust' );
	echo '</p></div>';
}

/**
 * Deactivate self safely (used if WooCommerce is missing at activation time).
 */
function tnm_trust_deactivate_self() {
	if ( ! function_exists( 'deactivate_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	deactivate_plugins( TNM_TRUST_BASENAME );
}

/**
 * Activation handler.
 * Creates DB tables, registers attribute taxonomies/terms, seeds the
 * "Listing Boost" virtual product, and schedules cron events.
 */
function tnm_trust_activate() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		tnm_trust_deactivate_self();
		wp_die(
			esc_html__( 'MyNest Trust & Growth Suite requires WooCommerce to be installed and active. The plugin has been deactivated.', 'nest-trust' ),
			esc_html__( 'Plugin dependency check', 'nest-trust' ),
			array( 'back_link' => true )
		);
	}

	// Compat helpers are a foundational dependency of the setup classes below
	// (e.g. TNM_Trust_Attributes::ensure_attribute_exists() calls
	// TNM_Trust_Compat::table_exists()). The plugins_loaded bootstrap has NOT
	// run for this plugin during its own activation request, so load it here.
	require_once TNM_TRUST_DIR . 'includes/class-tnm-trust-compat.php';

	require_once TNM_TRUST_DIR . 'includes/class-tnm-trust-db.php';
	TNM_Trust_DB::create_tables();
	TNM_Trust_DB::add_default_options();

	require_once TNM_TRUST_DIR . 'includes/class-tnm-trust-attributes.php';
	TNM_Trust_Attributes::register_attributes_on_activation();

	require_once TNM_TRUST_DIR . 'includes/class-tnm-trust-boosts.php';
	TNM_Trust_Boosts::ensure_boost_product_exists();

	if ( ! wp_next_scheduled( 'tnm_trust_hourly_event' ) ) {
		wp_schedule_event( time(), 'hourly', 'tnm_trust_hourly_event' );
	}

	update_option( 'tnm_trust_activated_at', time() );
}
register_activation_hook( __FILE__, 'tnm_trust_activate' );

/**
 * Deactivation handler. Clears scheduled cron events only.
 * Data/tables are left intact (removed only via uninstall.php on delete).
 */
function tnm_trust_deactivate() {
	$timestamp = wp_next_scheduled( 'tnm_trust_hourly_event' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'tnm_trust_hourly_event' );
	}
}
register_deactivation_hook( __FILE__, 'tnm_trust_deactivate' );

/**
 * Bootstraps the plugin once all plugins have loaded, so we can safely
 * detect WooCommerce and the (optional) MyNest Unified Marketplace plugin.
 */
function tnm_trust_bootstrap() {
	if ( ! tnm_trust_requirements_met() ) {
		add_action( 'admin_notices', 'tnm_trust_requirements_notice' );
		return;
	}

	// Core helpers / compatibility shims — loaded first, everything depends on these.
	require_once TNM_TRUST_DIR . 'includes/class-tnm-trust-compat.php';
	require_once TNM_TRUST_DIR . 'includes/class-tnm-trust-db.php';

	// Native Stripe PaymentSheet helper (reuses the marketplace's Stripe config).
	require_once TNM_TRUST_DIR . 'includes/class-tnm-trust-native-pay.php';

	// Feature classes.
	require_once TNM_TRUST_DIR . 'includes/class-tnm-trust-disputes.php';
	require_once TNM_TRUST_DIR . 'includes/class-tnm-trust-seller-badge.php';
	require_once TNM_TRUST_DIR . 'includes/class-tnm-trust-favorites.php';
	require_once TNM_TRUST_DIR . 'includes/class-tnm-trust-feed.php';
	require_once TNM_TRUST_DIR . 'includes/class-tnm-trust-offers.php';
	require_once TNM_TRUST_DIR . 'includes/class-tnm-trust-attributes.php';
	require_once TNM_TRUST_DIR . 'includes/class-tnm-trust-boosts.php';
	require_once TNM_TRUST_DIR . 'includes/class-tnm-trust-rest.php';
	require_once TNM_TRUST_DIR . 'includes/class-tnm-trust-shortcodes.php';
	require_once TNM_TRUST_DIR . 'includes/class-tnm-trust-admin.php';
	require_once TNM_TRUST_DIR . 'includes/class-tnm-trust-cron.php';
	require_once TNM_TRUST_DIR . 'includes/class-tnm-trust-assets.php';

	// Initialize each feature module.
	TNM_Trust_Disputes::init();
	TNM_Trust_Seller_Badge::init();
	TNM_Trust_Favorites::init();
	TNM_Trust_Feed::init();
	TNM_Trust_Offers::init();
	TNM_Trust_Attributes::init();
	TNM_Trust_Boosts::init();
	TNM_Trust_REST::init();
	TNM_Trust_Shortcodes::init();
	TNM_Trust_Admin::init();
	TNM_Trust_Cron::init();
	TNM_Trust_Assets::init();
}
add_action( 'plugins_loaded', 'tnm_trust_bootstrap', 20 );

/**
 * Load textdomain for translations.
 */
function tnm_trust_load_textdomain() {
	load_plugin_textdomain( 'nest-trust', false, dirname( TNM_TRUST_BASENAME ) . '/languages' );
}
add_action( 'init', 'tnm_trust_load_textdomain' );
