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
        return tnm_json_error( 'stripe_onboarding_required', tnm_seller_listing_blocked_message(), 403 );
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
        // Allow other code (e.g. bulk CSV importer) to bypass the Stripe onboarding gate.
        // Products still get created as pending/draft; the seller must connect Stripe before
        // they can actually be published for sale.
        $skip_stripe_gate = (bool) apply_filters( 'mnu_skip_stripe_onboarding_gate', false, $seller_id, $data );
        if ( ! $skip_stripe_gate && ! tnm_is_admin_or_manager() && class_exists( 'MNU_Connect' ) && ! MNU_Connect::seller_can_sell( $seller_id ) ) {
            return tnm_json_error( 'stripe_onboarding_required', 'You must finish connecting your bank account with Stripe before you can list new products. Open your seller dashboard to complete Stripe onboarding.', 403 );
        }

        $name        = sanitize_text_field( (string) tnm_array_get( $data, 'name', '' ) );
        $description = wp_kses_post( (string) tnm_array_get( $data, 'description', '' ) );
        $price       = wc_format_decimal( (string) tnm_array_get( $data, 'price', '' ) );
        $stock       = max( 0, (int) tnm_array_get( $data, 'stock', 0 ) );
        $sku         = wc_clean( (string) tnm_array_get( $data, 'sku', '' ) );
        $status      = 'yes' === tnm_get_option( 'seller_can_publish', 'yes' ) || tnm_is_admin_or_manager() ? 'publish' : 'pending';

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
                // Block only the publish/go-live transition for sellers who have
                // not finished Stripe onboarding; editing other fields is allowed.
                if ( 'publish' === $status && 'publish' !== $product->get_status() && ! tnm_is_admin_or_manager() && class_exists( 'MNU_Connect' ) && ! MNU_Connect::seller_can_sell( $seller_id ) ) {
                    return tnm_json_error( 'stripe_onboarding_required', 'You must finish connecting your bank account with Stripe before you can publish products. Open your seller dashboard to complete Stripe onboarding.', 403 );
                }
                if ( 'publish' === $status && 'yes' !== tnm_get_option( 'seller_can_publish', 'yes' ) && ! tnm_is_admin_or_manager() ) {
                    $status = 'pending';
                }
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

        // Stripe onboarding gate — same rule as create. A seller who can't list
        // new products shouldn't be able to duplicate their way around it.
        if ( ! tnm_is_admin_or_manager() && class_exists( 'MNU_Connect' ) && ! MNU_Connect::seller_can_sell( $seller_id ) ) {
            return tnm_json_error( 'stripe_onboarding_required', 'You must finish connecting your bank account with Stripe before you can duplicate a listing.', 403 );
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
        );
        if ( $seller_context ) {
            $data['status'] = $product->get_status();
            $data['sku']    = $product->get_sku();
            $data['sales']  = (int) $product->get_total_sales();
        }
        return $data;
    }

    public static function seller_orders( int $seller_id, int $page = 1, int $per_page = 20 ): array {
        $query = wc_get_orders(
            array(
                'limit'    => max( 1, min( 100, $per_page ) ),
                'page'     => max( 1, $page ),
                'paginate' => true,
                'orderby'  => 'date',
                'order'    => 'DESC',
                'status'   => array_keys( wc_get_order_statuses() ),
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
