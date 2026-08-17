<?php

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'MNU_LABELS_NS' ) ) {
    define( 'MNU_LABELS_NS', 'nest-labels/v1' );
}
if ( ! defined( 'MNU_LABELS_VERSION' ) ) {
    define( 'MNU_LABELS_VERSION', '5.1.0' );
}

function mnu_labels_current_user_id( ?WP_REST_Request $request = null ): int {
    if ( class_exists( 'MNU_Ops' ) ) {
        return MNU_Ops::get_bearer_user_id( $request );
    }

    return get_current_user_id();
}

/**
 * @return array{shippo_token:string,test_mode:int}
 */
function mnu_labels_settings(): array {
    $settings = array_merge(
        array(
            'shippo_token' => '',
            'test_mode'    => 1,
        ),
        (array) get_option( 'thenest_shipping_labels_settings', array() )
    );

    if ( empty( $settings['shippo_token'] ) ) {
        $settings['shippo_token'] = (string) get_option( 'thenest_shippo_api_token', '' );
    }

    $settings['shippo_token'] = trim( (string) $settings['shippo_token'] );
    $settings['test_mode']    = ! empty( $settings['test_mode'] ) ? 1 : 0;

    return $settings;
}

function mnu_labels_menu(): void {
    add_submenu_page(
        'tnm-marketplace',
        'Shipping Labels',
        'Shipping Labels',
        'manage_woocommerce',
        'thenest-shipping-labels',
        'mnu_labels_page'
    );
}
add_action( 'admin_menu', 'mnu_labels_menu', 20 );

function mnu_labels_page(): void {
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        return;
    }

    $stored = (array) get_option( 'thenest_shipping_labels_settings', array() );
    if ( isset( $_POST['mnu_labels_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mnu_labels_nonce'] ) ), 'save_thenest_labels' ) ) {
        if ( ! empty( $_POST['shippo_token'] ) ) {
            $stored['shippo_token'] = sanitize_text_field( wp_unslash( $_POST['shippo_token'] ) );
            update_option( 'thenest_shippo_api_token', $stored['shippo_token'], false );
        }
        $stored['test_mode'] = ! empty( $_POST['test_mode'] ) ? 1 : 0;
        update_option( 'thenest_shipping_labels_settings', $stored, false );

        if ( isset( $_POST['mnu_package_presets'] ) && is_array( $_POST['mnu_package_presets'] ) && function_exists( 'mnu_ship_save_package_presets' ) ) {
            mnu_ship_save_package_presets( wp_unslash( $_POST['mnu_package_presets'] ) );
        }

        echo '<div class="notice notice-success"><p>Shipping label settings saved.</p></div>';
    }

    $settings = mnu_labels_settings();
    $presets  = function_exists( 'mnu_ship_package_presets' ) ? mnu_ship_package_presets() : array();
    $live_key_in_test_mode = ! empty( $settings['test_mode'] ) && str_starts_with( $settings['shippo_token'], 'shippo_live_' );
    ?>
    <div class="wrap">
        <h1><?php echo esc_html( get_bloginfo( 'name' ) . ' Shipping Labels' ); ?></h1>
        <p>Configure the Shippo account used to rate shipments and purchase seller labels. Start with a Shippo test token. You can also configure the same token under <a href="<?php echo esc_url( admin_url( 'admin.php?page=tnm-settings' ) ); ?>"><?php echo esc_html( get_bloginfo( 'name' ) ); ?> → Settings</a>.</p>
        <?php if ( $live_key_in_test_mode ) : ?>
            <div class="notice notice-warning inline"><p>A live Shippo token is saved while test mode is enabled. Label purchases are blocked until you use a test token or intentionally disable test mode.</p></div>
        <?php endif; ?>
        <form method="post">
            <?php wp_nonce_field( 'save_thenest_labels', 'mnu_labels_nonce' ); ?>
            <table class="form-table">
                <tr>
                    <th><label for="mnu-shippo-token">Shippo API token</label></th>
                    <td>
                        <input id="mnu-shippo-token" type="password" class="regular-text" name="shippo_token" value="" autocomplete="new-password" placeholder="Leave blank to keep saved token">
                        <p class="description"><?php echo empty( $settings['shippo_token'] ) ? 'No token is configured.' : 'A token is currently configured.'; ?></p>
                    </td>
                </tr>
                <tr>
                    <th>Test mode</th>
                    <td><label><input type="checkbox" name="test_mode" <?php checked( ! empty( $settings['test_mode'] ) ); ?>> Block live-token purchases while testing</label></td>
                </tr>
            </table>

            <h2>Package size presets</h2>
            <p>Sellers can pick one of these presets instead of typing package dimensions when they create or edit a product. Picking a preset fills the product's length, width, and height (in inches) so Shippo quotes match. Weight is always entered per product.</p>
            <table class="form-table">
                <?php foreach ( $presets as $preset_key => $preset ) : ?>
                <tr>
                    <th><?php echo esc_html( (string) ( $preset['label'] ?? ucfirst( $preset_key ) ) ); ?></th>
                    <td>
                        <label>Length <input type="number" step="0.1" min="0.1" name="mnu_package_presets[<?php echo esc_attr( $preset_key ); ?>][length_in]" value="<?php echo esc_attr( $preset['length_in'] ); ?>" style="width:6em"></label>
                        <label>Width <input type="number" step="0.1" min="0.1" name="mnu_package_presets[<?php echo esc_attr( $preset_key ); ?>][width_in]" value="<?php echo esc_attr( $preset['width_in'] ); ?>" style="width:6em"></label>
                        <label>Height <input type="number" step="0.1" min="0.1" name="mnu_package_presets[<?php echo esc_attr( $preset_key ); ?>][height_in]" value="<?php echo esc_attr( $preset['height_in'] ); ?>" style="width:6em"></label>
                        <span class="description">inches</span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

function mnu_labels_routes(): void {
    register_rest_route(
        MNU_LABELS_NS,
        '/health',
        array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => static function (): array {
                $settings = mnu_labels_settings();
                return array(
                    'ok'         => true,
                    'version'    => MNU_LABELS_VERSION,
                    'configured' => ! empty( $settings['shippo_token'] ),
                    'test_mode'  => ! empty( $settings['test_mode'] ),
                );
            },
            'permission_callback' => '__return_true',
        )
    );

    register_rest_route(
        MNU_LABELS_NS,
        '/seller/orders/(?P<id>\d+)/rates',
        array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => 'mnu_labels_rates',
            'permission_callback' => 'mnu_labels_auth',
        )
    );

    register_rest_route(
        MNU_LABELS_NS,
        '/seller/orders/(?P<id>\d+)/label',
        array(
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => 'mnu_labels_buy',
                'permission_callback' => 'mnu_labels_auth',
            ),
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => 'mnu_labels_get',
                'permission_callback' => 'mnu_labels_auth',
            ),
        )
    );
}
add_action( 'rest_api_init', 'mnu_labels_routes' );

/**
 * v3.7.79 — admin-only routes for diagnosing seller-order Shippo failures.
 * - GET  /mnu/v1/admin/labels_last_error → last recorded Shippo error blob
 * - POST /mnu/v1/admin/diagnose_seller_order { order_id, seller_id }
 *   returns the exact from/to/parcel that mnu_labels_rates would have sent
 *   plus the live Shippo response (including error body when it fails).
 */
add_action(
    'rest_api_init',
    static function () {
        register_rest_route(
            'mnu/v1',
            '/admin/labels_last_error',
            array(
                'methods'             => 'GET',
                'callback'            => static function () {
                    return new WP_REST_Response(
                        array(
                            'ok'         => true,
                            'last_error' => get_option( 'mnu_labels_last_shippo_error', null ),
                        ),
                        200
                    );
                },
                'permission_callback' => static function () { return current_user_can( 'manage_options' ); },
            )
        );

        register_rest_route(
            'mnu/v1',
            '/admin/diagnose_seller_order',
            array(
                'methods'             => 'POST',
                'callback'            => 'mnu_labels_diagnose_seller_order',
                'permission_callback' => static function () { return current_user_can( 'manage_options' ); },
                'args'                => array(
                    'order_id'  => array( 'required' => true,  'type' => 'integer' ),
                    'seller_id' => array( 'required' => false, 'type' => 'integer' ),
                ),
            )
        );
    }
);

/**
 * v3.7.79 — mirror of mnu_labels_rates for admins that also returns the
 * from/to/parcel used and the full Shippo response (including on failure).
 */
function mnu_labels_diagnose_seller_order( WP_REST_Request $request ): WP_REST_Response {
    $order_id  = (int) $request->get_param( 'order_id' );
    $seller_id = (int) $request->get_param( 'seller_id' );
    $order     = wc_get_order( $order_id );
    if ( ! $order ) {
        return new WP_REST_Response( array( 'error' => 'invalid_order' ), 404 );
    }
    if ( $seller_id <= 0 ) {
        foreach ( $order->get_items() as $item ) {
            $sid = (int) tnm_get_order_item_seller_id( $item );
            if ( $sid > 0 ) { $seller_id = $sid; break; }
        }
    }
    if ( $seller_id <= 0 ) {
        return new WP_REST_Response( array( 'error' => 'no_seller' ), 400 );
    }

    list( $from, $to ) = mnu_labels_order_addresses( $order, $seller_id );
    $out = array(
        'order_id'  => $order_id,
        'seller_id' => $seller_id,
        'from'      => $from,
        'to'        => $to,
    );

    $from_valid = mnu_labels_validate_address( $from, 'ship-from' );
    if ( is_wp_error( $from_valid ) ) {
        $out['error']        = 'incomplete_from';
        $out['error_detail'] = $from_valid->get_error_message();
        return new WP_REST_Response( $out, 200 );
    }
    $to_valid = mnu_labels_validate_address( $to, 'ship-to' );
    if ( is_wp_error( $to_valid ) ) {
        $out['error']        = 'incomplete_to';
        $out['error_detail'] = $to_valid->get_error_message();
        return new WP_REST_Response( $out, 200 );
    }

    $parcel = mnu_labels_parcel_from_order( $order, $seller_id );
    if ( is_wp_error( $parcel ) ) {
        $out['error']        = 'parcel_error';
        $out['error_detail'] = $parcel->get_error_message();
        return new WP_REST_Response( $out, 200 );
    }
    $out['parcel'] = $parcel;

    $body = array(
        'address_from' => $from,
        'address_to'   => $to,
        'parcels'      => array( $parcel ),
        'async'        => false,
        'metadata'     => sprintf( 'diagnose order %s seller %d', $order->get_order_number(), $seller_id ),
    );
    $shipment = mnu_labels_shippo_request( '/shipments/', $body );
    if ( is_wp_error( $shipment ) ) {
        $out['error']        = 'shippo_error';
        $out['error_detail'] = $shipment->get_error_message();
        $out['shippo']       = $shipment->get_error_data();
        return new WP_REST_Response( $out, 200 );
    }

    $out['shipment_id'] = (string) ( $shipment['object_id'] ?? '' );
    $out['messages']    = isset( $shipment['messages'] ) ? $shipment['messages'] : array();
    $out['rates']       = array_map(
        static function ( $r ) {
            return array(
                'provider'  => (string) ( $r['provider'] ?? '' ),
                'service'   => (string) ( $r['servicelevel']['name'] ?? '' ),
                'token'     => (string) ( $r['servicelevel']['token'] ?? '' ),
                'amount'    => (string) ( $r['amount'] ?? '' ),
                'currency'  => (string) ( $r['currency'] ?? '' ),
                'estimated' => (string) ( $r['estimated_days'] ?? '' ),
                'state'     => (string) ( $r['object_state'] ?? 'VALID' ),
            );
        },
        isset( $shipment['rates'] ) && is_array( $shipment['rates'] ) ? $shipment['rates'] : array()
    );
    return new WP_REST_Response( $out, 200 );
}

function mnu_labels_auth( WP_REST_Request $request ): bool|WP_Error {
    $user_id = mnu_labels_current_user_id( $request );
    if ( ! $user_id ) {
        return new WP_Error( 'not_logged_in', 'You must be logged in.', array( 'status' => 401 ) );
    }

    return tnm_is_marketplace_user( $user_id )
        ? true
        : new WP_Error( 'seller_required', 'An approved seller account is required.', array( 'status' => 403 ) );
}

function mnu_labels_seller_for_request( WP_REST_Request $request, WC_Order $order ): int|WP_Error {
    $user_id = mnu_labels_current_user_id( $request );
    if ( ! $user_id ) {
        return new WP_Error( 'not_logged_in', 'You must be logged in.', array( 'status' => 401 ) );
    }

    if ( user_can( $user_id, 'manage_woocommerce' ) || user_can( $user_id, 'manage_options' ) ) {
        $requested = absint( $request->get_param( 'seller_id' ) );
        if ( $requested && tnm_order_contains_seller( $order, $requested ) ) {
            return $requested;
        }
        foreach ( $order->get_items() as $item ) {
            $seller_id = tnm_get_order_item_seller_id( $item );
            if ( $seller_id ) {
                return $seller_id;
            }
        }
    }

    return tnm_order_contains_seller( $order, $user_id )
        ? $user_id
        : new WP_Error( 'forbidden', 'You cannot access this order.', array( 'status' => 403 ) );
}

/**
 * Convert Shippo's varied error payloads into a readable message.
 */
function mnu_labels_shippo_error_message( mixed $data ): string {
    if ( ! is_array( $data ) ) {
        return 'Shippo request failed.';
    }

    foreach ( array( 'detail', 'message' ) as $key ) {
        if ( isset( $data[ $key ] ) && is_string( $data[ $key ] ) && '' !== trim( $data[ $key ] ) ) {
            return sanitize_text_field( $data[ $key ] );
        }
    }

    if ( isset( $data['messages'] ) && is_array( $data['messages'] ) ) {
        foreach ( $data['messages'] as $message ) {
            if ( is_array( $message ) ) {
                $text = (string) ( $message['text'] ?? $message['message'] ?? '' );
                if ( '' !== trim( $text ) ) {
                    return sanitize_text_field( $text );
                }
            } elseif ( is_string( $message ) && '' !== trim( $message ) ) {
                return sanitize_text_field( $message );
            }
        }
    }

    return 'Shippo request failed.';
}

/**
 * v3.7.79 — stash the last Shippo error (request + response) so an admin
 * can inspect what went wrong without server logs. Keeps only the newest.
 *
 * @param array<string,mixed>  $body    Request body sent to Shippo.
 * @param array<string,mixed>|null $data Decoded response, if any.
 * @param string               $raw     Raw response body, if data was not JSON.
 * @param array<string,mixed>  $context Free-form context (order_id, seller_id, ...).
 */
function mnu_labels_record_last_shippo_error( string $method, string $path, array $body, int $code, mixed $data, string $raw = '', array $context = array() ): void {
    $entry = array(
        'ts'       => gmdate( 'c' ),
        'method'   => $method,
        'path'     => $path,
        'code'     => $code,
        'request'  => $body,
        'response' => is_array( $data ) ? $data : array( 'raw' => (string) $raw ),
        'context'  => $context,
    );
    update_option( 'mnu_labels_last_shippo_error', $entry, false );
}

/**
 * Send a request to Shippo while preserving the legacy POST helper signature.
 *
 * @param array<string,mixed> $body
 */
function mnu_labels_shippo_api_request( string $method, string $path, array $body = array() ): array|WP_Error {
    $settings = mnu_labels_settings();
    if ( empty( $settings['shippo_token'] ) ) {
        return new WP_Error( 'shippo_not_configured', sprintf( 'Shippo API token is missing. An administrator must configure it under %s → Shipping Labels.', get_bloginfo( 'name' ) ), array( 'status' => 503 ) );
    }

    $args = array(
        'method'  => strtoupper( $method ),
        'headers' => array(
            'Authorization' => 'ShippoToken ' . $settings['shippo_token'],
            'Content-Type'  => 'application/json',
            'Accept'             => 'application/json',
            'SHIPPO-API-VERSION' => '2018-02-08',
        ),
        'timeout' => 45,
    );

    if ( ! empty( $body ) && 'GET' !== strtoupper( $method ) ) {
        $args['body'] = wp_json_encode( $body );
    }

    $response = wp_remote_request( 'https://api.goshippo.com' . $path, $args );
    if ( is_wp_error( $response ) ) {
        return new WP_Error( 'shippo_connection_error', $response->get_error_message(), array( 'status' => 502 ) );
    }

    $raw  = wp_remote_retrieve_body( $response );
    $data = json_decode( $raw, true );
    $code = wp_remote_retrieve_response_code( $response );

    if ( $code < 200 || $code >= 300 ) {
        // v3.7.79 — preserve the raw Shippo response body and endpoint
        // so seller-orders errors can be diagnosed without shell access.
        // Also stashed to an option (see mnu_labels_record_last_shippo_error)
        // that the admin diagnose endpoint reads back.
        $err = new WP_Error(
            'shippo_error',
            mnu_labels_shippo_error_message( $data ),
            array(
                'status'      => 502,
                'shippo_code' => $code,
                'shippo_path' => $path,
                'shippo_body' => is_array( $data ) ? $data : array( 'raw' => (string) $raw ),
            )
        );
        if ( function_exists( 'mnu_labels_record_last_shippo_error' ) ) {
            mnu_labels_record_last_shippo_error( $method, $path, $body, $code, $data, $raw );
        }
        return $err;
    }

    return is_array( $data )
        ? $data
        : new WP_Error( 'shippo_invalid_response', 'Shippo returned an invalid response.', array( 'status' => 502 ) );
}

/**
 * Backward-compatible POST helper used by MNU_Ops.
 *
 * @param array<string,mixed> $body
 */
function mnu_labels_shippo_request( string $path, array $body ): array|WP_Error {
    return mnu_labels_shippo_api_request( 'POST', $path, $body );
}

function mnu_labels_shippo_get( string $path ): array|WP_Error {
    return mnu_labels_shippo_api_request( 'GET', $path );
}

/**
 * @return array{0:array<string,string>,1:array<string,string>}
 */
function mnu_labels_order_addresses( WC_Order $order, int $seller_id ): array {
    $profile = function_exists( 'mnu_ship_get_profile' )
        ? mnu_ship_get_profile( $seller_id )
        : get_user_meta( $seller_id, '_thenest_shipping_profile', true );
    $profile = is_array( $profile ) ? $profile : array();
    $seller  = get_userdata( $seller_id );

    $from = array(
        'name'    => (string) ( $profile['ship_from_name'] ?? ( $seller ? $seller->display_name : tnm_seller_display_name( $seller_id ) ) ),
        'company' => (string) ( $profile['ship_from_company'] ?? '' ),
        'street1' => (string) ( $profile['ship_from_street1'] ?? '' ),
        'street2' => (string) ( $profile['ship_from_street2'] ?? '' ),
        'city'    => (string) ( $profile['ship_from_city'] ?? '' ),
        'state'   => (string) ( $profile['ship_from_state'] ?? '' ),
        'zip'     => (string) ( $profile['ship_from_zip'] ?? '' ),
        'country' => (string) ( $profile['ship_from_country'] ?? 'US' ),
        'phone'   => (string) ( $profile['ship_from_phone'] ?? get_user_meta( $seller_id, 'billing_phone', true ) ),
        'email'   => $seller ? (string) $seller->user_email : '',
    );

    $to = array(
        'name'    => trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() ) ?: trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
        'company' => $order->get_shipping_company() ?: $order->get_billing_company(),
        'street1' => $order->get_shipping_address_1() ?: $order->get_billing_address_1(),
        'street2' => $order->get_shipping_address_2() ?: $order->get_billing_address_2(),
        'city'    => $order->get_shipping_city() ?: $order->get_billing_city(),
        'state'   => $order->get_shipping_state() ?: $order->get_billing_state(),
        'zip'     => $order->get_shipping_postcode() ?: $order->get_billing_postcode(),
        'country' => $order->get_shipping_country() ?: ( $order->get_billing_country() ?: 'US' ),
        'phone'   => $order->get_billing_phone(),
        'email'   => $order->get_billing_email(),
    );

    return array( $from, $to );
}

/**
 * @param array<string,string> $address
 */
function mnu_labels_validate_address( array $address, string $label ): bool|WP_Error {
    $required = array(
        'name'    => 'name',
        'street1' => 'street address',
        'city'    => 'city',
        'zip'     => 'postal code',
        'country' => 'country',
    );

    $country = strtoupper( (string) ( $address['country'] ?? '' ) );
    if ( in_array( $country, array( 'US', 'CA' ), true ) ) {
        $required['state'] = 'state/province';
    }

    $missing = array();
    foreach ( $required as $key => $friendly ) {
        if ( '' === trim( (string) ( $address[ $key ] ?? '' ) ) ) {
            $missing[] = $friendly;
        }
    }

    if ( $missing ) {
        return new WP_Error(
            'incomplete_shipping_address',
            sprintf( '%s is missing: %s.', $label, implode( ', ', $missing ) ),
            array( 'status' => 422 )
        );
    }

    return true;
}

function mnu_labels_parcel_from_order( WC_Order $order, int $seller_id ): array|WP_Error {
    $profile = function_exists( 'mnu_ship_get_profile' )
        ? mnu_ship_get_profile( $seller_id )
        : get_user_meta( $seller_id, '_thenest_shipping_profile', true );
    $profile = is_array( $profile ) ? $profile : array();

    $weight = 0.0;
    $length = (float) ( $profile['default_length_in'] ?? 8 );
    $width  = (float) ( $profile['default_width_in'] ?? 6 );
    $height = (float) ( $profile['default_height_in'] ?? 2 );
    $found  = false;

    foreach ( $order->get_items() as $item ) {
        if ( tnm_get_order_item_seller_id( $item ) !== $seller_id ) {
            continue;
        }

        $found       = true;
        $product_id  = $item->get_product_id();
        $variation_id= $item->get_variation_id();
        $source_id   = $variation_id ?: $product_id;
        $quantity    = max( 1, (int) $item->get_quantity() );
        $item_weight = (float) ( get_post_meta( $source_id, '_thenest_weight_oz', true ) ?: get_post_meta( $product_id, '_thenest_weight_oz', true ) ?: ( $profile['default_weight_oz'] ?? 8 ) );

        $weight += max( 0.1, $item_weight ) * $quantity;
        $length  = max( $length, (float) ( get_post_meta( $source_id, '_thenest_length_in', true ) ?: get_post_meta( $product_id, '_thenest_length_in', true ) ?: $length ) );
        $width   = max( $width, (float) ( get_post_meta( $source_id, '_thenest_width_in', true ) ?: get_post_meta( $product_id, '_thenest_width_in', true ) ?: $width ) );
        $height  = max( $height, (float) ( get_post_meta( $source_id, '_thenest_height_in', true ) ?: get_post_meta( $product_id, '_thenest_height_in', true ) ?: $height ) );
    }

    if ( ! $found ) {
        return new WP_Error( 'no_seller_items', 'This order has no items for the selected seller.', array( 'status' => 409 ) );
    }

    if ( $weight <= 0 || $length <= 0 || $width <= 0 || $height <= 0 ) {
        return new WP_Error( 'invalid_parcel', 'Package weight and dimensions must all be greater than zero.', array( 'status' => 422 ) );
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
 * @param array<int,mixed> $rates
 * @return array<int,array<string,mixed>>
 */
function mnu_labels_sort_rates( array $rates ): array {
    $rates = array_values(
        array_filter(
            $rates,
            static function ( mixed $rate ): bool {
                if ( ! is_array( $rate ) || empty( $rate['object_id'] ) ) {
                    return false;
                }
                $state = strtoupper( (string) ( $rate['object_state'] ?? 'VALID' ) );
                return 'VALID' === $state;
            }
        )
    );

    usort(
        $rates,
        static fn( array $left, array $right ): int => (float) ( $left['amount'] ?? PHP_FLOAT_MAX ) <=> (float) ( $right['amount'] ?? PHP_FLOAT_MAX )
    );

    return $rates;
}

function mnu_labels_rates( WP_REST_Request $request ): array|WP_Error {
    $order = wc_get_order( absint( $request['id'] ) );
    if ( ! $order ) {
        return new WP_Error( 'invalid_order', 'Order not found.', array( 'status' => 404 ) );
    }

    $seller_id = mnu_labels_seller_for_request( $request, $order );
    if ( is_wp_error( $seller_id ) ) {
        return $seller_id;
    }

    $existing = mnu_labels_payload( $order, $seller_id );
    if ( ! empty( $existing['transaction'] ) || ! empty( $existing['label_url'] ) ) {
        return new WP_Error( 'label_already_created', 'A shipping label already exists for this seller order.', array( 'status' => 409, 'label' => $existing ) );
    }

    list( $from, $to ) = mnu_labels_order_addresses( $order, $seller_id );
    $from_valid = mnu_labels_validate_address( $from, 'Your ship-from address' );
    if ( is_wp_error( $from_valid ) ) {
        return $from_valid;
    }
    $to_valid = mnu_labels_validate_address( $to, 'The buyer shipping address' );
    if ( is_wp_error( $to_valid ) ) {
        return $to_valid;
    }

    $parcel = mnu_labels_parcel_from_order( $order, $seller_id );
    if ( is_wp_error( $parcel ) ) {
        return $parcel;
    }

    $shippo_body = array(
        'address_from' => $from,
        'address_to'   => $to,
        'parcels'      => array( $parcel ),
        'async'        => false,
        'metadata'     => sprintf( 'MyNest order %s seller %d', $order->get_order_number(), $seller_id ),
    );
    $shipment = mnu_labels_shippo_request( '/shipments/', $shippo_body );
    if ( is_wp_error( $shipment ) ) {
        // v3.7.79 — attach the request payload so the shipper knows exactly
        // what we sent to Shippo when the response was a 4xx/5xx.
        $data = $shipment->get_error_data();
        if ( is_array( $data ) ) {
            $data['request'] = array(
                'from'   => $from,
                'to'     => $to,
                'parcel' => $parcel,
            );
            $shipment->add_data( $data );
        }
        mnu_labels_record_last_shippo_error(
            'POST', '/shipments/', $shippo_body,
            (int) ( $data['shippo_code'] ?? 0 ),
            $data['shippo_body'] ?? array(),
            '',
            array( 'order_id' => $order->get_id(), 'seller_id' => $seller_id )
        );
        return $shipment;
    }

    $rates = mnu_labels_sort_rates( isset( $shipment['rates'] ) && is_array( $shipment['rates'] ) ? $shipment['rates'] : array() );
    if ( ! $rates ) {
        $message = mnu_labels_shippo_error_message( $shipment );
        if ( 'Shippo request failed.' === $message ) {
            $message = 'No shipping services were available for this address and package. Check the shipping profile, package dimensions, and carrier accounts in Shippo.';
        }
        return new WP_Error( 'no_shipping_rates', $message, array( 'status' => 422 ) );
    }

    $shipment_id = sanitize_text_field( (string) ( $shipment['object_id'] ?? '' ) );
    if ( $shipment_id ) {
        $order->update_meta_data( '_thenest_shippo_shipment_' . $seller_id, $shipment_id );
        $order->save();
    }

    return array(
        'seller_id' => $seller_id,
        'shipment'  => $shipment,
        'rates'     => $rates,
        'parcel'    => $parcel,
        'test_mode' => ! empty( mnu_labels_settings()['test_mode'] ),
    );
}

/**
 * Return the first useful message from a transaction response.
 *
 * @param array<string,mixed> $transaction
 */
function mnu_labels_transaction_message( array $transaction ): string {
    $message = mnu_labels_shippo_error_message( $transaction );
    return 'Shippo request failed.' === $message ? 'Shippo could not create the label.' : $message;
}

/**
 * Save transaction state and finalize successful labels.
 *
 * @param array<string,mixed> $transaction
 * @param array<string,mixed> $selected_rate
 */
function mnu_labels_store_transaction( WC_Order $order, int $seller_id, array $transaction, array $selected_rate = array() ): array|WP_Error {
    $status = strtolower( sanitize_key( (string) ( $transaction['status'] ?? 'waiting' ) ) );
    if ( ! in_array( $status, array( 'success', 'queued', 'waiting' ), true ) ) {
        return new WP_Error( 'label_purchase_failed', mnu_labels_transaction_message( $transaction ), array( 'status' => 502 ) );
    }

    $suffix         = '_' . $seller_id;
    $transaction_id = sanitize_text_field( (string) ( $transaction['object_id'] ?? '' ) );
    $tracking       = sanitize_text_field( (string) ( $transaction['tracking_number'] ?? '' ) );
    $label          = esc_url_raw( (string) ( $transaction['label_url'] ?? '' ) );
    $rate_data      = isset( $transaction['rate'] ) && is_array( $transaction['rate'] ) ? $transaction['rate'] : array();
    $provider       = sanitize_text_field( (string) ( $rate_data['provider'] ?? $selected_rate['provider'] ?? '' ) );
    $service        = sanitize_text_field( (string) ( $rate_data['servicelevel']['name'] ?? $rate_data['servicelevel_name'] ?? $selected_rate['service'] ?? '' ) );
    $amount         = wc_format_decimal( (string) ( $rate_data['amount'] ?? $selected_rate['amount'] ?? '' ) );
    $currency       = sanitize_text_field( (string) ( $rate_data['currency'] ?? $selected_rate['currency'] ?? $order->get_currency() ) );

    if ( $transaction_id ) {
        $order->update_meta_data( '_thenest_label_transaction' . $suffix, $transaction_id );
        $order->update_meta_data( '_thenest_shippo_transaction' . $suffix, $transaction_id );
    }
    $order->update_meta_data( '_thenest_label_status' . $suffix, $status );
    $order->update_meta_data( '_thenest_tracking_carrier' . $suffix, $provider );
    $order->update_meta_data( '_thenest_shipping_service' . $suffix, $service );
    $order->update_meta_data( '_thenest_label_amount' . $suffix, $amount );
    $order->update_meta_data( '_thenest_label_currency' . $suffix, $currency );

    if ( $label ) {
        $order->update_meta_data( '_thenest_label_url' . $suffix, $label );
    }
    if ( $tracking ) {
        $order->update_meta_data( '_thenest_tracking_number' . $suffix, $tracking );
        $order->update_meta_data( '_tnm_tracking_' . $seller_id, $tracking );
    }

    if ( 'success' === $status && $label ) {
        $already_finalized = (bool) $order->get_meta( '_mnu_label_finalized_' . $seller_id, true );
        $order->update_meta_data( '_tnm_seller_status_' . $seller_id, 'shipped' );

        if ( ! $already_finalized ) {
            $order->add_order_note(
                sprintf(
                    '%s purchased a %s%s shipping label. Tracking: %s',
                    tnm_seller_display_name( $seller_id ),
                    $provider ? $provider . ' ' : '',
                    $service,
                    $tracking ?: 'pending'
                )
            );
            $order->update_meta_data( '_mnu_label_finalized_' . $seller_id, current_time( 'mysql', true ) );
        }

        $customer_id = (int) $order->get_customer_id();
        if ( $customer_id && ! $order->get_meta( '_mnu_label_buyer_notice_' . $seller_id, true ) ) {
            tnm_notify(
                $customer_id,
                $seller_id,
                'order_shipped',
                'Order #' . $order->get_order_number() . ' shipped',
                $tracking ? 'Tracking number: ' . $tracking : 'The seller created a shipping label for your order.',
                $order->get_id(),
                'shop_order',
                $order->get_view_order_url()
            );
            if ( class_exists( 'MNU_Ops' ) ) {
                MNU_Ops::notify_user(
                    $customer_id,
                    'Order shipped',
                    'Your MyNest order #' . $order->get_order_number() . ' has shipped.',
                    array(
                        'type'      => 'order_shipped',
                        'order_id'  => $order->get_id(),
                        'seller_id' => $seller_id,
                    )
                );
            }
            $order->update_meta_data( '_mnu_label_buyer_notice_' . $seller_id, current_time( 'mysql', true ) );
        }
    }

    $order->save();
    return mnu_labels_payload( $order, $seller_id );
}

function mnu_labels_buy( WP_REST_Request $request ): array|WP_Error {
    $order = wc_get_order( absint( $request['id'] ) );
    if ( ! $order ) {
        return new WP_Error( 'invalid_order', 'Order not found.', array( 'status' => 404 ) );
    }

    $seller_id = mnu_labels_seller_for_request( $request, $order );
    if ( is_wp_error( $seller_id ) ) {
        return $seller_id;
    }

    $existing = mnu_labels_payload( $order, $seller_id );
    if ( ! empty( $existing['transaction'] ) || ! empty( $existing['label_url'] ) ) {
        return array(
            'ok'        => true,
            'existing'  => true,
            'seller_id' => $seller_id,
            'status'    => $existing['status'] ?: 'success',
            'label'     => $existing,
        );
    }

    $data = $request->get_json_params();
    if ( ! is_array( $data ) ) {
        $data = $request->get_params();
    }

    $rate_id = sanitize_text_field( (string) ( $data['rate'] ?? $data['rate_object_id'] ?? '' ) );
    if ( ! $rate_id ) {
        return new WP_Error( 'missing_rate', 'Select a shipping rate before purchasing the label.', array( 'status' => 400 ) );
    }

    $settings = mnu_labels_settings();
    if ( ! empty( $settings['test_mode'] ) && str_starts_with( $settings['shippo_token'], 'shippo_live_' ) ) {
        return new WP_Error( 'live_token_blocked', 'Test mode is enabled, but a live Shippo token is configured. Use a test token or have an administrator intentionally disable test mode.', array( 'status' => 409 ) );
    }

    $selected_rate = array(
        'provider' => sanitize_text_field( (string) ( $data['provider'] ?? '' ) ),
        'service'  => sanitize_text_field( (string) ( $data['service'] ?? '' ) ),
        'amount'   => wc_format_decimal( (string) ( $data['amount'] ?? '' ) ),
        'currency' => sanitize_text_field( (string) ( $data['currency'] ?? $order->get_currency() ) ),
    );

    $transaction = mnu_labels_shippo_request(
        '/transactions/',
        array(
            'rate'            => $rate_id,
            'label_file_type' => 'PDF',
            'async'           => false,
            'metadata'        => sprintf( 'MyNest order %s seller %d', $order->get_order_number(), $seller_id ),
        )
    );
    if ( is_wp_error( $transaction ) ) {
        return $transaction;
    }

    $label = mnu_labels_store_transaction( $order, $seller_id, $transaction, $selected_rate );
    if ( is_wp_error( $label ) ) {
        return $label;
    }

    return array(
        'ok'        => true,
        'seller_id' => $seller_id,
        'status'    => $label['status'] ?: 'success',
        'label'     => $label,
    );
}

function mnu_labels_payload( WC_Order $order, int $seller_id ): array {
    $suffix   = '_' . $seller_id;
    $settings = mnu_labels_settings();

    return array(
        'label_url'       => (string) ( $order->get_meta( '_thenest_label_url' . $suffix, true ) ?: $order->get_meta( '_thenest_label_url', true ) ),
        'tracking_number' => (string) ( $order->get_meta( '_thenest_tracking_number' . $suffix, true ) ?: $order->get_meta( '_thenest_tracking_number', true ) ),
        'carrier'         => (string) ( $order->get_meta( '_thenest_tracking_carrier' . $suffix, true ) ?: $order->get_meta( '_thenest_tracking_carrier', true ) ),
        'service'         => (string) $order->get_meta( '_thenest_shipping_service' . $suffix, true ),
        'amount'          => (string) $order->get_meta( '_thenest_label_amount' . $suffix, true ),
        'currency'        => (string) ( $order->get_meta( '_thenest_label_currency' . $suffix, true ) ?: $order->get_currency() ),
        'transaction'     => (string) ( $order->get_meta( '_thenest_label_transaction' . $suffix, true ) ?: $order->get_meta( '_thenest_label_transaction', true ) ),
        'status'          => (string) ( $order->get_meta( '_thenest_label_status' . $suffix, true ) ?: ( $order->get_meta( '_thenest_label_url' . $suffix, true ) ? 'success' : '' ) ),
        'test_mode'       => ! empty( $settings['test_mode'] ),
    );
}

function mnu_labels_get( WP_REST_Request $request ): array|WP_Error {
    $order = wc_get_order( absint( $request['id'] ) );
    if ( ! $order ) {
        return new WP_Error( 'invalid_order', 'Order not found.', array( 'status' => 404 ) );
    }

    $seller_id = mnu_labels_seller_for_request( $request, $order );
    if ( is_wp_error( $seller_id ) ) {
        return $seller_id;
    }

    $label = mnu_labels_payload( $order, $seller_id );
    if ( empty( $label['label_url'] ) && ! empty( $label['transaction'] ) ) {
        $transaction = mnu_labels_shippo_get( '/transactions/' . rawurlencode( $label['transaction'] ) . '/' );
        if ( is_wp_error( $transaction ) ) {
            return $transaction;
        }
        $refreshed = mnu_labels_store_transaction( $order, $seller_id, $transaction );
        if ( is_wp_error( $refreshed ) ) {
            return $refreshed;
        }
        $label = $refreshed;
    }

    return array(
        'seller_id' => $seller_id,
        'label'     => $label,
    );
}
