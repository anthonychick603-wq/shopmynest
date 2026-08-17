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
        register_rest_route( self::NS, '/media', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'upload_media' ), 'permission_callback' => array( __CLASS__, 'seller' ) ) );

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
        register_rest_route( self::NS, '/messages', array( array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'conversations' ), 'permission_callback' => array( __CLASS__, 'logged_in' ) ), array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'send_message' ), 'permission_callback' => array( __CLASS__, 'logged_in' ) ) ) );
        register_rest_route( self::NS, '/messages/(?P<user_id>\d+)', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'conversation' ), 'permission_callback' => array( __CLASS__, 'logged_in' ) ) );

        register_rest_route( self::NS, '/seller/application', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'application' ), 'permission_callback' => array( __CLASS__, 'logged_in' ) ) );
        register_rest_route( self::NS, '/seller/dashboard', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'seller_dashboard' ), 'permission_callback' => array( __CLASS__, 'seller' ) ) );
        register_rest_route( self::NS, '/seller/profile', array( array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'seller_profile_me' ), 'permission_callback' => array( __CLASS__, 'seller' ) ), array( 'methods' => WP_REST_Server::EDITABLE, 'callback' => array( __CLASS__, 'seller_profile_update' ), 'permission_callback' => array( __CLASS__, 'seller' ) ) ) );
        register_rest_route( self::NS, '/seller/products', array( array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'seller_products' ), 'permission_callback' => array( __CLASS__, 'seller' ) ), array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'seller_product_create' ), 'permission_callback' => array( __CLASS__, 'seller' ) ) ) );
        register_rest_route( self::NS, '/seller/products/(?P<id>\d+)', array( array( 'methods' => WP_REST_Server::EDITABLE, 'callback' => array( __CLASS__, 'seller_product_update' ), 'permission_callback' => array( __CLASS__, 'seller' ) ), array( 'methods' => WP_REST_Server::DELETABLE, 'callback' => array( __CLASS__, 'seller_product_delete' ), 'permission_callback' => array( __CLASS__, 'seller' ) ) ) );
        register_rest_route( self::NS, '/seller/orders', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'seller_orders' ), 'permission_callback' => array( __CLASS__, 'seller' ) ) );
        register_rest_route( self::NS, '/seller/orders/(?P<id>\d+)', array( 'methods' => WP_REST_Server::EDITABLE, 'callback' => array( __CLASS__, 'seller_order_update' ), 'permission_callback' => array( __CLASS__, 'seller' ) ) );
        register_rest_route( self::NS, '/seller/earnings', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'seller_earnings' ), 'permission_callback' => array( __CLASS__, 'seller' ) ) );
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
        $message_id = TNM_Social::send_message( get_current_user_id(), absint( $request->get_param( 'recipient_id' ) ), (string) $request->get_param( 'message' ), absint( $request->get_param( 'product_id' ) ) );
        return is_wp_error( $message_id ) ? $message_id : rest_ensure_response( array( 'success' => true, 'message_id' => $message_id ) );
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

    public static function seller_payouts(): WP_REST_Response {
        return rest_ensure_response( array( 'balances' => TNM_Ledger::balances( get_current_user_id() ), 'payouts' => TNM_Payouts::list_for_seller( get_current_user_id() ), 'minimum' => (float) tnm_get_option( 'minimum_payout', 25 ) ) );
    }

    public static function seller_payout_request( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $payout_id = TNM_Payouts::request( get_current_user_id(), (float) $request->get_param( 'amount' ), sanitize_key( (string) $request->get_param( 'method' ) ), sanitize_text_field( (string) $request->get_param( 'destination' ) ) );
        return is_wp_error( $payout_id ) ? $payout_id : rest_ensure_response( array( 'success' => true, 'payout' => TNM_Payouts::get( $payout_id ) ) );
    }
}
