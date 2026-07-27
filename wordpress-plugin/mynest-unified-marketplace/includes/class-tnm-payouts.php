<?php

defined( 'ABSPATH' ) || exit;

final class TNM_Payouts {
    public static function init(): void {
        add_action( 'admin_post_tnm_payout_action', array( __CLASS__, 'admin_action' ) );
    }

    public static function request( int $seller_id, float $amount = 0, string $method = '', string $destination = '', bool $automatic = false ): int|WP_Error {
        global $wpdb;
        if ( ! $automatic && ( ! tnm_current_user_can_manage_seller( $seller_id ) || ( ! tnm_is_seller( $seller_id ) && ! tnm_is_admin_or_manager() ) ) ) {
            return tnm_json_error( 'payout_permission_denied', 'You cannot request this payout.', 403 );
        }
        $balances = TNM_Ledger::balances( $seller_id );
        $minimum  = max( 0, (float) tnm_get_option( 'minimum_payout', 25 ) );
        if ( $balances['available'] < $minimum ) {
            return tnm_json_error( 'minimum_payout_not_met', 'Available balance has not reached the minimum payout amount.', 409, array( 'minimum' => $minimum, 'available' => $balances['available'] ) );
        }
        if ( $amount > 0 && $amount < $minimum ) {
            return tnm_json_error( 'payout_amount_too_small', 'Requested payout must meet the minimum payout amount.', 422, array( 'minimum' => $minimum ) );
        }
        if ( $amount > 0 && $amount < (float) $balances['available'] - 0.01 ) {
            return tnm_json_error(
                'partial_payout_not_supported',
                'For accurate ledger reconciliation, payout requests must use the full available balance.',
                422,
                array( 'available' => $balances['available'] )
            );
        }
        if ( $amount <= 0 || $amount > $balances['available'] ) {
            $amount = (float) $balances['available'];
        }
        $method = sanitize_key( $method ?: (string) tnm_get_option( 'payout_method', 'manual' ) );
        if ( ! in_array( $method, array( 'manual', 'paypal' ), true ) ) {
            $method = 'manual';
        }
        if ( ! $destination ) {
            $destination = 'paypal' === $method ? (string) get_user_meta( $seller_id, 'tnm_paypal_email', true ) : (string) get_user_meta( $seller_id, 'tnm_payout_destination', true );
        }
        if ( 'paypal' === $method && ! is_email( $destination ) ) {
            $user        = get_userdata( $seller_id );
            $destination = $user ? $user->user_email : '';
        }

        $wpdb->insert(
            tnm_table( 'payouts' ),
            array(
                'seller_id'   => $seller_id,
                'amount'      => 0,
                'currency'    => $balances['currency'],
                'method'      => $method,
                'destination' => sanitize_text_field( $destination ),
                'external_id' => '',
                'status'      => 'requested',
                'notes'       => $automatic ? 'Automatically generated payout.' : 'Seller-requested payout.',
                'requested_at'=> current_time( 'mysql', true ),
                'processed_at'=> null,
            ),
            array( '%d', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
        );
        $payout_id = (int) $wpdb->insert_id;
        if ( ! $payout_id ) {
            return tnm_json_error( 'payout_create_failed', 'Could not create payout request.', 500 );
        }

        $reserved = TNM_Ledger::reserve_available( $seller_id, $payout_id, $amount );
        if ( is_wp_error( $reserved ) ) {
            $wpdb->delete( tnm_table( 'payouts' ), array( 'id' => $payout_id ), array( '%d' ) );
            return $reserved;
        }
        $wpdb->update( tnm_table( 'payouts' ), array( 'amount' => $reserved ), array( 'id' => $payout_id ), array( '%f' ), array( '%d' ) );

        tnm_notify( $seller_id, 0, 'payout_requested', 'Payout requested', 'A payout of ' . tnm_money( $reserved, $balances['currency'] ) . ' was created.', $payout_id, 'payout', tnm_page_url( 'seller_dashboard' ) );
        do_action( 'tnm_payout_requested', $payout_id, $seller_id, $reserved, $method );

        if ( $automatic && 'paypal' === $method ) {
            self::process_paypal( $payout_id );
        }
        return $payout_id;
    }

    public static function get( int $payout_id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . tnm_table( 'payouts' ) . ' WHERE id=%d', $payout_id ), ARRAY_A );
        if ( ! $row ) {
            return null;
        }
        $row['id']        = (int) $row['id'];
        $row['seller_id'] = (int) $row['seller_id'];
        $row['amount']    = (float) $row['amount'];
        return $row;
    }

    public static function list_for_seller( int $seller_id, int $limit = 50 ): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . tnm_table( 'payouts' ) . ' WHERE seller_id=%d ORDER BY requested_at DESC,id DESC LIMIT %d',
                $seller_id,
                max( 1, min( 100, $limit ) )
            ),
            ARRAY_A
        );
        return array_map(
            static function ( array $row ): array {
                $row['id']        = (int) $row['id'];
                $row['seller_id'] = (int) $row['seller_id'];
                $row['amount']    = (float) $row['amount'];
                return $row;
            },
            $rows
        );
    }

    public static function mark_paid( int $payout_id, string $external_id = '', string $notes = '' ): bool|WP_Error {
        global $wpdb;
        $payout = self::get( $payout_id );
        if ( ! $payout ) {
            return tnm_json_error( 'payout_not_found', 'Payout not found.', 404 );
        }
        if ( 'paid' === $payout['status'] ) {
            return true;
        }
        if ( ! in_array( $payout['status'], array( 'requested', 'processing' ), true ) ) {
            return tnm_json_error( 'invalid_payout_status', 'This payout cannot be marked paid.', 409 );
        }
        $wpdb->update(
            tnm_table( 'payouts' ),
            array(
                'status'       => 'paid',
                'external_id'  => sanitize_text_field( $external_id ?: $payout['external_id'] ),
                'notes'        => trim( $payout['notes'] . ' ' . sanitize_textarea_field( $notes ) ),
                'processed_at' => current_time( 'mysql', true ),
            ),
            array( 'id' => $payout_id ),
            array( '%s', '%s', '%s', '%s' ),
            array( '%d' )
        );
        TNM_Ledger::mark_payout_paid( $payout_id );
        tnm_notify( (int) $payout['seller_id'], 0, 'payout_paid', 'Payout sent', 'Your payout of ' . tnm_money( $payout['amount'], $payout['currency'] ) . ' was marked paid.', $payout_id, 'payout', tnm_page_url( 'seller_dashboard' ) );
        do_action( 'tnm_payout_paid', $payout_id, $payout );
        return true;
    }

    public static function cancel( int $payout_id, string $notes = '' ): bool|WP_Error {
        global $wpdb;
        $payout = self::get( $payout_id );
        if ( ! $payout ) {
            return tnm_json_error( 'payout_not_found', 'Payout not found.', 404 );
        }
        if ( in_array( $payout['status'], array( 'paid', 'cancelled' ), true ) ) {
            return tnm_json_error( 'invalid_payout_status', 'This payout cannot be cancelled.', 409 );
        }
        $wpdb->update(
            tnm_table( 'payouts' ),
            array(
                'status'       => 'cancelled',
                'notes'        => trim( $payout['notes'] . ' ' . sanitize_textarea_field( $notes ) ),
                'processed_at' => current_time( 'mysql', true ),
            ),
            array( 'id' => $payout_id ),
            array( '%s', '%s', '%s' ),
            array( '%d' )
        );
        TNM_Ledger::release_payout_reservation( $payout_id );
        tnm_notify( (int) $payout['seller_id'], 0, 'payout_cancelled', 'Payout cancelled', 'The payout reservation was returned to your available balance.', $payout_id, 'payout', tnm_page_url( 'seller_dashboard' ) );
        return true;
    }

    public static function process_paypal( int $payout_id ): bool|WP_Error {
        global $wpdb;
        $payout = self::get( $payout_id );
        if ( ! $payout ) {
            return tnm_json_error( 'payout_not_found', 'Payout not found.', 404 );
        }
        if ( 'paypal' !== $payout['method'] ) {
            return tnm_json_error( 'invalid_payout_method', 'This payout is not configured for PayPal.', 409 );
        }
        if ( ! is_email( $payout['destination'] ) ) {
            return tnm_json_error( 'invalid_payout_destination', 'A valid PayPal email is required.', 422 );
        }
        $token = self::paypal_access_token();
        if ( is_wp_error( $token ) ) {
            return $token;
        }
        $base = 'live' === tnm_get_option( 'paypal_environment', 'sandbox' ) ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
        $body = array(
            'sender_batch_header' => array(
                'sender_batch_id' => 'tnm-' . $payout_id . '-' . wp_generate_uuid4(),
                'email_subject'   => sprintf( 'You received a payout from %s', get_bloginfo( 'name' ) ),
                'email_message'   => sprintf( 'Your seller earnings payout from %s has been sent.', get_bloginfo( 'name' ) ),
            ),
            'items' => array(
                array(
                    'recipient_type' => 'EMAIL',
                    'amount'         => array(
                        'value'    => number_format( (float) $payout['amount'], 2, '.', '' ),
                        'currency' => $payout['currency'],
                    ),
                    'receiver'        => $payout['destination'],
                    'note'            => get_bloginfo( 'name' ) . ' seller payout #' . $payout_id,
                    'sender_item_id'  => (string) $payout_id,
                ),
            ),
        );
        $response = wp_remote_post(
            $base . '/v1/payments/payouts',
            array(
                'timeout' => 30,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                ),
                'body' => wp_json_encode( $body ),
            )
        );
        if ( is_wp_error( $response ) ) {
            return tnm_json_error( 'paypal_request_failed', $response->get_error_message(), 502 );
        }
        $code = wp_remote_retrieve_response_code( $response );
        $json = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( $code < 200 || $code >= 300 || empty( $json['batch_header']['payout_batch_id'] ) ) {
            $message = sanitize_text_field( (string) ( $json['message'] ?? 'PayPal rejected the payout request.' ) );
            return tnm_json_error( 'paypal_payout_failed', $message, 502, array( 'paypal_response' => $json ) );
        }
        $external_id = sanitize_text_field( $json['batch_header']['payout_batch_id'] );
        $status      = strtoupper( sanitize_text_field( (string) ( $json['batch_header']['batch_status'] ?? 'PENDING' ) ) );
        $wpdb->update(
            tnm_table( 'payouts' ),
            array( 'status' => 'processing', 'external_id' => $external_id, 'notes' => trim( $payout['notes'] . ' PayPal batch status: ' . $status ) ),
            array( 'id' => $payout_id ),
            array( '%s', '%s', '%s' ),
            array( '%d' )
        );
        if ( 'SUCCESS' === $status ) {
            self::mark_paid( $payout_id, $external_id, 'PayPal batch completed.' );
        }
        return true;
    }

    private static function paypal_access_token(): string|WP_Error {
        $client_id = trim( (string) tnm_get_option( 'paypal_client_id', '' ) );
        $secret    = trim( (string) tnm_get_option( 'paypal_client_secret', '' ) );
        if ( ! $client_id || ! $secret ) {
            return tnm_json_error( 'paypal_not_configured', 'PayPal client ID and secret are not configured.', 409 );
        }
        $environment = (string) tnm_get_option( 'paypal_environment', 'sandbox' );
        $cache_key   = 'tnm_paypal_token_' . md5( $environment . $client_id );
        $cached      = get_transient( $cache_key );
        if ( $cached ) {
            return (string) $cached;
        }
        $base = 'live' === $environment ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
        $response = wp_remote_post(
            $base . '/v1/oauth2/token',
            array(
                'timeout' => 20,
                'headers' => array(
                    'Authorization' => 'Basic ' . base64_encode( $client_id . ':' . $secret ),
                    'Accept'        => 'application/json',
                    'Content-Type'  => 'application/x-www-form-urlencoded',
                ),
                'body' => 'grant_type=client_credentials',
            )
        );
        if ( is_wp_error( $response ) ) {
            return tnm_json_error( 'paypal_auth_failed', $response->get_error_message(), 502 );
        }
        $code = wp_remote_retrieve_response_code( $response );
        $json = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( $code < 200 || $code >= 300 || empty( $json['access_token'] ) ) {
            return tnm_json_error( 'paypal_auth_failed', sanitize_text_field( (string) ( $json['error_description'] ?? 'Could not authenticate with PayPal.' ) ), 502 );
        }
        $expires = max( 60, (int) ( $json['expires_in'] ?? 300 ) - 60 );
        set_transient( $cache_key, sanitize_text_field( $json['access_token'] ), $expires );
        return sanitize_text_field( $json['access_token'] );
    }

    public static function sync_paypal_processing(): void {
        global $wpdb;
        $payouts = $wpdb->get_results( "SELECT * FROM " . tnm_table( 'payouts' ) . " WHERE method='paypal' AND status='processing' AND external_id<>'' LIMIT 100", ARRAY_A );
        if ( ! $payouts ) {
            return;
        }
        $token = self::paypal_access_token();
        if ( is_wp_error( $token ) ) {
            return;
        }
        $base = 'live' === tnm_get_option( 'paypal_environment', 'sandbox' ) ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
        foreach ( $payouts as $payout ) {
            $response = wp_remote_get(
                $base . '/v1/payments/payouts/' . rawurlencode( $payout['external_id'] ) . '?fields=batch_header',
                array(
                    'timeout' => 20,
                    'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json' ),
                )
            );
            if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) >= 300 ) {
                continue;
            }
            $json   = json_decode( wp_remote_retrieve_body( $response ), true );
            $status = strtoupper( sanitize_text_field( (string) ( $json['batch_header']['batch_status'] ?? '' ) ) );
            if ( 'SUCCESS' === $status ) {
                self::mark_paid( (int) $payout['id'], $payout['external_id'], 'PayPal batch confirmed successful.' );
            } elseif ( in_array( $status, array( 'DENIED', 'CANCELED' ), true ) ) {
                self::cancel( (int) $payout['id'], 'PayPal batch status: ' . $status );
            }
        }
    }

    public static function maybe_generate_automatic_payouts(): void {
        self::sync_paypal_processing();
        if ( 'yes' !== tnm_get_option( 'automatic_payouts', 'no' ) ) {
            return;
        }
        $schedule = (string) tnm_get_option( 'payout_schedule', 'weekly' );
        $days     = array( 'weekly' => 7, 'biweekly' => 14, 'monthly' => 30 )[ $schedule ] ?? 7;
        $last     = (int) get_option( 'tnm_last_auto_payout_run', 0 );
        if ( $last && time() - $last < $days * DAY_IN_SECONDS ) {
            return;
        }
        update_option( 'tnm_last_auto_payout_run', time(), false );
        $sellers = get_users( array( 'role__in' => array( 'tnm_seller', 'mynest_seller' ), 'fields' => 'ID' ) );
        foreach ( array_unique( array_map( 'intval', $sellers ) ) as $seller_id ) {
            $balances = TNM_Ledger::balances( (int) $seller_id );
            if ( $balances['available'] >= (float) tnm_get_option( 'minimum_payout', 25 ) ) {
                self::request( (int) $seller_id, 0, (string) tnm_get_option( 'payout_method', 'manual' ), '', true );
            }
        }
    }

    public static function admin_action(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'You do not have permission to manage payouts.' );
        }
        $payout_id = absint( $_GET['payout_id'] ?? 0 );
        check_admin_referer( 'tnm_payout_' . $payout_id );
        $action = sanitize_key( $_GET['payout_action'] ?? '' );
        if ( 'paid' === $action ) {
            self::mark_paid( $payout_id, sanitize_text_field( wp_unslash( $_GET['external_id'] ?? '' ) ), 'Marked paid by administrator.' );
        } elseif ( 'cancel' === $action ) {
            self::cancel( $payout_id, 'Cancelled by administrator.' );
        } elseif ( 'process' === $action ) {
            self::process_paypal( $payout_id );
        }
        wp_safe_redirect( admin_url( 'admin.php?page=tnm-payouts' ) );
        exit;
    }
}
