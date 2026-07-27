<?php

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'MNU_SHIP_PROFILES_NS' ) ) {
    define( 'MNU_SHIP_PROFILES_NS', 'nest-shipping/v1' );
}
if ( ! defined( 'MNU_SHIP_PROFILES_VERSION' ) ) {
    define( 'MNU_SHIP_PROFILES_VERSION', '5.1.0' );
}

/**
 * Return the user authenticated through WordPress cookies, signed MyNest
 * bearer tokens, or a legacy mobile token.
 */
function mnu_ship_current_user_id( ?WP_REST_Request $request = null ): int {
    if ( class_exists( 'MNU_Ops' ) ) {
        return MNU_Ops::get_bearer_user_id( $request );
    }

    return get_current_user_id();
}

/**
 * Default ship-from and package values for a seller.
 *
 * @return array<string, string|bool>
 */
function mnu_ship_default_profile(): array {
    return array(
        'ship_from_name'         => '',
        'ship_from_company'      => '',
        'ship_from_street1'      => '',
        'ship_from_street2'      => '',
        'ship_from_city'         => '',
        'ship_from_state'        => '',
        'ship_from_zip'          => '',
        'ship_from_country'      => 'US',
        'ship_from_phone'        => '',
        'processing_time'        => '3-5 business days',
        'default_weight_oz'      => '8',
        'default_length_in'      => '8',
        'default_width_in'       => '6',
        'default_height_in'      => '2',
        'free_shipping_allowed'  => false,
    );
}

/**
 * Allowed values for a product's package_size selection.
 *
 * @return array<int, string>
 */
function mnu_ship_allowed_package_sizes(): array {
    return array( 'small', 'medium', 'large', 'custom' );
}

/**
 * Canonical package-size presets (dimensions in inches).
 *
 * Admins may override the dimensions via the "Package size presets" section on
 * the Shipping Labels settings page (stored in the `mnu_ship_package_presets`
 * option); developers may further adjust them through the
 * `mynest_package_presets` filter. Weight is never part of a preset — sellers
 * always enter package weight themselves.
 *
 * @return array<string, array<string, string>>
 */
function mnu_ship_package_presets(): array {
    $presets = array(
        'small'  => array( 'label' => 'Small',  'length_in' => '8',  'width_in' => '6',  'height_in' => '2' ),
        'medium' => array( 'label' => 'Medium', 'length_in' => '12', 'width_in' => '10', 'height_in' => '6' ),
        'large'  => array( 'label' => 'Large',  'length_in' => '16', 'width_in' => '14', 'height_in' => '10' ),
    );

    $overrides = get_option( 'mnu_ship_package_presets', array() );
    if ( is_array( $overrides ) ) {
        foreach ( array_keys( $presets ) as $size ) {
            if ( empty( $overrides[ $size ] ) || ! is_array( $overrides[ $size ] ) ) {
                continue;
            }
            foreach ( array( 'length_in', 'width_in', 'height_in' ) as $dim ) {
                if ( ! isset( $overrides[ $size ][ $dim ] ) || '' === $overrides[ $size ][ $dim ] ) {
                    continue;
                }
                $clean = mnu_ship_decimal( $overrides[ $size ][ $dim ], $presets[ $size ][ $dim ] );
                if ( '' !== $clean ) {
                    $presets[ $size ][ $dim ] = $clean;
                }
            }
        }
    }

    /**
     * Filter the package-size presets.
     *
     * @param array<string, array<string, string>> $presets
     */
    return apply_filters( 'mynest_package_presets', $presets );
}

/**
 * Sanitize and persist admin overrides for the package-size presets.
 *
 * @param array<string, mixed> $data Raw submitted preset dimensions.
 * @return array<string, array<string, string>> The stored overrides.
 */
function mnu_ship_save_package_presets( array $data ): array {
    $defaults = array(
        'small'  => array( 'length_in' => '8',  'width_in' => '6',  'height_in' => '2' ),
        'medium' => array( 'length_in' => '12', 'width_in' => '10', 'height_in' => '6' ),
        'large'  => array( 'length_in' => '16', 'width_in' => '14', 'height_in' => '10' ),
    );

    $overrides = array();
    foreach ( $defaults as $size => $dims ) {
        $submitted = ( isset( $data[ $size ] ) && is_array( $data[ $size ] ) ) ? $data[ $size ] : array();
        foreach ( array( 'length_in', 'width_in', 'height_in' ) as $dim ) {
            $overrides[ $size ][ $dim ] = mnu_ship_decimal( $submitted[ $dim ] ?? '', $dims[ $dim ] );
        }
    }

    update_option( 'mnu_ship_package_presets', $overrides, false );

    return $overrides;
}

/**
 * Read a seller's shipping profile while preserving legacy storage.
 *
 * @return array<string, string|bool>
 */
function mnu_ship_get_profile( int $user_id ): array {
    $profile = get_user_meta( $user_id, '_thenest_shipping_profile', true );
    if ( ! is_array( $profile ) ) {
        $profile = array();
    }

    return array_merge( mnu_ship_default_profile(), $profile );
}

/**
 * Normalize a positive decimal value represented as text.
 */
function mnu_ship_decimal( mixed $value, string $fallback = '' ): string {
    if ( '' === $value || null === $value ) {
        return $fallback;
    }

    $number = function_exists( 'wc_format_decimal' )
        ? wc_format_decimal( $value, 4 )
        : number_format( max( 0, (float) $value ), 4, '.', '' );

    if ( '' === $number || (float) $number < 0 ) {
        return $fallback;
    }

    return rtrim( rtrim( (string) $number, '0' ), '.' );
}

/**
 * Sanitize and save a seller's shipping profile.
 *
 * @param array<string, mixed> $data Submitted profile values.
 * @return array<string, string|bool>
 */
function mnu_ship_save_profile( int $user_id, array $data ): array {
    $current = mnu_ship_get_profile( $user_id );
    $clean   = array();

    foreach ( array( 'ship_from_name', 'ship_from_company', 'ship_from_street1', 'ship_from_street2', 'ship_from_city', 'ship_from_state', 'ship_from_zip', 'ship_from_phone', 'processing_time' ) as $key ) {
        $clean[ $key ] = sanitize_text_field( (string) ( $data[ $key ] ?? $current[ $key ] ?? '' ) );
    }

    $country = strtoupper( sanitize_text_field( (string) ( $data['ship_from_country'] ?? $current['ship_from_country'] ?? 'US' ) ) );
    $clean['ship_from_country'] = preg_match( '/^[A-Z]{2}$/', $country ) ? $country : 'US';

    foreach ( array( 'default_weight_oz', 'default_length_in', 'default_width_in', 'default_height_in' ) as $key ) {
        $clean[ $key ] = mnu_ship_decimal( $data[ $key ] ?? $current[ $key ] ?? '', (string) ( $current[ $key ] ?? '' ) );
    }

    $clean['free_shipping_allowed'] = rest_sanitize_boolean( $data['free_shipping_allowed'] ?? $current['free_shipping_allowed'] ?? false );

    update_user_meta( $user_id, '_thenest_shipping_profile', $clean );

    return $clean;
}

/**
 * Legacy product metadata mapped to API-facing field names.
 *
 * @return array<string, string>
 */
function mnu_ship_product_fields(): array {
    return array(
        '_thenest_weight_oz'       => 'weight_oz',
        '_thenest_length_in'       => 'length_in',
        '_thenest_width_in'        => 'width_in',
        '_thenest_height_in'       => 'height_in',
        '_thenest_package_size'    => 'package_size',
        '_thenest_shipping_profile'=> 'shipping_profile',
        '_thenest_processing_time' => 'processing_time',
    );
}

/**
 * Read normalized shipping metadata for a product.
 *
 * @return array<string, string>
 */
function mnu_ship_get_product_shipping( int $product_id ): array {
    $shipping = array();

    foreach ( mnu_ship_product_fields() as $meta_key => $api_key ) {
        $shipping[ $api_key ] = (string) get_post_meta( $product_id, $meta_key, true );
    }

    // Products created before package presets existed have no stored value;
    // treat them as manually-dimensioned ("custom") so existing dims are kept.
    if ( ! in_array( $shipping['package_size'], mnu_ship_allowed_package_sizes(), true ) ) {
        $shipping['package_size'] = 'custom';
    }

    return $shipping;
}

/**
 * Save product shipping metadata and mirror dimensions into WooCommerce's
 * native product fields so rate calculations and extensions agree.
 *
 * @param array<string, mixed> $data Submitted values.
 * @return array<string, string>
 */
function mnu_ship_save_product_shipping( int $product_id, array $data ): array {
    $existing = mnu_ship_get_product_shipping( $product_id );
    $clean    = array();

    // Resolve the package size first: a preset fills the dimensions, "custom"
    // (the default) keeps whatever the seller typed in the L/W/H inputs.
    $requested_size = isset( $data['package_size'] )
        ? sanitize_key( (string) $data['package_size'] )
        : (string) ( $existing['package_size'] ?? 'custom' );
    if ( ! in_array( $requested_size, mnu_ship_allowed_package_sizes(), true ) ) {
        $requested_size = 'custom';
    }
    $clean['package_size'] = $requested_size;

    foreach ( array( 'weight_oz', 'length_in', 'width_in', 'height_in' ) as $key ) {
        $clean[ $key ] = mnu_ship_decimal( $data[ $key ] ?? $existing[ $key ] ?? '', (string) ( $existing[ $key ] ?? '' ) );
    }

    // A chosen preset overwrites submitted dimensions; weight stays seller-entered.
    if ( 'custom' !== $requested_size ) {
        $presets = mnu_ship_package_presets();
        if ( isset( $presets[ $requested_size ] ) ) {
            foreach ( array( 'length_in', 'width_in', 'height_in' ) as $dim ) {
                $clean[ $dim ] = mnu_ship_decimal( $presets[ $requested_size ][ $dim ] ?? '', $clean[ $dim ] );
            }
        }
    }

    $clean['shipping_profile'] = sanitize_key( (string) ( $data['shipping_profile'] ?? $existing['shipping_profile'] ?? '' ) );
    $clean['processing_time']  = sanitize_text_field( (string) ( $data['processing_time'] ?? $existing['processing_time'] ?? '' ) );

    foreach ( mnu_ship_product_fields() as $meta_key => $api_key ) {
        update_post_meta( $product_id, $meta_key, $clean[ $api_key ] ?? '' );
    }

    $product = wc_get_product( $product_id );
    if ( $product ) {
        $weight_unit    = (string) get_option( 'woocommerce_weight_unit', 'lbs' );
        $dimension_unit = (string) get_option( 'woocommerce_dimension_unit', 'in' );

        if ( '' !== $clean['weight_oz'] ) {
            $weight = function_exists( 'wc_get_weight' )
                ? wc_get_weight( (float) $clean['weight_oz'], $weight_unit, 'oz' )
                : (float) $clean['weight_oz'];
            $product->set_weight( (string) $weight );
        }

        foreach ( array( 'length', 'width', 'height' ) as $dimension ) {
            $api_key = $dimension . '_in';
            if ( '' === $clean[ $api_key ] ) {
                continue;
            }
            $value = function_exists( 'wc_get_dimension' )
                ? wc_get_dimension( (float) $clean[ $api_key ], $dimension_unit, 'in' )
                : (float) $clean[ $api_key ];
            $setter = 'set_' . $dimension;
            $product->{$setter}( (string) $value );
        }

        $product->save();
    }

    return $clean;
}

/**
 * Register backward-compatible shipping-profile routes.
 */
function mnu_ship_rest_routes(): void {
    register_rest_route(
        MNU_SHIP_PROFILES_NS,
        '/health',
        array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => static fn(): array => array( 'ok' => true, 'version' => MNU_SHIP_PROFILES_VERSION ),
            'permission_callback' => '__return_true',
        )
    );

    register_rest_route(
        MNU_SHIP_PROFILES_NS,
        '/seller/profile',
        array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => 'mnu_ship_get_profile_rest',
                'permission_callback' => 'mnu_ship_rest_auth',
            ),
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => 'mnu_ship_save_profile_rest',
                'permission_callback' => 'mnu_ship_rest_auth',
            ),
        )
    );

    register_rest_route(
        MNU_SHIP_PROFILES_NS,
        '/seller/products/(?P<id>\d+)/shipping',
        array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => 'mnu_ship_get_product_shipping_rest',
                'permission_callback' => 'mnu_ship_rest_auth',
            ),
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => 'mnu_ship_save_product_shipping_rest',
                'permission_callback' => 'mnu_ship_rest_auth',
            ),
        )
    );
}
add_action( 'rest_api_init', 'mnu_ship_rest_routes' );

/**
 * Require an approved seller or a WooCommerce administrator.
 */
function mnu_ship_rest_auth( WP_REST_Request $request ): bool|WP_Error {
    $user_id = mnu_ship_current_user_id( $request );

    if ( ! $user_id ) {
        return new WP_Error( 'not_logged_in', 'You must be logged in.', array( 'status' => 401 ) );
    }
    if ( ! function_exists( 'tnm_is_marketplace_user' ) || ! tnm_is_marketplace_user( $user_id ) ) {
        return new WP_Error( 'seller_required', 'An approved seller account is required.', array( 'status' => 403 ) );
    }

    return true;
}

function mnu_ship_get_profile_rest( WP_REST_Request $request ): WP_REST_Response|WP_Error {
    $user_id = mnu_ship_current_user_id( $request );
    if ( ! $user_id ) {
        return new WP_Error( 'not_logged_in', 'You must be logged in.', array( 'status' => 401 ) );
    }

    return rest_ensure_response( array( 'profile' => mnu_ship_get_profile( $user_id ) ) );
}

function mnu_ship_save_profile_rest( WP_REST_Request $request ): WP_REST_Response|WP_Error {
    $user_id = mnu_ship_current_user_id( $request );
    if ( ! $user_id ) {
        return new WP_Error( 'not_logged_in', 'You must be logged in.', array( 'status' => 401 ) );
    }

    $data = $request->get_json_params();
    if ( ! is_array( $data ) ) {
        $data = $request->get_params();
    }

    return rest_ensure_response( array( 'profile' => mnu_ship_save_profile( $user_id, $data ) ) );
}

function mnu_ship_can_edit_product( int $user_id, int $product_id ): bool {
    if ( ! $user_id ) {
        return false;
    }
    if ( user_can( $user_id, 'manage_woocommerce' ) || user_can( $user_id, 'manage_options' ) ) {
        return true;
    }

    $product = wc_get_product( $product_id );
    return $product && function_exists( 'tnm_get_product_seller_id' ) && tnm_get_product_seller_id( $product ) === $user_id;
}

function mnu_ship_get_product_shipping_rest( WP_REST_Request $request ): WP_REST_Response|WP_Error {
    $user_id   = mnu_ship_current_user_id( $request );
    $product_id = absint( $request['id'] );

    if ( ! $product_id || 'product' !== get_post_type( $product_id ) ) {
        return new WP_Error( 'invalid_product', 'Product not found.', array( 'status' => 404 ) );
    }
    if ( ! mnu_ship_can_edit_product( $user_id, $product_id ) ) {
        return new WP_Error( 'forbidden', 'You cannot edit this product.', array( 'status' => 403 ) );
    }

    return rest_ensure_response(
        array(
            'shipping' => mnu_ship_get_product_shipping( $product_id ),
            'presets'  => mnu_ship_package_presets(),
        )
    );
}

function mnu_ship_save_product_shipping_rest( WP_REST_Request $request ): WP_REST_Response|WP_Error {
    $user_id    = mnu_ship_current_user_id( $request );
    $product_id = absint( $request['id'] );

    if ( ! $product_id || 'product' !== get_post_type( $product_id ) ) {
        return new WP_Error( 'invalid_product', 'Product not found.', array( 'status' => 404 ) );
    }
    if ( ! mnu_ship_can_edit_product( $user_id, $product_id ) ) {
        return new WP_Error( 'forbidden', 'You cannot edit this product.', array( 'status' => 403 ) );
    }

    $data = $request->get_json_params();
    if ( ! is_array( $data ) ) {
        $data = $request->get_params();
    }

    return rest_ensure_response(
        array(
            'shipping' => mnu_ship_save_product_shipping( $product_id, $data ),
            'presets'  => mnu_ship_package_presets(),
        )
    );
}

function mnu_ship_add_product_metabox(): void {
    add_meta_box(
        'thenest_shipping_box',
        __( 'MyNest Shipping Package', 'mynest-unified-marketplace' ),
        'mnu_ship_product_metabox_html',
        'product',
        'side',
        'default'
    );
}
add_action( 'add_meta_boxes_product', 'mnu_ship_add_product_metabox' );

function mnu_ship_product_metabox_html( WP_Post $post ): void {
    wp_nonce_field( 'mnu_ship_product_save', 'mnu_ship_nonce' );
    $shipping = mnu_ship_get_product_shipping( $post->ID );
    $presets  = mnu_ship_package_presets();
    $selected_size = $shipping['package_size'] ?? 'custom';

    echo '<p><label><strong>' . esc_html__( 'Package size', 'mynest-unified-marketplace' ) . '</strong><br>';
    echo '<select name="mnu_shipping[package_size]" style="width:100%">';
    foreach ( $presets as $size => $preset ) {
        printf(
            '<option value="%1$s"%2$s>%3$s — %4$s×%5$s×%6$s in</option>',
            esc_attr( $size ),
            selected( $selected_size, $size, false ),
            esc_html( (string) ( $preset['label'] ?? ucfirst( $size ) ) ),
            esc_html( $preset['length_in'] ),
            esc_html( $preset['width_in'] ),
            esc_html( $preset['height_in'] )
        );
    }
    printf(
        '<option value="custom"%s>%s</option>',
        selected( $selected_size, 'custom', false ),
        esc_html__( 'Custom dimensions', 'mynest-unified-marketplace' )
    );
    echo '</select></label></p>';
    echo '<p class="description">' . esc_html__( 'Choosing a preset fills the length, width, and height below when saved. Weight is always entered manually.', 'mynest-unified-marketplace' ) . '</p>';

    $labels = array(
        'weight_oz'       => __( 'Weight (oz)', 'mynest-unified-marketplace' ),
        'length_in'       => __( 'Length (in)', 'mynest-unified-marketplace' ),
        'width_in'        => __( 'Width (in)', 'mynest-unified-marketplace' ),
        'height_in'       => __( 'Height (in)', 'mynest-unified-marketplace' ),
        'shipping_profile'=> __( 'Shipping profile', 'mynest-unified-marketplace' ),
        'processing_time' => __( 'Processing time', 'mynest-unified-marketplace' ),
    );

    foreach ( $labels as $key => $label ) {
        $type = in_array( $key, array( 'weight_oz', 'length_in', 'width_in', 'height_in' ), true ) ? 'number' : 'text';
        $step = 'number' === $type ? ' step="0.01" min="0"' : '';
        printf(
            '<p><label><strong>%1$s</strong><br><input type="%2$s"%3$s name="mnu_shipping[%4$s]" value="%5$s" style="width:100%%"></label></p>',
            esc_html( $label ),
            esc_attr( $type ),
            $step, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed safe attributes.
            esc_attr( $key ),
            esc_attr( $shipping[ $key ] ?? '' )
        );
    }
}

function mnu_ship_save_product_metabox( int $post_id ): void {
    if ( ! isset( $_POST['mnu_ship_nonce'] ) ) {
        return;
    }

    $nonce = sanitize_text_field( wp_unslash( $_POST['mnu_ship_nonce'] ) );
    if ( ! wp_verify_nonce( $nonce, 'mnu_ship_product_save' ) ) {
        return;
    }
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }
    if ( wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
        return;
    }

    $data = isset( $_POST['mnu_shipping'] ) && is_array( $_POST['mnu_shipping'] )
        ? wp_unslash( $_POST['mnu_shipping'] )
        : array();

    mnu_ship_save_product_shipping( $post_id, $data );
}
add_action( 'save_post_product', 'mnu_ship_save_product_metabox' );
