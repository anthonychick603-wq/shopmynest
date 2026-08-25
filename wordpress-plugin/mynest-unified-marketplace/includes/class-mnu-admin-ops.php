<?php
/**
 * MNU_Admin_Ops
 *
 * v3.13.36 — Unified admin operational queues + actions for the mobile admin
 * drawer (per OPERATIONAL_BACKEND_REQUIREMENTS.md). Adds:
 *
 *   GET  /the-nest/v1/admin/operations               — unified counts summary
 *   GET  /the-nest/v1/admin/seller-applications      — application queue
 *   POST /the-nest/v1/admin/seller-applications/{id}/approve
 *   POST /the-nest/v1/admin/seller-applications/{id}/reject
 *   GET  /the-nest/v1/admin/refunds                  — refund lifecycle queue
 *   POST /the-nest/v1/admin/orders/{id}/refund/process — idempotent alias for approve
 *   GET  /the-nest/v1/admin/payouts                  — payout queue
 *   POST /the-nest/v1/admin/payouts/{id}/process
 *   POST /the-nest/v1/admin/payouts/{id}/retry
 *   POST /the-nest/v1/admin/payouts/{id}/cancel
 *
 * The actual work stays in the domain classes (TNM_Applications,
 * MNU_Refund_Lifecycle, TNM_Payouts) — this file is thin plumbing so the
 * mobile admin drawer has one predictable REST surface.
 *
 * @package MyNest_Unified_Marketplace
 * @since 3.13.36
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

final class MNU_Admin_Ops {

    const NS = 'the-nest/v1';

    public static function init(): void {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
    }

    public static function register_routes(): void {
        $perm = array( 'MNU_Blog', 'admin' );

        register_rest_route( self::NS, '/admin/operations', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( __CLASS__, 'operations_summary' ),
            'permission_callback' => $perm,
        ) );

        register_rest_route( self::NS, '/admin/seller-applications', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( __CLASS__, 'list_seller_applications' ),
            'permission_callback' => $perm,
            'args'                => array(
                'status'   => array( 'type' => 'string', 'required' => false ),
                'page'     => array( 'type' => 'integer', 'required' => false ),
                'per_page' => array( 'type' => 'integer', 'required' => false ),
            ),
        ) );

        register_rest_route( self::NS, '/admin/seller-applications/(?P<id>\d+)/approve', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( __CLASS__, 'approve_seller_application' ),
            'permission_callback' => $perm,
        ) );

        register_rest_route( self::NS, '/admin/seller-applications/(?P<id>\d+)/reject', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( __CLASS__, 'reject_seller_application' ),
            'permission_callback' => $perm,
            'args'                => array(
                'reason'        => array( 'type' => 'string',  'required' => false ),
                'can_resubmit'  => array( 'type' => 'boolean', 'required' => false ),
            ),
        ) );

        register_rest_route( self::NS, '/admin/refunds', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( __CLASS__, 'list_refunds' ),
            'permission_callback' => $perm,
            'args'                => array(
                'status'   => array( 'type' => 'string', 'required' => false ),
                'page'     => array( 'type' => 'integer', 'required' => false ),
                'per_page' => array( 'type' => 'integer', 'required' => false ),
            ),
        ) );

        register_rest_route( self::NS, '/admin/orders/(?P<id>\d+)/refund/process', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( __CLASS__, 'process_refund' ),
            'permission_callback' => $perm,
            'args'                => array(
                'amount' => array( 'type' => 'number', 'required' => false ),
                'note'   => array( 'type' => 'string', 'required' => false ),
            ),
        ) );

        register_rest_route( self::NS, '/admin/payouts', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( __CLASS__, 'list_payouts' ),
            'permission_callback' => $perm,
            'args'                => array(
                'status'   => array( 'type' => 'string', 'required' => false ),
                'page'     => array( 'type' => 'integer', 'required' => false ),
                'per_page' => array( 'type' => 'integer', 'required' => false ),
            ),
        ) );

        register_rest_route( self::NS, '/admin/payouts/(?P<id>\d+)/process', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( __CLASS__, 'process_payout' ),
            'permission_callback' => $perm,
        ) );

        register_rest_route( self::NS, '/admin/payouts/(?P<id>\d+)/retry', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( __CLASS__, 'retry_payout' ),
            'permission_callback' => $perm,
        ) );

        register_rest_route( self::NS, '/admin/payouts/(?P<id>\d+)/cancel', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( __CLASS__, 'cancel_payout' ),
            'permission_callback' => $perm,
            'args'                => array(
                'notes' => array( 'type' => 'string', 'required' => false ),
            ),
        ) );
    }

    /* ---------------------------------------------------------- operations */

    /**
     * Unified counts + oldest_hours for every admin queue the mobile drawer
     * surfaces. Returns 0 counts on any subsystem that isn't installed so the
     * mobile UI can render zeroes cleanly rather than errors.
     */
    public static function operations_summary(): WP_REST_Response {
        global $wpdb;

        // Seller applications
        $app_pending = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key='_tnm_status' AND pm.meta_value='pending'
             WHERE p.post_type='tnm_application'"
        );
        $app_oldest = self::hours_since( (string) $wpdb->get_var(
            "SELECT MIN(p.post_date_gmt) FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key='_tnm_status' AND pm.meta_value='pending'
             WHERE p.post_type='tnm_application'"
        ) );

        // Refunds — requested + approved (awaiting Stripe firing)
        $refund_rows = self::refund_rows( array( 'requested', 'approved' ) );
        $refund_count  = count( $refund_rows );
        $refund_oldest = 0;
        foreach ( $refund_rows as $row ) {
            $h = self::hours_since( (string) ( $row['requested_at'] ?? '' ) );
            if ( $h > $refund_oldest ) { $refund_oldest = $h; }
        }

        // Disputes — open on trust plugin's own table.
        $disputes_pending = 0;
        $disputes_oldest  = 0;
        if ( class_exists( 'TNM_Trust_DB' ) ) {
            $table = TNM_Trust_DB::table( 'disputes' );
            $disputes_pending = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status IN ('open','needs_seller','needs_buyer','escalated')" );
            $disputes_oldest  = self::hours_since( (string) $wpdb->get_var( "SELECT MIN(created_at) FROM {$table} WHERE status IN ('open','needs_seller','needs_buyer','escalated')" ) );
        }

        // Payouts pending / failed
        $payouts_table   = tnm_table( 'payouts' );
        $payouts_pending = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$payouts_table} WHERE status IN ('requested','processing')" );
        $payouts_pending_oldest = self::hours_since( (string) $wpdb->get_var( "SELECT MIN(requested_at) FROM {$payouts_table} WHERE status IN ('requested','processing')" ) );
        $payouts_failed  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$payouts_table} WHERE status IN ('failed','returned')" );
        $payouts_failed_oldest = self::hours_since( (string) $wpdb->get_var( "SELECT MIN(requested_at) FROM {$payouts_table} WHERE status IN ('failed','returned')" ) );

        // Reports moderation queue
        $reports_pending = 0;
        $reports_oldest  = 0;
        if ( post_type_exists( 'mynest_report' ) ) {
            $reports_pending = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='mynest_report' AND post_status='pending'"
            );
            $reports_oldest = self::hours_since( (string) $wpdb->get_var(
                "SELECT MIN(post_date_gmt) FROM {$wpdb->posts} WHERE post_type='mynest_report' AND post_status='pending'"
            ) );
        }

        // Shipping / order exceptions — orders paid but with a purchase-state error.
        $shipping_exceptions = 0;
        if ( post_type_exists( 'shop_order' ) ) {
            $shipping_exceptions = (int) $wpdb->get_var(
                "SELECT COUNT(DISTINCT pm.post_id) FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_key LIKE '_thenest_label_purchase_state%' AND pm.meta_value='error'
                   AND p.post_type='shop_order'"
            );
        }
        $order_exceptions = 0;
        if ( post_type_exists( 'shop_order' ) ) {
            $order_exceptions = (int) $wpdb->get_var(
                "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type='shop_order' AND post_status IN ('wc-on-hold','wc-failed')"
            );
        }

        return rest_ensure_response( array(
            'seller_applications'  => array( 'count' => $app_pending,        'oldest_hours' => $app_oldest ),
            'refunds'              => array( 'count' => $refund_count,       'oldest_hours' => $refund_oldest ),
            'disputes'             => array( 'count' => $disputes_pending,   'oldest_hours' => $disputes_oldest ),
            'payouts_pending'      => array( 'count' => $payouts_pending,    'oldest_hours' => $payouts_pending_oldest ),
            'payouts_failed'       => array( 'count' => $payouts_failed,     'oldest_hours' => $payouts_failed_oldest ),
            'shipping_exceptions'  => array( 'count' => $shipping_exceptions, 'oldest_hours' => 0 ),
            'order_exceptions'     => array( 'count' => $order_exceptions,    'oldest_hours' => 0 ),
            'reports'              => array( 'count' => $reports_pending,     'oldest_hours' => $reports_oldest ),
            'refreshed_at'         => current_time( 'c' ),
        ) );
    }

    /* -------------------------------------------------------- applications */

    public static function list_seller_applications( WP_REST_Request $request ): WP_REST_Response {
        $status   = sanitize_key( (string) $request->get_param( 'status' ) ) ?: 'pending';
        $page     = max( 1, (int) ( $request->get_param( 'page' ) ?: 1 ) );
        $per_page = max( 1, min( 50, (int) ( $request->get_param( 'per_page' ) ?: 20 ) ) );

        // _tnm_status is the source of truth (kept in sync with post_status
        // via TNM_Applications::promote / ::reject). Query by meta so the
        // rejected + resubmittable states are both visible.
        $allowed = array( 'pending', 'approved', 'rejected' );
        if ( ! in_array( $status, $allowed, true ) ) {
            $status = 'pending';
        }

        $q = new WP_Query( array(
            'post_type'      => 'tnm_application',
            'post_status'    => array( 'pending', 'publish', 'draft' ),
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'orderby'        => 'date',
            'order'          => 'ASC',
            'meta_query'     => array(
                array( 'key' => '_tnm_status', 'value' => $status ),
            ),
            'no_found_rows'  => false,
        ) );

        $items = array();
        foreach ( $q->posts as $post ) {
            $items[] = self::application_to_array( $post );
        }

        return rest_ensure_response( array(
            'items'       => $items,
            'page'        => $page,
            'total'       => (int) $q->found_posts,
            'total_pages' => (int) $q->max_num_pages,
            'status'      => $status,
        ) );
    }

    public static function approve_seller_application( WP_REST_Request $request ) {
        $id     = absint( $request['id'] );
        $result = TNM_Applications::approve( $id, get_current_user_id() );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        $post = get_post( $id );
        if ( ! $post ) {
            return tnm_json_error( 'invalid_application', 'Seller application not found.', 404 );
        }
        return rest_ensure_response( self::application_to_array( $post ) );
    }

    public static function reject_seller_application( WP_REST_Request $request ) {
        $id           = absint( $request['id'] );
        $reason       = sanitize_textarea_field( (string) $request->get_param( 'reason' ) );
        $can_resubmit = null === $request->get_param( 'can_resubmit' ) ? true : (bool) $request->get_param( 'can_resubmit' );
        $result       = TNM_Applications::reject( $id, get_current_user_id(), $reason );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        update_post_meta( $id, '_tnm_can_resubmit', $can_resubmit ? '1' : '0' );
        $post = get_post( $id );
        if ( ! $post ) {
            return tnm_json_error( 'invalid_application', 'Seller application not found.', 404 );
        }
        return rest_ensure_response( self::application_to_array( $post ) );
    }

    private static function application_to_array( WP_Post $post ): array {
        $user   = get_userdata( (int) $post->post_author );
        $status = (string) get_post_meta( $post->ID, '_tnm_status', true ) ?: 'pending';
        $reviewed_at = (string) get_post_meta( $post->ID, '_tnm_reviewed_at', true );
        $reviewer_id = (int) get_post_meta( $post->ID, '_tnm_reviewed_by', true );
        $reviewer    = $reviewer_id ? get_userdata( $reviewer_id ) : null;

        // Default: rejections without an explicit meta value can be resubmitted.
        $can_resubmit_meta = get_post_meta( $post->ID, '_tnm_can_resubmit', true );
        $can_resubmit = '' === $can_resubmit_meta ? ( 'rejected' === $status ) : (bool) $can_resubmit_meta;

        return array(
            'id'               => (int) $post->ID,
            'seller_id'        => (int) $post->post_author,
            'seller_name'      => $user ? $user->display_name : '',
            'seller_email'     => $user ? $user->user_email : '',
            'store_name'       => (string) get_post_meta( $post->ID, '_tnm_store_name', true ),
            'about'            => (string) $post->post_content,
            'products'         => (string) get_post_meta( $post->ID, '_tnm_products', true ),
            'website'          => (string) get_post_meta( $post->ID, '_tnm_website', true ),
            'categories'       => (string) get_post_meta( $post->ID, '_tnm_categories', true ),
            'submitted_at'     => (string) get_post_time( DATE_ATOM, true, $post ),
            'status'           => $status,
            'rejection_reason' => (string) get_post_meta( $post->ID, '_tnm_rejection_reason', true ),
            'reviewed_at'      => $reviewed_at ? mysql_to_rfc3339( $reviewed_at ) : '',
            'reviewed_by'      => $reviewer ? $reviewer->display_name : '',
            'can_resubmit'     => $can_resubmit,
        );
    }

    /* -------------------------------------------------------------- refunds */

    /**
     * List orders whose refund lifecycle is in one of the supplied states.
     * Uses the compact `_mnu_refund_lifecycle` JSON meta as the source of
     * truth (populated by MNU_Refund_Lifecycle::set).
     */
    public static function list_refunds( WP_REST_Request $request ): WP_REST_Response {
        $status   = sanitize_key( (string) $request->get_param( 'status' ) ) ?: 'requested';
        $page     = max( 1, (int) ( $request->get_param( 'page' ) ?: 1 ) );
        $per_page = max( 1, min( 50, (int) ( $request->get_param( 'per_page' ) ?: 20 ) ) );

        // Map friendly filter → concrete lifecycle states.
        $map = array(
            'requested'  => array( 'requested' ),
            'approved'   => array( 'approved' ),
            'processing' => array( 'processing' ),
            'completed'  => array( 'completed' ),
            'denied'     => array( 'denied' ),
            'open'       => array( 'requested', 'approved' ),
            'all'        => array( 'requested', 'approved', 'processing', 'completed', 'denied' ),
        );
        if ( ! isset( $map[ $status ] ) ) {
            $status = 'requested';
        }
        $rows = self::refund_rows( $map[ $status ] );

        $total       = count( $rows );
        $total_pages = (int) max( 1, ceil( $total / $per_page ) );
        $offset      = ( $page - 1 ) * $per_page;
        $page_rows   = array_slice( $rows, $offset, $per_page );

        return rest_ensure_response( array(
            'items'       => $page_rows,
            'page'        => $page,
            'total'       => $total,
            'total_pages' => $total_pages,
            'status'      => $status,
        ) );
    }

    /**
     * @return array<int,array> Ordered oldest→newest by requested_at.
     */
    private static function refund_rows( array $states ): array {
        global $wpdb;
        if ( ! post_type_exists( 'shop_order' ) ) {
            return array();
        }
        // Fetch every order carrying the lifecycle meta and filter in PHP.
        // The meta is a compact JSON blob and there aren't enough refund
        // requests to warrant a bespoke table.
        $order_ids = $wpdb->get_col(
            "SELECT pm.post_id FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key='_mnu_refund_lifecycle' AND p.post_type='shop_order'"
        );
        $rows = array();
        foreach ( $order_ids as $order_id ) {
            $order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $order_id ) : null;
            if ( ! $order ) {
                continue;
            }
            $lifecycle = MNU_Refund_Lifecycle::get( $order );
            if ( ! in_array( (string) $lifecycle['state'], $states, true ) ) {
                continue;
            }
            $requested_at = '';
            foreach ( (array) $lifecycle['timeline'] as $entry ) {
                if ( 'requested' === (string) ( $entry['state'] ?? '' ) ) {
                    $requested_at = (string) ( $entry['at'] ?? '' );
                    break;
                }
            }
            $rows[] = array(
                'order_id'         => (int) $order->get_id(),
                'order_number'     => (string) $order->get_order_number(),
                'buyer_name'       => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) ?: $order->get_billing_email(),
                'buyer_email'      => (string) $order->get_billing_email(),
                'order_total'      => (float) $order->get_total(),
                'currency'         => (string) $order->get_currency(),
                'state'            => (string) $lifecycle['state'],
                'requested_amount' => (float) $lifecycle['requested_amount'],
                'refunded_amount'  => (float) $lifecycle['refunded_amount'],
                'reason'           => (string) $lifecycle['reason'],
                'details'          => (string) $lifecycle['details'],
                'requested_at'     => $requested_at ? mysql_to_rfc3339( $requested_at ) : '',
            );
        }
        usort( $rows, static function ( $a, $b ) {
            return strcmp( (string) $a['requested_at'], (string) $b['requested_at'] );
        } );
        return $rows;
    }

    /**
     * Idempotent alias for the existing approve endpoint. Fires the Stripe
     * refund via wc_create_refund. No-op when the lifecycle is already at
     * approved/processing/completed.
     */
    public static function process_refund( WP_REST_Request $request ) {
        $order = wc_get_order( absint( $request['id'] ) );
        if ( ! $order ) {
            return tnm_json_error( 'order_not_found', 'Order not found.', 404 );
        }
        $lifecycle = MNU_Refund_Lifecycle::get( $order );
        $state     = (string) $lifecycle['state'];
        if ( in_array( $state, array( 'approved', 'processing', 'completed' ), true ) ) {
            $eligibility = MNU_Refund_Lifecycle::eligibility( $order );
            return rest_ensure_response( MNU_Refund_Lifecycle::view_payload( $order, $lifecycle, $eligibility ) );
        }
        return MNU_Refund_Lifecycle::rest_admin_approve( $request );
    }

    /* -------------------------------------------------------------- payouts */

    public static function list_payouts( WP_REST_Request $request ): WP_REST_Response {
        global $wpdb;
        $status   = sanitize_key( (string) $request->get_param( 'status' ) ) ?: 'pending';
        $page     = max( 1, (int) ( $request->get_param( 'page' ) ?: 1 ) );
        $per_page = max( 1, min( 50, (int) ( $request->get_param( 'per_page' ) ?: 20 ) ) );

        $map = array(
            'pending'    => array( 'requested', 'processing' ),
            'processing' => array( 'processing' ),
            'requested'  => array( 'requested' ),
            'failed'     => array( 'failed', 'returned' ),
            'returned'   => array( 'returned' ),
            'paid'       => array( 'paid' ),
            'cancelled'  => array( 'cancelled' ),
            'all'        => array( 'requested', 'processing', 'paid', 'cancelled', 'failed', 'returned' ),
        );
        if ( ! isset( $map[ $status ] ) ) {
            $status = 'pending';
        }
        $states     = $map[ $status ];
        $table      = tnm_table( 'payouts' );
        $placeholders = implode( ',', array_fill( 0, count( $states ), '%s' ) );

        $total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE status IN ({$placeholders})",
            $states
        ) );

        $offset = ( $page - 1 ) * $per_page;
        $sql = "SELECT * FROM {$table} WHERE status IN ({$placeholders}) ORDER BY requested_at ASC, id ASC LIMIT %d OFFSET %d";
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $states, array( $per_page, $offset ) ) ), ARRAY_A );

        $items = array();
        foreach ( $rows ?: array() as $row ) {
            $items[] = self::payout_row( $row );
        }

        return rest_ensure_response( array(
            'items'       => $items,
            'page'        => $page,
            'total'       => $total,
            'total_pages' => (int) max( 1, ceil( $total / $per_page ) ),
            'status'      => $status,
        ) );
    }

    private static function payout_row( array $row ): array {
        $seller_id = (int) $row['seller_id'];
        $user      = get_userdata( $seller_id );
        return array(
            'id'           => (int) $row['id'],
            'seller_id'    => $seller_id,
            'seller_name'  => $user ? $user->display_name : '',
            'seller_email' => $user ? $user->user_email : '',
            'amount'       => (float) $row['amount'],
            'currency'     => (string) $row['currency'],
            'method'       => (string) $row['method'],
            'destination'  => (string) $row['destination'],
            'external_id'  => (string) $row['external_id'],
            'status'       => (string) $row['status'],
            'notes'        => (string) $row['notes'],
            'requested_at' => $row['requested_at'] ? mysql_to_rfc3339( (string) $row['requested_at'] ) : '',
            'processed_at' => $row['processed_at'] ? mysql_to_rfc3339( (string) $row['processed_at'] ) : '',
        );
    }

    public static function process_payout( WP_REST_Request $request ) {
        $id = absint( $request['id'] );
        $payout = TNM_Payouts::get( $id );
        if ( ! $payout ) {
            return tnm_json_error( 'payout_not_found', 'Payout not found.', 404 );
        }
        // Idempotent — already-paid / already-cancelled return current state.
        if ( in_array( $payout['status'], array( 'paid', 'cancelled' ), true ) ) {
            return rest_ensure_response( self::payout_row( $payout ) );
        }
        if ( 'paypal' === $payout['method'] ) {
            $result = TNM_Payouts::process_paypal( $id );
        } else {
            // Manual ACH: admin has sent the wire from Bluevine — mark paid.
            $result = TNM_Payouts::mark_paid( $id, (string) $request->get_param( 'external_id' ), (string) $request->get_param( 'notes' ) );
        }
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        $payout = TNM_Payouts::get( $id );
        return $payout ? rest_ensure_response( self::payout_row( $payout ) ) : tnm_json_error( 'payout_not_found', 'Payout not found.', 404 );
    }

    public static function retry_payout( WP_REST_Request $request ) {
        $id = absint( $request['id'] );
        $payout = TNM_Payouts::get( $id );
        if ( ! $payout ) {
            return tnm_json_error( 'payout_not_found', 'Payout not found.', 404 );
        }
        // Retry only makes sense for stuck / errored payouts.
        if ( ! in_array( $payout['status'], array( 'requested', 'processing', 'failed', 'returned' ), true ) ) {
            return tnm_json_error( 'invalid_payout_status', 'This payout cannot be retried in its current state.', 409, array( 'status' => $payout['status'] ) );
        }
        if ( 'paypal' === $payout['method'] ) {
            $result = TNM_Payouts::process_paypal( $id );
        } else {
            $result = TNM_Payouts::mark_paid( $id );
        }
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        $payout = TNM_Payouts::get( $id );
        return $payout ? rest_ensure_response( self::payout_row( $payout ) ) : tnm_json_error( 'payout_not_found', 'Payout not found.', 404 );
    }

    public static function cancel_payout( WP_REST_Request $request ) {
        $id    = absint( $request['id'] );
        $notes = sanitize_textarea_field( (string) $request->get_param( 'notes' ) );
        $result = TNM_Payouts::cancel( $id, $notes ?: 'Cancelled by administrator via mobile drawer.' );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        $payout = TNM_Payouts::get( $id );
        return $payout ? rest_ensure_response( self::payout_row( $payout ) ) : tnm_json_error( 'payout_not_found', 'Payout not found.', 404 );
    }

    /* -------------------------------------------------------------- helpers */

    private static function hours_since( string $mysql_gmt ): int {
        if ( ! $mysql_gmt ) {
            return 0;
        }
        $ts = strtotime( $mysql_gmt . ' UTC' );
        if ( ! $ts ) {
            return 0;
        }
        return max( 0, (int) floor( ( time() - $ts ) / 3600 ) );
    }
}
