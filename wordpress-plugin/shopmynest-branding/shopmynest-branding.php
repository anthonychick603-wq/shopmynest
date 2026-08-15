<?php
/**
 * Plugin Name:       ShopMyNest Branding
 * Plugin URI:        https://shopmynest.com
 * Description:       Applies the ShopMyNest logo and brand identity across your WordPress + WooCommerce site: custom logo, favicons, wp-admin login screen, admin bar mark, and WooCommerce transactional email header.
 * Version:           1.3.0
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

define( 'SMN_BRANDING_VERSION', '1.3.0' );
define( 'SMN_BRANDING_DIR', plugin_dir_path( __FILE__ ) );
define( 'SMN_BRANDING_URL', plugin_dir_url( __FILE__ ) );
define( 'SMN_BRANDING_ASSETS', SMN_BRANDING_URL . 'assets/' );

/**
 * Brand color palette (from the ShopMyNest logo).
 */
function smn_branding_palette() {
    // Studio Clay palette — deep forest primary + clay accent on parchment.
    // History: teal+cream (v<=1.2.2) → Studio Clay (v1.3.0).
    return array(
        'primary'    => '#3C4B33', // Deep Forest
        'dark'       => '#2A3624', // Forest Dark
        'accent'     => '#B0553A', // Clay warm accent
        'background' => '#F5EFE4', // Parchment surface
        'card'       => '#FFFBF3', // Warm paper card
        'ink'        => '#26221C', // Bark near-black text
        'border'     => '#DFD3BE', // Sand border
        // Legacy alias so downstream code keeps working.
        'secondary'  => '#B0553A',
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
/* -----------------------------------------------------------
 * 9. Front-end storefront polish + layout upgrades
 * ----------------------------------------------------------- */
add_action( 'wp_enqueue_scripts', 'smn_branding_enqueue_storefront_css', 20 );
function smn_branding_enqueue_storefront_css() {
    wp_enqueue_style(
        'shopmynest-storefront',
        SMN_BRANDING_ASSETS . 'storefront.css',
        array(),
        SMN_BRANDING_VERSION
    );
    // Expose the plugin palette as CSS custom properties so templates and
    // future stylesheets can reference the exact same tokens.
    $p = smn_branding_palette();
    $css = ":root{" .
        "--sn-teal:"     . $p['primary']    . ";" .
        "--sn-teal-dk:"  . $p['dark']       . ";" .
        "--sn-cream:"    . $p['background'] . ";" .
        "--sn-card:"     . $p['card']       . ";" .
        "--sn-ink:"      . $p['ink']        . ";" .
        "--sn-border:"   . $p['border']     . ";" .
        "--sn-accent:"   . $p['accent']     . ";" .
        "--sn-shadow:0 6px 24px rgba(60,75,51,0.10);" .
        "--sn-shadow-lg:0 12px 32px rgba(60,75,51,0.16);" .
        "--sn-radius:14px;--sn-radius-sm:8px;--sn-radius-lg:24px;" .
        "}";
    wp_add_inline_style( 'shopmynest-storefront', $css );
}

/**
 * Shortcode: [shopmynest_seller_row] — shows the current product's seller
 * name and shop link. Renders nothing outside a single-product view.
 */
add_shortcode( 'shopmynest_seller_row', function() {
    if ( ! function_exists( 'is_product' ) || ! is_product() ) {
        return '';
    }
    global $product;
    if ( ! $product ) {
        $product = wc_get_product( get_the_ID() );
    }
    if ( ! $product ) {
        return '';
    }
    $author_id = (int) get_post_field( 'post_author', $product->get_id() );
    if ( $author_id <= 0 ) {
        return '';
    }
    $author = get_userdata( $author_id );
    if ( ! $author ) {
        return '';
    }
    $display   = $author->display_name ?: $author->user_login;
    $shop_url  = function_exists( 'tnm_seller_shop_url' ) ? tnm_seller_shop_url( $author_id ) : get_author_posts_url( $author_id );
    $initial   = strtoupper( mb_substr( $display, 0, 1 ) );
    return sprintf(
        '<div class="mynest-seller-row">' .
            '<div class="avatar-placeholder" aria-hidden="true">%s</div>' .
            '<div class="seller-info">' .
                '<div class="label">%s</div>' .
                '<a class="name" href="%s">%s</a>' .
            '</div>' .
        '</div>',
        esc_html( $initial ),
        esc_html__( 'Sold by', 'shopmynest-branding' ),
        esc_url( $shop_url ),
        esc_html( $display )
    );
} );

/**
 * Shortcode: [shopmynest_trust variant="product|cart"] — renders the trust
 * badges strip (secure payment, buyer protection, easy returns).
 */
add_shortcode( 'shopmynest_trust', function( $atts ) {
    $atts    = shortcode_atts( array( 'variant' => 'product' ), $atts );
    $badges  = array(
        array( '🔒', __( 'Secure checkout', 'shopmynest-branding' ), __( 'Stripe-powered payments', 'shopmynest-branding' ) ),
        array( '🛡️', __( 'Buyer protection', 'shopmynest-branding' ), __( 'Refund if it doesn\'t arrive', 'shopmynest-branding' ) ),
        array( '↩️', __( 'Easy returns', 'shopmynest-branding' ), __( 'See individual seller policies', 'shopmynest-branding' ) ),
    );
    $out = '<div class="mynest-trust-row">';
    foreach ( $badges as $b ) {
        $out .= sprintf(
            '<div class="mynest-trust"><span class="icon" aria-hidden="true">%s</span><span><span class="label">%s</span>%s</span></div>',
            esc_html( $b[0] ),
            esc_html( $b[1] ),
            esc_html( $b[2] )
        );
    }
    return $out . '</div>';
} );

/**
 * Shortcode: [shopmynest_category_grid] — renders the six category tiles
 * as text-only labels. Used on the home page and shop archive.
 */
add_shortcode( 'shopmynest_category_grid', function() {
    $cats = array(
        array( 'home-decor',        __( 'Home Decor', 'shopmynest-branding' ) ),
        array( 'jewelry',           __( 'Jewelry', 'shopmynest-branding' ) ),
        array( 'baby-children',     __( 'Baby & Children', 'shopmynest-branding' ) ),
        array( 'art-prints',        __( 'Art & Prints', 'shopmynest-branding' ) ),
        array( 'crochet-knit',      __( 'Crochet & Knit', 'shopmynest-branding' ) ),
        array( 'pottery-ceramics',  __( 'Pottery & Ceramics', 'shopmynest-branding' ) ),
    );
    $out = '<div class="mynest-cat-grid">';
    foreach ( $cats as $c ) {
        $out .= sprintf(
            '<a class="mynest-cat-tile" href="/product-category/%s/"><span>%s</span></a>',
            esc_attr( $c[0] ),
            esc_html( $c[1] )
        );
    }
    return $out . '</div>';
} );

/**
 * Shortcode: [shopmynest_values] — three-tile value-prop strip.
 */
add_shortcode( 'shopmynest_values', function() {
    $vals = array(
        array( '✨', __( 'Handmade by real people', 'shopmynest-branding' ), __( 'Every item is created by an independent maker or artisan.', 'shopmynest-branding' ) ),
        array( '💛', __( 'Support small shops', 'shopmynest-branding' ), __( 'Your purchase pays a real person, not an algorithm.', 'shopmynest-branding' ) ),
        array( '📦', __( 'Ships direct from the maker', 'shopmynest-branding' ), __( 'Get tracked shipping and packing straight from the shop.', 'shopmynest-branding' ) ),
    );
    $out = '<div class="mynest-values">';
    foreach ( $vals as $v ) {
        $out .= sprintf(
            '<div class="mynest-value"><span class="icon" aria-hidden="true">%s</span><h3>%s</h3><p>%s</p></div>',
            esc_html( $v[0] ),
            esc_html( $v[1] ),
            esc_html( $v[2] )
        );
    }
    return $out . '</div>';
} );

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
