<?php
/**
 * Built-in MyNest buyer protection / disputes.
 *
 * Replaces the mobile app's former dependency on a separate Trust Suite for
 * the operational dispute lifecycle. The source of truth is wp_tnm_disputes.
 *
 * @package MyNest_Unified_Marketplace
 * @since 3.13.37
 */

declare( strict_types = 1 );
defined( 'ABSPATH' ) || exit;

final class MNU_Trust {
    public const NS = 'nest-trust/v1';

    public static function init(): void {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
    }

    public static function table(): string {
        return tnm_table( 'disputes' );
    }

    public static function register_routes(): void {
        register_rest_route( self::NS, '/disputes', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( __CLASS__, 'list_disputes' ),
                'permission_callback' => array( __CLASS__, 'logged_in' ),
            ),
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( __CLASS__, 'create_dispute' ),
                'permission_callback' => array( __CLASS__, 'logged_in' ),
            ),
        ) );
        register_rest_route( self::NS, '/disputes/(?P<id>\d+)', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( __CLASS__, 'get_dispute' ),
                'permission_callback' => array( __CLASS__, 'logged_in' ),
            ),
            array(
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => array( __CLASS__, 'update_dispute' ),
                'permission_callback' => array( __CLASS__, 'logged_in' ),
            ),
        ) );
        register_rest_route( self::NS, '/disputes/(?P<id>\d+)/escalate', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( __CLASS__, 'escalate_dispute' ),
            'permission_callback' => array( __CLASS__, 'logged_in' ),
        ) );
    }

    public static function logged_in( WP_REST_Request $request ): bool|WP_Error {
        return self::user_id( $request ) > 0
            ? true
            : new WP_Error( 'not_logged_in', 'You must be logged in.', array( 'status' => 401 ) );
    }

    private static function user_id( ?WP_REST_Request $request = null ): int {
        if ( class_exists( 'MNU_Ops' ) ) {
            return MNU_Ops::get_bearer_user_id( $request );
        }
        return get_current_user_id();
    }

    private static function is_admin( int $user_id ): bool {
        return $user_id > 0 && ( user_can( $user_id, 'manage_woocommerce' ) || user_can( $user_id, 'manage_options' ) );
    }

    public static function has_active_for_order( int $order_id ): bool {
        global $wpdb;
        $status = (string) $wpdb->get_var( $wpdb->prepare(
            'SELECT status FROM ' . self::table() . ' WHERE order_id=%d LIMIT 1', $order_id
        ) );
        return $status && ! self::is_resolved_status( $status );
    }

    private static function is_resolved_status( string $status ): bool {
        return 0 === strpos( $status, 'resolved_' ) || 'closed' === $status;
    }

    private static function seller_ids_for_order( WC_Order $order ): array {
        $ids = array();
        foreach ( $order->get_items( 'line_item' ) as $item ) {
            if ( ! $item instanceof WC_Order_Item_Product ) { continue; }
            $sid = function_exists( 'tnm_get_order_item_seller_id' ) ? (int) tnm_get_order_item_seller_id( $item ) : 0;
            if ( $sid > 0 ) { $ids[ $sid ] = true; }
        }
        return array_map( 'intval', array_keys( $ids ) );
    }

    private static function seller_participates( WC_Order $order, int $user_id ): bool {
        return in_array( $user_id, self::seller_ids_for_order( $order ), true );
    }

    private static function get_row( int $id ): ?object {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id=%d', $id ) );
        return $row ?: null;
    }

    private static function row_for_order( int $order_id ): ?object {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE order_id=%d', $order_id ) );
        return $row ?: null;
    }

    private static function authorized( object $row, int $user_id ): bool {
        if ( self::is_admin( $user_id ) || (int) $row->buyer_id === $user_id ) { return true; }
        $order = wc_get_order( (int) $row->order_id );
        return $order ? self::seller_participates( $order, $user_id ) : false;
    }

    public static function create_dispute( WP_REST_Request $request ) {
        global $wpdb;
        $user_id  = self::user_id( $request );
        $order_id = absint( $request->get_param( 'order_id' ) );
        $order    = wc_get_order( $order_id );
        if ( ! $order ) { return tnm_json_error( 'order_not_found', 'Order not found.', 404 ); }
        if ( (int) $order->get_customer_id() !== $user_id ) {
            return tnm_json_error( 'buyer_permission_denied', 'Only the buyer can open buyer protection for this order.', 403 );
        }
        if ( in_array( $order->get_status(), array( 'cancelled', 'failed' ), true ) ) {
            return tnm_json_error( 'order_not_eligible', 'Buyer protection is not available for this order status.', 409 );
        }
        $existing = self::row_for_order( $order_id );
        if ( $existing ) {
            return tnm_json_error( 'dispute_already_exists', 'A buyer-protection case already exists for this order.', 409, array( 'dispute_id' => (int) $existing->id ) );
        }

        if ( class_exists( 'MNU_Refund_Lifecycle' ) ) {
            $refund = MNU_Refund_Lifecycle::get( $order );
            if ( in_array( (string) $refund['state'], array( 'requested', 'approved', 'processing', 'completed' ), true ) ) {
                return tnm_json_error( 'refund_resolution_active', 'This order already has an active or completed refund resolution.', 409 );
            }
            if ( 'none' === (string) $refund['state'] ) {
                $eligibility = MNU_Refund_Lifecycle::eligibility( $order );
                if ( ! empty( $eligibility['eligible'] ) ) {
                    return tnm_json_error( 'refund_request_required', 'Start with a refund request on the order. Buyer protection is the escalation path if that cannot resolve the issue.', 409 );
                }
            }
        }

        $reason = sanitize_key( (string) $request->get_param( 'reason' ) );
        $allowed_reasons = array( 'not_arrived', 'not_as_described', 'damaged', 'wrong_item', 'other' );
        if ( ! in_array( $reason, $allowed_reasons, true ) ) { $reason = 'other'; }
        $description = sanitize_textarea_field( (string) $request->get_param( 'description' ) );
        if ( mb_strlen( trim( $description ) ) < 10 ) {
            return tnm_json_error( 'description_too_short', 'Please describe the issue in at least 10 characters.', 422 );
        }
        $evidence = array();
        foreach ( array_slice( (array) $request->get_param( 'evidence' ), 0, 5 ) as $url ) {
            $url = esc_url_raw( (string) $url );
            if ( $url && wp_http_validate_url( $url ) ) { $evidence[] = $url; }
        }
        $contacted = self::parse_iso_to_mysql( (string) $request->get_param( 'contacted_seller_at' ) );
        $seller_ids = self::seller_ids_for_order( $order );
        $seller_id  = 1 === count( $seller_ids ) ? (int) $seller_ids[0] : 0;
        $now = current_time( 'mysql', true );

        $insert_data = array(
            'order_id'        => $order_id,
            'buyer_id'        => $user_id,
            'seller_id'       => $seller_id,
            'status'          => 'awaiting_seller',
            'reason'          => $reason,
            'description'     => $description,
            'resolution_note' => '',
            'evidence'        => wp_json_encode( array_values( array_unique( $evidence ) ) ),
            'created_at'      => $now,
            'updated_at'      => $now,
        );
        $insert_formats = array( '%d','%d','%d','%s','%s','%s','%s','%s','%s' );
        if ( $contacted ) {
            $insert_data['contacted_seller_at'] = $contacted;
            $insert_formats[] = '%s';
        }
        $inserted = $wpdb->insert( self::table(), $insert_data, $insert_formats );
        if ( false === $inserted ) {
            $dupe = self::row_for_order( $order_id );
            if ( $dupe ) {
                return tnm_json_error( 'dispute_already_exists', 'A buyer-protection case already exists for this order.', 409, array( 'dispute_id' => (int) $dupe->id ) );
            }
            return tnm_json_error( 'dispute_create_failed', 'Could not open buyer protection. Please try again.', 500 );
        }
        $id = (int) $wpdb->insert_id;
        self::apply_hold( $order, $id );
        foreach ( $seller_ids as $sid ) {
            tnm_notify( $sid, $user_id, 'buyer_dispute_opened', 'Buyer protection opened', 'A buyer-protection case was opened for order #' . $order->get_order_number() . '.', $id, 'dispute', '' );
        }
        $order->add_order_note( sprintf( 'Buyer-protection case #%d opened. Seller earnings for this order are held where still available.', $id ) );
        $row = self::get_row( $id );
        return rest_ensure_response( array(
            'dispute' => self::hydrate( $row, $user_id ),
            'warning' => $contacted ? null : 'Give the seller a chance to respond before escalating when possible.',
        ) );
    }

    public static function list_disputes( WP_REST_Request $request ): WP_REST_Response {
        global $wpdb;
        $user_id = self::user_id( $request );
        $status  = sanitize_key( (string) $request->get_param( 'status' ) );
        $rows    = $wpdb->get_results( 'SELECT * FROM ' . self::table() . ' ORDER BY updated_at DESC,id DESC LIMIT 500' );
        $out = array();
        foreach ( $rows ?: array() as $row ) {
            if ( $status && $status !== (string) $row->status ) { continue; }
            if ( self::authorized( $row, $user_id ) ) { $out[] = self::hydrate( $row, $user_id ); }
        }
        return rest_ensure_response( array( 'disputes' => $out ) );
    }

    public static function get_dispute( WP_REST_Request $request ) {
        $user_id = self::user_id( $request );
        $row = self::get_row( absint( $request['id'] ) );
        if ( ! $row || ! self::authorized( $row, $user_id ) ) {
            return tnm_json_error( 'dispute_not_found', 'Buyer-protection case not found.', 404 );
        }
        return rest_ensure_response( self::hydrate( $row, $user_id ) );
    }

    public static function update_dispute( WP_REST_Request $request ) {
        global $wpdb;
        $user_id = self::user_id( $request );
        $row = self::get_row( absint( $request['id'] ) );
        if ( ! $row || ! self::authorized( $row, $user_id ) ) {
            return tnm_json_error( 'dispute_not_found', 'Buyer-protection case not found.', 404 );
        }
        if ( self::is_resolved_status( (string) $row->status ) ) {
            return rest_ensure_response( self::hydrate( $row, $user_id ) );
        }
        $order = wc_get_order( (int) $row->order_id );
        if ( ! $order ) { return tnm_json_error( 'order_not_found', 'Order not found.', 404 ); }
        $note = sanitize_textarea_field( (string) $request->get_param( 'resolution_note' ) );

        if ( self::is_admin( $user_id ) ) {
            $status = sanitize_key( (string) $request->get_param( 'status' ) );
            $allowed = array( 'awaiting_seller','awaiting_buyer','escalated','resolved_refund','resolved_partial','resolved_no_refund','closed' );
            if ( ! $status ) { $status = (string) $row->status; }
            if ( ! in_array( $status, $allowed, true ) ) {
                return tnm_json_error( 'invalid_dispute_status', 'That buyer-protection status is not allowed.', 422 );
            }
            $refund_amount = null;
            if ( in_array( $status, array( 'resolved_refund', 'resolved_partial' ), true ) ) {
                $requested = (float) $request->get_param( 'refund_amount' );
                $refund_amount = 'resolved_refund' === $status ? max( 0.0, (float) $order->get_total() - (float) $order->get_total_refunded() ) : $requested;
                $result = self::apply_refund( $order, $refund_amount, $note );
                if ( is_wp_error( $result ) ) { return $result; }
            }
            $update = array(
                'status'          => $status,
                'resolution_note' => $note ?: (string) $row->resolution_note,
                'updated_at'      => current_time( 'mysql', true ),
            );
            $formats = array( '%s','%s','%s' );
            if ( null !== $refund_amount ) { $update['refund_amount'] = $refund_amount; $formats[] = '%f'; }
            if ( self::is_resolved_status( $status ) ) { $update['resolved_at'] = current_time( 'mysql', true ); $formats[] = '%s'; }
            $wpdb->update( self::table(), $update, array( 'id' => (int) $row->id ), $formats, array( '%d' ) );
            if ( self::is_resolved_status( $status ) ) {
                self::clear_hold( $order );
                $resolution_label = str_replace( '_', ' ', $status );
                tnm_notify( (int) $row->buyer_id, $user_id, 'dispute_update', 'Buyer protection resolved', 'Case #' . $row->id . ' was resolved: ' . $resolution_label . '.', (int) $row->id, 'dispute', '' );
                foreach ( self::seller_ids_for_order( $order ) as $sid ) {
                    tnm_notify( (int) $sid, $user_id, 'dispute_update', 'Buyer protection resolved', 'Case #' . $row->id . ' was resolved: ' . $resolution_label . '.', (int) $row->id, 'dispute', '' );
                }
                $order->add_order_note( 'Buyer-protection case #' . $row->id . ' resolved: ' . $resolution_label . ( $note ? ' — ' . $note : '' ) );
            }
        } else {
            if ( ! self::seller_participates( $order, $user_id ) ) {
                return tnm_json_error( 'seller_permission_denied', 'Only a seller on this order can respond.', 403 );
            }
            if ( 'escalated' === (string) $row->status ) {
                return tnm_json_error( 'dispute_escalated', 'My Nest is reviewing this case. Add further information through support.', 409 );
            }
            if ( mb_strlen( trim( $note ) ) < 5 ) {
                return tnm_json_error( 'response_too_short', 'Please write a short response first.', 422 );
            }
            $wpdb->update( self::table(), array(
                'status' => 'awaiting_buyer', 'resolution_note' => $note, 'updated_at' => current_time( 'mysql', true ),
            ), array( 'id' => (int) $row->id ), array( '%s','%s','%s' ), array( '%d' ) );
            tnm_notify( (int) $row->buyer_id, $user_id, 'buyer_dispute_response', 'Seller responded', 'The seller responded to buyer-protection case #' . $row->id . '.', (int) $row->id, 'dispute', '' );
        }
        $fresh = self::get_row( (int) $row->id );
        return rest_ensure_response( self::hydrate( $fresh, $user_id ) );
    }

    public static function escalate_dispute( WP_REST_Request $request ) {
        global $wpdb;
        $user_id = self::user_id( $request );
        $row = self::get_row( absint( $request['id'] ) );
        if ( ! $row || (int) $row->buyer_id !== $user_id ) {
            return tnm_json_error( 'dispute_not_found', 'Buyer-protection case not found.', 404 );
        }
        if ( self::is_resolved_status( (string) $row->status ) || 'escalated' === (string) $row->status ) {
            return rest_ensure_response( self::hydrate( $row, $user_id ) );
        }
        if ( ! self::can_escalate( $row, $user_id ) ) {
            return tnm_json_error( 'escalation_wait', 'Give the seller up to 24 hours to respond before escalating.', 409 );
        }
        $now = current_time( 'mysql', true );
        $wpdb->update( self::table(), array( 'status'=>'escalated','escalated_at'=>$now,'updated_at'=>$now ), array( 'id'=>(int)$row->id ), array('%s','%s','%s'), array('%d') );
        $order = wc_get_order( (int) $row->order_id );
        if ( $order ) { $order->add_order_note( 'Buyer-protection case #' . $row->id . ' escalated to My Nest.' ); }
        $fresh = self::get_row( (int) $row->id );
        return rest_ensure_response( self::hydrate( $fresh, $user_id ) );
    }

    private static function apply_refund( WC_Order $order, float $amount, string $note ) {
        if ( ! class_exists( 'MNU_Refund_Lifecycle' ) ) {
            return tnm_json_error( 'refund_unavailable', 'Refund processing is unavailable.', 503 );
        }
        $remaining = max( 0.0, (float) $order->get_total() - (float) $order->get_total_refunded() );
        $amount = min( $remaining, max( 0.0, $amount ) );
        if ( $amount <= 0 ) { return true; }
        $req = new WP_REST_Request( 'POST' );
        $req->set_param( 'id', $order->get_id() );
        $req->set_param( 'amount', $amount );
        $req->set_param( 'note', $note ?: 'Resolved through MyNest buyer protection.' );
        $result = MNU_Refund_Lifecycle::rest_admin_approve( $req );
        return is_wp_error( $result ) ? $result : true;
    }

    private static function apply_hold( WC_Order $order, int $dispute_id ): void {
        global $wpdb;
        $order->update_meta_data( '_mnu_buyer_dispute_hold', (string) $dispute_id );
        $order->save();

        // If these earnings were already reserved into a not-yet-paid payout,
        // cancel that payout first. TNM_Payouts::cancel releases every row in
        // the payout back to available; we then hold only this order below.
        $payout_ids = $wpdb->get_col( $wpdb->prepare(
            'SELECT DISTINCT payout_id FROM ' . tnm_table( 'ledger' ) . " WHERE order_id=%d AND type='earning' AND status='reserved' AND payout_id>0",
            $order->get_id()
        ) );
        if ( class_exists( 'TNM_Payouts' ) ) {
            foreach ( $payout_ids ?: array() as $payout_id ) {
                $payout = TNM_Payouts::get( (int) $payout_id );
                if ( ! $payout || in_array( (string) $payout['status'], array( 'paid', 'cancelled' ), true ) ) { continue; }
                $provider_submitted = 'paypal' === (string) $payout['method']
                    && 'processing' === (string) $payout['status']
                    && '' !== trim( (string) $payout['external_id'] );
                if ( $provider_submitted ) {
                    $order->add_order_note( 'Buyer protection opened after PayPal payout #' . (int) $payout_id . ' was submitted. The payout remains provider-controlled; any approved refund will reconcile against seller earnings/debt.' );
                    continue;
                }
                TNM_Payouts::cancel( (int) $payout_id, 'Automatically cancelled because buyer protection opened on order #' . $order->get_order_number() . '.' );
            }
        }

        $now = current_time( 'mysql', true );
        $wpdb->query( $wpdb->prepare(
            'UPDATE ' . tnm_table( 'ledger' ) . " SET status='disputed_hold',payout_id=0,updated_at=%s WHERE order_id=%d AND type='earning' AND status='available'",
            $now, $order->get_id()
        ) );
    }

    private static function clear_hold( WC_Order $order ): void {
        global $wpdb;
        $order->delete_meta_data( '_mnu_buyer_dispute_hold' );
        $order->save();
        $now = current_time( 'mysql', true );
        $wpdb->query( $wpdb->prepare(
            'UPDATE ' . tnm_table( 'ledger' ) . " SET status=IF(available_at<=%s,'available','pending'),updated_at=%s WHERE order_id=%d AND type='earning' AND status='disputed_hold'",
            $now, $now, $order->get_id()
        ) );
        if ( class_exists( 'TNM_Ledger' ) && $order->has_status( 'completed' ) ) {
            TNM_Ledger::release_order_if_ready( $order );
        }
    }

    private static function can_escalate( object $row, int $viewer_id ): bool {
        if ( (int) $row->buyer_id !== $viewer_id || self::is_resolved_status( (string) $row->status ) || 'escalated' === (string) $row->status ) { return false; }
        if ( 'awaiting_buyer' === (string) $row->status ) { return true; }
        $base = (string) ( $row->contacted_seller_at ?: $row->created_at );
        $ts = $base ? strtotime( $base . ' UTC' ) : 0;
        return $ts > 0 && ( time() - $ts ) >= DAY_IN_SECONDS;
    }

    private static function hydrate( ?object $row, int $viewer_id ): array {
        if ( ! $row ) { return array(); }
        $evidence = json_decode( (string) $row->evidence, true );
        if ( ! is_array( $evidence ) ) { $evidence = array(); }
        return array(
            'id'                  => (int) $row->id,
            'order_id'            => (int) $row->order_id,
            'status'              => (string) $row->status,
            'reason'              => (string) $row->reason,
            'description'         => (string) $row->description,
            'resolution_note'     => (string) $row->resolution_note ?: null,
            'refund_amount'       => null === $row->refund_amount ? null : (float) $row->refund_amount,
            'evidence'            => array_values( array_map( 'strval', $evidence ) ),
            'buyer_id'            => (int) $row->buyer_id,
            'seller_id'           => (int) $row->seller_id,
            'contacted_seller_at' => self::rfc3339( (string) $row->contacted_seller_at ),
            'created_at'          => self::rfc3339( (string) $row->created_at ),
            'updated_at'          => self::rfc3339( (string) $row->updated_at ),
            'can_escalate'        => self::can_escalate( $row, $viewer_id ),
        );
    }

    private static function parse_iso_to_mysql( string $value ): string {
        if ( '' === trim( $value ) ) { return ''; }
        try {
            $dt = new DateTimeImmutable( $value );
            return $dt->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
        } catch ( Throwable $e ) {
            return '';
        }
    }

    private static function rfc3339( string $mysql ): ?string {
        return $mysql ? mysql_to_rfc3339( $mysql ) : null;
    }
}

// Compatibility shim for older admin/dashboard helpers that only know the
// historical Trust Suite database class.
if ( ! class_exists( 'TNM_Trust_DB' ) ) {
    final class TNM_Trust_DB {
        public static function table( string $name ): string {
            return 'disputes' === $name ? MNU_Trust::table() : tnm_table( 'trust_' . sanitize_key( $name ) );
        }
    }
}
