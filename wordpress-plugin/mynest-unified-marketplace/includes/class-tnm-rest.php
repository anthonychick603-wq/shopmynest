<?php

defined( 'ABSPATH' ) || exit;

final class TNM_REST {
    private const NS = 'the-nest/v1';

    public static function init(): void {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
    }

    public static function register_routes(): void {
        register_rest_route( self::NS, '/config', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'config' ), 'permission_callback' => '__return_true' ) );
        register_rest_route( self::NS, '/auth/register', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'register' ), 'permission_callback' => '__return_true' ) );
        register_rest_route( self::NS, '/auth/login', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'login' ), 'permission_callback' => '__return_true' ) );
        register_rest_route( self::NS, '/auth/logout', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'logout' ), 'permission_callback' => array( __CLASS__, 'logged_in' ) ) );
        register_rest_route( self::NS, '/auth/me', array( array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'me' ), 'permission_callback' => array( __CLASS__, 'logged_in' ) ), array( 'methods' => WP_REST_Server::EDITABLE, 'callback' => array( __CLASS__, 'update_me' ), 'permission_callback' => array( __CLASS__, 'logged_in' ) ) ) );
        register_rest_route( self::NS, '/media', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'upload_media' ), 'permission_callback' => array( __CLASS__, 'media_upload_permission' ) ) );

        register_rest_route( self::NS, '/categories', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'categories' ), 'permission_callback' => '__return_true' ) );
        register_rest_route( self::NS, '/products', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'products' ), 'permission_callback' => '__return_true' ) );
        register_rest_route( self::NS, '/products/(?P<id>\d+)', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'product' ), 'permission_callback' => '__return_true' ) );
        register_rest_route( self::NS, '/feed', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'feed' ), 'permission_callback' => '__return_true' ) );
        register_rest_route( self::NS, '/home', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'home_feed' ), 'permission_callback' => '__return_true' ) );
        register_rest_route( self::NS, '/posts', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'create_post' ), 'permission_callback' => array( __CLASS__, 'seller' ) ) );
        register_rest_route( self::NS, '/posts/(?P<id>\d+)/comments', array( array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'post_comments' ), 'permission_callback' => '__return_true' ), array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'create_comment' ), 'permission_callback' => array( __CLASS__, 'logged_in' ) ) ) );

        register_rest_route( self::NS, '/sellers', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'sellers_list' ), 'permission_callback' => '__return_true' ) );
        register_rest_route( self::NS, '/following', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'following_list' ), 'permission_callback' => array( __CLASS__, 'logged_in' ) ) );
        register_rest_route( self::NS, '/following/feed', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'following_feed' ), 'permission_callback' => array( __CLASS__, 'logged_in' ) ) );
        register_rest_route( self::NS, '/sellers/(?P<id>\d+)', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'seller_profile' ), 'permission_callback' => '__return_true' ) );
        register_rest_route( self::NS, '/sellers/(?P<id>\d+)/products', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'seller_products_public' ), 'permission_callback' => '__return_true' ) );
        register_rest_route( self::NS, '/sellers/(?P<id>\d+)/posts', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'seller_posts_public' ), 'permission_callback' => '__return_true' ) );
        register_rest_route( self::NS, '/sellers/(?P<id>\d+)/reviews', array( array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'seller_reviews' ), 'permission_callback' => '__return_true' ), array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'submit_review' ), 'permission_callback' => array( __CLASS__, 'logged_in' ) ) ) );
        register_rest_route( self::NS, '/sellers/(?P<id>\d+)/follow', array( array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'follow' ), 'permission_callback' => array( __CLASS__, 'logged_in' ) ), array( 'methods' => WP_REST_Server::DELETABLE, 'callback' => array( __CLASS__, 'unfollow' ), 'permission_callback' => array( __CLASS__, 'logged_in' ) ) ) );

        register_rest_route( self::NS, '/notifications', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'notifications' ), 'permission_callback' => array( __CLASS__, 'logged_in' ) ) );
        register_rest_route( self::NS, '/notifications/read', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'notifications_read' ), 'permission_callback' => array( __CLASS__, 'logged_in' ) ) );

        // v3.7.101 — saved searches. GET lists the caller's saved searches;
        // POST creates one from the current search payload (returns the row so
        // the client can echo an "Alert saved" confirmation with the label).
        // PUT toggles notify. DELETE removes.
        register_rest_route( self::NS, '/saved-searches', array(
            array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'saved_searches_list' ), 'permission_callback' => array( __CLASS__, 'logged_in' ) ),
            array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'saved_searches_create' ), 'permission_callback' => array( __CLASS__, 'logged_in' ) ),
        ) );
        register_rest_route( self::NS, '/saved-searches/(?P<id>\d+)', array(
            array( 'methods' => WP_REST_Server::EDITABLE, 'callback' => array( __CLASS__, 'saved_searches_update' ), 'permission_callback' => array( __CLASS__, 'logged_in' ) ),
            array( 'methods' => WP_REST_Server::DELETABLE, 'callback' => array( __CLASS__, 'saved_searches_delete' ), 'permission_callback' => array( __CLASS__, 'logged_in' ) ),
        ) );
        register_rest_route( self::NS, '/messages', array( array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'conversations' ), 'permission_callback' => array( __CLASS__, 'logged_in' ) ), array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'send_message' ), 'permission_callback' => array( __CLASS__, 'logged_in' ) ) ) );
        register_rest_route( self::NS, '/messages/(?P<user_id>\d+)', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'conversation' ), 'permission_callback' => array( __CLASS__, 'logged_in' ) ) );
        // v3.7.86 — photo attachments in DMs. Upload is multipart; photo
        // fetch is unauth (uses HMAC-signed URL). Report requires login and
        // participant status.
        register_rest_route( self::NS, '/messages/photo_upload', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'message_photo_upload' ), 'permission_callback' => array( __CLASS__, 'logged_in' ) ) );
        register_rest_route( self::NS, '/messages/photo/(?P<id>\d+)', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'message_photo_get' ), 'permission_callback' => '__return_true' ) );
        register_rest_route( self::NS, '/messages/(?P<id>\d+)/report_photo', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'message_photo_report' ), 'permission_callback' => array( __CLASS__, 'logged_in' ) ) );

        register_rest_route( self::NS, '/seller/application', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'application' ), 'permission_callback' => array( __CLASS__, 'logged_in' ) ) );
        register_rest_route( self::NS, '/seller/dashboard', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'seller_dashboard' ), 'permission_callback' => array( __CLASS__, 'seller' ) ) );
        register_rest_route( self::NS, '/seller/profile', array( array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'seller_profile_me' ), 'permission_callback' => array( __CLASS__, 'seller' ) ), array( 'methods' => WP_REST_Server::EDITABLE, 'callback' => array( __CLASS__, 'seller_profile_update' ), 'permission_callback' => array( __CLASS__, 'seller' ) ) ) );
        register_rest_route( self::NS, '/seller/products', array( array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'seller_products' ), 'permission_callback' => array( __CLASS__, 'seller' ) ), array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'seller_product_create' ), 'permission_callback' => array( __CLASS__, 'seller' ) ) ) );
        register_rest_route( self::NS, '/seller/products/(?P<id>\d+)', array( array( 'methods' => WP_REST_Server::EDITABLE, 'callback' => array( __CLASS__, 'seller_product_update' ), 'permission_callback' => array( __CLASS__, 'seller' ) ), array( 'methods' => WP_REST_Server::DELETABLE, 'callback' => array( __CLASS__, 'seller_product_delete' ), 'permission_callback' => array( __CLASS__, 'seller' ) ) ) );
        // v3.7.102 (Build #3) — clone an existing listing (as a draft) so the seller
        // only has to change color/size/photo instead of retyping every field.
        register_rest_route( self::NS, '/seller/products/(?P<id>\d+)/duplicate', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'seller_product_duplicate' ), 'permission_callback' => array( __CLASS__, 'seller' ) ) );
        // v3.7.119 (Build #8) — seller variations editor: PUT replaces
        // attributes + variations in one shot. GET is served by the existing
        // product endpoint (attributes + variation_details already ship).
        register_rest_route( self::NS, '/seller/products/(?P<id>\d+)/variations', array( 'methods' => WP_REST_Server::EDITABLE, 'callback' => array( __CLASS__, 'seller_product_variations_save' ), 'permission_callback' => array( __CLASS__, 'seller' ) ) );
        // v3.7.119 (Build #10) — seller coupon CRUD (per-shop codes) + admin coupon CRUD
        // (site-wide codes) + coupon apply for the native mobile cart.
        register_rest_route( self::NS, '/seller/coupons', array( array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'seller_coupons_list' ), 'permission_callback' => array( __CLASS__, 'seller' ) ), array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'seller_coupon_create' ), 'permission_callback' => array( __CLASS__, 'seller' ) ) ) );
        register_rest_route( self::NS, '/seller/coupons/(?P<id>\d+)', array( array( 'methods' => WP_REST_Server::EDITABLE, 'callback' => array( __CLASS__, 'seller_coupon_update' ), 'permission_callback' => array( __CLASS__, 'seller' ) ), array( 'methods' => WP_REST_Server::DELETABLE, 'callback' => array( __CLASS__, 'seller_coupon_delete' ), 'permission_callback' => array( __CLASS__, 'seller' ) ) ) );
        register_rest_route( self::NS, '/admin/coupons', array( array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'admin_coupons_list' ), 'permission_callback' => array( __CLASS__, 'admin' ) ), array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'admin_coupon_create' ), 'permission_callback' => array( __CLASS__, 'admin' ) ) ) );
        register_rest_route( self::NS, '/admin/coupons/(?P<id>\d+)', array( array( 'methods' => WP_REST_Server::EDITABLE, 'callback' => array( __CLASS__, 'admin_coupon_update' ), 'permission_callback' => array( __CLASS__, 'admin' ) ), array( 'methods' => WP_REST_Server::DELETABLE, 'callback' => array( __CLASS__, 'admin_coupon_delete' ), 'permission_callback' => array( __CLASS__, 'admin' ) ) ) );
        register_rest_route( self::NS, '/coupons/apply', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'coupon_apply' ), 'permission_callback' => array( __CLASS__, 'logged_in' ) ) );
        register_rest_route( self::NS, '/products/(?P<id>\d+)/reviews', array(
            array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'product_reviews' ), 'permission_callback' => '__return_true' ),
            array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'submit_product_review' ), 'permission_callback' => array( __CLASS__, 'logged_in' ) ),
        ) );
        register_rest_route( self::NS, '/products/(?P<id>\d+)/reviews/(?P<review_id>\d+)/response', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'respond_to_product_review' ), 'permission_callback' => array( __CLASS__, 'logged_in' ) ) );
        register_rest_route( self::NS, '/orders/(?P<id>\d+)/reviewable-products', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'reviewable_products' ), 'permission_callback' => array( __CLASS__, 'logged_in' ) ) );
        register_rest_route( self::NS, '/seller/reviews', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'seller_product_reviews' ), 'permission_callback' => array( __CLASS__, 'seller' ) ) );
        // v3.7.119 (Build #11) — buyer address book. GET lists, POST creates, PUT edits,
        // DELETE removes. Multi-address support (existing /ops/addresses is single-shipping).
        register_rest_route( self::NS, '/me/addresses', array( array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'address_book_list' ), 'permission_callback' => array( __CLASS__, 'logged_in' ) ), array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'address_book_create' ), 'permission_callback' => array( __CLASS__, 'logged_in' ) ) ) );
        register_rest_route( self::NS, '/me/addresses/(?P<id>[a-z0-9-]+)', array( array( 'methods' => WP_REST_Server::EDITABLE, 'callback' => array( __CLASS__, 'address_book_update' ), 'permission_callback' => array( __CLASS__, 'logged_in' ) ), array( 'methods' => WP_REST_Server::DELETABLE, 'callback' => array( __CLASS__, 'address_book_delete' ), 'permission_callback' => array( __CLASS__, 'logged_in' ) ) ) );
        // v3.7.120 (Build #14) — buyer alert preferences. Currently only
        // exposes the price-drop toggle; other per-buyer alert prefs will
        // grow into this endpoint over time.
        register_rest_route( self::NS, '/me/preferences', array(
            array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'me_preferences_get' ), 'permission_callback' => array( __CLASS__, 'logged_in' ) ),
            array( 'methods' => WP_REST_Server::EDITABLE, 'callback' => array( __CLASS__, 'me_preferences_update' ), 'permission_callback' => array( __CLASS__, 'logged_in' ) ),
        ) );
        // v3.7.121 (Build #18a) — recently viewed products. Small MRU
        // list stored per-user in user_meta so the home tab can show a
        // "Keep browsing" row and the buyer gets a dedicated screen.
        register_rest_route( self::NS, '/me/recently-viewed', array(
            array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'recently_viewed_get' ), 'permission_callback' => array( __CLASS__, 'logged_in' ) ),
            array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'recently_viewed_track' ), 'permission_callback' => array( __CLASS__, 'logged_in' ) ),
            array( 'methods' => WP_REST_Server::DELETABLE, 'callback' => array( __CLASS__, 'recently_viewed_clear' ), 'permission_callback' => array( __CLASS__, 'logged_in' ) ),
        ) );
        register_rest_route( self::NS, '/seller/orders', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'seller_orders' ), 'permission_callback' => array( __CLASS__, 'seller' ) ) );
        register_rest_route( self::NS, '/seller/orders/(?P<id>\d+)', array( 'methods' => WP_REST_Server::EDITABLE, 'callback' => array( __CLASS__, 'seller_order_update' ), 'permission_callback' => array( __CLASS__, 'seller' ) ) );
        register_rest_route( self::NS, '/seller/earnings', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'seller_earnings' ), 'permission_callback' => array( __CLASS__, 'seller' ) ) );
        // v3.7.118 — rolling revenue timeseries + top products + refund rate
        // powering the seller analytics dashboard on mobile.
        register_rest_route( self::NS, '/seller/analytics', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'seller_analytics' ), 'permission_callback' => array( __CLASS__, 'seller' ) ) );
        // v3.7.120 (Build #15) — CSV export of the seller's orders inside
        // the analytics window. Returns a JSON envelope carrying the CSV
        // string so the mobile client can write it to a file and use the
        // native share sheet (no direct file download in RN).
        register_rest_route( self::NS, '/seller/analytics/export', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'seller_analytics_export' ), 'permission_callback' => array( __CLASS__, 'seller' ) ) );
        register_rest_route( self::NS, '/seller/payouts', array( array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'seller_payouts' ), 'permission_callback' => array( __CLASS__, 'seller' ) ), array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'seller_payout_request' ), 'permission_callback' => array( __CLASS__, 'seller' ) ) ) );
    }

    public static function logged_in(): bool|WP_Error {
        return get_current_user_id() ? true : tnm_json_error( 'rest_login_required', 'Authentication is required.', 401 );
    }

    public static function seller(): bool|WP_Error {
        if ( ! get_current_user_id() ) {
            return tnm_json_error( 'rest_login_required', 'Authentication is required.', 401 );
        }
        return tnm_is_seller() || tnm_is_admin_or_manager() ? true : tnm_json_error( 'rest_seller_required', 'An approved seller account is required.', 403 );
    }

    public static function media_upload_permission( WP_REST_Request $request ): bool|WP_Error {
        if ( 'review' === sanitize_key( (string) $request->get_param( 'context' ) ) ) {
            return self::logged_in();
        }
        return self::seller();
    }

    // v3.7.119 (Build #10) — admin/shop-manager gate for site-wide coupon CRUD.
    public static function admin(): bool|WP_Error {
        if ( ! get_current_user_id() ) {
            return tnm_json_error( 'rest_login_required', 'Authentication is required.', 401 );
        }
        return tnm_is_admin_or_manager() ? true : tnm_json_error( 'rest_admin_required', 'Administrator access is required.', 403 );
    }

    public static function config(): WP_REST_Response {
        return rest_ensure_response(
            array(
                'name'       => get_bloginfo( 'name' ),
                'version'    => TNM_VERSION,
                'site_url'   => home_url( '/' ),
                'rest_url'   => rest_url( self::NS . '/' ),
                'currency'   => get_woocommerce_currency(),
                'fee'        => array( 'percent' => tnm_fee_percent(), 'label' => tnm_fee_label() ),
                'pages'      => array(
                    'shop'               => wc_get_page_permalink( 'shop' ),
                    'cart'               => wc_get_cart_url(),
                    'checkout'           => wc_get_checkout_url(),
                    'account'            => wc_get_page_permalink( 'myaccount' ),
                    'feed'               => tnm_page_url( 'feed' ),
                    'seller_dashboard'   => tnm_page_url( 'seller_dashboard' ),
                    'seller_application' => tnm_page_url( 'seller_application' ),
                    'privacy_policy'     => tnm_page_url( 'privacy_policy', get_privacy_policy_url() ),
                    'terms'              => tnm_page_url( 'terms' ),
                    'seller_terms'       => tnm_page_url( 'seller_terms' ),
                    'refund_policy'      => tnm_page_url( 'refund_policy' ),
                    'shipping_policy'    => tnm_page_url( 'shipping_policy', home_url( '/shipping/' ) ),
                    'data_deletion'      => tnm_page_url( 'data_deletion', home_url( '/data-deletion/' ) ),
                ),
                'features'   => array(
                    'social_feed'       => true,
                    'seller_posts'      => true,
                    'follows'           => true,
                    'notifications'     => true,
                    'messages'          => true,
                    'verified_reviews'  => 'yes' === tnm_get_option( 'verified_reviews_only', 'yes' ),
                    'seller_payouts'    => true,
                    'paypal_payouts'    => 'paypal' === tnm_get_option( 'payout_method', 'manual' ),
                ),
                'authenticated' => is_user_logged_in(),
                'user'          => is_user_logged_in() ? tnm_rest_user_data( wp_get_current_user() ) : null,
            )
        );
    }

    /**
     * Return a request param, falling back to the raw JSON body when the WP REST
     * pipeline didn't populate params. WordPress.com Atomic strips JSON body
     * parsing on some completely-anonymous POSTs, so we re-read the body once
     * and cache the parsed array on the request object.
     */
    private static function param( WP_REST_Request $request, string $key ): string {
        $value = $request->get_param( $key );
        if ( null !== $value && '' !== $value ) {
            return is_string( $value ) ? $value : (string) $value;
        }
        static $cache = array();
        $rid = spl_object_id( $request );
        if ( ! isset( $cache[ $rid ] ) ) {
            $body = (string) $request->get_body();
            $data = array();
            if ( '' !== $body ) {
                $decoded = json_decode( $body, true );
                if ( is_array( $decoded ) ) {
                    $data = $decoded;
                } else {
                    parse_str( $body, $parsed );
                    if ( is_array( $parsed ) ) {
                        $data = $parsed;
                    }
                }
            }
            $cache[ $rid ] = $data;
        }
        $raw = $cache[ $rid ][ $key ] ?? '';
        return is_scalar( $raw ) ? (string) $raw : '';
    }

    private static function auth_rate_key( string $login ): string {
        $ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ) );
        return 'tnm_auth_' . md5( strtolower( trim( $login ) ) . '|' . $ip );
    }

    public static function register( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        // v3.7.122.16 — the legacy one-shot register endpoint let anyone
        // (including Google Play's pre-launch bot) create a wp_users row
        // with a fake email like testuser123@example.com. Signups must go
        // through the two-step /auth/signup/start + /auth/signup/verify
        // flow so no user record exists until the email is confirmed.
        return tnm_json_error(
            'signup_deprecated',
            'Please update the ShopMyNest app to the latest version to sign up. This registration method is no longer supported.',
            410
        );

        // The rest of this method is unreachable but kept in place until
        // the next full audit so any old integrations that somehow reach
        // it get a clean error rather than a fatal.
        if ( 'yes' !== tnm_get_option( 'allow_buyer_registration', 'yes' ) ) {
            return tnm_json_error( 'registration_disabled', 'Registration is currently disabled.', 403 );
        }
        $email        = sanitize_email( self::param( $request, 'email' ) );
        $username     = sanitize_user( self::param( $request, 'username' ), true );
        $password     = self::param( $request, 'password' );
        $display_name = sanitize_text_field( self::param( $request, 'display_name' ) );
        if ( '' === $display_name ) {
            $display_name = sanitize_text_field( self::param( $request, 'name' ) );
        }
        if ( ! is_email( $email ) || ! $username || strlen( $password ) < 8 ) {
            return tnm_json_error( 'invalid_registration', 'A valid email, username, and password of at least 8 characters are required.', 422 );
        }
        if ( email_exists( $email ) || username_exists( $username ) ) {
            return tnm_json_error( 'account_exists', 'An account already exists with that email or username.', 409 );
        }

        // WP.com Atomic (and some mu-plugins) attach filters to
        // 'wp_pre_insert_user_data' that empty out $data on REST-created users,
        // causing wp_insert_user to return WP_Error(empty_data). We remove all
        // hooks on that filter for the duration of this single wp_create_user
        // call, then restore them. This is scoped to the register endpoint only.
        $original_hook = null;
        if ( isset( $GLOBALS['wp_filter']['wp_pre_insert_user_data'] ) ) {
            $original_hook = $GLOBALS['wp_filter']['wp_pre_insert_user_data'];
            unset( $GLOBALS['wp_filter']['wp_pre_insert_user_data'] );
        }
        $user_id = wp_create_user( $username, $password, $email );
        if ( null !== $original_hook ) {
            $GLOBALS['wp_filter']['wp_pre_insert_user_data'] = $original_hook;
        }
        if ( is_wp_error( $user_id ) ) {
            return $user_id;
        }
        $user = new WP_User( $user_id );
        if ( ! in_array( 'customer', (array) $user->roles, true ) ) {
            $user->add_role( 'customer' );
        }
        if ( in_array( 'subscriber', (array) $user->roles, true ) ) {
            $user->remove_role( 'subscriber' );
        }
        if ( $display_name ) {
            wp_update_user( array( 'ID' => $user_id, 'display_name' => $display_name ) );
        }
        $token = TNM_Auth::issue_token( $user_id );
        return rest_ensure_response( array( 'token' => $token, 'user' => tnm_rest_user_data( get_userdata( $user_id ) ) ) );
    }

    public static function login( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $login    = sanitize_text_field( self::param( $request, 'login' ) );
        if ( '' === $login ) {
            $login = sanitize_text_field( self::param( $request, 'username' ) );
        }
        if ( '' === $login ) {
            $login = sanitize_text_field( self::param( $request, 'email' ) );
        }
        $password = self::param( $request, 'password' );
        if ( ! $login || ! $password ) {
            return tnm_json_error( 'missing_credentials', 'Login and password are required.', 422 );
        }
        $rate_key = self::auth_rate_key( $login );
        $attempts = (int) get_transient( $rate_key );
        if ( $attempts >= 10 ) {
            return tnm_json_error( 'too_many_login_attempts', 'Too many login attempts. Try again later.', 429 );
        }
        $user = wp_authenticate( $login, $password );
        if ( is_wp_error( $user ) && is_email( $login ) ) {
            $found = get_user_by( 'email', $login );
            if ( $found ) {
                $user = wp_authenticate( $found->user_login, $password );
            }
        }
        if ( is_wp_error( $user ) ) {
            set_transient( $rate_key, $attempts + 1, 15 * MINUTE_IN_SECONDS );
            return tnm_json_error( 'invalid_credentials', 'The email/username or password is incorrect.', 401 );
        }
        delete_transient( $rate_key );
        $token = TNM_Auth::issue_token( $user->ID );
        return rest_ensure_response( array( 'token' => $token, 'user' => tnm_rest_user_data( $user ) ) );
    }

    public static function logout(): WP_REST_Response {
        TNM_Auth::revoke_all( get_current_user_id() );
        wp_logout();
        return rest_ensure_response( array( 'success' => true ) );
    }

    public static function me(): WP_REST_Response {
        return rest_ensure_response( tnm_rest_user_data( wp_get_current_user() ) );
    }

    public static function update_me( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $user_id = get_current_user_id();
        $args    = array( 'ID' => $user_id );
        if ( null !== $request->get_param( 'display_name' ) ) {
            $args['display_name'] = sanitize_text_field( (string) $request->get_param( 'display_name' ) );
        }
        if ( null !== $request->get_param( 'email' ) ) {
            $email = sanitize_email( (string) $request->get_param( 'email' ) );
            if ( ! is_email( $email ) ) {
                return tnm_json_error( 'invalid_email', 'Enter a valid email address.', 422 );
            }
            $existing = email_exists( $email );
            if ( $existing && (int) $existing !== $user_id ) {
                return tnm_json_error( 'email_exists', 'That email is already in use.', 409 );
            }
            $args['user_email'] = $email;
        }
        $updated = wp_update_user( $args );
        if ( is_wp_error( $updated ) ) {
            return $updated;
        }
        return rest_ensure_response( tnm_rest_user_data( get_userdata( $user_id ) ) );
    }

    public static function upload_media( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        if ( empty( $_FILES['file'] ) ) {
            return tnm_json_error( 'missing_file', 'A file upload is required in the file field.', 422 );
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $attachment_id = media_handle_upload( 'file', 0 );
        if ( is_wp_error( $attachment_id ) ) {
            return $attachment_id;
        }
        return rest_ensure_response(
            array(
                'id'        => $attachment_id,
                'url'       => wp_get_attachment_url( $attachment_id ),
                'thumbnail' => wp_get_attachment_image_url( $attachment_id, 'medium' ),
                'mime_type' => get_post_mime_type( $attachment_id ),
            )
        );
    }

    public static function categories(): WP_REST_Response {
        $terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'name' ) );
        if ( is_wp_error( $terms ) ) {
            return rest_ensure_response( array() );
        }
        return rest_ensure_response( array_map( static fn( WP_Term $term ): array => array( 'id' => $term->term_id, 'name' => $term->name, 'slug' => $term->slug, 'count' => $term->count, 'parent' => $term->parent ), $terms ) );
    }

    public static function products( WP_REST_Request $request ): WP_REST_Response {
        $page     = max( 1, (int) $request->get_param( 'page' ) );
        $per_page = max( 1, min( 50, (int) ( $request->get_param( 'per_page' ) ?: 20 ) ) );
        $args = array(
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => $per_page,
            'paged'          => $page,
            's'              => sanitize_text_field( (string) $request->get_param( 'search' ) ),
            'mnu_keyword_search' => 1,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'tax_query'      => array(
                array( 'taxonomy' => 'product_visibility', 'field' => 'name', 'terms' => array( 'exclude-from-catalog' ), 'operator' => 'NOT IN' ),
                // v3.7.75 — hide out-of-stock products from the mobile shop feed.
                array( 'taxonomy' => 'product_visibility', 'field' => 'name', 'terms' => array( 'outofstock' ), 'operator' => 'NOT IN' ),
            ),
        );
        $category = absint( $request->get_param( 'category' ) );
        if ( $category ) {
            $args['tax_query'][] = array( 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => array( $category ) );
        }
        $seller_id = absint( $request->get_param( 'seller_id' ) );
        if ( $seller_id ) {
            $seller_product_ids = tnm_seller_product_ids( $seller_id, array( 'publish' ) );
            $args['post__in']   = $seller_product_ids ?: array( 0 );
        }
        $sort = sanitize_key( (string) $request->get_param( 'sort' ) );
        if ( in_array( $sort, array( 'price_asc', 'price_desc' ), true ) ) {
            $args['meta_key'] = '_price';
            $args['orderby']  = 'meta_value_num';
            $args['order']    = 'price_asc' === $sort ? 'ASC' : 'DESC';
        } elseif ( 'popular' === $sort ) {
            $args['meta_key'] = 'total_sales';
            $args['orderby']  = 'meta_value_num';
        }
        $query = new WP_Query( $args );
        $items = array();
        foreach ( $query->posts as $post ) {
            $product = wc_get_product( $post->ID );
            if ( $product && $product->is_visible() && ! self::is_out_of_stock( $product ) ) {
                $items[] = TNM_Marketplace::product_to_array( $product );
            }
        }
        return rest_ensure_response( array( 'items' => $items, 'page' => $page, 'total' => (int) $query->found_posts, 'total_pages' => (int) $query->max_num_pages ) );
    }

    /**
     * v3.7.75 — buyer-facing OOS check. WC_Product::is_in_stock() covers the
     * common case (Woo stock_status = 'outofstock'), and we also guard against
     * manage_stock=true products whose quantity has dropped to zero even when
     * their status hasn't been resynced by Woo yet.
     */
    protected static function is_out_of_stock( $product ): bool {
        if ( ! $product ) {
            return true;
        }
        if ( method_exists( $product, 'is_in_stock' ) && ! $product->is_in_stock() ) {
            return true;
        }
        if ( method_exists( $product, 'managing_stock' ) && $product->managing_stock() ) {
            $qty = $product->get_stock_quantity();
            if ( null !== $qty && (float) $qty <= 0 ) {
                return true;
            }
        }
        return false;
    }

    public static function product( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $product = wc_get_product( absint( $request['id'] ) );
        if ( ! $product || 'publish' !== $product->get_status() ) {
            return tnm_json_error( 'product_not_found', 'Product not found.', 404 );
        }
        // v3.7.75 — don't return OOS products to shoppers browsing the app.
        // Existing deep links still 404 gracefully.
        if ( self::is_out_of_stock( $product ) ) {
            return tnm_json_error( 'product_not_found', 'Product not found.', 404 );
        }
        return rest_ensure_response( TNM_Marketplace::product_to_array( $product ) );
    }

    public static function feed( WP_REST_Request $request ): WP_REST_Response {
        return rest_ensure_response( TNM_Social::feed( get_current_user_id(), max( 1, (int) $request->get_param( 'page' ) ), max( 1, (int) ( $request->get_param( 'per_page' ) ?: 20 ) ) ) );
    }

    /**
     * Home feed for the native app. Returns recent listings from shops the
     * viewer follows (marked with from_followed=true), then pads with recent
     * listings from all shops so a brand-new user who follows no one still
     * sees a full page. Always public: for anon viewers we skip the followed
     * section entirely and just return the recent listings.
     */
    public static function home_feed( WP_REST_Request $request ): WP_REST_Response {
        $per_page = max( 1, min( 50, (int) ( $request->get_param( 'per_page' ) ?: 20 ) ) );
        $viewer   = get_current_user_id();
        $followed_ids = array();
        if ( $viewer && class_exists( 'TNM_Social' ) ) {
            $followed_ids = array_map( 'intval', TNM_Social::following_ids( $viewer ) );
        }
        $followed_product_ids = array();
        if ( $followed_ids ) {
            foreach ( $followed_ids as $seller_id ) {
                foreach ( tnm_seller_product_ids( $seller_id, array( 'publish' ) ) as $pid ) {
                    $followed_product_ids[] = (int) $pid;
                }
            }
            $followed_product_ids = array_values( array_unique( $followed_product_ids ) );
        }

        $items = array();
        $seen  = array();

        // 1. Followed-shop products (most recent first)
        if ( $followed_product_ids ) {
            $q = new WP_Query( array(
                'post_type'      => 'product',
                'post_status'    => 'publish',
                'posts_per_page' => $per_page,
                'post__in'       => $followed_product_ids,
                'orderby'        => 'date',
                'order'          => 'DESC',
                'tax_query'      => array(
                    array( 'taxonomy' => 'product_visibility', 'field' => 'name', 'terms' => array( 'exclude-from-catalog' ), 'operator' => 'NOT IN' ),
                    // v3.7.75 — hide OOS from home feed.
                    array( 'taxonomy' => 'product_visibility', 'field' => 'name', 'terms' => array( 'outofstock' ), 'operator' => 'NOT IN' ),
                ),
            ) );
            foreach ( $q->posts as $post ) {
                $product = wc_get_product( $post->ID );
                if ( $product && $product->is_visible() && ! self::is_out_of_stock( $product ) ) {
                    $row = TNM_Marketplace::product_to_array( $product );
                    $row['from_followed'] = true;
                    $items[] = $row;
                    $seen[ (int) $post->ID ] = true;
                }
            }
        }

        // 2. Fallback pad: recent products from anywhere, excluding what's already shown
        $need = $per_page - count( $items );
        if ( $need > 0 ) {
            $args = array(
                'post_type'      => 'product',
                'post_status'    => 'publish',
                'posts_per_page' => $need,
                'orderby'        => 'date',
                'order'          => 'DESC',
                'tax_query'      => array(
                    array( 'taxonomy' => 'product_visibility', 'field' => 'name', 'terms' => array( 'exclude-from-catalog' ), 'operator' => 'NOT IN' ),
                    // v3.7.75 — hide OOS from home feed fallback pad.
                    array( 'taxonomy' => 'product_visibility', 'field' => 'name', 'terms' => array( 'outofstock' ), 'operator' => 'NOT IN' ),
                ),
            );
            if ( ! empty( $seen ) ) {
                $args['post__not_in'] = array_keys( $seen );
            }
            $q2 = new WP_Query( $args );
            foreach ( $q2->posts as $post ) {
                $product = wc_get_product( $post->ID );
                if ( $product && $product->is_visible() && ! self::is_out_of_stock( $product ) ) {
                    $row = TNM_Marketplace::product_to_array( $product );
                    $row['from_followed'] = false;
                    $items[] = $row;
                }
            }
        }

        return rest_ensure_response( array(
            'items'           => $items,
            'followed_count'  => count( $followed_ids ),
            'has_followed'    => (bool) $followed_ids,
            'is_authenticated' => (bool) $viewer,
        ) );
    }

    public static function create_post( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $post_id = TNM_Social::create_post( get_current_user_id(), $request->get_json_params() ?: $request->get_params() );
        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }
        return rest_ensure_response( TNM_Social::post_to_array( get_post( $post_id ) ) );
    }

    public static function post_comments( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $result = TNM_Social::post_comments(
            absint( $request['id'] ),
            max( 1, (int) $request->get_param( 'page' ) ),
            max( 1, (int) ( $request->get_param( 'per_page' ) ?: 20 ) )
        );
        return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
    }

    public static function create_comment( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $params = $request->get_json_params() ?: $request->get_params();
        $result = TNM_Social::add_comment( get_current_user_id(), absint( $request['id'] ), (string) tnm_array_get( $params, 'content', '' ) );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return new WP_REST_Response( $result, 201 );
    }

    public static function seller_profile( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $profile = TNM_Social::seller_profile( absint( $request['id'] ), get_current_user_id() );
        return is_wp_error( $profile ) ? $profile : rest_ensure_response( $profile );
    }

    public static function seller_products_public( WP_REST_Request $request ): WP_REST_Response {
        $request->set_param( 'seller_id', absint( $request['id'] ) );
        return self::products( $request );
    }

    public static function seller_posts_public( WP_REST_Request $request ): WP_REST_Response {
        $page     = max( 1, (int) $request->get_param( 'page' ) );
        $per_page = max( 1, min( 50, (int) ( $request->get_param( 'per_page' ) ?: 20 ) ) );
        $query = new WP_Query(
            array(
                'post_type'      => 'tnm_post',
                'post_status'    => 'publish',
                'author'         => absint( $request['id'] ),
                'posts_per_page' => $per_page,
                'paged'          => $page,
                'orderby'        => 'date',
                'order'          => 'DESC',
            )
        );
        return rest_ensure_response(
            array(
                'items'       => array_map( array( 'TNM_Social', 'post_to_array' ), $query->posts ),
                'page'        => $page,
                'total'       => (int) $query->found_posts,
                'total_pages' => (int) $query->max_num_pages,
            )
        );
    }

    public static function seller_reviews( WP_REST_Request $request ): WP_REST_Response {
        return rest_ensure_response( TNM_Social::seller_reviews( absint( $request['id'] ), max( 1, (int) $request->get_param( 'page' ) ), max( 1, (int) ( $request->get_param( 'per_page' ) ?: 20 ) ) ) );
    }

    public static function submit_review( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $review_id = TNM_Social::submit_review( get_current_user_id(), absint( $request['id'] ), (int) $request->get_param( 'rating' ), (string) $request->get_param( 'review' ), absint( $request->get_param( 'order_id' ) ) );
        return is_wp_error( $review_id ) ? $review_id : rest_ensure_response( array( 'success' => true, 'review_id' => $review_id ) );
    }

    public static function follow( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $result = TNM_Social::follow( get_current_user_id(), absint( $request['id'] ) );
        return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'success' => true ) );
    }

    public static function unfollow( WP_REST_Request $request ): WP_REST_Response {
        return rest_ensure_response( array( 'success' => TNM_Social::unfollow( get_current_user_id(), absint( $request['id'] ) ) ) );
    }

    public static function notifications( WP_REST_Request $request ): WP_REST_Response {
        return rest_ensure_response( TNM_Social::notifications( get_current_user_id(), max( 1, (int) $request->get_param( 'page' ) ), max( 1, (int) ( $request->get_param( 'per_page' ) ?: 30 ) ) ) );
    }

    public static function notifications_read( WP_REST_Request $request ): WP_REST_Response {
        $ids = array_map( 'absint', (array) $request->get_param( 'ids' ) );
        return rest_ensure_response( array( 'updated' => TNM_Social::mark_notifications_read( get_current_user_id(), $ids ) ) );
    }

    // v3.7.101 — saved searches. Thin wrappers around MNU_SavedSearches so the
    // storage rules (hash, dedup, max per user) live in one class.
    public static function saved_searches_list(): WP_REST_Response {
        return rest_ensure_response( array( 'items' => MNU_SavedSearches::list_for_user( get_current_user_id() ) ) );
    }

    public static function saved_searches_create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $payload = array(
            'label'        => sanitize_text_field( (string) $request->get_param( 'label' ) ),
            'search'       => sanitize_text_field( (string) $request->get_param( 'search' ) ),
            'category'     => absint( $request->get_param( 'category' ) ),
            'sort'         => sanitize_key( (string) $request->get_param( 'sort' ) ),
            'min_price'    => sanitize_text_field( (string) $request->get_param( 'min_price' ) ),
            'max_price'    => sanitize_text_field( (string) $request->get_param( 'max_price' ) ),
            'pa_condition' => sanitize_text_field( (string) $request->get_param( 'pa_condition' ) ),
            'pa_size'      => sanitize_text_field( (string) $request->get_param( 'pa_size' ) ),
            'pa_brand'     => sanitize_text_field( (string) $request->get_param( 'pa_brand' ) ),
            'seller_id'    => absint( $request->get_param( 'seller_id' ) ),
        );
        $row = MNU_SavedSearches::create( get_current_user_id(), $payload );
        if ( is_wp_error( $row ) ) {
            return $row;
        }
        return rest_ensure_response( $row );
    }

    public static function saved_searches_update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $id     = absint( $request['id'] );
        $notify = null;
        if ( null !== $request->get_param( 'notify' ) ) {
            $notify = (bool) $request->get_param( 'notify' );
        }
        $row = MNU_SavedSearches::update( get_current_user_id(), $id, array( 'notify' => $notify ) );
        if ( is_wp_error( $row ) ) {
            return $row;
        }
        return rest_ensure_response( $row );
    }

    public static function saved_searches_delete( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $id = absint( $request['id'] );
        $ok = MNU_SavedSearches::delete( get_current_user_id(), $id );
        if ( is_wp_error( $ok ) ) {
            return $ok;
        }
        return rest_ensure_response( array( 'success' => (bool) $ok ) );
    }

    public static function conversations(): WP_REST_Response {
        return rest_ensure_response( TNM_Social::conversations( get_current_user_id() ) );
    }

    public static function conversation( WP_REST_Request $request ): WP_REST_Response {
        return rest_ensure_response( TNM_Social::conversation( get_current_user_id(), absint( $request['user_id'] ), max( 1, (int) ( $request->get_param( 'limit' ) ?: 100 ) ) ) );
    }

    public static function sellers_list( WP_REST_Request $request ): WP_REST_Response {
        return rest_ensure_response(
            TNM_Social::sellers_list(
                (string) $request->get_param( 'search' ),
                max( 1, (int) ( $request->get_param( 'page' ) ?: 1 ) ),
                max( 1, (int) ( $request->get_param( 'per_page' ) ?: 24 ) ),
                get_current_user_id()
            )
        );
    }

    public static function following_list(): WP_REST_Response {
        return rest_ensure_response( TNM_Social::following_list( get_current_user_id() ) );
    }

    public static function following_feed( WP_REST_Request $request ): WP_REST_Response {
        return rest_ensure_response( TNM_Social::following_feed( get_current_user_id(), max( 1, (int) ( $request->get_param( 'limit' ) ?: 20 ) ) ) );
    }

    public static function send_message( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        // v3.7.86 — photo_ids arrives as either a JSON string or a real array
        // depending on transport (some clients still send form-encoded), so
        // normalize to an int array before handing off to TNM_Social.
        $raw = $request->get_param( 'photo_ids' );
        if ( is_string( $raw ) ) {
            $decoded = json_decode( $raw, true );
            $raw = is_array( $decoded ) ? $decoded : array();
        }
        $photo_ids = is_array( $raw ) ? $raw : array();
        $message_id = TNM_Social::send_message(
            get_current_user_id(),
            absint( $request->get_param( 'recipient_id' ) ),
            (string) $request->get_param( 'message' ),
            absint( $request->get_param( 'product_id' ) ),
            $photo_ids
        );
        return is_wp_error( $message_id ) ? $message_id : rest_ensure_response( array( 'success' => true, 'message_id' => $message_id ) );
    }

    /**
     * v3.7.86 — accept a multipart upload from the message composer. The
     * client sends a single field "file" (per call, one photo). We validate
     * mime + size, run it through wp_handle_upload, create a private WP
     * attachment tagged as a message photo, and return the attachment ID for
     * later send_message() attach.
     */
    public static function message_photo_upload( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $sender_id    = get_current_user_id();
        $recipient_id = absint( $request->get_param( 'recipient_id' ) );
        if ( ! $recipient_id || ! get_userdata( $recipient_id ) || $recipient_id === $sender_id ) {
            return tnm_json_error( 'invalid_recipient', 'Choose a valid recipient.', 422 );
        }
        $files = $request->get_file_params();
        if ( empty( $files['file'] ) ) {
            return tnm_json_error( 'no_file', 'No photo was uploaded.', 422 );
        }
        $file  = $files['file'];
        $mime  = strtolower( (string) ( $file['type'] ?? '' ) );
        $size  = (int) ( $file['size'] ?? 0 );
        if ( ! in_array( $mime, array( 'image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/heic' ), true ) ) {
            return tnm_json_error( 'invalid_type', 'Only JPG, PNG, WEBP or HEIC photos are supported.', 415 );
        }
        if ( $size <= 0 || $size > 8 * 1024 * 1024 ) {
            return tnm_json_error( 'file_too_large', 'Photos must be 8 MB or smaller.', 413 );
        }
        // Rate limit — 20 photo uploads per hour per sender to blunt abuse.
        global $wpdb;
        if ( ! tnm_is_admin_or_manager( $sender_id ) ) {
            $recent = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON p.ID=m.post_id WHERE p.post_author=%d AND p.post_type='attachment' AND m.meta_key='_mnu_message_photo' AND p.post_date_gmt >= %s",
                $sender_id,
                gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS )
            ) );
            if ( $recent >= 20 ) {
                return tnm_json_error( 'rate_limited', 'You have uploaded too many photos in the last hour.', 429 );
            }
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $upload = wp_handle_upload( $file, array( 'test_form' => false, 'mimes' => array(
            'jpg|jpeg|jpe' => 'image/jpeg',
            'png'          => 'image/png',
            'webp'         => 'image/webp',
            'heic'         => 'image/heic',
        ) ) );
        if ( isset( $upload['error'] ) ) {
            return tnm_json_error( 'upload_failed', (string) $upload['error'], 500 );
        }
        $attach_id = TNM_Social::create_message_photo_attachment( $upload, $sender_id, $recipient_id );
        if ( is_wp_error( $attach_id ) ) { return $attach_id; }
        $meta = wp_get_attachment_metadata( $attach_id );
        return rest_ensure_response( array(
            'attachment_id' => (int) $attach_id,
            'w'             => (int) ( $meta['width']  ?? 0 ),
            'h'             => (int) ( $meta['height'] ?? 0 ),
            'mime'          => $upload['type'],
            'preview_url'   => TNM_Social::signed_photo_url( (int) $attach_id, $sender_id ),
        ) );
    }

    /**
     * v3.7.86 — stream a message photo to the caller. Uses HMAC signature
     * instead of cookie auth so the URL works from any client (including the
     * expo-image cache) without needing to forward the app-password header.
     */
    public static function message_photo_get( WP_REST_Request $request ) {
        $attachment_id = absint( $request->get_param( 'id' ) );
        $viewer_id     = absint( $request->get_param( 'u' ) );
        $expires       = absint( $request->get_param( 'e' ) );
        $sig           = (string) $request->get_param( 's' );
        if ( ! $attachment_id || ! $viewer_id || ! $expires || ! $sig ) {
            return tnm_json_error( 'bad_signature', 'Missing signature parameters.', 400 );
        }
        if ( ! TNM_Social::verify_signed_photo( $attachment_id, $viewer_id, $expires, $sig ) ) {
            return tnm_json_error( 'bad_signature', 'Invalid or expired photo URL.', 403 );
        }
        // Extra guardrail: the signature already binds the viewer, but we
        // still verify the viewer is actually a participant in case a stale
        // URL is being replayed after a message deletion, etc.
        $sender_id    = (int) get_post_meta( $attachment_id, '_mnu_sender_id', true );
        $recipient_id = (int) get_post_meta( $attachment_id, '_mnu_recipient_id', true );
        if ( ! $sender_id ) {
            return tnm_json_error( 'not_found', 'Photo not found.', 404 );
        }
        if ( $viewer_id !== $sender_id && $viewer_id !== $recipient_id && ! user_can( $viewer_id, 'manage_woocommerce' ) ) {
            return tnm_json_error( 'forbidden', 'You cannot view this photo.', 403 );
        }
        if ( get_post_meta( $attachment_id, '_mnu_photo_hidden', true ) === '1' && ! user_can( $viewer_id, 'manage_woocommerce' ) ) {
            return tnm_json_error( 'hidden', 'This photo was hidden by a report.', 410 );
        }
        $path = get_attached_file( $attachment_id );
        if ( ! $path || ! file_exists( $path ) ) {
            return tnm_json_error( 'not_found', 'Photo file missing.', 404 );
        }
        $mime = (string) get_post_mime_type( $attachment_id );
        nocache_headers();
        header( 'Content-Type: ' . ( $mime ?: 'image/jpeg' ) );
        header( 'Content-Length: ' . filesize( $path ) );
        header( 'X-Content-Type-Options: nosniff' );
        header( 'Cache-Control: private, max-age=86400' );
        readfile( $path );
        exit;
    }

    public static function message_photo_report( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $attachment_id = absint( $request->get_param( 'attachment_id' ) );
        $reason        = (string) $request->get_param( 'reason' );
        $result        = TNM_Social::report_message_photo( get_current_user_id(), $attachment_id, $reason );
        return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
    }

    public static function application( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $application_id = TNM_Applications::submit( get_current_user_id(), $request->get_json_params() ?: $request->get_params() );
        return is_wp_error( $application_id ) ? $application_id : rest_ensure_response( array( 'success' => true, 'application_id' => $application_id, 'status' => 'pending' ) );
    }

    public static function seller_dashboard(): WP_REST_Response {
        $seller_id = get_current_user_id();
        $balances  = TNM_Ledger::balances( $seller_id );
        $product_ids = tnm_seller_product_ids( $seller_id, array( 'publish', 'pending', 'draft', 'private' ) );
        $orders    = TNM_Marketplace::seller_orders( $seller_id, 1, 5 );
        // TNM_Social::seller_profile() returns WP_Error for admins. Serializing
        // a WP_Error into a JSON response leaks an error object into a success
        // payload — replace it with null so the caller can render "admin view".
        $profile = TNM_Social::seller_profile( $seller_id, $seller_id );
        if ( is_wp_error( $profile ) ) {
            $profile = null;
        }

        // v3.7.68 — the mobile dashboard renders `dashboard.products`. We used
        // to only return `product_count`, which meant the seller’s own
        // dashboard always showed "No products yet" even when the public
        // marketplace happily listed 100+ products for the same account.
        // v3.7.69 — bump the slice so sellers with big catalogs (Johanna has
        // 137) see the real count on the dashboard. The full
        // /seller/products endpoint still owns pagination for larger stores.
        $recent_ids = array_slice( $product_ids, 0, 500 );
        $products   = array();
        if ( $recent_ids ) {
            $query = new WP_Query( array(
                'post_type'      => 'product',
                'post_status'    => array( 'publish', 'pending', 'draft', 'private' ),
                'post__in'       => $recent_ids,
                'orderby'        => 'post__in',
                'posts_per_page' => count( $recent_ids ),
                'no_found_rows'  => true,
            ) );
            foreach ( $query->posts as $post ) {
                $product = wc_get_product( $post->ID );
                if ( $product ) {
                    $products[] = TNM_Marketplace::product_to_array( $product, true );
                }
            }
        }

        return rest_ensure_response(
            array(
                'profile'       => $profile,
                'balances'      => $balances,
                'product_count' => count( $product_ids ),
                'products'      => $products,
                // v3.7.69 — the dashboard showed "$-29" earnings because we were
                // handing the ledger's net (fees already deducted) as the sole
                // signal even when the seller had no gross yet. Clamp to ≥ 0 so
                // negative bootstrap balances don't scare new sellers; the
                // Earnings screen still shows the exact ledger figures.
                'totals'        => array(
                    'orders'   => (int) $orders['total'],
                    'revenue'  => max( 0.0, (float) ( $balances['lifetime_gross'] ?? 0 ) ),
                    'earnings' => max( 0.0, (float) ( $balances['lifetime_net'] ?? $balances['available'] ?? 0 ) ),
                ),
                'recent_orders' => $orders['orders'],
                'fee'           => array( 'percent' => tnm_fee_percent(), 'label' => tnm_fee_label() ),
                'minimum_payout'=> (float) tnm_get_option( 'minimum_payout', 25 ),
            )
        );
    }

    public static function seller_profile_me(): WP_REST_Response|WP_Error {
        $user_id = get_current_user_id();
        // Administrators and shop managers have seller-tool permission but are not
        // vendors themselves — TNM_Social::seller_profile() will return a
        // WP_Error for them, which used to fatal because of the WP_REST_Response
        // return type. Return a 200-safe empty profile for admins instead.
        if ( tnm_is_admin_or_manager( $user_id ) ) {
            return rest_ensure_response(
                array(
                    'id'           => $user_id,
                    'store_name'   => '',
                    'display_name' => wp_get_current_user()->display_name,
                    'about'        => '',
                    'avatar'       => tnm_user_avatar_url( $user_id, 512 ),
                    'banner'       => '',
                    'followers'    => 0,
                    'is_following' => false,
                    'rating'       => 0,
                    'review_count' => 0,
                    'joined'       => wp_get_current_user()->user_registered,
                    'posts'        => array(),
                    'is_admin'     => true,
                )
            );
        }
        $profile = TNM_Social::seller_profile( $user_id, $user_id );
        if ( is_wp_error( $profile ) ) {
            return $profile;
        }
        return rest_ensure_response( $profile );
    }

    public static function seller_profile_update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $seller_id = get_current_user_id();
        $params    = $request->get_json_params() ?: $request->get_params();
        if ( array_key_exists( 'store_name', $params ) ) {
            $store_name = sanitize_text_field( (string) $params['store_name'] );
            if ( ! $store_name ) {
                return tnm_json_error( 'invalid_store_name', 'Store name cannot be empty.', 422 );
            }
            update_user_meta( $seller_id, 'tnm_store_name', $store_name );
        }
        if ( array_key_exists( 'about', $params ) ) {
            update_user_meta( $seller_id, 'tnm_store_about', sanitize_textarea_field( (string) $params['about'] ) );
        }
        if ( array_key_exists( 'tagline', $params ) ) {
            $tagline = mb_substr( sanitize_text_field( (string) $params['tagline'] ), 0, 140 );
            update_user_meta( $seller_id, 'tnm_store_tagline', $tagline );
        }
        if ( array_key_exists( 'banner_id', $params ) ) {
            $banner_id = absint( $params['banner_id'] );
            if ( $banner_id && ! tnm_user_can_use_attachment( $seller_id, $banner_id ) ) {
                return tnm_json_error( 'invalid_banner', 'Banner must be an image attachment.', 422 );
            }
            update_user_meta( $seller_id, 'tnm_store_banner_id', $banner_id );
        }
        if ( array_key_exists( 'paypal_email', $params ) ) {
            $email = sanitize_email( (string) $params['paypal_email'] );
            if ( $email && ! is_email( $email ) ) {
                return tnm_json_error( 'invalid_paypal_email', 'Enter a valid PayPal email.', 422 );
            }
            update_user_meta( $seller_id, 'tnm_paypal_email', $email );
        }
        // Fetch the refreshed profile. TNM_Social::seller_profile() returns
        // WP_Error for non-seller callers (e.g. admins); surface that instead
        // of triggering a return-type fatal.
        $profile = TNM_Social::seller_profile( $seller_id, $seller_id );
        if ( is_wp_error( $profile ) ) {
            return $profile;
        }
        return rest_ensure_response( $profile );
    }

    public static function seller_products( WP_REST_Request $request ): WP_REST_Response {
        $seller_id = get_current_user_id();
        $page      = max( 1, (int) $request->get_param( 'page' ) );
        $per_page  = max( 1, min( 100, (int) ( $request->get_param( 'per_page' ) ?: 30 ) ) );
        $product_ids = tnm_seller_product_ids( $seller_id, array( 'publish', 'pending', 'draft', 'private' ) );
        $query = new WP_Query(
            array(
                'post_type'      => 'product',
                'post_status'    => array( 'publish', 'pending', 'draft', 'private' ),
                'post__in'       => $product_ids ?: array( 0 ),
                'posts_per_page' => $per_page,
                'paged'          => $page,
                'orderby'        => 'date',
                'order'          => 'DESC',
            )
        );
        $items = array();
        foreach ( $query->posts as $post ) {
            $product = wc_get_product( $post->ID );
            if ( $product ) {
                $items[] = TNM_Marketplace::product_to_array( $product, true );
            }
        }
        return rest_ensure_response( array( 'items' => $items, 'page' => $page, 'total' => (int) $query->found_posts, 'total_pages' => (int) $query->max_num_pages ) );
    }

    public static function seller_product_create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $product_id = TNM_Marketplace::create_product( get_current_user_id(), $request->get_json_params() ?: $request->get_params() );
        if ( is_wp_error( $product_id ) ) {
            return $product_id;
        }
        return rest_ensure_response( TNM_Marketplace::product_to_array( wc_get_product( $product_id ), true ) );
    }

    public static function seller_product_update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $product_id = TNM_Marketplace::update_product( get_current_user_id(), absint( $request['id'] ), $request->get_json_params() ?: $request->get_params() );
        if ( is_wp_error( $product_id ) ) {
            return $product_id;
        }
        return rest_ensure_response( TNM_Marketplace::product_to_array( wc_get_product( $product_id ), true ) );
    }

    public static function seller_product_delete( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $result = TNM_Marketplace::delete_product( get_current_user_id(), absint( $request['id'] ) );
        return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'success' => (bool) $result ) );
    }

    public static function seller_product_duplicate( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $new_id = TNM_Marketplace::duplicate_product( get_current_user_id(), absint( $request['id'] ) );
        if ( is_wp_error( $new_id ) ) {
            return $new_id;
        }
        $product = wc_get_product( $new_id );
        if ( ! $product ) {
            return tnm_json_error( 'duplicate_missing', 'The duplicated product could not be loaded.', 500 );
        }
        return rest_ensure_response( TNM_Marketplace::product_to_array( $product, true ) );
    }

    public static function seller_orders( WP_REST_Request $request ): WP_REST_Response {
        return rest_ensure_response( TNM_Marketplace::seller_orders( get_current_user_id(), max( 1, (int) $request->get_param( 'page' ) ), max( 1, (int) ( $request->get_param( 'per_page' ) ?: 20 ) ) ) );
    }

    public static function seller_order_update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $result = TNM_Marketplace::update_seller_order_status( get_current_user_id(), absint( $request['id'] ), sanitize_key( (string) $request->get_param( 'status' ) ), sanitize_text_field( (string) $request->get_param( 'tracking_number' ) ) );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return rest_ensure_response( TNM_Marketplace::seller_order_to_array( wc_get_order( absint( $request['id'] ) ), get_current_user_id() ) );
    }

    public static function seller_earnings( WP_REST_Request $request ): WP_REST_Response {
        return rest_ensure_response( array( 'balances' => TNM_Ledger::balances( get_current_user_id() ), 'ledger' => TNM_Ledger::entries( get_current_user_id(), max( 1, (int) $request->get_param( 'page' ) ), max( 1, (int) ( $request->get_param( 'per_page' ) ?: 30 ) ) ) ) );
    }

    /**
     * v3.7.118 — marketplace-scoped seller analytics. Returns:
     *   - `range`: window used (7 / 30 / 90 days)
     *   - `revenue`: daily net revenue for the window (gross - fees)
     *   - `orders_count`: total paid+ orders in the window
     *   - `refund_rate`: refunded_orders / paid_orders in the window (0–1)
     *   - `top_products`: 5 highest-gross products in the window
     *   - `pending_payout`: seller ledger available balance
     */
    public static function seller_analytics( WP_REST_Request $request ): WP_REST_Response {
        $seller_id = get_current_user_id();
        $range     = (int) ( $request->get_param( 'range' ) ?: 30 );
        if ( ! in_array( $range, array( 7, 30, 90 ), true ) ) { $range = 30; }

        $since = strtotime( '-' . ( $range - 1 ) . ' days 00:00:00' );
        // v3.7.120 (Build #15) — previous period window for compare deltas.
        // The prior window is the same length ending the day before $since.
        $prev_since = $since - ( $range * DAY_IN_SECONDS );
        $prev_until = $since - 1;
        $tz    = wp_timezone();

        $days = array();
        for ( $i = 0; $i < $range; $i++ ) {
            $d = ( new DateTime( '@' . ( $since + $i * DAY_IN_SECONDS ) ) )->setTimezone( $tz )->format( 'Y-m-d' );
            $days[ $d ] = 0.0;
        }

        // Load orders back to the start of the PREVIOUS period so we can
        // compute both the current window and the compare baseline in one
        // wc_get_orders call. Costs the same as fetching only the current
        // window for a busy seller.
        $query = wc_get_orders( array(
            'limit'      => -1,
            'status'     => array( 'wc-processing', 'wc-completed', 'wc-on-hold', 'wc-refunded' ),
            'date_after' => gmdate( 'Y-m-d H:i:s', $prev_since ),
            'return'     => 'objects',
            'meta_query' => array(
                array(
                    'key'     => '_tnm_seller_ids',
                    'value'   => ',' . $seller_id . ',',
                    'compare' => 'LIKE',
                ),
            ),
        ) );

        $orders_count    = 0;
        $refunded_orders = 0;
        $total_gross     = 0.0;
        $total_fees      = 0.0;
        $product_gross   = array();
        $product_names   = array();
        $product_images  = array();
        // v3.7.120 — previous period accumulators (compare baseline).
        $prev_orders_count = 0;
        $prev_total_gross  = 0.0;

        foreach ( $query as $order ) {
            if ( ! tnm_order_contains_seller( $order, $seller_id ) ) {
                continue;
            }
            $dt = $order->get_date_created();
            if ( ! $dt ) { continue; }
            $ts = $dt->getTimestamp();
            // Bucket the order into current vs previous window. Anything
            // older than $prev_since was pulled in by rounding — skip.
            $is_current = ( $ts >= $since );
            $is_prev    = ( ! $is_current && $ts >= $prev_since && $ts <= $prev_until );
            if ( ! $is_current && ! $is_prev ) {
                continue;
            }
            if ( $is_current ) {
                $orders_count++;
                if ( 'refunded' === $order->get_status() ) {
                    $refunded_orders++;
                }
            } else {
                $prev_orders_count++;
            }
            $bucket = $dt->setTimezone( $tz )->format( 'Y-m-d' );

            foreach ( tnm_get_seller_order_items( $order, $seller_id ) as $item ) {
                if ( $is_prev ) {
                    $prev_total_gross += (float) $item->get_total();
                    continue;
                }
                $gross = (float) $item->get_total();
                $fee   = TNM_Marketplace::resolve_item_platform_fee( $item );
                $net   = max( 0, $gross - $fee );
                $total_gross += $gross;
                $total_fees  += $fee;
                if ( isset( $days[ $bucket ] ) ) {
                    $days[ $bucket ] += $net;
                }
                $pid = (int) $item->get_product_id();
                if ( $pid ) {
                    if ( ! isset( $product_gross[ $pid ] ) ) {
                        $product_gross[ $pid ] = 0.0;
                        $product_names[ $pid ] = $item->get_name();
                        $img = wp_get_attachment_image_url( get_post_thumbnail_id( $pid ), 'medium' );
                        $product_images[ $pid ] = $img ?: '';
                    }
                    $product_gross[ $pid ] += $gross;
                }
            }
        }

        arsort( $product_gross );
        $top_products = array();
        foreach ( array_slice( $product_gross, 0, 5, true ) as $pid => $g ) {
            $top_products[] = array(
                'id'    => (int) $pid,
                'name'  => (string) ( $product_names[ $pid ] ?? '' ),
                'image' => (string) ( $product_images[ $pid ] ?? '' ),
                'gross' => round( (float) $g, 2 ),
            );
        }

        $revenue_series = array();
        foreach ( $days as $d => $v ) {
            $revenue_series[] = array( 'date' => $d, 'revenue' => round( (float) $v, 2 ) );
        }

        $balances = class_exists( 'TNM_Ledger' ) ? TNM_Ledger::balances( $seller_id ) : array();

        // v3.7.120 (Build #15) — compare block. Deltas are computed as a
        // pure difference and a percentage of the previous window. When the
        // previous window is empty we return null for pct_change so the
        // client can render "—" instead of a bogus infinite gain.
        $delta_gross     = $total_gross - $prev_total_gross;
        $delta_orders    = $orders_count - $prev_orders_count;
        $pct_gross       = $prev_total_gross > 0 ? round( ( $delta_gross / $prev_total_gross ) * 100, 1 ) : null;
        $pct_orders      = $prev_orders_count > 0 ? round( ( $delta_orders / $prev_orders_count ) * 100, 1 ) : null;

        return rest_ensure_response( array(
            'range'          => $range,
            'revenue'        => $revenue_series,
            'orders_count'   => $orders_count,
            'refund_rate'    => $orders_count > 0 ? round( $refunded_orders / $orders_count, 4 ) : 0,
            'total_gross'    => round( $total_gross, 2 ),
            'total_fees'     => round( $total_fees, 2 ),
            'total_net'      => round( max( 0, $total_gross - $total_fees ), 2 ),
            'top_products'   => $top_products,
            'pending_payout' => isset( $balances['available'] ) ? (float) $balances['available'] : 0,
            'compare'        => array(
                'prev_total_gross'  => round( $prev_total_gross, 2 ),
                'prev_orders_count' => $prev_orders_count,
                'delta_gross'       => round( $delta_gross, 2 ),
                'delta_orders'      => $delta_orders,
                'pct_gross'         => $pct_gross,
                'pct_orders'        => $pct_orders,
            ),
        ) );
    }

    /**
     * v3.7.120 (Build #15) — CSV export of the current seller's orders
     * over the same 7/30/90-day window that seller_analytics reports.
     * We return the CSV inline in a JSON envelope; the mobile client
     * writes it to expo-file-system and hands it to the OS share sheet.
     * Doing it that way keeps auth headers attached (a raw text/csv
     * response over the REST client would need a separate download
     * pathway) and avoids leaking the CSV to any device browser.
     */
    public static function seller_analytics_export( WP_REST_Request $request ): WP_REST_Response {
        $seller_id = get_current_user_id();
        $range     = (int) ( $request->get_param( 'range' ) ?: 30 );
        if ( ! in_array( $range, array( 7, 30, 90 ), true ) ) { $range = 30; }
        $since = strtotime( '-' . ( $range - 1 ) . ' days 00:00:00' );

        $orders = wc_get_orders( array(
            'limit'      => -1,
            'status'     => array( 'wc-processing', 'wc-completed', 'wc-on-hold', 'wc-refunded' ),
            'date_after' => gmdate( 'Y-m-d H:i:s', $since ),
            'return'     => 'objects',
            'meta_query' => array(
                array( 'key' => '_tnm_seller_ids', 'value' => ',' . $seller_id . ',', 'compare' => 'LIKE' ),
            ),
        ) );

        $rows = array();
        $rows[] = array( 'order_id', 'date', 'status', 'buyer', 'product', 'sku', 'qty', 'gross', 'platform_fee', 'net' );
        foreach ( $orders as $order ) {
            if ( ! tnm_order_contains_seller( $order, $seller_id ) ) { continue; }
            $date = $order->get_date_created();
            $date_s = $date ? $date->format( 'Y-m-d H:i:s' ) : '';
            $buyer  = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) ?: '(guest)';
            foreach ( tnm_get_seller_order_items( $order, $seller_id ) as $item ) {
                $gross = (float) $item->get_total();
                $fee   = TNM_Marketplace::resolve_item_platform_fee( $item );
                $net   = max( 0, $gross - $fee );
                $sku   = '';
                $pid   = (int) $item->get_product_id();
                if ( $pid ) {
                    $product = wc_get_product( $pid );
                    if ( $product ) { $sku = $product->get_sku(); }
                }
                $rows[] = array(
                    (string) $order->get_id(),
                    $date_s,
                    (string) $order->get_status(),
                    $buyer,
                    (string) $item->get_name(),
                    (string) $sku,
                    (string) $item->get_quantity(),
                    number_format( $gross, 2, '.', '' ),
                    number_format( $fee, 2, '.', '' ),
                    number_format( $net, 2, '.', '' ),
                );
            }
        }

        $handle = fopen( 'php://temp', 'w+' );
        foreach ( $rows as $row ) { fputcsv( $handle, $row ); }
        rewind( $handle );
        $csv = stream_get_contents( $handle );
        fclose( $handle );

        return rest_ensure_response( array(
            'range'    => $range,
            'filename' => sprintf( 'shopmynest-orders-%dd-%s.csv', $range, gmdate( 'Y-m-d' ) ),
            'csv'      => (string) $csv,
            'rows'     => count( $rows ) - 1,
        ) );
    }

    public static function seller_payouts(): WP_REST_Response {
        return rest_ensure_response( array( 'balances' => TNM_Ledger::balances( get_current_user_id() ), 'payouts' => TNM_Payouts::list_for_seller( get_current_user_id() ), 'minimum' => (float) tnm_get_option( 'minimum_payout', 25 ) ) );
    }

    public static function seller_payout_request( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $payout_id = TNM_Payouts::request( get_current_user_id(), (float) $request->get_param( 'amount' ), sanitize_key( (string) $request->get_param( 'method' ) ), sanitize_text_field( (string) $request->get_param( 'destination' ) ) );
        return is_wp_error( $payout_id ) ? $payout_id : rest_ensure_response( array( 'success' => true, 'payout' => TNM_Payouts::get( $payout_id ) ) );
    }

    // =========================================================================
    // v3.7.119 — Build 8 (variations editor), 9-lite (product reviews list),
    // 10 (coupons: seller + admin + apply), 11 (buyer address book).
    // Kept together at the bottom to avoid churning the earlier file layout.
    // =========================================================================

    public static function seller_product_variations_save( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $result = TNM_Marketplace::save_product_variations( get_current_user_id(), absint( $request['id'] ), $request->get_json_params() ?: $request->get_params() );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return rest_ensure_response( $result );
    }

    // ---- Coupons -----------------------------------------------------------

    /**
     * Serialize a WC_Coupon into the mobile-friendly shape. `scope` disambiguates
     * seller vs admin coupons — seller coupons carry `_tnm_seller_id`.
     */
    private static function coupon_to_array( WC_Coupon $coupon ): array {
        $seller_id = (int) $coupon->get_meta( '_tnm_seller_id' );
        return array(
            'id'                => $coupon->get_id(),
            'code'              => $coupon->get_code(),
            'discount_type'     => $coupon->get_discount_type(),
            'amount'            => (float) $coupon->get_amount(),
            'description'       => $coupon->get_description(),
            'minimum_amount'    => (float) $coupon->get_minimum_amount(),
            'usage_limit'       => (int) $coupon->get_usage_limit(),
            'usage_count'       => (int) $coupon->get_usage_count(),
            'expires_at'        => $coupon->get_date_expires() ? $coupon->get_date_expires()->date( 'Y-m-d' ) : '',
            'free_shipping'     => (bool) $coupon->get_free_shipping(),
            'seller_id'         => $seller_id ?: 0,
            'scope'             => $seller_id ? 'seller' : 'site',
        );
    }

    private static function coupon_apply_payload( WP_REST_Request $request, WC_Coupon $coupon, int $seller_scope = 0 ): int|WP_Error {
        $code = wc_format_coupon_code( (string) ( $request->get_param( 'code' ) ?: $coupon->get_code() ) );
        $type = sanitize_key( (string) $request->get_param( 'discount_type' ) );
        $allowed_types = array( 'percent', 'fixed_cart', 'fixed_product' );
        if ( ! in_array( $type, $allowed_types, true ) ) { $type = 'percent'; }
        $amount = wc_format_decimal( (string) $request->get_param( 'amount' ) );
        if ( '' === $amount || (float) $amount < 0 ) {
            return tnm_json_error( 'invalid_coupon_amount', 'Coupon amount must be non-negative.', 422 );
        }
        if ( 'percent' === $type && (float) $amount > 100 ) {
            return tnm_json_error( 'invalid_coupon_amount', 'Percent coupons cannot exceed 100.', 422 );
        }
        if ( '' === $code ) {
            return tnm_json_error( 'invalid_coupon_code', 'Coupon code cannot be empty.', 422 );
        }
        $coupon->set_code( $code );
        $coupon->set_discount_type( $type );
        $coupon->set_amount( $amount );
        $description = $request->get_param( 'description' );
        if ( null !== $description ) { $coupon->set_description( sanitize_textarea_field( (string) $description ) ); }
        $minimum = $request->get_param( 'minimum_amount' );
        if ( null !== $minimum ) { $coupon->set_minimum_amount( wc_format_decimal( (string) $minimum ) ); }
        $usage_limit = $request->get_param( 'usage_limit' );
        if ( null !== $usage_limit ) { $coupon->set_usage_limit( max( 0, (int) $usage_limit ) ); }
        $free_shipping = $request->get_param( 'free_shipping' );
        if ( null !== $free_shipping ) { $coupon->set_free_shipping( (bool) $free_shipping ); }
        $expires_at = $request->get_param( 'expires_at' );
        if ( null !== $expires_at ) {
            $expires_at = sanitize_text_field( (string) $expires_at );
            $coupon->set_date_expires( $expires_at ? $expires_at : null );
        }
        if ( $seller_scope > 0 ) {
            $coupon->update_meta_data( '_tnm_seller_id', $seller_scope );
            // Restrict the coupon's product_ids to this seller's catalog
            // so a buyer can't use a Vermont Woodworks 10% coupon on a
            // ceramics-studio order that happens to have both sellers in it.
            $product_ids = tnm_seller_product_ids( $seller_scope, array( 'publish', 'private' ) );
            if ( $product_ids ) {
                $coupon->set_product_ids( array_map( 'intval', $product_ids ) );
            }
        }
        $id = $coupon->save();
        if ( is_wp_error( $id ) ) { return $id; }
        return (int) $id;
    }

    private static function seller_owns_coupon( int $coupon_id, int $seller_id ): bool {
        $meta = (int) get_post_meta( $coupon_id, '_tnm_seller_id', true );
        return $meta === $seller_id;
    }

    public static function seller_coupons_list(): WP_REST_Response {
        $seller_id = get_current_user_id();
        $q = new WP_Query( array( 'post_type' => 'shop_coupon', 'post_status' => 'publish', 'posts_per_page' => 200, 'meta_key' => '_tnm_seller_id', 'meta_value' => $seller_id ) );
        $items = array();
        foreach ( $q->posts as $post ) {
            $coupon = new WC_Coupon( $post->ID );
            $items[] = self::coupon_to_array( $coupon );
        }
        return rest_ensure_response( array( 'items' => $items ) );
    }

    public static function seller_coupon_create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $seller_id = get_current_user_id();
        $coupon    = new WC_Coupon();
        $id = self::coupon_apply_payload( $request, $coupon, $seller_id );
        if ( is_wp_error( $id ) ) { return $id; }
        return rest_ensure_response( self::coupon_to_array( new WC_Coupon( $id ) ) );
    }

    public static function seller_coupon_update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $seller_id = get_current_user_id();
        $coupon_id = absint( $request['id'] );
        if ( ! self::seller_owns_coupon( $coupon_id, $seller_id ) && ! tnm_is_admin_or_manager() ) {
            return tnm_json_error( 'coupon_permission_denied', 'You cannot edit this coupon.', 403 );
        }
        $coupon = new WC_Coupon( $coupon_id );
        $id = self::coupon_apply_payload( $request, $coupon, $seller_id );
        if ( is_wp_error( $id ) ) { return $id; }
        return rest_ensure_response( self::coupon_to_array( new WC_Coupon( $id ) ) );
    }

    public static function seller_coupon_delete( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $seller_id = get_current_user_id();
        $coupon_id = absint( $request['id'] );
        if ( ! self::seller_owns_coupon( $coupon_id, $seller_id ) && ! tnm_is_admin_or_manager() ) {
            return tnm_json_error( 'coupon_permission_denied', 'You cannot delete this coupon.', 403 );
        }
        wp_delete_post( $coupon_id, true );
        return rest_ensure_response( array( 'success' => true ) );
    }

    public static function admin_coupons_list(): WP_REST_Response {
        // Site-wide coupons only — the ones without _tnm_seller_id set.
        $q = new WP_Query( array( 'post_type' => 'shop_coupon', 'post_status' => 'publish', 'posts_per_page' => 500, 'meta_query' => array( array( 'key' => '_tnm_seller_id', 'compare' => 'NOT EXISTS' ) ) ) );
        $items = array();
        foreach ( $q->posts as $post ) {
            $coupon = new WC_Coupon( $post->ID );
            $items[] = self::coupon_to_array( $coupon );
        }
        return rest_ensure_response( array( 'items' => $items ) );
    }

    public static function admin_coupon_create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $coupon = new WC_Coupon();
        $id = self::coupon_apply_payload( $request, $coupon, 0 );
        if ( is_wp_error( $id ) ) { return $id; }
        return rest_ensure_response( self::coupon_to_array( new WC_Coupon( $id ) ) );
    }

    public static function admin_coupon_update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $coupon = new WC_Coupon( absint( $request['id'] ) );
        $id = self::coupon_apply_payload( $request, $coupon, 0 );
        if ( is_wp_error( $id ) ) { return $id; }
        return rest_ensure_response( self::coupon_to_array( new WC_Coupon( $id ) ) );
    }

    public static function admin_coupon_delete( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        wp_delete_post( absint( $request['id'] ), true );
        return rest_ensure_response( array( 'success' => true ) );
    }

    /**
     * Validate a coupon against a proposed cart. Called by the mobile cart
     * to preview the discount — the same math runs again inside
     * mnu_native_calc_items() so the final charge always matches.
     */
    public static function coupon_apply( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $code = wc_format_coupon_code( (string) $request->get_param( 'code' ) );
        if ( '' === $code ) {
            return tnm_json_error( 'invalid_coupon_code', 'Enter a coupon code.', 422 );
        }
        $items_in = (array) $request->get_param( 'items' );
        if ( ! $items_in ) {
            return tnm_json_error( 'invalid_cart', 'Cart is empty.', 422 );
        }
        $coupon_id = wc_get_coupon_id_by_code( $code );
        if ( ! $coupon_id ) {
            return tnm_json_error( 'coupon_not_found', 'Coupon code not found.', 404 );
        }
        $coupon = new WC_Coupon( $coupon_id );
        // Expiration + usage limit checks.
        if ( $coupon->get_date_expires() && $coupon->get_date_expires()->getTimestamp() < current_time( 'timestamp', true ) ) {
            return tnm_json_error( 'coupon_expired', 'This coupon has expired.', 410 );
        }
        if ( $coupon->get_usage_limit() > 0 && $coupon->get_usage_count() >= $coupon->get_usage_limit() ) {
            return tnm_json_error( 'coupon_used_up', 'This coupon has reached its usage limit.', 410 );
        }
        // Compute subtotal + eligible subtotal.
        $subtotal = 0.0; $eligible = 0.0; $seller_ids = array();
        $allowed_product_ids = $coupon->get_product_ids();
        foreach ( $items_in as $item ) {
            $pid = absint( $item['product_id'] ?? 0 );
            $qty = max( 1, (int) ( $item['quantity'] ?? 1 ) );
            $product = wc_get_product( $pid );
            if ( ! $product ) { continue; }
            $vid = absint( $item['variation_id'] ?? 0 );
            $priceable = $vid ? wc_get_product( $vid ) : $product;
            if ( ! $priceable ) { continue; }
            $line = (float) $priceable->get_price() * $qty;
            $subtotal += $line;
            $seller_ids[] = tnm_get_product_seller_id( $product );
            if ( ! $allowed_product_ids || in_array( $pid, $allowed_product_ids, true ) ) {
                $eligible += $line;
            }
        }
        if ( $coupon->get_minimum_amount() > 0 && $subtotal < (float) $coupon->get_minimum_amount() ) {
            return tnm_json_error( 'coupon_min_amount', sprintf( 'Cart subtotal must be at least $%.2f.', (float) $coupon->get_minimum_amount() ), 422 );
        }
        if ( $eligible <= 0 ) {
            return tnm_json_error( 'coupon_not_applicable', 'This coupon does not apply to any items in your cart.', 422 );
        }
        $type   = $coupon->get_discount_type();
        $amount = (float) $coupon->get_amount();
        $discount = 0.0;
        if ( 'percent' === $type ) { $discount = round( $eligible * ( $amount / 100 ), 2 ); }
        elseif ( 'fixed_cart' === $type ) { $discount = min( $eligible, $amount ); }
        elseif ( 'fixed_product' === $type ) { $discount = min( $eligible, $amount ); }
        $discount = max( 0.0, round( $discount, 2 ) );
        return rest_ensure_response( array(
            'coupon'         => self::coupon_to_array( $coupon ),
            'subtotal'       => round( $subtotal, 2 ),
            'eligible'       => round( $eligible, 2 ),
            'discount'       => $discount,
            'free_shipping'  => (bool) $coupon->get_free_shipping(),
        ) );
    }

    // ---- Product reviews ---------------------------------------------------

    public static function product_reviews( WP_REST_Request $request ): WP_REST_Response {
        $product_id = absint( $request['id'] );
        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return rest_ensure_response( array( 'items' => array(), 'total' => 0, 'average' => 0, 'page' => 1, 'total_pages' => 0 ) );
        }
        $page     = max( 1, (int) $request->get_param( 'page' ) );
        $per_page = max( 1, min( 100, (int) ( $request->get_param( 'per_page' ) ?: 20 ) ) );
        return rest_ensure_response( TNM_Social::product_reviews( $product_id, $page, $per_page ) );
    }

    public static function submit_product_review( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        global $wpdb;
        $reviewer_id = get_current_user_id();
        $product_id  = absint( $request['id'] );
        $order_id    = absint( $request->get_param( 'order_id' ) );
        $rating      = (int) $request->get_param( 'rating' );
        $review      = sanitize_textarea_field( (string) $request->get_param( 'review' ) );
        $variation_id = absint( $request->get_param( 'variation_id' ) );
        $photo_ids    = array_values( array_filter( array_map( 'absint', (array) $request->get_param( 'photo_ids' ) ) ) );
        $product      = $product_id ? wc_get_product( $product_id ) : false;

        if ( ! $product || ! $order_id || $rating < 1 || $rating > 5 || strlen( $review ) > 2000 || count( $photo_ids ) > 5 ) {
            return tnm_json_error( 'invalid_product_review', 'Enter a rating, review, and valid review details.', 422 );
        }
        $order = wc_get_order( $order_id );
        if ( ! $order || (int) $order->get_customer_id() !== $reviewer_id || ! in_array( $order->get_status(), array( 'completed' ), true ) ) {
            return tnm_json_error( 'review_not_verified', 'Only the buyer of a completed order can review this product.', 403 );
        }
        $purchased = false;
        foreach ( $order->get_items() as $item ) {
            if ( ! $item instanceof WC_Order_Item_Product || (int) $item->get_product_id() !== $product_id ) {
                continue;
            }
            if ( $variation_id && (int) $item->get_variation_id() !== $variation_id ) {
                continue;
            }
            $purchased = true;
            if ( ! $variation_id ) {
                $variation_id = (int) $item->get_variation_id();
            }
            break;
        }
        if ( ! $purchased ) {
            return tnm_json_error( 'review_not_verified', 'This completed order does not contain that product.', 403 );
        }
        foreach ( $photo_ids as $photo_id ) {
            $photo = get_post( $photo_id );
            if ( ! $photo || 'attachment' !== $photo->post_type || (int) $photo->post_author !== $reviewer_id ) {
                return tnm_json_error( 'review_not_verified', 'Review photos must be uploaded by the buyer.', 403 );
            }
        }
        $seller_id = tnm_get_product_seller_id( $product );
        if ( ! $seller_id ) {
            return tnm_json_error( 'product_not_found', 'Product not found.', 404 );
        }
        $existing = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . tnm_table( 'reviews' ) . ' WHERE reviewer_id=%d AND order_id=%d AND product_id=%d', $reviewer_id, $order_id, $product_id ) );
        if ( $existing ) {
            return tnm_json_error( 'product_review_exists', 'You have already reviewed this product from this order.', 409 );
        }
        $now = current_time( 'mysql', true );
        $inserted = $wpdb->insert(
            tnm_table( 'reviews' ),
            array(
                'reviewer_id' => $reviewer_id, 'seller_id' => $seller_id, 'order_id' => $order_id,
                'product_id' => $product_id, 'variation_id' => $variation_id, 'rating' => $rating,
                'review' => $review, 'photo_ids' => wp_json_encode( $photo_ids ), 'status' => 'approved',
                'created_at' => $now, 'updated_at' => $now,
            ),
            array( '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
        );
        if ( false === $inserted ) {
            if ( false !== strpos( strtolower( $wpdb->last_error ), 'duplicate' ) ) {
                return tnm_json_error( 'product_review_exists', 'You have already reviewed this product from this order.', 409 );
            }
            return tnm_json_error( 'product_review_failed', 'Could not save your review. Please try again.', 500 );
        }
        $review_id = (int) $wpdb->insert_id;
        TNM_Social::clear_product_rating_summary( $product_id );
        tnm_notify( $seller_id, $reviewer_id, 'product_review', 'New product review', wp_trim_words( $review, 20 ), $review_id, 'orders' );
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . tnm_table( 'reviews' ) . ' WHERE id=%d', $review_id ), ARRAY_A );
        return rest_ensure_response( TNM_Social::product_review_to_array( $row ?: array() ) );
    }

    public static function respond_to_product_review( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        global $wpdb;
        $product_id = absint( $request['id'] );
        $review_id  = absint( $request['review_id'] );
        $response   = sanitize_textarea_field( (string) $request->get_param( 'response' ) );
        $product    = $product_id ? wc_get_product( $product_id ) : false;
        if ( ! $product || tnm_get_product_seller_id( $product ) !== get_current_user_id() ) {
            return tnm_json_error( 'review_response_forbidden', 'Only this product’s seller can respond.', 403 );
        }
        if ( '' === trim( $response ) || strlen( $response ) > 2000 ) {
            return tnm_json_error( 'invalid_review_response', 'Response must be between 1 and 2000 characters.', 422 );
        }
        $updated = $wpdb->update(
            tnm_table( 'reviews' ),
            array( 'seller_response' => $response, 'seller_response_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ) ),
            array( 'id' => $review_id, 'product_id' => $product_id ),
            array( '%s', '%s', '%s' ),
            array( '%d', '%d' )
        );
        if ( false === $updated || 0 === $updated ) {
            return tnm_json_error( 'product_review_not_found', 'Review not found.', 404 );
        }
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . tnm_table( 'reviews' ) . ' WHERE id=%d', $review_id ), ARRAY_A );
        return rest_ensure_response( TNM_Social::product_review_to_array( $row ?: array() ) );
    }

    public static function reviewable_products( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        global $wpdb;
        $order_id = absint( $request['id'] );
        $order    = wc_get_order( $order_id );
        if ( ! $order || (int) $order->get_customer_id() !== get_current_user_id() || ! in_array( $order->get_status(), array( 'completed' ), true ) ) {
            return tnm_json_error( 'reviewable_products_forbidden', 'Only completed orders you placed can be reviewed.', 403 );
        }
        $items = array();
        foreach ( $order->get_items() as $item ) {
            if ( ! $item instanceof WC_Order_Item_Product ) {
                continue;
            }
            $product_id = (int) $item->get_product_id();
            if ( ! $product_id || isset( $items[ $product_id ] ) ) {
                continue;
            }
            $product = wc_get_product( $product_id );
            $items[ $product_id ] = array(
                'product_id'       => $product_id,
                'name'             => $product ? $product->get_name() : $item->get_name(),
                'image'            => $product ? ( wp_get_attachment_image_url( $product->get_image_id(), 'medium' ) ?: wc_placeholder_img_src() ) : '',
                'variation_id'     => (int) $item->get_variation_id(),
                'already_reviewed' => (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . tnm_table( 'reviews' ) . ' WHERE reviewer_id=%d AND order_id=%d AND product_id=%d', get_current_user_id(), $order_id, $product_id ) ),
            );
        }
        return rest_ensure_response( array( 'items' => array_values( $items ) ) );
    }

    public static function seller_product_reviews( WP_REST_Request $request ): WP_REST_Response {
        global $wpdb;
        $page     = max( 1, (int) $request->get_param( 'page' ) );
        $per_page = max( 1, min( 100, (int) ( $request->get_param( 'per_page' ) ?: 20 ) ) );
        $seller_id = get_current_user_id();
        $summary = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS total, AVG(rating) AS average FROM " . tnm_table( 'reviews' ) . " WHERE seller_id=%d AND product_id!=0 AND status='approved'", $seller_id ), ARRAY_A );
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM " . tnm_table( 'reviews' ) . " WHERE seller_id=%d AND product_id!=0 AND status='approved' ORDER BY created_at DESC,id DESC LIMIT %d OFFSET %d", $seller_id, $per_page, ( $page - 1 ) * $per_page ), ARRAY_A );
        $items = array();
        foreach ( $rows as $row ) {
            $item = TNM_Social::product_review_to_array( $row );
            $item['product_name'] = get_the_title( (int) $row['product_id'] );
            $items[] = $item;
        }
        return rest_ensure_response( array( 'items' => $items, 'total' => (int) ( $summary['total'] ?? 0 ), 'average' => round( (float) ( $summary['average'] ?? 0 ), 2 ), 'page' => $page, 'total_pages' => (int) ceil( (int) ( $summary['total'] ?? 0 ) / $per_page ) ) );
    }

    // ---- Buyer address book ------------------------------------------------

    private static function address_book(): array {
        $raw = get_user_meta( get_current_user_id(), 'tnm_address_book', true );
        return is_array( $raw ) ? array_values( $raw ) : array();
    }

    private static function save_address_book( array $book ): void {
        update_user_meta( get_current_user_id(), 'tnm_address_book', array_values( $book ) );
    }

    private static function sanitize_address_row( array $data, string $id_hint = '' ): array {
        $row = array(
            'id'         => $id_hint ?: sanitize_key( uniqid( 'addr_', true ) ),
            'label'      => sanitize_text_field( (string) ( $data['label'] ?? '' ) ),
            'first_name' => sanitize_text_field( (string) ( $data['first_name'] ?? '' ) ),
            'last_name'  => sanitize_text_field( (string) ( $data['last_name'] ?? '' ) ),
            'company'    => sanitize_text_field( (string) ( $data['company'] ?? '' ) ),
            'address_1'  => sanitize_text_field( (string) ( $data['address_1'] ?? $data['address'] ?? '' ) ),
            'address_2'  => sanitize_text_field( (string) ( $data['address_2'] ?? '' ) ),
            'city'       => sanitize_text_field( (string) ( $data['city'] ?? '' ) ),
            'state'      => sanitize_text_field( (string) ( $data['state'] ?? 'NH' ) ),
            'postcode'   => sanitize_text_field( (string) ( $data['postcode'] ?? '' ) ),
            'country'    => strtoupper( sanitize_text_field( (string) ( $data['country'] ?? 'US' ) ) ) ?: 'US',
            'phone'      => sanitize_text_field( (string) ( $data['phone'] ?? '' ) ),
            'is_default' => (bool) ( $data['is_default'] ?? false ),
        );
        return $row;
    }

    public static function address_book_list(): WP_REST_Response {
        $book = self::address_book();
        // Ensure at most one is default.
        $default_seen = false;
        foreach ( $book as &$row ) {
            if ( ! empty( $row['is_default'] ) ) {
                if ( $default_seen ) { $row['is_default'] = false; } else { $default_seen = true; }
            }
        }
        unset( $row );
        return rest_ensure_response( array( 'items' => $book ) );
    }

    public static function address_book_create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $data = $request->get_json_params() ?: $request->get_params();
        if ( empty( $data['address_1'] ) && empty( $data['address'] ) ) {
            return tnm_json_error( 'invalid_address', 'Street address is required.', 422 );
        }
        if ( empty( $data['city'] ) || empty( $data['postcode'] ) ) {
            return tnm_json_error( 'invalid_address', 'City and postcode are required.', 422 );
        }
        $book = self::address_book();
        $row  = self::sanitize_address_row( (array) $data );
        if ( ! empty( $row['is_default'] ) ) {
            foreach ( $book as &$existing ) { $existing['is_default'] = false; }
            unset( $existing );
        } elseif ( ! $book ) {
            $row['is_default'] = true; // first address is default
        }
        $book[] = $row;
        self::save_address_book( $book );
        return rest_ensure_response( $row );
    }

    public static function address_book_update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $id   = sanitize_key( (string) $request['id'] );
        $book = self::address_book();
        $found = false; $updated = null;
        $data = $request->get_json_params() ?: $request->get_params();
        foreach ( $book as &$existing ) {
            if ( $existing['id'] === $id ) {
                $found = true;
                $existing = self::sanitize_address_row( array_merge( $existing, (array) $data ), $id );
                $updated = $existing;
                break;
            }
        }
        unset( $existing );
        if ( ! $found ) {
            return tnm_json_error( 'address_not_found', 'Address not found.', 404 );
        }
        if ( ! empty( $updated['is_default'] ) ) {
            foreach ( $book as &$existing ) {
                if ( $existing['id'] !== $id ) { $existing['is_default'] = false; }
            }
            unset( $existing );
        }
        self::save_address_book( $book );
        return rest_ensure_response( $updated );
    }

    public static function address_book_delete( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $id   = sanitize_key( (string) $request['id'] );
        $book = self::address_book();
        $filtered = array_values( array_filter( $book, static fn( $row ) => $row['id'] !== $id ) );
        if ( count( $filtered ) === count( $book ) ) {
            return tnm_json_error( 'address_not_found', 'Address not found.', 404 );
        }
        // Re-establish a default when we removed the previous one.
        $has_default = false;
        foreach ( $filtered as $row ) { if ( ! empty( $row['is_default'] ) ) { $has_default = true; break; } }
        if ( ! $has_default && $filtered ) { $filtered[0]['is_default'] = true; }
        self::save_address_book( $filtered );
        return rest_ensure_response( array( 'success' => true ) );
    }

    // ---- Buyer alert preferences (v3.7.120) --------------------------------

    // v3.7.121 (Build #17b) — push preferences center. Each category is a
    // user_meta boolean stored as '0' (off) or '1' (on); anything unset
    // defaults to on so we don't silently turn off notifications when a
    // user upgrades the app. MNU_Ops::notify_user() checks these via the
    // helper below before sending a push.
    const PREF_CATEGORIES = array( 'orders', 'messages', 'price_drop_alerts', 'follows', 'promos' );

    public static function me_pref_meta_key( string $category ): string {
        // price_drop_alerts keeps its legacy key so v3.7.120 clients keep
        // reading the same value; new categories get a mnu_pref_ prefix.
        if ( 'price_drop_alerts' === $category ) { return MNU_Price_Drop::USER_META_OPT_IN; }
        return 'mnu_pref_' . $category;
    }

    public static function me_pref_enabled( int $user_id, string $category ): bool {
        if ( ! in_array( $category, self::PREF_CATEGORIES, true ) ) { return true; }
        $raw = (string) get_user_meta( $user_id, self::me_pref_meta_key( $category ), true );
        return '0' !== $raw; // unset / '1' / legacy → on
    }

    public static function me_preferences_get(): WP_REST_Response {
        $uid = get_current_user_id();
        $out = array();
        foreach ( self::PREF_CATEGORIES as $cat ) {
            $out[ $cat ] = self::me_pref_enabled( $uid, $cat );
        }
        return rest_ensure_response( $out );
    }

    public static function me_preferences_update( WP_REST_Request $request ): WP_REST_Response {
        $uid = get_current_user_id();
        foreach ( self::PREF_CATEGORIES as $cat ) {
            $raw = $request->get_param( $cat );
            if ( null === $raw ) { continue; }
            $enabled = in_array( $raw, array( true, 1, '1', 'true', 'on' ), true );
            update_user_meta( $uid, self::me_pref_meta_key( $cat ), $enabled ? '1' : '0' );
        }
        return self::me_preferences_get();
    }

    // ---- Recently viewed products (v3.7.121 / Build #18a) ------------------
    //
    // Stored as user_meta 'mnu_recently_viewed' = array of
    // [ 'id' => int, 'ts' => unix ] rows, most-recent-first, capped at 20.
    // We deliberately store timestamps so we can filter out anything stale
    // when the buyer hasn't opened the app in a long time.
    const RECENTLY_VIEWED_META = 'mnu_recently_viewed';
    const RECENTLY_VIEWED_MAX  = 20;

    private static function recently_viewed_load( int $user_id ): array {
        $raw = get_user_meta( $user_id, self::RECENTLY_VIEWED_META, true );
        if ( ! is_array( $raw ) ) { return array(); }
        $out = array();
        foreach ( $raw as $row ) {
            if ( ! is_array( $row ) || empty( $row['id'] ) ) { continue; }
            $out[] = array( 'id' => (int) $row['id'], 'ts' => isset( $row['ts'] ) ? (int) $row['ts'] : 0 );
        }
        return $out;
    }

    public static function recently_viewed_get( WP_REST_Request $request ): WP_REST_Response {
        $uid = get_current_user_id();
        $rows = self::recently_viewed_load( $uid );
        $limit = max( 1, min( self::RECENTLY_VIEWED_MAX, (int) $request->get_param( 'limit' ) ?: self::RECENTLY_VIEWED_MAX ) );
        $items = array();
        foreach ( array_slice( $rows, 0, $limit ) as $row ) {
            $product = wc_get_product( (int) $row['id'] );
            if ( ! $product || $product->get_status() !== 'publish' ) { continue; }
            $items[] = TNM_Marketplace::product_to_array( $product );
        }
        return rest_ensure_response( array( 'items' => $items ) );
    }

    public static function recently_viewed_track( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $uid = get_current_user_id();
        $pid = absint( $request->get_param( 'product_id' ) );
        if ( ! $pid ) { return new WP_Error( 'missing_product_id', 'product_id is required.', array( 'status' => 400 ) ); }
        $product = wc_get_product( $pid );
        if ( ! $product || $product->get_status() !== 'publish' ) {
            return new WP_Error( 'product_not_found', 'Product not found.', array( 'status' => 404 ) );
        }
        $rows = self::recently_viewed_load( $uid );
        // Drop any existing row for this product; MRU is a moving window.
        $rows = array_values( array_filter( $rows, function( $r ) use ( $pid ) { return (int) $r['id'] !== $pid; } ) );
        array_unshift( $rows, array( 'id' => $pid, 'ts' => time() ) );
        if ( count( $rows ) > self::RECENTLY_VIEWED_MAX ) {
            $rows = array_slice( $rows, 0, self::RECENTLY_VIEWED_MAX );
        }
        update_user_meta( $uid, self::RECENTLY_VIEWED_META, $rows );
        return rest_ensure_response( array( 'ok' => true, 'count' => count( $rows ) ) );
    }

    public static function recently_viewed_clear(): WP_REST_Response {
        $uid = get_current_user_id();
        delete_user_meta( $uid, self::RECENTLY_VIEWED_META );
        return rest_ensure_response( array( 'ok' => true ) );
    }
}
