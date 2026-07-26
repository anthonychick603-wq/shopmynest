<?php
/**
 * Plugin Name:       ShopMyNest Branding
 * Plugin URI:        https://shopmynest.com
 * Description:       Applies the ShopMyNest logo and brand identity across your WordPress + WooCommerce site: custom logo, favicons, wp-admin login screen, admin bar mark, and WooCommerce transactional email header.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            ShopMyNest
 * Author URI:        https://shopmynest.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       shopmynest-branding
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'SMN_BRANDING_VERSION', '1.0.0' );
define( 'SMN_BRANDING_DIR', plugin_dir_path( __FILE__ ) );
define( 'SMN_BRANDING_URL', plugin_dir_url( __FILE__ ) );
define( 'SMN_BRANDING_ASSETS', SMN_BRANDING_URL . 'assets/' );

/**
 * Brand color palette (from the ShopMyNest logo).
 */
function smn_branding_palette() {
    return array(
        'primary'    => '#6B3F23', // deep brown (wordmark)
        'secondary'  => '#D97A5B', // coral bird
        'accent'     => '#E8C9A5', // warm nest tan
        'background' => '#FAF4EB', // cream
        'dark'       => '#3A2A1E',
    );
}

/* -----------------------------------------------------------
 * 1. Front-end favicons + theme color
 * ----------------------------------------------------------- */
add_action( 'wp_head', 'smn_branding_frontend_head', 1 );
function smn_branding_frontend_head() {
    $palette = smn_branding_palette();
    ?>
    <link rel="icon" type="image/x-icon" href="<?php echo esc_url( SMN_BRANDING_ASSETS . 'favicon.ico' ); ?>">
    <link rel="icon" type="image/png" sizes="16x16"  href="<?php echo esc_url( SMN_BRANDING_ASSETS . 'favicon-16.png' ); ?>">
    <link rel="icon" type="image/png" sizes="32x32"  href="<?php echo esc_url( SMN_BRANDING_ASSETS . 'favicon-32.png' ); ?>">
    <link rel="icon" type="image/png" sizes="192x192" href="<?php echo esc_url( SMN_BRANDING_ASSETS . 'favicon-192.png' ); ?>">
    <link rel="apple-touch-icon" sizes="180x180" href="<?php echo esc_url( SMN_BRANDING_ASSETS . 'apple-touch-icon.png' ); ?>">
    <meta name="theme-color" content="<?php echo esc_attr( $palette['background'] ); ?>">
    <meta name="msapplication-TileColor" content="<?php echo esc_attr( $palette['background'] ); ?>">
    <?php
}

/* -----------------------------------------------------------
 * 2. Register the site icon programmatically (WP 5.9+ fallback)
 *    Only used if the site has no custom Site Icon set.
 * ----------------------------------------------------------- */
add_filter( 'get_site_icon_url', 'smn_branding_site_icon_url', 10, 3 );
function smn_branding_site_icon_url( $url, $size, $blog_id ) {
    if ( ! empty( $url ) ) {
        return $url;
    }
    $size = (int) $size;
    if ( $size <= 32 )       return SMN_BRANDING_ASSETS . 'favicon-32.png';
    if ( $size <= 48 )       return SMN_BRANDING_ASSETS . 'favicon-48.png';
    if ( $size <= 96 )       return SMN_BRANDING_ASSETS . 'favicon-96.png';
    if ( $size <= 180 )      return SMN_BRANDING_ASSETS . 'favicon-180.png';
    if ( $size <= 192 )      return SMN_BRANDING_ASSETS . 'favicon-192.png';
    return SMN_BRANDING_ASSETS . 'favicon-512.png';
}

/* -----------------------------------------------------------
 * 3. wp-admin login screen branding
 * ----------------------------------------------------------- */
add_action( 'login_enqueue_scripts', 'smn_branding_login_styles' );
function smn_branding_login_styles() {
    $palette = smn_branding_palette();
    ?>
    <style type="text/css">
        body.login {
            background: <?php echo esc_attr( $palette['background'] ); ?>;
        }
        #login h1 a, .login h1 a {
            background-image: url('<?php echo esc_url( SMN_BRANDING_ASSETS . 'login-logo.png' ); ?>');
            background-size: contain;
            background-repeat: no-repeat;
            background-position: center;
            height: 120px;
            width: 320px;
            margin: 0 auto 16px;
        }
        .login form {
            border-radius: 12px;
            box-shadow: 0 6px 24px rgba(107, 63, 35, 0.10);
            border: 1px solid <?php echo esc_attr( $palette['accent'] ); ?>;
        }
        .wp-core-ui .button-primary {
            background: <?php echo esc_attr( $palette['primary'] ); ?>;
            border-color: <?php echo esc_attr( $palette['primary'] ); ?>;
            box-shadow: 0 1px 0 <?php echo esc_attr( $palette['dark'] ); ?>;
            text-shadow: none;
        }
        .wp-core-ui .button-primary:hover,
        .wp-core-ui .button-primary:focus {
            background: <?php echo esc_attr( $palette['dark'] ); ?>;
            border-color: <?php echo esc_attr( $palette['dark'] ); ?>;
        }
        .login #backtoblog a, .login #nav a {
            color: <?php echo esc_attr( $palette['primary'] ); ?>;
        }
    </style>
    <?php
}

add_filter( 'login_headerurl',  function() { return home_url( '/' ); } );
add_filter( 'login_headertext', function() { return get_bloginfo( 'name' ) . ' — ShopMyNest'; } );

/* -----------------------------------------------------------
 * 4. Admin bar: replace the WordPress logo with the nest mark
 * ----------------------------------------------------------- */
add_action( 'admin_bar_menu', 'smn_branding_admin_bar', 11 );
function smn_branding_admin_bar( $wp_admin_bar ) {
    $wp_admin_bar->remove_node( 'wp-logo' );
    $wp_admin_bar->add_node( array(
        'id'    => 'smn-logo',
        'title' => '<span class="ab-icon smn-ab-icon"></span><span class="screen-reader-text">ShopMyNest</span>',
        'href'  => admin_url(),
        'meta'  => array( 'title' => 'ShopMyNest' ),
    ) );
}
add_action( 'admin_head', 'smn_branding_admin_bar_style' );
add_action( 'wp_head',    'smn_branding_admin_bar_style' );
function smn_branding_admin_bar_style() {
    if ( ! is_admin_bar_showing() ) return;
    ?>
    <style type="text/css">
        #wpadminbar .smn-ab-icon {
            background-image: url('<?php echo esc_url( SMN_BRANDING_ASSETS . 'favicon-48.png' ); ?>');
            background-size: 20px 20px;
            background-repeat: no-repeat;
            background-position: center center;
            width: 24px; height: 24px; float: left; margin-top: 6px;
        }
        #wpadminbar .smn-ab-icon::before { content: ""; }
    </style>
    <?php
}

/* -----------------------------------------------------------
 * 5. Admin footer credit
 * ----------------------------------------------------------- */
add_filter( 'admin_footer_text', function( $text ) {
    return 'Powered by <strong>ShopMyNest</strong> · Handmade & pre-loved marketplace';
} );

/* -----------------------------------------------------------
 * 6. WooCommerce transactional email header + colors
 * ----------------------------------------------------------- */
add_action( 'woocommerce_email_header', 'smn_branding_woo_email_header', 5 );
function smn_branding_woo_email_header( $email_heading ) {
    // If Woo already has a header image configured in settings, don't stomp on it.
    if ( get_option( 'woocommerce_email_header_image' ) ) return;
    ?>
    <div style="text-align:center; padding:24px 0; background:#FAF4EB;">
        <img src="<?php echo esc_url( SMN_BRANDING_ASSETS . 'email-header.png' ); ?>"
             alt="ShopMyNest"
             style="max-width:280px; height:auto; display:inline-block;" />
    </div>
    <?php
}

add_filter( 'woocommerce_email_settings', 'smn_branding_woo_email_defaults' );
function smn_branding_woo_email_defaults( $settings ) {
    $palette = smn_branding_palette();
    foreach ( $settings as &$s ) {
        if ( ! is_array( $s ) || empty( $s['id'] ) ) continue;
        if ( $s['id'] === 'woocommerce_email_base_color'       && empty( get_option( $s['id'] ) ) ) $s['default'] = $palette['primary'];
        if ( $s['id'] === 'woocommerce_email_background_color' && empty( get_option( $s['id'] ) ) ) $s['default'] = $palette['background'];
        if ( $s['id'] === 'woocommerce_email_body_background_color' && empty( get_option( $s['id'] ) ) ) $s['default'] = '#FFFFFF';
        if ( $s['id'] === 'woocommerce_email_text_color'       && empty( get_option( $s['id'] ) ) ) $s['default'] = $palette['dark'];
    }
    return $settings;
}

/* -----------------------------------------------------------
 * 7. Custom logo support (works with themes that use the standard hook)
 * ----------------------------------------------------------- */
add_action( 'after_setup_theme', function() {
    add_theme_support( 'custom-logo', array(
        'height'      => 200,
        'width'       => 200,
        'flex-height' => true,
        'flex-width'  => true,
    ) );
} );

/**
 * Shortcode: [shopmynest_logo size="300"] — insert the logo anywhere.
 */
add_shortcode( 'shopmynest_logo', function( $atts ) {
    $atts = shortcode_atts( array( 'size' => '300', 'alt' => 'ShopMyNest' ), $atts );
    $size = in_array( (int) $atts['size'], array( 300, 512, 1024 ), true ) ? (int) $atts['size'] : 300;
    return sprintf(
        '<img src="%s" alt="%s" width="%d" style="max-width:100%%;height:auto;" />',
        esc_url( SMN_BRANDING_ASSETS . 'logo-' . $size . '.png' ),
        esc_attr( $atts['alt'] ),
        $size
    );
} );

/* -----------------------------------------------------------
 * 8. Settings link on the Plugins page
 * ----------------------------------------------------------- */
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function( $links ) {
    $links[] = '<a href="' . esc_url( admin_url( 'options-general.php?page=shopmynest-branding' ) ) . '">Settings</a>';
    return $links;
} );

add_action( 'admin_menu', function() {
    add_options_page(
        'ShopMyNest Branding',
        'ShopMyNest',
        'manage_options',
        'shopmynest-branding',
        'smn_branding_settings_page'
    );
} );

function smn_branding_settings_page() {
    $palette = smn_branding_palette();
    ?>
    <div class="wrap">
        <h1>ShopMyNest Branding</h1>
        <p>This plugin applies the ShopMyNest logo and brand colors across your site automatically. No configuration needed.</p>
        <h2>What it does</h2>
        <ul style="list-style:disc;padding-left:24px;">
            <li>Adds favicons, apple-touch-icon and theme color to the site &lt;head&gt;.</li>
            <li>Rebrands the wp-admin login screen with the ShopMyNest logo and brand colors.</li>
            <li>Replaces the WordPress logo in the admin bar with the ShopMyNest mark.</li>
            <li>Adds a branded header to WooCommerce transactional emails (unless you've set one in WooCommerce → Settings → Emails).</li>
            <li>Registers the site icon so any theme calling <code>get_site_icon_url()</code> gets the ShopMyNest logo.</li>
            <li>Provides <code>[shopmynest_logo size="300"]</code> shortcode.</li>
        </ul>
        <h2>Brand palette</h2>
        <div style="display:flex;gap:12px;flex-wrap:wrap;">
            <?php foreach ( $palette as $name => $hex ) : ?>
                <div style="text-align:center;">
                    <div style="width:80px;height:80px;background:<?php echo esc_attr( $hex ); ?>;border:1px solid #ddd;border-radius:8px;"></div>
                    <div style="font-family:monospace;font-size:12px;margin-top:4px;"><?php echo esc_html( $hex ); ?></div>
                    <div style="font-size:11px;color:#666;"><?php echo esc_html( $name ); ?></div>
                </div>
            <?php endforeach; ?>
        </div>
        <h2>Logo preview</h2>
        <img src="<?php echo esc_url( SMN_BRANDING_ASSETS . 'logo-512.png' ); ?>" style="max-width:300px;height:auto;background:#FAF4EB;padding:16px;border-radius:12px;" />
    </div>
    <?php
}
