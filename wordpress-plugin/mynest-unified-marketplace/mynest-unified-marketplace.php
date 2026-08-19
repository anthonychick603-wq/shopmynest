<?php
/**
 * Plugin Name: MyNest Unified Marketplace
 * Plugin URI:  https://shopmynest.com/
 * Description: One complete WooCommerce marketplace plugin for MyNest sellers, fees, payouts, orders, social features, mobile APIs, checkout, and shipping.
 * Version:     3.7.112
 * Author:      MyNest
 * Text Domain: mynest-unified-marketplace
 * Requires at least: 6.5
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 * WC requires at least: 8.0
 * WC tested up to: 10.9
 * License: GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

define( 'MNU_VERSION', '3.7.112' );
define( 'MNU_DB_VERSION', '3.0.13' );
define( 'MNU_FILE', __FILE__ );
define( 'MNU_BASENAME', plugin_basename( __FILE__ ) );
define( 'MNU_PATH', plugin_dir_path( __FILE__ ) );
define( 'MNU_URL', plugin_dir_url( __FILE__ ) );

// Preserve the public constants used by the mobile app and existing data layer.
if ( ! defined( 'TNM_VERSION' ) ) {
	define( 'TNM_VERSION', MNU_VERSION );
}
if ( ! defined( 'TNM_FILE' ) ) {
	define( 'TNM_FILE', MNU_FILE );
}
if ( ! defined( 'TNM_PATH' ) ) {
	define( 'TNM_PATH', MNU_PATH );
}
if ( ! defined( 'TNM_URL' ) ) {
	define( 'TNM_URL', MNU_URL );
}

add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', MNU_FILE, true );
		}
	}
);

/**
 * Load all plugin source files in a fixed dependency order.
 */
function mnu_load_files(): void {
	static $loaded = false;
	if ( $loaded ) {
		return;
	}
	$loaded = true;

	$files = array(
		'includes/helpers.php',
		'includes/class-tnm-auth.php',
		'includes/class-tnm-content.php',
		'includes/class-mnu-install.php',
		'includes/class-tnm-applications.php',
		'includes/class-tnm-social.php',
		'includes/class-mnu-blog.php',
		'includes/class-tnm-marketplace.php',
		'includes/class-tnm-ledger.php',
		'includes/class-tnm-payouts.php',
		'includes/class-tnm-shortcodes.php',
		'includes/class-tnm-rest.php',
		'includes/class-mnu-compat.php',
		'includes/class-mnu-lifecycle.php',
		'includes/class-mnu-admin-tables.php',
		'includes/class-mnu-admin-seller-products.php',
		'includes/class-mnu-seller-attribution.php',
		'includes/class-mnu-buyer-experience.php',
		'includes/class-mnu-ops.php',
		'includes/class-mnu-saved-searches.php',
		'includes/class-mnu-native-checkout.php',
		'includes/class-mnu-connect.php',
		'includes/class-mnu-redirects.php',
		'includes/class-mnu-catalog-sort.php',
		'includes/class-mnu-keyword-search.php',
		'includes/class-mnu-social-frontend.php',
		'includes/class-mnu-woo-gateway.php',
		'includes/class-mnu-checkout-finalize.php',
		'includes/class-mnu-shipping-labels.php',
		'includes/class-mnu-auto-label.php',
		'includes/class-mnu-shippo-connect.php',
		'includes/class-mnu-shipping-profiles.php',
		'includes/class-mnu-web-shipping.php',
		'includes/class-mnu-ship-from-guard.php',
		'includes/class-mnu-email-verify.php',
		'includes/class-mnu-payout-gate.php',
		'includes/class-tnm-admin.php',
		'includes/class-mnu-system.php',
		'includes/class-mnu-product-import.php',
		'includes/class-mnu-multi-roles.php',
		'includes/class-mnu-reconciliation.php',
		'includes/class-mnu-refund-lifecycle.php',
		'includes/class-mnu-tax-posture.php',
		'includes/class-mnu-seller-ownership.php',
		'includes/class-mnu-seller-readiness.php',
		'includes/class-mnu-app-links.php',
		'includes/class-mnu-favorites-listener.php',
		'includes/class-tnm-order-review-card.php',
		'includes/class-tnm-review-nudge.php',
		// v3.7.105 - Web parity: brings the app-only favorites list, saved
		// searches, blog, save-alert pill, and seller rating badges to the
		// browsing site as native WordPress surfaces.
		'includes/class-mnu-web-parity.php',
	);

	foreach ( $files as $file ) {
		require_once MNU_PATH . $file;
	}
}

/**
 * Install or upgrade the data model without deleting legacy marketplace data.
 */
function mnu_install_or_upgrade(): void {
	mnu_load_files();
	MNU_Install::activate();

	$settings = (array) get_option( 'tnm_settings', array() );
	// Real-money automation is never switched on by an install or update.
	$settings['automatic_payouts'] = 'no';
	update_option( 'tnm_settings', $settings, false );
	update_option( 'mnu_version', MNU_VERSION, false );
	update_option( 'mnu_db_version', MNU_DB_VERSION, false );
}
register_activation_hook( __FILE__, 'mnu_install_or_upgrade' );

/**
 * v3.7.109 — one-shot auto-approval of the moderation backlog. Flips every
 * pending WooCommerce product to publish, and every pending community blog
 * post (mnu_blog_post CPT) to publish. Idempotent: relies on the version
 * option guard around the caller so it never runs twice.
 */
function mnu_auto_approve_pending_backlog(): void {
	$pending_products = get_posts(
		array(
			'post_type'      => 'product',
			'post_status'    => 'pending',
			'posts_per_page' => 500,
			'fields'         => 'ids',
			'suppress_filters' => true,
		)
	);
	foreach ( (array) $pending_products as $pid ) {
		wp_update_post( array( 'ID' => (int) $pid, 'post_status' => 'publish' ) );
	}

	$blog_cpt = class_exists( 'MNU_Blog' ) && defined( 'MNU_Blog::CPT' ) ? MNU_Blog::CPT : 'mnu_blog_post';
	$pending_posts = get_posts(
		array(
			'post_type'      => $blog_cpt,
			'post_status'    => 'pending',
			'posts_per_page' => 500,
			'fields'         => 'ids',
			'suppress_filters' => true,
		)
	);
	foreach ( (array) $pending_posts as $bpid ) {
		wp_update_post( array( 'ID' => (int) $bpid, 'post_status' => 'publish' ) );
	}
}

function mnu_deactivate_plugin(): void {
	mnu_load_files();
	MNU_Install::deactivate();
	if ( class_exists( 'MNU_Reconciliation' ) ) {
		MNU_Reconciliation::deactivate();
	}
	if ( class_exists( 'MNU_Tax_Posture' ) ) {
		MNU_Tax_Posture::deactivate();
	}
	if ( class_exists( 'MNU_Seller_Ownership' ) ) {
		MNU_Seller_Ownership::deactivate();
	}
}
register_deactivation_hook( __FILE__, 'mnu_deactivate_plugin' );

final class MNU_Plugin {
	private static ?MNU_Plugin $instance = null;
	private bool $booted = false;

	public static function instance(): MNU_Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'plugins_loaded', array( $this, 'boot' ), 30 );
		add_action( 'admin_notices', array( $this, 'dependency_notice' ) );
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		/*
		 * A second legacy marketplace backend must never run beside this one.
		 * When this ZIP replaces version 2.x in the same folder, those classes do
		 * not exist yet, so normal upgrades continue without interruption.
		 */
		if ( function_exists( 'tnm_table' ) || class_exists( 'TNM_Marketplace', false ) ) {
			add_action( 'admin_notices', array( $this, 'legacy_conflict_notice' ) );
			return;
		}

		mnu_load_files();
		MNU_Install::init();

		$installed = (string) get_option( 'mnu_db_version', '' );
		if ( MNU_DB_VERSION !== $installed ) {
			MNU_Install::activate();
			$settings = (array) get_option( 'tnm_settings', array() );
			// Updates never turn on movement of real money automatically.
			$settings['automatic_payouts'] = 'no';
			update_option( 'tnm_settings', $settings, false );
			update_option( 'mnu_db_version', MNU_DB_VERSION, false );
			update_option( 'mnu_version', MNU_VERSION, false );
		}

		// v3.7.109 — auto-approve legacy backlog: publish any pending products
		// and blog posts once, so the moderation removal covers items already
		// in the queue at upgrade time. Runs at most once per site.
		if ( 'done' !== (string) get_option( 'mnu_auto_approve_backlog_v1', '' ) ) {
			mnu_auto_approve_pending_backlog();
			update_option( 'mnu_auto_approve_backlog_v1', 'done', false );
		}

		TNM_Auth::init();
		TNM_Content::init();
		TNM_Applications::init();
		TNM_Social::init();
		MNU_Blog::init();
		TNM_Marketplace::init();
		TNM_Ledger::init();
		TNM_Payouts::init();
		TNM_Shortcodes::init();
		TNM_REST::init();
		MNU_Compat::init( true );
		MNU_Lifecycle::init();
		if ( is_admin() ) {
			MNU_Admin_Tables::init();
			MNU_Admin_Seller_Products::init();
		}
		MNU_Buyer_Experience::init();
		MNU_Ops::init();
		MNU_Connect::init();
		MNU_Catalog_Sort::init();
		MNU_Social_Frontend::init();
		MNU_Woo_Gateway_Loader::init();
		TNM_Admin::init();
		MNU_System::init( true, false );
		MNU_Product_Import::init();
		if ( is_admin() ) {
			MNU_Multi_Roles::init();
		}
		// v3.7.105 - Web parity surfaces (shortcodes, save-alert pill,
		// rating badges). ensure_pages() must NOT run on plugins_loaded
		// because wp_insert_post -> get_permalink touches $wp_rewrite,
		// which is only initialized on the 'init' hook. Defer to 'init'.
		MNU_Web_Parity::init();
		TNM_Order_Review_Card::init();
		TNM_Review_Nudge::init();
		if ( get_option( 'mnu_web_parity_pages_version', '' ) !== MNU_VERSION ) {
			add_action( 'init', array( 'MNU_Web_Parity', 'ensure_pages_once' ), 20 );
		}

		update_option( 'mnu_last_successful_boot', current_time( 'mysql', true ), false );
		do_action( 'mnu_marketplace_loaded', MNU_VERSION );
	}

	public function dependency_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) || class_exists( 'WooCommerce' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>MyNest Unified Marketplace:</strong> WooCommerce must be active.</p></div>';
	}

	public function legacy_conflict_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>MyNest Unified Marketplace did not start:</strong> another old MyNest/The Nest marketplace backend is active. Leave this plugin and WooCommerce active, then deactivate the other custom marketplace plugin.</p></div>';
	}
}

MNU_Plugin::instance();
