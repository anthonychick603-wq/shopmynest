<?php
/**
 * v3.7.114 — Admin console REST surface for the mobile app.
 *
 * Powers the ShopMyNest admin drawer (small admin controls surfaced inside
 * the regular buyer app, gated on user.role === "admin"). All routes require
 * marketplace administrator access via MNU_Blog::admin(); non-admins get a
 * 403, unauthenticated requests get a 401.
 *
 * Endpoints (namespace: the-nest/v1):
 *   GET  /admin/stats                        — dashboard counts
 *   GET  /admin/orders?range=7d|30d|all      — marketplace-wide orders list
 *                                              (v3.7.117)
 *   GET  /admin/reports?status=...           — moderation queue for user
 *                                              reports (mynest_report CPT)
 *   POST /admin/reports/{id}/resolve         — mark report resolved
 *   POST /admin/reports/{id}/dismiss         — mark report dismissed
 */

defined( 'ABSPATH' ) || exit;

final class MNU_Admin_Console {

    const NS         = 'the-nest/v1';
    const REPORT_CPT = 'mynest_report';

    /** Report status → WordPress post status. */
    private const STATUSES = array(
        'pending'   => 'pending',
        'resolved'  => 'publish',
        'dismissed' => 'private',
    );

    public static function init(): void {
        add_action( 'init', array( __CLASS__, 'register_post_type' ) );
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
    }

    /**
     * Register the mynest_report CPT. MNU_Blog::report_post() has been
     * inserting into this post type since v3.7.110 without registering it,
     * relying on WP accepting arbitrary post types. Registering here makes
     * wp_count_posts() and WP_Query with post_status filters reliable, and
     * gives the admin console a stable schema to build against.
     *
     * `public: false` + `show_in_rest: false` keeps reports off the public
     * REST feed and out of the admin sidebar; the console reads them via
     * the-nest/v1/admin/reports.
     */
    public static function register_post_type(): void {
        if ( post_type_exists( self::REPORT_CPT ) ) {
            return;
        }
        register_post_type(
            self::REPORT_CPT,
            array(
                'labels'              => array(
                    'name'          => __( 'Reports', 'mynest-unified-marketplace' ),
                    'singular_name' => __( 'Report', 'mynest-unified-marketplace' ),
                ),
                'public'              => false,
                'show_ui'             => false,
                'show_in_menu'        => false,
                'show_in_rest'        => false,
                'supports'            => array( 'title', 'editor', 'author' ),
                'capability_type'     => 'post',
                'map_meta_cap'        => true,
                'exclude_from_search' => true,
            )
        );
    }

    public static function register_routes(): void {
        register_rest_route(
            self::NS,
            '/admin/stats',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( __CLASS__, 'stats' ),
                'permission_callback' => array( 'MNU_Blog', 'admin' ),
            )
        );
        register_rest_route(
            self::NS,
            '/admin/reports',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( __CLASS__, 'list_reports' ),
                'permission_callback' => array( 'MNU_Blog', 'admin' ),
            )
        );
        register_rest_route(
            self::NS,
            '/admin/orders',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( __CLASS__, 'list_orders' ),
                'permission_callback' => array( 'MNU_Blog', 'admin' ),
            )
        );
        register_rest_route(
            self::NS,
            '/admin/reports/(?P<id>\d+)/resolve',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( __CLASS__, 'resolve_report' ),
                'permission_callback' => array( 'MNU_Blog', 'admin' ),
            )
        );
        register_rest_route(
            self::NS,
            '/admin/reports/(?P<id>\d+)/dismiss',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( __CLASS__, 'dismiss_report' ),
                'permission_callback' => array( 'MNU_Blog', 'admin' ),
            )
        );
    }

    /* ---------------------------------------------------------------- stats */

    public static function stats(): WP_REST_Response {
        $blog_counts   = wp_count_posts( MNU_Blog::CPT );
        $report_counts = wp_count_posts( self::REPORT_CPT );

        // Seller count: match the public directory's role list.
        $roles = array( 'tnm_seller', 'mynest_seller', 'seller', 'vendor', 'wcv_vendor', 'dokan_vendor', 'shop_vendor', 'wc_product_vendors_vendor' );
        $seller_query = new WP_User_Query(
            array(
                'role__in' => $roles,
                'fields'   => 'ID',
                'number'   => -1,
            )
        );
        $sellers_total = (int) $seller_query->get_total();

        // Products total (published only).
        $product_counts   = wp_count_posts( 'product' );
        $products_total   = isset( $product_counts->publish ) ? (int) $product_counts->publish : 0;

        // Orders in the last 7 days. WooCommerce may not be active — fall
        // back to 0 in that case rather than fataling.
        $orders_7d = 0;
        if ( post_type_exists( 'shop_order' ) ) {
            $seven_days_ago = gmdate( 'Y-m-d H:i:s', strtotime( '-7 days' ) );
            $orders_query = new WP_Query(
                array(
                    'post_type'      => 'shop_order',
                    'post_status'    => array( 'wc-processing', 'wc-completed', 'wc-on-hold' ),
                    'posts_per_page' => -1,
                    'fields'         => 'ids',
                    'date_query'     => array(
                        array( 'after' => $seven_days_ago, 'inclusive' => true ),
                    ),
                    'no_found_rows'  => false,
                )
            );
            $orders_7d = (int) $orders_query->found_posts;
        }

        return rest_ensure_response(
            array(
                'pending_blog_posts' => isset( $blog_counts->pending ) ? (int) $blog_counts->pending : 0,
                'pending_reports'    => isset( $report_counts->pending ) ? (int) $report_counts->pending : 0,
                'sellers_total'      => $sellers_total,
                'products_total'     => $products_total,
                'orders_7d'          => $orders_7d,
                'refreshed_at'       => current_time( 'c' ),
            )
        );
    }

    /* --------------------------------------------------------------- orders */

    /**
     * v3.7.117 — marketplace-wide orders list for the admin drawer's
     * Orders tile. Filters by created-date range (default last 7 days,
     * matching the tile label). WooCommerce is expected but optional —
     * returns an empty list rather than a 500 if shop_order isn't
     * registered.
     */
    public static function list_orders( WP_REST_Request $request ): WP_REST_Response {
        $range    = (string) $request->get_param( 'range' );
        $page     = max( 1, (int) ( $request->get_param( 'page' ) ?: 1 ) );
        $per_page = max( 1, min( 50, (int) ( $request->get_param( 'per_page' ) ?: 20 ) ) );

        if ( ! post_type_exists( 'shop_order' ) ) {
            return rest_ensure_response( array(
                'items'       => array(),
                'page'        => $page,
                'total'       => 0,
                'total_pages' => 0,
                'range'       => $range ?: '7d',
            ) );
        }

        $args = array(
            'post_type'      => 'shop_order',
            'post_status'    => array( 'wc-processing', 'wc-completed', 'wc-on-hold', 'wc-refunded', 'wc-cancelled', 'wc-failed', 'wc-pending' ),
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'no_found_rows'  => false,
        );

        $days = null;
        if ( '30d' === $range ) { $days = 30; }
        elseif ( 'all' === $range ) { $days = null; }
        else { $range = '7d'; $days = 7; }

        if ( null !== $days ) {
            $args['date_query'] = array(
                array( 'after' => gmdate( 'Y-m-d H:i:s', strtotime( '-' . $days . ' days' ) ), 'inclusive' => true ),
            );
        }

        $q = new WP_Query( $args );
        $items = array();
        foreach ( $q->posts as $post ) {
            $order = function_exists( 'wc_get_order' ) ? wc_get_order( $post->ID ) : null;
            if ( ! $order ) {
                continue;
            }
            $items[] = array(
                'id'         => (int) $order->get_id(),
                'number'     => (string) $order->get_order_number(),
                'status'     => (string) $order->get_status(),
                'total'      => (float) $order->get_total(),
                'currency'   => (string) $order->get_currency(),
                'item_count' => (int) $order->get_item_count(),
                'buyer'      => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) ?: $order->get_billing_email(),
                'created_at' => $order->get_date_created() ? $order->get_date_created()->date( 'c' ) : null,
            );
        }

        return rest_ensure_response( array(
            'items'       => $items,
            'page'        => $page,
            'total'       => (int) $q->found_posts,
            'total_pages' => (int) $q->max_num_pages,
            'range'       => $range,
        ) );
    }

    /* -------------------------------------------------------------- reports */

    public static function list_reports( WP_REST_Request $request ): WP_REST_Response {
        $status_key = (string) $request->get_param( 'status' );
        if ( ! isset( self::STATUSES[ $status_key ] ) ) {
            $status_key = 'pending';
        }
        $post_status = self::STATUSES[ $status_key ];
        $page        = max( 1, (int) ( $request->get_param( 'page' ) ?: 1 ) );
        $per_page    = max( 1, min( 50, (int) ( $request->get_param( 'per_page' ) ?: 20 ) ) );

        $q = new WP_Query(
            array(
                'post_type'      => self::REPORT_CPT,
                'post_status'    => $post_status,
                'posts_per_page' => $per_page,
                'paged'          => $page,
                'orderby'        => 'date',
                'order'          => 'DESC',
                'no_found_rows'  => false,
            )
        );

        $items = array();
        foreach ( $q->posts as $report ) {
            $items[] = self::report_to_array( $report );
        }

        return rest_ensure_response(
            array(
                'items'       => $items,
                'page'        => $page,
                'total'       => (int) $q->found_posts,
                'total_pages' => (int) $q->max_num_pages,
                'status'      => $status_key,
            )
        );
    }

    public static function resolve_report( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        return self::update_report_status( absint( $request['id'] ), 'resolved' );
    }

    public static function dismiss_report( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        return self::update_report_status( absint( $request['id'] ), 'dismissed' );
    }

    private static function update_report_status( int $id, string $status_key ): WP_REST_Response|WP_Error {
        if ( ! isset( self::STATUSES[ $status_key ] ) ) {
            return tnm_json_error( 'invalid_status', 'Unknown report status.', 422 );
        }
        $post = get_post( $id );
        if ( ! $post || $post->post_type !== self::REPORT_CPT ) {
            return tnm_json_error( 'report_not_found', 'Report not found.', 404 );
        }

        wp_update_post(
            array(
                'ID'          => $id,
                'post_status' => self::STATUSES[ $status_key ],
            )
        );
        update_post_meta( $id, '_mynest_resolved_by', get_current_user_id() );
        update_post_meta( $id, '_mynest_resolved_at', current_time( 'mysql', true ) );

        return rest_ensure_response(
            array(
                'success' => true,
                'report'  => self::report_to_array( get_post( $id ) ),
            )
        );
    }

    /* -------------------------------------------------------------- shaping */

    private static function report_to_array( WP_Post $report ): array {
        $kind         = (string) get_post_meta( $report->ID, '_mynest_report_kind', true );
        $reason       = (string) get_post_meta( $report->ID, '_mynest_reason', true );
        $reporter_id  = (int) get_post_meta( $report->ID, '_mynest_reporter_id', true );
        $product_id   = (int) get_post_meta( $report->ID, '_mynest_product_id', true );
        $blog_post_id = (int) get_post_meta( $report->ID, '_mynest_blog_post_id', true );
        $comment_id   = (int) get_post_meta( $report->ID, '_mynest_blog_comment_id', true );

        $subject_label = '';
        $subject_body  = '';
        $subject_url   = '';
        if ( 'blog_post' === $kind && $blog_post_id ) {
            $subject_label = 'Blog post';
            $bp            = get_post( $blog_post_id );
            $subject_body  = $bp ? wp_trim_words( wp_strip_all_tags( (string) $bp->post_content ), 30 ) : '';
        } elseif ( 'blog_comment' === $kind && $comment_id ) {
            $subject_label = 'Blog comment';
            $comment       = get_comment( $comment_id );
            $subject_body  = $comment ? wp_trim_words( wp_strip_all_tags( (string) $comment->comment_content ), 30 ) : '';
        } elseif ( 'product' === $kind && $product_id ) {
            $subject_label = 'Product';
            $product       = get_post( $product_id );
            $subject_body  = $product ? (string) $product->post_title : '';
        } else {
            $subject_label = $kind ? ucfirst( str_replace( '_', ' ', $kind ) ) : 'Report';
        }

        $reporter = $reporter_id ? get_userdata( $reporter_id ) : null;
        $status_key = array_search( $report->post_status, self::STATUSES, true );
        if ( false === $status_key ) $status_key = 'pending';

        $resolved_by_id = (int) get_post_meta( $report->ID, '_mynest_resolved_by', true );
        $resolved_by    = $resolved_by_id ? get_userdata( $resolved_by_id ) : null;
        $resolved_at    = (string) get_post_meta( $report->ID, '_mynest_resolved_at', true );

        return array(
            'id'             => (int) $report->ID,
            'kind'           => $kind,
            'status'         => $status_key,
            'reason'         => $reason,
            'created_at'     => mysql2date( 'c', $report->post_date_gmt, false ),
            'reporter'       => $reporter ? array(
                'id'   => (int) $reporter->ID,
                'name' => (string) $reporter->display_name,
            ) : null,
            'subject_label'  => $subject_label,
            'subject_body'   => $subject_body,
            'subject_url'    => $subject_url,
            'blog_post_id'   => $blog_post_id ?: null,
            'blog_comment_id'=> $comment_id ?: null,
            'product_id'     => $product_id ?: null,
            'resolved_by'    => $resolved_by ? array(
                'id'   => (int) $resolved_by->ID,
                'name' => (string) $resolved_by->display_name,
            ) : null,
            'resolved_at'    => $resolved_at ?: null,
        );
    }
}
