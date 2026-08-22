<?php
/**
 * Customization request REST API and order lifecycle handling.
 *
 * @package MyNest_Unified_Marketplace
 */

defined( 'ABSPATH' ) || exit;

final class MNU_Custom_Requests {
    private const NS = 'the-nest/v1';

    /**
     * Register REST and WooCommerce hooks.
     */
    public static function init(): void {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
        add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'mark_paid_on_order' ) );
        add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'mark_completed_on_order' ) );
    }

    /**
     * Register custom request REST endpoints.
     */
    public static function register_routes(): void {
        register_rest_route(
            self::NS,
            '/custom-requests',
            array(
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array( __CLASS__, 'create_request' ),
                    'permission_callback' => array( __CLASS__, 'logged_in' ),
                ),
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( __CLASS__, 'list_requests' ),
                    'permission_callback' => array( __CLASS__, 'logged_in' ),
                ),
            )
        );
        register_rest_route(
            self::NS,
            '/custom-requests/(?P<id>\d+)',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( __CLASS__, 'get_request_detail' ),
                'permission_callback' => array( __CLASS__, 'logged_in' ),
            )
        );
        register_rest_route(
            self::NS,
            '/custom-requests/(?P<id>\d+)/messages',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( __CLASS__, 'post_message' ),
                'permission_callback' => array( __CLASS__, 'logged_in' ),
            )
        );
        register_rest_route(
            self::NS,
            '/custom-requests/(?P<id>\d+)/quote',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( __CLASS__, 'quote_request' ),
                'permission_callback' => array( __CLASS__, 'logged_in' ),
            )
        );
        register_rest_route(
            self::NS,
            '/custom-requests/(?P<id>\d+)/accept',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( __CLASS__, 'accept_request' ),
                'permission_callback' => array( __CLASS__, 'logged_in' ),
            )
        );
        register_rest_route(
            self::NS,
            '/custom-requests/(?P<id>\d+)/decline',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( __CLASS__, 'decline_request' ),
                'permission_callback' => array( __CLASS__, 'logged_in' ),
            )
        );
        register_rest_route(
            self::NS,
            '/custom-requests/(?P<id>\d+)/withdraw',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( __CLASS__, 'withdraw_request' ),
                'permission_callback' => array( __CLASS__, 'logged_in' ),
            )
        );
    }

    /**
     * Require an authenticated REST user.
     */
    public static function logged_in(): bool|WP_Error {
        if ( ! is_user_logged_in() ) {
            return tnm_json_error( 'rest_not_logged_in', 'You must be signed in to manage customization requests.', 401 );
        }
        return true;
    }

    /**
     * Create a buyer customization request.
     */
    public static function create_request( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        global $wpdb;

        $params      = self::params( $request );
        $buyer_id    = get_current_user_id();
        $product_id  = self::integer_param( $params['product_id'] ?? null, 1, PHP_INT_MAX, 'invalid_product', 'A valid product is required.' );
        $quantity    = self::integer_param( $params['quantity'] ?? 1, 1, 65535, 'invalid_quantity', 'Quantity must be between 1 and 65535.' );
        $budget      = self::integer_param( $params['budget_cents'] ?? 0, 0, 4294967295, 'invalid_budget', 'Budget must be a non-negative whole number of cents.' );
        $title       = sanitize_text_field( self::scalar_string( $params['title'] ?? '' ) );
        $description = wp_kses_post( self::scalar_string( $params['description'] ?? '' ) );

        if ( is_wp_error( $product_id ) ) {
            return $product_id;
        }
        if ( is_wp_error( $quantity ) ) {
            return $quantity;
        }
        if ( is_wp_error( $budget ) ) {
            return $budget;
        }
        if ( '' === $title || strlen( $title ) > 200 ) {
            return tnm_json_error( 'invalid_title', 'A title of 200 characters or fewer is required.', 422 );
        }
        if ( '' === trim( wp_strip_all_tags( $description ) ) ) {
            return tnm_json_error( 'invalid_description', 'A description is required.', 422 );
        }

        $photo_ids = self::attachment_ids( $params['reference_photo_ids'] ?? array(), 3, 'reference_photo_ids' );
        if ( is_wp_error( $photo_ids ) ) {
            return $photo_ids;
        }

        $product = wc_get_product( $product_id );
        if ( ! $product || 'product' !== get_post_type( $product_id ) ) {
            return tnm_json_error( 'product_not_found', 'Product not found.', 404 );
        }
        if ( 'yes' !== $product->get_meta( '_mnu_customizable', true ) ) {
            return tnm_json_error( 'product_not_customizable', 'This product does not accept customization requests.', 422 );
        }

        $seller_id = tnm_get_product_seller_id( $product );
        if ( $seller_id <= 0 ) {
            return tnm_json_error( 'seller_not_found', 'This product does not have a seller.', 422 );
        }

        $now = self::now();
        $saved = $wpdb->insert(
            tnm_table( 'custom_requests' ),
            array(
                'buyer_id'            => $buyer_id,
                'seller_id'           => $seller_id,
                'product_id'          => $product_id,
                'title'               => $title,
                'description'         => $description,
                'budget_cents'        => $budget,
                'quantity'            => $quantity,
                'reference_photo_ids' => wp_json_encode( $photo_ids ),
                'status'              => 'open',
                'last_activity_at'    => $now,
                'created_at'          => $now,
                'updated_at'          => $now,
            ),
            array( '%d', '%d', '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
        );
        if ( false === $saved ) {
            return tnm_json_error( 'custom_request_create_failed', 'Could not create the customization request.', 500 );
        }

        $created = self::get_row( (int) $wpdb->insert_id );
        if ( ! $created ) {
            return tnm_json_error( 'custom_request_create_failed', 'Could not load the customization request.', 500 );
        }
        return rest_ensure_response( self::hydrate_request( $created, $buyer_id ) );
    }

    /**
     * List requests belonging to the current buyer or seller.
     */
    public static function list_requests( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        global $wpdb;

        $user_id  = get_current_user_id();
        $role     = sanitize_key( (string) $request->get_param( 'role' ) );
        $role     = in_array( $role, array( 'buyer', 'seller' ), true ) ? $role : 'buyer';
        $page     = max( 1, absint( $request->get_param( 'page' ) ?: 1 ) );
        $per_page = min( 50, max( 1, absint( $request->get_param( 'per_page' ) ?: 20 ) ) );

        if ( 'seller' === $role && ! self::seller_user( $user_id ) ) {
            return tnm_json_error( 'seller_permission_denied', 'Only sellers can view seller customization requests.', 403 );
        }

        $statuses = array();
        $raw_statuses = self::scalar_string( $request->get_param( 'status' ) );
        if ( '' !== $raw_statuses ) {
            foreach ( explode( ',', $raw_statuses ) as $status ) {
                $status = sanitize_key( $status );
                if ( in_array( $status, self::statuses(), true ) ) {
                    $statuses[] = $status;
                }
            }
            $statuses = array_values( array_unique( $statuses ) );
            if ( ! $statuses ) {
                return tnm_json_error( 'invalid_status', 'The status filter contains no valid request statuses.', 422 );
            }
        }

        $table  = tnm_table( 'custom_requests' );
        $column = 'seller' === $role ? 'seller_id' : 'buyer_id';
        $sql    = "SELECT SQL_CALC_FOUND_ROWS * FROM {$table} WHERE {$column} = %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $args   = array( $user_id );
        if ( $statuses ) {
            $sql .= ' AND status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')';
            $args = array_merge( $args, $statuses );
        }
        $sql    .= ' ORDER BY last_activity_at DESC, id DESC LIMIT %d OFFSET %d';
        $args[] = $per_page;
        $args[] = ( $page - 1 ) * $per_page;

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ) );
        $total = (int) $wpdb->get_var( 'SELECT FOUND_ROWS()' );
        $items = array();
        foreach ( (array) $rows as $row ) {
            $items[] = self::hydrate_request( $row, $user_id );
        }

        return rest_ensure_response(
            array(
                'items'       => $items,
                'page'        => $page,
                'per_page'    => $per_page,
                'total'       => $total,
                'total_pages' => (int) ceil( $total / $per_page ),
            )
        );
    }

    /**
     * Return a request with its message thread.
     */
    public static function get_request_detail( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        global $wpdb;

        $user_id = get_current_user_id();
        $row = self::authorized_request( absint( $request['id'] ), $user_id );
        if ( is_wp_error( $row ) ) {
            return $row;
        }

        $table = tnm_table( 'custom_request_messages' );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $messages = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE request_id = %d ORDER BY created_at ASC, id ASC", $row->id ) );
        $data = self::hydrate_request( $row, $user_id );
        $data['messages'] = array_map( static fn( $message ) => self::hydrate_message( $message ), (array) $messages );
        return rest_ensure_response( $data );
    }

    /**
     * Add a buyer or seller message to an active request.
     */
    public static function post_message( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $user_id = get_current_user_id();
        $row = self::authorized_request( absint( $request['id'] ), $user_id );
        if ( is_wp_error( $row ) ) {
            return $row;
        }
        if ( self::terminal_status( (string) $row->status ) ) {
            return tnm_json_error( 'custom_request_closed', 'Messages cannot be added to a closed customization request.', 422 );
        }

        $params = self::params( $request );
        $body = sanitize_textarea_field( self::scalar_string( $params['body'] ?? '' ) );
        $photo_ids = self::attachment_ids( $params['photo_attachments'] ?? array(), 3, 'photo_attachments' );
        if ( is_wp_error( $photo_ids ) ) {
            return $photo_ids;
        }
        if ( '' === trim( $body ) && ! $photo_ids ) {
            return tnm_json_error( 'empty_message', 'A message body or photo attachment is required.', 422 );
        }

        $message = self::insert_message( (int) $row->id, $user_id, 'message', $body, $photo_ids );
        if ( is_wp_error( $message ) ) {
            return $message;
        }
        self::touch_request( (int) $row->id );
        return rest_ensure_response( self::hydrate_message( $message ) );
    }

    /**
     * Store or replace the seller's quote.
     */
    public static function quote_request( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        global $wpdb;

        $user_id = get_current_user_id();
        $row = self::authorized_request( absint( $request['id'] ), $user_id );
        if ( is_wp_error( $row ) ) {
            return $row;
        }
        if ( $user_id !== (int) $row->seller_id || ! self::seller_user( $user_id ) ) {
            return tnm_json_error( 'seller_permission_denied', 'Only the assigned seller can quote this request.', 403 );
        }
        if ( ! in_array( (string) $row->status, array( 'open', 'quoted' ), true ) ) {
            return tnm_json_error( 'invalid_request_status', 'Only open or quoted requests can be quoted.', 422 );
        }

        $params = self::params( $request );
        $price = self::integer_param( $params['price_cents'] ?? null, 100, 4294967295, 'invalid_quote_price', 'Quote price must be at least 100 cents.' );
        $lead_days = self::integer_param( $params['lead_days'] ?? null, 0, 65535, 'invalid_lead_days', 'Lead days must be a non-negative whole number.' );
        $note = sanitize_textarea_field( self::scalar_string( $params['note'] ?? '' ) );
        if ( is_wp_error( $price ) ) {
            return $price;
        }
        if ( is_wp_error( $lead_days ) ) {
            return $lead_days;
        }

        $now = self::now();
        $updated = $wpdb->update(
            tnm_table( 'custom_requests' ),
            array(
                'status'              => 'quoted',
                'quoted_price_cents'  => $price,
                'quoted_lead_days'    => $lead_days,
                'quoted_at'           => $now,
                'quote_note'          => $note,
                'last_activity_at'    => $now,
                'updated_at'          => $now,
            ),
            array( 'id' => $row->id ),
            array( '%s', '%d', '%d', '%s', '%s', '%s', '%s' ),
            array( '%d' )
        );
        if ( false === $updated ) {
            return tnm_json_error( 'quote_failed', 'Could not save the quote.', 500 );
        }

        $body = sprintf( 'Quoted %s with a %d-day lead time.', self::format_cents( $price ), $lead_days );
        if ( '' !== $note ) {
            $body .= ' ' . $note;
        }
        $message = self::insert_message( (int) $row->id, $user_id, 'system_quote', $body );
        if ( is_wp_error( $message ) ) {
            return $message;
        }
        $updated_row = self::get_row( (int) $row->id );
        return rest_ensure_response( self::hydrate_request( $updated_row, $user_id ) );
    }

    /**
     * Accept a quote and create the hidden one-off WooCommerce product.
     */
    public static function accept_request( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        global $wpdb;

        $user_id = get_current_user_id();
        $row = self::authorized_request( absint( $request['id'] ), $user_id );
        if ( is_wp_error( $row ) ) {
            return $row;
        }
        if ( $user_id !== (int) $row->buyer_id ) {
            return tnm_json_error( 'buyer_permission_denied', 'Only the buyer can accept this quote.', 403 );
        }
        if ( 'quoted' !== (string) $row->status ) {
            return tnm_json_error( 'invalid_request_status', 'Only quoted requests can be accepted.', 422 );
        }
        if ( ! class_exists( 'WC_Product_Simple' ) ) {
            return tnm_json_error( 'woocommerce_unavailable', 'WooCommerce is required to accept this quote.', 503 );
        }

        $product = new WC_Product_Simple();
        $product->set_name( sprintf( 'Custom: %s (Request #%d)', $row->title, $row->id ) );
        $product->set_status( 'private' );
        $product->set_catalog_visibility( 'hidden' );
        $price = number_format( ( (int) $row->quoted_price_cents ) / 100, 2, '.', '' );
        $product->set_regular_price( $price );
        $product->set_price( $price );
        $product->set_manage_stock( true );
        $product->set_stock_quantity( max( 1, (int) $row->quantity ) );
        $product->set_stock_status( 'instock' );
        $product->set_sold_individually( false );
        $buyer = get_userdata( (int) $row->buyer_id );
        $product->set_short_description( 'Custom order for ' . ( $buyer ? sanitize_text_field( $buyer->display_name ) : __( 'buyer', 'mynest-unified-marketplace' ) ) );
        $product->set_description( wp_kses_post( (string) $row->description ) );
        // `_tnm_seller_id` is the primary seller-attribution key used by
        // marketplace checkout, shipping, payouts, and analytics. Keep its
        // legacy mirror in sync, matching normal seller product creation.
        $product->update_meta_data( '_tnm_seller_id', (int) $row->seller_id );
        $product->update_meta_data( '_mynest_seller_id', (int) $row->seller_id );
        $product->update_meta_data( '_mnu_custom_request_id', (int) $row->id );
        $private_product_id = $product->save();
        if ( $private_product_id <= 0 ) {
            return tnm_json_error( 'private_product_create_failed', 'Could not create the custom product.', 500 );
        }

        // Match normal seller products by making the seller the post author too.
        wp_update_post( array( 'ID' => $private_product_id, 'post_author' => (int) $row->seller_id ) );
        $source_thumb_id = get_post_thumbnail_id( (int) $row->product_id );
        if ( $source_thumb_id ) {
            set_post_thumbnail( $private_product_id, $source_thumb_id );
        }

        $now = self::now();
        $updated = $wpdb->update(
            tnm_table( 'custom_requests' ),
            array(
                'status'             => 'accepted',
                'private_product_id' => $private_product_id,
                'last_activity_at'   => $now,
                'updated_at'         => $now,
            ),
            array( 'id' => $row->id ),
            array( '%s', '%d', '%s', '%s' ),
            array( '%d' )
        );
        if ( false === $updated ) {
            return tnm_json_error( 'custom_request_accept_failed', 'The custom product was created but the request could not be updated.', 500 );
        }

        $message = self::insert_message( (int) $row->id, $user_id, 'system_accept', 'Buyer accepted the quote.' );
        if ( is_wp_error( $message ) ) {
            return $message;
        }

        $updated_row = self::get_row( (int) $row->id );
        $slug = (string) get_post_field( 'post_name', $private_product_id );
        return rest_ensure_response(
            array(
                'request'              => self::hydrate_request( $updated_row, $user_id ),
                'private_product_id'   => $private_product_id,
                'private_product_slug' => $slug,
                'add_to_cart_url'      => add_query_arg( 'add-to-cart', $private_product_id, home_url( '/' ) ),
            )
        );
    }

    /**
     * Decline a request before payment.
     */
    public static function decline_request( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        global $wpdb;

        $user_id = get_current_user_id();
        $row = self::authorized_request( absint( $request['id'] ), $user_id );
        if ( is_wp_error( $row ) ) {
            return $row;
        }
        if ( ! in_array( (string) $row->status, array( 'open', 'quoted' ), true ) ) {
            return tnm_json_error( 'invalid_request_status', 'Only open or quoted requests can be declined.', 422 );
        }

        $params = self::params( $request );
        $reason = sanitize_textarea_field( self::scalar_string( $params['reason'] ?? '' ) );
        if ( strlen( $reason ) > 500 ) {
            return tnm_json_error( 'invalid_decline_reason', 'The decline reason must be 500 characters or fewer.', 422 );
        }
        $now = self::now();
        $updated = $wpdb->update(
            tnm_table( 'custom_requests' ),
            array(
                'status'           => 'declined',
                'decline_reason'   => $reason,
                'last_activity_at' => $now,
                'updated_at'       => $now,
            ),
            array( 'id' => $row->id ),
            array( '%s', '%s', '%s', '%s' ),
            array( '%d' )
        );
        if ( false === $updated ) {
            return tnm_json_error( 'decline_failed', 'Could not decline the customization request.', 500 );
        }
        $body = 'Customization request declined.' . ( '' !== $reason ? ' ' . $reason : '' );
        $message = self::insert_message( (int) $row->id, $user_id, 'system_decline', $body );
        if ( is_wp_error( $message ) ) {
            return $message;
        }
        return rest_ensure_response( self::hydrate_request( self::get_row( (int) $row->id ), $user_id ) );
    }

    /**
     * Allow the buyer to withdraw an unaccepted request.
     */
    public static function withdraw_request( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        global $wpdb;

        $user_id = get_current_user_id();
        $row = self::authorized_request( absint( $request['id'] ), $user_id );
        if ( is_wp_error( $row ) ) {
            return $row;
        }
        if ( $user_id !== (int) $row->buyer_id ) {
            return tnm_json_error( 'buyer_permission_denied', 'Only the buyer can withdraw this request.', 403 );
        }
        if ( ! in_array( (string) $row->status, array( 'open', 'quoted' ), true ) ) {
            return tnm_json_error( 'invalid_request_status', 'Only open or quoted requests can be withdrawn.', 422 );
        }

        $now = self::now();
        $updated = $wpdb->update(
            tnm_table( 'custom_requests' ),
            array(
                'status'           => 'withdrawn',
                'last_activity_at' => $now,
                'updated_at'       => $now,
            ),
            array( 'id' => $row->id ),
            array( '%s', '%s', '%s' ),
            array( '%d' )
        );
        if ( false === $updated ) {
            return tnm_json_error( 'withdraw_failed', 'Could not withdraw the customization request.', 500 );
        }
        $message = self::insert_message( (int) $row->id, $user_id, 'system_withdraw', 'Buyer withdrew the customization request.' );
        if ( is_wp_error( $message ) ) {
            return $message;
        }
        return rest_ensure_response( self::hydrate_request( self::get_row( (int) $row->id ), $user_id ) );
    }

    /**
     * Mark accepted custom requests paid when their order enters processing.
     *
     * @param int|WC_Order $order_id Order ID supplied by WooCommerce.
     */
    public static function mark_paid_on_order( $order_id ): void {
        self::transition_order_requests( $order_id, 'accepted', 'paid', 'system_paid', 'Payment received for custom request.' );
    }

    /**
     * Mark paid custom requests completed when the containing order completes.
     *
     * @param int|WC_Order $order_id Order ID supplied by WooCommerce.
     */
    public static function mark_completed_on_order( $order_id ): void {
        self::transition_order_requests( $order_id, 'paid', 'completed', 'system_completed', 'Custom order completed.' );
    }

    /**
     * Transition custom request(s) represented in an order's line items.
     *
     * @param int|WC_Order $order_id Order ID or instance.
     */
    private static function transition_order_requests( $order_id, string $from_status, string $to_status, string $message_kind, string $message_body ): void {
        global $wpdb;

        $order = $order_id instanceof WC_Order ? $order_id : wc_get_order( absint( $order_id ) );
        if ( ! $order ) {
            return;
        }

        foreach ( $order->get_items( 'line_item' ) as $item ) {
            if ( ! $item instanceof WC_Order_Item_Product ) {
                continue;
            }
            $product = $item->get_product();
            if ( ! $product ) {
                $product = wc_get_product( $item->get_product_id() );
            }
            if ( ! $product ) {
                continue;
            }
            $request_id = absint( $product->get_meta( '_mnu_custom_request_id', true ) );
            if ( ! $request_id ) {
                continue;
            }

            $row = self::get_row( $request_id );
            if ( ! $row || $from_status !== (string) $row->status ) {
                continue;
            }
            $now = self::now();
            $updated = $wpdb->update(
                tnm_table( 'custom_requests' ),
                array(
                    'status'           => $to_status,
                    'order_id'         => $order->get_id(),
                    'last_activity_at' => $now,
                    'updated_at'       => $now,
                ),
                array( 'id' => $request_id, 'status' => $from_status ),
                array( '%s', '%d', '%s', '%s' ),
                array( '%d', '%s' )
            );
            if ( 1 !== $updated ) {
                continue;
            }
            self::insert_message( $request_id, 0, $message_kind, $message_body . ' Order #' . $order->get_order_number() . '.' );
        }
    }

    /**
     * Load a row and verify the caller participates in it.
     */
    private static function authorized_request( int $request_id, int $user_id ): mixed {
        $row = self::get_row( $request_id );
        if ( ! $row ) {
            return tnm_json_error( 'custom_request_not_found', 'Customization request not found.', 404 );
        }
        if ( $user_id !== (int) $row->buyer_id && $user_id !== (int) $row->seller_id ) {
            return tnm_json_error( 'custom_request_permission_denied', 'You cannot access this customization request.', 403 );
        }
        return $row;
    }

    /**
     * Fetch one customization request row.
     */
    private static function get_row( int $request_id ): ?object {
        global $wpdb;

        if ( $request_id <= 0 ) {
            return null;
        }
        $table = tnm_table( 'custom_requests' );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $request_id ) );
        return is_object( $row ) ? $row : null;
    }

    /**
     * Create a request message and return the stored row.
     *
     * @param int[] $photo_ids Attachment IDs.
     */
    private static function insert_message( int $request_id, int $sender_id, string $kind, string $body, array $photo_ids = array() ): mixed {
        global $wpdb;

        $allowed_kinds = array( 'message', 'system_quote', 'system_accept', 'system_decline', 'system_withdraw', 'system_paid', 'system_completed' );
        if ( ! in_array( $kind, $allowed_kinds, true ) ) {
            return tnm_json_error( 'invalid_message_kind', 'Invalid customization message type.', 500 );
        }
        $saved = $wpdb->insert(
            tnm_table( 'custom_request_messages' ),
            array(
                'request_id'        => $request_id,
                'sender_id'         => $sender_id,
                'kind'              => $kind,
                'body'              => sanitize_textarea_field( $body ),
                'photo_attachments' => wp_json_encode( array_values( $photo_ids ) ),
                'created_at'        => self::now(),
            ),
            array( '%d', '%d', '%s', '%s', '%s', '%s' )
        );
        if ( false === $saved ) {
            return tnm_json_error( 'message_create_failed', 'Could not save the customization request message.', 500 );
        }

        $table = tnm_table( 'custom_request_messages' );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $message = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $wpdb->insert_id ) );
        if ( ! is_object( $message ) ) {
            return tnm_json_error( 'message_create_failed', 'Could not load the customization request message.', 500 );
        }
        return $message;
    }

    /**
     * Update a request's activity timestamps.
     */
    private static function touch_request( int $request_id ): void {
        global $wpdb;

        $now = self::now();
        $wpdb->update(
            tnm_table( 'custom_requests' ),
            array( 'last_activity_at' => $now, 'updated_at' => $now ),
            array( 'id' => $request_id ),
            array( '%s', '%s' ),
            array( '%d' )
        );
    }

    /**
     * Turn a database row into the request response object.
     */
    private static function hydrate_request( object $row, int $caller_id = 0 ): array {
        $reference_photo_ids = self::decode_ids( $row->reference_photo_ids ?? '' );
        $private_product_id = absint( $row->private_product_id ?? 0 );
        $source_product_id = absint( $row->product_id ?? 0 );
        $source_product = $source_product_id ? wc_get_product( $source_product_id ) : false;

        return array(
            'id'                  => absint( $row->id ),
            'buyer_id'            => absint( $row->buyer_id ),
            'seller_id'           => absint( $row->seller_id ),
            'product_id'          => $source_product_id,
            'title'               => (string) $row->title,
            'description'         => (string) $row->description,
            'budget_cents'        => absint( $row->budget_cents ),
            'quantity'            => absint( $row->quantity ),
            'reference_photo_ids' => $reference_photo_ids,
            'reference_photo_urls' => self::attachment_urls( $reference_photo_ids ),
            'status'              => (string) $row->status,
            'quoted_price_cents'  => absint( $row->quoted_price_cents ),
            'quoted_lead_days'    => absint( $row->quoted_lead_days ),
            'quoted_at'           => self::iso_or_null( $row->quoted_at ?? null ),
            'quote_note'          => '' === (string) ( $row->quote_note ?? '' ) ? null : (string) $row->quote_note,
            'decline_reason'      => '' === (string) ( $row->decline_reason ?? '' ) ? null : (string) $row->decline_reason,
            'private_product_id'  => $private_product_id,
            'private_product_slug' => $private_product_id ? (string) get_post_field( 'post_name', $private_product_id ) : null,
            'order_id'            => absint( $row->order_id ),
            'buyer'               => self::brief_user( absint( $row->buyer_id ) ),
            'seller'              => self::brief_user( absint( $row->seller_id ), true ),
            'product'             => array(
                'id'        => $source_product_id,
                'name'      => $source_product ? $source_product->get_name() : '',
                'image_url' => $source_product ? ( wp_get_attachment_image_url( $source_product->get_image_id(), 'large' ) ?: wc_placeholder_img_src() ) : '',
                'permalink' => $source_product_id ? get_permalink( $source_product_id ) : '',
            ),
            // v1 tables have no per-user read cursor. Keep the documented
            // field stable for clients until read tracking is introduced.
            'unread_for_caller' => 0,
            'last_activity_at' => self::iso_or_null( $row->last_activity_at ?? null ),
            'created_at'       => self::iso_or_null( $row->created_at ?? null ),
            'updated_at'       => self::iso_or_null( $row->updated_at ?? null ),
        );
    }

    /**
     * Turn a message row into the response shape.
     */
    private static function hydrate_message( object $message ): array {
        $photo_ids = self::decode_ids( $message->photo_attachments ?? '' );
        return array(
            'id'                => absint( $message->id ),
            'request_id'        => absint( $message->request_id ),
            'sender_id'         => absint( $message->sender_id ),
            'sender'            => self::brief_user( absint( $message->sender_id ) ),
            'kind'              => (string) $message->kind,
            'body'              => (string) $message->body,
            'photo_attachments' => $photo_ids,
            'photo_urls'        => self::attachment_urls( $photo_ids ),
            'created_at'        => self::iso_or_null( $message->created_at ?? null ),
        );
    }

    /**
     * Minimal public user information for request payloads.
     */
    private static function brief_user( int $user_id, bool $seller = false ): array {
        $user = $user_id ? get_userdata( $user_id ) : false;
        $display_name = $seller ? tnm_seller_display_name( $user_id ) : ( $user ? $user->display_name : '' );
        $data = array(
            'id'           => $user_id,
            'display_name' => sanitize_text_field( (string) $display_name ),
            'avatar_url'   => $user_id ? tnm_user_avatar_url( $user_id, 256 ) : '',
        );
        if ( $seller ) {
            $data['shop_url'] = home_url( '/shop-profile/?seller=' . $user_id );
        }
        return $data;
    }

    /**
     * Validate and normalize an attachment ID list.
     *
     * @return int[]|WP_Error
     */
    private static function attachment_ids( mixed $value, int $max, string $field ): array|WP_Error {
        if ( ! is_array( $value ) ) {
            return tnm_json_error( 'invalid_' . sanitize_key( $field ), sprintf( '%s must be an array of media IDs.', $field ), 422 );
        }
        if ( count( $value ) > $max ) {
            return tnm_json_error( 'too_many_' . sanitize_key( $field ), sprintf( 'At most %d media IDs are allowed.', $max ), 422 );
        }
        $ids = array();
        foreach ( $value as $value_id ) {
            $id = self::integer_param( $value_id, 1, PHP_INT_MAX, 'invalid_' . sanitize_key( $field ), 'Media IDs must be positive whole numbers.' );
            if ( is_wp_error( $id ) ) {
                return $id;
            }
            if ( 'attachment' !== get_post_type( $id ) ) {
                return tnm_json_error( 'invalid_' . sanitize_key( $field ), 'Each media ID must reference an uploaded attachment.', 422 );
            }
            $ids[] = $id;
        }
        return array_values( array_unique( $ids ) );
    }

    /**
     * Validate an integer REST parameter without coercing malformed values.
     */
    private static function integer_param( mixed $value, int $minimum, int $maximum, string $code, string $message ): int|WP_Error {
        if ( is_int( $value ) ) {
            $integer = $value;
        } elseif ( is_string( $value ) && preg_match( '/^-?\d+$/', $value ) ) {
            $integer = (int) $value;
        } else {
            return tnm_json_error( $code, $message, 422 );
        }
        if ( $integer < $minimum || $integer > $maximum ) {
            return tnm_json_error( $code, $message, 422 );
        }
        return $integer;
    }

    /**
     * Coerce a scalar request value to a string without PHP array warnings.
     */
    private static function scalar_string( mixed $value ): string {
        return is_scalar( $value ) ? (string) $value : '';
    }

    /**
     * Get JSON parameters with form parameters as a fallback.
     */
    private static function params( WP_REST_Request $request ): array {
        $json = $request->get_json_params();
        return is_array( $json ) && $json ? $json : (array) $request->get_params();
    }

    /**
     * Decode a stored JSON attachment list defensively.
     *
     * @return int[]
     */
    private static function decode_ids( mixed $json ): array {
        $decoded = is_string( $json ) ? json_decode( $json, true ) : $json;
        if ( ! is_array( $decoded ) ) {
            return array();
        }
        return array_values( array_unique( array_filter( array_map( 'absint', $decoded ) ) ) );
    }

    /**
     * Resolve attachment URLs for an ID list.
     *
     * @param int[] $ids Attachment IDs.
     * @return string[]
     */
    private static function attachment_urls( array $ids ): array {
        $urls = array();
        foreach ( $ids as $id ) {
            $url = wp_get_attachment_url( $id );
            if ( $url ) {
                $urls[] = $url;
            }
        }
        return $urls;
    }

    /**
     * Seller capability/role check that remains compatible with all supported seller roles.
     */
    private static function seller_user( int $user_id ): bool {
        return user_can( $user_id, 'edit_products' ) || tnm_is_seller( $user_id );
    }

    /**
     * Known request statuses.
     *
     * @return string[]
     */
    private static function statuses(): array {
        return array( 'open', 'quoted', 'accepted', 'paid', 'completed', 'declined', 'withdrawn' );
    }

    /**
     * Terminal statuses cannot receive new state transitions or messages.
     */
    private static function terminal_status( string $status ): bool {
        return in_array( $status, array( 'declined', 'withdrawn', 'completed' ), true );
    }

    /**
     * Format integer cents for system message text.
     */
    private static function format_cents( int $cents ): string {
        return '$' . number_format( $cents / 100, 2, '.', '' );
    }

    /**
     * Current UTC database timestamp.
     */
    private static function now(): string {
        return gmdate( 'Y-m-d H:i:s' );
    }

    /**
     * Convert nullable database UTC timestamps into API timestamps.
     */
    private static function iso_or_null( mixed $value ): ?string {
        $value = self::scalar_string( $value );
        return '' === $value ? null : tnm_mysql_utc_to_iso8601( $value );
    }
}
