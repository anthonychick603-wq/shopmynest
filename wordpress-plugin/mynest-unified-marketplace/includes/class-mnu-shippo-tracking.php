<?php
/**
 * MNU Shippo Tracking Webhook Handler
 *
 * v3.7.122.7 — Handles the Shippo `track_updated` webhook so that the
 * seller_status doesn't flip to 'shipped' at label creation. Instead, the
 * carrier's first physical scan is what marks the order shipped, and the
 * delivery scan is what marks it delivered.
 *
 * Product rule (from Anthony, ShopMyNest founder):
 *   "Shipping labels should be bought when the order is placed. The order
 *   status 'shipped' should not update until the carrier scans in the
 *   package."
 *
 * Flow:
 *   1. Label auto-buy (MNU_Auto_Label) fires on payment success, calls
 *      mnu_shipping_labels_buy_labels_for_seller_order(), which stamps
 *      `_tnm_seller_label_at_{seller}` — but no longer flips seller_status.
 *   2. Shippo automatically tracks any label bought through Shippo — no
 *      per-label /tracks/ POST needed. Shippo just needs one webhook of
 *      type `Track Updated` registered to point at our REST endpoint.
 *   3. When the carrier scans (first TRANSIT event), Shippo POSTs the
 *      payload here. We look up the order by transaction id, flip
 *      seller_status to 'shipped', stamp `_tnm_seller_shipped_at_{seller}`,
 *      notify the buyer.
 *   4. On DELIVERED event, we stamp `_tnm_seller_delivered_at_{seller}`
 *      and notify the buyer. seller_status stays 'shipped' unless the
 *      seller manually completes the order.
 *
 * Idempotency:
 *   Each transition writes a "notified" marker meta so that repeated
 *   webhook deliveries (Shippo retries, duplicate events) don't spam
 *   the buyer or re-write timestamps.
 *
 * Security:
 *   Shippo track_updated webhooks are not signed. We accept unauthenticated
 *   POSTs but only act on payloads where we can find a matching order by
 *   transaction id. Unknown transactions are silently 200-acked to avoid
 *   Shippo retry storms. To harden further, admins can set a shared secret
 *   in the webhook URL query string; we check it if present.
 *
 * Endpoint: POST /wp-json/nest-native/v1/shippo-track-webhook
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MNU_Shippo_Tracking {

    public const NS               = 'nest-native/v1';
    public const ROUTE            = '/shippo-track-webhook';
    public const OPTION_SECRET    = 'mnu_shippo_track_secret';
    public const OPTION_ENABLED   = 'mnu_shippo_track_enabled';
    public const META_TRANSIT_AT  = '_tnm_seller_shipped_at';
    public const META_DELIVER_AT  = '_tnm_seller_delivered_at';
    public const META_LABEL_AT    = '_tnm_seller_label_at';
    public const META_TRACK_STATE = '_mnu_scan_last_state';   // per-seller cache of last processed status

    public static function init(): void {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
    }

    public static function register_routes(): void {
        register_rest_route(
            self::NS,
            self::ROUTE,
            array(
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => array( __CLASS__, 'handle_webhook' ),
                'permission_callback' => '__return_true',
            )
        );
    }

    /**
     * Handle a Shippo track_updated payload.
     *
     * Expected shape (from docs.goshippo.com/docs/tracking/tracking/):
     *   {
     *     "carrier": "usps",
     *     "tracking_number": "...",
     *     "transaction": "<shippo transaction object_id>",
     *     "tracking_status": {
     *       "status": "PRE_TRANSIT" | "TRANSIT" | "DELIVERED" | "RETURNED" | "FAILURE" | "UNKNOWN",
     *       "status_details": "...",
     *       "status_date": "2026-08-20T13:03:00Z",
     *       ...
     *     },
     *     "tracking_history": [...]
     *   }
     */
    public static function handle_webhook( \WP_REST_Request $req ) {
        // Optional shared-secret check via ?secret= query param
        $configured_secret = (string) get_option( self::OPTION_SECRET, '' );
        if ( $configured_secret ) {
            $provided = (string) $req->get_param( 'secret' );
            if ( ! hash_equals( $configured_secret, $provided ) ) {
                return new \WP_REST_Response( array( 'ok' => false, 'error' => 'bad_secret' ), 401 );
            }
        }

        $payload = $req->get_json_params();
        if ( ! is_array( $payload ) ) {
            $payload = $req->get_params();
        }

        $carrier         = sanitize_text_field( (string) ( $payload['carrier'] ?? '' ) );
        $tracking_number = sanitize_text_field( (string) ( $payload['tracking_number'] ?? '' ) );
        $transaction_id  = sanitize_text_field( (string) ( $payload['transaction'] ?? '' ) );
        $status_block    = isset( $payload['tracking_status'] ) && is_array( $payload['tracking_status'] ) ? $payload['tracking_status'] : array();
        $status          = strtoupper( sanitize_text_field( (string) ( $status_block['status'] ?? '' ) ) );
        $status_date     = sanitize_text_field( (string) ( $status_block['status_date'] ?? '' ) );
        $status_details  = sanitize_text_field( (string) ( $status_block['status_details'] ?? '' ) );

        // Silently 200 for empty / test / probe requests so Shippo doesn't retry.
        if ( ! $status || ( ! $transaction_id && ! $tracking_number ) ) {
            return new \WP_REST_Response( array( 'ok' => true, 'ignored' => 'no_lookup_key' ), 200 );
        }

        // Locate the order + seller. Prefer transaction id (unique per label);
        // fall back to tracking number if the payload omits transaction.
        $match = self::find_order_by_transaction_or_tracking( $transaction_id, $tracking_number );
        if ( ! $match ) {
            // Unknown label — could be a stale Shippo test or a label bought
            // outside this plugin. Ack with 200 so Shippo doesn't retry.
            return new \WP_REST_Response( array( 'ok' => true, 'ignored' => 'no_order_match' ), 200 );
        }

        $order     = $match['order'];
        $seller_id = (int) $match['seller_id'];
        $suffix    = '_' . $seller_id;

        // Cache the raw status for debugging + short-circuit dedup on identical repeats.
        $last_state = (string) $order->get_meta( self::META_TRACK_STATE . $suffix, true );
        if ( $last_state === $status ) {
            // Same status as last processed event — nothing new to do.
            return new \WP_REST_Response( array( 'ok' => true, 'ignored' => 'dup_state', 'status' => $status ), 200 );
        }
        $order->update_meta_data( self::META_TRACK_STATE . $suffix, $status );

        $now_gmt = current_time( 'mysql', true );

        switch ( $status ) {
            case 'TRANSIT':
                // First real scan. Flip status to shipped + notify buyer.
                self::mark_shipped( $order, $seller_id, $suffix, $tracking_number, $status_details, $status_date, $now_gmt );
                break;

            case 'DELIVERED':
                // If somehow we skipped straight to DELIVERED (shipper drop-off
                // scan combined with delivery scan for local mail), still stamp
                // the shipped timestamp so the audit trail is consistent.
                if ( ! $order->get_meta( self::META_TRANSIT_AT . $suffix, true ) ) {
                    self::mark_shipped( $order, $seller_id, $suffix, $tracking_number, $status_details, $status_date, $now_gmt );
                }
                self::mark_delivered( $order, $seller_id, $suffix, $tracking_number, $status_details, $status_date, $now_gmt );
                break;

            case 'RETURNED':
            case 'FAILURE':
                // Add an order note so seller sees the exception, but don't
                // auto-refund or change status — human decides.
                $order->add_order_note( sprintf( '[Shippo Track] %s — %s (tracking %s)', $status, $status_details, $tracking_number ) );
                break;

            case 'PRE_TRANSIT':
            case 'UNKNOWN':
            default:
                // No-op. Label exists but carrier hasn't scanned yet.
                break;
        }

        $order->save();

        return new \WP_REST_Response(
            array(
                'ok'         => true,
                'order_id'   => $order->get_id(),
                'seller_id'  => $seller_id,
                'status'     => $status,
                'processed'  => $now_gmt,
            ),
            200
        );
    }

    /**
     * Flip seller status to shipped, stamp timestamp, notify buyer.
     * Idempotent — only fires if `_tnm_seller_shipped_at_{seller}` is empty.
     */
    protected static function mark_shipped( \WC_Order $order, int $seller_id, string $suffix, string $tracking_number, string $details, string $status_date, string $now_gmt ): void {
        if ( $order->get_meta( self::META_TRANSIT_AT . $suffix, true ) ) {
            return; // already stamped, don't re-notify
        }

        $order->update_meta_data( '_tnm_seller_status_' . $seller_id, 'shipped' );
        $order->update_meta_data( self::META_TRANSIT_AT . $suffix, $now_gmt );
        if ( $status_date ) {
            $order->update_meta_data( '_tnm_seller_scan_at' . $suffix, $status_date );
        }

        $order->add_order_note( sprintf( '[Shippo Track] TRANSIT — %s (tracking %s). Marked shipped by carrier scan.', $details ?: 'Package accepted', $tracking_number ) );

        $customer_id = (int) $order->get_customer_id();
        if ( $customer_id ) {
            if ( function_exists( 'tnm_notify' ) ) {
                tnm_notify(
                    $customer_id,
                    $seller_id,
                    'order_shipped',
                    'Order #' . $order->get_order_number() . ' shipped',
                    $tracking_number ? 'The carrier has scanned your package. Tracking: ' . $tracking_number : 'The carrier has scanned your package.',
                    $order->get_id(),
                    'shop_order',
                    $order->get_view_order_url()
                );
            }
            if ( class_exists( 'MNU_Ops' ) ) {
                \MNU_Ops::notify_user(
                    $customer_id,
                    'Your order shipped',
                    'The carrier scanned MyNest order #' . $order->get_order_number() . '. It\'s on its way.',
                    array(
                        'type'      => 'order_shipped',
                        'category'  => 'orders',
                        'order_id'  => $order->get_id(),
                        'seller_id' => $seller_id,
                    )
                );
            }
        }
    }

    /**
     * Stamp delivered + notify buyer. Idempotent via delivered_at meta.
     * seller_status stays 'shipped' — seller decides when to mark completed.
     */
    protected static function mark_delivered( \WC_Order $order, int $seller_id, string $suffix, string $tracking_number, string $details, string $status_date, string $now_gmt ): void {
        if ( $order->get_meta( self::META_DELIVER_AT . $suffix, true ) ) {
            return;
        }

        $order->update_meta_data( self::META_DELIVER_AT . $suffix, $now_gmt );
        if ( $status_date ) {
            $order->update_meta_data( '_tnm_seller_delivered_scan_at' . $suffix, $status_date );
        }

        $order->add_order_note( sprintf( '[Shippo Track] DELIVERED — %s (tracking %s).', $details ?: 'Package delivered', $tracking_number ) );

        $customer_id = (int) $order->get_customer_id();
        if ( $customer_id ) {
            if ( function_exists( 'tnm_notify' ) ) {
                tnm_notify(
                    $customer_id,
                    $seller_id,
                    'order_delivered',
                    'Order #' . $order->get_order_number() . ' delivered',
                    $tracking_number ? 'Your package was delivered. Tracking: ' . $tracking_number : 'Your package was delivered.',
                    $order->get_id(),
                    'shop_order',
                    $order->get_view_order_url()
                );
            }
            if ( class_exists( 'MNU_Ops' ) ) {
                \MNU_Ops::notify_user(
                    $customer_id,
                    'Your order was delivered',
                    'MyNest order #' . $order->get_order_number() . ' was delivered by the carrier.',
                    array(
                        'type'      => 'order_delivered',
                        'category'  => 'orders',
                        'order_id'  => $order->get_id(),
                        'seller_id' => $seller_id,
                    )
                );
            }
        }
    }

    /**
     * Find the WC_Order + seller_id whose `_thenest_shippo_transaction_{seller}`
     * meta matches the payload's transaction id. Falls back to
     * `_thenest_tracking_number_{seller}` if transaction lookup misses.
     * Returns null if no match found.
     */
    protected static function find_order_by_transaction_or_tracking( string $transaction_id, string $tracking_number ): ?array {
        global $wpdb;

        // HPOS-aware query — hit the WooCommerce order meta table if available,
        // else fall back to postmeta.
        $orders_meta = $wpdb->prefix . 'wc_orders_meta';
        $has_hpos    = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $orders_meta ) ) === $orders_meta );

        $meta_keys_by_transaction = array( '_thenest_shippo_transaction', '_thenest_label_transaction' );
        $meta_keys_by_tracking    = array( '_thenest_tracking_number' );

        // Try transaction-based lookup first.
        if ( $transaction_id ) {
            $row = self::lookup_order_row( $has_hpos, $orders_meta, $meta_keys_by_transaction, $transaction_id );
            if ( $row ) {
                $order = wc_get_order( (int) $row['order_id'] );
                if ( $order ) {
                    return array( 'order' => $order, 'seller_id' => (int) $row['seller_id'] );
                }
            }
        }

        // Fallback: tracking number.
        if ( $tracking_number ) {
            $row = self::lookup_order_row( $has_hpos, $orders_meta, $meta_keys_by_tracking, $tracking_number );
            if ( $row ) {
                $order = wc_get_order( (int) $row['order_id'] );
                if ( $order ) {
                    return array( 'order' => $order, 'seller_id' => (int) $row['seller_id'] );
                }
            }
        }

        return null;
    }

    /**
     * Look up an order row whose meta_key matches one of the base names
     * (with a trailing `_<seller_id>` suffix) and whose meta_value equals
     * $needle. Returns array{order_id:int, seller_id:int} or null.
     *
     * We use REGEXP so we can extract the seller_id from the suffixed key
     * name in a single query.
     */
    protected static function lookup_order_row( bool $has_hpos, string $orders_meta, array $base_keys, string $needle ): ?array {
        global $wpdb;

        // Build a LIKE clause per base key: base + '_%'
        $like_clauses = array();
        $params       = array();
        foreach ( $base_keys as $base ) {
            $like_clauses[] = 'meta_key LIKE %s';
            $params[]        = $wpdb->esc_like( $base ) . '\\_%';
        }
        $like_sql = '(' . implode( ' OR ', $like_clauses ) . ')';

        if ( $has_hpos ) {
            $params[] = $needle;
            $sql = "SELECT order_id, meta_key FROM {$orders_meta} WHERE {$like_sql} AND meta_value = %s LIMIT 1";
        } else {
            $params[] = $needle;
            $sql = "SELECT post_id AS order_id, meta_key FROM {$wpdb->postmeta} WHERE {$like_sql} AND meta_value = %s LIMIT 1";
        }

        $row = $wpdb->get_row( $wpdb->prepare( $sql, ...$params ), ARRAY_A );
        if ( ! $row ) {
            return null;
        }

        // Extract trailing seller id from meta_key
        if ( preg_match( '/_(\d+)$/', (string) $row['meta_key'], $m ) ) {
            return array(
                'order_id'  => (int) $row['order_id'],
                'seller_id' => (int) $m[1],
            );
        }
        return null;
    }
}

MNU_Shippo_Tracking::init();
