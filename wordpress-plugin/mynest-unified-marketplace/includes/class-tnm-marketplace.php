<?php

defined( 'ABSPATH' ) || exit;

final class TNM_Marketplace {
    public static function init(): void {
        add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'stamp_order_item' ), 10, 4 );
        add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'stamp_order_sellers' ) );
        add_action( 'woocommerce_order_item_meta_end', array( __CLASS__, 'render_item_breakdown' ), 10, 4 );
        add_action( 'woocommerce_after_order_itemmeta', array( __CLASS__, 'render_admin_item_breakdown' ), 10, 3 );
        add_action( 'woocommerce_order_details_after_order_table', array( __CLASS__, 'render_buyer_order_breakdown' ), 20, 1 );
        add_action( 'woocommerce_email_after_order_table', array( __CLASS__, 'render_email_order_breakdown' ), 20, 4 );
        add_filter( 'woocommerce_get_order_item_totals', array( __CLASS__, 'add_order_total_rows' ), 30, 3 );
        add_action( 'woocommerce_admin_order_data_after_order_details', array( __CLASS__, 'render_admin_order_breakdown' ), 20, 1 );
        add_filter( 'woocommerce_hidden_order_itemmeta', array( __CLASS__, 'hide_internal_item_meta' ) );
        add_filter( 'woocommerce_product_get_price', array( __CLASS__, 'normalize_price' ), 20, 2 );
        add_filter( 'woocommerce_product_get_regular_price', array( __CLASS__, 'normalize_price' ), 20, 2 );
        add_filter( 'map_meta_cap', array( __CLASS__, 'protect_products' ), 20, 4 );
        // Sellers hold edit_products/publish_products, so the generic product REST
        // controllers would otherwise let a vendor create a listing without going
        // through create_product() and its Stripe check.
        add_filter( 'woocommerce_rest_check_permissions', array( __CLASS__, 'block_wc_rest_listing' ), 20, 4 );
        add_filter( 'rest_pre_insert_product', array( __CLASS__, 'block_core_rest_listing' ), 10, 2 );
        add_action( 'save_post_product', array( __CLASS__, 'stamp_product_seller' ), 20, 3 );
        add_action( 'template_redirect', array( __CLASS__, 'redirect_seller_admin' ) );
        add_action( 'admin_init', array( __CLASS__, 'redirect_seller_wp_admin' ) );
        add_filter( 'show_admin_bar', array( __CLASS__, 'hide_seller_admin_bar' ) );
        add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'admin_product_seller_field' ) );
        add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save_admin_product_seller' ) );
        add_filter( 'woocommerce_product_query_meta_query', array( __CLASS__, 'exclude_unapproved_seller_products' ), 10, 2 );
        add_action( 'transition_post_status', array( __CLASS__, 'notify_product_followers' ), 10, 3 );
    }

    public static function stamp_order_item( WC_Order_Item_Product $item, string $cart_item_key, array $values, WC_Order $order ): void {
        $product = $values['data'] ?? null;
        self::stamp_item_snapshot( $item, $product, $order );
    }

    /**
     * Store the seller and fee snapshot directly on an order item.
     * This is used by both WooCommerce checkout and the native app checkout.
     *
     * v3.7.53 hardens seller resolution against WooCommerce cart-cache
     * staleness. The cart snapshots the WC_Product object at add-to-cart
     * time, so if `_tnm_seller_id` was added or changed between add-to-cart
     * and checkout, the cached product's meta would be stale and the item
     * would be stamped with the wrong seller. We now:
     *   1. Re-resolve seller from `wp_postmeta` directly by product id,
     *      bypassing WC_Product's meta cache.
     *   2. Compare against the cart-cached product's seller and record a
     *      `_tnm_seller_resolution_diag` note on the item when they differ.
     *   3. Record when the resolution fell through to `post_author` so
     *      migrations / mis-imported catalog entries are visible.
     *
     * @param mixed         $item    Expected WC_Order_Item_Product.
     * @param mixed         $product Expected WC_Product (may have stale meta).
     * @param WC_Order|null $order   Order for diagnostic notes; optional.
     */
    public static function stamp_item_snapshot( $item, $product, $order = null ): void {
        if ( ! $item instanceof WC_Order_Item_Product || ! $product instanceof WC_Product ) {
            return;
        }

        $product_id = (int) $item->get_product_id();
        if ( $product_id <= 0 ) {
            $product_id = (int) $product->get_id();
        }

        list( $seller_id, $source ) = self::resolve_product_seller_fresh( $product_id, $product );
        if ( $seller_id <= 0 ) {
            return;
        }

        // Diagnostic: compare the fresh resolution to what the cart-cached
        // product object would have returned. If they differ, the cart had
        // stale meta and we just corrected it — record so ops can see it.
        $cached_seller = tnm_get_product_seller_id( $product );
        if ( $cached_seller > 0 && $cached_seller !== $seller_id ) {
            $item->update_meta_data(
                '_tnm_seller_resolution_diag',
                sprintf( 'stale cart snapshot corrected: cached=%d fresh=%d source=%s', $cached_seller, $seller_id, $source )
            );
            if ( $order instanceof WC_Order ) {
                $order->add_order_note(
                    sprintf(
                        'v3.7.53 diagnostic: product #%d cart-cached seller was %d but fresh DB meta says %d (source: %s). Line item was stamped with the fresh value.',
                        $product_id,
                        $cached_seller,
                        $seller_id,
                        $source
                    )
                );
            }
        }

        // Diagnostic: fell through to post_author fallback — no meta was
        // ever set. This should never happen once catalog is fully migrated.
        if ( 'post_author' === $source && $order instanceof WC_Order ) {
            $order->add_order_note(
                sprintf(
                    'v3.7.53 diagnostic: product #%d has no _tnm_seller_id meta; resolved seller %d via post_author fallback. Consider stamping meta on this product.',
                    $product_id,
                    $seller_id
                )
            );
        }

        $gross = max( 0, (float) $item->get_total() );
        $fee   = round( $gross * ( tnm_fee_percent() / 100 ), wc_get_price_decimals() + 2 );
        $item->update_meta_data( '_tnm_seller_id', $seller_id );
        $item->update_meta_data( '_mynest_seller_id', $seller_id );
        $item->update_meta_data( '_tnm_store_name', tnm_seller_display_name( $seller_id ) );
        $item->update_meta_data( '_tnm_fee_percent', tnm_fee_percent() );
        $item->update_meta_data( '_tnm_platform_fee', $fee );
        $item->update_meta_data( '_mynest_nestkeeper_fee', $fee );
        $item->update_meta_data( '_tnm_seller_net_before_shipping', max( 0, $gross - $fee ) );
    }

    /**
     * Resolve a product's seller by reading `wp_postmeta` directly, so we
     * bypass any stale WC_Product / cart / persistent object-cache layer.
     *
     * Returns [ seller_id, source ] where source is one of:
     *   '_tnm_seller_id', '_mynest_seller_id', '_wcv_vendor_id',
     *   '_dokan_vendor_id', 'post_author', or '' when nothing resolves.
     *
     * @return array{0:int,1:string}
     */
    public static function resolve_product_seller_fresh( int $product_id, $product = null ): array {
        if ( $product_id <= 0 ) {
            return array( 0, '' );
        }
        foreach ( array( '_tnm_seller_id', '_mynest_seller_id', '_wcv_vendor_id', '_dokan_vendor_id' ) as $meta_key ) {
            $seller_id = (int) get_post_meta( $product_id, $meta_key, true );
            if ( $seller_id > 0 ) {
                return array( $seller_id, $meta_key );
            }
        }
        $author_id = (int) get_post_field( 'post_author', $product_id );
        if ( $author_id > 0 ) {
            return array( $author_id, 'post_author' );
        }
        return array( 0, '' );
    }

    public static function stamp_order_sellers( WC_Order $order ): void {
        $seller_ids   = array();
        $product_ids  = array();
        foreach ( $order->get_items() as $item ) {
            if ( ! $item instanceof WC_Order_Item_Product ) {
                continue;
            }
            $seller_id = tnm_get_order_item_seller_id( $item );
            if ( $seller_id ) {
                $seller_ids[ $seller_id ] = true;
            }
            $pid = (int) $item->get_product_id();
            if ( $pid > 0 ) {
                $product_ids[] = $pid;
            }
        }

        // v3.7.53 diagnostic: recompute distinct sellers directly from DB
        // meta for every product in the order. If the DB says the order
        // spans more sellers than the stamped items indicate, seller
        // resolution collapsed somewhere upstream (cart cache, wrong meta,
        // etc.) — flag it so the multi-seller transfer path can be audited.
        $fresh_sellers = array();
        foreach ( $product_ids as $pid ) {
            list( $fresh_id ) = self::resolve_product_seller_fresh( $pid );
            if ( $fresh_id > 0 ) {
                $fresh_sellers[ $fresh_id ] = true;
            }
        }
        if ( count( $fresh_sellers ) > count( $seller_ids ) ) {
            $order->add_order_note(
                sprintf(
                    'v3.7.53 diagnostic: order stamped with %d seller(s) [%s] but products actually span %d seller(s) [%s]. Multi-seller transfer path may not have fired.',
                    count( $seller_ids ),
                    implode( ',', array_keys( $seller_ids ) ),
                    count( $fresh_sellers ),
                    implode( ',', array_keys( $fresh_sellers ) )
                )
            );
            $order->update_meta_data( '_tnm_seller_resolution_collapsed', 1 );
        }

        if ( $seller_ids ) {
            $order->update_meta_data( '_tnm_seller_ids', ',' . implode( ',', array_map( 'absint', array_keys( $seller_ids ) ) ) . ',' );
            $order->save();
        }
    }

    /**
     * Renders the seller fee breakdown after an order item's public metadata.
     *
     * WooCommerce passes the order object as the third argument to
     * `woocommerce_order_item_meta_end`. Some templates pass a fourth
     * `$plain_text` argument while others may omit it, so the callback keeps
     * the last two parameters deliberately flexible and validates the item
     * before using it.
     *
     * @param mixed $item_id    Order item ID supplied by WooCommerce.
     * @param mixed $item       Expected WC_Order_Item_Product instance.
     * @param mixed $order      WC_Order (or a compatible WooCommerce override).
     * @param mixed $plain_text Whether the output is for a plain-text email.
     */
    public static function render_item_breakdown( $item_id, $item, $order = null, $plain_text = false ): void {
        if ( ! $item instanceof WC_Order_Item_Product ) {
            return;
        }

        $plain_text = (bool) $plain_text;
        $seller_id  = tnm_get_order_item_seller_id( $item );
        if ( ! $seller_id ) {
            return;
        }
        if ( ! tnm_current_user_can_manage_seller( $seller_id ) ) {
            return;
        }
        $fee   = (float) $item->get_meta( '_tnm_platform_fee', true );
        $gross = (float) $item->get_total();
        $net   = max( 0, $gross - $fee );
        if ( $plain_text ) {
            echo "\n" . tnm_fee_label() . ': -' . tnm_money( $fee ) . "\nSeller net before shipping: " . tnm_money( $net );
            return;
        }
        echo '<div class="tnm-order-item-breakdown"><small><strong>' . esc_html( tnm_fee_label() ) . ':</strong> -' . wp_kses_post( wc_price( $fee ) ) . '<br><strong>Seller net before shipping:</strong> ' . wp_kses_post( wc_price( $net ) ) . '</small></div>';
    }

    public static function hide_internal_item_meta( array $hidden ): array {
        return array_merge(
            $hidden,
            array(
                '_tnm_seller_id',
                '_tnm_store_name',
                '_tnm_fee_percent',
                '_tnm_platform_fee',
                '_tnm_seller_net_before_shipping',
            )
        );
    }

    public static function normalize_price( mixed $price, WC_Product $product ): mixed {
        return '' === $price ? $price : wc_format_decimal( $price );
    }

    public static function protect_products( array $caps, string $cap, int $user_id, array $args ): array {
        if ( ! in_array( $cap, array( 'edit_post', 'delete_post', 'read_post' ), true ) || empty( $args[0] ) ) {
            return $caps;
        }
        $post = get_post( (int) $args[0] );
        if ( ! $post || 'product' !== $post->post_type || ! tnm_is_seller( $user_id ) || tnm_is_admin_or_manager( $user_id ) ) {
            return $caps;
        }
        $product = wc_get_product( $post->ID );
        if ( ! $product || tnm_get_product_seller_id( $product ) !== $user_id ) {
            return array( 'do_not_allow' );
        }
        return $caps;
    }

    /**
     * @param mixed $permission
     * @return mixed
     */
    public static function block_wc_rest_listing( $permission, string $context, int $object_id, string $post_type ) {
        if ( true !== $permission || 'create' !== $context || 'product' !== $post_type ) {
            return $permission;
        }
        return tnm_seller_listing_blocked() ? false : $permission;
    }

    /**
     * @param mixed $prepared
     * @return mixed
     */
    public static function block_core_rest_listing( $prepared, WP_REST_Request $request ) {
        // Set only when an existing product is being updated; edits stay allowed,
        // matching update_product(), which blocks the publish transition instead.
        if ( ! empty( $request['id'] ) || ! tnm_seller_listing_blocked() ) {
            return $prepared;
        }
        return tnm_json_error( 'bank_account_required', tnm_seller_listing_blocked_message(), 403 );
    }

    public static function stamp_product_seller( int $post_id, WP_Post $post, bool $update ): void {
        if ( wp_is_post_revision( $post_id ) || 'product' !== $post->post_type ) {
            return;
        }
        $seller_id = (int) get_post_meta( $post_id, '_tnm_seller_id', true );
        if ( ! $seller_id ) {
            $seller_id = (int) $post->post_author;
            update_post_meta( $post_id, '_tnm_seller_id', $seller_id );
            update_post_meta( $post_id, '_mynest_seller_id', $seller_id );
        }
    }

    public static function redirect_seller_admin(): void {
        // Reserved for front-end route handling.
    }

    public static function redirect_seller_wp_admin(): void {
        if ( ! tnm_is_seller() || wp_doing_ajax() || defined( 'REST_REQUEST' ) || current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( $screen && in_array( $screen->base, array( 'profile', 'user-edit', 'upload' ), true ) ) {
            return;
        }
        wp_safe_redirect( tnm_page_url( 'seller_dashboard', home_url( '/' ) ) );
        exit;
    }

    public static function hide_seller_admin_bar( bool $show ): bool {
        return tnm_is_seller() && ! current_user_can( 'manage_woocommerce' ) ? false : $show;
    }

    public static function admin_product_seller_field(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        $sellers = get_users( array( 'role__in' => array( 'tnm_seller', 'mynest_seller' ), 'orderby' => 'display_name' ) );
        $options = array( '' => __( 'Use product author', 'the-nest-marketplace' ) );
        foreach ( $sellers as $seller ) {
            $options[ $seller->ID ] = tnm_seller_display_name( $seller->ID ) . ' — ' . $seller->user_email;
        }
        woocommerce_wp_select(
            array(
                'id'          => '_tnm_seller_id',
                'label'       => __( 'The Nest seller', 'the-nest-marketplace' ),
                'description' => __( 'Assigns earnings and order visibility to this seller.', 'the-nest-marketplace' ),
                'desc_tip'    => true,
                'options'     => $options,
            )
        );
    }

    public static function save_admin_product_seller( int $product_id ): void {
        if ( ! current_user_can( 'manage_woocommerce' ) || ! isset( $_POST['_tnm_seller_id'] ) ) {
            return;
        }
        $seller_id = absint( wp_unslash( $_POST['_tnm_seller_id'] ) );
        if ( $seller_id && tnm_is_seller( $seller_id ) ) {
            update_post_meta( $product_id, '_tnm_seller_id', $seller_id );
            update_post_meta( $product_id, '_mynest_seller_id', $seller_id );
            wp_update_post( array( 'ID' => $product_id, 'post_author' => $seller_id ) );
        } else {
            delete_post_meta( $product_id, '_tnm_seller_id' );
            delete_post_meta( $product_id, '_mynest_seller_id' );
        }
    }

    public static function exclude_unapproved_seller_products( array $meta_query, WC_Query $query ): array {
        return $meta_query;
    }

    public static function notify_product_followers( string $new_status, string $old_status, WP_Post $post ): void {
        if ( 'product' !== $post->post_type || 'publish' !== $new_status || 'publish' === $old_status ) {
            return;
        }
        $seller_id = tnm_get_product_seller_id( $post->ID );
        if ( ! $seller_id ) {
            return;
        }
        global $wpdb;
        $followers = $wpdb->get_col(
            $wpdb->prepare(
                'SELECT follower_id FROM ' . tnm_table( 'follows' ) . ' WHERE following_id = %d',
                $seller_id
            )
        );
        foreach ( $followers as $follower_id ) {
            tnm_notify(
                (int) $follower_id,
                $seller_id,
                'new_product',
                sprintf( '%s listed something new', tnm_seller_display_name( $seller_id ) ),
                get_the_title( $post ),
                $post->ID,
                'product',
                get_permalink( $post )
            );
        }
    }

    /**
     * Adds the private seller math to the admin order-item editor.
     * The callback is deliberately untyped because WooCommerce admin screens
     * and extensions can supply compatible override objects.
     */
    public static function render_admin_item_breakdown( $item_id, $item, $product = null ): void {
        if ( ! is_admin() || ! current_user_can( 'manage_woocommerce' ) || ! $item instanceof WC_Order_Item_Product ) {
            return;
        }
        $seller_id = tnm_get_order_item_seller_id( $item );
        if ( ! $seller_id ) {
            return;
        }
        $gross = (float) $item->get_total();
        $fee   = (float) $item->get_meta( '_tnm_platform_fee', true );
        $net   = max( 0, $gross - $fee );
        echo '<div class="tnm-admin-item-breakdown" style="margin:8px 0;padding:8px 10px;border-left:3px solid #8b5e3c;background:#fffaf5">';
        echo '<strong>' . esc_html( tnm_seller_display_name( $seller_id ) ) . '</strong><br>';
        echo esc_html( tnm_fee_label() ) . ': ' . wp_kses_post( wc_price( $fee ) ) . '<br>';
        echo 'Seller net before shipping: ' . wp_kses_post( wc_price( $net ) );
        echo '</div>';
    }

    public static function order_breakdown_data( WC_Order $order, int $seller_id = 0 ): array {
        $data = array(
            'item_subtotal' => 0.0,
            'discount'      => max( 0, (float) $order->get_discount_total() ),
            'shipping'      => max( 0, (float) $order->get_shipping_total() ),
            'tax'           => max( 0, (float) $order->get_total_tax() ),
            'total'         => max( 0, (float) $order->get_total() ),
            'seller_gross'  => 0.0,
            'platform_fee'  => 0.0,
            'seller_net'    => 0.0,
            'sellers'       => array(),
        );

        foreach ( $order->get_items( 'line_item' ) as $item ) {
            if ( ! $item instanceof WC_Order_Item_Product ) {
                continue;
            }
            $item_seller = tnm_get_order_item_seller_id( $item );
            $line_gross  = max( 0, (float) $item->get_total() );
            $line_fee    = (float) $item->get_meta( '_tnm_platform_fee', true );
            if ( '' === $item->get_meta( '_tnm_platform_fee', true ) ) {
                $line_fee = round( $line_gross * ( tnm_fee_percent() / 100 ), wc_get_price_decimals() + 2 );
            }
            $data['item_subtotal'] += max( 0, (float) $item->get_subtotal() );
            if ( $seller_id && $seller_id !== $item_seller ) {
                continue;
            }
            $data['seller_gross'] += $line_gross;
            $data['platform_fee'] += $line_fee;
            if ( $item_seller ) {
                if ( ! isset( $data['sellers'][ $item_seller ] ) ) {
                    $data['sellers'][ $item_seller ] = array(
                        'id'       => $item_seller,
                        'name'     => tnm_seller_display_name( $item_seller ),
                        'gross'    => 0.0,
                        'fee'      => 0.0,
                        'tracking' => (string) $order->get_meta( '_tnm_tracking_' . $item_seller, true ),
                        'status'   => (string) ( $order->get_meta( '_tnm_seller_status_' . $item_seller, true ) ?: 'processing' ),
                    );
                }
                $data['sellers'][ $item_seller ]['gross'] += $line_gross;
                $data['sellers'][ $item_seller ]['fee']   += $line_fee;
            }
        }
        $data['seller_net'] = max( 0, $data['seller_gross'] - $data['platform_fee'] );
        return $data;
    }

    /**
     * Render one order-breakdown row with an explicit mobile label.
     * WooCommerce responsive tables hide table headers on small screens and
     * read the replacement label from the amount cell's data-title attribute.
     */
    private static function render_breakdown_row( string $label, string $value_html, bool $is_total = false ): void {
        $class = $is_total ? ' class="tnm-breakdown-total"' : '';
        echo '<tr' . $class . '><th scope="row">' . esc_html( $label ) . '</th><td data-title="' . esc_attr( $label ) . '" aria-label="' . esc_attr( $label ) . '">' . wp_kses_post( $value_html ) . '</td></tr>';
    }

    private static function breakdown_html( WC_Order $order, string $context = 'buyer', int $seller_id = 0 ): string {
        $data      = self::order_breakdown_data( $order, $seller_id );
        $show_fee  = 'admin' === $context || 'seller' === $context || 'yes' === tnm_get_option( 'buyer_sees_seller_fee', 'no' );
        $currency  = $order->get_currency();
        $title     = 'seller' === $context ? 'Your order earnings' : 'Order breakdown';
        ob_start();
        echo '<section class="tnm-card tnm-order-breakdown"><h2>' . esc_html( $title ) . '</h2><table class="shop_table shop_table_responsive">';
        if ( 'seller' === $context ) {
            self::render_breakdown_row( 'Gross item sales', wp_kses_post( wc_price( $data['seller_gross'], array( 'currency' => $currency ) ) ) );
            self::render_breakdown_row( tnm_fee_label(), '-' . wp_kses_post( wc_price( $data['platform_fee'], array( 'currency' => $currency ) ) ) );
            self::render_breakdown_row( 'Net before shipping', '<strong>' . wp_kses_post( wc_price( $data['seller_net'], array( 'currency' => $currency ) ) ) . '</strong>', true );
        } else {
            self::render_breakdown_row( 'Items before discounts', wp_kses_post( wc_price( $data['item_subtotal'], array( 'currency' => $currency ) ) ) );
            if ( $data['discount'] > 0 ) {
                self::render_breakdown_row( 'Discounts', '-' . wp_kses_post( wc_price( $data['discount'], array( 'currency' => $currency ) ) ) );
            }
            self::render_breakdown_row( 'Shipping', wp_kses_post( wc_price( $data['shipping'], array( 'currency' => $currency ) ) ) );
            self::render_breakdown_row( 'Tax', wp_kses_post( wc_price( $data['tax'], array( 'currency' => $currency ) ) ) );
            if ( $show_fee && $data['platform_fee'] > 0 ) {
                self::render_breakdown_row( tnm_fee_label(), wp_kses_post( wc_price( $data['platform_fee'], array( 'currency' => $currency ) ) ) );
            }
            self::render_breakdown_row( 'Total', '<strong>' . wp_kses_post( wc_price( $data['total'], array( 'currency' => $currency ) ) ) . '</strong>', true );
        }
        echo '</table>';

        if ( 'seller' !== $context && $data['sellers'] ) {
            echo '<h3>Fulfillment</h3><ul class="tnm-fulfillment-list">';
            foreach ( $data['sellers'] as $seller ) {
                echo '<li><strong>' . esc_html( $seller['name'] ) . '</strong>: ' . esc_html( ucfirst( $seller['status'] ) );
                if ( $seller['tracking'] ) {
                    echo ' — Tracking: ' . esc_html( $seller['tracking'] );
                }
                echo '</li>';
            }
            echo '</ul>';
        }
        echo '</section>';
        return (string) ob_get_clean();
    }

    public static function render_buyer_order_breakdown( $order ): void {
        if ( ! $order instanceof WC_Order ) {
            return;
        }
        echo wp_kses_post( self::breakdown_html( $order, 'buyer' ) );
    }

    public static function render_email_order_breakdown( $order, $sent_to_admin = false, $plain_text = false, $email = null ): void {
        if ( 'yes' !== tnm_get_option( 'order_breakdown_emails', 'yes' ) || ! $order instanceof WC_Order ) {
            return;
        }
        $data = self::order_breakdown_data( $order );
        if ( $plain_text ) {
            echo "\nOrder breakdown\n";
            echo 'Items before discounts: ' . tnm_money( $data['item_subtotal'], $order->get_currency() ) . "\n";
            echo 'Shipping: ' . tnm_money( $data['shipping'], $order->get_currency() ) . "\n";
            echo 'Tax: ' . tnm_money( $data['tax'], $order->get_currency() ) . "\n";
            echo 'Total: ' . tnm_money( $data['total'], $order->get_currency() ) . "\n";
            return;
        }
        echo wp_kses_post( self::breakdown_html( $order, $sent_to_admin ? 'admin' : 'buyer' ) );
    }

    public static function add_order_total_rows( array $rows, $order, $tax_display ): array {
        if ( 'yes' !== tnm_get_option( 'buyer_sees_seller_fee', 'no' ) || ! $order instanceof WC_Order ) {
            return $rows;
        }
        $data = self::order_breakdown_data( $order );
        if ( $data['platform_fee'] > 0 ) {
            $rows['tnm_marketplace_fee'] = array(
                'label' => tnm_fee_label(),
                'value' => wc_price( $data['platform_fee'], array( 'currency' => $order->get_currency() ) ),
            );
        }
        return $rows;
    }

    public static function render_admin_order_breakdown( $order ): void {
        if ( ! current_user_can( 'manage_woocommerce' ) || ! $order instanceof WC_Order ) {
            return;
        }
        echo wp_kses_post( self::breakdown_html( $order, 'admin' ) );
    }

    public static function create_product( int $seller_id, array $data, array $files = array() ): int|WP_Error {
        if ( ! tnm_current_user_can_manage_seller( $seller_id ) || ( ! tnm_is_seller( $seller_id ) && ! tnm_is_admin_or_manager() ) ) {
            return tnm_json_error( 'seller_permission_denied', 'You cannot create products for this seller.', 403 );
        }
        // v3.9.2 — sellers must save a bank account (routing + account number)
        // before they can list products. The old Stripe Connect gate is gone;
        // payouts run as manual ACH from the platform's business checking after
        // the 7-day holding window. The filter name is preserved for anything
        // (like the bulk CSV importer) that already opts out of the pre-list gate.
        $skip_bank_gate = (bool) apply_filters( 'mnu_skip_stripe_onboarding_gate', false, $seller_id, $data );
        if ( ! $skip_bank_gate && ! tnm_is_admin_or_manager() && class_exists( 'MNU_Bank_Account' ) && ! MNU_Bank_Account::has_bank_account( $seller_id ) ) {
            return tnm_json_error( 'bank_account_required', 'Add a bank account before you can list new products. Open your seller dashboard → Payout account and enter your routing and account numbers.', 403 );
        }

        // v3.9.2 — Shippo gate removed. The platform now ships every order via
        // its own Shippo token, so sellers no longer need to connect their own
        // account. If a seller has personally connected Shippo, the label
        // purchase path will still prefer their token; if not, it falls back
        // to the platform token. Either way, listing a product is no longer
        // blocked on Shippo connection.

        $name        = sanitize_text_field( (string) tnm_array_get( $data, 'name', '' ) );
        $description = wp_kses_post( (string) tnm_array_get( $data, 'description', '' ) );
        $price       = wc_format_decimal( (string) tnm_array_get( $data, 'price', '' ) );
        $stock       = max( 0, (int) tnm_array_get( $data, 'stock', 0 ) );
        $sku         = wc_clean( (string) tnm_array_get( $data, 'sku', '' ) );
        // v3.7.109 — products auto-publish. The Stripe onboarding gate above
        // is the real-money guardrail; the moderation queue was pure friction.
        $status      = 'publish';

        if ( ! $name || '' === $price || (float) $price < 0 ) {
            return tnm_json_error( 'invalid_product', 'Product name and a valid non-negative price are required.', 422 );
        }

        // v3.7.77 — every listing must ship with at least one photo. The
        // seller portal shortcode uploads first and passes `image_id`, and
        // the mobile REST client uploads via /media then passes `image_id`
        // (or `image_ids[]` for the gallery), so we check both. Importers
        // can bypass via the `mnu_skip_photo_required_gate` filter so the
        // CSV importer keeps working when it sources images from a URL
        // column processed later in the same request.
        $skip_photo_gate = (bool) apply_filters( 'mnu_skip_photo_required_gate', false, $seller_id, $data );
        if ( ! $skip_photo_gate ) {
            $primary_image_id = absint( tnm_array_get( $data, 'image_id', 0 ) );
            $gallery_ids      = array_filter( array_map( 'absint', (array) tnm_array_get( $data, 'image_ids', array() ) ) );
            $gallery_ids      = array_merge( $gallery_ids, array_filter( array_map( 'absint', (array) tnm_array_get( $data, 'gallery_image_ids', array() ) ) ) );
            $has_upload_file  = ! empty( $files['image']['tmp_name'] ) || ! empty( $files['product_image']['tmp_name'] );
            if ( ! $primary_image_id && ! $gallery_ids && ! $has_upload_file ) {
                return tnm_json_error( 'photo_required', 'A photo is required. Please attach at least one image before saving your listing.', 422 );
            }
        }

        $product = new WC_Product_Simple();
        $product->set_name( $name );
        $product->set_description( $description );
        $product->set_regular_price( $price );
        $product->set_status( $status );
        $product->set_catalog_visibility( 'visible' );
        $product->set_manage_stock( true );
        $product->set_stock_quantity( $stock );
        $product->set_stock_status( $stock > 0 ? 'instock' : 'outofstock' );
        if ( $sku ) {
            try {
                $product->set_sku( $sku );
            } catch ( WC_Data_Exception $exception ) {
                return tnm_json_error( 'invalid_sku', $exception->getMessage(), 422 );
            }
        }
        $product->update_meta_data( '_tnm_seller_id', $seller_id );
        $product->update_meta_data( '_mynest_seller_id', $seller_id );
        $product_id = $product->save();
        wp_update_post( array( 'ID' => $product_id, 'post_author' => $seller_id ) );

        $category_ids = array_filter( array_map( 'absint', (array) tnm_array_get( $data, 'category_ids', array() ) ) );
        if ( $category_ids ) {
            wp_set_object_terms( $product_id, $category_ids, 'product_cat' );
        }

        $image_id = absint( tnm_array_get( $data, 'image_id', 0 ) );
        if ( $image_id && tnm_user_can_use_attachment( $seller_id, $image_id ) ) {
            $product->set_image_id( $image_id );
            $product->save();
        }

        // Persist shipping (including a package_size preset) when the caller
        // supplied any shipping fields — e.g. the mobile app's product-create
        // REST payload. The seller portal saves shipping separately.
        if ( function_exists( 'mnu_ship_save_product_shipping' ) ) {
            $shipping_keys = array( 'weight_oz', 'length_in', 'width_in', 'height_in', 'package_size', 'processing_time' );
            $shipping_data = array();
            foreach ( $shipping_keys as $shipping_key ) {
                if ( array_key_exists( $shipping_key, $data ) ) {
                    $shipping_data[ $shipping_key ] = $data[ $shipping_key ];
                }
            }
            if ( $shipping_data ) {
                mnu_ship_save_product_shipping( (int) $product_id, $shipping_data );
            }
        }

        do_action( 'tnm_product_created', $product_id, $seller_id, $data );
        return $product_id;
    }

    public static function update_product( int $seller_id, int $product_id, array $data ): int|WP_Error {
        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return tnm_json_error( 'product_not_found', 'Product not found.', 404 );
        }
        if ( tnm_get_product_seller_id( $product ) !== $seller_id && ! tnm_is_admin_or_manager() ) {
            return tnm_json_error( 'product_permission_denied', 'You cannot edit this product.', 403 );
        }
        if ( array_key_exists( 'name', $data ) ) {
            $name = sanitize_text_field( (string) $data['name'] );
            if ( ! $name ) {
                return tnm_json_error( 'invalid_product_name', 'Product name cannot be empty.', 422 );
            }
            $product->set_name( $name );
        }
        if ( array_key_exists( 'description', $data ) ) {
            $product->set_description( wp_kses_post( (string) $data['description'] ) );
        }
        if ( array_key_exists( 'price', $data ) ) {
            $price = wc_format_decimal( (string) $data['price'] );
            if ( '' === $price || (float) $price < 0 ) {
                return tnm_json_error( 'invalid_product_price', 'Price must be a non-negative number.', 422 );
            }
            $product->set_regular_price( $price );
        }
        if ( array_key_exists( 'stock', $data ) ) {
            $stock = max( 0, (int) $data['stock'] );
            $product->set_manage_stock( true );
            $product->set_stock_quantity( $stock );
            $product->set_stock_status( $stock > 0 ? 'instock' : 'outofstock' );
        }
        if ( array_key_exists( 'status', $data ) ) {
            $allowed = array( 'publish', 'draft', 'pending' );
            $status  = sanitize_key( (string) $data['status'] );
            if ( in_array( $status, $allowed, true ) ) {
                // v3.9.2 — Block only the publish/go-live transition for sellers
                // who have not saved a bank account yet. Editing other fields is
                // allowed. Stripe Connect check is retired.
                if ( 'publish' === $status && 'publish' !== $product->get_status() && ! tnm_is_admin_or_manager() && class_exists( 'MNU_Bank_Account' ) && ! MNU_Bank_Account::has_bank_account( $seller_id ) ) {
                    return tnm_json_error( 'bank_account_required', 'Add a bank account before you can publish products. Open your seller dashboard → Payout account and enter your routing and account numbers.', 403 );
                }
                // v3.9.2 — Shippo publish-gate removed. Platform Shippo token
                // covers every seller by default.
                // v3.7.109 — no admin moderation gate; the bank-account check
                // above is the only guard on going live.
                $product->set_status( $status );
            }
        }
        $product->save();
        if ( array_key_exists( 'category_ids', $data ) ) {
            wp_set_object_terms( $product_id, array_filter( array_map( 'absint', (array) $data['category_ids'] ) ), 'product_cat' );
        }
        if ( ! empty( $data['image_id'] ) && tnm_user_can_use_attachment( $seller_id, absint( $data['image_id'] ) ) ) {
            $product->set_image_id( absint( $data['image_id'] ) );
            $product->save();
        }

        // Persist shipping (including a package_size preset) on edit, mirroring
        // create_product(). Without this an existing product's package size /
        // real WC dimensions could never be changed through the REST path.
        if ( function_exists( 'mnu_ship_save_product_shipping' ) ) {
            $shipping_keys = array( 'weight_oz', 'length_in', 'width_in', 'height_in', 'package_size', 'processing_time' );
            $shipping_data = array();
            foreach ( $shipping_keys as $shipping_key ) {
                if ( array_key_exists( $shipping_key, $data ) ) {
                    $shipping_data[ $shipping_key ] = $data[ $shipping_key ];
                }
            }
            if ( $shipping_data ) {
                mnu_ship_save_product_shipping( (int) $product_id, $shipping_data );
            }
        }

        return $product_id;
    }

    public static function delete_product( int $seller_id, int $product_id ): bool|WP_Error {
        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return tnm_json_error( 'product_not_found', 'Product not found.', 404 );
        }
        if ( tnm_get_product_seller_id( $product ) !== $seller_id && ! tnm_is_admin_or_manager() ) {
            return tnm_json_error( 'product_permission_denied', 'You cannot delete this product.', 403 );
        }
        return (bool) wp_trash_post( $product_id );
    }

    /**
     * Duplicate an existing product owned by $seller_id.
     *
     * v3.7.102 (Build #3) — powers the mobile "Duplicate this listing" action so
     * a maker with variant items (e.g. hair bows in eight colors) doesn't have
     * to retype every field. We deliberately clone as a **draft** with " (Copy)"
     * appended: the seller lands in the edit form, tweaks the color/size/photo,
     * and hits publish. That's the whole workflow.
     *
     * We bypass create_product() here because it strips gallery, attributes,
     * and shipping — which are the exact fields duplication needs to preserve.
     * Instead we clone by hand: WC_Product_Simple → copy scalars → set image /
     * gallery / categories / attribute terms → clone _mnu_ship_* meta.
     */
    public static function duplicate_product( int $seller_id, int $source_id ): int|WP_Error {
        $source = wc_get_product( $source_id );
        if ( ! $source ) {
            return tnm_json_error( 'product_not_found', 'Product not found.', 404 );
        }
        if ( tnm_get_product_seller_id( $source ) !== $seller_id && ! tnm_is_admin_or_manager() ) {
            return tnm_json_error( 'product_permission_denied', 'You cannot duplicate this product.', 403 );
        }

        // v3.9.2 — Bank-account gate, same rule as create. A seller who can't
        // list new products shouldn't be able to duplicate their way around it.
        if ( ! tnm_is_admin_or_manager() && class_exists( 'MNU_Bank_Account' ) && ! MNU_Bank_Account::has_bank_account( $seller_id ) ) {
            return tnm_json_error( 'bank_account_required', 'Add a bank account before you can duplicate a listing. Open your seller dashboard → Payout account.', 403 );
        }

        $copy = new WC_Product_Simple();
        $copy->set_name( $source->get_name() . ' (Copy)' );
        $copy->set_description( $source->get_description() );
        $copy->set_short_description( $source->get_short_description() );
        $copy->set_regular_price( $source->get_regular_price() );
        $copy->set_sale_price( $source->get_sale_price() );
        $copy->set_status( 'draft' );  // Always draft so it doesn't accidentally publish.
        $copy->set_catalog_visibility( 'visible' );
        $copy->set_manage_stock( $source->get_manage_stock() );
        $copy->set_stock_quantity( $source->get_stock_quantity() );
        $copy->set_stock_status( ( $source->get_stock_quantity() ?: 0 ) > 0 ? 'instock' : 'outofstock' );
        $copy->set_weight( $source->get_weight() );
        $copy->set_length( $source->get_length() );
        $copy->set_width( $source->get_width() );
        $copy->set_height( $source->get_height() );
        $copy->set_image_id( $source->get_image_id() );
        $copy->set_gallery_image_ids( $source->get_gallery_image_ids() );
        // Attributes clone verbatim; taxonomy terms are set below.
        $copy->set_attributes( $source->get_attributes() );
        // Not the SKU — WooCommerce enforces uniqueness and a collision would
        // reject the save. Leave blank; the seller can retype it if they use SKUs.
        $copy->update_meta_data( '_tnm_seller_id', $seller_id );
        $copy->update_meta_data( '_mynest_seller_id', $seller_id );
        $new_id = $copy->save();
        if ( ! $new_id || is_wp_error( $new_id ) ) {
            return tnm_json_error( 'duplicate_failed', 'Could not duplicate the listing. Please try again.', 500 );
        }
        wp_update_post( array( 'ID' => $new_id, 'post_author' => $seller_id ) );

        // Categories.
        $cat_ids = wp_get_object_terms( $source_id, 'product_cat', array( 'fields' => 'ids' ) );
        if ( is_array( $cat_ids ) && $cat_ids ) {
            wp_set_object_terms( $new_id, array_map( 'intval', $cat_ids ), 'product_cat' );
        }

        // Attribute taxonomy terms (pa_condition / pa_size / pa_brand / etc.).
        foreach ( array( 'pa_condition', 'pa_size', 'pa_brand', 'pa_color', 'pa_material' ) as $taxonomy ) {
            if ( ! taxonomy_exists( $taxonomy ) ) {
                continue;
            }
            $terms = wp_get_object_terms( $source_id, $taxonomy, array( 'fields' => 'ids' ) );
            if ( is_array( $terms ) && $terms ) {
                wp_set_object_terms( $new_id, array_map( 'intval', $terms ), $taxonomy );
            }
        }

        // Shipping meta (weight/dims/package_size/processing_time). Live in
        // _mnu_ship_* keys — copy them across as-is.
        if ( function_exists( 'mnu_ship_get_product_shipping' ) && function_exists( 'mnu_ship_save_product_shipping' ) ) {
            $shipping = mnu_ship_get_product_shipping( $source_id );
            if ( is_array( $shipping ) && $shipping ) {
                mnu_ship_save_product_shipping( (int) $new_id, $shipping );
            }
        }

        do_action( 'tnm_product_duplicated', $new_id, $source_id, $seller_id );
        do_action( 'tnm_product_created', $new_id, $seller_id, array( 'source_id' => $source_id ) );
        return (int) $new_id;
    }

    /**
     * v3.7.119 (Build #8) — seller variations editor.
     *
     * Overwrites a product's custom (product-level, not taxonomy) attributes
     * and its child variations in one shot. We deliberately do NOT touch
     * global taxonomy attributes (pa_size etc.) here so this endpoint stays
     * a safe superset of what the mobile app renders in the picker: sellers
     * type option names, we hash them into slugs, and every combination
     * they set gets a real WC_Product_Variation with its own price + stock.
     *
     * $payload shape:
     *   attributes  => [ { name:string, options:[string,...] }, ... ]
     *   variations  => [ { attributes:{name:slug,...}, price:num, stock:int, sku?:string }, ... ]
     *
     * When the seller submits `attributes = []` we downgrade the product
     * back to a simple product (all existing variations are deleted) so
     * they can undo the whole thing without contacting support.
     */
    public static function save_product_variations( int $seller_id, int $product_id, array $payload ): array|WP_Error {
        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return tnm_json_error( 'product_not_found', 'Product not found.', 404 );
        }
        if ( tnm_get_product_seller_id( $product ) !== $seller_id && ! tnm_is_admin_or_manager() ) {
            return tnm_json_error( 'product_permission_denied', 'You cannot edit this product.', 403 );
        }

        $attributes_in = is_array( $payload['attributes'] ?? null ) ? $payload['attributes'] : array();
        $variations_in = is_array( $payload['variations'] ?? null ) ? $payload['variations'] : array();

        // Sanitize + de-dupe attributes.
        $attr_defs = array(); // attr_key => [ 'name'=>display, 'options'=>[ slug=>label, ... ] ]
        foreach ( $attributes_in as $attr ) {
            if ( ! is_array( $attr ) ) { continue; }
            $name = sanitize_text_field( (string) ( $attr['name'] ?? '' ) );
            if ( '' === $name ) { continue; }
            $key  = sanitize_title( $name );
            if ( '' === $key ) { continue; }
            $opts = array();
            foreach ( (array) ( $attr['options'] ?? array() ) as $opt ) {
                $label = sanitize_text_field( (string) $opt );
                if ( '' === $label ) { continue; }
                $slug = sanitize_title( $label );
                if ( '' === $slug || isset( $opts[ $slug ] ) ) { continue; }
                $opts[ $slug ] = $label;
            }
            if ( ! $opts ) { continue; }
            $attr_defs[ $key ] = array( 'name' => $name, 'options' => $opts );
        }

        // If the seller cleared attributes, downgrade to simple product and
        // drop every existing variation.
        if ( ! $attr_defs ) {
            self::delete_variations_for( $product_id );
            wp_set_object_terms( $product_id, array( 'simple' ), 'product_type' );
            $product = wc_get_product( $product_id );
            $product->set_attributes( array() );
            $product->save();
            return array( 'attributes' => array(), 'variations' => array() );
        }

        // Promote to variable if not already.
        if ( 'variable' !== $product->get_type() ) {
            wp_set_object_terms( $product_id, array( 'variable' ), 'product_type' );
            $product = wc_get_product( $product_id );
            if ( ! $product instanceof WC_Product_Variable ) {
                // WooCommerce couldn't re-hydrate as variable — try one more time.
                $product = new WC_Product_Variable( $product_id );
            }
        }

        // Build attribute objects (product-level, not taxonomy).
        $wc_attributes = array();
        $position = 0;
        foreach ( $attr_defs as $key => $def ) {
            $attribute = new WC_Product_Attribute();
            $attribute->set_id( 0 );
            $attribute->set_name( $def['name'] );
            $attribute->set_options( array_values( $def['options'] ) );
            $attribute->set_position( $position++ );
            $attribute->set_visible( true );
            $attribute->set_variation( true );
            $wc_attributes[] = $attribute;
        }
        $product->set_attributes( $wc_attributes );
        $product->save();

        // Track existing variations so we can reuse ids where possible instead
        // of thrashing rows on every edit — matches Woo's usual behavior.
        $existing = array();
        foreach ( $product->get_children() as $vid ) {
            $existing[ (int) $vid ] = wc_get_product( (int) $vid );
        }

        // Woo variations use per-attribute meta keys like `attribute_size`.
        // Build variation attribute maps in that format.
        $seen_ids   = array();
        $seen_combo = array();
        $errors     = array();
        foreach ( $variations_in as $var ) {
            if ( ! is_array( $var ) ) { continue; }
            $picked_raw = is_array( $var['attributes'] ?? null ) ? $var['attributes'] : array();
            $picked     = array(); // WC meta key => slug
            $normalized = array(); // display key => slug (for dedup)
            $missing    = false;
            foreach ( $attr_defs as $key => $def ) {
                $raw_slug = sanitize_title( (string) ( $picked_raw[ $key ] ?? $picked_raw[ $def['name'] ] ?? '' ) );
                if ( '' === $raw_slug || ! isset( $def['options'][ $raw_slug ] ) ) {
                    $missing = true;
                    break;
                }
                $picked[ 'attribute_' . $key ] = $raw_slug;
                $normalized[ $key ] = $raw_slug;
            }
            if ( $missing ) {
                $errors[] = 'variation_missing_attribute';
                continue;
            }
            $combo_key = wp_json_encode( $normalized );
            if ( isset( $seen_combo[ $combo_key ] ) ) {
                $errors[] = 'duplicate_variation_combo';
                continue;
            }
            $seen_combo[ $combo_key ] = true;

            $price = wc_format_decimal( (string) ( $var['price'] ?? '' ) );
            if ( '' === $price || (float) $price < 0 ) {
                $errors[] = 'invalid_variation_price';
                continue;
            }
            $stock = max( 0, (int) ( $var['stock'] ?? 0 ) );

            // Reuse existing id when its attribute combo matches.
            $target_id = 0;
            foreach ( $existing as $vid => $variation_obj ) {
                if ( isset( $seen_ids[ $vid ] ) ) { continue; }
                if ( ! $variation_obj instanceof WC_Product_Variation ) { continue; }
                $curr = array();
                foreach ( $variation_obj->get_attributes() as $k => $v ) {
                    $curr[ $k ] = (string) $v;
                }
                ksort( $curr );
                $needle = $picked;
                ksort( $needle );
                if ( $curr === $needle ) { $target_id = $vid; break; }
            }
            $variation = $target_id ? wc_get_product( $target_id ) : new WC_Product_Variation();
            if ( ! $variation instanceof WC_Product_Variation ) {
                $variation = new WC_Product_Variation();
            }
            $variation->set_parent_id( $product_id );
            $variation->set_attributes( $picked );
            $variation->set_regular_price( $price );
            $variation->set_sale_price( '' );
            $variation->set_status( 'publish' );
            $variation->set_manage_stock( true );
            $variation->set_stock_quantity( $stock );
            $variation->set_stock_status( $stock > 0 ? 'instock' : 'outofstock' );
            $sku = isset( $var['sku'] ) ? wc_clean( (string) $var['sku'] ) : '';
            if ( $sku ) {
                try { $variation->set_sku( $sku ); } catch ( WC_Data_Exception $exception ) { /* leave sku unset */ }
            }
            $vid = $variation->save();
            if ( $vid ) { $seen_ids[ (int) $vid ] = true; }
        }

        // Delete variations that weren't retained.
        foreach ( $existing as $vid => $_v ) {
            if ( isset( $seen_ids[ $vid ] ) ) { continue; }
            wp_delete_post( (int) $vid, true );
        }

        // Rebuild the price cache so front-of-shop price ranges are accurate.
        if ( class_exists( 'WC_Product_Variable' ) ) {
            WC_Product_Variable::sync( $product_id );
        }

        $product = wc_get_product( $product_id );
        $out = array(
            'attributes' => $product instanceof WC_Product_Variable ? self::product_attributes_payload( $product ) : array(),
            'variations' => $product instanceof WC_Product_Variable ? self::product_variations_payload( $product ) : array(),
        );
        if ( $errors ) { $out['warnings'] = array_values( array_unique( $errors ) ); }
        return $out;
    }

    private static function delete_variations_for( int $product_id ): void {
        $variations = get_posts( array(
            'post_parent'    => $product_id,
            'post_type'      => 'product_variation',
            'posts_per_page' => -1,
            'post_status'    => array( 'publish', 'private' ),
            'fields'         => 'ids',
        ) );
        foreach ( $variations as $vid ) {
            wp_delete_post( (int) $vid, true );
        }
    }

    public static function product_to_array( WC_Product $product, bool $seller_context = false ): array {
        $seller_id = tnm_get_product_seller_id( $product );
        $data = array(
            'id'                => $product->get_id(),
            'name'              => $product->get_name(),
            'slug'              => $product->get_slug(),
            'description'       => wp_strip_all_tags( $product->get_description() ),
            'short_description' => wp_strip_all_tags( $product->get_short_description() ),
            'price'             => (float) $product->get_price(),
            'regular_price'     => (float) $product->get_regular_price(),
            'price_html'        => $product->get_price_html(),
            'currency'          => get_woocommerce_currency(),
            'stock_status'      => $product->get_stock_status(),
            'stock_quantity'    => $product->get_stock_quantity(),
            'image'             => wp_get_attachment_image_url( $product->get_image_id(), 'large' ) ?: wc_placeholder_img_src(),
            'gallery'           => array_values( array_filter( array_map( static fn( $id ) => wp_get_attachment_image_url( $id, 'large' ), $product->get_gallery_image_ids() ) ) ),
            'permalink'         => get_permalink( $product->get_id() ),
            'product_rating'    => class_exists( 'TNM_Social' )
                ? TNM_Social::product_rating_summary( $product->get_id() )
                : array( 'rating' => 0.0, 'review_count' => 0 ),
            'seller'            => ( function() use ( $seller_id ) {
                // v3.7.103 - attach cached rating aggregate so product cards
                // can render a star badge without a follow-up request.
                $rating = class_exists( 'TNM_Social' ) ? TNM_Social::seller_rating_summary( $seller_id ) : array( 'rating' => 0.0, 'review_count' => 0 );
                return array(
                    'id'           => $seller_id,
                    'store_name'   => tnm_seller_display_name( $seller_id ),
                    'avatar'       => tnm_user_avatar_url( $seller_id, 256 ),
                    'rating'       => $rating['rating'],
                    'review_count' => $rating['review_count'],
                );
            } )(),
            'categories'        => array_map(
                static fn( $term ) => array( 'id' => $term->term_id, 'name' => $term->name, 'slug' => $term->slug ),
                get_the_terms( $product->get_id(), 'product_cat' ) ?: array()
            ),
            // v3.7.118 — expose product type + variation payload so the
            // mobile app can render size/color pickers and add the picked
            // variation to cart.
            'type'              => $product->get_type(),
        );
        if ( $product->is_type( 'variable' ) && $product instanceof WC_Product_Variable ) {
            $data['attributes']  = self::product_attributes_payload( $product );
            $data['variations']  = self::product_variations_payload( $product );
        }
        if ( $seller_context ) {
            $data['status']           = $product->get_status();
            $data['sku']              = $product->get_sku();
            $data['sales']            = (int) $product->get_total_sales();
            // v3.7.104 - seller listings show a heart + count so the seller
            // knows which items are drawing interest. Only in seller context;
            // buyer-facing product cards don't need it.
            $data['favorites_count']  = class_exists( 'MNU_Favorites_Listener' )
                ? (int) ( MNU_Favorites_Listener::counts_for_products( array( $product->get_id() ) )[ $product->get_id() ] ?? 0 )
                : 0;
        }
        return $data;
    }

    /**
     * v3.7.118 — attribute options list for a variable product.
     * Returns an array of { name, slug, options[] } tuples used by the
     * mobile app to render size/color pickers.
     */
    public static function product_attributes_payload( WC_Product_Variable $product ): array {
        $out = array();
        foreach ( $product->get_attributes() as $attribute ) {
            if ( ! ( $attribute instanceof WC_Product_Attribute ) ) { continue; }
            if ( ! $attribute->get_variation() ) { continue; }
            $name    = $attribute->get_name();
            $label   = wc_attribute_label( $name, $product );
            $options = array();
            if ( $attribute->is_taxonomy() ) {
                $terms = wc_get_product_terms( $product->get_id(), $name, array( 'fields' => 'all' ) );
                foreach ( $terms as $term ) {
                    $options[] = array( 'slug' => $term->slug, 'label' => $term->name );
                }
            } else {
                foreach ( $attribute->get_options() as $opt ) {
                    $slug = sanitize_title( $opt );
                    $options[] = array( 'slug' => $slug, 'label' => (string) $opt );
                }
            }
            $out[] = array( 'name' => $name, 'label' => $label, 'options' => $options );
        }
        return $out;
    }

    /**
     * v3.7.118 — per-variation payload for a variable product. The
     * `attributes` map keys are attribute taxonomy names (matching
     * product_attributes_payload) and values are the picked option slug.
     */
    public static function product_variations_payload( WC_Product_Variable $product ): array {
        $out = array();
        foreach ( $product->get_available_variations() as $var ) {
            $variation = wc_get_product( (int) $var['variation_id'] );
            if ( ! $variation instanceof WC_Product_Variation ) { continue; }
            $attributes = array();
            foreach ( $variation->get_attributes() as $attr_name => $attr_value ) {
                if ( '' === $attr_value ) { continue; }
                $attributes[ $attr_name ] = (string) $attr_value;
            }
            $image = '';
            if ( $variation->get_image_id() ) {
                $image = wp_get_attachment_image_url( $variation->get_image_id(), 'large' ) ?: '';
            }
            $out[] = array(
                'id'             => $variation->get_id(),
                'attributes'     => (object) $attributes,
                'price'          => (float) $variation->get_price(),
                'regular_price'  => (float) $variation->get_regular_price(),
                'stock_status'   => $variation->get_stock_status(),
                'stock_quantity' => $variation->get_stock_quantity(),
                'image'          => $image,
                'sku'            => $variation->get_sku(),
                'is_purchasable' => (bool) $variation->is_purchasable(),
            );
        }
        return $out;
    }

    public static function seller_orders( int $seller_id, int $page = 1, int $per_page = 20 ): array {
        // v3.7.122.5 — sellers should only see orders that actually earn them
        // money. wc-pending, wc-failed, and wc-cancelled all mean the buyer
        // never completed payment. Internal tester Jo saw a phantom #3509
        // "Pending" row on her seller dashboard next to the real paid #3510
        // because the native checkout had opened a draft order she never
        // paid on. wc-refunded stays in the list so sellers can still
        // reference orders that were paid then reversed. wc-checkout-draft
        // (from other Woo flows) is likewise excluded.
        $seller_visible_statuses = array( 'wc-processing', 'wc-completed', 'wc-on-hold', 'wc-refunded' );
        $query = wc_get_orders(
            array(
                'limit'    => max( 1, min( 100, $per_page ) ),
                'page'     => max( 1, $page ),
                'paginate' => true,
                'orderby'  => 'date',
                'order'    => 'DESC',
                'status'   => $seller_visible_statuses,
                'return'   => 'objects',
                'meta_query' => array(
                    array(
                        'key'     => '_tnm_seller_ids',
                        'value'   => ',' . $seller_id . ',',
                        'compare' => 'LIKE',
                    ),
                ),
            )
        );
        $orders = array();
        foreach ( $query->orders as $order ) {
            if ( ! tnm_order_contains_seller( $order, $seller_id ) ) {
                continue;
            }
            // v3.7.122.14 — if the seller is also the buyer on this order
            // (a seller-buyer account who purchased from another seller and
            // somehow has their own id on the `_tnm_seller_ids` CSV, or a
            // seller who bought their own listing) the seller-orders list
            // must not include it. Otherwise the buyer's order screen
            // short-circuits to the seller-framed view because getSellerOrders
            // returns a match for the same order id. Anthony saw #3529
            // render as a seller screen even though he bought the items
            // from Jo.
            if ( (int) $order->get_customer_id() === $seller_id ) {
                continue;
            }
            $orders[] = self::seller_order_to_array( $order, $seller_id );
        }
        return array(
            'orders'      => $orders,
            'page'        => max( 1, $page ),
            'total'       => count( $orders ),
            'total_pages' => (int) $query->max_num_pages,
        );
    }

    /**
     * Resolve an order item's marketplace fee using the same basis as the ledger writer:
     * prefer the stamped `_tnm_platform_fee` snapshot, fall back to the legacy
     * `_mynest_nestkeeper_fee` key, and finally recompute from the item line total
     * (post-discount, tax-excluded) times the configured fee percent. Orders that were
     * never stamped at checkout (native app / admin-created / imported) have no fee meta,
     * so without this fallback the seller dashboard would read 0 and show -$0.00.
     */
    public static function resolve_item_platform_fee( WC_Order_Item_Product $item ): float {
        $fee_meta = $item->get_meta( '_tnm_platform_fee', true );
        if ( '' === $fee_meta ) {
            $fee_meta = $item->get_meta( '_mynest_nestkeeper_fee', true );
        }
        if ( '' === $fee_meta ) {
            $gross = max( 0, (float) $item->get_total() );
            return round( $gross * ( tnm_fee_percent() / 100 ), wc_get_price_decimals() + 2 );
        }
        return (float) $fee_meta;
    }

    public static function seller_order_to_array( WC_Order $order, int $seller_id ): array {
        $items = array();
        $subtotal = 0.0;
        $fees = 0.0;
        foreach ( tnm_get_seller_order_items( $order, $seller_id ) as $item_id => $item ) {
            $gross = (float) $item->get_total();
            $fee   = self::resolve_item_platform_fee( $item );
            $subtotal += $gross;
            $fees     += $fee;
            $items[] = array(
                'item_id'      => $item_id,
                'product_id'   => $item->get_product_id(),
                'variation_id' => $item->get_variation_id(),
                'name'         => $item->get_name(),
                'quantity'     => $item->get_quantity(),
                'gross'        => $gross,
                'tax'          => (float) $item->get_total_tax(),
                'platform_fee' => $fee,
                'net'          => max( 0, $gross - $fee ),
            );
        }
        // v3.7.124 — surface the seller's Stripe-fee share so the app can
        // show the seller's true net (product − platform fee − Stripe fee
        // on product). Note: under v3.8.0 the platform fee is a flat 10%
        // and the Stripe fee is absorbed by the platform, so this line
        // stays zero on new-model orders.
        // For orders where the platform kept the shipping (new-model orders),
        // we intentionally do NOT expose a shipping line to the seller —
        // they never received that money.
        $platform_keeps_shipping = '' !== (string) $order->get_meta( '_mnu_platform_shipping_kept_cents', true );
        $stripe_fee_seller_cents = 0;
        if ( $platform_keeps_shipping ) {
            // Single-seller destination-charge path stamps the aggregate.
            $stripe_fee_seller_cents = (int) $order->get_meta( '_mnu_stripe_fee_product_cents', true );
            // Multi-seller SCT path stamps a per-seller share map.
            $shares_raw = $order->get_meta( '_mnu_stripe_fee_seller_shares', true );
            $shares = is_string( $shares_raw ) ? json_decode( $shares_raw, true ) : $shares_raw;
            if ( is_array( $shares ) && isset( $shares[ (string) $seller_id ] ) ) {
                $stripe_fee_seller_cents = (int) $shares[ (string) $seller_id ];
            }
        }
        $stripe_fee_seller = round( $stripe_fee_seller_cents / 100, wc_get_price_decimals() );
        $seller_net = max( 0, $subtotal - $fees - $stripe_fee_seller );

        return array(
            'id'              => $order->get_id(),
            'number'          => $order->get_order_number(),
            'status'          => $order->get_status(),
            'seller_status'   => $order->get_meta( '_tnm_seller_status_' . $seller_id, true ) ?: 'processing',
            'tracking_number' => $order->get_meta( '_tnm_tracking_' . $seller_id, true ),
            'date_created'    => $order->get_date_created() ? $order->get_date_created()->date( DATE_ATOM ) : null,
            'customer'        => array(
                'name'    => trim( $order->get_formatted_billing_full_name() ),
                'email'   => $order->get_billing_email(),
                'phone'   => $order->get_billing_phone(),
                'address' => $order->get_formatted_shipping_address() ?: $order->get_formatted_billing_address(),
            ),
            'items'           => $items,
            'gross'           => $subtotal,
            // v3.7.88 — alias so mobile clients that read `total` (the seller
            // dashboard's recent-orders row) render the seller's subtotal
            // instead of $0.00. Kept alongside `gross`/`net_before_shipping`
            // so no existing consumer breaks.
            'total'           => $subtotal,
            'platform_fee'    => $fees,
            'net_before_shipping' => max( 0, $subtotal - $fees ),
            // v3.7.124 — additional fields for accurate seller-side math.
            'stripe_fee'              => $stripe_fee_seller,
            'seller_net'              => $seller_net,
            'platform_keeps_shipping' => $platform_keeps_shipping,
            'currency'        => $order->get_currency(),
        );
    }

    public static function update_seller_order_status( int $seller_id, int $order_id, string $status, string $tracking = '' ): bool|WP_Error {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return tnm_json_error( 'order_not_found', 'Order not found.', 404 );
        }
        if ( ! tnm_order_contains_seller( $order, $seller_id ) && ! tnm_is_admin_or_manager() ) {
            return tnm_json_error( 'order_permission_denied', 'You cannot update this order.', 403 );
        }
        $allowed = array( 'processing', 'shipped', 'completed', 'cancelled' );
        if ( ! in_array( $status, $allowed, true ) ) {
            return tnm_json_error( 'invalid_seller_status', 'Invalid seller order status.', 422 );
        }
        $order->update_meta_data( '_tnm_seller_status_' . $seller_id, $status );
        // v3.7.95 - stamp shipped/completed timestamps so the buyer order
        // payload can render "Shipped Aug 17" / "Delivered Aug 20" per seller.
        if ( 'shipped' === $status && ! $order->get_meta( '_tnm_seller_shipped_at_' . $seller_id, true ) ) {
            $order->update_meta_data( '_tnm_seller_shipped_at_' . $seller_id, current_time( 'mysql', true ) );
        }
        if ( 'completed' === $status && ! $order->get_meta( '_tnm_seller_completed_at_' . $seller_id, true ) ) {
            $order->update_meta_data( '_tnm_seller_completed_at_' . $seller_id, current_time( 'mysql', true ) );
        }
        if ( $tracking ) {
            $order->update_meta_data( '_tnm_tracking_' . $seller_id, sanitize_text_field( $tracking ) );
            // v3.7.95 - if seller pasted "USPS 9400..." split the carrier off
            // and store it, so the buyer sees carrier + number and a
            // tap-through tracking URL (guessed on read if not stored).
            $parts = preg_split( '/\s+/', trim( sanitize_text_field( $tracking ) ), 2 );
            if ( is_array( $parts ) && count( $parts ) === 2 && ! $order->get_meta( '_thenest_tracking_carrier_' . $seller_id, true ) ) {
                $order->update_meta_data( '_thenest_tracking_carrier_' . $seller_id, $parts[0] );
                $order->update_meta_data( '_thenest_tracking_number_' . $seller_id, $parts[1] );
                $order->update_meta_data( '_tnm_tracking_' . $seller_id, $parts[1] );
            }
        }
        $order->add_order_note( sprintf( '%s updated their seller fulfillment status to %s%s.', tnm_seller_display_name( $seller_id ), $status, $tracking ? ' (tracking: ' . sanitize_text_field( $tracking ) . ')' : '' ) );
        $order->save();

        $customer_id = $order->get_customer_id();
        if ( $customer_id ) {
            tnm_notify( $customer_id, $seller_id, 'order_update', 'Order #' . $order->get_order_number() . ' update', tnm_seller_display_name( $seller_id ) . ' marked their items ' . $status . '.', $order_id, 'shop_order', $order->get_view_order_url() );
        }
        return true;
    }
}
