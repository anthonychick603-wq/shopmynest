<?php

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'MNU_NATIVE_NS' ) ) {
    define( 'MNU_NATIVE_NS', 'nest-native/v1' );
}
if ( ! defined( 'MNU_NATIVE_VERSION' ) ) {
    define( 'MNU_NATIVE_VERSION', '5.0.0' );
}

function mnu_native_current_user_id( ?WP_REST_Request $request = null ): int {
    if ( class_exists( 'MNU_Ops' ) ) {
        return MNU_Ops::get_bearer_user_id( $request );
    }

    return get_current_user_id();
}

function mnu_native_settings_defaults(): array {
    return array(
        'publishable_key'        => '',
        'secret_key'             => '',
        'webhook_secret'          => '',
        'currency'                => strtolower( get_woocommerce_currency() ?: 'usd' ),
        'test_mode'               => 1,
        'flat_shipping'           => '6.95',
        'free_shipping_threshold' => '50.00',
    );
}

function mnu_native_get_settings(): array {
    $stored   = (array) get_option( 'thenest_native_checkout_settings', array() );
    $settings = array_merge( mnu_native_settings_defaults(), $stored );

    // Reuse the active WooCommerce Stripe Gateway keys when dedicated native keys are blank.
    $stripe = (array) get_option( 'woocommerce_stripe_settings', array() );
    if ( $stripe ) {
        $gateway_test_mode = 'yes' === ( $stripe['testmode'] ?? 'no' );
        $using_fallback    = empty( $stored['publishable_key'] ) || empty( $stored['secret_key'] );
        if ( empty( $settings['publishable_key'] ) ) {
            $settings['publishable_key'] = (string) ( $gateway_test_mode ? ( $stripe['test_publishable_key'] ?? '' ) : ( $stripe['publishable_key'] ?? '' ) );
        }
        if ( empty( $settings['secret_key'] ) ) {
            $settings['secret_key'] = (string) ( $gateway_test_mode ? ( $stripe['test_secret_key'] ?? '' ) : ( $stripe['secret_key'] ?? '' ) );
        }
        if ( $using_fallback && ! array_key_exists( 'test_mode', $stored ) ) {
            $settings['test_mode'] = $gateway_test_mode ? 1 : 0;
        }
    }
    return $settings;
}

function mnu_native_admin_menu(): void {
    add_submenu_page( 'tnm-marketplace', 'Native Checkout', 'Native Checkout', 'manage_woocommerce', 'thenest-native-checkout', 'mnu_native_settings_page' );
}
add_action( 'admin_menu', 'mnu_native_admin_menu', 20 );

function mnu_native_settings_page(): void {
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        return;
    }
    $stored = (array) get_option( 'thenest_native_checkout_settings', array() );
    if ( isset( $_POST['mnu_native_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mnu_native_nonce'] ) ), 'save_thenest_native' ) ) {
        $stored = array_merge(
            mnu_native_settings_defaults(),
            $stored,
            array(
                'publishable_key'        => sanitize_text_field( wp_unslash( $_POST['publishable_key'] ?? '' ) ),
                'currency'                => strtolower( sanitize_key( wp_unslash( $_POST['currency'] ?? 'usd' ) ) ),
                'test_mode'               => ! empty( $_POST['test_mode'] ) ? 1 : 0,
                'flat_shipping'           => wc_format_decimal( wp_unslash( $_POST['flat_shipping'] ?? '6.95' ) ),
                'free_shipping_threshold' => wc_format_decimal( wp_unslash( $_POST['free_shipping_threshold'] ?? '50' ) ),
            )
        );
        if ( ! empty( $_POST['secret_key'] ) ) {
            $stored['secret_key'] = sanitize_text_field( wp_unslash( $_POST['secret_key'] ) );
        }
        if ( ! empty( $_POST['webhook_secret'] ) ) {
            $stored['webhook_secret'] = sanitize_text_field( wp_unslash( $_POST['webhook_secret'] ) );
        }
        update_option( 'thenest_native_checkout_settings', $stored, false );
        echo '<div class="notice notice-success"><p>Native checkout settings saved.</p></div>';
    }
    $settings = mnu_native_get_settings();
    ?>
    <div class="wrap"><h1><?php echo esc_html( get_bloginfo( 'name' ) . ' Native Checkout' ); ?></h1>
        <p>Dedicated keys override the active WooCommerce Stripe Gateway keys. Leave secret fields blank to keep their current values.</p>
        <form method="post"><?php wp_nonce_field( 'save_thenest_native', 'mnu_native_nonce' ); ?>
            <table class="form-table">
                <tr><th><label for="mnu-pk">Stripe publishable key</label></th><td><input id="mnu-pk" type="text" class="regular-text" name="publishable_key" value="<?php echo esc_attr( $stored['publishable_key'] ?? '' ); ?>"></td></tr>
                <tr><th><label for="mnu-sk">Stripe secret key</label></th><td><input id="mnu-sk" type="password" class="regular-text" name="secret_key" value="" autocomplete="new-password" placeholder="Leave blank to keep saved/fallback key"></td></tr>
                <tr><th><label for="mnu-wh">Stripe webhook signing secret</label></th><td><input id="mnu-wh" type="password" class="regular-text" name="webhook_secret" value="" autocomplete="new-password" placeholder="whsec_…"><p class="description">Required before the webhook can mark orders paid.</p></td></tr>
                <tr><th><label for="mnu-currency">Currency</label></th><td><input id="mnu-currency" type="text" name="currency" value="<?php echo esc_attr( $settings['currency'] ); ?>" maxlength="3"></td></tr>
                <tr><th><label for="mnu-flat">Fallback flat shipping</label></th><td><input id="mnu-flat" type="number" name="flat_shipping" value="<?php echo esc_attr( $settings['flat_shipping'] ); ?>" min="0" step="0.01"></td></tr>
                <tr><th><label for="mnu-free">Free-shipping threshold</label></th><td><input id="mnu-free" type="number" name="free_shipping_threshold" value="<?php echo esc_attr( $settings['free_shipping_threshold'] ); ?>" min="0" step="0.01"></td></tr>
                <tr><th>Test mode</th><td><label><input type="checkbox" name="test_mode" <?php checked( ! empty( $settings['test_mode'] ) ); ?>> Use test mode</label></td></tr>
            </table><?php submit_button(); ?>
        </form>
        <p>Webhook URL: <code><?php echo esc_html( rest_url( MNU_NATIVE_NS . '/stripe-webhook' ) ); ?></code></p>
    </div>
    <?php
}

function mnu_native_rest_routes(): void {
    register_rest_route(
        MNU_NATIVE_NS,
        '/health',
        array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => static function (): array {
                $settings = mnu_native_get_settings();
                return array(
                    'ok'                 => true,
                    'version'            => MNU_NATIVE_VERSION,
                    'stripe_configured'  => ! empty( $settings['publishable_key'] ) && ! empty( $settings['secret_key'] ),
                    'webhook_configured' => ! empty( $settings['webhook_secret'] ),
                );
            },
            'permission_callback' => '__return_true',
        )
    );
    register_rest_route( MNU_NATIVE_NS, '/checkout/quote', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => 'mnu_native_quote', 'permission_callback' => 'mnu_native_auth' ) );
    register_rest_route( MNU_NATIVE_NS, '/checkout/create-intent', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => 'mnu_native_create_intent', 'permission_callback' => 'mnu_native_auth' ) );
    register_rest_route( MNU_NATIVE_NS, '/checkout/complete', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => 'mnu_native_complete', 'permission_callback' => 'mnu_native_auth' ) );
    register_rest_route( MNU_NATIVE_NS, '/stripe-webhook', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => 'mnu_native_webhook', 'permission_callback' => '__return_true' ) );
}
add_action( 'rest_api_init', 'mnu_native_rest_routes' );

function mnu_native_auth( WP_REST_Request $request ): bool|WP_Error {
    return mnu_native_current_user_id( $request ) > 0 ? true : new WP_Error( 'not_logged_in', 'You must be logged in.', array( 'status' => 401 ) );
}

function mnu_native_cents( float|string $amount ): int {
    return (int) round( (float) $amount * 100 );
}

function mnu_native_calc_items( array $items, int $buyer_id = 0 ): array|WP_Error {
    $lines    = array();
    $subtotal = 0.0;
    foreach ( $items as $row ) {
        $product_id   = absint( $row['product_id'] ?? ( $row['product']['id'] ?? 0 ) );
        // v3.7.118 — variation_id support. When present, we price the picked
        // variation and store both ids so the WC order line carries the
        // variation reference (needed for stock + per-variation shipping).
        $variation_id = absint( $row['variation_id'] ?? 0 );
        $quantity     = max( 1, absint( $row['quantity'] ?? 1 ) );
        $parent       = wc_get_product( $product_id );
        $product      = $variation_id ? wc_get_product( $variation_id ) : $parent;
        $custom_buyer_purchase = $product && $buyer_id > 0 && class_exists( 'MNU_Custom_Requests' )
            && MNU_Custom_Requests::buyer_can_purchase_product( $product_id, $buyer_id );
        if ( ! $product || ( ! $product->is_purchasable() && ! $custom_buyer_purchase ) ) {
            return new WP_Error( 'invalid_product', 'One or more products are unavailable.', array( 'status' => 409, 'product_id' => $product_id ) );
        }
        if ( $variation_id && $product instanceof WC_Product_Variation ) {
            if ( (int) $product->get_parent_id() !== $product_id ) {
                return new WP_Error( 'invalid_variation', 'Variation does not belong to this product.', array( 'status' => 400, 'product_id' => $product_id ) );
            }
        }
        if ( ! $product->has_enough_stock( $quantity ) ) {
            return new WP_Error( 'insufficient_stock', ( $parent ? $parent->get_name() : $product->get_name() ) . ' does not have enough stock.', array( 'status' => 409, 'product_id' => $product_id ) );
        }
        // v3.11.0 — Under the sellers-off-Stripe money model, buyer add-to-cart
        // is only blocked when the seller has no bank account on file (no ACH
        // destination). The old gate here checked MNU_Connect charges_enabled
        // / payouts_enabled, which no longer reflects reality — sellers stopped
        // touching Stripe in v3.8.0. Fall back to the Stripe gate only when
        // MNU_Bank_Account is missing (defensive; the class is always loaded
        // once the plugin has booted).
        // v3.13.35 — Stripe Connect readiness is retired; the only gate
        // now is whether the seller has a bank account on file. The old
        // `elseif ( class_exists( 'MNU_Connect' ) )` fallback that read
        // charges_enabled / payouts_enabled from MNU_Connect has been
        // removed because those fields no longer reflect payout
        // eligibility (sellers are paid by manual ACH, not Stripe Connect).
        $seller_id = (int) tnm_get_product_seller_id( $parent ?: $product );
        if ( $seller_id > 0 && class_exists( 'MNU_Bank_Account' ) ) {
            if ( ! MNU_Bank_Account::has_bank_account( $seller_id ) ) {
                return new WP_Error( 'seller_not_ready', ( $parent ? $parent->get_name() : $product->get_name() ) . ' is temporarily unavailable because its seller has not finished payment setup.', array( 'status' => 409, 'product_id' => $product_id ) );
            }
        }
        $price      = (float) wc_get_price_excluding_tax( $product );
        $line_total = $price * $quantity;
        $subtotal  += $line_total;
        $lines[]    = array(
            'product'      => $product,
            'product_id'   => $product_id,
            'variation_id' => $variation_id,
            'quantity'     => $quantity,
            'price'        => $price,
            'line_total'   => $line_total,
            'name'         => ( $parent ? $parent->get_name() : $product->get_name() ),
        );
    }
    return $lines ? array( $lines, $subtotal ) : new WP_Error( 'empty_cart', 'No valid products found.', array( 'status' => 400 ) );
}

/**
 * v3.7.119 (Build #10) — Calculate a coupon discount for a native cart quote.
 * Mirrors the /coupons/apply validation but returns 0 (with a reason) instead
 * of throwing so a bad code never blocks checkout — the buyer just doesn't get
 * the discount and sees the reason on the client.
 *
 * @param array<int, array<string, mixed>> $lines Lines from mnu_native_calc_items().
 * @return array{discount: float, free_shipping: bool, reason: string, code: string, coupon_id: int}
 */
function mnu_native_coupon_discount( array $lines, string $code ): array {
    $result = array( 'discount' => 0.0, 'free_shipping' => false, 'reason' => '', 'code' => wc_format_coupon_code( $code ), 'coupon_id' => 0 );
    if ( '' === $result['code'] ) { return $result; }
    $coupon_id = wc_get_coupon_id_by_code( $result['code'] );
    if ( ! $coupon_id ) { $result['reason'] = 'coupon_not_found'; return $result; }
    $coupon = new WC_Coupon( $coupon_id );
    if ( $coupon->get_date_expires() && $coupon->get_date_expires()->getTimestamp() < current_time( 'timestamp', true ) ) {
        $result['reason'] = 'coupon_expired'; return $result;
    }
    if ( $coupon->get_usage_limit() > 0 && $coupon->get_usage_count() >= $coupon->get_usage_limit() ) {
        $result['reason'] = 'coupon_used_up'; return $result;
    }
    $allowed_ids = $coupon->get_product_ids();
    $eligible    = 0.0; $subtotal = 0.0;
    foreach ( $lines as $line ) {
        $subtotal += (float) $line['line_total'];
        if ( ! $allowed_ids || in_array( (int) $line['product_id'], $allowed_ids, true ) ) {
            $eligible += (float) $line['line_total'];
        }
    }
    if ( $coupon->get_minimum_amount() > 0 && $subtotal < (float) $coupon->get_minimum_amount() ) {
        $result['reason'] = 'coupon_min_amount'; return $result;
    }
    if ( $eligible <= 0 ) { $result['reason'] = 'coupon_not_applicable'; return $result; }
    $type = $coupon->get_discount_type();
    $amt  = (float) $coupon->get_amount();
    if ( 'percent' === $type )        { $result['discount'] = round( $eligible * ( $amt / 100 ), 2 ); }
    elseif ( 'fixed_cart' === $type ) { $result['discount'] = min( $eligible, $amt ); }
    elseif ( 'fixed_product' === $type ) { $result['discount'] = min( $eligible, $amt ); }
    $result['discount']      = max( 0.0, round( $result['discount'], 2 ) );
    $result['free_shipping'] = (bool) $coupon->get_free_shipping();
    $result['coupon_id']     = $coupon_id;
    return $result;
}

function mnu_native_flat_shipping( float $subtotal ): float {
    $settings  = mnu_native_get_settings();
    $threshold = max( 0, (float) $settings['free_shipping_threshold'] );
    return $threshold > 0 && $subtotal >= $threshold ? 0.0 : max( 0, (float) $settings['flat_shipping'] );
}

/**
 * Map an incoming (already-sanitized) address array to a WooCommerce shipping
 * package "destination" array. Country is normalized to a 2-letter uppercase
 * code (empty when invalid, which signals "no usable address").
 *
 * @param array<string, string> $address
 * @return array<string, string>
 */
function mnu_native_map_destination( array $address ): array {
    $country   = strtoupper( sanitize_text_field( (string) ( $address['country'] ?? '' ) ) );
    $address_1 = sanitize_text_field( (string) ( $address['address_1'] ?? $address['address'] ?? '' ) );

    return array(
        'country'   => preg_match( '/^[A-Z]{2}$/', $country ) ? $country : '',
        'state'     => sanitize_text_field( (string) ( $address['state'] ?? '' ) ),
        'postcode'  => sanitize_text_field( (string) ( $address['postcode'] ?? '' ) ),
        'city'      => sanitize_text_field( (string) ( $address['city'] ?? '' ) ),
        'address'   => $address_1,
        'address_1' => $address_1,
        'address_2' => sanitize_text_field( (string) ( $address['address_2'] ?? '' ) ),
    );
}

/**
 * Log a live-rate diagnostic, but only when WP_DEBUG is on, so production error
 * logs are never flooded by routine rate lookups.
 */
function mnu_native_shipping_debug_log( string $message ): void {
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( '[MyNest native checkout shipping] ' . $message );
    }
}

/**
 * Map a sanitized checkout destination address to a Shippo "to" address.
 *
 * @param array<string, string> $address
 * @return array<string, string>
 */
function mnu_native_shippo_destination( array $address ): array {
    $mapped = mnu_native_map_destination( $address );
    $name   = trim( (string) ( $address['first_name'] ?? '' ) . ' ' . (string) ( $address['last_name'] ?? '' ) );

    return array(
        'name'    => $name ?: 'Customer',
        'street1' => (string) $mapped['address_1'],
        'street2' => (string) $mapped['address_2'],
        'city'    => (string) $mapped['city'],
        'state'   => (string) $mapped['state'],
        'zip'     => (string) $mapped['postcode'],
        'country' => (string) $mapped['country'],
        'phone'   => (string) ( $address['phone'] ?? '' ),
        'email'   => (string) ( $address['email'] ?? '' ),
    );
}

/**
 * Build a seller's Shippo "from" address from their shipping profile.
 *
 * @return array<string, string>
 */
function mnu_native_seller_ship_from( int $seller_id ): array {
    $profile = mnu_ship_get_profile( $seller_id );
    $seller  = get_userdata( $seller_id );

    return array(
        'name'    => (string) ( $profile['ship_from_name'] ?: ( $seller ? $seller->display_name : tnm_seller_display_name( $seller_id ) ) ),
        'company' => (string) ( $profile['ship_from_company'] ?? '' ),
        'street1' => (string) ( $profile['ship_from_street1'] ?? '' ),
        'street2' => (string) ( $profile['ship_from_street2'] ?? '' ),
        'city'    => (string) ( $profile['ship_from_city'] ?? '' ),
        'state'   => (string) ( $profile['ship_from_state'] ?? '' ),
        'zip'     => (string) ( $profile['ship_from_zip'] ?? '' ),
        'country' => (string) ( $profile['ship_from_country'] ?: 'US' ),
        'phone'   => (string) ( $profile['ship_from_phone'] ?? '' ),
        'email'   => $seller ? (string) $seller->user_email : '',
    );
}

/**
 * Aggregate a Shippo parcel (summed weight, max dimensions) for a group of cart
 * lines that all ship from the same seller, mirroring the seller label flow's
 * per-order parcel builder but sourced from live cart lines.
 *
 * @param array<int, array<string, mixed>> $lines
 * @param array<string, string|bool>       $profile
 * @return array<string, string>|WP_Error
 */
function mnu_native_parcel_for_lines( array $lines, array $profile ): array|WP_Error {
    $weight = 0.0;
    $length = (float) ( $profile['default_length_in'] ?? 8 );
    $width  = (float) ( $profile['default_width_in'] ?? 6 );
    $height = (float) ( $profile['default_height_in'] ?? 2 );

    foreach ( $lines as $line ) {
        $product_id  = (int) $line['product_id'];
        $quantity    = max( 1, (int) $line['quantity'] );
        $item_weight = (float) ( get_post_meta( $product_id, '_thenest_weight_oz', true ) ?: ( $profile['default_weight_oz'] ?? 8 ) );

        $weight += max( 0.1, $item_weight ) * $quantity;
        $length  = max( $length, (float) ( get_post_meta( $product_id, '_thenest_length_in', true ) ?: $length ) );
        $width   = max( $width, (float) ( get_post_meta( $product_id, '_thenest_width_in', true ) ?: $width ) );
        $height  = max( $height, (float) ( get_post_meta( $product_id, '_thenest_height_in', true ) ?: $height ) );
    }

    if ( $weight <= 0 || $length <= 0 || $width <= 0 || $height <= 0 ) {
        return new WP_Error( 'invalid_parcel', 'Package weight and dimensions must all be greater than zero.' );
    }

    return array(
        'length'        => (string) max( 0.1, $length ),
        'width'         => (string) max( 0.1, $width ),
        'height'        => (string) max( 0.1, $height ),
        'distance_unit' => 'in',
        'weight'        => (string) max( 0.1, $weight ),
        'mass_unit'     => 'oz',
    );
}

/**
 * Compute real, live shipping rates for a set of cart lines shipped to a
 * destination address by calling Shippo directly — the SAME integration
 * (api.goshippo.com + `ShippoToken` auth + the `thenest_shippo_api_token`
 * option) that the working seller label flow uses via mnu_labels_shippo_request().
 *
 * Each seller in the cart ships from their own origin, so lines are grouped by
 * seller and rated as a separate Shippo shipment. For a single-seller cart the
 * seller's live service levels are returned as selectable options (keyed by a
 * STABLE carrier+service slug, not Shippo's per-request object_id, so the
 * quote → create-intent recompute can match the buyer's choice). For a
 * multi-seller cart the cheapest live rate per seller is summed into one honest
 * live total. Any failure returns a WP_Error so mnu_native_shipping_options()
 * degrades to the flat-rate estimate rather than breaking checkout.
 *
 * @param array<int, array<string, mixed>> $lines   Lines from mnu_native_calc_items().
 * @param array<string, string>            $address Sanitized destination address.
 * @return array<int, array<string, mixed>>|WP_Error List of {id,label,amount,method_id} or error.
 */
/**
 * Compute a per-seller shipping breakdown for the given cart lines and
 * destination. Reuses the same Shippo path that mnu_native_get_live_shipping_rates()
 * uses and returns [seller_id => cheapest_amount_in_currency] on success.
 *
 * Used at order-create time to persist a truthful shipping-by-seller map on
 * order meta so the ledger can allocate shipping to the seller who actually
 * incurred the label cost (instead of splitting proportional to product
 * subtotal, which under-credits sellers who ship a low-value item in a heavy
 * parcel and over-credits sellers who ship a high-value item in a small one).
 *
 * @param array<int, array<string, mixed>> $lines   Standard checkout lines.
 * @param array<string, string>            $address Destination address.
 * @return array<int, float>|WP_Error               Map of seller_id => cheapest rate amount.
 */
function mnu_native_shipping_breakdown_by_seller( array $lines, array $address ) {
    if ( ! function_exists( 'mnu_labels_shippo_request' ) || ! function_exists( 'mnu_ship_get_profile' ) ) {
        return new WP_Error( 'shipping_unavailable', 'Live shipping calculation is unavailable.' );
    }
    if ( '' === mnu_native_map_destination( $address )['country'] ) {
        return new WP_Error( 'invalid_shipping_address', 'A valid destination country is required to calculate shipping.' );
    }
    $to = mnu_native_shippo_destination( $address );
    if ( '' === $to['street1'] || '' === $to['city'] || '' === $to['zip'] ) {
        return new WP_Error( 'incomplete_shipping_address', 'A complete destination address is required for live rates.' );
    }

    $by_seller = array();
    foreach ( $lines as $line ) {
        $product = $line['product'] ?? null;
        if ( ! $product instanceof WC_Product ) {
            continue;
        }
        $seller_id = (int) tnm_get_product_seller_id( $product );
        if ( $seller_id <= 0 ) {
            continue;
        }
        $by_seller[ $seller_id ][] = $line;
    }
    if ( ! $by_seller ) {
        return new WP_Error( 'empty_cart', 'No shippable products found.' );
    }

    $breakdown = array();
    foreach ( $by_seller as $seller_id => $seller_lines ) {
        $profile = mnu_ship_get_profile( $seller_id );
        $from    = mnu_native_seller_ship_from( $seller_id );
        if ( '' === $from['street1'] || '' === $from['city'] || '' === $from['zip'] ) {
            return new WP_Error( 'incomplete_ship_from', 'The seller ship-from address is incomplete.' );
        }
        $parcel = mnu_native_parcel_for_lines( $seller_lines, $profile );
        if ( is_wp_error( $parcel ) ) {
            return $parcel;
        }
        $shipment = mnu_labels_shippo_request(
            '/shipments/',
            array(
                'address_from' => $from,
                'address_to'   => $to,
                'parcels'      => array( $parcel ),
                'async'        => false,
            )
        );
        if ( is_wp_error( $shipment ) ) {
            return $shipment;
        }
        $rates = function_exists( 'mnu_labels_sort_rates' )
            ? mnu_labels_sort_rates( isset( $shipment['rates'] ) && is_array( $shipment['rates'] ) ? $shipment['rates'] : array() )
            : array();
        if ( ! $rates ) {
            return new WP_Error( 'no_live_rates', 'No live shipping rates were returned.' );
        }
        $breakdown[ $seller_id ] = round( (float) ( $rates[0]['amount'] ?? 0 ), wc_get_price_decimals() );
    }
    return $breakdown;
}

function mnu_native_get_live_shipping_rates( array $lines, array $address ): array|WP_Error {
    if ( ! function_exists( 'mnu_labels_shippo_request' ) || ! function_exists( 'mnu_ship_get_profile' ) ) {
        return new WP_Error( 'shipping_unavailable', 'Live shipping calculation is unavailable.' );
    }

    if ( '' === mnu_native_map_destination( $address )['country'] ) {
        return new WP_Error( 'invalid_shipping_address', 'A valid destination country is required to calculate shipping.' );
    }

    $to = mnu_native_shippo_destination( $address );
    if ( '' === $to['street1'] || '' === $to['city'] || '' === $to['zip'] ) {
        return new WP_Error( 'incomplete_shipping_address', 'A complete destination address is required for live rates.' );
    }

    // Group cart lines by their selling vendor: each seller ships from their own
    // origin, so each needs its own Shippo shipment.
    $by_seller = array();
    foreach ( $lines as $line ) {
        $product = $line['product'] ?? null;
        if ( ! $product instanceof WC_Product ) {
            continue;
        }
        $seller_id                 = (int) tnm_get_product_seller_id( $product );
        $by_seller[ $seller_id ][] = $line;
    }
    if ( ! $by_seller ) {
        return new WP_Error( 'empty_cart', 'No shippable products found.' );
    }

    $single_seller    = 1 === count( $by_seller );
    $options          = array();
    $combined_cost    = 0.0;
    // v3.7.87 — per-seller rate/parcel snapshots so create-intent can
     // persist the exact rate the buyer paid for and auto-buy after payment.
    $seller_snapshots = array();

    foreach ( $by_seller as $seller_id => $seller_lines ) {
        if ( $seller_id <= 0 ) {
            return new WP_Error( 'no_ship_from', 'A product is missing its seller ship-from address.' );
        }

        $profile = mnu_ship_get_profile( $seller_id );
        $from    = mnu_native_seller_ship_from( $seller_id );
        if ( '' === $from['street1'] || '' === $from['city'] || '' === $from['zip'] ) {
            mnu_native_shipping_debug_log( sprintf( 'Seller %d has an incomplete ship-from address; skipping live rates.', $seller_id ) );
            return new WP_Error( 'incomplete_ship_from', 'The seller ship-from address is incomplete.' );
        }

        $parcel = mnu_native_parcel_for_lines( $seller_lines, $profile );
        if ( is_wp_error( $parcel ) ) {
            return $parcel;
        }

        $shipment = mnu_labels_shippo_request(
            '/shipments/',
            array(
                'address_from' => $from,
                'address_to'   => $to,
                'parcels'      => array( $parcel ),
                'async'        => false,
            )
        );
        if ( is_wp_error( $shipment ) ) {
            mnu_native_shipping_debug_log( sprintf( 'Shippo shipment failed for seller %d: %s', $seller_id, $shipment->get_error_message() ) );
            return $shipment;
        }

        $rates = function_exists( 'mnu_labels_sort_rates' )
            ? mnu_labels_sort_rates( isset( $shipment['rates'] ) && is_array( $shipment['rates'] ) ? $shipment['rates'] : array() )
            : array();
        if ( ! $rates ) {
            mnu_native_shipping_debug_log(
                sprintf(
                    'Shippo returned no valid rates for seller %d (shipment %s): %s',
                    $seller_id,
                    (string) ( $shipment['object_id'] ?? '' ),
                    function_exists( 'mnu_labels_shippo_error_message' ) ? mnu_labels_shippo_error_message( $shipment ) : 'no rates'
                )
            );
            return new WP_Error( 'no_live_rates', 'No live shipping rates were returned.' );
        }

        // v3.7.87 — always capture the cheapest rate per seller so
         // create-intent has a per-seller rate/parcel snapshot to buy a
         // label from after payment, even in multi-seller carts where the
         // buyer only sees one combined shipping line.
        $cheapest = $rates[0];
        $seller_snapshots[ $seller_id ] = array(
            'shipment_object_id' => (string) ( $shipment['object_id'] ?? '' ),
            'rate_object_id'     => (string) ( $cheapest['object_id'] ?? '' ),
            'provider'           => (string) ( $cheapest['provider'] ?? '' ),
            'service'            => (string) ( $cheapest['servicelevel']['name'] ?? $cheapest['servicelevel_name'] ?? '' ),
            'service_token'      => (string) ( $cheapest['servicelevel']['token'] ?? $cheapest['servicelevel_token'] ?? '' ),
            'amount'             => (float) ( $cheapest['amount'] ?? 0 ),
            'currency'           => (string) ( $cheapest['currency'] ?? 'USD' ),
            'parcel'             => $parcel,
            'rated_at'           => gmdate( 'c' ),
        );

        if ( $single_seller ) {
            foreach ( $rates as $rate ) {
                $provider  = (string) ( $rate['provider'] ?? '' );
                $service   = (string) ( $rate['servicelevel']['name'] ?? $rate['servicelevel_name'] ?? '' );
                $token     = (string) ( $rate['servicelevel']['token'] ?? $rate['servicelevel_token'] ?? '' );
                $slug      = sanitize_key( $provider . '_' . ( $token ?: $service ) );
                $options[] = array(
                    'id'                 => 'shippo_' . ( $slug ?: substr( md5( $provider . $service ), 0, 12 ) ),
                    'label'              => trim( $provider . ' ' . $service ) ?: 'Shipping',
                    'amount'             => round( (float) ( $rate['amount'] ?? 0 ), wc_get_price_decimals() ),
                    'method_id'          => 'shippo',
                    // v3.7.87 — opaque IDs the create-intent handler resolves
                    // back to the underlying seller/rate/parcel so auto-buy
                    // can call transactions.create({rate: rate_object_id}).
                    'seller_id'          => (int) $seller_id,
                    'shippo_rate_id'     => (string) ( $rate['object_id'] ?? '' ),
                    'shippo_shipment_id' => (string) ( $shipment['object_id'] ?? '' ),
                    'provider'           => $provider,
                    'service'            => $service,
                    'service_token'      => $token,
                );
            }
        } else {
            // mnu_labels_sort_rates() sorts cheapest-first, so [0] is the cheapest.
            $combined_cost += (float) ( $rates[0]['amount'] ?? 0 );
        }
    }

    if ( ! $single_seller ) {
        $options[] = array(
            'id'                 => 'shippo_combined',
            'label'               => 'Standard shipping',
            'amount'             => round( $combined_cost, wc_get_price_decimals() ),
            'method_id'          => 'shippo',
            // Combined lines don't map to a single Shippo rate id; the
            // create-intent handler uses the persisted per-seller snapshots.
            'combined'           => true,
        );
    }

    // v3.7.87 — stash the per-seller snapshots for the create-intent
    // handler to pick up. Kept out of the returned array so numeric
    // iterations elsewhere (cheapest_option, requested-id match) stay
    // clean; a static holder is fine here because the request lifecycle
    // is single-threaded.
    mnu_native_seller_snapshots( $seller_snapshots );

    return $options;
}

/**
 * v3.7.87 — request-scoped holder for per-seller Shippo rate snapshots.
 * Written by mnu_native_get_live_shipping_rates(), read by
 * mnu_native_create_intent_locked() before the order is created.
 *
 * @param array<int,array<string,mixed>>|null $set Snapshots to store, or null to read.
 * @return array<int,array<string,mixed>>
 */
function mnu_native_seller_snapshots( ?array $set = null ): array {
    static $current = array();
    if ( is_array( $set ) ) {
        $current = $set;
    }
    return $current;
}

/**
 * Resolve the list of shipping options a buyer can pick from. Returns live
 * carrier rates when available; otherwise degrades to a single synthetic
 * flat-rate option so checkout never breaks (task's fallback safety net).
 *
 * @param array<int, array<string, mixed>> $lines
 * @param array<string, string>            $address
 * @param string|null                      $debug_reason Out-param set to the failure reason when the flat fallback is used.
 * @return array<int, array<string, mixed>> Non-empty list of {id,label,amount,method_id}.
 */
function mnu_native_shipping_options( array $lines, float $subtotal, array $address, ?string &$debug_reason = null ): array {
    $live = mnu_native_get_live_shipping_rates( $lines, $address );
    if ( ! is_wp_error( $live ) && ! empty( $live ) ) {
        return $live;
    }

    // Live rates were unavailable; surface the concrete reason so the site owner
    // can diagnose why the flat estimate is being used instead.
    $debug_reason = is_wp_error( $live )
        ? $live->get_error_message()
        : 'No live shipping rates were returned.';

    return array(
        array(
            'id'        => 'thenest_standard',
            'label'     => 'Standard shipping',
            'amount'    => round( mnu_native_flat_shipping( $subtotal ), wc_get_price_decimals() ),
            'method_id' => 'thenest_standard',
        ),
    );
}

/**
 * Pick the cheapest option from a shipping-options list.
 *
 * @param array<int, array<string, mixed>> $options
 * @return array<string, mixed>
 */
function mnu_native_cheapest_option( array $options ): array {
    usort( $options, static fn( array $a, array $b ): int => ( (float) $a['amount'] ) <=> ( (float) $b['amount'] ) );
    return $options[0] ?? array();
}

/**
 * Read the shipping method/label/amount recorded on an order for API responses.
 *
 * @return array{amount: float, label: string, method_id: string}
 */
function mnu_native_order_shipping_summary( WC_Order $order ): array {
    $rate_id = (string) $order->get_meta( '_thenest_shipping_rate_id', true );
    $label   = (string) $order->get_meta( '_thenest_shipping_label', true );

    return array(
        'amount'    => (float) $order->get_shipping_total(),
        'label'     => $label ?: 'Standard shipping',
        'method_id' => $rate_id ?: 'thenest_standard',
    );
}

function mnu_native_quote_signature( string $body ): string {
    return tnm_base64url_encode( hash_hmac( 'sha256', $body, wp_salt( 'nonce' ), true ) );
}

function mnu_native_issue_quote_token( int $user_id, array $lines, float $subtotal, float $shipping, string $currency ): string {
    $payload = array(
        'sub'      => $user_id,
        'iat'      => time(),
        'exp'      => time() + ( 15 * MINUTE_IN_SECONDS ),
        'currency' => strtolower( $currency ),
        'subtotal' => round( $subtotal, wc_get_price_decimals() + 2 ),
        'shipping' => round( $shipping, wc_get_price_decimals() + 2 ),
        'items'    => array_map(
            static fn( array $line ): array => array(
                'product_id' => (int) $line['product_id'],
                'quantity'   => (int) $line['quantity'],
            ),
            $lines
        ),
    );
    $body = tnm_base64url_encode( wp_json_encode( $payload ) );
    return $body . '.' . mnu_native_quote_signature( $body );
}

function mnu_native_decode_quote_token( string $token, int $user_id ): array|WP_Error {
    $parts = explode( '.', $token );
    if ( 2 !== count( $parts ) ) {
        return new WP_Error( 'invalid_quote', 'The checkout quote is invalid.', array( 'status' => 409 ) );
    }
    [ $body, $signature ] = $parts;
    if ( ! hash_equals( mnu_native_quote_signature( $body ), $signature ) ) {
        return new WP_Error( 'invalid_quote', 'The checkout quote is invalid.', array( 'status' => 409 ) );
    }
    $decoded = tnm_base64url_decode( $body );
    $payload = $decoded ? json_decode( $decoded, true ) : null;
    if ( ! is_array( $payload ) || (int) ( $payload['sub'] ?? 0 ) !== $user_id || (int) ( $payload['exp'] ?? 0 ) < time() || ! is_array( $payload['items'] ?? null ) ) {
        return new WP_Error( 'expired_quote', 'The checkout quote expired. Refresh the cart and try again.', array( 'status' => 409 ) );
    }
    return $payload;
}

function mnu_native_quote( WP_REST_Request $request ): array|WP_Error {
    $user_id = mnu_native_current_user_id( $request );
    if ( ! $user_id ) {
        return new WP_Error( 'not_logged_in', 'You must be logged in.', array( 'status' => 401 ) );
    }
    $data   = (array) $request->get_json_params();
    $result = mnu_native_calc_items( is_array( $data['items'] ?? null ) ? $data['items'] : array(), $user_id );
    if ( is_wp_error( $result ) ) {
        return $result;
    }
    [ $lines, $subtotal ] = $result;
    $currency = strtolower( (string) mnu_native_get_settings()['currency'] );

    // An optional destination address unlocks real, live carrier rates. Without
    // one we keep the historical flat-rate estimate untouched.
    $raw_address  = is_array( $data['shipping_address'] ?? null )
        ? $data['shipping_address']
        : ( is_array( $data['shipping'] ?? null ) ? $data['shipping'] : array() );
    $address      = mnu_native_sanitize_address( $raw_address );
    $has_address  = '' !== ( mnu_native_map_destination( $address )['country'] );

    $shipping_rates = array();
    $debug_reason   = null;
    if ( $has_address ) {
        $options  = mnu_native_shipping_options( $lines, $subtotal, $address, $debug_reason );
        $cheapest = mnu_native_cheapest_option( $options );
        $shipping = (float) ( $cheapest['amount'] ?? mnu_native_flat_shipping( $subtotal ) );
        // Only expose REAL live carrier rates as selectable options. When the flat
        // estimate fallback was used, $debug_reason is set: keep shipping_rates
        // empty so the app shows its estimated-shipping fallback (and the debug
        // reason) instead of rendering the synthetic estimate as a real choice.
        if ( null === $debug_reason ) {
            $shipping_rates = array_map(
                static fn( array $opt ): array => array(
                    'id'     => (string) $opt['id'],
                    'label'  => (string) $opt['label'],
                    'amount' => (float) $opt['amount'],
                ),
                $options
            );
        }
    } else {
        $shipping     = mnu_native_flat_shipping( $subtotal );
        $debug_reason = 'A complete destination address (street, city, state, ZIP, and 2-letter country) is required to fetch live shipping rates; an estimate is shown instead.';
    }

    // v3.7.119 (Build #10) — coupon layer. Apply discount to subtotal; when
    // the coupon carries free_shipping, zero out shipping for this quote too.
    $coupon_code = sanitize_text_field( (string) ( $data['coupon_code'] ?? '' ) );
    $coupon      = mnu_native_coupon_discount( $lines, $coupon_code );
    $discount    = (float) $coupon['discount'];
    if ( $coupon['free_shipping'] && $discount >= 0 && $coupon_code ) {
        $shipping = 0.0;
    }

    // v3.13.23 — bake the flat $1.05 shipping-&-handling fee into every non-zero
    // shipping amount the buyer sees (the cheapest quote AND each selectable
    // rate). Was $0.43 in v3.8.0 — raised to better cover Stripe's real
    // 2.9% + 30¢ card fee across common order sizes. This must match the same
    // fee applied in mnu_native_create_intent_locked() so the quote total the
    // buyer confirmed equals the amount actually charged. Free shipping stays
    // free (no fee attached to a $0 line).
    $processing_fee_cents = (int) apply_filters( 'mnu_v380_processing_fee_cents', 105, null );
    $processing_fee       = $processing_fee_cents / 100;
    if ( $shipping > 0 ) {
        $shipping = round( $shipping + $processing_fee, wc_get_price_decimals() );
    }
    if ( ! empty( $shipping_rates ) ) {
        foreach ( $shipping_rates as $i => $rate ) {
            if ( (float) $rate['amount'] > 0 ) {
                $shipping_rates[ $i ]['amount'] = round( (float) $rate['amount'] + $processing_fee, wc_get_price_decimals() );
            }
        }
    }

    $total_before_tax = max( 0.0, ( $subtotal - $discount ) + $shipping );

    $response = array(
        'items' => array_map(
            static fn( array $line ): array => array(
                'product_id' => $line['product_id'],
                'name'       => $line['name'],
                'quantity'   => $line['quantity'],
                'line_total' => $line['line_total'],
            ),
            $lines
        ),
        'subtotal'      => $subtotal,
        'discount'      => $discount,
        'shipping'      => $shipping,
        'tax'           => 0,
        'tax_estimated' => true,
        'total'         => $total_before_tax,
        'currency'      => $currency,
        'expires_in'    => 15 * MINUTE_IN_SECONDS,
        'quote_token'   => mnu_native_issue_quote_token( $user_id, $lines, $subtotal, $shipping, $currency ),
    );
    if ( $coupon_code ) {
        $response['coupon'] = array(
            'code'          => $coupon['code'],
            'discount'      => $discount,
            'free_shipping' => (bool) $coupon['free_shipping'],
            'valid'         => '' === $coupon['reason'],
            'reason'        => $coupon['reason'],
        );
    }
    if ( $has_address ) {
        $response['shipping_rates'] = $shipping_rates;
    }
    // Always surface the fallback reason at the top level (not gated on
    // $has_address): the app renders the shipping box whenever it has a saved
    // address, and an incomplete/unmappable destination is itself a reason worth
    // showing to the site owner.
    if ( null !== $debug_reason && '' !== $debug_reason ) {
        $response['debug_reason'] = $debug_reason;
    }
    return $response;
}

function mnu_native_sanitize_address( mixed $address ): array {
    $allowed = array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone' );
    $clean   = array();
    foreach ( $allowed as $key ) {
        if ( isset( $address[ $key ] ) ) {
            $clean[ $key ] = 'email' === $key ? sanitize_email( $address[ $key ] ) : sanitize_text_field( $address[ $key ] );
        }
    }
    return $clean;
}

function mnu_native_create_order( int $user_id, array $lines, array $billing, array $shipping, float $shipping_total, string $checkout_token = '', string $shipping_title = 'Standard shipping', string $shipping_method_id = 'thenest_standard' ): WC_Order|WP_Error {
    $order = wc_create_order( array( 'customer_id' => $user_id, 'created_via' => 'the-nest-native-app' ) );
    if ( is_wp_error( $order ) ) {
        return $order;
    }
    foreach ( $lines as $line ) {
        $item_id = $order->add_product( $line['product'], $line['quantity'] );
        $item    = $item_id ? $order->get_item( $item_id ) : false;
        if ( $item instanceof WC_Order_Item_Product ) {
            TNM_Marketplace::stamp_item_snapshot( $item, $line['product'], $order );
            $item->save();
        }
    }
    $billing  = mnu_native_sanitize_address( $billing );
    $shipping = mnu_native_sanitize_address( $shipping ?: $billing );
    if ( $billing ) {
        $order->set_address( $billing, 'billing' );
    }
    if ( $shipping ) {
        $order->set_address( $shipping, 'shipping' );
    }
    $clean_title     = sanitize_text_field( $shipping_title ) ?: 'Standard shipping';
    $clean_method_id = $shipping_method_id ?: 'thenest_standard';
    if ( $shipping_total > 0 ) {
        $shipping_item = new WC_Order_Item_Shipping();
        $shipping_item->set_method_title( $clean_title );
        // sanitize_key would corrupt full carrier rate ids like "flat_rate:3";
        // the full id is preserved in order meta below for reporting/matching.
        $shipping_item->set_method_id( sanitize_key( $clean_method_id ) ?: 'thenest_standard' );
        $shipping_item->set_total( $shipping_total );
        $order->add_item( $shipping_item );
    }
    // Record the chosen rate id/label verbatim (even for $0 free shipping) so the
    // API can report the real selection and match it on reuse.
    $order->update_meta_data( '_thenest_shipping_rate_id', $clean_method_id );
    $order->update_meta_data( '_thenest_shipping_label', $clean_title );
    $order->set_payment_method( 'stripe' );
    $order->set_payment_method_title( 'Card — ' . get_bloginfo( 'name' ) . ' app' );
    if ( $checkout_token ) {
        $order->update_meta_data( '_thenest_checkout_token', $checkout_token );
    }
    // Persist a per-seller shipping breakdown so the ledger can allocate the
    // shipping line to the seller who actually incurred the label cost. Best
    // effort: any failure falls back to proportional allocation in
    // TNM_Ledger::create_order_rows(), which is fine for happy path (single
    // seller carries the entire shipping_total anyway).
    if ( $shipping_total > 0 ) {
        $ship_addr  = $shipping ?: $billing;
        $breakdown  = function_exists( 'mnu_native_shipping_breakdown_by_seller' )
            ? mnu_native_shipping_breakdown_by_seller( $lines, $ship_addr )
            : new WP_Error( 'unavailable', 'no helper' );
        if ( ! is_wp_error( $breakdown ) && ! empty( $breakdown ) ) {
            // If our per-seller sum differs from the chosen shipping_total
            // (buyer picked a non-cheapest rate on a single-seller cart, or
            // rounding), scale so the seller totals reconcile to what the
            // buyer actually paid.
            $sum = array_sum( $breakdown );
            if ( $sum > 0 && abs( $sum - $shipping_total ) > 0.001 ) {
                $scale = $shipping_total / $sum;
                foreach ( $breakdown as $sid => $amt ) {
                    $breakdown[ $sid ] = round( (float) $amt * $scale, wc_get_price_decimals() + 2 );
                }
            }
            $order->update_meta_data( '_mnu_shipping_by_seller', wp_json_encode( $breakdown ) );
        }
    }
    $order->calculate_taxes();
    $order->calculate_totals();
    $order->set_status( 'pending' );
    $order->save();
    TNM_Marketplace::stamp_order_sellers( $order );
    return $order;
}

function mnu_native_stripe_request( string $path, array $params, string $idempotency_key = '', array $extra_headers = array() ): array|WP_Error {
    $settings = mnu_native_get_settings();
    if ( empty( $settings['secret_key'] ) ) {
        return new WP_Error( 'stripe_not_configured', 'Stripe is not configured.', array( 'status' => 503 ) );
    }
    $headers = array( 'Authorization' => 'Basic ' . base64_encode( $settings['secret_key'] . ':' ) );
    if ( $idempotency_key ) {
        $headers['Idempotency-Key'] = substr( preg_replace( '/[^A-Za-z0-9_\-]/', '', $idempotency_key ), 0, 255 );
    }
    foreach ( $extra_headers as $header_name => $header_value ) {
        $headers[ $header_name ] = $header_value;
    }
    $response = wp_remote_post( 'https://api.stripe.com/v1' . $path, array( 'headers' => $headers, 'body' => $params, 'timeout' => 30 ) );
    if ( is_wp_error( $response ) ) {
        return $response;
    }
    $body = json_decode( wp_remote_retrieve_body( $response ), true );
    $code = wp_remote_retrieve_response_code( $response );
    if ( $code < 200 || $code >= 300 ) {
        return new WP_Error( 'stripe_error', sanitize_text_field( $body['error']['message'] ?? 'Stripe request failed.' ), array( 'status' => 502 ) );
    }
    return is_array( $body ) ? $body : new WP_Error( 'stripe_invalid_response', 'Stripe returned an invalid response.', array( 'status' => 502 ) );
}

function mnu_native_stripe_get( string $path ): array|WP_Error {
    $settings = mnu_native_get_settings();
    if ( empty( $settings['secret_key'] ) ) {
        return new WP_Error( 'stripe_not_configured', 'Stripe is not configured.', array( 'status' => 503 ) );
    }
    $response = wp_remote_get( 'https://api.stripe.com/v1' . $path, array( 'headers' => array( 'Authorization' => 'Basic ' . base64_encode( $settings['secret_key'] . ':' ) ), 'timeout' => 30 ) );
    if ( is_wp_error( $response ) ) {
        return $response;
    }
    $body = json_decode( wp_remote_retrieve_body( $response ), true );
    $code = wp_remote_retrieve_response_code( $response );
    if ( $code < 200 || $code >= 300 ) {
        return new WP_Error( 'stripe_error', sanitize_text_field( $body['error']['message'] ?? 'Stripe request failed.' ), array( 'status' => 502 ) );
    }
    return is_array( $body ) ? $body : new WP_Error( 'stripe_invalid_response', 'Stripe returned an invalid response.', array( 'status' => 502 ) );
}

/**
 * Get (or lazily create) the Stripe Customer for a buyer and cache its ID in
 * user meta. Stored per mode (test/live) so switching keys can't reuse a
 * customer that only exists in the other mode's Stripe account.
 */
function mnu_native_get_or_create_customer( int $user_id, array $settings ): string|WP_Error {
    $meta_key    = ! empty( $settings['test_mode'] ) ? '_thenest_stripe_customer_id_test' : '_thenest_stripe_customer_id_live';
    $customer_id = (string) get_user_meta( $user_id, $meta_key, true );
    if ( $customer_id ) {
        return $customer_id;
    }

    $user   = get_userdata( $user_id );
    $params = array( 'metadata[wp_user_id]' => $user_id );
    if ( $user ) {
        if ( is_email( $user->user_email ) ) {
            $params['email'] = $user->user_email;
        }
        $name = trim( $user->display_name );
        if ( $name ) {
            $params['name'] = $name;
        }
    }

    $customer = mnu_native_stripe_request( '/customers', $params, 'mynest_customer_' . $user_id );
    if ( is_wp_error( $customer ) ) {
        return $customer;
    }
    if ( empty( $customer['id'] ) ) {
        return new WP_Error( 'stripe_customer_error', 'Could not create a Stripe customer.', array( 'status' => 502 ) );
    }

    update_user_meta( $user_id, $meta_key, sanitize_text_field( $customer['id'] ) );
    return (string) $customer['id'];
}

/**
 * Create a short-lived ephemeral key so the PaymentSheet can display saved
 * payment methods for the customer. Requires a pinned Stripe-Version header.
 */
function mnu_native_create_ephemeral_key( string $customer_id ): array|WP_Error {
    return mnu_native_stripe_request(
        '/ephemeral_keys',
        array( 'customer' => $customer_id ),
        '',
        array( 'Stripe-Version' => '2024-06-20' )
    );
}

function mnu_native_find_existing_order( int $user_id, string $checkout_token ): WC_Order|false {
    if ( ! $checkout_token ) {
        return false;
    }
    // meta_query (not top-level meta_key/meta_value) so the lookup resolves under
    // both legacy post storage and HPOS — this plugin declares HPOS compatible,
    // and a lookup that silently matched nothing there would hand back a
    // duplicate order for every retry.
    //
    // v3.13.29 — include the completed statuses (`processing`, `on-hold`,
    // `completed`) so a client replaying create-intent after a successful
    // payment resolves to the SAME order it already paid for, instead of
    // getting a fresh order + fresh intent (which is the audit's finding #7).
    // The lookup remains scoped to the same customer so this cannot cross
    // buyers.
    $orders = wc_get_orders(
        array(
            'customer_id' => $user_id,
            'limit'       => 1,
            'status'      => array( 'pending', 'failed', 'processing', 'on-hold', 'completed' ),
            'return'      => 'objects',
            'meta_query'  => array(
                array(
                    'key'     => '_thenest_checkout_token',
                    'value'   => $checkout_token,
                    'compare' => '=',
                ),
            ),
        )
    );
    return $orders ? $orders[0] : false;
}

/**
 * Serialise create-intent calls that share a checkout token.
 *
 * mnu_native_create_intent_locked() looks an order up by token and creates one
 * when it finds none. Two concurrent requests carrying the same token can both
 * complete that lookup before either has saved an order, and each then creates
 * one — the exact duplicate the token exists to prevent. add_option() is the
 * atomic primitive available here (option_name is UNIQUE), so it doubles as a
 * mutex. Stale locks are stolen after MNU_NATIVE_INTENT_LOCK_TTL so a request
 * that died mid-flight cannot wedge a buyer's checkout permanently.
 */
const MNU_NATIVE_INTENT_LOCK_TTL = 60;

function mnu_native_intent_lock_acquire( int $user_id, string $checkout_token ): string|false {
    $name = 'mnu_intent_lock_' . md5( $user_id . '|' . $checkout_token );
    if ( add_option( $name, (string) time(), '', false ) ) {
        return $name;
    }
    if ( (int) get_option( $name, 0 ) < time() - MNU_NATIVE_INTENT_LOCK_TTL ) {
        update_option( $name, (string) time(), false );
        return $name;
    }
    return false;
}

function mnu_native_create_intent( WP_REST_Request $request ): array|WP_Error {
    $user_id = mnu_native_current_user_id( $request );
    if ( ! $user_id ) {
        return new WP_Error( 'not_logged_in', 'You must be logged in.', array( 'status' => 401 ) );
    }
    $data           = (array) $request->get_json_params();
    $checkout_token = sanitize_text_field( (string) ( $data['checkout_token'] ?? $data['request_id'] ?? '' ) );

    // v3.13.29 — the checkout token is REQUIRED. Previously a missing token
    // silently bypassed both the existing-order lookup and the mutex, so an
    // older app build could double-tap Pay and create two Woo orders + two
    // Stripe PaymentIntents. Reject the request instead of quietly enabling
    // a duplicate-charge mode. All current app builds (v1.0.x) send a token;
    // any client without one is running a build old enough that it should
    // update anyway.
    if ( ! $checkout_token ) {
        return new WP_Error(
            'checkout_token_required',
            'This version of the app is too old to check out safely. Please update ShopMyNest and try again.',
            array( 'status' => 426 )
        );
    }

    $lock = mnu_native_intent_lock_acquire( $user_id, $checkout_token );
    if ( ! $lock ) {
        return new WP_Error(
            'checkout_in_progress',
            'This checkout is already being set up. Give it a moment and try again.',
            array( 'status' => 409 )
        );
    }
    try {
        return mnu_native_create_intent_locked( $user_id, $data, $checkout_token );
    } finally {
        delete_option( $lock );
    }
}

/**
 * v3.8.0 — new money model. Buyer pays product + shipping (real Shippo rate
 * + $1.05 handling fee baked into the shipping line). 100% of the charge
 * lands in the platform Stripe account: no destination charge, no
 * application_fee_amount, no Connect involvement at intent creation. Sellers
 * are paid later via manual ACH from the platform's business checking
 * (Bluevine) after a 7-day holding window. See TNM_Ledger::create_order_rows()
 * for the seller ledger row (net = product × 0.90).
 *
 * This function only stamps reconciliation metadata on the order. It is called
 * from mnu_native_create_intent_locked() right before the payment intent is
 * created.
 *
 * @since 3.8.0
 */
function mnu_native_stamp_v380_intent_meta( WC_Order $order ): void {
    $total_cents          = mnu_native_cents( $order->get_total() );
    $stripe_fee_est_cents = mnu_native_estimate_stripe_fee_cents( $total_cents );

    // Snapshot each seller's product cents so the payout admin table and the
    // ledger row don't have to re-derive it from items later.
    $product_cents_by_seller = array();
    foreach ( $order->get_items() as $item ) {
        if ( ! $item instanceof WC_Order_Item_Product ) { continue; }
        $seller_id = (int) $item->get_meta( '_tnm_seller_id', true );
        if ( $seller_id <= 0 ) { continue; }
        $product_cents_by_seller[ $seller_id ] = ( $product_cents_by_seller[ $seller_id ] ?? 0 )
            + mnu_native_cents( (float) $item->get_total() + (float) $item->get_subtotal_tax() );
    }

    $order->update_meta_data( '_mnu_v380_model',                    '1' );
    $order->update_meta_data( '_mnu_stripe_fee_estimate_cents',     (string) $stripe_fee_est_cents );
    $order->update_meta_data( '_mnu_v380_product_cents_by_seller',  wp_json_encode( $product_cents_by_seller ) );
    $order->save();
}

/**
 * DEPRECATED in v3.8.0. Kept as a no-op shim so no legacy call site errors.
 * All new orders take the plain platform-charge path (no Connect).
 *
 * @deprecated 3.8.0 Use mnu_native_stamp_v380_intent_meta() instead.
 */
function mnu_native_connect_intent_params( WC_Order $order ): array {
    return array();
}

/**
 * Estimate the Stripe processing fee for a charge in cents. Defaults to the
 * US card rate (2.9% + 30¢). Filterable via `mnu_stripe_processing_fee_estimate`
 * so we can tune per-country or per-payment-method later without another
 * plugin release.
 *
 * @since 3.7.122.10
 */
function mnu_native_estimate_stripe_fee_cents( int $total_cents ): int {
    if ( $total_cents <= 0 ) {
        return 0;
    }
    // ceil() ensures we don't systematically under-charge; a small over-estimate
    // is fine because the delta shows up as platform gain that averages to ~zero.
    $fee = (int) ceil( $total_cents * 0.029 ) + 30;
    return (int) apply_filters( 'mnu_stripe_processing_fee_estimate', $fee, $total_cents );
}

function mnu_native_create_intent_locked( int $user_id, array $data, string $checkout_token ): array|WP_Error {
    $order          = mnu_native_find_existing_order( $user_id, $checkout_token );
    $shipping_changed = false;

    if ( ! $order ) {
        $quote_token = sanitize_text_field( (string) ( $data['quote_token'] ?? '' ) );
        if ( $quote_token ) {
            $quote = mnu_native_decode_quote_token( $quote_token, $user_id );
            if ( is_wp_error( $quote ) ) {
                return $quote;
            }
            $source_items = $quote['items'];
        } else {
            // Compatibility path for older app builds: calculate all money on the
            // server and ignore any client-supplied shipping amount.
            $quote        = null;
            $source_items = is_array( $data['items'] ?? null ) ? $data['items'] : array();
        }

        $result = mnu_native_calc_items( $source_items, $user_id );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        [ $lines, $subtotal ] = $result;

        if ( is_array( $quote ) && abs( (float) $quote['subtotal'] - $subtotal ) > 0.01 ) {
            return new WP_Error( 'quote_changed', 'A product price changed. Refresh the cart and try again.', array( 'status' => 409 ) );
        }

        // Resolve shipping. If the app sends a destination address, ALWAYS
        // recompute live rates on the server and only trust which rate id the
        // client picked — never a client-supplied amount (anti-tampering).
        $billing_raw  = is_array( $data['billing'] ?? null ) ? $data['billing'] : array();
        $shipping_raw = is_array( $data['shipping'] ?? null ) ? $data['shipping'] : array();
        $address_raw  = is_array( $data['shipping_address'] ?? null ) ? $data['shipping_address'] : $shipping_raw;
        $address      = mnu_native_sanitize_address( $address_raw );
        $has_address  = '' !== ( mnu_native_map_destination( $address )['country'] );

        if ( $has_address ) {
            $options      = mnu_native_shipping_options( $lines, $subtotal, $address );
            $requested_id = sanitize_text_field( (string) ( $data['shipping_method_id'] ?? '' ) );
            $chosen       = array();
            foreach ( $options as $opt ) {
                if ( '' !== $requested_id && (string) $opt['id'] === $requested_id ) {
                    $chosen = $opt;
                    break;
                }
            }
            if ( ! $chosen ) {
                // Requested rate is gone (or none supplied): fall back to the
                // cheapest live rate rather than erroring, and flag the change.
                $chosen = mnu_native_cheapest_option( $options );
                if ( '' !== $requested_id ) {
                    $shipping_changed = true;
                }
            }
            $shipping_total  = (float) ( $chosen['amount'] ?? 0 );
            $shipping_title  = (string) ( $chosen['label'] ?? 'Standard shipping' );
            $shipping_method = (string) ( $chosen['id'] ?? 'thenest_standard' );

            // v3.7.87 — if the buyer picked a non-cheapest rate on a single-seller
            // cart, overwrite that seller's snapshot with the exact rate they paid
            // for so auto-buy purchases the same service the buyer selected.
            if ( ! empty( $chosen['shippo_rate_id'] ) && ! empty( $chosen['seller_id'] ) ) {
                $snaps = mnu_native_seller_snapshots();
                if ( isset( $snaps[ (int) $chosen['seller_id'] ] ) ) {
                    $snaps[ (int) $chosen['seller_id'] ] = array_merge(
                        $snaps[ (int) $chosen['seller_id'] ],
                        array(
                            'rate_object_id' => (string) $chosen['shippo_rate_id'],
                            'provider'       => (string) ( $chosen['provider'] ?? '' ),
                            'service'        => (string) ( $chosen['service'] ?? '' ),
                            'service_token'  => (string) ( $chosen['service_token'] ?? '' ),
                            'amount'         => (float) $chosen['amount'],
                        )
                    );
                    mnu_native_seller_snapshots( $snaps );
                }
            }
        } else {
            // No address: preserve the historical behaviour exactly.
            $shipping_total  = is_array( $quote )
                ? max( 0, (float) $quote['shipping'] )
                : mnu_native_flat_shipping( $subtotal );
            $shipping_title  = 'Standard shipping';
            $shipping_method = 'thenest_standard';
        }

        // v3.13.23 — bake the flat $1.05 buyer-paid handling fee INTO the
        // shipping line so the buyer sees a single Shipping row. The real
        // Shippo rate ($shipping_total pre-adjust) is preserved separately
        // as `_mnu_real_shipping_cents` so the auto-label service still buys
        // the label at its actual carrier cost. Skip on free-shipping /
        // no-shipping carts to avoid attaching a fee to a $0 line.
        //
        // v3.13.28 — the fee is added by the quote (see the response
        // assembly around line ~792 and $shipping_rates[i]['amount']
        // adjustment). If we get here from any quote-derived source —
        // meaning $chosen came from $shipping_rates OR $quote['shipping']
        // was passed through — the fee is ALREADY in $shipping_total.
        // Adding it again here charged the buyer $2.10 instead of $1.05.
        //
        // Only mnu_native_flat_shipping() returns a raw rate with no fee,
        // and that only happens when we have NO address AND no quote. That
        // is the sole case where we must add the fee here.
        $processing_fee_cents = (int) apply_filters( 'mnu_v380_processing_fee_cents', 105, $order ?? null );
        $processing_fee       = $processing_fee_cents / 100;
        $quote_carried_fee    = is_array( $quote );  // both with-address (via $shipping_rates) and no-address (via $quote['shipping']) already include it
        if ( $shipping_total > 0 && ! $quote_carried_fee ) {
            $shipping_total = round( $shipping_total + $processing_fee, wc_get_price_decimals() );
        }

        // Recover the true carrier rate for label-purchase accounting. If the
        // quote carried the fee, subtract it out; otherwise $shipping_total
        // is already the raw rate.
        $real_shipping_total = $quote_carried_fee && $shipping_total > 0
            ? round( max( 0.0, $shipping_total - $processing_fee ), wc_get_price_decimals() )
            : (float) $shipping_total;
        if ( $shipping_total <= 0 ) {
            $processing_fee_cents = 0;
        }

        $order = mnu_native_create_order(
            $user_id,
            $lines,
            $billing_raw,
            $address_raw ?: $shipping_raw,
            $shipping_total,
            $checkout_token,
            $shipping_title,
            $shipping_method
        );
        if ( is_wp_error( $order ) ) {
            return $order;
        }
        if ( $quote_token ) {
            $order->update_meta_data( '_thenest_quote_token_hash', hash( 'sha256', $quote_token ) );
            $order->save();
        }

        // v3.8.0 — stamp real vs buyer-visible shipping so the label service
        // uses the real carrier rate and the ledger/refund path can subtract
        // the processing fee from platform net cleanly.
        if ( $shipping_total > 0 ) {
            $order->update_meta_data( '_mnu_real_shipping_cents',    (string) mnu_native_cents( $real_shipping_total ) );
            $order->update_meta_data( '_mnu_processing_fee_cents',   (string) $processing_fee_cents );
            $order->save();
        }

        // v3.7.119 (Build #10) — apply coupon to the newly created order.
        // We compute the discount on the server against the same lines so a
        // tampered client-side amount can't change what the buyer is charged.
        $coupon_code = sanitize_text_field( (string) ( $data['coupon_code'] ?? '' ) );
        if ( '' !== $coupon_code ) {
            $coupon_result = mnu_native_coupon_discount( $lines, $coupon_code );
            if ( $coupon_result['discount'] > 0 || $coupon_result['free_shipping'] ) {
                $wc_coupon = new WC_Coupon( $coupon_result['coupon_id'] );
                // WC's built-in apply_coupon handles usage counting + validates,
                // but we want the specific discount amount from our
                // seller-scoped calculation. Add as an explicit fee-line coupon.
                $order->apply_coupon( $wc_coupon );
                if ( $coupon_result['free_shipping'] ) {
                    foreach ( $order->get_items( 'shipping' ) as $ship_item ) {
                        $ship_item->set_total( '0' );
                        $ship_item->save();
                    }
                }
                $order->update_meta_data( '_mnu_coupon_code', $coupon_result['code'] );
                $order->update_meta_data( '_mnu_coupon_discount', (string) $coupon_result['discount'] );
                $order->calculate_totals();
            }
        }

        // v3.7.87 — persist the per-seller Shippo rate/parcel snapshots so
        // the auto-label service can buy each seller's label immediately
        // after payment succeeds, without re-quoting.
        $snapshots = mnu_native_seller_snapshots();
        if ( ! empty( $snapshots ) ) {
            foreach ( $snapshots as $sid => $snap ) {
                $sid = (int) $sid;
                if ( $sid <= 0 ) { continue; }
                $order->update_meta_data( '_mnu_ship_rate_id_'   . $sid, (string) ( $snap['rate_object_id'] ?? '' ) );
                $order->update_meta_data( '_mnu_ship_shipment_'  . $sid, (string) ( $snap['shipment_object_id'] ?? '' ) );
                $order->update_meta_data( '_mnu_ship_provider_'  . $sid, (string) ( $snap['provider'] ?? '' ) );
                $order->update_meta_data( '_mnu_ship_service_'   . $sid, (string) ( $snap['service'] ?? '' ) );
                $order->update_meta_data( '_mnu_ship_amount_'    . $sid, (string) ( $snap['amount'] ?? '' ) );
                $order->update_meta_data( '_mnu_ship_currency_'  . $sid, (string) ( $snap['currency'] ?? 'USD' ) );
                $order->update_meta_data( '_mnu_ship_parcel_'    . $sid, wp_json_encode( (array) ( $snap['parcel'] ?? array() ) ) );
                $order->update_meta_data( '_mnu_ship_rated_at_'  . $sid, (string) ( $snap['rated_at'] ?? gmdate( 'c' ) ) );
            }
            $order->update_meta_data( '_mnu_ship_snapshot_sellers', wp_json_encode( array_map( 'intval', array_keys( $snapshots ) ) ) );
            $order->save();
        }
    }

    $settings = mnu_native_get_settings();

    $stripe_customer_id = mnu_native_get_or_create_customer( $user_id, $settings );
    if ( is_wp_error( $stripe_customer_id ) ) {
        return $stripe_customer_id;
    }

    $intent_id = (string) $order->get_meta( '_thenest_stripe_payment_intent', true );
    if ( $intent_id ) {
        $intent = mnu_native_stripe_get( '/payment_intents/' . rawurlencode( $intent_id ) );
    } else {
        // v3.8.0 — plain platform charge. 100% of the buyer's charge lands
        // in the platform Stripe account. Sellers are paid via manual ACH
        // from the platform's business checking after a 7-day holding
        // window (see TNM_Ledger). No Connect destination charge, no
        // application_fee_amount.
        mnu_native_stamp_v380_intent_meta( $order );
        $intent_params = array(
            'amount'                             => mnu_native_cents( $order->get_total() ),
            'currency'                           => strtolower( $settings['currency'] ),
            'customer'                           => $stripe_customer_id,
            'automatic_payment_methods[enabled]' => 'true',
            'metadata[wc_order_id]'              => $order->get_id(),
            'metadata[customer_id]'              => $user_id,
            'metadata[source]'                   => 'the_nest_native_app',
            'metadata[money_model]'              => 'v380_platform_charge',
            'description'                        => 'MyNest order #' . $order->get_order_number(),
        );
        // v3.13.29 — idempotency key incorporates the checkout token, so a
        // late retry that races the lock (or arrives after the lock TTL has
        // rolled over) still hits the SAME Stripe intent. Order id + token
        // fully identify one buyer attempt, and Stripe returns the original
        // intent for a repeat post rather than creating a second one.
        $idem_key = 'mynest_order_' . $order->get_id();
        if ( $checkout_token ) {
            $idem_key .= '_' . substr( md5( $checkout_token ), 0, 16 );
        }
        $intent = mnu_native_stripe_request(
            '/payment_intents',
            $intent_params,
            $idem_key
        );
    }
    if ( is_wp_error( $intent ) ) {
        return $intent;
    }

    $ephemeral_key = mnu_native_create_ephemeral_key( $stripe_customer_id );
    if ( is_wp_error( $ephemeral_key ) ) {
        return $ephemeral_key;
    }

    $order->update_meta_data( '_thenest_stripe_payment_intent', sanitize_text_field( $intent['id'] ) );
    $order->save();
    $ship_summary = mnu_native_order_shipping_summary( $order );
    return array(
        'publishable_key'    => $settings['publishable_key'],
        'client_secret'      => $intent['client_secret'] ?? '',
        'payment_intent_id'  => $intent['id'],
        'customer_id'        => $stripe_customer_id,
        'ephemeral_key_secret'=> $ephemeral_key['secret'] ?? '',
        'order_id'           => $order->get_id(),
        'amount'             => (float) $order->get_total(),
        'currency'           => strtolower( $settings['currency'] ),
        'shipping_total'     => $ship_summary['amount'],
        'shipping_label'     => $ship_summary['label'],
        'shipping_method_id' => $ship_summary['method_id'],
        'shipping_selection_changed' => $shipping_changed,
        // v3.13.24 — items subtotal the server just computed from live WC
        // prices. The mobile client compares this against the cart display
        // to catch stale-price drift (seller edited a listing after the
        // buyer added it to cart) and re-prompt before charging.
        'subtotal'           => round( (float) $subtotal, wc_get_price_decimals() + 2 ),
    );
}

function mnu_native_complete( WP_REST_Request $request ): array|WP_Error {
    $user_id = mnu_native_current_user_id( $request );
    $data    = (array) $request->get_json_params();
    $order   = wc_get_order( absint( $data['order_id'] ?? 0 ) );
    if ( ! $order ) {
        return new WP_Error( 'invalid_order', 'Order not found.', array( 'status' => 404 ) );
    }
    if ( (int) $order->get_customer_id() !== $user_id && ! user_can( $user_id, 'manage_woocommerce' ) && ! user_can( $user_id, 'manage_options' ) ) {
        return new WP_Error( 'forbidden', 'You cannot complete this order.', array( 'status' => 403 ) );
    }
    $saved_intent = (string) $order->get_meta( '_thenest_stripe_payment_intent', true );
    $intent_id    = sanitize_text_field( (string) ( $data['payment_intent_id'] ?? $saved_intent ) );
    if ( ! $intent_id || ( $saved_intent && ! hash_equals( $saved_intent, $intent_id ) ) ) {
        return new WP_Error( 'invalid_payment_intent', 'The payment intent does not match this order.', array( 'status' => 409 ) );
    }
    $intent = mnu_native_stripe_get( '/payment_intents/' . rawurlencode( $intent_id ) );
    if ( is_wp_error( $intent ) ) {
        return $intent;
    }
    if ( 'succeeded' === ( $intent['status'] ?? '' ) ) {
        $expected_amount = mnu_native_cents( $order->get_total() );
        $received_amount = (int) ( $intent['amount_received'] ?? $intent['amount'] ?? 0 );
        $expected_currency = strtolower( (string) mnu_native_get_settings()['currency'] );
        $received_currency = strtolower( sanitize_key( (string) ( $intent['currency'] ?? '' ) ) );
        if ( $expected_amount !== $received_amount || $expected_currency !== $received_currency ) {
            $order->add_order_note( 'Native checkout payment verification failed because the Stripe amount or currency did not match the order.' );
            $order->save();
            return new WP_Error( 'payment_amount_mismatch', 'The payment amount did not match this order.', array( 'status' => 409 ) );
        }
        if ( ! $order->is_paid() ) {
            // v3.13.28 — stamp the v3.8 platform-charge markers BEFORE
            // payment_complete() fires, because payment_complete() triggers
            // the ledger capture and the auto-label purchase, both of which
            // depend on `_mnu_platform_shipping_kept_cents` being set to
            // keep shipping on the platform side. Previously stamping only
            // happened in the webhook, and the webhook skipped it when the
            // order was already paid — causing native-sync-completed orders
            // to write a negative postage row against the seller. Also stamp
            // the latest charge id here so refund/dispute lookups have it on
            // both the sync and webhook paths.
            $latest_charge = sanitize_text_field( (string) ( $intent['latest_charge'] ?? '' ) );
            if ( '' !== $latest_charge ) {
                $order->update_meta_data( '_thenest_stripe_latest_charge', $latest_charge );
            }
            mnu_native_issue_seller_transfers( $order, $latest_charge );
            $order->payment_complete( $intent_id );
            $order->add_order_note( 'Paid through The Nest native checkout.' );
            // v3.7.87 — auto-buy the buyer's selected label. Idempotent, so
            // it's safe if the webhook path already ran this action.
            do_action( 'mnu_native_payment_succeeded', $order->get_id() );
        }
        return array( 'ok' => true, 'status' => $order->get_status(), 'order_id' => $order->get_id() );
    }
    return array( 'ok' => false, 'payment_status' => $intent['status'] ?? 'unknown', 'order_status' => $order->get_status() );
}

/**
 * v3.7.34 — Compute per-seller subtotals for an order.
 *
 * Sums each order item's line_subtotal + line_subtotal_tax by _tnm_seller_id.
 * Shipping is intentionally excluded here — it belongs to whichever seller
 * shipped the parcel and is handled elsewhere. Returns array keyed by
 * seller_id, values in cents:
 *
 *   array(
 *     123 => array( 'gross_cents' => 4500, 'fee_cents' => 450, 'net_cents' => 4050 ),
 *     456 => array( 'gross_cents' => 1200, 'fee_cents' => 120, 'net_cents' => 1080 ),
 *   )
 *
 * `fee_cents` comes from _tnm_platform_fee stamped by stamp_item_snapshot() at
 * cart time (the 10% marketplace fee). If a line is missing the fee meta we
 * fall back to 10% of gross.
 *
 * @return array<int, array<string, int>>
 */
function mnu_native_seller_splits( WC_Order $order ): array {
    $splits = array();
    foreach ( $order->get_items() as $item ) {
        if ( ! $item instanceof WC_Order_Item_Product ) {
            continue;
        }
        $seller_id = (int) $item->get_meta( '_tnm_seller_id', true );
        if ( $seller_id <= 0 ) {
            continue;
        }
        $line_gross = (float) $item->get_subtotal() + (float) $item->get_subtotal_tax();
        $line_fee   = (float) $item->get_meta( '_tnm_platform_fee', true );
        if ( $line_fee <= 0 ) {
            // Fallback: current platform fee percentage of the pre-tax subtotal,
            // matching stamp_item_snapshot(). v3.9.1 — reads from the live
            // setting so a future percentage change doesn't leave this line
            // stuck at the old value.
            $rate     = function_exists( 'tnm_fee_percent' ) ? ( (float) tnm_fee_percent() / 100.0 ) : 0.10;
            $line_fee = round( (float) $item->get_subtotal() * $rate, 2 );
        }
        if ( ! isset( $splits[ $seller_id ] ) ) {
            $splits[ $seller_id ] = array( 'gross_cents' => 0, 'fee_cents' => 0, 'net_cents' => 0 );
        }
        $splits[ $seller_id ]['gross_cents'] += (int) round( $line_gross * 100 );
        $splits[ $seller_id ]['fee_cents']   += (int) round( $line_fee * 100 );
    }
    foreach ( $splits as $sid => $row ) {
        $splits[ $sid ]['net_cents'] = max( 0, $row['gross_cents'] - $row['fee_cents'] );
    }
    return $splits;
}

/**
 * v3.7.34 — After a multi-seller charge captures on the platform, issue one
 * Stripe Transfer per seller (Separate Charges & Transfers pattern). Records
 * the transfer ids in _mnu_seller_transfers so a later refund can reverse
 * them.
 *
 * Single-seller carts already use a destination charge in
 * mnu_native_connect_intent_params(), so this function is a no-op for them.
 *
 * @param WC_Order $order
 * @param string   $charge_id  The Stripe charge id (from intent.latest_charge)
 */
function mnu_native_issue_seller_transfers( WC_Order $order, string $charge_id ): void {
    // v3.11.0 — Sellers are OFF Stripe entirely. 100% of the buyer's charge
    // stays at the platform and sellers are paid by manual ACH from the
    // platform business checking account after the 7-day holding window
    // (see WP Admin → MyNest → Payouts). This function used to fan out
    // Stripe Connect transfers to each seller; that branch has been
    // retired unconditionally to make it impossible for a legacy
    // Connect-onboarded seller to receive a real transfer on top of
    // their manual ACH.
    //
    // The function is kept (rather than deleted) so the existing
    // payment_intent.succeeded webhook call site keeps compiling and any
    // future retry / reconciliation code paths keep a single stamping
    // entry point. It now only stamps two order metas that downstream
    // ledger / reconciliation code still reads:
    //   _mnu_platform_shipping_kept_cents — shipping stays with platform.
    //   _mnu_seller_transfers             — audit trail explaining the skip.
    //
    // $charge_id is retained in the signature so older callers still
    // work, but it is intentionally unused now.
    unset( $charge_id );

    $existing = (array) json_decode( (string) $order->get_meta( '_mnu_seller_transfers', true ), true );
    if ( ! empty( $existing ) ) {
        return; // Already processed.
    }

    $order->update_meta_data(
        '_mnu_platform_shipping_kept_cents',
        (string) mnu_native_cents( (float) $order->get_shipping_total() )
    );
    $order->update_meta_data(
        '_mnu_seller_transfers',
        wp_json_encode(
            array(
                '__v380' => array(
                    'status' => 'skipped',
                    'reason' => 'v3.11.0 — sellers OFF Stripe. Manual ACH payouts from platform business checking after the 7-day holding window.',
                ),
            )
        )
    );
    $order->add_order_note( 'Skipped per-seller Stripe transfers (v3.11.0: sellers off Stripe entirely). Seller earnings will be paid manually via ACH after the holding window.' );
    $order->save();

    // v3.7.65 / v3.11.0 — the split-payment guardrail still runs so admin
    // reconciliation queues stay honest for multi-seller orders. Under the
    // new money model the "expected" state is that no per-seller transfer
    // exists, which the guardrail already tolerates because expected =
    // actual_sent ∪ actual_held for v3.8.0-model orders.
    mnu_native_apply_split_guardrail( $order );
}

/**
 * Compute the split-payment guardrail state for an order without persisting
 * anything. Returns an array with:
 *   status         — 'ok' | 'held' | 'single_seller' | 'unpaid'
 *   expected       — list of seller ids the order was supposed to fund
 *   actual_sent    — list of seller ids with a successful Stripe transfer
 *   actual_held    — list of seller ids marked held (no connected account)
 *   actual_failed  — list of seller ids whose transfer errored
 *   missing        — expected but not present in transfer meta at all
 *   issues         — human-readable strings for the admin queue
 */
function mnu_native_check_split_guardrail( WC_Order $order ): array {
    $out = array(
        'status'        => 'ok',
        'expected'      => array(),
        'actual_sent'   => array(),
        'actual_held'   => array(),
        'actual_failed' => array(),
        'missing'       => array(),
        'issues'        => array(),
    );
    // Guardrail applies once transfers have been (or should have been) issued.
    // is_paid() returns true for processing/completed; explicit 'refunded'
    // still had transfers so we evaluate it (transfers should be 'reversed').
    $status_slug = $order->get_status();
    $applies     = $order->is_paid() || in_array( $status_slug, array( 'refunded' ), true );
    if ( ! $applies ) {
        $out['status'] = 'unpaid';
        return $out;
    }

    // Expected sellers: use per-line stamps (canonical) and fall back to the
    // aggregated _tnm_seller_ids CSV.
    $expected = array();
    foreach ( $order->get_items() as $item ) {
        if ( ! $item instanceof WC_Order_Item_Product ) {
            continue;
        }
        $sid = (int) $item->get_meta( '_tnm_seller_id', true );
        if ( $sid > 0 ) {
            $expected[ $sid ] = true;
        }
    }
    if ( ! $expected ) {
        $csv = (string) $order->get_meta( '_tnm_seller_ids', true );
        foreach ( array_filter( array_map( 'absint', explode( ',', $csv ) ) ) as $sid ) {
            $expected[ (int) $sid ] = true;
        }
    }
    $expected_ids   = array_keys( $expected );
    $out['expected'] = $expected_ids;

    // Single-seller orders use a Stripe destination charge (no explicit
    // per-seller transfer). Nothing to guard.
    if ( count( $expected_ids ) < 2 ) {
        $out['status'] = 'single_seller';
        return $out;
    }

    // Actual transfer meta written by mnu_native_issue_seller_transfers().
    $raw       = (string) $order->get_meta( '_mnu_seller_transfers', true );
    $transfers = '' !== $raw ? (array) json_decode( $raw, true ) : array();

    foreach ( $expected_ids as $sid ) {
        $key   = (string) $sid;
        $entry = $transfers[ $key ] ?? null;
        if ( ! is_array( $entry ) ) {
            $out['missing'][] = $sid;
            $out['issues'][]  = sprintf( 'Seller #%d has no transfer record.', $sid );
            continue;
        }
        $status = (string) ( $entry['status'] ?? '' );
        if ( 'sent' === $status ) {
            $out['actual_sent'][] = $sid;
        } elseif ( 'held' === $status ) {
            $out['actual_held'][] = $sid;
            $out['issues'][]      = sprintf( 'Seller #%d transfer held (no connected account / onboarding incomplete).', $sid );
        } elseif ( 'failed' === $status ) {
            $out['actual_failed'][] = $sid;
            $out['issues'][]        = sprintf( 'Seller #%d transfer failed: %s', $sid, (string) ( $entry['error'] ?? 'unknown error' ) );
        } elseif ( 'reversed' === $status ) {
            // Full refunds mark transfers reversed — that's expected, not a hold.
            $out['actual_sent'][] = $sid;
        } else {
            $out['actual_failed'][] = $sid;
            $out['issues'][]        = sprintf( 'Seller #%d transfer in unknown state "%s".', $sid, $status );
        }
    }

    // Also surface any extra transfer records for sellers we didn't expect —
    // that's a stamping bug that should stop the order from being marked ok.
    $extra = array_diff( array_map( 'intval', array_keys( $transfers ) ), $expected_ids );
    foreach ( $extra as $sid ) {
        $out['issues'][] = sprintf( 'Transfer to seller #%d recorded but that seller is not in this order.', $sid );
    }

    if ( $out['missing'] || $out['actual_held'] || $out['actual_failed'] || $extra ) {
        $out['status'] = 'held';
    }
    return $out;
}

/**
 * Evaluate the split-payment guardrail and persist the result to order meta,
 * including a compact history entry for the admin queue and an order note on
 * the first hold event so it surfaces in wp-admin.
 */
function mnu_native_apply_split_guardrail( WC_Order $order ): array {
    $eval = mnu_native_check_split_guardrail( $order );
    $prev_status = (string) $order->get_meta( '_mnu_split_guardrail_status', true );

    $snapshot = array(
        'evaluated_at' => current_time( 'mysql', true ),
        'status'       => $eval['status'],
        'issues'       => $eval['issues'],
        'expected'     => $eval['expected'],
        'sent'         => $eval['actual_sent'],
        'held'         => $eval['actual_held'],
        'failed'       => $eval['actual_failed'],
        'missing'      => $eval['missing'],
    );
    $order->update_meta_data( '_mnu_split_guardrail', wp_json_encode( $snapshot ) );
    $order->update_meta_data( '_mnu_split_guardrail_status', $eval['status'] );
    if ( 'held' === $eval['status'] ) {
        $order->update_meta_data( '_mnu_split_hold', 1 );
        if ( 'held' !== $prev_status ) {
            $order->add_order_note( 'Split-payment guardrail HELD: ' . implode( ' ', $eval['issues'] ) . ' Admin action required.' );
        }
    } else {
        $order->delete_meta_data( '_mnu_split_hold' );
        if ( 'held' === $prev_status ) {
            $order->add_order_note( 'Split-payment guardrail cleared — all seller transfers accounted for.' );
        }
    }
    $order->save();
    return $eval;
}

/**
 * v3.13.29 — Handle a charge.dispute.* webhook. Resolves the disputed
 * charge to a Woo order using `_thenest_stripe_latest_charge` (stamped by
 * both the sync and webhook success paths since v3.13.28), stamps the
 * dispute state, freezes unreserved pending earnings, opens a debt hold if
 * the seller has already been paid, and notifies the admin.
 *
 * Idempotent: `_mnu_dispute_id` is used as the anti-replay key.
 */
function mnu_native_handle_dispute_event( string $event_type, array $dispute ): void {
    $charge_id  = sanitize_text_field( (string) ( $dispute['charge'] ?? '' ) );
    $dispute_id = sanitize_text_field( (string) ( $dispute['id'] ?? '' ) );
    $status     = sanitize_key( (string) ( $dispute['status'] ?? '' ) );
    $reason     = sanitize_text_field( (string) ( $dispute['reason'] ?? '' ) );
    $amount_cts = (int) ( $dispute['amount'] ?? 0 );
    $amount_dol = $amount_cts / 100;

    if ( '' === $charge_id || '' === $dispute_id ) {
        error_log( '[MNU dispute] Received charge.dispute event with no charge/dispute id; ignoring.' );
        return;
    }

    // Find the order that paid this charge. Native and web checkout both
    // stamp `_thenest_stripe_latest_charge` on payment_complete since
    // v3.13.28.
    $orders = wc_get_orders( array(
        'limit'      => 1,
        'return'     => 'objects',
        'meta_query' => array(
            array(
                'key'     => '_thenest_stripe_latest_charge',
                'value'   => $charge_id,
                'compare' => '=',
            ),
        ),
    ) );
    if ( empty( $orders ) ) {
        error_log( sprintf( '[MNU dispute] Could not resolve charge %s to an order for event %s (dispute %s).', $charge_id, $event_type, $dispute_id ) );
        return;
    }
    $order = $orders[0];

    // Idempotency: log once per (event_type, dispute_id) combination.
    $seen = (array) json_decode( (string) $order->get_meta( '_mnu_dispute_events_seen', true ), true );
    $key  = $event_type . '|' . $dispute_id;
    if ( in_array( $key, $seen, true ) ) {
        return;
    }
    $seen[] = $key;
    $order->update_meta_data( '_mnu_dispute_events_seen', wp_json_encode( array_slice( $seen, -25 ) ) );

    $order->update_meta_data( '_mnu_dispute_id', $dispute_id );
    $order->update_meta_data( '_mnu_dispute_status', $status );
    $order->update_meta_data( '_mnu_dispute_reason', $reason );
    $order->update_meta_data( '_mnu_dispute_amount_cents', (string) $amount_cts );
    $order->update_meta_data( '_mnu_dispute_last_event', $event_type );
    $order->update_meta_data( '_mnu_dispute_last_seen_at', current_time( 'mysql', true ) );

    $note_lines = array( sprintf( 'Stripe dispute event %s (id %s, status %s, reason %s, $%.2f).', $event_type, $dispute_id, $status, $reason, $amount_dol ) );

    // On dispute.created and any state that removes funds, freeze the order.
    // The ledger's earning release query respects `_mnu_dispute_hold` so
    // pending earnings for this order will NOT flip to available until the
    // hold is cleared.
    $freezing_events = array(
        'charge.dispute.created'          => true,
        'charge.dispute.updated'          => true,
        'charge.dispute.funds_withdrawn'  => true,
    );
    if ( isset( $freezing_events[ $event_type ] ) ) {
        $order->update_meta_data( '_mnu_dispute_hold', '1' );
        // Freeze pending earnings for THIS order regardless of hold_until
        // date. The ledger read side must consult _mnu_dispute_hold before
        // releasing (see TNM_Ledger::release_available_earnings).
        global $wpdb;
        $ledger = tnm_table( 'ledger' );
        $frozen = (int) $wpdb->query( $wpdb->prepare(
            "UPDATE {$ledger} SET status='disputed_hold', updated_at=%s
             WHERE order_id=%d AND status IN ('pending','available') AND type='earning'",
            current_time( 'mysql', true ),
            $order->get_id()
        ) );
        if ( $frozen > 0 ) {
            $note_lines[] = sprintf( 'Froze %d ledger earning row(s) to disputed_hold.', $frozen );
        } else {
            // If nothing was frozen it means the earnings were already
            // paid to the seller (status=paid). Record a seller debt row
            // so the next batch nets it out.
            $seller_debts = 0;
            $paid_rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT seller_id, SUM(net) AS owed_net FROM {$ledger}
                 WHERE order_id=%d AND status='paid' AND type='earning'
                 GROUP BY seller_id",
                $order->get_id()
            ), ARRAY_A );
            foreach ( (array) $paid_rows as $row ) {
                $sid = (int) $row['seller_id'];
                if ( $sid <= 0 ) { continue; }
                $wpdb->insert( $ledger, array(
                    'order_id'        => $order->get_id(),
                    'seller_id'       => $sid,
                    'type'            => 'dispute_debt_' . $dispute_id,
                    'gross'           => 0,
                    'fee'             => 0,
                    'net'             => -1 * (float) $row['owed_net'],
                    'currency'        => $order->get_currency() ?: 'USD',
                    'status'          => 'available',
                    'available_at'    => current_time( 'mysql', true ),
                    'created_at'      => current_time( 'mysql', true ),
                    'updated_at'      => current_time( 'mysql', true ),
                ) );
                $seller_debts++;
            }
            if ( $seller_debts > 0 ) {
                $note_lines[] = sprintf(
                    'Earnings already paid — opened dispute debt row(s) for %d seller(s) at -$%.2f. Next payout batch will net this out.',
                    $seller_debts,
                    (float) $order->get_meta( '_mnu_v380_seller_kept_cents', true ) / 100
                );
            }
        }
    }

    // On close/win/reinstated: clear the hold if the ruling was in our
    // favour, otherwise leave the hold in place (the funds have been
    // permanently withdrawn from the platform).
    if ( 'charge.dispute.closed' === $event_type || 'charge.dispute.funds_reinstated' === $event_type ) {
        if ( 'won' === $status || 'charge.dispute.funds_reinstated' === $event_type ) {
            $order->delete_meta_data( '_mnu_dispute_hold' );
            $note_lines[] = 'Dispute resolved in our favour — hold cleared. Any disputed_hold ledger rows must be reviewed manually before re-releasing.';
        } else {
            $note_lines[] = 'Dispute closed against the platform — hold remains in place.';
        }
    }

    // Admin alert.
    if ( class_exists( 'MNU_Ops' ) ) {
        MNU_Ops::notify_admin(
            sprintf( 'Stripe dispute %s on order #%d ($%.2f)', $status, $order->get_id(), $amount_dol ),
            implode( "\n", $note_lines ),
            array( 'order_id' => $order->get_id(), 'dispute_id' => $dispute_id )
        );
    } else {
        // Fallback: email the admin.
        $admin_email = get_option( 'admin_email' );
        if ( $admin_email ) {
            wp_mail(
                $admin_email,
                sprintf( '[ShopMyNest] Stripe dispute on order #%d', $order->get_id() ),
                implode( "\n\n", $note_lines )
            );
        }
    }

    $order->add_order_note( implode( ' ', $note_lines ) );
    $order->save();
}

function mnu_native_verify_webhook_signature( string $payload, string $signature_header, string $secret ): bool {
    if ( ! $payload || ! $signature_header || ! $secret ) {
        return false;
    }
    $timestamp  = 0;
    $signatures = array();
    foreach ( explode( ',', $signature_header ) as $part ) {
        $pair = array_map( 'trim', explode( '=', $part, 2 ) );
        if ( 2 !== count( $pair ) ) {
            continue;
        }
        if ( 't' === $pair[0] ) {
            $timestamp = (int) $pair[1];
        } elseif ( 'v1' === $pair[0] ) {
            $signatures[] = $pair[1];
        }
    }
    if ( ! $timestamp || abs( time() - $timestamp ) > 300 || ! $signatures ) {
        return false;
    }
    $expected = hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );
    foreach ( $signatures as $signature ) {
        if ( hash_equals( $expected, $signature ) ) {
            return true;
        }
    }
    return false;
}

function mnu_native_webhook( WP_REST_Request $request ): array|WP_Error {
    $settings = mnu_native_get_settings();
    $payload  = $request->get_body();
    if ( empty( $settings['webhook_secret'] ) ) {
        return new WP_Error( 'webhook_not_configured', 'Stripe webhook signing secret is not configured.', array( 'status' => 503 ) );
    }
    if ( ! mnu_native_verify_webhook_signature( $payload, (string) $request->get_header( 'stripe-signature' ), (string) $settings['webhook_secret'] ) ) {
        return new WP_Error( 'invalid_signature', 'Invalid Stripe webhook signature.', array( 'status' => 400 ) );
    }
    $event = json_decode( $payload, true );
    if ( ! is_array( $event ) ) {
        return new WP_Error( 'invalid_payload', 'Invalid payload.', array( 'status' => 400 ) );
    }
    $event_type = sanitize_text_field( (string) ( $event['type'] ?? '' ) );

    if ( 'account.updated' === $event_type ) {
        // v3.13.35 — Stripe Connect retired; ignore lifecycle events for
        // legacy connected accounts. Stripe will keep firing these while any
        // pre-cutover accounts exist (and after they are rejected), but the
        // platform no longer reads charges_enabled / payouts_enabled from
        // them, so there is nothing to sync back to user meta.
        return array( 'received' => true );
    }

    // v3.7.90 — refund lifecycle sync from Stripe.
    if ( 0 === strpos( $event_type, 'refund.' ) || 'charge.refund.updated' === $event_type ) {
        if ( class_exists( 'MNU_Refund_Lifecycle' ) ) {
            MNU_Refund_Lifecycle::handle_stripe_refund_event( (array) ( $event['data']['object'] ?? array() ) );
        }
        return array( 'received' => true );
    }

    // v3.7.122.10 — Dashboard-initiated refunds skip Woo's process_refund path
    // entirely (no metadata[wc_order_id], no transfer_reversal). The
    // guardrail listens for charge.refunded and issues the missing seller
    // transfer reversal + application fee refund so the platform doesn't eat
    // the seller's payout.
    if ( 'charge.refunded' === $event_type ) {
        if ( class_exists( 'MNU_Refund_Guardrail' ) ) {
            MNU_Refund_Guardrail::handle_charge_refunded( (array) ( $event['data']['object'] ?? array() ) );
        }
        return array( 'received' => true );
    }

    // v3.13.29 — dispute lifecycle. Previously charge.dispute.* events fell
    // through to the intent-metadata branch below, which failed to match
    // (dispute objects carry the charge, not the intent) and silently
    // returned received:true — no hold, no debt, no admin alert. That is
    // especially dangerous now that sellers can be paid manually after only
    // 7 days: a dispute opened after the hold could find the money already reserved or paid.
    // Handle .created, .updated, .closed, .funds_withdrawn, .funds_reinstated
    // all in one dispatch that resolves charge -> order and marks the order
    // as disputed (which blocks any pending earnings from becoming
    // available in TNM_Ledger::release_available_earnings via the meta
    // check).
    if ( 0 === strpos( $event_type, 'charge.dispute.' ) ) {
        mnu_native_handle_dispute_event( $event_type, (array) ( $event['data']['object'] ?? array() ) );
        return array( 'received' => true );
    }

    $intent     = (array) ( $event['data']['object'] ?? array() );
    $order_id   = absint( $intent['metadata']['wc_order_id'] ?? 0 );
    $order      = $order_id ? wc_get_order( $order_id ) : false;

    if ( $order && hash_equals( (string) $order->get_meta( '_thenest_stripe_payment_intent', true ), (string) ( $intent['id'] ?? '' ) ) ) {
        if ( 'payment_intent.succeeded' === $event_type && ! $order->is_paid() ) {
            $expected_amount   = mnu_native_cents( $order->get_total() );
            $received_amount   = (int) ( $intent['amount_received'] ?? $intent['amount'] ?? 0 );
            $expected_currency = strtolower( (string) mnu_native_get_settings()['currency'] );
            $received_currency = strtolower( sanitize_key( (string) ( $intent['currency'] ?? '' ) ) );
            if ( $expected_amount === $received_amount && $expected_currency === $received_currency ) {
                // v3.13.30 Fix #15 — stamp _thenest_stripe_latest_charge on the
                // webhook success path (previously only the web finalizer wrote
                // it). Required so charge.dispute.* and charge.refunded lookups
                // can resolve back to the order for native-app checkouts.
                $charge_id = sanitize_text_field( (string) ( $intent['latest_charge'] ?? '' ) );
                if ( '' === $charge_id && ! empty( $intent['charges']['data'][0]['id'] ) ) {
                    $charge_id = sanitize_text_field( (string) $intent['charges']['data'][0]['id'] );
                }
                if ( '' !== $charge_id ) {
                    $order->update_meta_data( '_thenest_stripe_latest_charge', $charge_id );
                    $order->save();
                }
                $order->payment_complete( sanitize_text_field( (string) $intent['id'] ) );
                $order->add_order_note( sprintf( 'Stripe webhook confirmed payment for The Nest native checkout. Charge %s.', $charge_id ?: 'unknown' ) );
                // v3.7.34 — Issue per-seller transfers for multi-seller carts (no-op for single-seller).
                if ( '' !== $charge_id ) {
                    mnu_native_issue_seller_transfers( $order, $charge_id );
                }
            } else {
                $order->add_order_note( 'Stripe webhook payment was not applied because the amount or currency did not match the order.' );
                $order->save();
            }
        } elseif ( 'payment_intent.payment_failed' === $event_type && ! $order->is_paid() ) {
            $order->update_status( 'failed', 'Stripe reported that the native checkout payment failed.' );
        } elseif ( 'payment_intent.canceled' === $event_type && ! $order->is_paid() ) {
            $order->update_status( 'cancelled', 'Stripe reported that the native checkout payment was cancelled.' );
        }
    }
    return array( 'received' => true );
}

/**
 * v3.7.122.5 — auto-cancel native-app checkout drafts that were never paid.
 *
 * Every native /checkout/create-intent call opens a wc-pending order. If the
 * buyer bails (backgrounds the app, force-quits, closes the sheet) the order
 * lingers forever. It also shows up on the seller's dashboard as a "sold"
 * order that isn't real. This cron sweeps native-app orders older than
 * MNU_NATIVE_PENDING_TTL and marks them wc-cancelled so:
 *   - the seller_orders filter (v3.7.122.5) never surfaces them again
 *   - stock levels release back to the listing
 *   - the buyer's cart is still intact — the token-scoped attempt in
 *     app/(tabs)/cart.tsx opens a fresh order on the next Pay tap.
 *
 * Wired to the hourly WP cron; on hosts without alt-cron this still catches
 * up on the next real request.
 */
const MNU_NATIVE_PENDING_TTL = 45 * MINUTE_IN_SECONDS;

function mnu_native_cleanup_stale_pending(): void {
    if ( ! function_exists( 'wc_get_orders' ) ) {
        return;
    }
    $cutoff = gmdate( 'Y-m-d H:i:s', time() - MNU_NATIVE_PENDING_TTL );
    // wc_get_orders' 'created_via' filter works under both HPOS (column) and
    // legacy post-meta storage, so we don't have to branch on the storage
    // engine. We DO NOT sweep pending orders coming from the classic Woo
    // checkout, the Woo Stripe Gateway, or admin-created holds.
    $orders = wc_get_orders( array(
        'limit'       => 50,
        'status'      => array( 'pending' ),
        'date_before' => $cutoff,
        'return'      => 'objects',
        'created_via' => 'the-nest-native-app',
    ) );
    if ( ! $orders ) {
        return;
    }
    foreach ( $orders as $order ) {
        $order->update_status(
            'cancelled',
            'Auto-cancelled: native app checkout draft was never paid within ' . (int) ( MNU_NATIVE_PENDING_TTL / MINUTE_IN_SECONDS ) . ' minutes.'
        );
    }
}

add_action( 'mnu_native_cleanup_stale_pending', 'mnu_native_cleanup_stale_pending' );

function mnu_native_schedule_cleanup(): void {
    if ( ! wp_next_scheduled( 'mnu_native_cleanup_stale_pending' ) ) {
        wp_schedule_event( time() + 300, 'hourly', 'mnu_native_cleanup_stale_pending' );
    }
    // v3.7.122.6 — run cleanup once immediately after upgrade so existing
    // stale drafts (e.g. tester Jo's #3509 phantom) disappear on the next
    // request instead of waiting up to an hour for the scheduled sweep.
    // Keyed to the plugin version so we don't re-run it every request.
    $ran_for = (string) get_option( 'mnu_native_cleanup_bootstrap_version', '' );
    if ( defined( 'MNU_VERSION' ) && MNU_VERSION !== $ran_for ) {
        mnu_native_cleanup_stale_pending();
        update_option( 'mnu_native_cleanup_bootstrap_version', MNU_VERSION, false );
    }
}
add_action( 'init', 'mnu_native_schedule_cleanup' );
