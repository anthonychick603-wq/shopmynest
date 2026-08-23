<?php
/**
 * ShopMyNest bulk product importer.
 *
 * Accepts a WooCommerce-format CSV (as produced by WooCommerce → Products →
 * Export) and imports each row as a product owned by the current seller.
 *
 * REST surface (all under the-nest/v1, seller permission):
 *   POST /seller/import/upload     multipart CSV → { job_id, total_rows, columns, preview, unrecognized_columns }
 *   POST /seller/import/{id}/run   start async processing (WP-Cron in background)
 *   GET  /seller/import/{id}       poll status → { status, processed, total, created, updated, errors }
 *
 * Storage: mnu_import_jobs (see class-mnu-install.php) + serialized rows in
 * post_content of a synthetic CPT-free option to avoid ballooning postmeta.
 *
 * @package MNU
 */

defined( 'ABSPATH' ) || exit;

final class MNU_Product_Import {

    public const NAMESPACE = 'the-nest/v1';
    public const CRON_HOOK = 'mnu_import_process_batch';
    public const BATCH_SIZE = 10; // rows per cron tick

    /**
     * Supported column headers (normalized to lowercase, trimmed).
     * Left-hand side = WooCommerce export column name(s) accepted.
     * Right-hand side = canonical internal key.
     */
    private const COLUMN_MAP = array(
        'id'                => 'id',
        'type'              => 'type',
        'sku'               => 'sku',
        'name'              => 'name',
        'published'         => 'published',
        'is featured?'      => 'featured',
        'visibility in catalog' => 'visibility',
        'short description' => 'short_description',
        'description'       => 'description',
        'tax status'        => 'tax_status',
        'tax class'         => 'tax_class',
        'in stock?'         => 'in_stock',
        'stock'             => 'stock',
        'weight (lbs)'      => 'weight_lbs',
        'length (in)'       => 'length_in',
        'width (in)'        => 'width_in',
        'height (in)'       => 'height_in',
        'sale price'        => 'sale_price',
        'regular price'     => 'regular_price',
        'categories'        => 'categories',
        'tags'              => 'tags',
        'shipping class'    => 'shipping_class',
        'images'            => 'images',
        'position'          => 'position',
        'brands'            => 'brands',
    );

    public static function init(): void {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
        add_action( self::CRON_HOOK, array( __CLASS__, 'process_batch' ), 10, 1 );
    }

    public static function register_routes(): void {
        register_rest_route(
            self::NAMESPACE,
            '/seller/import/upload',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( __CLASS__, 'rest_upload' ),
                'permission_callback' => array( 'TNM_REST', 'seller' ),
            )
        );
        register_rest_route(
            self::NAMESPACE,
            '/seller/import/(?P<id>\d+)/run',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( __CLASS__, 'rest_run' ),
                'permission_callback' => array( 'TNM_REST', 'seller' ),
            )
        );
        register_rest_route(
            self::NAMESPACE,
            '/seller/import/(?P<id>\d+)',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( __CLASS__, 'rest_status' ),
                'permission_callback' => array( 'TNM_REST', 'seller' ),
            )
        );
        register_rest_route(
            self::NAMESPACE,
            '/seller/import/template',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( __CLASS__, 'rest_template' ),
                'permission_callback' => array( 'TNM_REST', 'seller' ),
            )
        );
        register_rest_route(
            self::NAMESPACE,
            '/seller/import/(?P<id>\d+)/debug',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( __CLASS__, 'rest_admin_status' ),
                'permission_callback' => static function () { return current_user_can( 'manage_options' ); },
            )
        );
    }

    /* ---------------------------------------------------------------------
     * REST callbacks
     * ------------------------------------------------------------------ */

    public static function rest_upload( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $seller_id = self::current_seller_id();
        if ( is_wp_error( $seller_id ) ) {
            return $seller_id;
        }

        $files = $request->get_file_params();
        if ( empty( $files['file'] ) || ! is_array( $files['file'] ) ) {
            return new WP_Error( 'no_file', 'Attach a CSV file under the "file" field.', array( 'status' => 400 ) );
        }
        $file = $files['file'];
        if ( ! empty( $file['error'] ) ) {
            return new WP_Error( 'upload_error', 'File upload failed.', array( 'status' => 400, 'code' => $file['error'] ) );
        }
        if ( (int) $file['size'] > 10 * 1024 * 1024 ) {
            return new WP_Error( 'file_too_large', 'CSV must be 10 MB or less.', array( 'status' => 413 ) );
        }

        $parsed = self::parse_csv( $file['tmp_name'] );
        if ( is_wp_error( $parsed ) ) {
            return $parsed;
        }
        if ( empty( $parsed['rows'] ) ) {
            return new WP_Error( 'empty_csv', 'The CSV had no data rows.', array( 'status' => 422 ) );
        }
        if ( count( $parsed['rows'] ) > 500 ) {
            return new WP_Error( 'too_many_rows', 'CSV exceeds 500 rows. Split it into smaller files.', array( 'status' => 422 ) );
        }

        // Dry-run validation — report row-level problems before creating anything.
        $preview = array();
        $errors  = array();
        foreach ( array_slice( $parsed['rows'], 0, 5 ) as $i => $row ) {
            $normalized = self::normalize_row( $row );
            $preview[] = array(
                'row'   => $i + 2, // +2 accounts for header + 1-based indexing
                'name'  => $normalized['name'] ?? '',
                'price' => $normalized['regular_price'] ?? '',
                'stock' => $normalized['stock'] ?? '',
                'sku'   => $normalized['sku'] ?? '',
                'images_count' => count( $normalized['images_list'] ?? array() ),
            );
        }
        foreach ( $parsed['rows'] as $i => $row ) {
            $normalized = self::normalize_row( $row );
            $problems   = self::validate_row( $normalized );
            if ( $problems ) {
                $errors[] = array( 'row' => $i + 2, 'name' => $normalized['name'] ?? '', 'problems' => $problems );
            }
        }

        // Persist job.
        global $wpdb;
        $table = self::table();
        $now   = current_time( 'mysql' );
        $wpdb->insert(
            $table,
            array(
                'seller_id'    => $seller_id,
                'status'       => 'ready',
                'total_rows'   => count( $parsed['rows'] ),
                'processed'    => 0,
                'created'      => 0,
                'updated'      => 0,
                'failed'       => 0,
                'columns_json' => wp_json_encode( $parsed['columns'] ),
                'rows_json'    => wp_json_encode( $parsed['rows'] ),
                'errors_json'  => wp_json_encode( array() ),
                'created_at'   => $now,
                'updated_at'   => $now,
            ),
            array( '%d', '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
        );
        $job_id = (int) $wpdb->insert_id;

        return rest_ensure_response(
            array(
                'job_id'              => $job_id,
                'total_rows'          => count( $parsed['rows'] ),
                'columns'             => $parsed['columns'],
                'unrecognized_columns' => $parsed['unrecognized'],
                'preview'             => $preview,
                'validation_errors'   => $errors,
                'ready_to_run'        => count( $errors ) === 0,
            )
        );
    }

    public static function rest_run( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $seller_id = self::current_seller_id();
        if ( is_wp_error( $seller_id ) ) {
            return $seller_id;
        }
        $job = self::load_job( (int) $request['id'], $seller_id );
        if ( is_wp_error( $job ) ) {
            return $job;
        }
        if ( $job['status'] !== 'ready' ) {
            return new WP_Error( 'invalid_state', "Job is not runnable (status={$job['status']}).", array( 'status' => 409 ) );
        }
        self::update_job( $job['id'], array( 'status' => 'running', 'updated_at' => current_time( 'mysql' ) ) );
        // Schedule immediately so it kicks in on next WP-Cron tick or manual spawn.
        if ( ! wp_next_scheduled( self::CRON_HOOK, array( $job['id'] ) ) ) {
            wp_schedule_single_event( time(), self::CRON_HOOK, array( $job['id'] ) );
        }
        return rest_ensure_response( array( 'job_id' => $job['id'], 'status' => 'running' ) );
    }

    public static function rest_status( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $seller_id = self::current_seller_id();
        if ( is_wp_error( $seller_id ) ) {
            return $seller_id;
        }
        $job = self::load_job( (int) $request['id'], $seller_id );
        if ( is_wp_error( $job ) ) {
            return $job;
        }
        return rest_ensure_response(
            array(
                'job_id'    => $job['id'],
                'status'    => $job['status'],
                'total'     => (int) $job['total_rows'],
                'processed' => (int) $job['processed'],
                'created'   => (int) $job['created'],
                'updated'   => (int) $job['updated'],
                'failed'    => (int) $job['failed'],
                'errors'    => json_decode( $job['errors_json'] ?: '[]', true ),
                'updated_at' => $job['updated_at'],
            )
        );
    }

    /**
     * Admin-only: fetch any job (for support/debugging), bypassing ownership check.
     */
    public static function rest_admin_status( WP_REST_Request $request ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return new WP_Error( 'not_admin', 'Admin only.', array( 'status' => 403 ) );
        }
        global $wpdb;
        $table = $wpdb->prefix . 'mnu_import_jobs';
        $row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $request['id'] ), ARRAY_A );
        if ( ! $row ) {
            return new WP_Error( 'not_found', 'Job not found.', array( 'status' => 404 ) );
        }
        $errors = json_decode( $row['errors_json'] ?: '[]', true ) ?: array();
        return rest_ensure_response( array(
            'job_id'     => (int) $row['id'],
            'seller_id'  => (int) $row['seller_id'],
            'filename'   => $row['filename'] ?? '',
            'status'     => $row['status'],
            'total'      => (int) $row['total_rows'],
            'processed'  => (int) $row['processed'],
            'created'    => (int) $row['created'],
            'updated'    => (int) $row['updated'],
            'failed'     => (int) $row['failed'],
            'errors'     => $errors,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ) );
    }

    public static function rest_template( WP_REST_Request $request ): WP_REST_Response {
        $columns = array( 'Name', 'SKU', 'Regular price', 'Sale price', 'Description', 'Short description', 'Stock', 'Categories', 'Tags', 'Images', 'Weight (lbs)', 'Length (in)', 'Width (in)', 'Height (in)' );
        $example = array( 'Autumn Hair Bow', 'HB-001', '4.25', '', 'Handmade hair bow in fall colors.', 'Fall hair bow.', '10', 'Hair Bows > Hair Clips', 'Bows, Girls', 'https://shopmynest.com/example.jpg', '0.05', '3', '1.75', '0.5' );
        $csv = fopen( 'php://temp', 'r+' );
        fputcsv( $csv, $columns );
        fputcsv( $csv, $example );
        rewind( $csv );
        $data = stream_get_contents( $csv );
        fclose( $csv );
        return rest_ensure_response( array( 'csv' => $data, 'columns' => $columns ) );
    }

    /* ---------------------------------------------------------------------
     * CSV parsing
     * ------------------------------------------------------------------ */

    /**
     * Parses a CSV file into a list of associative rows keyed by canonical
     * column keys. Also returns the raw header list and any columns we
     * didn't map.
     *
     * @param string $path
     * @return array{columns:string[],rows:array<int,array<string,string>>,unrecognized:string[]}|WP_Error
     */
    private static function parse_csv( string $path ) {
        $fh = fopen( $path, 'r' );
        if ( ! $fh ) {
            return new WP_Error( 'read_failed', 'Could not open the uploaded file.', array( 'status' => 500 ) );
        }
        $header = fgetcsv( $fh );
        if ( ! $header ) {
            fclose( $fh );
            return new WP_Error( 'bad_header', 'The CSV appears to be empty or malformed.', array( 'status' => 422 ) );
        }
        // Strip BOM from the first cell (Excel loves adding these).
        if ( isset( $header[0] ) ) {
            $header[0] = preg_replace( '/^\x{FEFF}/u', '', (string) $header[0] );
        }

        $mapped_indexes = array();
        $unrecognized   = array();
        foreach ( $header as $idx => $name ) {
            $key = strtolower( trim( (string) $name ) );
            if ( isset( self::COLUMN_MAP[ $key ] ) ) {
                $mapped_indexes[ $idx ] = self::COLUMN_MAP[ $key ];
            } else {
                $unrecognized[] = (string) $name;
            }
        }

        $rows = array();
        while ( ( $line = fgetcsv( $fh ) ) !== false ) {
            if ( count( array_filter( $line, static fn( $v ) => '' !== trim( (string) $v ) ) ) === 0 ) {
                continue; // blank row
            }
            $row = array();
            foreach ( $mapped_indexes as $idx => $key ) {
                $row[ $key ] = isset( $line[ $idx ] ) ? (string) $line[ $idx ] : '';
            }
            $rows[] = $row;
        }
        fclose( $fh );

        return array( 'columns' => $header, 'rows' => $rows, 'unrecognized' => $unrecognized );
    }

    private static function normalize_row( array $row ): array {
        // Split multi-value fields.
        $row['images_list']     = array_values( array_filter( array_map( 'trim', explode( ',', (string) ( $row['images'] ?? '' ) ) ) ) );
        $row['categories_list'] = array_values( array_filter( array_map( 'trim', explode( ',', (string) ( $row['categories'] ?? '' ) ) ) ) );
        $row['tags_list']       = array_values( array_filter( array_map( 'trim', explode( ',', (string) ( $row['tags'] ?? '' ) ) ) ) );
        return $row;
    }

    private static function validate_row( array $row ): array {
        $problems = array();
        if ( empty( trim( (string) ( $row['name'] ?? '' ) ) ) ) {
            $problems[] = 'Name is required.';
        }
        $price = $row['regular_price'] ?? '';
        if ( '' === $price || ! is_numeric( $price ) || (float) $price < 0 ) {
            $problems[] = 'Regular price must be a non-negative number.';
        }
        if ( isset( $row['sale_price'] ) && '' !== $row['sale_price'] && ! is_numeric( $row['sale_price'] ) ) {
            $problems[] = 'Sale price must be numeric or blank.';
        }
        if ( isset( $row['stock'] ) && '' !== $row['stock'] && ! is_numeric( $row['stock'] ) ) {
            $problems[] = 'Stock must be a whole number or blank.';
        }
        return $problems;
    }

    /* ---------------------------------------------------------------------
     * Cron worker
     * ------------------------------------------------------------------ */

    public static function process_batch( int $job_id ): void {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $job_id ), ARRAY_A );
        if ( ! $row || $row['status'] !== 'running' ) {
            return;
        }
        $rows        = json_decode( $row['rows_json'] ?: '[]', true ) ?: array();
        $errors      = json_decode( $row['errors_json'] ?: '[]', true ) ?: array();
        $processed   = (int) $row['processed'];
        $created     = (int) $row['created'];
        $updated_ctr = (int) $row['updated'];
        $failed      = (int) $row['failed'];
        $seller_id   = (int) $row['seller_id'];

        // CRITICAL: In WP-Cron there is no logged-in user, so create_product's
        // ownership check (get_current_user_id() === $seller_id) fails.
        // Impersonate the job owner for the duration of this batch.
        $previous_user = get_current_user_id();
        wp_set_current_user( $seller_id );

        // Allow the seller to bulk-import their catalog before completing bank-account
        // onboarding. Imported products go live immediately via TNM_Marketplace::create_product,
        // which was hardcoded to 'publish' in v3.7.109 (no admin moderation queue).
        add_filter( 'mnu_skip_stripe_onboarding_gate', '__return_true' );

        $end = min( $processed + self::BATCH_SIZE, count( $rows ) );
        for ( $i = $processed; $i < $end; $i++ ) {
            $normalized = self::normalize_row( $rows[ $i ] );
            $result     = self::import_row( $seller_id, $normalized );
            if ( is_wp_error( $result ) ) {
                $failed++;
                $errors[] = array( 'row' => $i + 2, 'name' => $normalized['name'] ?? '', 'error' => $result->get_error_message() );
            } elseif ( 'updated' === ( $result['op'] ?? '' ) ) {
                $updated_ctr++;
            } else {
                $created++;
            }
        }
        $processed = $end;

        // Restore prior user context and remove the Stripe-gate bypass filter.
        remove_filter( 'mnu_skip_stripe_onboarding_gate', '__return_true' );
        wp_set_current_user( $previous_user );

        $status = $processed >= count( $rows ) ? 'complete' : 'running';
        self::update_job(
            (int) $row['id'],
            array(
                'processed'   => $processed,
                'created'     => $created,
                'updated'     => $updated_ctr,
                'failed'      => $failed,
                'status'      => $status,
                'errors_json' => wp_json_encode( array_slice( $errors, -100 ) ), // cap at last 100 errors
                'updated_at'  => current_time( 'mysql' ),
            )
        );

        if ( 'running' === $status ) {
            wp_schedule_single_event( time() + 5, self::CRON_HOOK, array( $job_id ) );
        }
    }

    /**
     * Import one row using existing marketplace helpers so permissions,
     * meta, and Stripe gates stay consistent with normal product creation.
     */
    private static function import_row( int $seller_id, array $row ): array|WP_Error {
        if ( ! class_exists( 'TNM_Marketplace' ) ) {
            return new WP_Error( 'marketplace_missing', 'Marketplace helper unavailable.' );
        }

        $sku      = trim( (string) ( $row['sku'] ?? '' ) );
        $existing = 0;
        if ( $sku && function_exists( 'wc_get_product_id_by_sku' ) ) {
            $existing = (int) wc_get_product_id_by_sku( $sku );
            if ( $existing ) {
                $owner = (int) get_post_meta( $existing, '_tnm_seller_id', true );
                if ( $owner !== $seller_id ) {
                    return new WP_Error( 'sku_taken', "SKU '{$sku}' is used by another seller; skipping." );
                }
            }
        }

        $category_ids = self::ensure_categories( $row['categories_list'] ?? array() );
        $image_ids    = self::sideload_images( $seller_id, $row['images_list'] ?? array() );

        $data = array(
            'name'         => (string) ( $row['name'] ?? '' ),
            'description'  => (string) ( $row['description'] ?? '' ),
            'price'        => (string) ( $row['regular_price'] ?? '' ),
            'stock'        => (int) ( $row['stock'] ?? 0 ),
            'sku'          => $sku,
            'category_ids' => $category_ids,
            'image_id'     => ! empty( $image_ids ) ? $image_ids[0] : 0,
            'weight_oz'    => isset( $row['weight_lbs'] ) && '' !== $row['weight_lbs']
                ? round( ( (float) $row['weight_lbs'] ) * 16, 2 )
                : null,
            'length_in'    => $row['length_in'] ?? null,
            'width_in'     => $row['width_in'] ?? null,
            'height_in'    => $row['height_in'] ?? null,
        );

        if ( $existing ) {
            $result = TNM_Marketplace::update_product( $seller_id, $existing, $data );
            if ( is_wp_error( $result ) ) {
                return $result;
            }
            self::attach_gallery( $existing, array_slice( $image_ids, 1 ) );
            self::attach_tags( $existing, $row['tags_list'] ?? array() );
            self::attach_short_description( $existing, (string) ( $row['short_description'] ?? '' ) );
            self::attach_sale_price( $existing, (string) ( $row['sale_price'] ?? '' ) );
            return array( 'op' => 'updated', 'product_id' => (int) $result );
        }

        $created = TNM_Marketplace::create_product( $seller_id, $data );
        if ( is_wp_error( $created ) ) {
            return $created;
        }
        self::attach_gallery( (int) $created, array_slice( $image_ids, 1 ) );
        self::attach_tags( (int) $created, $row['tags_list'] ?? array() );
        self::attach_short_description( (int) $created, (string) ( $row['short_description'] ?? '' ) );
        self::attach_sale_price( (int) $created, (string) ( $row['sale_price'] ?? '' ) );
        return array( 'op' => 'created', 'product_id' => (int) $created );
    }

    /* ---------------------------------------------------------------------
     * Row helpers
     * ------------------------------------------------------------------ */

    private static function ensure_categories( array $names ): array {
        $ids = array();
        foreach ( $names as $raw ) {
            // WooCommerce exports use " > " to indicate hierarchy.
            $parts  = array_map( 'trim', explode( '>', (string) $raw ) );
            $parent = 0;
            foreach ( $parts as $part ) {
                if ( '' === $part ) { continue; }
                $existing = get_term_by( 'name', $part, 'product_cat' );
                if ( $existing && (int) $existing->parent === $parent ) {
                    $parent = (int) $existing->term_id;
                    continue;
                }
                $created = wp_insert_term( $part, 'product_cat', array( 'parent' => $parent ) );
                if ( is_wp_error( $created ) ) {
                    // Term may already exist under a different parent — reuse.
                    if ( $existing ) {
                        $parent = (int) $existing->term_id;
                        continue;
                    }
                    break;
                }
                $parent = (int) $created['term_id'];
            }
            if ( $parent ) {
                $ids[] = $parent;
            }
        }
        return array_values( array_unique( $ids ) );
    }

    private static function sideload_images( int $seller_id, array $urls ): array {
        if ( ! function_exists( 'media_sideload_image' ) ) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
        $ids = array();
        foreach ( array_slice( $urls, 0, 8 ) as $url ) {
            $url = esc_url_raw( trim( (string) $url ) );
            if ( ! $url ) { continue; }
            $attachment_id = media_sideload_image( $url, 0, null, 'id' );
            if ( is_wp_error( $attachment_id ) ) { continue; }
            wp_update_post( array( 'ID' => (int) $attachment_id, 'post_author' => $seller_id ) );
            $ids[] = (int) $attachment_id;
        }
        return $ids;
    }

    private static function attach_gallery( int $product_id, array $extra_image_ids ): void {
        if ( empty( $extra_image_ids ) ) { return; }
        $product = wc_get_product( $product_id );
        if ( ! $product ) { return; }
        $product->set_gallery_image_ids( array_map( 'intval', $extra_image_ids ) );
        $product->save();
    }

    private static function attach_tags( int $product_id, array $tags ): void {
        if ( empty( $tags ) ) { return; }
        wp_set_object_terms( $product_id, array_map( 'sanitize_text_field', $tags ), 'product_tag' );
    }

    private static function attach_short_description( int $product_id, string $short ): void {
        if ( '' === trim( $short ) ) { return; }
        wp_update_post( array( 'ID' => $product_id, 'post_excerpt' => wp_kses_post( $short ) ) );
    }

    private static function attach_sale_price( int $product_id, string $sale_price ): void {
        if ( '' === trim( $sale_price ) || ! is_numeric( $sale_price ) ) { return; }
        $product = wc_get_product( $product_id );
        if ( ! $product ) { return; }
        $product->set_sale_price( wc_format_decimal( $sale_price ) );
        $product->save();
    }

    /* ---------------------------------------------------------------------
     * Persistence
     * ------------------------------------------------------------------ */

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'mnu_import_jobs';
    }

    private static function load_job( int $job_id, int $seller_id ) {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . self::table() . ' WHERE id = %d AND seller_id = %d',
                $job_id,
                $seller_id
            ),
            ARRAY_A
        );
        if ( ! $row ) {
            return new WP_Error( 'not_found', 'Import job not found.', array( 'status' => 404 ) );
        }
        return $row;
    }

    private static function update_job( int $job_id, array $fields ): void {
        global $wpdb;
        $wpdb->update( self::table(), $fields, array( 'id' => $job_id ) );
    }

    private static function current_seller_id() {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return new WP_Error( 'not_logged_in', 'You must be signed in.', array( 'status' => 401 ) );
        }
        // Match the rest of the plugin: sellers OR admins/managers may import.
        // Admins imports go into their own account so they can be QA-tested
        // without needing a real seller to be online.
        $is_seller  = function_exists( 'tnm_is_seller' ) && tnm_is_seller( $user_id );
        $is_manager = function_exists( 'tnm_is_admin_or_manager' ) && tnm_is_admin_or_manager();
        if ( ! $is_seller && ! $is_manager ) {
            return new WP_Error( 'not_seller', 'An approved seller account is required.', array( 'status' => 403 ) );
        }
        return (int) $user_id;
    }
}
