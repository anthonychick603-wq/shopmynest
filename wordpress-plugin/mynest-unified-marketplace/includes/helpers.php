<?php

defined( 'ABSPATH' ) || exit;

function tnm_table( string $name ): string {
    global $wpdb;
    return $wpdb->prefix . 'tnm_' . $name;
}

function tnm_get_option( string $key, mixed $default = null ): mixed {
    $settings = get_option( 'tnm_settings', array() );
    return array_key_exists( $key, $settings ) ? $settings[ $key ] : $default;
}

function tnm_update_option( string $key, mixed $value ): void {
    $settings         = get_option( 'tnm_settings', array() );
    $settings[ $key ] = $value;
    update_option( 'tnm_settings', $settings, false );
}

function tnm_fee_percent(): float {
    return max( 0.0, min( 100.0, (float) tnm_get_option( 'fee_percent', 8 ) ) );
}

function tnm_fee_label(): string {
    return sanitize_text_field( (string) tnm_get_option( 'fee_label', 'Nest Service Fee' ) );
}

function tnm_is_seller( int $user_id = 0 ): bool {
    $user_id = $user_id ?: get_current_user_id();
    if ( ! $user_id ) {
        return false;
    }
    $user = get_userdata( $user_id );
    if ( ! $user ) {
        return false;
    }
    // Administrators and shop managers can use seller tools, but they must not
    // be treated as vendors for ownership, redirects, fees, or public profiles.
    if ( tnm_is_admin_or_manager( $user_id ) ) {
        return false;
    }
    $seller_roles = array(
        'tnm_seller',
        'mynest_seller',
        'seller',
        'vendor',
        'wcv_vendor',
        'dokan_vendor',
        'shop_vendor',
        'wc_product_vendors_vendor',
    );
    return user_can( $user_id, 'tnm_manage_store' ) || (bool) array_intersect( $seller_roles, (array) $user->roles );
}

function tnm_is_marketplace_user( int $user_id = 0 ): bool {
    return tnm_is_seller( $user_id ) || tnm_is_admin_or_manager( $user_id );
}

/**
 * Whether this user is a vendor who has not yet met the Stripe requirement for
 * listing products.
 *
 * `tnm_stripe_payouts_enabled` user meta (read through MNU_Connect) is the one
 * source of truth for "Stripe is connected enough to sell"; the mobile app gates
 * its new-listing screen on the same value via nest-connect/v1/status. Admins and
 * shop managers are not vendors (tnm_is_seller() already excludes them) and list
 * on a seller's behalf, so they are never blocked.
 */
function tnm_seller_listing_blocked( int $user_id = 0 ): bool {
    $user_id = $user_id ?: get_current_user_id();
    if ( ! tnm_is_seller( $user_id ) ) {
        return false;
    }
    return class_exists( 'MNU_Connect' ) && ! MNU_Connect::seller_can_sell( $user_id );
}

function tnm_seller_listing_blocked_message(): string {
    return 'Connect your Stripe account before you can list products for sale. Open your seller dashboard to finish Stripe onboarding.';
}

function tnm_is_admin_or_manager( int $user_id = 0 ): bool {
    $user_id = $user_id ?: get_current_user_id();
    return user_can( $user_id, 'manage_woocommerce' ) || user_can( $user_id, 'manage_options' );
}

function tnm_current_user_can_manage_seller( int $seller_id ): bool {
    return get_current_user_id() === $seller_id || tnm_is_admin_or_manager();
}

function tnm_get_product_seller_id( int|WC_Product $product ): int {
    $product = is_a( $product, 'WC_Product' ) ? $product : wc_get_product( $product );
    if ( ! $product ) {
        return 0;
    }
    foreach ( array( '_tnm_seller_id', '_mynest_seller_id', '_wcv_vendor_id', '_dokan_vendor_id' ) as $meta_key ) {
        $seller_id = (int) $product->get_meta( $meta_key, true );
        if ( $seller_id > 0 ) {
            return $seller_id;
        }
    }
    return (int) get_post_field( 'post_author', $product->get_id() );
}

/**
 * Return every product owned by a seller, including migrated products whose
 * WordPress author was left as an administrator but whose seller ID is stored
 * in marketplace metadata.
 *
 * @param array<int, string> $statuses Allowed product statuses.
 * @return array<int, int>
 */
function tnm_seller_product_ids( int $seller_id, array $statuses = array( 'publish', 'pending', 'draft', 'private' ) ): array {
    if ( $seller_id <= 0 ) {
        return array();
    }

    $base_args = array(
        'post_type'              => 'product',
        'post_status'            => $statuses,
        'posts_per_page'         => -1,
        'fields'                 => 'ids',
        'no_found_rows'          => true,
        'orderby'                => 'date',
        'order'                  => 'DESC',
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
    );

    $ids = get_posts( array_merge( $base_args, array( 'author' => $seller_id ) ) );

    foreach ( array( '_tnm_seller_id', '_mynest_seller_id', '_wcv_vendor_id', '_dokan_vendor_id' ) as $meta_key ) {
        $ids = array_merge(
            $ids,
            get_posts(
                array_merge(
                    $base_args,
                    array(
                        'meta_key'   => $meta_key,
                        'meta_value' => $seller_id,
                    )
                )
            )
        );
    }

    $ids = array_values( array_unique( array_map( 'absint', $ids ) ) );
    $ids = array_values(
        array_filter(
            $ids,
            static function ( int $product_id ) use ( $seller_id, $statuses ): bool {
                $product = wc_get_product( $product_id );
                return $product
                    && in_array( $product->get_status(), $statuses, true )
                    && tnm_get_product_seller_id( $product ) === $seller_id;
            }
        )
    );

    usort(
        $ids,
        static fn( int $left, int $right ): int => (int) get_post_time( 'U', true, $right ) <=> (int) get_post_time( 'U', true, $left )
    );

    return $ids;
}

function tnm_get_order_item_seller_id( WC_Order_Item_Product $item ): int {
    foreach ( array( '_tnm_seller_id', '_mynest_seller_id' ) as $meta_key ) {
        $seller_id = (int) $item->get_meta( $meta_key, true );
        if ( $seller_id > 0 ) {
            return $seller_id;
        }
    }
    $product = $item->get_product();
    return $product ? tnm_get_product_seller_id( $product ) : 0;
}

function tnm_money( float|string $amount, string $currency = '' ): string {
    if ( function_exists( 'wc_price' ) ) {
        return wp_strip_all_tags( wc_price( (float) $amount, array( 'currency' => $currency ?: get_woocommerce_currency() ) ) );
    }
    return number_format_i18n( (float) $amount, 2 );
}

function tnm_json_error( string $code, string $message, int $status = 400, array $data = array() ): WP_Error {
    return new WP_Error( $code, $message, array_merge( array( 'status' => $status ), $data ) );
}

function tnm_base64url_encode( string $data ): string {
    return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
}

function tnm_base64url_decode( string $data ): string|false {
    $padding = strlen( $data ) % 4;
    if ( $padding ) {
        $data .= str_repeat( '=', 4 - $padding );
    }
    return base64_decode( strtr( $data, '-_', '+/' ), true );
}

function tnm_request_bearer_token(): string {
    $header = '';
    if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
        $header = sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) );
    } elseif ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
        $header = sanitize_text_field( wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) );
    }
    return preg_match( '/^Bearer\s+(\S+)$/i', $header, $matches ) ? $matches[1] : '';
}

function tnm_array_get( array $array, string $key, mixed $default = null ): mixed {
    return array_key_exists( $key, $array ) ? $array[ $key ] : $default;
}

function tnm_page_url( string $key, string $fallback = '' ): string {
    $page_id = (int) get_option( 'tnm_page_' . $key, 0 );
    return $page_id ? get_permalink( $page_id ) : $fallback;
}

function tnm_upload_image_from_request( string $field = 'image' ): int|WP_Error {
    if ( empty( $_FILES[ $field ]['name'] ) ) {
        return 0;
    }
    if ( ! function_exists( 'media_handle_upload' ) ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
    }
    return media_handle_upload( $field, 0 );
}

function tnm_seller_display_name( int $seller_id ): string {
    foreach ( array( 'tnm_store_name', 'mynest_store_name', '_mynest_shop_name', 'billing_company' ) as $meta_key ) {
        $store_name = get_user_meta( $seller_id, $meta_key, true );
        if ( $store_name ) {
            return sanitize_text_field( html_entity_decode( (string) $store_name, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
        }
    }
    // v3.7.69 — admin/shop-manager accounts are used as the marketplace
    // house-seller for admin-owned test products. Show "ShopMyNest" instead
    // of the WP nickname ("Admin") or display_name ("shopmynestadmin") so
    // the storefront never leaks internal wording. Set tnm_store_name on
    // the user to override.
    if ( tnm_is_admin_or_manager( $seller_id ) ) {
        return (string) apply_filters( 'tnm_admin_store_label', get_bloginfo( 'name' ) ?: 'ShopMyNest', $seller_id );
    }
    // v3.7.67 — fall back to nickname before display_name so sellers whose
    // WP display_name is their real name still surface as their store name
    // (nickname is the WP-native "public label" that predates our meta keys).
    $nickname = (string) get_user_meta( $seller_id, 'nickname', true );
    if ( $nickname ) {
        return sanitize_text_field( html_entity_decode( $nickname, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
    }
    $user = get_userdata( $seller_id );
    return $user ? $user->display_name : __( 'Seller', 'mynest-unified-marketplace' );
}

function tnm_order_contains_seller( WC_Order $order, int $seller_id ): bool {
    foreach ( $order->get_items() as $item ) {
        if ( $item instanceof WC_Order_Item_Product && tnm_get_order_item_seller_id( $item ) === $seller_id ) {
            return true;
        }
    }
    return false;
}

function tnm_get_seller_order_items( WC_Order $order, int $seller_id ): array {
    $items = array();
    foreach ( $order->get_items() as $item_id => $item ) {
        if ( $item instanceof WC_Order_Item_Product && tnm_get_order_item_seller_id( $item ) === $seller_id ) {
            $items[ $item_id ] = $item;
        }
    }
    return $items;
}

function tnm_notify( int $user_id, int $actor_id, string $type, string $title, string $message = '', int $object_id = 0, string $object_type = '', string $url = '' ): int {
    global $wpdb;
    $wpdb->insert(
        tnm_table( 'notifications' ),
        array(
            'user_id'     => $user_id,
            'actor_id'    => $actor_id,
            'type'        => sanitize_key( $type ),
            'object_id'   => $object_id,
            'object_type' => sanitize_key( $object_type ),
            'title'       => sanitize_text_field( $title ),
            'message'     => sanitize_textarea_field( $message ),
            'url'         => esc_url_raw( $url ),
            'is_read'     => 0,
            'created_at'  => current_time( 'mysql', true ),
        ),
        array( '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s' )
    );
    $notification_id = (int) $wpdb->insert_id;

    // v3.7.100 — every in-app notification also fans out to registered
    // Expo push tokens for that user. Dispatch on shutdown so the current
    // request (checkout finalisation, message insert, etc.) is not blocked
    // by an 8s Expo HTTP call. MNU_Ops::notify_user() short-circuits if
    // there are no tokens on file, so this is a no-op for signed-out or
    // web-only users. The 'tnm_notify_push_payload' filter is public so
    // it can be muted per-type by other code without touching this file.
    //
    // The three order-lifecycle types are already pushed directly by
    // MNU_Ops with their own per-order dedup markers, so let them keep
    // owning that path instead of double-firing here.
    $direct_push_types = array( 'new_order', 'order_update', 'order_shipped' );
    if ( $notification_id > 0 && $user_id > 0 && ! in_array( sanitize_key( $type ), $direct_push_types, true ) ) {
        $payload = array(
            'user_id'         => $user_id,
            'title'           => (string) $title,
            'body'            => (string) $message,
            'data'            => array(
                'type'            => sanitize_key( $type ),
                'object_id'       => (int) $object_id,
                'object_type'     => sanitize_key( $object_type ),
                'actor_id'        => (int) $actor_id,
                'notification_id' => $notification_id,
            ),
        );
        add_action( 'shutdown', function() use ( $payload ) {
            if ( ! class_exists( 'MNU_Ops' ) || ! method_exists( 'MNU_Ops', 'notify_user' ) ) {
                return;
            }
            $payload = apply_filters( 'tnm_notify_push_payload', $payload );
            if ( ! is_array( $payload ) || empty( $payload['user_id'] ) ) {
                return;
            }
            MNU_Ops::notify_user(
                (int) $payload['user_id'],
                (string) ( $payload['title'] ?? '' ),
                (string) ( $payload['body'] ?? '' ),
                is_array( $payload['data'] ?? null ) ? $payload['data'] : array()
            );
        }, 100 );
    }

    return $notification_id;
}

function tnm_user_can_use_attachment( int $user_id, int $attachment_id ): bool {
    if ( ! $user_id || ! $attachment_id || ! wp_attachment_is_image( $attachment_id ) ) {
        return false;
    }
    if ( tnm_is_admin_or_manager( $user_id ) ) {
        return true;
    }
    $attachment = get_post( $attachment_id );
    return $attachment instanceof WP_Post && (int) $attachment->post_author === $user_id;
}

function tnm_user_avatar_url( int $user_id, int $size = 256 ): string {
    $photo_id = (int) get_user_meta( $user_id, 'thenest_profile_photo_id', true );
    $custom   = $photo_id ? wp_get_attachment_image_url( $photo_id, 'medium' ) : false;
    if ( ! $custom ) {
        $custom = esc_url_raw( (string) get_user_meta( $user_id, 'thenest_profile_photo_url', true ) );
    }
    return $custom ?: get_avatar_url( $user_id, array( 'size' => $size ) );
}

/**
 * One of not_submitted|pending|approved|rejected. The app keys its seller
 * onboarding screens off these exact four values.
 */
function tnm_seller_application_status( int $user_id ): string {
    if ( tnm_is_seller( $user_id ) ) {
        return 'approved';
    }
    $ids = get_posts(
        array(
            'post_type'        => 'tnm_application',
            'post_status'      => array( 'pending', 'publish', 'draft' ),
            'author'           => $user_id,
            'posts_per_page'   => 1,
            'orderby'          => 'ID',
            'order'            => 'DESC',
            'fields'           => 'ids',
            'suppress_filters' => false,
        )
    );
    if ( ! $ids ) {
        return 'not_submitted';
    }
    $status = sanitize_key( (string) get_post_meta( (int) $ids[0], '_tnm_status', true ) );
    return in_array( $status, array( 'pending', 'approved', 'rejected' ), true ) ? $status : 'pending';
}

function tnm_rest_user_data( WP_User $user ): array {
    $is_seller = tnm_is_seller( $user->ID );
    $is_admin  = tnm_is_admin_or_manager( $user->ID );
    return array(
        'id'           => $user->ID,
        'username'     => $user->user_login,
        'email'        => $user->user_email,
        'display_name' => $user->display_name,
        'avatar'       => tnm_user_avatar_url( $user->ID, 256 ),
        'roles'        => array_values( $user->roles ),
        'is_seller'    => $is_seller,
        // v3.7.115 — explicit admin flag so the mobile app can surface the
        // admin drawer independently of is_approved_seller (which is also
        // true for regular sellers).
        'is_admin'     => $is_admin,
        // Mirrors the seller() REST permission gate, so the app only shows
        // seller-only UI to accounts the seller routes will actually accept.
        'is_approved_seller' => $is_seller || $is_admin,
        'store_name'   => tnm_seller_display_name( $user->ID ),
        // null, not 0, for non-sellers: the app treats any present value as a
        // real seller id and would fetch store "0".
        'seller_id'    => $is_seller ? $user->ID : null,
        'seller_status' => tnm_seller_application_status( $user->ID ),
        // Convenience counters so mobile / web can badge the Messages icon
        // without a separate request.
        'unread_messages' => class_exists( 'TNM_Social' ) ? TNM_Social::unread_message_count( $user->ID ) : 0,
        'unread_threads'  => class_exists( 'TNM_Social' ) ? TNM_Social::unread_thread_count( $user->ID ) : 0,
    );
}
