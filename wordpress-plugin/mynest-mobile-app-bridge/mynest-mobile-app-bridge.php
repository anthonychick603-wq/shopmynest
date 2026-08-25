<?php
/**
 * Plugin Name: MyNest Mobile App Bridge
 * Plugin URI:  https://shopmynest.com/
 * Description: Adds mobile buyer endpoints, moderated community posts for the home feed, an app permissions endpoint, reliable bearer-token authentication, and safe Stripe Tax sandbox checkout compatibility for The Nest Android app.
 * Version:     1.2.4
 * Author:      MyNest
 * Text Domain: mynest-mobile-app-bridge
 * Requires at least: 6.5
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce,mynest-unified-marketplace
 * WC requires at least: 8.0
 * WC tested up to: 10.9
 */

defined( 'ABSPATH' ) || exit;

final class MyNest_Mobile_App_Bridge {
    private const VERSION            = '1.2.1';
    private const NS                 = 'the-nest/v1';
    private const COMMUNITY_TYPE     = 'mynest_community_post';
    private const COMMUNITY_MENU     = 'mynest-community-posts';
    private const COMMUNITY_PER_PAGE = 20;

    private static bool $stripe_tax_sandbox_fallback_active = false;

    public static function init(): void {
        add_action( 'before_woocommerce_init', array( __CLASS__, 'declare_woocommerce_compatibility' ) );

        // WordPress hosts and reverse proxies do not always expose the standard
        // Authorization header to PHP. The app sends both Authorization and the
        // X-MyNest-Token fallback header, and this filter accepts either one.
        add_filter( 'determine_current_user', array( __CLASS__, 'authenticate_mobile_token' ), 5 );
        add_filter( 'rest_pre_dispatch', array( __CLASS__, 'authenticate_rest_request' ), 5, 3 );

        add_action( 'init', array( __CLASS__, 'register_report_type' ), 20 );
        add_action( 'init', array( __CLASS__, 'register_community_post_type' ), 20 );
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ), 30 );

        // Late priority so the MyNest Unified Marketplace parent menu exists.
        add_action( 'admin_menu', array( __CLASS__, 'register_community_menu' ), 99 );
        add_action( 'admin_post_mynest_community_moderate', array( __CLASS__, 'handle_community_moderation_action' ) );

        // The supplied site has Stripe Tax enabled in Sandbox mode. When that
        // sandbox connector fails, it blocks WooCommerce Store API checkout with
        // "Taxes have not been calculated at the moment." In Sandbox only, use
        // WooCommerce's normal tax tables so test orders can still be placed.
        add_action( 'plugins_loaded', array( __CLASS__, 'configure_stripe_tax_sandbox_fallback' ), 999 );
        add_action( 'init', array( __CLASS__, 'configure_stripe_tax_sandbox_fallback' ), 999 );
        add_action( 'wp_loaded', array( __CLASS__, 'configure_stripe_tax_sandbox_fallback' ), 1 );
        add_action( 'rest_api_init', array( __CLASS__, 'configure_stripe_tax_sandbox_fallback' ), 999 );

        add_action( 'admin_notices', array( __CLASS__, 'dependency_notice' ) );
        add_action( 'admin_notices', array( __CLASS__, 'stripe_tax_sandbox_notice' ) );
        add_action( 'admin_notices', array( __CLASS__, 'community_pending_notice' ) );
    }

    public static function declare_woocommerce_compatibility(): void {
        if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
            return;
        }

        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );

        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'cart_checkout_blocks',
            __FILE__,
            true
        );
    }

    public static function dependency_notice(): void {
        if ( ! current_user_can( 'activate_plugins' ) ) {
            return;
        }
        if ( class_exists( 'WooCommerce' ) && class_exists( 'TNM_REST' ) ) {
            return;
        }
        echo '<div class="notice notice-error"><p><strong>MyNest Mobile App Bridge:</strong> WooCommerce and MyNest Unified Marketplace must be active.</p></div>';
    }

    public static function stripe_tax_sandbox_notice(): void {
        if ( ! self::$stripe_tax_sandbox_fallback_active || ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( $screen && ! in_array( $screen->id, array( 'plugins', 'woocommerce_page_wc-settings', 'dashboard' ), true ) ) {
            return;
        }

        echo '<div class="notice notice-warning"><p><strong>MyNest checkout compatibility:</strong> Stripe Tax is currently in Sandbox mode, so failed sandbox tax requests are falling back to WooCommerce tax tables instead of blocking checkout. Configure Stripe Tax and switch it to Live mode before collecting real sales tax.</p></div>';
    }

    /**
     * Disable only the Stripe Tax sandbox handlers. Live mode is never bypassed.
     * This prevents a failed test connector from blocking both the website block
     * checkout and the mobile app while preserving live-mode tax safeguards.
     */
    public static function configure_stripe_tax_sandbox_fallback(): void {
        $options_class = '\\Stripe\\StripeTaxForWooCommerce\\WordPress\\Options';
        $plugin_class  = '\\Stripe\\StripeTaxForWooCommerce\\WordPress\\StripeTax_Plugin';
        $hooks_class   = '\\Stripe\\StripeTaxForWooCommerce\\WordPress\\Hooks';

        if ( ! class_exists( $options_class ) ) {
            return;
        }

        $enabled = (bool) apply_filters( 'mynest_mobile_stripe_tax_sandbox_fallback', true );
        if ( ! $enabled ) {
            return;
        }

        if ( is_callable( array( $options_class, 'is_live_mode_enabled' ) ) && ! $options_class::is_live_mode_enabled() ) {
            return;
        }

        $mode = (int) $options_class::get_mode_type();
        if ( defined( $options_class . '::MODE_TEST' ) ) {
            $test_mode = (int) constant( $options_class . '::MODE_TEST' );
        } else {
            $test_mode = 1;
        }

        if ( $mode !== $test_mode ) {
            return;
        }

        if ( class_exists( $plugin_class ) && is_callable( array( $plugin_class, 'unload' ) ) ) {
            $plugin_class::unload();
        }

        if ( class_exists( $hooks_class ) ) {
            remove_action(
                'woocommerce_store_api_checkout_order_processed',
                array( $hooks_class, 'handle_checkout_order_notice' ),
                10
            );
        }

        self::$stripe_tax_sandbox_fallback_active = true;
    }

    private static function token_from_request( ?WP_REST_Request $request = null ): string {
        $headers = array();

        if ( $request ) {
            $headers[] = (string) $request->get_header( 'x-mynest-token' );
            $headers[] = (string) $request->get_header( 'authorization' );
        }

        $server_keys = array(
            'HTTP_X_MYNEST_TOKEN',
            'HTTP_AUTHORIZATION',
            'REDIRECT_HTTP_AUTHORIZATION',
        );
        foreach ( $server_keys as $key ) {
            if ( isset( $_SERVER[ $key ] ) ) {
                $headers[] = (string) wp_unslash( $_SERVER[ $key ] );
            }
        }

        if ( function_exists( 'apache_request_headers' ) ) {
            $apache_headers = apache_request_headers();
            if ( is_array( $apache_headers ) ) {
                foreach ( array( 'X-MyNest-Token', 'x-mynest-token', 'Authorization', 'authorization' ) as $name ) {
                    if ( isset( $apache_headers[ $name ] ) ) {
                        $headers[] = (string) $apache_headers[ $name ];
                    }
                }
            }
        }

        foreach ( $headers as $header ) {
            $header = trim( $header );
            if ( ! $header ) {
                continue;
            }
            if ( 0 === stripos( $header, 'Bearer ' ) ) {
                $header = trim( substr( $header, 7 ) );
            }
            if ( $header ) {
                return sanitize_text_field( $header );
            }
        }

        return '';
    }

    private static function user_id_from_token( string $token ): int {
        if ( ! $token ) {
            return 0;
        }

        if ( class_exists( 'TNM_Auth' ) && is_callable( array( 'TNM_Auth', 'decode_token' ) ) ) {
            $payload = TNM_Auth::decode_token( $token );
            if ( ! is_wp_error( $payload ) && ! empty( $payload['sub'] ) ) {
                return absint( $payload['sub'] );
            }
        }

        // Preserve sessions issued by older versions of The Nest mobile app.
        $legacy_users = get_users(
            array(
                'meta_key'   => '_nest_mobile_token',
                'meta_value' => sanitize_text_field( $token ),
                'number'     => 1,
                'fields'     => 'ID',
            )
        );

        return $legacy_users ? absint( $legacy_users[0] ) : 0;
    }

    public static function authenticate_mobile_token( int|false $user_id ): int|false {
        if ( $user_id ) {
            return $user_id;
        }

        $token = self::token_from_request();
        $found = self::user_id_from_token( $token );
        return $found ?: $user_id;
    }

    public static function authenticate_rest_request( mixed $result, WP_REST_Server $server, WP_REST_Request $request ): mixed {
        if ( get_current_user_id() ) {
            return $result;
        }

        $route = (string) $request->get_route();
        if ( ! preg_match( '#^/(?:the-nest|nest-ops|nest-native|nest-labels|nest-shipping)/v1/#', $route ) ) {
            return $result;
        }

        $user_id = self::user_id_from_token( self::token_from_request( $request ) );
        if ( $user_id ) {
            wp_set_current_user( $user_id );
        }

        return $result;
    }

    private static function ensure_current_user( ?WP_REST_Request $request = null ): int {
        $user_id = get_current_user_id();
        if ( $user_id ) {
            return $user_id;
        }

        $user_id = self::user_id_from_token( self::token_from_request( $request ) );
        if ( $user_id ) {
            wp_set_current_user( $user_id );
        }

        return $user_id;
    }

    public static function register_report_type(): void {
        register_post_type(
            'mynest_report',
            array(
                'labels' => array(
                    'name'          => 'Product Reports',
                    'singular_name' => 'Product Report',
                    'menu_name'     => 'Product Reports',
                ),
                'public'              => false,
                'show_ui'             => true,
                'show_in_menu'        => class_exists( 'TNM_Admin' ) ? 'tnm-marketplace' : 'woocommerce',
                'supports'            => array( 'title', 'editor', 'author' ),
                'capability_type'     => 'post',
                'map_meta_cap'        => true,
                'exclude_from_search' => true,
                'show_in_rest'        => false,
            )
        );
    }

    public static function register_routes(): void {
        register_rest_route(
            self::NS,
            '/mobile-health',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( __CLASS__, 'mobile_health' ),
                'permission_callback' => '__return_true',
            )
        );
        register_rest_route(
            self::NS,
            '/orders',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( __CLASS__, 'orders' ),
                'permission_callback' => array( __CLASS__, 'logged_in' ),
            )
        );
        register_rest_route(
            self::NS,
            '/orders/(?P<id>\d+)',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( __CLASS__, 'order' ),
                'permission_callback' => array( __CLASS__, 'logged_in' ),
            )
        );
        // v3.7.121 (Build #16) — buyer-initiated cancel. Only the customer
        // on the order can call this; permission enforced inside the
        // callback because we need to open the order to check identity.
        register_rest_route(
            self::NS,
            '/orders/(?P<id>\d+)/cancel',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( __CLASS__, 'cancel_order' ),
                'permission_callback' => array( __CLASS__, 'logged_in' ),
            )
        );
        register_rest_route(
            self::NS,
            '/products/(?P<id>\d+)/report',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( __CLASS__, 'report_product' ),
                'permission_callback' => array( __CLASS__, 'logged_in' ),
            )
        );
        register_rest_route(
            self::NS,
            '/seller/application/status',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( __CLASS__, 'application_status' ),
                'permission_callback' => array( __CLASS__, 'logged_in' ),
            )
        );
        register_rest_route(
            self::NS,
            '/account/photo/upload',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( __CLASS__, 'upload_account_photo' ),
                'permission_callback' => array( __CLASS__, 'logged_in' ),
            )
        );
        register_rest_route(
            self::NS,
            '/community/posts',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( __CLASS__, 'community_posts' ),
                    'permission_callback' => '__return_true',
                ),
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array( __CLASS__, 'create_community_post' ),
                    'permission_callback' => array( __CLASS__, 'logged_in' ),
                ),
            )
        );
        register_rest_route(
            self::NS,
            '/community/moderation/posts',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( __CLASS__, 'community_moderation_posts' ),
                'permission_callback' => array( __CLASS__, 'can_moderate_community' ),
            )
        );
        register_rest_route(
            self::NS,
            '/community/moderation/posts/(?P<id>\d+)/approve',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( __CLASS__, 'approve_community_post' ),
                'permission_callback' => array( __CLASS__, 'can_moderate_community' ),
            )
        );
        register_rest_route(
            self::NS,
            '/community/moderation/posts/(?P<id>\d+)/reject',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( __CLASS__, 'reject_community_post' ),
                'permission_callback' => array( __CLASS__, 'can_moderate_community' ),
            )
        );
        register_rest_route(
            self::NS,
            '/auth/me/permissions',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( __CLASS__, 'auth_me_permissions' ),
                'permission_callback' => array( __CLASS__, 'logged_in' ),
            )
        );
    }

    /**
     * Reports the current user's capability flags to the mobile app so it can
     * hide admin-only surfaces (Blog moderation, seller review, etc.) from
     * non-admin Makers. Kept in the bridge plugin so it does not depend on the
     * main marketplace plugin's /auth/me implementation. Uses the existing
     * community_capability() helper so both community moderation and the app's
     * gate agree on who is an admin for Blog purposes.
     */
    public static function auth_me_permissions(): WP_REST_Response {
        $user_id                = get_current_user_id();
        $can_moderate_community = $user_id > 0 && current_user_can( self::community_capability() );
        $is_admin               = $user_id > 0 && user_can( $user_id, 'manage_options' );

        $permissions = array(
            'user_id'                => $user_id,
            'is_admin'               => $is_admin,
            'can_moderate_community' => $can_moderate_community,
            'can_manage_woocommerce' => $user_id > 0 && user_can( $user_id, 'manage_woocommerce' ),
            'roles'                  => $user_id > 0 ? array_values( (array) wp_get_current_user()->roles ) : array(),
        );

        /**
         * Filter the permissions payload returned to the mobile app.
         *
         * @param array $permissions Permission flags keyed by name.
         * @param int   $user_id     Current user ID (0 if none — should not
         *                           happen because the route requires auth).
         */
        $permissions = (array) apply_filters( 'mynest_mobile_auth_permissions', $permissions, $user_id );

        return rest_ensure_response( $permissions );
    }

    public static function mobile_health(): WP_REST_Response {
        return rest_ensure_response(
            array(
                'ok'                          => true,
                'version'                     => self::VERSION,
                'authenticated'               => get_current_user_id() > 0,
                'stripe_tax_sandbox_fallback' => self::$stripe_tax_sandbox_fallback_active,
            )
        );
    }

    public static function logged_in( WP_REST_Request $request ): bool|WP_Error {
        if ( self::ensure_current_user( $request ) ) {
            return true;
        }
        return new WP_Error( 'rest_login_required', 'Authentication is required.', array( 'status' => 401 ) );
    }

    public static function orders( WP_REST_Request $request ): WP_REST_Response {
        $page     = max( 1, absint( $request->get_param( 'page' ) ?: 1 ) );
        $per_page = max( 1, min( 100, absint( $request->get_param( 'per_page' ) ?: 20 ) ) );
        $result   = wc_get_orders(
            array(
                'customer_id' => get_current_user_id(),
                'limit'       => $per_page,
                'page'        => $page,
                'paginate'    => true,
                'orderby'     => 'date',
                'order'       => 'DESC',
                'status'      => array_keys( wc_get_order_statuses() ),
                'return'      => 'objects',
            )
        );

        return rest_ensure_response(
            array(
                'orders'      => array_map( array( __CLASS__, 'order_to_array' ), $result->orders ),
                'page'        => $page,
                'total'       => (int) $result->total,
                'total_pages' => (int) $result->max_num_pages,
            )
        );
    }

    public static function order( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $order = wc_get_order( absint( $request['id'] ) );
        if ( ! $order ) {
            return new WP_Error( 'order_not_found', 'Order not found.', array( 'status' => 404 ) );
        }
        $user_id = get_current_user_id();
        $is_buyer = (int) $order->get_customer_id() === $user_id;
        $is_admin = user_can( $user_id, 'manage_woocommerce' );
        // v3.7.84 — sellers with an item in the order can view it too. The
        // mobile app opens this endpoint from both buyer and seller order
        // lists, and before this fix a seller viewing an order they only
        // fulfilled (never bought) got a 403 which surfaced as the lock
        // screen “This order belongs to another buyer”.
        $is_seller_on_order = false;
        if ( ! $is_buyer && ! $is_admin && $user_id > 0 ) {
            foreach ( $order->get_items() as $item ) {
                if ( ! $item instanceof WC_Order_Item_Product ) { continue; }
                $seller_id = function_exists( 'tnm_get_order_item_seller_id' ) ? (int) tnm_get_order_item_seller_id( $item ) : 0;
                if ( $seller_id === $user_id ) { $is_seller_on_order = true; break; }
            }
        }
        if ( ! $is_buyer && ! $is_admin && ! $is_seller_on_order ) {
            return new WP_Error( 'order_permission_denied', 'You cannot view this order.', array( 'status' => 403 ) );
        }
        return rest_ensure_response( self::order_to_array( $order ) );
    }

    /**
     * v3.7.121 (Build #16) — buyer-initiated order cancellation. Only
     * pending / on-hold orders with no shipments can be cancelled outright.
     * A processing (paid) order is directed to the refund lifecycle so we
     * don't strand a captured payment. When a seller has already shipped
     * anything we refuse and leave the buyer to open a dispute instead.
     */
    public static function cancel_order( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $order = wc_get_order( absint( $request['id'] ) );
        if ( ! $order ) {
            return new WP_Error( 'order_not_found', 'Order not found.', array( 'status' => 404 ) );
        }
        $user_id = get_current_user_id();
        if ( (int) $order->get_customer_id() !== $user_id && ! user_can( $user_id, 'manage_woocommerce' ) ) {
            return new WP_Error( 'order_permission_denied', 'You cannot cancel this order.', array( 'status' => 403 ) );
        }

        // Reject cancels once anything has shipped — the buyer should open a
        // dispute in that case, not silently mark the order cancelled.
        $shipped_any = false;
        foreach ( $order->get_items() as $item ) {
            if ( ! $item instanceof WC_Order_Item_Product ) { continue; }
            $seller_id = function_exists( 'tnm_get_order_item_seller_id' ) ? (int) tnm_get_order_item_seller_id( $item ) : 0;
            if ( ! $seller_id ) { continue; }
            $seller_status = (string) $order->get_meta( '_tnm_seller_status_' . $seller_id, true );
            if ( 'shipped' === $seller_status || 'completed' === $seller_status ) { $shipped_any = true; break; }
        }
        if ( $shipped_any ) {
            return new WP_Error( 'order_already_shipped', 'This order has already shipped. Open a dispute for a refund.', array( 'status' => 409 ) );
        }

        $status = $order->get_status();
        if ( in_array( $status, array( 'processing', 'completed' ), true ) ) {
            return new WP_Error( 'order_paid_use_refund', 'This order is paid. Please request a refund instead of a cancellation.', array( 'status' => 409 ) );
        }
        if ( in_array( $status, array( 'cancelled', 'refunded', 'failed' ), true ) ) {
            return new WP_Error( 'order_already_closed', 'This order is already closed.', array( 'status' => 409 ) );
        }

        $reason = sanitize_text_field( (string) $request->get_param( 'reason' ) );
        $note   = $reason !== '' ? sprintf( 'Buyer cancelled: %s', $reason ) : 'Buyer cancelled from mobile app.';
        $order->update_status( 'cancelled', $note );

        return rest_ensure_response( self::order_to_array( wc_get_order( $order->get_id() ) ) );
    }

    public static function order_to_array( WC_Order $order ): array {
        $items      = array();
        $tracking   = array();
        $seller_ids = array();

        foreach ( $order->get_items() as $item_id => $item ) {
            if ( ! $item instanceof WC_Order_Item_Product ) {
                continue;
            }
            $product    = $item->get_product();
            $seller_id = function_exists( 'tnm_get_order_item_seller_id' ) ? tnm_get_order_item_seller_id( $item ) : 0;
            if ( $seller_id ) {
                $seller_ids[ $seller_id ] = true;
            }
            $items[] = array(
                'item_id'      => (int) $item_id,
                'product_id'   => (int) $item->get_product_id(),
                'variation_id' => (int) $item->get_variation_id(),
                'name'         => $item->get_name(),
                'quantity'     => (int) $item->get_quantity(),
                'subtotal'     => (float) $item->get_subtotal(),
                'total'        => (float) $item->get_total(),
                'tax'          => (float) $item->get_total_tax(),
                'image'        => $product ? wp_get_attachment_image_url( $product->get_image_id(), 'medium' ) : '',
                'seller_id'    => $seller_id,
                'seller_name'  => $seller_id && function_exists( 'tnm_seller_display_name' ) ? tnm_seller_display_name( $seller_id ) : '',
            );
        }

        // v3.7.95 - buyers used to see a single seller-name-as-carrier line
        // even on multi-seller orders. Emit one row per seller with the
        // metadata we already store (carrier, service, tracking url, label
        // source) so the app can render a real "USPS 9400... - track this"
        // card and the buyer can tap through to the carrier.
        foreach ( array_keys( $seller_ids ) as $seller_id ) {
            $suffix         = '_' . $seller_id;
            $number         = sanitize_text_field( (string) $order->get_meta( '_tnm_tracking_' . $seller_id, true ) );
            $carrier        = sanitize_text_field( (string) $order->get_meta( '_thenest_tracking_carrier' . $suffix, true ) );
            $service        = sanitize_text_field( (string) $order->get_meta( '_thenest_shipping_service' . $suffix, true ) );
            $tracking_url   = esc_url_raw( (string) $order->get_meta( '_thenest_tracking_url' . $suffix, true ) );
            $label_url      = esc_url_raw( (string) $order->get_meta( '_thenest_label_url' . $suffix, true ) );
            $shipped_at     = sanitize_text_field( (string) $order->get_meta( '_tnm_seller_shipped_at' . $suffix, true ) );
            $seller_status  = sanitize_key( (string) $order->get_meta( '_tnm_seller_status_' . $seller_id, true ) );
            if ( ! $number && ! $seller_status ) {
                continue;
            }
            if ( ! $tracking_url && $number ) {
                $tracking_url = self::guess_tracking_url( $carrier, $number );
            }
            $tracking[] = array(
                'seller_id'    => (int) $seller_id,
                'seller_name'  => function_exists( 'tnm_seller_display_name' ) ? tnm_seller_display_name( $seller_id ) : '',
                'number'       => $number,
                'carrier'      => $carrier,
                'service'      => $service,
                'tracking_url' => $tracking_url,
                'label_source' => $label_url ? 'shippo' : ( $number ? 'manual' : '' ),
                'shipped_at'   => $shipped_at,
                'status'       => $seller_status,
            );
        }

        // v3.7.95 - derive an aggregate shipping_status across all sellers on
        // the order so the buyer sees "partial" instead of just "processing"
        // when one of two sellers has shipped. The adapter on mobile uses
        // this to map to the buyer-facing status vocabulary.
        $shipping_status = self::aggregate_shipping_status( $order, array_keys( $seller_ids ), $tracking );
        // v3.7.108 — filter out sellers the buyer has already reviewed on this
        // order, so the mobile OrderReviewCTA and the web "leave a review" card
        // stop nagging once every seller on the order has been rated.
        $can_review            = false;
        $reviewable_seller_ids = array();
        if ( in_array( $order->get_status(), array( 'processing', 'completed' ), true ) ) {
            $order_seller_ids = array_map( 'intval', array_keys( $seller_ids ) );
            $already_reviewed = array();
            if ( ! empty( $order_seller_ids ) && function_exists( 'tnm_table' ) ) {
                global $wpdb;
                $reviews_table    = tnm_table( 'reviews' );
                $buyer_id         = (int) $order->get_customer_id();
                $order_id         = (int) $order->get_id();
                $placeholders     = implode( ',', array_fill( 0, count( $order_seller_ids ), '%d' ) );
                $params           = array_merge( array( $buyer_id, $order_id ), $order_seller_ids );
                $sql              = "SELECT DISTINCT seller_id FROM {$reviews_table} WHERE reviewer_id = %d AND order_id = %d AND seller_id IN ({$placeholders})";
                $already_reviewed = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $sql, $params ) ) );
            }
            foreach ( $order_seller_ids as $sid ) {
                if ( in_array( $sid, $already_reviewed, true ) ) {
                    continue;
                }
                $reviewable_seller_ids[] = $sid;
            }
            $can_review = ! empty( $reviewable_seller_ids );
        }

        // v3.7.121 (Build #16) — timeline block. The mobile order screen
        // already renders a Placed → Paid → Shipped → Delivered progress
        // bar; give it per-step timestamps so the buyer can see WHEN each
        // step happened without opening the tracking card. shipped_at is
        // the earliest ship timestamp across all sellers (the order enters
        // the "Shipped" state as soon as any seller has shipped).
        $shipped_at = null;
        foreach ( $tracking as $row ) {
            $ts = isset( $row['shipped_at'] ) ? (string) $row['shipped_at'] : '';
            if ( '' === $ts ) { continue; }
            if ( null === $shipped_at || strtotime( $ts ) < strtotime( $shipped_at ) ) {
                $shipped_at = $ts;
            }
        }

        // v3.7.121 (Build #16) — buyer-side cancel eligibility. Buyers can
        // request cancellation while the order is pending/on-hold and no
        // seller has shipped yet. Once payment is captured (processing) we
        // route the buyer to the refund flow instead so we don't strand
        // money in an authorised-then-cancelled state.
        $cancellable_by_buyer = in_array( $order->get_status(), array( 'pending', 'on-hold' ), true ) && 'awaiting' === $shipping_status;

        return array(
            'id'              => $order->get_id(),
            'number'          => $order->get_order_number(),
            'status'          => $order->get_status(),
            // v1.2.4 — buyer identity on the order so a seller-buyer
            // account viewing their own purchase in the app can be forced
            // into the buyer-framed screen even if the order somehow
            // matched the seller-orders list. Anthony bought #3529 from
            // Jo but saw the seller view because a stale `_tnm_seller_ids`
            // stamp put his uid on the CSV. This lets the mobile client
            // gate on identity, not just on "appears in my seller list".
            'customer_id'     => (int) $order->get_customer_id(),
            'shipping_status' => $shipping_status,
            'date_created'    => $order->get_date_created() ? $order->get_date_created()->date( DATE_ATOM ) : null,
            'date_paid'       => $order->get_date_paid() ? $order->get_date_paid()->date( DATE_ATOM ) : null,
            'date_completed'  => $order->get_date_completed() ? $order->get_date_completed()->date( DATE_ATOM ) : null,
            // v3.7.121 (Build #16)
            'timeline'        => array(
                'placed'    => $order->get_date_created() ? $order->get_date_created()->date( DATE_ATOM ) : null,
                'paid'      => $order->get_date_paid() ? $order->get_date_paid()->date( DATE_ATOM ) : null,
                'shipped'   => $shipped_at,
                'delivered' => $order->get_date_completed() ? $order->get_date_completed()->date( DATE_ATOM ) : null,
            ),
            'cancellable'     => $cancellable_by_buyer,
            'currency'        => $order->get_currency(),
            'subtotal'        => (float) $order->get_subtotal(),
            'shipping_total'  => (float) $order->get_shipping_total(),
            'tax_total'       => (float) $order->get_total_tax(),
            'discount_total'  => (float) $order->get_discount_total(),
            'total'           => (float) $order->get_total(),
            'payment_method'  => $order->get_payment_method_title(),
            'shipping_method' => $order->get_shipping_method(),
            'billing'         => $order->get_address( 'billing' ),
            'shipping'        => $order->get_address( 'shipping' ),
            'items'           => $items,
            'tracking'        => $tracking,
            'refund'          => self::refund_block( $order ),
            'customer_note'   => $order->get_customer_note(),
            'reviewable'      => array(
                'can_review'       => $can_review,
                'seller_ids'       => $reviewable_seller_ids,
            ),
        );
    }

    /**
     * v3.7.95 - collapse per-seller shipping status into one buyer-facing
     * value. Returns 'awaiting' when nobody has shipped, 'partial' when at
     * least one but not all sellers have shipped, 'shipped' when all have,
     * and 'delivered' when the order itself is completed.
     *
     * @param WC_Order $order        Woo order.
     * @param int[]    $seller_ids   Sellers with items on the order.
     * @param array[]  $tracking     Tracking rows already assembled above.
     */
    private static function aggregate_shipping_status( WC_Order $order, array $seller_ids, array $tracking ): string {
        if ( in_array( $order->get_status(), array( 'completed' ), true ) ) {
            return 'delivered';
        }
        if ( empty( $seller_ids ) ) {
            return 'awaiting';
        }
        $shipped = 0;
        foreach ( $seller_ids as $sid ) {
            $seller_status = (string) $order->get_meta( '_tnm_seller_status_' . $sid, true );
            if ( 'shipped' === $seller_status || 'completed' === $seller_status ) {
                $shipped++;
            }
        }
        if ( 0 === $shipped ) {
            return 'awaiting';
        }
        if ( $shipped < count( $seller_ids ) ) {
            return 'partial';
        }
        return 'shipped';
    }

    /**
     * v3.7.95 - build a best-effort tracking URL when the seller didn't
     * paste one. Falls back to empty string when the carrier isn't one of
     * the majors, at which point the app just shows the raw number.
     */
    private static function guess_tracking_url( string $carrier, string $number ): string {
        $c = strtolower( trim( $carrier ) );
        if ( '' === $c || '' === $number ) {
            return '';
        }
        $n = rawurlencode( $number );
        if ( str_contains( $c, 'usps' ) ) {
            return 'https://tools.usps.com/go/TrackConfirmAction?tLabels=' . $n;
        }
        if ( str_contains( $c, 'ups' ) ) {
            return 'https://www.ups.com/track?tracknum=' . $n;
        }
        if ( str_contains( $c, 'fedex' ) ) {
            return 'https://www.fedex.com/fedextrack/?trknbr=' . $n;
        }
        if ( str_contains( $c, 'dhl' ) ) {
            return 'https://www.dhl.com/us-en/home/tracking/tracking-express.html?tracking-id=' . $n;
        }
        return '';
    }

    /**
     * v3.7.90 — buyer-safe refund lifecycle block on every order payload.
     */
    private static function refund_block( WC_Order $order ): array {
        if ( ! class_exists( 'MNU_Refund_Lifecycle' ) ) {
            return array(
                'state'   => 'none',
                'label'   => 'No refund activity',
                'eligibility' => array( 'can_request' => false, 'blockers' => array( 'Refund lifecycle unavailable.' ), 'policy_days' => 14 ),
            );
        }
        $lifecycle   = \MNU_Refund_Lifecycle::get( $order );
        $eligibility = \MNU_Refund_Lifecycle::eligibility( $order );
        return \MNU_Refund_Lifecycle::view_payload( $order, $lifecycle, $eligibility );
    }

    public static function report_product( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $product = wc_get_product( absint( $request['id'] ) );
        if ( ! $product ) {
            return new WP_Error( 'product_not_found', 'Product not found.', array( 'status' => 404 ) );
        }

        $reason  = sanitize_text_field( (string) $request->get_param( 'reason' ) );
        $details = sanitize_textarea_field( (string) $request->get_param( 'details' ) );
        if ( ! $reason ) {
            return new WP_Error( 'report_reason_required', 'Choose a reason for the report.', array( 'status' => 422 ) );
        }

        $report_id = wp_insert_post(
            array(
                'post_type'    => 'mynest_report',
                'post_status'  => 'pending',
                'post_author'  => get_current_user_id(),
                'post_title'   => sprintf( 'Product #%d — %s', $product->get_id(), $reason ),
                'post_content' => $details,
            ),
            true
        );
        if ( is_wp_error( $report_id ) ) {
            return $report_id;
        }

        update_post_meta( $report_id, '_mynest_product_id', $product->get_id() );
        update_post_meta( $report_id, '_mynest_reason', $reason );
        update_post_meta( $report_id, '_mynest_product_url', get_permalink( $product->get_id() ) );
        update_post_meta( $report_id, '_mynest_reporter_id', get_current_user_id() );

        $admins = get_users( array( 'role__in' => array( 'administrator', 'shop_manager' ), 'fields' => array( 'user_email' ) ) );
        foreach ( $admins as $admin ) {
            if ( is_email( $admin->user_email ) ) {
                wp_mail( $admin->user_email, 'New MyNest product report', sprintf( "Product: %s\nReason: %s\nDetails: %s", $product->get_name(), $reason, $details ) );
            }
        }

        return rest_ensure_response( array( 'success' => true, 'report_id' => (int) $report_id ) );
    }

    public static function upload_account_photo(): WP_REST_Response|WP_Error {
        if ( empty( $_FILES['file'] ) ) {
            return new WP_Error( 'missing_file', 'A profile image is required in the file field.', array( 'status' => 422 ) );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $attachment_id = media_handle_upload( 'file', 0 );
        if ( is_wp_error( $attachment_id ) ) {
            return $attachment_id;
        }
        if ( ! wp_attachment_is_image( $attachment_id ) ) {
            wp_delete_attachment( $attachment_id, true );
            return new WP_Error( 'invalid_photo', 'The uploaded file must be an image.', array( 'status' => 422 ) );
        }

        $user_id = get_current_user_id();
        wp_update_post( array( 'ID' => $attachment_id, 'post_author' => $user_id ) );
        $url = (string) wp_get_attachment_image_url( $attachment_id, 'full' );
        update_user_meta( $user_id, 'thenest_profile_photo_id', $attachment_id );
        update_user_meta( $user_id, 'thenest_profile_photo_url', esc_url_raw( $url ) );

        return rest_ensure_response(
            array(
                'ok'        => true,
                'photo_id'  => (int) $attachment_id,
                'photo_url' => $url,
            )
        );
    }

    public static function application_status(): WP_REST_Response {
        $user_id = get_current_user_id();
        if ( function_exists( 'tnm_is_seller' ) && tnm_is_seller( $user_id ) ) {
            return rest_ensure_response( array( 'status' => 'approved' ) );
        }

        $applications = get_posts(
            array(
                'post_type'      => 'tnm_application',
                'post_status'    => array( 'pending', 'publish', 'draft' ),
                'author'         => $user_id,
                'posts_per_page' => 1,
                'orderby'        => 'date',
                'order'          => 'DESC',
            )
        );
        if ( ! $applications ) {
            return rest_ensure_response( array( 'status' => 'none' ) );
        }
        $application = $applications[0];
        $status      = sanitize_key( (string) get_post_meta( $application->ID, '_tnm_status', true ) ) ?: 'pending';

        // v3.13.36 — include rejection metadata + resubmit flag so the mobile
        // "Become a seller" screen can render the right empty state (retry vs.
        // permanently rejected) without a second round trip.
        $reviewed_at  = (string) get_post_meta( $application->ID, '_tnm_reviewed_at', true );
        $resub_meta   = get_post_meta( $application->ID, '_tnm_can_resubmit', true );
        $can_resubmit = '' === $resub_meta ? ( 'rejected' === $status ) : (bool) $resub_meta;

        return rest_ensure_response(
            array(
                'status'           => $status,
                'application_id'   => $application->ID,
                'submitted_at'     => get_post_time( DATE_ATOM, true, $application ),
                'rejection_reason' => (string) get_post_meta( $application->ID, '_tnm_rejection_reason', true ),
                'reviewed_at'      => $reviewed_at ? mysql_to_rfc3339( $reviewed_at ) : '',
                'can_resubmit'     => $can_resubmit,
            )
        );
    }

    /**
     * Community posts are buyer or seller submissions for the app home feed.
     * They stay in the pending status until an administrator approves them,
     * so nothing user-submitted reaches the public feed unreviewed.
     */
    public static function register_community_post_type(): void {
        register_post_type(
            self::COMMUNITY_TYPE,
            array(
                'labels' => array(
                    'name'          => 'Community Posts',
                    'singular_name' => 'Community Post',
                    'menu_name'     => 'Community Posts',
                ),
                'public'              => false,
                'show_ui'             => false,
                'show_in_menu'        => false,
                'supports'            => array( 'title', 'editor', 'author', 'thumbnail' ),
                'capability_type'     => 'post',
                'map_meta_cap'        => true,
                'exclude_from_search' => true,
                'show_in_rest'        => false,
            )
        );
    }

    private static function community_capability(): string {
        return (string) apply_filters( 'mynest_community_moderation_capability', 'manage_woocommerce' );
    }

    public static function can_moderate_community( WP_REST_Request $request ): bool|WP_Error {
        if ( ! self::ensure_current_user( $request ) ) {
            return new WP_Error( 'rest_login_required', 'Authentication is required.', array( 'status' => 401 ) );
        }
        if ( ! current_user_can( self::community_capability() ) ) {
            return new WP_Error( 'rest_forbidden', 'Community moderation is limited to site administrators.', array( 'status' => 403 ) );
        }
        return true;
    }

    private static function community_pending_count(): int {
        $counts = wp_count_posts( self::COMMUNITY_TYPE );
        return isset( $counts->pending ) ? (int) $counts->pending : 0;
    }

    /**
     * Public feed status names are kept stable for the app: WordPress
     * pending/publish/trash map to pending/approved/rejected.
     */
    private static function community_status_slug( string $post_status ): string {
        return match ( $post_status ) {
            'publish' => 'approved',
            'trash'   => 'rejected',
            default   => 'pending',
        };
    }

    private static function community_post_to_array( WP_Post $post ): array {
        $image_id = (int) get_post_thumbnail_id( $post );

        return array(
            'id'            => (int) $post->ID,
            'status'        => self::community_status_slug( (string) $post->post_status ),
            'content'       => wp_kses_post( (string) $post->post_content ),
            'image_id'      => $image_id,
            'image_url'     => $image_id ? (string) wp_get_attachment_image_url( $image_id, 'large' ) : '',
            'thumbnail_url' => $image_id ? (string) wp_get_attachment_image_url( $image_id, 'medium' ) : '',
            'author_id'     => (int) $post->post_author,
            'author_name'   => (string) get_the_author_meta( 'display_name', (int) $post->post_author ),
            'author_avatar' => esc_url_raw( (string) get_user_meta( (int) $post->post_author, 'thenest_profile_photo_url', true ) ),
            'date_created'  => (string) get_post_time( DATE_ATOM, true, $post ),
        );
    }

    private static function community_query( string $post_status, int $page, int $per_page ): array {
        $query = new WP_Query(
            array(
                'post_type'      => self::COMMUNITY_TYPE,
                'post_status'    => $post_status,
                'posts_per_page' => $per_page,
                'paged'          => $page,
                'orderby'        => 'date',
                'order'          => 'DESC',
            )
        );

        return array(
            'posts'       => array_map( array( __CLASS__, 'community_post_to_array' ), $query->posts ),
            'page'        => $page,
            'total'       => (int) $query->found_posts,
            'total_pages' => (int) $query->max_num_pages,
        );
    }

    private static function community_paging( WP_REST_Request $request ): array {
        return array(
            max( 1, absint( $request->get_param( 'page' ) ?: 1 ) ),
            max( 1, min( 50, absint( $request->get_param( 'per_page' ) ?: 20 ) ) ),
        );
    }

    public static function community_posts( WP_REST_Request $request ): WP_REST_Response {
        list( $page, $per_page ) = self::community_paging( $request );
        return rest_ensure_response( self::community_query( 'publish', $page, $per_page ) );
    }

    public static function community_moderation_posts( WP_REST_Request $request ): WP_REST_Response {
        list( $page, $per_page ) = self::community_paging( $request );

        $requested = sanitize_key( (string) $request->get_param( 'status' ) ) ?: 'pending';
        $statuses  = array(
            'pending'  => 'pending',
            'approved' => 'publish',
            'rejected' => 'trash',
        );
        $post_status = $statuses[ $requested ] ?? 'pending';

        $response                  = self::community_query( $post_status, $page, $per_page );
        $response['status']        = array_search( $post_status, $statuses, true );
        $response['pending_count'] = self::community_pending_count();

        return rest_ensure_response( $response );
    }

    public static function create_community_post( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $raw     = (string) ( $request->get_param( 'content' ) ?? $request->get_param( 'caption' ) ?? '' );
        $content = wp_kses_post( $raw );
        if ( '' === trim( wp_strip_all_tags( $content ) ) ) {
            return new WP_Error( 'community_content_required', 'Write something to share with the community.', array( 'status' => 422 ) );
        }

        $user_id = get_current_user_id();
        $post_id = wp_insert_post(
            array(
                'post_type'    => self::COMMUNITY_TYPE,
                'post_status'  => 'pending',
                'post_author'  => $user_id,
                'post_title'   => sprintf(
                    'Community post by %s',
                    (string) get_the_author_meta( 'display_name', $user_id )
                ),
                'post_content' => $content,
            ),
            true
        );
        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }

        $field = '';
        foreach ( array( 'image', 'photo', 'file' ) as $candidate ) {
            if ( ! empty( $_FILES[ $candidate ] ) ) {
                $field = $candidate;
                break;
            }
        }

        if ( $field ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';

            $attachment_id = media_handle_upload( $field, (int) $post_id );
            if ( is_wp_error( $attachment_id ) ) {
                wp_delete_post( (int) $post_id, true );
                return $attachment_id;
            }
            if ( ! wp_attachment_is_image( $attachment_id ) ) {
                wp_delete_attachment( $attachment_id, true );
                wp_delete_post( (int) $post_id, true );
                return new WP_Error( 'community_invalid_image', 'The uploaded file must be an image.', array( 'status' => 422 ) );
            }

            wp_update_post( array( 'ID' => $attachment_id, 'post_author' => $user_id ) );
            set_post_thumbnail( (int) $post_id, (int) $attachment_id );
        }

        self::notify_admins_of_community_post( (int) $post_id );

        $post = get_post( (int) $post_id );

        return rest_ensure_response(
            array(
                'success' => true,
                'pending' => true,
                'post'    => $post instanceof WP_Post ? self::community_post_to_array( $post ) : array( 'id' => (int) $post_id ),
            )
        );
    }

    private static function notify_admins_of_community_post( int $post_id ): void {
        if ( ! apply_filters( 'mynest_community_post_notify_email', true, $post_id ) ) {
            return;
        }

        $post = get_post( $post_id );
        if ( ! $post instanceof WP_Post ) {
            return;
        }

        $admins = get_users(
            array(
                'role__in' => array( 'administrator', 'shop_manager' ),
                'fields'   => array( 'user_email' ),
            )
        );
        $message = sprintf(
            "%s submitted a community post for review.\n\n%s\n\nReview it here: %s",
            (string) get_the_author_meta( 'display_name', (int) $post->post_author ),
            wp_strip_all_tags( (string) $post->post_content ),
            admin_url( 'admin.php?page=' . self::COMMUNITY_MENU )
        );

        foreach ( $admins as $admin ) {
            if ( is_email( $admin->user_email ) ) {
                wp_mail( $admin->user_email, 'New MyNest community post awaiting approval', $message );
            }
        }
    }

    private static function set_community_status( int $post_id, string $post_status ): WP_Post|WP_Error {
        $post = get_post( $post_id );
        if ( ! $post instanceof WP_Post || self::COMMUNITY_TYPE !== $post->post_type ) {
            return new WP_Error( 'community_post_not_found', 'Community post not found.', array( 'status' => 404 ) );
        }

        if ( 'trash' === $post_status ) {
            // wp_trash_post keeps the submission recoverable instead of deleting it.
            $result = wp_trash_post( $post_id );
            if ( ! $result ) {
                return new WP_Error( 'community_reject_failed', 'The community post could not be rejected.', array( 'status' => 500 ) );
            }
        } else {
            if ( 'trash' === $post->post_status ) {
                wp_untrash_post( $post_id );
            }
            $result = wp_update_post( array( 'ID' => $post_id, 'post_status' => $post_status ), true );
            if ( is_wp_error( $result ) ) {
                return $result;
            }
        }

        $updated = get_post( $post_id );
        return $updated instanceof WP_Post ? $updated : new WP_Error( 'community_post_not_found', 'Community post not found.', array( 'status' => 404 ) );
    }

    public static function approve_community_post( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $post = self::set_community_status( absint( $request['id'] ), 'publish' );
        if ( is_wp_error( $post ) ) {
            return $post;
        }
        return rest_ensure_response( array( 'success' => true, 'post' => self::community_post_to_array( $post ) ) );
    }

    public static function reject_community_post( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $post = self::set_community_status( absint( $request['id'] ), 'trash' );
        if ( is_wp_error( $post ) ) {
            return $post;
        }
        return rest_ensure_response( array( 'success' => true, 'post' => self::community_post_to_array( $post ) ) );
    }

    public static function register_community_menu(): void {
        $capability = self::community_capability();
        $pending    = self::community_pending_count();
        $title      = 'Community Posts';
        if ( $pending ) {
            $title .= sprintf(
                ' <span class="awaiting-mod"><span class="pending-count">%s</span></span>',
                number_format_i18n( $pending )
            );
        }

        $parent = class_exists( 'TNM_Admin' ) ? 'tnm-marketplace' : null;
        if ( $parent ) {
            add_submenu_page( $parent, 'Community Posts', $title, $capability, self::COMMUNITY_MENU, array( __CLASS__, 'render_community_screen' ) );
            return;
        }

        add_menu_page( 'Community Posts', $title, $capability, self::COMMUNITY_MENU, array( __CLASS__, 'render_community_screen' ), 'dashicons-format-status', 26 );
    }

    public static function community_pending_notice(): void {
        if ( ! current_user_can( self::community_capability() ) ) {
            return;
        }

        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( $screen && ! in_array( $screen->id, array( 'dashboard', 'plugins' ), true ) ) {
            return;
        }

        $pending = self::community_pending_count();
        if ( ! $pending ) {
            return;
        }

        printf(
            '<div class="notice notice-info"><p><strong>MyNest community posts:</strong> %s <a href="%s">Review them now</a>.</p></div>',
            esc_html( sprintf( _n( '%s post is waiting for approval.', '%s posts are waiting for approval.', $pending, 'mynest-mobile-app-bridge' ), number_format_i18n( $pending ) ) ),
            esc_url( admin_url( 'admin.php?page=' . self::COMMUNITY_MENU ) )
        );
    }

    private static function community_action_url( int $post_id, string $action, string $view, int $page ): string {
        return wp_nonce_url(
            add_query_arg(
                array(
                    'action' => 'mynest_community_moderate',
                    'post'   => $post_id,
                    'do'     => $action,
                    'view'   => $view,
                    'paged'  => $page,
                ),
                admin_url( 'admin-post.php' )
            ),
            'mynest_community_moderate_' . $post_id
        );
    }

    public static function handle_community_moderation_action(): void {
        $post_id = absint( $_GET['post'] ?? 0 );
        if ( ! current_user_can( self::community_capability() ) ) {
            wp_die( esc_html__( 'You are not allowed to moderate community posts.', 'mynest-mobile-app-bridge' ), '', array( 'response' => 403 ) );
        }
        check_admin_referer( 'mynest_community_moderate_' . $post_id );

        $action  = sanitize_key( (string) ( $_GET['do'] ?? '' ) );
        $view    = sanitize_key( (string) ( $_GET['view'] ?? 'pending' ) );
        $page    = max( 1, absint( $_GET['paged'] ?? 1 ) );
        $updated = 'approve' === $action ? 'approved' : 'rejected';

        $result = self::set_community_status( $post_id, 'approve' === $action ? 'publish' : 'trash' );

        wp_safe_redirect(
            add_query_arg(
                array(
                    'page'    => self::COMMUNITY_MENU,
                    'view'    => $view,
                    'paged'   => $page,
                    'updated' => is_wp_error( $result ) ? 'error' : $updated,
                ),
                admin_url( 'admin.php' )
            )
        );
        exit;
    }

    public static function render_community_screen(): void {
        if ( ! current_user_can( self::community_capability() ) ) {
            wp_die( esc_html__( 'You are not allowed to moderate community posts.', 'mynest-mobile-app-bridge' ), '', array( 'response' => 403 ) );
        }

        $views = array(
            'pending'  => array( 'Pending', 'pending' ),
            'approved' => array( 'Approved', 'publish' ),
            'rejected' => array( 'Rejected', 'trash' ),
        );
        $view = sanitize_key( (string) ( $_GET['view'] ?? 'pending' ) );
        if ( ! isset( $views[ $view ] ) ) {
            $view = 'pending';
        }
        $page   = max( 1, absint( $_GET['paged'] ?? 1 ) );
        $counts = wp_count_posts( self::COMMUNITY_TYPE );
        $result = self::community_query( $views[ $view ][1], $page, self::COMMUNITY_PER_PAGE );

        echo '<div class="wrap"><h1>Community Posts</h1>';

        $updated = sanitize_key( (string) ( $_GET['updated'] ?? '' ) );
        if ( 'approved' === $updated ) {
            echo '<div class="notice notice-success is-dismissible"><p>Community post approved and published to the home feed.</p></div>';
        } elseif ( 'rejected' === $updated ) {
            echo '<div class="notice notice-success is-dismissible"><p>Community post rejected.</p></div>';
        } elseif ( 'error' === $updated ) {
            echo '<div class="notice notice-error is-dismissible"><p>That community post could not be updated.</p></div>';
        }

        echo '<ul class="subsubsub">';
        $links = array();
        foreach ( $views as $slug => $definition ) {
            $status = $definition[1];
            $count  = isset( $counts->$status ) ? (int) $counts->$status : 0;
            $links[] = sprintf(
                '<li><a href="%s"%s>%s <span class="count">(%s)</span></a></li>',
                esc_url( add_query_arg( array( 'page' => self::COMMUNITY_MENU, 'view' => $slug ), admin_url( 'admin.php' ) ) ),
                $slug === $view ? ' class="current"' : '',
                esc_html( $definition[0] ),
                esc_html( number_format_i18n( $count ) )
            );
        }
        echo implode( ' | ', $links ) . '</ul>';

        if ( ! $result['posts'] ) {
            echo '<p style="clear:both;padding-top:1em;">No community posts in this view.</p></div>';
            return;
        }

        echo '<table class="wp-list-table widefat fixed striped" style="clear:both;margin-top:1em;"><thead><tr>';
        echo '<th scope="col" style="width:140px;">Photo</th><th scope="col">Post</th><th scope="col" style="width:200px;">Author</th><th scope="col" style="width:160px;">Actions</th>';
        echo '</tr></thead><tbody>';

        foreach ( $result['posts'] as $item ) {
            echo '<tr>';

            echo '<td>';
            if ( $item['thumbnail_url'] ) {
                printf(
                    '<a href="%s" target="_blank" rel="noreferrer noopener"><img src="%s" alt="" style="max-width:120px;height:auto;" /></a>',
                    esc_url( $item['image_url'] ),
                    esc_url( $item['thumbnail_url'] )
                );
            } else {
                echo '<span aria-hidden="true">—</span>';
            }
            echo '</td>';

            printf( '<td>%s</td>', wp_kses_post( wpautop( $item['content'] ) ) );

            printf(
                '<td>%s<br /><span class="description">%s</span></td>',
                esc_html( $item['author_name'] ),
                esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $item['date_created'] ) )
            );

            echo '<td>';
            if ( 'approved' !== $item['status'] ) {
                printf(
                    '<a class="button button-primary" href="%s">Approve</a> ',
                    esc_url( self::community_action_url( $item['id'], 'approve', $view, $page ) )
                );
            }
            if ( 'rejected' !== $item['status'] ) {
                printf(
                    '<a class="button" href="%s">Reject</a>',
                    esc_url( self::community_action_url( $item['id'], 'reject', $view, $page ) )
                );
            }
            echo '</td>';

            echo '</tr>';
        }

        echo '</tbody></table>';

        $pagination = paginate_links(
            array(
                'base'      => esc_url_raw( add_query_arg( array( 'page' => self::COMMUNITY_MENU, 'view' => $view, 'paged' => '%#%' ), admin_url( 'admin.php' ) ) ),
                'format'    => '',
                'current'   => $page,
                'total'     => $result['total_pages'],
                'prev_text' => '&laquo;',
                'next_text' => '&raquo;',
            )
        );
        if ( $pagination ) {
            printf( '<div class="tablenav"><div class="tablenav-pages">%s</div></div>', wp_kses_post( $pagination ) );
        }

        echo '</div>';
    }
}

MyNest_Mobile_App_Bridge::init();
