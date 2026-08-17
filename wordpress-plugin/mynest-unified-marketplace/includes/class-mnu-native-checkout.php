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

function mnu_native_calc_items( array $items ): array|WP_Error {
    $lines    = array();
    $subtotal = 0.0;
    foreach ( $items as $row ) {
        $product_id = absint( $row['product_id'] ?? ( $row['product']['id'] ?? 0 ) );
        $quantity   = max( 1, absint( $row['quantity'] ?? 1 ) );
        $product    = wc_get_product( $product_id );
        if ( ! $product || ! $product->is_purchasable() ) {
            return new WP_Error( 'invalid_product', 'One or more products are unavailable.', array( 'status' => 409, 'product_id' => $product_id ) );
        }
        if ( ! $product->has_enough_stock( $quantity ) ) {
            return new WP_Error( 'insufficient_stock', $product->get_name() . ' does not have enough stock.', array( 'status' => 409, 'product_id' => $product_id ) );
        }
        if ( class_exists( 'MNU_Connect' ) ) {
            $seller_id = (int) tnm_get_product_seller_id( $product );
            if ( $seller_id > 0 ) {
                $seller_status = MNU_Connect::cached_status( $seller_id );
                if ( ! $seller_status['charges_enabled'] || ! $seller_status['payouts_enabled'] ) {
                    return new WP_Error( 'seller_not_ready', $product->get_name() . ' is temporarily unavailable because its seller has not finished payment setup.', array( 'status' => 409, 'product_id' => $product_id ) );
                }
            }
        }
        $price      = (float) wc_get_price_excluding_tax( $product );
        $line_total = $price * $quantity;
        $subtotal  += $line_total;
        $lines[]    = array(
            'product'    => $product,
            'product_id' => $product_id,
            'quantity'   => $quantity,
            'price'      => $price,
            'line_total' => $line_total,
            'name'       => $product->get_name(),
        );
    }
    return $lines ? array( $lines, $subtotal ) : new WP_Error( 'empty_cart', 'No valid products found.', array( 'status' => 400 ) );
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
    $result = mnu_native_calc_items( is_array( $data['items'] ?? null ) ? $data['items'] : array() );
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
        'shipping'      => $shipping,
        'tax'           => 0,
        'tax_estimated' => true,
        'total'         => $subtotal + $shipping,
        'currency'      => $currency,
        'expires_in'    => 15 * MINUTE_IN_SECONDS,
        'quote_token'   => mnu_native_issue_quote_token( $user_id, $lines, $subtotal, $shipping, $currency ),
    );
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
    $orders = wc_get_orders(
        array(
            'customer_id' => $user_id,
            'limit'       => 1,
            'status'      => array( 'pending', 'failed' ),
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

    // No token means an older app build with nothing to serialise on.
    if ( ! $checkout_token ) {
        return mnu_native_create_intent_locked( $user_id, $data, '' );
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
 * Compute Stripe Connect routing parameters for a marketplace order.
 *
 * Returns an array of extra params to merge into /payment_intents. For
 * single-seller carts we use a destination charge (`transfer_data[destination]`)
 * plus `application_fee_amount` so the platform keeps its cut and the seller
 * receives the balance directly. For multi-seller carts we return an empty
 * array so the charge lands on the platform and payouts are handled later via
 * Separate Charges & Transfers.
 *
 * @return array<string,string>
 */
function mnu_native_connect_intent_params( WC_Order $order ): array {
    if ( ! class_exists( 'MNU_Connect' ) ) {
        return array();
    }

    // Group per-item platform fee by seller. `_tnm_platform_fee` is stamped
    // during stamp_item_snapshot() so it already reflects the 8% marketplace fee.
    $seller_fees = array();
    foreach ( $order->get_items() as $item ) {
        if ( ! $item instanceof WC_Order_Item_Product ) {
            continue;
        }
        $seller_id = (int) $item->get_meta( '_tnm_seller_id', true );
        if ( $seller_id <= 0 ) {
            continue;
        }
        $fee                        = (float) $item->get_meta( '_tnm_platform_fee', true );
        $seller_fees[ $seller_id ]  = ( $seller_fees[ $seller_id ] ?? 0 ) + max( 0, $fee );
    }

    // Multi-seller carts are handled separately (post-capture transfers). For
    // now the charge stays on the platform, which is safer than routing to
    // one arbitrary seller.
    if ( count( $seller_fees ) !== 1 ) {
        return array();
    }

    $seller_id      = (int) array_key_first( $seller_fees );
    $seller_account = MNU_Connect::account_id( $seller_id );

    // Seller has to have finished onboarding for the destination charge to
    // clear. If they haven't, fall back to a platform charge (still captures
    // the customer's money; ops can manually pay the seller).
    if ( '' === $seller_account || ! MNU_Connect::seller_can_sell( $seller_id ) ) {
        return array();
    }

    $application_fee_cents = (int) round( $seller_fees[ $seller_id ] * 100 );
    return array(
        'transfer_data[destination]' => $seller_account,
        'application_fee_amount'     => (string) $application_fee_cents,
    );
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

        $result = mnu_native_calc_items( $source_items );
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
        // Route the charge to the seller's Connect account when we have exactly one
        // seller in the cart, retaining the platform application fee. This turns
        // the charge into a proper marketplace destination charge instead of the
        // money landing in the platform account only.
        $intent_params = array(
            'amount'                             => mnu_native_cents( $order->get_total() ),
            'currency'                           => strtolower( $settings['currency'] ),
            'customer'                           => $stripe_customer_id,
            'automatic_payment_methods[enabled]' => 'true',
            'metadata[wc_order_id]'              => $order->get_id(),
            'metadata[customer_id]'              => $user_id,
            'metadata[source]'                   => 'the_nest_native_app',
            'description'                        => 'MyNest order #' . $order->get_order_number(),
        );
        $intent_params = array_merge( $intent_params, mnu_native_connect_intent_params( $order ) );
        $intent = mnu_native_stripe_request(
            '/payment_intents',
            $intent_params,
            'mynest_order_' . $order->get_id()
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
 *     123 => array( 'gross_cents' => 4500, 'fee_cents' => 360, 'net_cents' => 4140 ),
 *     456 => array( 'gross_cents' => 1200, 'fee_cents' => 96,  'net_cents' => 1104 ),
 *   )
 *
 * `fee_cents` comes from _tnm_platform_fee stamped by stamp_item_snapshot() at
 * cart time (the 8% marketplace fee). If a line is missing the fee meta we
 * fall back to 8% of gross.
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
            // Fallback: 8% of the pre-tax subtotal, matching stamp_item_snapshot().
            $line_fee = round( (float) $item->get_subtotal() * 0.08, 2 );
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
    if ( ! class_exists( 'MNU_Connect' ) ) {
        return;
    }
    $existing = (array) json_decode( (string) $order->get_meta( '_mnu_seller_transfers', true ), true );
    if ( ! empty( $existing ) ) {
        return; // Already processed.
    }
    $splits = mnu_native_seller_splits( $order );
    if ( count( $splits ) < 2 ) {
        return; // Single-seller carts already routed via destination charge.
    }

    $settings = mnu_native_get_settings();
    $currency = strtolower( (string) $settings['currency'] );
    $transfers = array();

    foreach ( $splits as $seller_id => $row ) {
        $seller_account = MNU_Connect::account_id( $seller_id );
        if ( '' === $seller_account || ! MNU_Connect::seller_can_sell( $seller_id ) ) {
            $order->add_order_note( sprintf( 'Skipped Stripe transfer to seller #%d: no connected account or onboarding incomplete. Platform is holding $%.2f owed to this seller.', $seller_id, $row['net_cents'] / 100 ) );
            $transfers[ (string) $seller_id ] = array( 'status' => 'held', 'net_cents' => $row['net_cents'] );
            continue;
        }
        $result = mnu_native_stripe_request(
            '/transfers',
            array(
                'amount'             => (string) $row['net_cents'],
                'currency'           => $currency,
                'destination'        => $seller_account,
                'source_transaction' => $charge_id,
                'transfer_group'     => 'mnu_order_' . $order->get_id(),
                'metadata[wc_order_id]' => (string) $order->get_id(),
                'metadata[seller_id]'   => (string) $seller_id,
            ),
            'mnu_transfer_' . $order->get_id() . '_' . $seller_id
        );
        if ( is_wp_error( $result ) ) {
            $order->add_order_note( sprintf( 'Stripe transfer to seller #%d failed: %s. Retrying later.', $seller_id, $result->get_error_message() ) );
            $transfers[ (string) $seller_id ] = array( 'status' => 'failed', 'error' => $result->get_error_message(), 'net_cents' => $row['net_cents'] );
            continue;
        }
        $transfer_id_str = (string) ( $result['id'] ?? '' );
        $transfers[ (string) $seller_id ] = array(
            'status'      => 'sent',
            'transfer_id' => $transfer_id_str,
            'net_cents'   => $row['net_cents'],
        );
        // Write the transfer id back onto every earning ledger row for this
        // order+seller so downstream refund reversals (record_refund →
        // reverse_transfer_for_item) can find it without re-parsing the
        // order-level JSON.
        if ( '' !== $transfer_id_str && function_exists( 'tnm_table' ) ) {
            global $wpdb;
            $wpdb->query(
                $wpdb->prepare(
                    'UPDATE ' . tnm_table( 'ledger' ) . " SET stripe_transfer_id=%s, updated_at=%s WHERE order_id=%d AND seller_id=%d AND type='earning' AND (stripe_transfer_id='' OR stripe_transfer_id IS NULL)",
                    $transfer_id_str,
                    current_time( 'mysql' ),
                    $order->get_id(),
                    (int) $seller_id
                )
            );
        }
    }
    $order->update_meta_data( '_mnu_seller_transfers', wp_json_encode( $transfers ) );
    $order->add_order_note( 'Issued Stripe transfers to ' . count( array_filter( $transfers, static function ( $t ) { return 'sent' === ( $t['status'] ?? '' ); } ) ) . ' seller(s).' );
    $order->save();
    // v3.7.65 — evaluate the split-payment guardrail every time transfers are
    // issued (or attempted). Flags multi-seller orders whose transfer set does
    // not match the expected seller set so an admin can reconcile before
    // funds sit indefinitely in the platform balance.
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
        if ( class_exists( 'MNU_Connect' ) ) {
            MNU_Connect::handle_account_updated( (array) ( $event['data']['object'] ?? array() ) );
        }
        return array( 'received' => true );
    }

    // v3.7.90 — refund lifecycle sync from Stripe.
    if ( 0 === strpos( $event_type, 'refund.' ) || 'charge.refund.updated' === $event_type ) {
        if ( class_exists( 'MNU_Refund_Lifecycle' ) ) {
            MNU_Refund_Lifecycle::handle_stripe_refund_event( (array) ( $event['data']['object'] ?? array() ) );
        }
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
                $order->payment_complete( sanitize_text_field( (string) $intent['id'] ) );
                $order->add_order_note( 'Stripe webhook confirmed payment for The Nest native checkout.' );
                // v3.7.34 — Issue per-seller transfers for multi-seller carts (no-op for single-seller).
                $charge_id = sanitize_text_field( (string) ( $intent['latest_charge'] ?? '' ) );
                if ( '' === $charge_id && ! empty( $intent['charges']['data'][0]['id'] ) ) {
                    $charge_id = sanitize_text_field( (string) $intent['charges']['data'][0]['id'] );
                }
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
