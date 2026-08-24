<?php
/**
 * v3.7.87 — Automatic shipping-label purchase after buyer payment.
 *
 * When a native-checkout PaymentIntent transitions to `succeeded`, this
 * service iterates the per-seller Shippo rate snapshots that the checkout
 * flow persisted onto the order (via `_mnu_ship_rate_id_{seller}`, etc.)
 * and immediately calls `POST /transactions/` for each one — buying the
 * exact rate the buyer paid for at the exact package we quoted against.
 *
 * The purchase runs against the seller's own Shippo token when they have
 * one connected (v3.7.82 Shippo Connect), or against the platform token
 * otherwise. The platform-paid case is netted off the seller's next
 * Stripe Connect transfer through the existing postage-recovery ledger
 * (mnu_labels_write_postage_ledger_row).
 *
 * On success: `_thenest_label_url_{seller}` + tracking meta get written,
 * `_tnm_seller_status_{seller}` flips to 'shipped', buyer + seller are
 * notified. On failure: the error is recorded in
 * `_mnu_autolabel_error_{seller}` and the seller dashboard falls back to
 * the manual rate-picker (v3.7.87 handles this via the seller-side
 * template check).
 *
 * Cancellations/refunds route through `MNU_Auto_Label::void_labels()`,
 * which calls Shippo `/refunds/` to void any label that hasn't been
 * scanned into the mailstream yet.
 */

defined( 'ABSPATH' ) || exit;

final class MNU_Auto_Label {

    /**
     * Attach hooks. Called from the plugin loader.
     */
    public static function init(): void {
        // Payment-side entry points. Both fire on success — the webhook is the
        // reliable path, and payment_complete covers the sync completion path
        // taken by mnu_native_complete(). The guard inside purchase_for_order()
        // makes duplicate calls no-ops.
        add_action( 'woocommerce_payment_complete', array( __CLASS__, 'on_payment_complete' ), 25, 1 );
        add_action( 'mnu_native_payment_succeeded', array( __CLASS__, 'on_payment_complete' ), 25, 1 );

        // Refund / cancel side.
        add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'on_order_cancelled' ), 20, 1 );
        add_action( 'woocommerce_order_status_refunded',  array( __CLASS__, 'on_order_cancelled' ), 20, 1 );
    }

    /**
     * WooCommerce fires this on payment_complete(). We also dispatch our own
     * `mnu_native_payment_succeeded` action from the webhook so we cover both
     * the sync-complete and async-webhook paths.
     */
    public static function on_payment_complete( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order instanceof WC_Order ) {
            return;
        }
        self::purchase_for_order( $order );
    }

    /**
     * Iterate every seller shipment on the order and buy their label using
     * the rate/parcel snapshot persisted at checkout. Idempotent per seller.
     */
    public static function purchase_for_order( WC_Order $order ): void {
        // Cheap guard so the same hook firing twice does no double-buys.
        if ( $order->get_meta( '_mnu_autolabel_run_at', true ) ) {
            // Still allow retry for sellers that previously failed.
            $completed = self::sellers_with_labels( $order );
            $planned   = self::snapshot_sellers( $order );
            $remaining = array_diff( $planned, $completed );
            if ( empty( $remaining ) ) {
                return;
            }
        }

        $order->update_meta_data( '_mnu_autolabel_run_at', current_time( 'mysql', true ) );
        $order->save();

        $sellers = self::snapshot_sellers( $order );
        if ( empty( $sellers ) ) {
            // Buyer paid before any live rate was captured (edge case, e.g.
            // flat-rate fallback path). Nothing to buy — seller dashboard
            // will show the manual rate picker like today.
            $order->add_order_note( 'Auto-label skipped because no live shipping rate was captured at checkout.' );
            return;
        }

        foreach ( $sellers as $seller_id ) {
            self::purchase_for_seller( $order, (int) $seller_id );
        }
    }

    /**
     * Buy a single seller's label. Writes success or error meta and adds an
     * order note in either case.
     */
    public static function purchase_for_seller( WC_Order $order, int $seller_id ): void {
        if ( $seller_id <= 0 ) {
            return;
        }
        $suffix = '_' . $seller_id;

        // Idempotency: if this seller already has a label, we're done.
        if ( $order->get_meta( '_thenest_label_transaction' . $suffix, true )
             || $order->get_meta( '_thenest_label_url' . $suffix, true ) ) {
            return;
        }

        // v3.13.29 — previously this method was documented as idempotent
        // per seller but the check-then-Shippo-call was not atomic. Two PHP
        // workers firing on the same payment hook could both observe no
        // transaction meta, both call Shippo, and both charge the postage.
        // The audit found no platform idempotency key was being sent to
        // Shippo either. Now we hold a MySQL GET_LOCK keyed by order_id +
        // seller_id for the duration of the purchase. GET_LOCK is atomic,
        // auto-expires when the DB connection dies (so a crashed worker
        // cannot wedge a seller permanently), and the second worker either
        // waits its turn and then sees the transaction meta already set,
        // or bails immediately with a lock-contention log line.
        global $wpdb;
        $lock_name  = sprintf( 'mnu_autolabel_%d_%d', $order->get_id(), $seller_id );
        $got_lock   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_name, 0 ) );
        if ( 1 !== $got_lock ) {
            $order->add_order_note( sprintf(
                'Auto-label for seller #%d skipped: another worker holds the label-purchase lock. It will retry on the next payment hook if needed.',
                $seller_id
            ) );
            return;
        }

        try {
            // Re-read after we got the lock. The other worker may have
            // finished by the time we entered.
            clean_post_cache( $order->get_id() );
            $fresh_order = wc_get_order( $order->get_id() );
            if ( $fresh_order instanceof WC_Order
                && ( $fresh_order->get_meta( '_thenest_label_transaction' . $suffix, true )
                     || $fresh_order->get_meta( '_thenest_label_url' . $suffix, true ) ) ) {
                return;
            }
            if ( $fresh_order instanceof WC_Order ) {
                // Use the freshly-loaded order for the rest of this call so
                // downstream meta writes happen against the current state.
                $order = $fresh_order;
            }

            $rate_id  = (string) $order->get_meta( '_mnu_ship_rate_id_' . $seller_id, true );
            $provider = (string) $order->get_meta( '_mnu_ship_provider_' . $seller_id, true );
            $service  = (string) $order->get_meta( '_mnu_ship_service_'  . $seller_id, true );
            $amount   = (string) $order->get_meta( '_mnu_ship_amount_'   . $seller_id, true );
            $currency = (string) $order->get_meta( '_mnu_ship_currency_' . $seller_id, true ) ?: $order->get_currency();

            if ( '' === $rate_id ) {
                self::record_error( $order, $seller_id, 'No captured Shippo rate id on this order — seller must buy the label manually.' );
                return;
            }

            if ( ! function_exists( 'mnu_labels_shippo_request' ) || ! function_exists( 'mnu_labels_with_seller_token' ) ) {
                self::record_error( $order, $seller_id, 'Shipping-labels module is not loaded; cannot auto-buy the label.' );
                return;
            }

            $body = array(
                'rate'            => $rate_id,
                'label_file_type' => 'PDF',
                'async'           => false,
                'metadata'        => sprintf( 'MyNest order %s seller %d (auto)', $order->get_order_number(), $seller_id ),
            );

            // v3.7.82 seller-token scope: uses the seller's own Shippo when
            // connected, platform token otherwise. Both cases are exercised by
            // the same wrapper, so we don't branch here.
            //
            // v3.13.29 — pre-stamp a "purchasing" marker so a concurrent
            // request that squeaks past the lock (e.g. lock lost mid-flight)
            // observes intent-in-flight rather than an empty transaction meta.
            $order->update_meta_data( '_thenest_label_purchase_state' . $suffix, 'purchasing' );
            $order->update_meta_data( '_thenest_label_purchase_started_at' . $suffix, current_time( 'mysql', true ) );
            $order->save();

            $transaction = mnu_labels_with_seller_token( $seller_id, static function () use ( $body ) {
                return mnu_labels_shippo_request( '/transactions/', $body );
            } );

            if ( is_wp_error( $transaction ) ) {
                $order->update_meta_data( '_thenest_label_purchase_state' . $suffix, 'error' );
                $order->save();
                self::record_error( $order, $seller_id, $transaction->get_error_message() );
                return;
            }

            // mnu_labels_store_transaction() writes all the standard label meta,
            // updates the ledger (platform-paid path), notifies the buyer, and
            // flips _tnm_seller_status_{seller} to 'shipped'. Reusing it here
            // keeps the manual and auto paths in lockstep.
            if ( ! function_exists( 'mnu_labels_store_transaction' ) ) {
                $order->update_meta_data( '_thenest_label_purchase_state' . $suffix, 'error' );
                $order->save();
                self::record_error( $order, $seller_id, 'Label store helper is unavailable.' );
                return;
            }

            $selected_rate = array(
                'provider' => $provider,
                'service'  => $service,
                'amount'   => $amount,
                'currency' => $currency,
            );

            $result = mnu_labels_store_transaction( $order, $seller_id, $transaction, $selected_rate );
            if ( is_wp_error( $result ) ) {
                $order->update_meta_data( '_thenest_label_purchase_state' . $suffix, 'error' );
                $order->save();
                self::record_error( $order, $seller_id, $result->get_error_message() );
                return;
            }

            // Clear any prior error and stamp success meta the dashboard uses to
            // decide whether to show the label card vs the rate picker.
            $order->delete_meta_data( '_mnu_autolabel_error' . $suffix );
            $order->update_meta_data( '_thenest_label_purchase_state' . $suffix, 'done' );
            $order->update_meta_data( '_mnu_autolabel_status' . $suffix, 'success' );
            $order->update_meta_data( '_mnu_autolabel_bought_at' . $suffix, current_time( 'mysql', true ) );
            // Whether the platform or seller Shippo funded it drives payout math
            // and dashboard copy ('billed to your Shippo' vs 'deducted from payout').
            $paid_by = ( function_exists( 'mnu_labels_seller_on_own_shippo' ) && mnu_labels_seller_on_own_shippo( $seller_id ) )
                ? 'seller_shippo'
                : 'platform';
            $order->update_meta_data( '_mnu_autolabel_paid_by' . $suffix, $paid_by );
            $order->save();

            $order->add_order_note(
                sprintf(
                    'Auto-purchased %s%s shipping label for %s (buyer-paid). Tracking: %s',
                    $provider ? $provider . ' ' : '',
                    $service ?: 'shipping',
                    function_exists( 'tnm_seller_display_name' ) ? tnm_seller_display_name( $seller_id ) : ( '#' . $seller_id ),
                    (string) ( $result['tracking_number'] ?? 'pending' )
                )
            );

            // Notify the seller they have a label ready to print.
            if ( function_exists( 'tnm_notify' ) ) {
                tnm_notify(
                    $seller_id,
                    (int) $order->get_customer_id(),
                    'label_ready',
                    'Label ready to print — order #' . $order->get_order_number(),
                    'The buyer already paid for shipping. Print the label from your seller dashboard.',
                    $order->get_id(),
                    'shop_order',
                    admin_url( 'admin.php?page=thenest-shipping-labels&order=' . $order->get_id() )
                );
            }
            if ( class_exists( 'MNU_Ops' ) ) {
                MNU_Ops::notify_user(
                    $seller_id,
                    'Label ready to print',
                    'Order #' . $order->get_order_number() . ' — the buyer paid for shipping and the label is ready in your dashboard.',
                    array(
                        'type'      => 'label_ready',
                        'order_id'  => $order->get_id(),
                        'seller_id' => $seller_id,
                    )
                );
            }
        } finally {
            // v3.13.29 — release the atomic order+seller lock. GET_LOCK is
            // scoped to this DB connection; RELEASE_LOCK is a no-op on any
            // path where we never got the lock (we early-returned above),
            // but calling it in finally guarantees cleanup on any early
            // return, wp_die, or thrown exception inside the try.
            $wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
        }
    }

    /**
     * Void every label attached to this order that hasn't been scanned yet.
     * Called on order cancel/refund. Uses Shippo `/refunds/` (async), which
     * accepts a transaction id and later credits the label cost back.
     */
    public static function on_order_cancelled( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order instanceof WC_Order ) {
            return;
        }
        self::void_labels( $order );
    }

    /**
     * Iterate every seller shipment on the order and request a Shippo
     * refund. Idempotent per seller via _mnu_label_voided_{seller}.
     */
    public static function void_labels( WC_Order $order ): void {
        if ( ! function_exists( 'mnu_labels_shippo_request' ) || ! function_exists( 'mnu_labels_with_seller_token' ) ) {
            return;
        }
        foreach ( self::snapshot_sellers( $order ) as $seller_id ) {
            $seller_id = (int) $seller_id;
            if ( $seller_id <= 0 ) { continue; }
            $suffix    = '_' . $seller_id;
            if ( $order->get_meta( '_mnu_label_voided' . $suffix, true ) ) { continue; }
            $tx_id = (string) $order->get_meta( '_thenest_label_transaction' . $suffix, true );
            if ( '' === $tx_id ) { continue; }

            $refund = mnu_labels_with_seller_token( $seller_id, static function () use ( $tx_id ) {
                return mnu_labels_shippo_request( '/refunds/', array( 'transaction' => $tx_id, 'async' => true ) );
            } );

            if ( is_wp_error( $refund ) ) {
                $order->add_order_note(
                    sprintf(
                        'Could not void %s label for %s: %s',
                        function_exists( 'tnm_seller_display_name' ) ? tnm_seller_display_name( $seller_id ) : ( '#' . $seller_id ),
                        $tx_id,
                        $refund->get_error_message()
                    )
                );
                continue;
            }

            $order->update_meta_data( '_mnu_label_voided' . $suffix, current_time( 'mysql', true ) );
            $order->update_meta_data( '_mnu_label_void_status' . $suffix, sanitize_text_field( (string) ( $refund['status'] ?? 'queued' ) ) );
            $order->save();
            $order->add_order_note(
                sprintf(
                    'Requested void for %s shipping label (%s). Shippo refund status: %s',
                    function_exists( 'tnm_seller_display_name' ) ? tnm_seller_display_name( $seller_id ) : ( '#' . $seller_id ),
                    $tx_id,
                    (string) ( $refund['status'] ?? 'queued' )
                )
            );

            // If the platform fronted this label, reverse the postage-ledger
            // debit so the seller's next payout isn't docked for a label they
            // never used.
            $paid_by = (string) $order->get_meta( '_mnu_autolabel_paid_by' . $suffix, true );
            if ( 'platform' === $paid_by && function_exists( 'mnu_labels_reverse_postage_ledger_row' ) ) {
                mnu_labels_reverse_postage_ledger_row( $order, $seller_id, $tx_id );
            }
        }
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private static function record_error( WC_Order $order, int $seller_id, string $message ): void {
        $suffix = '_' . $seller_id;
        $order->update_meta_data( '_mnu_autolabel_status' . $suffix, 'failed' );
        $order->update_meta_data( '_mnu_autolabel_error'  . $suffix, sanitize_text_field( $message ) );
        $order->update_meta_data( '_mnu_autolabel_failed_at' . $suffix, current_time( 'mysql', true ) );
        $order->save();
        $order->add_order_note(
            sprintf(
                'Auto-label purchase failed for %s: %s. Seller can retry from the dashboard.',
                function_exists( 'tnm_seller_display_name' ) ? tnm_seller_display_name( $seller_id ) : ( '#' . $seller_id ),
                $message
            )
        );

        // Buyer's card already cleared; nudge admins so they can decide whether
        // to refund the shipping portion or let the seller retry.
        if ( class_exists( 'MNU_Ops' ) ) {
            $admins = get_users( array( 'role' => 'administrator', 'fields' => array( 'ID' ) ) );
            $body   = sprintf(
                'Shippo rejected the label buy for %s: %s. Review the order and either retry the purchase or refund the shipping portion to the buyer.',
                function_exists( 'tnm_seller_display_name' ) ? tnm_seller_display_name( $seller_id ) : ( '#' . $seller_id ),
                $message
            );
            foreach ( (array) $admins as $admin ) {
                MNU_Ops::notify_user(
                    (int) $admin->ID,
                    'Auto-label failed on order #' . $order->get_order_number(),
                    $body,
                    array(
                        'type'      => 'autolabel_failed',
                        'order_id'  => $order->get_id(),
                        'seller_id' => $seller_id,
                    )
                );
            }
        }
    }

    /**
     * Return the list of seller IDs we captured a rate snapshot for at
     * checkout. Falls back to seller IDs we can derive from the order items,
     * so orders created before v3.7.87 still get the void-on-cancel path.
     *
     * @return int[]
     */
    private static function snapshot_sellers( WC_Order $order ): array {
        $json = (string) $order->get_meta( '_mnu_ship_snapshot_sellers', true );
        $ids  = $json ? (array) json_decode( $json, true ) : array();
        if ( empty( $ids ) ) {
            $ids = array();
            foreach ( $order->get_items() as $item ) {
                if ( ! $item instanceof WC_Order_Item_Product ) { continue; }
                $sid = (int) $item->get_meta( '_tnm_seller_id', true );
                if ( $sid > 0 ) { $ids[ $sid ] = $sid; }
            }
        }
        return array_values( array_map( 'intval', array_filter( $ids ) ) );
    }

    /** @return int[] */
    private static function sellers_with_labels( WC_Order $order ): array {
        $out = array();
        foreach ( self::snapshot_sellers( $order ) as $sid ) {
            $sid = (int) $sid;
            if ( $order->get_meta( '_thenest_label_url_' . $sid, true )
                 || $order->get_meta( '_thenest_label_transaction_' . $sid, true ) ) {
                $out[] = $sid;
            }
        }
        return $out;
    }
}

MNU_Auto_Label::init();
