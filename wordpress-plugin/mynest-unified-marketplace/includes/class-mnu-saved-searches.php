<?php
/**
 * v3.7.101 — Saved searches with new-listing alerts.
 *
 * A saved search is one row: (user_id, query_json). A cron replays each row
 * against WP_Query with `date_query >= last_checked_at`, and fires a
 * `saved_search_hit` notification (which goes through tnm_notify → in-app row
 * + Expo push) for every new product that matched. Then `last_checked_at`
 * moves forward.
 *
 * Design notes:
 *  - `query_hash` is a SHA-1 of the normalised payload. It's the UNIQUE key
 *    with user_id, so re-saving the same search is idempotent (returns the
 *    existing row). That's what a buyer wants: tapping "Save alert" twice
 *    shouldn't duplicate.
 *  - Max 25 saved searches per user. Enough for real use, cheap enough for
 *    the cron.
 *  - The cron caps matches per row per run at 10 to prevent a single
 *    catch-all search ("chair") from spamming a buyer when a seller bulk-
 *    imports 200 items. If more than 10 hit, we send a summary instead.
 */

defined( 'ABSPATH' ) || exit;

final class MNU_SavedSearches {

    const MAX_PER_USER      = 25;
    const MAX_HITS_PER_RUN  = 10;
    const CRON_HOOK         = 'mnu_saved_searches_check';
    const CRON_INTERVAL     = 'mnu_hourly';

    public static function init(): void {
        add_filter( 'cron_schedules', array( __CLASS__, 'add_cron_interval' ) );
        add_action( 'init', array( __CLASS__, 'schedule_cron' ) );
        add_action( self::CRON_HOOK, array( __CLASS__, 'run_all' ) );
    }

    public static function add_cron_interval( array $schedules ): array {
        if ( ! isset( $schedules[ self::CRON_INTERVAL ] ) ) {
            $schedules[ self::CRON_INTERVAL ] = array( 'interval' => HOUR_IN_SECONDS, 'display' => 'Every hour (MNU)' );
        }
        return $schedules;
    }

    public static function schedule_cron(): void {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + 300, self::CRON_INTERVAL, self::CRON_HOOK );
        }
    }

    // --- CRUD -------------------------------------------------------------

    public static function list_for_user( int $user_id ): array {
        global $wpdb;
        $t    = tnm_table( 'saved_searches' );
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE user_id = %d ORDER BY updated_at DESC", $user_id ), ARRAY_A );
        return array_map( array( __CLASS__, 'to_public' ), $rows ?: array() );
    }

    /**
     * Create (or return existing) saved search. Idempotent on (user, hash).
     *
     * @param int   $user_id
     * @param array $payload {
     *     label, search, category, sort, min_price, max_price,
     *     pa_condition, pa_size, pa_brand, seller_id
     * }
     * @return array|WP_Error the row (public shape).
     */
    public static function create( int $user_id, array $payload ) {
        $query   = self::normalise_query( $payload );
        $label   = self::derive_label( $payload );
        $hash    = sha1( wp_json_encode( $query ) );

        global $wpdb;
        $t = tnm_table( 'saved_searches' );

        $existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE user_id = %d AND query_hash = %s", $user_id, $hash ), ARRAY_A );
        if ( $existing ) {
            // Re-saving the same query: bump updated_at and turn notify back on.
            $wpdb->update(
                $t,
                array( 'notify' => 1, 'label' => $label, 'updated_at' => current_time( 'mysql', true ) ),
                array( 'id' => (int) $existing['id'] ),
                array( '%d', '%s', '%s' ),
                array( '%d' )
            );
            $existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", (int) $existing['id'] ), ARRAY_A );
            return self::to_public( $existing );
        }

        // Enforce per-user cap.
        $count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE user_id = %d", $user_id ) );
        if ( $count >= self::MAX_PER_USER ) {
            return new WP_Error( 'saved_search_limit', 'You have reached the maximum of ' . self::MAX_PER_USER . ' saved searches. Delete an old one first.', array( 'status' => 400 ) );
        }

        $now = current_time( 'mysql', true );
        $wpdb->insert(
            $t,
            array(
                'user_id'                 => $user_id,
                'label'                   => $label,
                'query_hash'              => $hash,
                'query_json'              => wp_json_encode( $query ),
                'notify'                  => 1,
                'last_checked_at'         => $now,
                'last_matched_product_id' => 0,
                'created_at'              => $now,
                'updated_at'              => $now,
            ),
            array( '%d', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s' )
        );
        $id  = (int) $wpdb->insert_id;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $id ), ARRAY_A );
        return self::to_public( $row );
    }

    public static function update( int $user_id, int $id, array $changes ) {
        global $wpdb;
        $t   = tnm_table( 'saved_searches' );
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d AND user_id = %d", $id, $user_id ), ARRAY_A );
        if ( ! $row ) {
            return new WP_Error( 'not_found', 'Saved search not found.', array( 'status' => 404 ) );
        }
        $set     = array( 'updated_at' => current_time( 'mysql', true ) );
        $formats = array( '%s' );
        if ( isset( $changes['notify'] ) && null !== $changes['notify'] ) {
            $set['notify'] = $changes['notify'] ? 1 : 0;
            $formats[]     = '%d';
        }
        $wpdb->update( $t, $set, array( 'id' => $id ), $formats, array( '%d' ) );
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $id ), ARRAY_A );
        return self::to_public( $row );
    }

    public static function delete( int $user_id, int $id ) {
        global $wpdb;
        $t       = tnm_table( 'saved_searches' );
        $deleted = $wpdb->delete( $t, array( 'id' => $id, 'user_id' => $user_id ), array( '%d', '%d' ) );
        if ( false === $deleted ) {
            return new WP_Error( 'delete_failed', 'Could not delete saved search.', array( 'status' => 500 ) );
        }
        return $deleted > 0;
    }

    // --- Cron -------------------------------------------------------------

    public static function run_all(): void {
        global $wpdb;
        $t = tnm_table( 'saved_searches' );

        // Only rows the user still wants alerts on. Order by oldest check so
        // even a large table drains fairly.
        $rows = $wpdb->get_results( "SELECT * FROM $t WHERE notify = 1 ORDER BY last_checked_at ASC LIMIT 500", ARRAY_A );
        foreach ( $rows ?: array() as $row ) {
            try {
                self::check_row( $row );
            } catch ( \Throwable $e ) {
                // Never let one bad row stop the batch.
                if ( function_exists( 'error_log' ) ) {
                    error_log( '[MNU_SavedSearches] row ' . $row['id'] . ' failed: ' . $e->getMessage() );
                }
            }
        }
    }

    protected static function check_row( array $row ): void {
        $user_id = (int) $row['user_id'];
        $query   = json_decode( (string) $row['query_json'], true );
        if ( ! is_array( $query ) ) {
            return;
        }

        $args = self::to_wp_query_args( $query );

        // Only products created since we last looked.
        $since = (string) $row['last_checked_at'];
        if ( $since ) {
            $args['date_query'] = array(
                array(
                    'column'    => 'post_date_gmt',
                    'after'     => $since,
                    'inclusive' => false,
                ),
            );
        }
        // Cap results — see MAX_HITS_PER_RUN docblock at top.
        $args['posts_per_page'] = self::MAX_HITS_PER_RUN + 1;
        $args['orderby']        = 'date';
        $args['order']          = 'ASC';
        $args['no_found_rows']  = true;

        $q     = new WP_Query( $args );
        $hits  = array();
        foreach ( $q->posts as $post ) {
            $product = function_exists( 'wc_get_product' ) ? wc_get_product( $post->ID ) : null;
            if ( ! $product ) {
                continue;
            }
            // Skip the buyer's own listings.
            $seller_id = (int) get_post_field( 'post_author', $post->ID );
            if ( $seller_id === $user_id ) {
                continue;
            }
            $hits[] = $product;
        }

        if ( empty( $hits ) ) {
            self::mark_checked( (int) $row['id'] );
            return;
        }

        $overflow = count( $hits ) > self::MAX_HITS_PER_RUN;
        $to_push  = $overflow ? array_slice( $hits, 0, self::MAX_HITS_PER_RUN ) : $hits;

        if ( $overflow ) {
            // One roll-up push instead of a firehose.
            $label = (string) $row['label'];
            tnm_notify(
                $user_id,
                0,
                'saved_search_hit',
                'New matches for "' . $label . '"',
                count( $hits ) . ' new items match your saved search.',
                (int) $row['id'],
                'saved_search',
                ''
            );
        } else {
            foreach ( $to_push as $product ) {
                $title = 'New match for "' . $row['label'] . '"';
                $body  = $product->get_name();
                $price = $product->get_price();
                if ( '' !== $price && null !== $price ) {
                    $body .= ' — $' . number_format( (float) $price, 2 );
                }
                tnm_notify(
                    $user_id,
                    (int) get_post_field( 'post_author', $product->get_id() ),
                    'saved_search_hit',
                    $title,
                    $body,
                    (int) $product->get_id(),
                    'product',
                    ''
                );
            }
        }

        // High-water mark is the most recent product we notified about, so if
        // the cron races with a new insert we don't double-notify next tick.
        $newest_id = 0;
        foreach ( $to_push as $p ) {
            $newest_id = max( $newest_id, (int) $p->get_id() );
        }
        self::mark_checked( (int) $row['id'], $newest_id );
    }

    protected static function mark_checked( int $id, int $newest_id = 0 ): void {
        global $wpdb;
        $t   = tnm_table( 'saved_searches' );
        $set = array( 'last_checked_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ) );
        $fmt = array( '%s', '%s' );
        if ( $newest_id > 0 ) {
            $set['last_matched_product_id'] = $newest_id;
            $fmt[]                          = '%d';
        }
        $wpdb->update( $t, $set, array( 'id' => $id ), $fmt, array( '%d' ) );
    }

    // --- Query translation ------------------------------------------------

    /**
     * Normalise the payload we accept from the client into the stored form.
     * Empty/zero values are dropped so the hash is stable across "min_price=''"
     * vs "min_price=0" etc.
     */
    protected static function normalise_query( array $p ): array {
        $out = array();
        $keys = array(
            'search'       => 'string',
            'category'     => 'int',
            'sort'         => 'string',
            'min_price'    => 'string',
            'max_price'    => 'string',
            'pa_condition' => 'string',
            'pa_size'      => 'string',
            'pa_brand'     => 'string',
            'seller_id'    => 'int',
        );
        foreach ( $keys as $k => $type ) {
            if ( ! isset( $p[ $k ] ) ) {
                continue;
            }
            if ( 'int' === $type ) {
                $v = (int) $p[ $k ];
                if ( $v > 0 ) {
                    $out[ $k ] = $v;
                }
            } else {
                $v = trim( (string) $p[ $k ] );
                if ( '' !== $v ) {
                    $out[ $k ] = $v;
                }
            }
        }
        ksort( $out );
        return $out;
    }

    /**
     * Turn the stored query into WP_Query args. Kept intentionally close to
     * `TNM_REST::products()` so the alerts fire on the same set of products
     * the /products endpoint would have returned to the buyer.
     */
    protected static function to_wp_query_args( array $q ): array {
        $args = array(
            'post_type'      => 'product',
            'post_status'    => 'publish',
            's'              => (string) ( $q['search'] ?? '' ),
            'tax_query'      => array(
                array( 'taxonomy' => 'product_visibility', 'field' => 'name', 'terms' => array( 'exclude-from-catalog' ), 'operator' => 'NOT IN' ),
                array( 'taxonomy' => 'product_visibility', 'field' => 'name', 'terms' => array( 'outofstock' ), 'operator' => 'NOT IN' ),
            ),
        );
        if ( ! empty( $q['category'] ) ) {
            $args['tax_query'][] = array( 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => array( (int) $q['category'] ) );
        }
        if ( ! empty( $q['pa_condition'] ) ) {
            $args['tax_query'][] = array( 'taxonomy' => 'pa_condition', 'field' => 'slug', 'terms' => array( sanitize_title( (string) $q['pa_condition'] ) ) );
        }
        if ( ! empty( $q['pa_size'] ) ) {
            $args['tax_query'][] = array( 'taxonomy' => 'pa_size', 'field' => 'name', 'terms' => array( (string) $q['pa_size'] ) );
        }
        if ( ! empty( $q['pa_brand'] ) ) {
            $args['tax_query'][] = array( 'taxonomy' => 'pa_brand', 'field' => 'name', 'terms' => array( (string) $q['pa_brand'] ) );
        }
        $meta = array();
        if ( isset( $q['min_price'] ) && '' !== (string) $q['min_price'] ) {
            $meta[] = array( 'key' => '_price', 'value' => (float) $q['min_price'], 'compare' => '>=', 'type' => 'DECIMAL(10,2)' );
        }
        if ( isset( $q['max_price'] ) && '' !== (string) $q['max_price'] ) {
            $meta[] = array( 'key' => '_price', 'value' => (float) $q['max_price'], 'compare' => '<=', 'type' => 'DECIMAL(10,2)' );
        }
        if ( $meta ) {
            $args['meta_query'] = array_merge( array( 'relation' => 'AND' ), $meta );
        }
        if ( ! empty( $q['seller_id'] ) ) {
            $args['author'] = (int) $q['seller_id'];
        }
        return $args;
    }

    protected static function derive_label( array $p ): string {
        $search = trim( (string) ( $p['search'] ?? '' ) );
        if ( '' !== $search ) {
            return mb_strimwidth( $search, 0, 100, '…' );
        }
        // No text query — fall back to whatever filters are set.
        $parts = array();
        if ( ! empty( $p['category'] ) ) {
            $term = get_term( (int) $p['category'], 'product_cat' );
            if ( $term && ! is_wp_error( $term ) ) {
                $parts[] = $term->name;
            }
        }
        foreach ( array( 'pa_condition', 'pa_size', 'pa_brand' ) as $k ) {
            if ( ! empty( $p[ $k ] ) ) {
                $parts[] = (string) $p[ $k ];
            }
        }
        if ( ! empty( $p['min_price'] ) || ! empty( $p['max_price'] ) ) {
            $parts[] = trim( '$' . ( $p['min_price'] ?? '' ) . '–' . ( $p['max_price'] ?? '' ), '-' );
        }
        return $parts ? implode( ' · ', $parts ) : 'Saved search';
    }

    protected static function to_public( ?array $row ): array {
        if ( ! $row ) {
            return array();
        }
        $q = json_decode( (string) $row['query_json'], true ) ?: array();
        return array(
            'id'              => (int) $row['id'],
            'label'           => (string) $row['label'],
            'query'           => $q,
            'notify'          => 1 === (int) $row['notify'],
            'last_checked_at' => (string) $row['last_checked_at'],
            'created_at'      => (string) $row['created_at'],
            'updated_at'      => (string) $row['updated_at'],
        );
    }
}

MNU_SavedSearches::init();
