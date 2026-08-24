<?php

defined( 'ABSPATH' ) || exit;

final class TNM_Ledger {
    public static function init(): void {
        add_action( 'woocommerce_payment_complete', array( __CLASS__, 'capture_order' ) );
        add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'capture_order' ) );
        add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'complete_order' ) );
        add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'void_order' ) );
        add_action( 'woocommerce_order_status_failed', array( __CLASS__, 'void_order' ) );
        add_action( 'woocommerce_order_refunded', array( __CLASS__, 'record_refund' ), 10, 2 );
        add_action( 'tnm_daily_maintenance', array( __CLASS__, 'release_available_earnings' ) );
    }

    public static function capture_order( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }
        TNM_Marketplace::stamp_order_sellers( $order );
        self::create_order_rows( $order );
        self::notify_new_order( $order );
    }

    private static function notify_new_order( WC_Order $order ): void {
        $seller_ids = array();
        foreach ( $order->get_items() as $item ) {
            $seller_id = $item instanceof WC_Order_Item_Product ? tnm_get_order_item_seller_id( $item ) : 0;
            if ( $seller_id ) {
                $seller_ids[ $seller_id ] = true;
            }
        }
        foreach ( array_keys( $seller_ids ) as $seller_id ) {
            $meta_key = '_tnm_new_order_notified_' . $seller_id;
            if ( $order->get_meta( $meta_key, true ) ) {
                continue;
            }
            $message = 'You have new items to fulfill in order #' . $order->get_order_number() . '.';
            tnm_notify( (int) $seller_id, 0, 'new_order', 'New seller order', $message, $order->get_id(), 'shop_order', tnm_page_url( 'seller_dashboard' ) );
            $seller = get_userdata( (int) $seller_id );
            if ( $seller && 'yes' === tnm_get_option( 'seller_order_emails', 'yes' ) ) {
                wp_mail( $seller->user_email, 'New MyNest order #' . $order->get_order_number(), $message );
            }
            $order->update_meta_data( $meta_key, current_time( 'mysql', true ) );
        }
        $order->save();
    }

    public static function complete_order( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }
        self::create_order_rows( $order );
        self::release_order_if_ready( $order );

        $seller_ids = array();
        foreach ( $order->get_items() as $item ) {
            $seller_id = $item instanceof WC_Order_Item_Product ? tnm_get_order_item_seller_id( $item ) : 0;
            if ( $seller_id ) {
                $seller_ids[ $seller_id ] = true;
            }
        }
        foreach ( array_keys( $seller_ids ) as $seller_id ) {
            $meta_key = '_tnm_completed_notified_' . $seller_id;
            if ( $order->get_meta( $meta_key, true ) ) {
                continue;
            }
            tnm_notify( (int) $seller_id, 0, 'order_completed', 'Order #' . $order->get_order_number() . ' completed', 'Earnings will become available after the marketplace holding period.', $order_id, 'shop_order', tnm_page_url( 'seller_dashboard' ) );
            $order->update_meta_data( $meta_key, current_time( 'mysql', true ) );
        }
        $order->save();
    }

    public static function create_order_rows( WC_Order $order ): void {
        global $wpdb;
        $items = $order->get_items();
        if ( ! $items ) {
            return;
        }

        $line_total = 0.0;
        $qty_total  = 0;
        foreach ( $items as $item ) {
            if ( $item instanceof WC_Order_Item_Product && tnm_get_order_item_seller_id( $item ) > 0 ) {
                $line_total += max( 0, (float) $item->get_total() );
                $qty_total  += max( 1, (int) $item->get_quantity() );
            }
        }
        $shipping_total = max( 0, (float) $order->get_shipping_total() );

        // Prefer a truthful per-seller shipping breakdown captured at order
        // creation (see mnu_native_create_order in class-mnu-native-checkout.php
        // and mnu_native_shipping_breakdown_by_seller). Falls back to the
        // legacy proportional-by-subtotal allocation for legacy orders and
        // orders whose breakdown snapshot wasn't captured.
        $shipping_by_seller = array();
        $ship_meta          = (string) $order->get_meta( '_mnu_shipping_by_seller', true );
        if ( '' !== $ship_meta ) {
            $decoded = json_decode( $ship_meta, true );
            if ( is_array( $decoded ) ) {
                foreach ( $decoded as $sid => $amt ) {
                    $sid = (int) $sid;
                    if ( $sid > 0 ) {
                        $shipping_by_seller[ $sid ] = (float) $amt;
                    }
                }
            }
        }
        $shipping_seller_allocated = array();

        // v3.8.0 — detect the new money model. New orders get a plain
        // platform charge (no Connect destination). Seller ledger net is
        // computed as (product * 0.90) with shipping=0 (platform keeps 100%
        // of buyer-paid shipping, pays for the Shippo label out of that).
        // Legacy orders (meta absent) keep the previous behavior so we
        // don't retroactively short existing payouts.
        $is_v380_model            = '1' === (string) $order->get_meta( '_mnu_v380_model', true );
        // v3.7.124 legacy flag — also causes platform to keep shipping. All
        // v3.8.0 orders satisfy this too, but reading both preserves the old
        // orders' behavior verbatim.
        $platform_keeps_shipping = $is_v380_model || '' !== (string) $order->get_meta( '_mnu_platform_shipping_kept_cents', true );

        // v3.13.14 — holding window is per-seller. Sellers wait
        // holding_days (default 7, per v3.13.15) after order-paid time
        // before earnings become available. Admins skip the hold entirely:
        // their earnings are written directly as 'available' with
        // available_at=now so a cancellation or refund can be issued
        // against real funds without waiting on the release cron.
        $holding_days     = max( 0, (int) tnm_get_option( 'holding_days', 2 ) );
        $paid_date        = $order->get_date_paid() ?: $order->get_date_created();
        $paid_ts          = $paid_date ? $paid_date->getTimestamp() : time();
        $seller_available = gmdate( 'Y-m-d H:i:s', $paid_ts + ( $holding_days * DAY_IN_SECONDS ) );
        $now              = current_time( 'mysql', true );

        foreach ( $items as $item_id => $item ) {
            $seller_id = $item instanceof WC_Order_Item_Product ? tnm_get_order_item_seller_id( $item ) : 0;
            if ( ! $seller_id ) {
                continue;
            }
            $gross = max( 0, (float) $item->get_total() );
            $tax   = max( 0, (float) $item->get_total_tax() );
            $fee_meta = $item->get_meta( '_tnm_platform_fee', true );
            if ( '' === $fee_meta ) {
                $fee_meta = $item->get_meta( '_mynest_nestkeeper_fee', true );
            }
            $fee   = '' === $fee_meta ? round( $gross * ( tnm_fee_percent() / 100 ), wc_get_price_decimals() + 2 ) : (float) $fee_meta;
            if ( $fee <= 0 && $gross > 0 ) {
                $fee = round( $gross * ( tnm_fee_percent() / 100 ), wc_get_price_decimals() + 2 );
            }
            if ( $is_v380_model ) {
                // v3.8.0 — hard-lock platform cut at 10% of the seller's
                // product subtotal. The item snapshot may have been stamped
                // with an older percent at product-add time; use
                // that value only for reference and overwrite the ledger
                // row fee to the new fixed 10% so seller_net is always
                // product * 0.90 for new-model orders.
                $fee = round( $gross * 0.10, wc_get_price_decimals() + 2 );
            }
            if ( $platform_keeps_shipping ) {
                // Platform keeps all shipping. Seller ledger row has zero
                // shipping. Postage-clawback rows are no longer written for
                // these orders either.
                $shipping = 0.0;
            } elseif ( isset( $shipping_by_seller[ $seller_id ] ) ) {
                // Allocate the seller's full shipping amount to their FIRST
                // ledger row; subsequent rows for the same seller get $0 so
                // per-seller shipping isn't multiplied across a seller with
                // multiple line items.
                if ( empty( $shipping_seller_allocated[ $seller_id ] ) ) {
                    $shipping                                = (float) $shipping_by_seller[ $seller_id ];
                    $shipping_seller_allocated[ $seller_id ] = true;
                } else {
                    $shipping = 0.0;
                }
            } elseif ( $line_total > 0 ) {
                $shipping = $shipping_total * ( $gross / $line_total );
            } else {
                $shipping = $qty_total > 0 ? $shipping_total * ( max( 1, (int) $item->get_quantity() ) / $qty_total ) : 0;
            }
            $net = max( 0, $gross - $fee + $shipping );

            // v3.13.14 — admins bypass the hold. Write the row already
            // 'available' with available_at=now so /balances and payout
            // eligibility both see it without waiting for the release cron.
            $is_admin_seller = user_can( $seller_id, 'manage_options' );
            $row_status      = $is_admin_seller ? 'available' : 'pending';
            $row_available   = $is_admin_seller ? $now : $seller_available;

            $wpdb->query(
                $wpdb->prepare(
                    'INSERT IGNORE INTO ' . tnm_table( 'ledger' ) . ' (seller_id,order_id,order_item_id,type,gross,platform_fee,tax,shipping,net,currency,status,available_at,payout_id,note,created_at,updated_at) VALUES (%d,%d,%d,%s,%f,%f,%f,%f,%f,%s,%s,%s,0,%s,%s,%s)',
                    $seller_id,
                    $order->get_id(),
                    $item_id,
                    'earning',
                    $gross,
                    $fee,
                    $tax,
                    $shipping,
                    $net,
                    $order->get_currency(),
                    $row_status,
                    $row_available,
                    tnm_fee_label() . ' ' . tnm_fee_percent() . '%; sales tax excluded from seller payout.',
                    $now,
                    $now
                )
            );
        }
    }

    public static function release_order_if_ready( WC_Order $order ): void {
        if ( ! $order->has_status( 'completed' ) ) {
            return;
        }
        // v3.13.29 — do not release earnings for an order with an active
        // Stripe dispute hold. The dispute handler in class-mnu-native-checkout
        // stamps _mnu_dispute_hold=1 on any charge.dispute.created /
        // .updated / .funds_withdrawn event, and clears it only if the
        // dispute closes in our favour or funds are reinstated.
        if ( '1' === (string) $order->get_meta( '_mnu_dispute_hold', true ) ) {
            return;
        }
        global $wpdb;
        $now = current_time( 'mysql', true );
        $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . tnm_table( 'ledger' ) . " SET status='available', updated_at=%s WHERE order_id=%d AND type='earning' AND status='pending' AND available_at <= %s",
                $now,
                $order->get_id(),
                $now
            )
        );

        self::create_seller_transfers( $order->get_id() );
    }

    /**
     * Create a Stripe Connect transfer for each seller with now-available
     * earnings on this order (separate charges and transfers pattern). Only
     * touches rows that have not been transferred yet; rows for sellers who are
     * not yet onboarded are left untouched so a later retry can pick them up.
     */
    public static function create_seller_transfers( int $order_id ): void {
        // v3.13.28 — HARD KILL SWITCH.
        //
        // v3.11.0 removed sellers from Stripe Connect entirely; every seller
        // is paid by manual ACH from Bluevine after the 2-day holding window.
        // The pre-existing per-order `_mnu_v380_model` guard depended on every
        // checkout path stamping that meta before payment. The v3.13.27 audit
        // found the web gateway did not stamp it, so any order created via
        // the WooCommerce web checkout could still hit the /transfers call
        // below and double-pay a seller (Stripe transfer + Bluevine ACH).
        //
        // Rather than rely on every future checkout path remembering to stamp
        // the model meta, this function is now an unconditional no-op. The
        // web gateway also stamps `_mnu_v380_model=1` for audit clarity, but
        // even without the stamp this method cannot move money.
        //
        // If we ever need to revive Stripe Connect payouts (we don't plan to),
        // reintroduce them as a NEW method on this class — do not re-enable
        // this one. The historical code below is retained under an impossible
        // guard so grep archaeology still finds it.
        return;

        // phpcs:ignore Squiz.PHP.NonExecutableCode.Unreachable
        if ( false ) {
            if ( ! function_exists( 'mnu_native_stripe_request' ) || ! class_exists( 'MNU_Connect' ) ) {
                return;
            }
            $order = wc_get_order( $order_id );
            if ( $order && '1' === (string) $order->get_meta( '_mnu_v380_model', true ) ) {
                return;
            }
        }
        global $wpdb;
        // v3.7.81 — include postage-debit rows so the marketplace recovers
        // Shippo postage from the seller’s payout for the same order. Postage
        // rows have net<0; grouping by seller lets SUM(net) do the netting.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, seller_id, net, currency FROM ' . tnm_table( 'ledger' ) . " WHERE order_id=%d AND type IN ('earning','postage') AND status='available' AND stripe_transfer_id=''",
                $order_id
            )
        );
        if ( ! $rows ) {
            return;
        }

        $groups = array();
        foreach ( $rows as $row ) {
            $seller_id = (int) $row->seller_id;
            $currency  = strtolower( (string) $row->currency );
            $key       = $seller_id . '|' . $currency;
            if ( ! isset( $groups[ $key ] ) ) {
                $groups[ $key ] = array(
                    'seller_id' => $seller_id,
                    'currency'  => $currency,
                    'amount'    => 0.0,
                    'ids'       => array(),
                );
            }
            $groups[ $key ]['amount'] += (float) $row->net;
            $groups[ $key ]['ids'][]   = (int) $row->id;
        }

        foreach ( $groups as $group ) {
            $seller_id  = $group['seller_id'];
            $account_id = MNU_Connect::account_id( $seller_id );
            if ( '' === $account_id || ! MNU_Connect::seller_can_sell( $seller_id ) ) {
                continue; // Seller not ready; leave rows for a later retry.
            }
            $cents = (int) round( $group['amount'] * 100 );
            if ( $cents <= 0 ) {
                // v3.7.81 — seller net is <= 0 after subtracting postage.
                // Leave the rows in place (do not stamp stripe_transfer_id)
                // so a later, larger payout can absorb the debt.
                continue;
            }
            $transfer = mnu_native_stripe_request(
                '/transfers',
                array(
                    'amount'                 => $cents,
                    'currency'               => $group['currency'],
                    'destination'            => $account_id,
                    'transfer_group'         => 'order_' . $order_id,
                    'metadata[wc_order_id]'  => (string) $order_id,
                    'metadata[seller_id]'    => (string) $seller_id,
                ),
                'tnm_transfer_' . $order_id . '_' . $seller_id . '_' . $cents
            );
            if ( is_wp_error( $transfer ) ) {
                continue; // Do not stamp; a retry can try again.
            }
            $transfer_id = sanitize_text_field( (string) ( $transfer['id'] ?? '' ) );
            if ( '' === $transfer_id ) {
                continue;
            }
            $now         = current_time( 'mysql', true );
            $placeholders = implode( ',', array_fill( 0, count( $group['ids'] ), '%d' ) );
            $params       = array_merge( array( $transfer_id, $now ), $group['ids'] );
            $wpdb->query(
                $wpdb->prepare(
                    'UPDATE ' . tnm_table( 'ledger' ) . " SET stripe_transfer_id=%s, updated_at=%s WHERE id IN ($placeholders)",
                    $params
                )
            );
        }
    }

    public static function release_available_earnings(): void {
        global $wpdb;
        $now      = current_time( 'mysql', true );
        $order_ids = $wpdb->get_col(
            $wpdb->prepare(
                'SELECT DISTINCT order_id FROM ' . tnm_table( 'ledger' ) . " WHERE type='earning' AND status='pending' AND available_at <= %s LIMIT 500",
                $now
            )
        );
        foreach ( $order_ids as $order_id ) {
            $order = wc_get_order( (int) $order_id );
            if ( $order && $order->has_status( 'completed' ) ) {
                self::release_order_if_ready( $order );
            }
        }

        // Retry transfers for earnings that became available but were not yet
        // transferred (e.g. the seller finished Stripe onboarding afterwards).
        $pending_transfer_orders = $wpdb->get_col(
            'SELECT DISTINCT order_id FROM ' . tnm_table( 'ledger' ) . " WHERE type='earning' AND status='available' AND stripe_transfer_id='' AND net>0 LIMIT 200"
        );
        foreach ( $pending_transfer_orders as $pending_order_id ) {
            self::create_seller_transfers( (int) $pending_order_id );
        }

        TNM_Payouts::maybe_generate_automatic_payouts();
    }

    public static function void_order( int $order_id ): void {
        global $wpdb;
        $now = current_time( 'mysql', true );
        $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . tnm_table( 'ledger' ) . " SET status='void', updated_at=%s, note=CONCAT(COALESCE(note,''), %s) WHERE order_id=%d AND status IN ('pending','available')",
                $now,
                ' Order cancelled or failed.',
                $order_id
            )
        );
    }

    public static function record_refund( int $order_id, int $refund_id ): void {
        global $wpdb;
        $order  = wc_get_order( $order_id );
        $refund = wc_get_order( $refund_id );
        if ( ! $order || ! $refund || ! is_a( $refund, 'WC_Order_Refund' ) ) {
            return;
        }
        $now = current_time( 'mysql', true );

        // A REST- or amount-only refund has no line items -- \WC_Order_Refund::get_items()
        // returns []. In that case, apportion the refund amount across the
        // original order's product line items proportional to each item's total,
        // so the marketplace ledger always compensates for a refund regardless
        // of how it was created (REST, wp-admin quick-refund, gateway webhook).
        $refund_items = $refund->get_items();
        if ( empty( $refund_items ) ) {
            $refund_amount = abs( (float) $refund->get_amount() );
            if ( $refund_amount <= 0 ) {
                return;
            }

            // Read the per-seller shipping breakdown captured at order-create
            // time (see mnu_native_create_order). Falls back to proportional
            // allocation when unavailable so legacy orders still refund.
            $shipping_by_seller = array();
            $ship_meta          = (string) $order->get_meta( '_mnu_shipping_by_seller', true );
            if ( '' !== $ship_meta ) {
                $decoded = json_decode( $ship_meta, true );
                if ( is_array( $decoded ) ) {
                    foreach ( $decoded as $sid => $amt ) {
                        $sid = (int) $sid;
                        if ( $sid > 0 ) {
                            $shipping_by_seller[ $sid ] = (float) $amt;
                        }
                    }
                }
            }

            $order_items_total  = 0.0;
            $eligible_items     = array();
            $seller_first_item  = array();
            foreach ( $order->get_items() as $order_item_id => $order_item ) {
                if ( ! $order_item instanceof WC_Order_Item_Product ) {
                    continue;
                }
                $line_total = (float) $order_item->get_total();
                if ( $line_total <= 0 ) {
                    continue;
                }
                $seller_id = (int) tnm_get_order_item_seller_id( $order_item );
                $eligible_items[ $order_item_id ] = array(
                    'item'       => $order_item,
                    'line_total' => $line_total,
                    'seller_id'  => $seller_id,
                );
                $order_items_total += $line_total;
                if ( $seller_id > 0 && ! isset( $seller_first_item[ $seller_id ] ) ) {
                    // The first item we see for each seller carries that
                    // seller's shipping-share refund. Later items for the
                    // same seller only carry their product-share refund.
                    $seller_first_item[ $seller_id ] = $order_item_id;
                }
            }
            if ( $order_items_total <= 0 || empty( $eligible_items ) ) {
                return;
            }

            // Split the refund into a product portion and a shipping portion so
            // each seller gets compensated for the product AND shipping they
            // actually lose. Without this, a $36.18 full refund on a $25.01
            // cart with $11.17 shipping was allocating almost the entire
            // amount to the seller with the higher line total — the $0.01
            // seller got $0.014 while the $25.00 seller ate $36.17.
            $order_ship_total = 0.0;
            foreach ( $shipping_by_seller as $sid => $amt ) {
                if ( isset( $seller_first_item[ $sid ] ) ) {
                    $order_ship_total += (float) $amt;
                }
            }
            $order_grand_total = $order_items_total + $order_ship_total;
            if ( $order_grand_total <= 0 ) {
                $order_grand_total = $order_items_total;
            }
            // Refund share proportion is capped at 1.0 (never over-refund) and
            // handles partial amount-only refunds correctly.
            $refund_share = min( 1.0, $refund_amount / $order_grand_total );

            foreach ( $eligible_items as $original_item_id => $entry ) {
                $original_item = $entry['item'];
                $seller_id     = $entry['seller_id'];
                if ( ! $seller_id ) {
                    continue;
                }
                // Product share of the refund is the same shape as before:
                // this line item's price scaled by the refund proportion.
                $refunded_gross = round( $entry['line_total'] * $refund_share, wc_get_price_decimals() + 2 );

                // Shipping share of the refund goes to the seller's FIRST
                // eligible item only, so per-seller shipping isn't double-
                // counted across multiple items for the same seller.
                $refunded_shipping = 0.0;
                if (
                    isset( $seller_first_item[ $seller_id ], $shipping_by_seller[ $seller_id ] )
                    && $seller_first_item[ $seller_id ] === $original_item_id
                ) {
                    $refunded_shipping = round( (float) $shipping_by_seller[ $seller_id ] * $refund_share, wc_get_price_decimals() + 2 );
                }

                if ( $refunded_gross <= 0 && $refunded_shipping <= 0 ) {
                    continue;
                }
                self::insert_refund_row( $order, $refund_id, $original_item, $refunded_gross, 0.0, $now, $refunded_shipping );
            }
            return;
        }

        foreach ( $refund_items as $refund_item_id => $refund_item ) {
            $original_item_id = absint( wc_get_order_item_meta( $refund_item_id, '_refunded_item_id', true ) );
            if ( ! $original_item_id ) {
                continue;
            }
            $original_item = $order->get_item( $original_item_id );
            if ( ! $original_item ) {
                continue;
            }
            $seller_id = $original_item instanceof WC_Order_Item_Product ? tnm_get_order_item_seller_id( $original_item ) : 0;
            if ( ! $seller_id ) {
                continue;
            }
            $refunded_gross = abs( (float) $refund_item->get_total() );
            $tax_refund     = abs( (float) $refund_item->get_total_tax() );
            self::insert_refund_row( $order, $refund_id, $original_item, $refunded_gross, $tax_refund, $now );
        }
    }

    /**
     * Insert one compensating ledger row for a per-item refund share and
     * (best-effort) reverse the Stripe transfer that funded it. Extracted so
     * both the item-based and amount-only refund paths write rows consistently.
     */
    private static function insert_refund_row( WC_Order $order, int $refund_id, WC_Order_Item_Product $original_item, float $refunded_gross, float $tax_refund, string $now, float $refunded_shipping = 0.0 ): void {
        global $wpdb;
        $seller_id = tnm_get_order_item_seller_id( $original_item );
        // Allow rows with only a shipping refund (shipping-only compensation for
        // a full refund where product share is $0.
        if ( ! $seller_id || ( $refunded_gross <= 0 && $refunded_shipping <= 0 ) ) {
            return;
        }
        $order_id         = $order->get_id();
        $original_item_id = $original_item->get_id();
        $original_gross   = max( 0.000001, (float) $original_item->get_total() );
        $is_v380_model    = '1' === (string) $order->get_meta( '_mnu_v380_model', true );

        if ( $is_v380_model ) {
            // v3.8.0 — platform cut is a fixed 10%, so the fee refund is
            // exactly 10% of the refunded product portion. Shipping is not
            // seller-owned under the new model — any buyer shipping refund
            // comes out of platform's kept-shipping bucket and never touches
            // the seller ledger.
            $original_fee      = round( $original_gross * 0.10, wc_get_price_decimals() + 2 );
            $fee_refund        = $refunded_gross > 0 ? round( $refunded_gross * 0.10, wc_get_price_decimals() + 2 ) : 0.0;
            $refunded_shipping = 0.0;
        } else {
            $original_fee_meta = $original_item->get_meta( '_tnm_platform_fee', true );
            if ( '' === $original_fee_meta ) {
                $original_fee_meta = $original_item->get_meta( '_mynest_nestkeeper_fee', true );
            }
            $original_fee = '' === $original_fee_meta ? round( $original_gross * ( tnm_fee_percent() / 100 ), wc_get_price_decimals() + 2 ) : (float) $original_fee_meta;
            $fee_refund   = $refunded_gross > 0 ? min( $original_fee, $original_fee * ( $refunded_gross / $original_gross ) ) : 0.0;
        }
        // Seller loses: product gross minus refunded platform fee, plus the
        // shipping they were credited for (labels are non-refundable). For
        // v3.8.0 the shipping portion is always 0 (see above).
        $net_reversal = -max( 0, ( $refunded_gross - $fee_refund ) + $refunded_shipping );
        $type         = 'refund_' . $refund_id;

        $inserted = $wpdb->query(
            $wpdb->prepare(
                'INSERT IGNORE INTO ' . tnm_table( 'ledger' ) . ' (seller_id,order_id,order_item_id,type,gross,platform_fee,tax,shipping,net,currency,status,available_at,payout_id,note,created_at,updated_at) VALUES (%d,%d,%d,%s,%f,%f,%f,%f,%f,%s,%s,%s,0,%s,%s,%s)',
                $seller_id,
                $order_id,
                $original_item_id,
                $type,
                -$refunded_gross,
                -$fee_refund,
                -$tax_refund,
                -$refunded_shipping,
                $net_reversal,
                $order->get_currency(),
                'available',
                $now,
                'Customer refund #' . $refund_id,
                $now,
                $now
            )
        );

        // Only reverse the Stripe transfer once, when this refund row is
        // first recorded (INSERT IGNORE returns 0 for a duplicate webhook).
        if ( $inserted > 0 ) {
            self::reverse_transfer_for_item( $order_id, $original_item_id, abs( $net_reversal ), $refund_id );
        }

        tnm_notify( $seller_id, 0, 'order_refund', 'Refund on order #' . $order->get_order_number(), 'A seller earnings adjustment of ' . tnm_money( $net_reversal, $order->get_currency() ) . ' was recorded.', $order_id, 'shop_order', tnm_page_url( 'seller_dashboard' ) );
    }

    /**
     * Reverse (all or part of) the Stripe transfer for a refunded earning row.
     * No-op when the row was never transferred. Best-effort: failures are noted
     * on the order but never interrupt the WooCommerce refund flow.
     */
    private static function reverse_transfer_for_item( int $order_id, int $order_item_id, float $amount, int $refund_id ): void {
        if ( ! function_exists( 'mnu_native_stripe_request' ) || $amount <= 0 ) {
            return;
        }
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT stripe_transfer_id, net FROM ' . tnm_table( 'ledger' ) . " WHERE order_id=%d AND order_item_id=%d AND type='earning' LIMIT 1",
                $order_id,
                $order_item_id
            )
        );
        if ( ! $row || '' === (string) $row->stripe_transfer_id ) {
            return; // Money was never transferred to the seller.
        }
        $transfer_id = (string) $row->stripe_transfer_id;
        $reverse     = min( $amount, (float) $row->net );
        $cents       = (int) round( $reverse * 100 );
        if ( $cents <= 0 ) {
            return;
        }
        $result = mnu_native_stripe_request(
            '/transfers/' . rawurlencode( $transfer_id ) . '/reversals',
            array(
                'amount'                => $cents,
                'metadata[wc_order_id]' => (string) $order_id,
                'metadata[refund_id]'   => (string) $refund_id,
            ),
            'tnm_reversal_' . $refund_id . '_' . $order_item_id
        );
        $order = wc_get_order( $order_id );
        if ( $order ) {
            if ( is_wp_error( $result ) ) {
                $order->add_order_note( 'Stripe transfer reversal failed for refund #' . $refund_id . ': ' . $result->get_error_message() );
            } else {
                $order->add_order_note( 'Reversed ' . $cents . ' (smallest currency unit) from seller Stripe transfer ' . $transfer_id . ' for refund #' . $refund_id . '.' );
            }
        }
    }

    public static function balances( int $seller_id ): array {
        global $wpdb;
        // v3.7.122.6 — group by type as well so we can split the postage debit
        // out of `available`. The postage row is inserted with negative net and
        // status='available' so it naturally nets off the next transfer, but
        // rendering that raw sum as "Available to withdraw" produces a
        // negative dollar figure the seller reads as "you owe us."
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT status, type, currency, SUM(net) AS amount FROM ' . tnm_table( 'ledger' ) . ' WHERE seller_id=%d GROUP BY status,type,currency',
                $seller_id
            ),
            ARRAY_A
        );
        $balances = array(
            'pending'       => 0.0,
            'available'     => 0.0,
            'reserved'      => 0.0,
            'paid'          => 0.0,
            // New surface: absolute value of postage debits still riding on the
            // seller's earnings. Positive number that the UI can render as
            // "Shipping owed" or "Postage due". When the next paid order lands,
            // create_seller_transfers() nets it off automatically.
            'shipping_owed' => 0.0,
            'currency'      => get_woocommerce_currency(),
        );
        foreach ( $rows as $row ) {
            $status = $row['status'];
            $type   = $row['type'];
            $amount = (float) $row['amount'];
            if ( 'available' === $status && 'postage' === $type ) {
                // Postage is always inserted as a negative net. Track it as
                // a positive "owed" figure separate from the withdraw pool.
                $balances['shipping_owed'] += -$amount;
            } elseif ( array_key_exists( $status, $balances ) ) {
                $balances[ $status ] += $amount;
            }
            $balances['currency'] = $row['currency'] ?: $balances['currency'];
        }
        // Postage debits are still riding on the seller's earnings pool: the
        // real withdrawable amount is (earnings available - postage owed),
        // clamped to ≥ 0. `shipping_owed` remains visible as-is so the UI
        // can explain the gap. When create_seller_transfers() runs on the
        // next earning, SUM(net) will net the two together automatically.
        $withdrawable          = $balances['available'] - $balances['shipping_owed'];
        $balances['available'] = max( 0.0, $withdrawable );
        $balances['pending']       = round( $balances['pending'], wc_get_price_decimals() );
        $balances['available']     = round( $balances['available'], wc_get_price_decimals() );
        $balances['reserved']      = round( $balances['reserved'], wc_get_price_decimals() );
        $balances['paid']          = round( $balances['paid'], wc_get_price_decimals() );
        $balances['shipping_owed'] = round( $balances['shipping_owed'], wc_get_price_decimals() );
        return $balances;
    }

    /**
     * Platform-wide platform_fee revenue, summed across every seller's rows and
     * grouped by the ledger's own status column. Mirrors the shape of
     * balances() so the admin dashboard can render it with the same helper.
     *
     * This is an informational view keyed to the order/earning lifecycle
     * (pending -> available -> reserved -> paid). It is NOT the same as "how
     * much has been withdrawn to the bank" — actual owner withdrawals are
     * tracked separately via the platform_payout_id column
     * (see withdrawable_platform_fees()).
     */
    public static function platform_balances(): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            'SELECT status, currency, SUM(platform_fee) AS amount FROM ' . tnm_table( 'ledger' ) . ' GROUP BY status,currency',
            ARRAY_A
        );
        $balances = array(
            'pending'   => 0.0,
            'available' => 0.0,
            'reserved'  => 0.0,
            'paid'      => 0.0,
            'currency'  => get_woocommerce_currency(),
        );
        foreach ( $rows as $row ) {
            $status = $row['status'];
            if ( array_key_exists( $status, $balances ) ) {
                $balances[ $status ] += (float) $row['amount'];
            }
            $balances['currency'] = $row['currency'] ?: $balances['currency'];
        }
        foreach ( array( 'pending', 'available', 'reserved', 'paid' ) as $key ) {
            $balances[ $key ] = round( $balances[ $key ], wc_get_price_decimals() );
        }
        return $balances;
    }

    /**
     * Net platform-fee revenue that is eligible to be withdrawn to the owner's
     * bank right now: rows whose earnings are 'available' and whose platform fee
     * has not already been paid out (platform_payout_id still empty). Refund
     * adjustments (negative platform_fee) net against positive rows so the figure
     * never overstates the owner's real share of the pooled Stripe balance.
     *
     * @return array{amount:float,currency:string}
     */
    public static function withdrawable_platform_fees(): array {
        global $wpdb;
        $row = $wpdb->get_row(
            "SELECT COALESCE(SUM(platform_fee),0) AS amount, MAX(currency) AS currency FROM " . tnm_table( 'ledger' ) . " WHERE status='available' AND platform_payout_id=''",
            ARRAY_A
        );
        $currency = (string) ( $row['currency'] ?? '' );
        return array(
            'amount'   => round( (float) ( $row['amount'] ?? 0 ), wc_get_price_decimals() ),
            'currency' => $currency ?: get_woocommerce_currency(),
        );
    }

    /**
     * Stamp the oldest eligible 'available' rows with a completed Stripe payout id
     * so their platform fee revenue can never be withdrawn twice. Walks rows
     * oldest-first (mirroring reserve_available): negative refund rows are always
     * consumed, positive rows are taken only while the running total stays within
     * the amount actually paid out. Rows are marked, not status-changed, so seller
     * balances (which read the status column) are never affected.
     */
    public static function mark_platform_fees_paid( string $stripe_payout_id, float $amount ): void {
        global $wpdb;
        $stripe_payout_id = sanitize_text_field( $stripe_payout_id );
        if ( '' === $stripe_payout_id || $amount <= 0 ) {
            return;
        }
        $wpdb->query( 'START TRANSACTION' );
        try {
            $rows = $wpdb->get_results(
                "SELECT id, platform_fee FROM " . tnm_table( 'ledger' ) . " WHERE status='available' AND platform_payout_id='' ORDER BY created_at ASC,id ASC FOR UPDATE",
                ARRAY_A
            );
            $selected = array();
            $total    = 0.0;
            foreach ( $rows as $row ) {
                $fee = (float) $row['platform_fee'];
                if ( $fee <= 0 ) {
                    $selected[] = (int) $row['id'];
                    $total += $fee;
                    continue;
                }
                if ( $total + $fee > $amount + 0.000001 ) {
                    continue;
                }
                $selected[] = (int) $row['id'];
                $total += $fee;
            }
            if ( ! $selected ) {
                $wpdb->query( 'ROLLBACK' );
                return;
            }
            $ids = implode( ',', array_map( 'absint', $selected ) );
            $wpdb->query(
                $wpdb->prepare(
                    'UPDATE ' . tnm_table( 'ledger' ) . " SET platform_payout_id=%s,updated_at=%s WHERE id IN ($ids)",
                    $stripe_payout_id,
                    current_time( 'mysql', true )
                )
            );
            $wpdb->query( 'COMMIT' );
        } catch ( Throwable $throwable ) {
            $wpdb->query( 'ROLLBACK' );
        }
    }

    public static function entries( int $seller_id, int $page = 1, int $per_page = 30 ): array {
        global $wpdb;
        $per_page = max( 1, min( 100, $per_page ) );
        $offset   = ( max( 1, $page ) - 1 ) * $per_page;
        $total    = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . tnm_table( 'ledger' ) . ' WHERE seller_id=%d', $seller_id ) );
        $rows     = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . tnm_table( 'ledger' ) . ' WHERE seller_id=%d ORDER BY created_at DESC,id DESC LIMIT %d OFFSET %d',
                $seller_id,
                $per_page,
                $offset
            ),
            ARRAY_A
        );
        foreach ( $rows as &$row ) {
            foreach ( array( 'gross', 'platform_fee', 'tax', 'shipping', 'net' ) as $field ) {
                $row[ $field ] = (float) $row[ $field ];
            }
            $row['id']            = (int) $row['id'];
            $row['order_id']      = (int) $row['order_id'];
            $row['order_item_id'] = (int) $row['order_item_id'];
            $row['payout_id']     = (int) $row['payout_id'];
        }
        return array(
            'entries'     => $rows,
            'page'        => max( 1, $page ),
            'total'       => $total,
            'total_pages' => (int) ceil( $total / $per_page ),
        );
    }

    /**
     * Reconcile ledger rows for a seller's existing paid orders.
     *
     * The earning/fee rows are normally written by live WooCommerce hooks
     * (payment_complete / processing / completed). Orders created before the
     * plugin was active, imported, or created through a path that never fired
     * those hooks have no ledger rows, so the earnings tab reads empty. This
     * walks the seller's paid orders and (re)creates their rows. It is safe to
     * run repeatedly: create_order_rows() uses INSERT IGNORE against the
     * ledger's UNIQUE KEY (order_id, order_item_id, type), so existing rows are
     * never duplicated. Fully-refunded orders are intentionally excluded — a
     * reversed sale carries no earning, and create_order_rows() only writes
     * `type='earning'` rows, so backfilling one would create a spurious pending
     * earning with no offsetting reversal. No schema change is involved.
     *
     * @return array{orders:int,rows_before:int,rows_after:int}
     */
    public static function backfill_seller( int $seller_id ): array {
        global $wpdb;
        $seller_id = absint( $seller_id );
        $summary   = array( 'orders' => 0, 'rows_before' => 0, 'rows_after' => 0 );
        if ( $seller_id <= 0 ) {
            return $summary;
        }

        $table               = tnm_table( 'ledger' );
        $summary['rows_before'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE seller_id=%d", $seller_id ) );

        $orders = wc_get_orders(
            array(
                'limit'      => 500,
                'orderby'    => 'date',
                'order'      => 'DESC',
                'status'     => array( 'processing', 'completed' ),
                'return'     => 'objects',
                'meta_query' => array(
                    array(
                        'key'     => '_tnm_seller_ids',
                        'value'   => ',' . $seller_id . ',',
                        'compare' => 'LIKE',
                    ),
                ),
            )
        );

        foreach ( $orders as $order ) {
            if ( ! $order instanceof WC_Order || ! tnm_order_contains_seller( $order, $seller_id ) ) {
                continue;
            }
            TNM_Marketplace::stamp_order_sellers( $order );
            self::create_order_rows( $order );
            if ( $order->has_status( 'completed' ) ) {
                self::release_order_if_ready( $order );
            }
            $summary['orders']++;
        }

        $summary['rows_after'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE seller_id=%d", $seller_id ) );
        return $summary;
    }

    public static function reserve_available( int $seller_id, int $payout_id, float $requested_amount = 0 ): float|WP_Error {
        global $wpdb;
        $wpdb->query( 'START TRANSACTION' );
        try {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT id,net FROM ' . tnm_table( 'ledger' ) . " WHERE seller_id=%d AND status='available' AND payout_id=0 ORDER BY created_at ASC,id ASC FOR UPDATE",
                    $seller_id
                ),
                ARRAY_A
            );
            $selected = array();
            $total    = 0.0;
            foreach ( $rows as $row ) {
                $net = (float) $row['net'];
                if ( $net <= 0 ) {
                    $selected[] = (int) $row['id'];
                    $total += $net;
                    continue;
                }
                if ( $requested_amount > 0 && $total + $net > $requested_amount + 0.000001 ) {
                    continue;
                }
                $selected[] = (int) $row['id'];
                $total += $net;
            }
            if ( $total <= 0 || ! $selected ) {
                $wpdb->query( 'ROLLBACK' );
                return tnm_json_error( 'no_available_earnings', 'No positive available earnings could be reserved.', 409 );
            }
            $ids = implode( ',', array_map( 'absint', $selected ) );
            $wpdb->query(
                $wpdb->prepare(
                    'UPDATE ' . tnm_table( 'ledger' ) . " SET status='reserved',payout_id=%d,updated_at=%s WHERE id IN ($ids)",
                    $payout_id,
                    current_time( 'mysql', true )
                )
            );
            $wpdb->query( 'COMMIT' );
            return round( $total, wc_get_price_decimals() );
        } catch ( Throwable $throwable ) {
            $wpdb->query( 'ROLLBACK' );
            return tnm_json_error( 'reserve_failed', 'Could not reserve earnings for payout.', 500 );
        }
    }

    public static function mark_payout_paid( int $payout_id ): void {
        global $wpdb;
        $wpdb->update(
            tnm_table( 'ledger' ),
            array( 'status' => 'paid', 'updated_at' => current_time( 'mysql', true ) ),
            array( 'payout_id' => $payout_id, 'status' => 'reserved' ),
            array( '%s', '%s' ),
            array( '%d', '%s' )
        );
    }

    public static function release_payout_reservation( int $payout_id ): void {
        global $wpdb;
        $wpdb->update(
            tnm_table( 'ledger' ),
            array( 'status' => 'available', 'payout_id' => 0, 'updated_at' => current_time( 'mysql', true ) ),
            array( 'payout_id' => $payout_id, 'status' => 'reserved' ),
            array( '%s', '%d', '%s' ),
            array( '%d', '%s' )
        );
    }
}
