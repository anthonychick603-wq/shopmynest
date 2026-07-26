<?php

defined( 'ABSPATH' ) || exit;

/**
 * Mobile operations and external-service integrations.
 *
 * The main marketplace API authenticates signed MyNest bearer tokens through
 * TNM_Auth. These endpoints also retain read-only compatibility with the older
 * token meta keys used by previous mobile builds.
 */
final class MNU_Ops {
    public const VERSION = '5.0.0';
    public const NS = 'nest-ops/v1';
    public const OPTION_GOOGLE_KEY = 'thenest_google_places_api_key';
    public const OPTION_SHIPPO_KEY = 'thenest_shippo_api_token';
    public const OPTION_LABEL_MODE = 'thenest_label_mode';

    public static function init(): void {
        add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
        add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 20 );
        add_action( 'woocommerce_new_order', array( __CLASS__, 'notify_sellers_new_order' ), 20, 1 );
        add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'notify_buyer_status_change' ), 20, 4 );
    }

    public static function admin_menu(): void {
        add_submenu_page(
            'tnm-marketplace',
            __( 'Operations & Integrations', 'mynest-unified-marketplace' ),
            __( 'Operations', 'mynest-unified-marketplace' ),
            'manage_woocommerce',
            'mnu-operations',
            array( __CLASS__, 'settings_page' )
        );
    }

    public static function settings_page(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        if ( isset( $_POST['thenest_ops_save'] ) ) {
            check_admin_referer( 'thenest_ops_save' );

            if ( ! empty( $_POST[ self::OPTION_GOOGLE_KEY ] ) ) {
                update_option(
                    self::OPTION_GOOGLE_KEY,
                    sanitize_text_field( wp_unslash( $_POST[ self::OPTION_GOOGLE_KEY ] ) ),
                    false
                );
            }

            if ( ! empty( $_POST[ self::OPTION_SHIPPO_KEY ] ) ) {
                $shippo_token = sanitize_text_field( wp_unslash( $_POST[ self::OPTION_SHIPPO_KEY ] ) );
                update_option( self::OPTION_SHIPPO_KEY, $shippo_token, false );

                $label_settings                  = (array) get_option( 'thenest_shipping_labels_settings', array() );
                $label_settings['shippo_token'] = $shippo_token;
                update_option( 'thenest_shipping_labels_settings', $label_settings, false );
            }

            $mode = sanitize_key( wp_unslash( $_POST[ self::OPTION_LABEL_MODE ] ?? 'test' ) );
            update_option( self::OPTION_LABEL_MODE, in_array( $mode, array( 'test', 'live' ), true ) ? $mode : 'test', false );

            echo '<div class="notice notice-success"><p>' . esc_html__( 'MyNest operations settings saved.', 'mynest-unified-marketplace' ) . '</p></div>';
        }

        $mode = (string) get_option( self::OPTION_LABEL_MODE, 'test' );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'MyNest Operations & Integrations', 'mynest-unified-marketplace' ); ?></h1>
            <p><?php esc_html_e( 'Configure server-side address suggestions, push notifications, and Shippo shipping services. Secret values are never returned to the mobile app.', 'mynest-unified-marketplace' ); ?></p>
            <form method="post">
                <?php wp_nonce_field( 'thenest_ops_save' ); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="mnu-google-key"><?php esc_html_e( 'Google Places API key', 'mynest-unified-marketplace' ); ?></label></th>
                        <td>
                            <input id="mnu-google-key" type="password" name="<?php echo esc_attr( self::OPTION_GOOGLE_KEY ); ?>" value="" placeholder="<?php esc_attr_e( 'Leave blank to keep saved key', 'mynest-unified-marketplace' ); ?>" class="regular-text" autocomplete="new-password">
                            <p class="description"><?php echo get_option( self::OPTION_GOOGLE_KEY, '' ) ? esc_html__( 'A key is configured.', 'mynest-unified-marketplace' ) : esc_html__( 'No key is configured.', 'mynest-unified-marketplace' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mnu-shippo-key"><?php esc_html_e( 'Shippo API token', 'mynest-unified-marketplace' ); ?></label></th>
                        <td>
                            <input id="mnu-shippo-key" type="password" name="<?php echo esc_attr( self::OPTION_SHIPPO_KEY ); ?>" value="" placeholder="<?php esc_attr_e( 'Leave blank to keep saved token', 'mynest-unified-marketplace' ); ?>" class="regular-text" autocomplete="new-password">
                            <p class="description"><?php echo get_option( self::OPTION_SHIPPO_KEY, '' ) ? esc_html__( 'A token is configured.', 'mynest-unified-marketplace' ) : esc_html__( 'No token is configured.', 'mynest-unified-marketplace' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mnu-label-mode"><?php esc_html_e( 'Shipping-label mode', 'mynest-unified-marketplace' ); ?></label></th>
                        <td>
                            <select id="mnu-label-mode" name="<?php echo esc_attr( self::OPTION_LABEL_MODE ); ?>">
                                <option value="test" <?php selected( $mode, 'test' ); ?>><?php esc_html_e( 'Test', 'mynest-unified-marketplace' ); ?></option>
                                <option value="live" <?php selected( $mode, 'live' ); ?>><?php esc_html_e( 'Live', 'mynest-unified-marketplace' ); ?></option>
                            </select>
                        </td>
                    </tr>
                </table>
                <?php submit_button( __( 'Save settings', 'mynest-unified-marketplace' ), 'primary', 'thenest_ops_save' ); ?>
            </form>

            <h2><?php esc_html_e( 'Health endpoint', 'mynest-unified-marketplace' ); ?></h2>
            <code><?php echo esc_html( rest_url( self::NS . '/health' ) ); ?></code>
        </div>
        <?php
    }

    public static function routes(): void {
        register_rest_route(
            self::NS,
            '/health',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => static function (): array {
                    return array(
                        'ok'                       => true,
                        'version'                  => self::VERSION,
                        'google_places_configured' => (bool) get_option( self::OPTION_GOOGLE_KEY, '' ),
                        'shippo_configured'        => (bool) get_option( self::OPTION_SHIPPO_KEY, '' ),
                        'label_mode'                => get_option( self::OPTION_LABEL_MODE, 'test' ),
                    );
                },
                'permission_callback' => '__return_true',
            )
        );

        register_rest_route( self::NS, '/device-token', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'save_device_token' ), 'permission_callback' => array( __CLASS__, 'logged_in_rest' ) ) );
        register_rest_route( self::NS, '/addresses', array( array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'get_addresses' ), 'permission_callback' => array( __CLASS__, 'logged_in_rest' ) ), array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'save_addresses' ), 'permission_callback' => array( __CLASS__, 'logged_in_rest' ) ) ) );
        register_rest_route( self::NS, '/address/suggest', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'address_suggest' ), 'permission_callback' => array( __CLASS__, 'logged_in_rest' ) ) );
        register_rest_route( self::NS, '/shipping/rates', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'shipping_rates' ), 'permission_callback' => array( __CLASS__, 'logged_in_rest' ) ) );
        register_rest_route( self::NS, '/shipping/label', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'create_label' ), 'permission_callback' => array( __CLASS__, 'seller_or_admin_rest' ) ) );
        register_rest_route( self::NS, '/orders/(?P<id>\d+)/mark-shipped', array( 'methods' => WP_REST_Server::EDITABLE, 'callback' => array( __CLASS__, 'mark_shipped' ), 'permission_callback' => array( __CLASS__, 'seller_or_admin_rest' ) ) );
        register_rest_route( self::NS, '/account/photo', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'save_account_photo' ), 'permission_callback' => array( __CLASS__, 'logged_in_rest' ) ) );
    }

    /**
     * Return the authenticated WordPress user, with compatibility for old app tokens.
     */
    public static function get_bearer_user_id( ?WP_REST_Request $request = null ): int {
        $current = get_current_user_id();
        if ( $current ) {
            return $current;
        }

        $token = '';
        if ( $request ) {
            $token = sanitize_text_field( (string) $request->get_header( 'x-nest-mobile-token' ) );
            if ( ! $token ) {
                $authorization = (string) $request->get_header( 'authorization' );
                if ( 0 === stripos( $authorization, 'bearer ' ) ) {
                    $token = sanitize_text_field( trim( substr( $authorization, 7 ) ) );
                }
            }
        }
        if ( ! $token ) {
            $token = tnm_request_bearer_token();
        }
        if ( ! $token ) {
            return 0;
        }

        if ( class_exists( 'TNM_Auth' ) ) {
            $payload = TNM_Auth::decode_token( $token );
            if ( ! is_wp_error( $payload ) ) {
                return (int) $payload['sub'];
            }
        }

        $users = get_users(
            array(
                'meta_query' => array(
                    'relation' => 'OR',
                    array( 'key' => 'thenest_mobile_token', 'value' => $token ),
                    array( 'key' => 'nest_mobile_token', 'value' => $token ),
                    array( 'key' => '_thenest_mobile_token', 'value' => $token ),
                    array( 'key' => '_nest_mobile_token', 'value' => $token ),
                ),
                'number'     => 1,
                'fields'     => 'ID',
            )
        );

        return $users ? (int) $users[0] : 0;
    }

    public static function logged_in_rest( WP_REST_Request $request ): bool|WP_Error {
        return self::get_bearer_user_id( $request ) > 0
            ? true
            : new WP_Error( 'not_logged_in', __( 'You must be logged in.', 'mynest-unified-marketplace' ), array( 'status' => 401 ) );
    }

    public static function seller_or_admin_rest( WP_REST_Request $request ): bool|WP_Error {
        $user_id = self::get_bearer_user_id( $request );
        if ( $user_id && ( user_can( $user_id, 'manage_woocommerce' ) || user_can( $user_id, 'manage_options' ) ) ) {
            return true;
        }
        return $user_id && tnm_is_seller( $user_id )
            ? true
            : new WP_Error( 'seller_required', __( 'An approved seller account is required.', 'mynest-unified-marketplace' ), array( 'status' => 403 ) );
    }

    public static function save_device_token( WP_REST_Request $request ): array|WP_Error {
        $user_id = self::get_bearer_user_id( $request );
        if ( ! $user_id ) {
            return new WP_Error( 'not_logged_in', __( 'You must be logged in.', 'mynest-unified-marketplace' ), array( 'status' => 401 ) );
        }

        $token = sanitize_text_field( (string) ( $request->get_param( 'expo_push_token' ) ?: $request->get_param( 'token' ) ) );
        if ( ! $token || strlen( $token ) > 255 ) {
            return new WP_Error( 'invalid_token', __( 'A valid Expo push token is required.', 'mynest-unified-marketplace' ), array( 'status' => 422 ) );
        }

        $tokens = get_user_meta( $user_id, 'thenest_expo_push_tokens', true );
        $tokens = is_array( $tokens ) ? array_filter( array_map( 'sanitize_text_field', $tokens ) ) : array();
        if ( ! in_array( $token, $tokens, true ) ) {
            $tokens[] = $token;
        }
        update_user_meta( $user_id, 'thenest_expo_push_tokens', array_slice( array_values( array_unique( $tokens ) ), -10 ) );

        return array( 'ok' => true, 'saved' => true );
    }

    public static function get_addresses( WP_REST_Request $request ): array|WP_Error {
        $user_id = self::get_bearer_user_id( $request );
        if ( ! $user_id ) {
            return new WP_Error( 'not_logged_in', __( 'You must be logged in.', 'mynest-unified-marketplace' ), array( 'status' => 401 ) );
        }

        $billing  = get_user_meta( $user_id, 'thenest_billing_address', true );
        $shipping = get_user_meta( $user_id, 'thenest_shipping_address', true );

        return array(
            'billing'  => is_array( $billing ) && $billing ? $billing : self::woo_address( $user_id, 'billing' ),
            'shipping' => is_array( $shipping ) && $shipping ? $shipping : self::woo_address( $user_id, 'shipping' ),
        );
    }

    private static function woo_address( int $user_id, string $type ): array {
        $address = array();
        foreach ( array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone' ) as $field ) {
            $address[ $field ] = (string) get_user_meta( $user_id, $type . '_' . $field, true );
        }
        return $address;
    }

    public static function save_addresses( WP_REST_Request $request ): array|WP_Error {
        $user_id = self::get_bearer_user_id( $request );
        if ( ! $user_id ) {
            return new WP_Error( 'not_logged_in', __( 'You must be logged in.', 'mynest-unified-marketplace' ), array( 'status' => 401 ) );
        }

        $data     = (array) $request->get_json_params();
        $billing  = self::sanitize_address( $data['billing'] ?? $request->get_param( 'billing' ) ?? array() );
        $shipping = self::sanitize_address( $data['shipping'] ?? $request->get_param( 'shipping' ) ?? $billing );

        update_user_meta( $user_id, 'thenest_billing_address', $billing );
        update_user_meta( $user_id, 'thenest_shipping_address', $shipping );

        foreach ( $billing as $key => $value ) {
            update_user_meta( $user_id, 'billing_' . $key, $value );
        }
        foreach ( $shipping as $key => $value ) {
            update_user_meta( $user_id, 'shipping_' . $key, $value );
        }

        return array( 'ok' => true, 'billing' => $billing, 'shipping' => $shipping );
    }

    public static function sanitize_address( mixed $address ): array {
        $address = is_array( $address ) ? $address : array();
        return array(
            'first_name' => sanitize_text_field( (string) ( $address['first_name'] ?? '' ) ),
            'last_name'  => sanitize_text_field( (string) ( $address['last_name'] ?? '' ) ),
            'company'    => sanitize_text_field( (string) ( $address['company'] ?? '' ) ),
            'address_1'  => sanitize_text_field( (string) ( $address['address_1'] ?? $address['street1'] ?? '' ) ),
            'address_2'  => sanitize_text_field( (string) ( $address['address_2'] ?? $address['street2'] ?? '' ) ),
            'city'       => sanitize_text_field( (string) ( $address['city'] ?? '' ) ),
            'state'      => sanitize_text_field( (string) ( $address['state'] ?? '' ) ),
            'postcode'   => sanitize_text_field( (string) ( $address['postcode'] ?? $address['zip'] ?? '' ) ),
            'country'    => strtoupper( sanitize_text_field( (string) ( $address['country'] ?? 'US' ) ) ),
            'email'      => sanitize_email( (string) ( $address['email'] ?? '' ) ),
            'phone'      => sanitize_text_field( (string) ( $address['phone'] ?? '' ) ),
        );
    }

    private static function shippo_address( mixed $address ): array {
        $address = self::sanitize_address( $address );
        return array(
            'name'    => trim( $address['first_name'] . ' ' . $address['last_name'] ),
            'company' => $address['company'],
            'street1' => $address['address_1'],
            'street2' => $address['address_2'],
            'city'    => $address['city'],
            'state'   => $address['state'],
            'zip'     => $address['postcode'],
            'country' => $address['country'],
            'email'   => $address['email'],
            'phone'   => $address['phone'],
        );
    }

    private static function sanitize_parcels( mixed $parcels ): array {
        $clean = array();
        foreach ( is_array( $parcels ) ? $parcels : array() as $parcel ) {
            if ( ! is_array( $parcel ) ) {
                continue;
            }
            $clean[] = array(
                'length'        => (string) max( 0.1, (float) ( $parcel['length'] ?? 0 ) ),
                'width'         => (string) max( 0.1, (float) ( $parcel['width'] ?? 0 ) ),
                'height'        => (string) max( 0.1, (float) ( $parcel['height'] ?? 0 ) ),
                'distance_unit' => in_array( ( $parcel['distance_unit'] ?? 'in' ), array( 'in', 'cm', 'ft', 'mm', 'm', 'yd' ), true ) ? $parcel['distance_unit'] : 'in',
                'weight'        => (string) max( 0.1, (float) ( $parcel['weight'] ?? 0 ) ),
                'mass_unit'     => in_array( ( $parcel['mass_unit'] ?? 'oz' ), array( 'g', 'kg', 'lb', 'oz' ), true ) ? $parcel['mass_unit'] : 'oz',
            );
        }
        return $clean;
    }

    public static function address_suggest( WP_REST_Request $request ): array|WP_Error {
        $input = sanitize_text_field( (string) $request->get_param( 'input' ) );
        if ( strlen( $input ) < 3 ) {
            return array( 'ok' => true, 'predictions' => array() );
        }

        $key = (string) get_option( self::OPTION_GOOGLE_KEY, '' );
        if ( ! $key ) {
            return new WP_Error( 'places_not_configured', __( 'Google Places is not configured.', 'mynest-unified-marketplace' ), array( 'status' => 503 ) );
        }

        $response = wp_remote_post(
            'https://places.googleapis.com/v1/places:autocomplete',
            array(
                'headers' => array(
                    'Content-Type'   => 'application/json',
                    'X-Goog-Api-Key' => $key,
                ),
                'body'    => wp_json_encode(
                    array(
                        'input'                       => $input,
                        'includedPrimaryTypes'        => array( 'street_address', 'premise' ),
                        'includedRegionCodes'         => array( 'us' ),
                        'languageCode'                => 'en-US',
                    )
                ),
                'timeout' => 12,
            )
        );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'places_error', $response->get_error_message(), array( 'status' => 502 ) );
        }

        $json = json_decode( wp_remote_retrieve_body( $response ), true );
        $code = wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error( 'places_error', sanitize_text_field( (string) ( $json['error']['message'] ?? 'Google Places request failed.' ) ), array( 'status' => 502 ) );
        }

        $predictions = array();
        foreach ( (array) ( $json['suggestions'] ?? array() ) as $suggestion ) {
            $prediction = (array) ( $suggestion['placePrediction'] ?? array() );
            $predictions[] = array(
                'place_id' => sanitize_text_field( (string) ( $prediction['placeId'] ?? '' ) ),
                'text'     => sanitize_text_field( (string) ( $prediction['text']['text'] ?? '' ) ),
            );
        }

        return array( 'ok' => true, 'predictions' => array_values( array_filter( $predictions, static fn( array $row ): bool => (bool) $row['text'] ) ) );
    }

    /**
     * Create live Shippo rates from either an existing seller order or explicit
     * checkout address/parcel data supplied by the authenticated app.
     */
    public static function shipping_rates( WP_REST_Request $request ): array|WP_Error {
        $data     = (array) $request->get_json_params();
        $order_id = absint( $data['order_id'] ?? $request->get_param( 'order_id' ) );

        if ( $order_id ) {
            $order = wc_get_order( $order_id );
            if ( ! $order ) {
                return new WP_Error( 'invalid_order', __( 'Order not found.', 'mynest-unified-marketplace' ), array( 'status' => 404 ) );
            }
            $seller_id = self::order_seller_id( $order, $request );
            if ( ! $seller_id ) {
                return new WP_Error( 'forbidden', __( 'You cannot access this order.', 'mynest-unified-marketplace' ), array( 'status' => 403 ) );
            }
            if ( ! function_exists( 'mnu_labels_order_addresses' ) || ! function_exists( 'mnu_labels_parcel_from_order' ) ) {
                return new WP_Error( 'shipping_unavailable', __( 'Shipping-label services are unavailable.', 'mynest-unified-marketplace' ), array( 'status' => 503 ) );
            }
            list( $from, $to ) = mnu_labels_order_addresses( $order, $seller_id );
            $parcel = mnu_labels_parcel_from_order( $order, $seller_id );
            if ( is_wp_error( $parcel ) ) {
                return $parcel;
            }
            $parcels = array( $parcel );
        } else {
            $from    = self::shippo_address( $data['address_from'] ?? array() );
            $to      = self::shippo_address( $data['address_to'] ?? array() );
            $parcels = self::sanitize_parcels( $data['parcels'] ?? array() );

            if ( empty( $from['street1'] ) || empty( $from['city'] ) || empty( $from['zip'] ) || empty( $to['street1'] ) || empty( $to['city'] ) || empty( $to['zip'] ) || ! $parcels ) {
                return new WP_Error( 'invalid_shipment', __( 'Complete origin, destination, and parcel data are required.', 'mynest-unified-marketplace' ), array( 'status' => 422 ) );
            }
        }

        $shipment = self::shippo_request(
            '/shipments/',
            array(
                'address_from' => $from,
                'address_to'   => $to,
                'parcels'      => $parcels,
                'async'        => false,
            )
        );
        if ( is_wp_error( $shipment ) ) {
            return $shipment;
        }

        return array(
            'ok'          => true,
            'shipment_id' => sanitize_text_field( (string) ( $shipment['object_id'] ?? '' ) ),
            'rates'       => self::public_rates( (array) ( $shipment['rates'] ?? array() ) ),
        );
    }

    private static function public_rates( array $rates ): array {
        $out = array();
        foreach ( $rates as $rate ) {
            if ( ! is_array( $rate ) ) {
                continue;
            }
            $out[] = array(
                'object_id'          => sanitize_text_field( (string) ( $rate['object_id'] ?? '' ) ),
                'provider'           => sanitize_text_field( (string) ( $rate['provider'] ?? '' ) ),
                'servicelevel_name'  => sanitize_text_field( (string) ( $rate['servicelevel']['name'] ?? $rate['servicelevel_name'] ?? '' ) ),
                'servicelevel_token' => sanitize_text_field( (string) ( $rate['servicelevel']['token'] ?? $rate['servicelevel_token'] ?? '' ) ),
                'amount'             => (float) ( $rate['amount'] ?? 0 ),
                'currency'           => sanitize_text_field( (string) ( $rate['currency'] ?? get_woocommerce_currency() ) ),
                'estimated_days'     => isset( $rate['estimated_days'] ) ? absint( $rate['estimated_days'] ) : null,
                'duration_terms'     => sanitize_text_field( (string) ( $rate['duration_terms'] ?? '' ) ),
            );
        }
        return $out;
    }

    private static function shippo_request( string $path, array $body ): array|WP_Error {
        if ( function_exists( 'mnu_labels_shippo_request' ) ) {
            return mnu_labels_shippo_request( $path, $body );
        }

        $key = (string) get_option( self::OPTION_SHIPPO_KEY, '' );
        if ( ! $key ) {
            return new WP_Error( 'shippo_not_configured', __( 'Shippo is not configured.', 'mynest-unified-marketplace' ), array( 'status' => 503 ) );
        }
        $response = wp_remote_post(
            'https://api.goshippo.com' . $path,
            array(
                'headers' => array(
                    'Authorization' => 'ShippoToken ' . $key,
                    'Content-Type'  => 'application/json',
                ),
                'body'    => wp_json_encode( $body ),
                'timeout' => 40,
            )
        );
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $json = json_decode( wp_remote_retrieve_body( $response ), true );
        $code = wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error( 'shippo_error', sanitize_text_field( (string) ( $json['detail'] ?? $json['message'] ?? 'Shippo request failed.' ) ), array( 'status' => 502 ) );
        }
        return is_array( $json ) ? $json : new WP_Error( 'shippo_invalid_response', __( 'Shippo returned an invalid response.', 'mynest-unified-marketplace' ), array( 'status' => 502 ) );
    }

    private static function order_seller_id( WC_Order $order, ?WP_REST_Request $request = null ): int {
        $user_id = self::get_bearer_user_id( $request );
        if ( $user_id && ( user_can( $user_id, 'manage_woocommerce' ) || user_can( $user_id, 'manage_options' ) ) ) {
            $requested = $request ? absint( $request->get_param( 'seller_id' ) ) : 0;
            if ( $requested && tnm_order_contains_seller( $order, $requested ) ) {
                return $requested;
            }
            foreach ( $order->get_items() as $item ) {
                if ( $item instanceof WC_Order_Item_Product ) {
                    $seller_id = tnm_get_order_item_seller_id( $item );
                    if ( $seller_id ) {
                        return $seller_id;
                    }
                }
            }
            return 0;
        }
        return $user_id && tnm_order_contains_seller( $order, $user_id ) ? $user_id : 0;
    }

    public static function create_label( WP_REST_Request $request ): array|WP_Error {
        $data     = (array) $request->get_json_params();
        $order_id = absint( $data['order_id'] ?? $request->get_param( 'order_id' ) );
        $order    = wc_get_order( $order_id );
        if ( ! $order ) {
            return new WP_Error( 'missing_order', __( 'Order not found.', 'mynest-unified-marketplace' ), array( 'status' => 404 ) );
        }

        $seller_id = self::order_seller_id( $order, $request );
        if ( ! $seller_id ) {
            return new WP_Error( 'forbidden', __( 'You cannot access this order.', 'mynest-unified-marketplace' ), array( 'status' => 403 ) );
        }

        $rate_id = sanitize_text_field( (string) ( $data['rate_object_id'] ?? $data['rate'] ?? $request->get_param( 'rate_object_id' ) ) );
        if ( ! $rate_id ) {
            return new WP_Error( 'missing_rate', __( 'A selected shipping rate is required.', 'mynest-unified-marketplace' ), array( 'status' => 422 ) );
        }

        $transaction = self::shippo_request(
            '/transactions/',
            array(
                'rate'            => $rate_id,
                'label_file_type' => 'PDF',
                'async'           => false,
                'test'            => 'live' !== get_option( self::OPTION_LABEL_MODE, 'test' ),
            )
        );
        if ( is_wp_error( $transaction ) ) {
            return $transaction;
        }

        $status = strtolower( sanitize_text_field( (string) ( $transaction['status'] ?? '' ) ) );
        if ( $status && ! in_array( $status, array( 'success', 'queued', 'waiting' ), true ) ) {
            return new WP_Error( 'label_purchase_failed', sanitize_text_field( (string) ( $transaction['messages'][0]['text'] ?? 'Shippo could not create the label.' ) ), array( 'status' => 502 ) );
        }

        $tracking = sanitize_text_field( (string) ( $transaction['tracking_number'] ?? '' ) );
        $label    = esc_url_raw( (string) ( $transaction['label_url'] ?? '' ) );
        $carrier  = sanitize_text_field( (string) ( $transaction['rate']['provider'] ?? '' ) );
        $suffix   = '_' . $seller_id;

        $order->update_meta_data( '_thenest_shippo_transaction' . $suffix, sanitize_text_field( (string) ( $transaction['object_id'] ?? '' ) ) );
        $order->update_meta_data( '_thenest_label_transaction' . $suffix, sanitize_text_field( (string) ( $transaction['object_id'] ?? '' ) ) );
        $order->update_meta_data( '_thenest_tracking_number' . $suffix, $tracking );
        $order->update_meta_data( '_thenest_tracking_carrier' . $suffix, $carrier );
        $order->update_meta_data( '_thenest_label_url' . $suffix, $label );
        $order->update_meta_data( '_tnm_tracking_' . $seller_id, $tracking );
        $order->update_meta_data( '_tnm_seller_status_' . $seller_id, 'shipped' );
        $order->add_order_note( sprintf( '%s purchased a shipping label. Tracking: %s', tnm_seller_display_name( $seller_id ), $tracking ?: 'pending' ) );
        $order->save();

        self::push_order_shipped( $order, $seller_id );

        return array(
            'ok'              => true,
            'seller_id'       => $seller_id,
            'tracking_number' => $tracking,
            'carrier'         => $carrier,
            'label_url'       => $label,
            'status'          => $status ?: 'success',
        );
    }

    public static function mark_shipped( WP_REST_Request $request ): array|WP_Error {
        $order = wc_get_order( absint( $request['id'] ) );
        if ( ! $order ) {
            return new WP_Error( 'missing_order', __( 'Order not found.', 'mynest-unified-marketplace' ), array( 'status' => 404 ) );
        }
        $seller_id = self::order_seller_id( $order, $request );
        if ( ! $seller_id ) {
            return new WP_Error( 'forbidden', __( 'You cannot access this order.', 'mynest-unified-marketplace' ), array( 'status' => 403 ) );
        }

        $data         = (array) $request->get_json_params();
        $tracking     = sanitize_text_field( (string) ( $data['tracking_number'] ?? $request->get_param( 'tracking_number' ) ) );
        $carrier      = sanitize_text_field( (string) ( $data['carrier'] ?? $request->get_param( 'carrier' ) ) );
        $tracking_url = esc_url_raw( (string) ( $data['tracking_url'] ?? $request->get_param( 'tracking_url' ) ) );
        $note         = sanitize_textarea_field( (string) ( $data['seller_note'] ?? $request->get_param( 'seller_note' ) ) );
        $suffix       = '_' . $seller_id;

        if ( $tracking ) {
            $order->update_meta_data( '_thenest_tracking_number' . $suffix, $tracking );
            $order->update_meta_data( '_tnm_tracking_' . $seller_id, $tracking );
        }
        if ( $carrier ) {
            $order->update_meta_data( '_thenest_tracking_carrier' . $suffix, $carrier );
        }
        if ( $tracking_url ) {
            $order->update_meta_data( '_thenest_tracking_url' . $suffix, $tracking_url );
        }
        if ( $note ) {
            $order->update_meta_data( '_thenest_seller_shipping_note' . $suffix, $note );
        }
        $order->update_meta_data( '_tnm_seller_status_' . $seller_id, 'shipped' );
        $order->add_order_note( tnm_seller_display_name( $seller_id ) . ' marked their items shipped.' );
        $order->save();

        if ( $order->get_customer_id() ) {
            tnm_notify(
                (int) $order->get_customer_id(),
                $seller_id,
                'order_shipped',
                'Order #' . $order->get_order_number() . ' shipped',
                $tracking ? 'Tracking number: ' . $tracking : 'The seller marked your items shipped.',
                $order->get_id(),
                'shop_order',
                $order->get_view_order_url()
            );
        }
        self::push_order_shipped( $order, $seller_id );

        return array(
            'ok'              => true,
            'order_id'        => $order->get_id(),
            'seller_status'   => 'shipped',
            'tracking_number' => $tracking,
            'carrier'         => $carrier,
            'tracking_url'    => $tracking_url,
        );
    }

    private static function push_order_shipped( WC_Order $order, int $seller_id ): void {
        $customer_id = (int) $order->get_customer_id();
        if ( ! $customer_id ) {
            return;
        }
        $marker = '_mnu_push_shipped_' . $seller_id;
        if ( $order->get_meta( $marker, true ) ) {
            return;
        }
        self::notify_user( $customer_id, 'Order shipped', 'Your MyNest order #' . $order->get_order_number() . ' has shipped.', array( 'order_id' => $order->get_id(), 'seller_id' => $seller_id ) );
        $order->update_meta_data( $marker, current_time( 'mysql', true ) );
        $order->save();
    }

    public static function save_account_photo( WP_REST_Request $request ): array|WP_Error {
        $user_id = self::get_bearer_user_id( $request );
        if ( ! $user_id ) {
            return new WP_Error( 'not_logged_in', __( 'You must be logged in.', 'mynest-unified-marketplace' ), array( 'status' => 401 ) );
        }

        $attachment_id = absint( $request->get_param( 'attachment_id' ) );
        $url           = esc_url_raw( (string) $request->get_param( 'url' ) );

        if ( $attachment_id ) {
            if ( ! wp_attachment_is_image( $attachment_id ) ) {
                return new WP_Error( 'invalid_photo', __( 'The selected attachment is not an image.', 'mynest-unified-marketplace' ), array( 'status' => 422 ) );
            }
            $attachment = get_post( $attachment_id );
            if ( ! $attachment || ! tnm_user_can_use_attachment( $user_id, $attachment_id ) ) {
                return new WP_Error( 'photo_permission_denied', __( 'You cannot use that image.', 'mynest-unified-marketplace' ), array( 'status' => 403 ) );
            }
            $url = (string) wp_get_attachment_image_url( $attachment_id, 'full' );
            update_user_meta( $user_id, 'thenest_profile_photo_id', $attachment_id );
            update_user_meta( $user_id, 'thenest_profile_photo_url', esc_url_raw( $url ) );
        } elseif ( $url ) {
            update_user_meta( $user_id, 'thenest_profile_photo_url', $url );
        } else {
            return new WP_Error( 'missing_photo', __( 'An image attachment or image URL is required.', 'mynest-unified-marketplace' ), array( 'status' => 422 ) );
        }

        return array(
            'ok'        => true,
            'photo_url' => get_user_meta( $user_id, 'thenest_profile_photo_url', true ),
            'photo_id'  => (int) get_user_meta( $user_id, 'thenest_profile_photo_id', true ),
        );
    }

    public static function notify_sellers_new_order( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $seller_ids = array();
        foreach ( $order->get_items() as $item ) {
            if ( $item instanceof WC_Order_Item_Product ) {
                $seller_id = tnm_get_order_item_seller_id( $item );
                if ( $seller_id ) {
                    $seller_ids[ $seller_id ] = true;
                }
            }
        }

        foreach ( array_keys( $seller_ids ) as $seller_id ) {
            $marker = '_mnu_push_new_order_' . $seller_id;
            if ( $order->get_meta( $marker, true ) ) {
                continue;
            }
            self::notify_user(
                (int) $seller_id,
                'Item sold',
                'You sold an item on MyNest. Order #' . $order->get_order_number() . ' is ready to review.',
                array( 'order_id' => $order->get_id(), 'seller_id' => (int) $seller_id )
            );
            $order->update_meta_data( $marker, current_time( 'mysql', true ) );
        }
        $order->save();
    }

    public static function notify_buyer_status_change( int $order_id, string $old_status, string $new_status, $order ): void {
        if ( ! $order instanceof WC_Order || $old_status === $new_status ) {
            return;
        }
        $user_id = (int) $order->get_user_id();
        if ( ! $user_id ) {
            return;
        }

        $marker = '_mnu_push_buyer_status_' . sanitize_key( $new_status );
        if ( $order->get_meta( $marker, true ) ) {
            return;
        }
        self::notify_user(
            $user_id,
            'Order updated',
            'Your MyNest order #' . $order->get_order_number() . ' is now ' . wc_get_order_status_name( $new_status ) . '.',
            array( 'order_id' => $order_id, 'status' => $new_status )
        );
        $order->update_meta_data( $marker, current_time( 'mysql', true ) );
        $order->save();
    }

    /**
     * Send an Expo notification. Failure is non-fatal and never blocks checkout.
     */
    public static function notify_user( int $user_id, string $title, string $body, array $data = array() ): bool {
        $tokens = get_user_meta( $user_id, 'thenest_expo_push_tokens', true );
        if ( ! is_array( $tokens ) || ! $tokens ) {
            return false;
        }

        $sent = false;
        foreach ( array_unique( array_filter( array_map( 'sanitize_text_field', $tokens ) ) ) as $token ) {
            $response = wp_remote_post(
                'https://exp.host/--/api/v2/push/send',
                array(
                    'headers' => array( 'Content-Type' => 'application/json' ),
                    'body'    => wp_json_encode(
                        array(
                            'to'    => $token,
                            'title' => sanitize_text_field( $title ),
                            'body'  => sanitize_text_field( $body ),
                            'sound' => 'default',
                            'data'  => array_merge( array( 'source' => 'thenest' ), $data ),
                        )
                    ),
                    'timeout' => 8,
                )
            );
            if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) >= 200 && wp_remote_retrieve_response_code( $response ) < 300 ) {
                $sent = true;
            }
        }

        return $sent;
    }
}
