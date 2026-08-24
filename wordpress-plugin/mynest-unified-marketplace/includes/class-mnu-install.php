<?php

defined( 'ABSPATH' ) || exit;

final class MNU_Install {
    private static bool $initialized = false;

    /**
     * Register lifecycle callbacks that must run at or after WordPress init.
     */
    public static function init(): void {
        if ( self::$initialized ) {
            return;
        }

        self::$initialized = true;
        add_action( 'init', array( __CLASS__, 'maybe_flush_rewrite_rules' ), 99 );
    }

    /**
     * Install or update persistent marketplace data.
     *
     * Custom post types are never registered from plugins_loaded. WordPress 7
     * initializes the rewrite object later in the request, so registering a
     * rewritable post type too early can call add_rewrite_tag() on a null
     * $wp_rewrite object and take the site down.
     */
    public static function activate(): void {
        self::create_roles();
        self::create_tables();
        self::create_pages();
        self::set_defaults();
        if ( class_exists( 'MNU_Buyer_Experience' ) ) {
            MNU_Buyer_Experience::configure_accounts();
        }

        // Rewrite rules are flushed once, only after init and CPT registration.
        update_option( 'mnu_flush_rewrite_rules', 'yes', false );

        if ( ! wp_next_scheduled( 'tnm_daily_maintenance' ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'tnm_daily_maintenance' );
        }

        /*
         * Plugin activation happens after init in wp-admin. In that specific
         * request, it is safe to register the post types and perform the one-time
         * flush immediately. Normal boot upgrades remain deferred until init.
         */
        if ( did_action( 'init' ) ) {
            self::maybe_flush_rewrite_rules();
        }
    }

    public static function deactivate(): void {
        wp_clear_scheduled_hook( 'tnm_daily_maintenance' );

        // Avoid calling the rewrite API if WordPress has not initialized it.
        global $wp_rewrite;
        if ( did_action( 'init' ) && is_object( $wp_rewrite ) ) {
            flush_rewrite_rules( false );
        }
    }

    /**
     * Register the marketplace CPTs and flush rewrite rules at a safe point.
     */
    public static function maybe_flush_rewrite_rules(): void {
        if ( 'yes' !== get_option( 'mnu_flush_rewrite_rules', '' ) ) {
            return;
        }

        if ( ! did_action( 'init' ) ) {
            return;
        }

        if ( class_exists( 'TNM_Content' ) ) {
            TNM_Content::register_post_types();
        }

        global $wp_rewrite;
        if ( ! is_object( $wp_rewrite ) ) {
            // Keep the pending flag so a later normal request can retry safely.
            return;
        }

        flush_rewrite_rules( false );
        delete_option( 'mnu_flush_rewrite_rules' );
    }

    public static function set_defaults(): void {
        $defaults = array(
            'fee_percent'              => 10,
            'fee_label'                => 'Nest Service Fee',
            'holding_days'             => 2,
            'minimum_payout'           => 25,
            'automatic_payouts'        => 'no',
            'payout_schedule'          => 'weekly',
            'payout_method'            => 'manual',
            'paypal_environment'       => 'sandbox',
            'paypal_client_id'         => '',
            'paypal_client_secret'     => '',
            'seller_can_publish'       => 'yes',
            'require_application'      => 'yes',
            'verified_reviews_only'    => 'yes',
            'allow_buyer_registration' => 'yes',
            'token_lifetime_days'      => 30,
            'buyer_sees_seller_fee'    => 'no',
            'order_breakdown_emails'   => 'yes',
            'remove_local_pickup'      => 'yes',
            'seller_order_emails'      => 'yes',
        );
        $current = get_option( 'tnm_settings', array() );
        $current = is_array( $current ) ? $current : array();
        $merged  = wp_parse_args( $current, $defaults );

        // v3.13.27 — seller hold is 2 days (matches Stripe's ~2-day
        // payout cycle so seller earnings become available the same day
        // Stripe drops the funds into Bluevine). Migrate any legacy value
        // (3 or 7) forward on activation; operator-set overrides survive
        // because they won't match either of those historical defaults.
        $legacy_hold = (int) ( $merged['holding_days'] ?? 0 );
        if ( 3 === $legacy_hold || 7 === $legacy_hold ) {
            $merged['holding_days'] = 2;
        }

        update_option( 'tnm_settings', $merged, false );
        update_option( 'tnm_db_version', MNU_DB_VERSION, false );
        update_option( 'mnu_version', MNU_VERSION, false );

        // v3.13.29 — recompute available_at on in-flight pending earning
        // rows so a row created under the old 3- or 7-day default doesn't
        // silently keep its old holding date after the option was moved to
        // 2 days. Uses each row's own `created_at` as the paid proxy plus
        // the current holding_days. Skips already-released, refunded, void,
        // or dispute-held rows. Idempotent — the WHERE clause only touches
        // rows whose current available_at is later than what today's setting
        // would produce, so re-running the migration on subsequent
        // activations is a no-op.
        self::migrate_pending_available_at( (int) $merged['holding_days'] );
    }

    /**
     * v3.13.29 — idempotent data migration for finding #11. Recalculates
     * available_at on `pending` earning rows so a holding_days change
     * applies to in-flight orders, not just future ones.
     *
     * Bounded to 5000 rows per invocation with a monotonic "last migrated
     * id" marker so a huge legacy install completes across activations
     * rather than timing out.
     */
    private static function migrate_pending_available_at( int $holding_days ): void {
        $holding_days = max( 0, $holding_days );
        global $wpdb;
        $ledger = tnm_table( 'ledger' );

        $last_id = (int) get_option( 'mnu_available_at_migration_last_id', 0 );
        $batch   = (int) apply_filters( 'mnu_available_at_migration_batch', 5000 );
        $now     = current_time( 'mysql', true );

        // Only touch rows whose stored available_at exceeds
        // created_at + holding_days (i.e. rows still held under the OLD,
        // longer window). Rows already inside the new window are left as-is.
        $target_seconds = $holding_days * DAY_IN_SECONDS;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, created_at, available_at FROM {$ledger}
             WHERE id > %d
               AND type='earning'
               AND status='pending'
               AND UNIX_TIMESTAMP(available_at) > UNIX_TIMESTAMP(created_at) + %d
             ORDER BY id ASC
             LIMIT %d",
            $last_id,
            $target_seconds,
            $batch
        ), ARRAY_A );

        if ( empty( $rows ) ) {
            update_option( 'mnu_available_at_migration_last_id', 0, false );
            update_option( 'mnu_available_at_migration_completed_at', $now, false );
            return;
        }

        $updated = 0;
        $max_id  = $last_id;
        foreach ( $rows as $row ) {
            $id           = (int) $row['id'];
            $created_ts   = strtotime( (string) $row['created_at'] );
            if ( ! $created_ts ) {
                $max_id = max( $max_id, $id );
                continue;
            }
            $new_available_at = gmdate( 'Y-m-d H:i:s', $created_ts + $target_seconds );
            if ( $new_available_at === (string) $row['available_at'] ) {
                $max_id = max( $max_id, $id );
                continue;
            }
            $wpdb->update(
                $ledger,
                array(
                    'available_at' => $new_available_at,
                    'updated_at'   => $now,
                    'note'         => sprintf( 'available_at migrated from %s to %s by v3.13.29 (holding_days=%d).', $row['available_at'], $new_available_at, $holding_days ),
                ),
                array( 'id' => $id ),
                array( '%s', '%s', '%s' ),
                array( '%d' )
            );
            $updated++;
            $max_id = max( $max_id, $id );
        }

        update_option( 'mnu_available_at_migration_last_id', $max_id, false );
        error_log( sprintf(
            '[MNU available_at migration] Batch complete: updated=%d, last_id=%d, holding_days=%d.',
            $updated,
            $max_id,
            $holding_days
        ) );
    }

    public static function create_roles(): void {
        $seller_caps = array(
            'read'                      => true,
            'upload_files'              => true,
            'edit_posts'                => true,
            'publish_posts'             => true,
            'delete_posts'              => true,
            'edit_published_posts'      => true,
            'delete_published_posts'    => true,
            'edit_products'             => true,
            'publish_products'          => true,
            'read_private_products'     => true,
            'delete_products'           => true,
            'edit_published_products'   => true,
            'delete_published_products' => true,
            'tnm_manage_store'          => true,
            'tnm_view_earnings'         => true,
            'tnm_request_payout'        => true,
        );

        foreach ( array( 'tnm_seller' => 'Nest Seller', 'mynest_seller' => 'MyNest Seller' ) as $role_key => $role_name ) {
            if ( ! get_role( $role_key ) ) {
                add_role( $role_key, __( $role_name, 'mynest-unified-marketplace' ), $seller_caps );
            }
            $role = get_role( $role_key );
            if ( $role ) {
                foreach ( $seller_caps as $cap => $grant ) {
                    $role->add_cap( $cap, $grant );
                }
            }
        }

        $admin = get_role( 'administrator' );
        if ( $admin ) {
            foreach ( array( 'tnm_manage_marketplace', 'tnm_manage_store', 'tnm_view_earnings', 'tnm_request_payout', 'mnu_manage_marketplace' ) as $cap ) {
                $admin->add_cap( $cap );
            }
        }

        $manager = get_role( 'shop_manager' );
        if ( $manager ) {
            foreach ( array( 'tnm_manage_marketplace', 'mnu_manage_marketplace' ) as $cap ) {
                $manager->add_cap( $cap );
            }
        }
    }

    public static function create_tables(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();

        $queries = array();
        $queries[] = 'CREATE TABLE ' . tnm_table( 'ledger' ) . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            seller_id bigint(20) unsigned NOT NULL,
            order_id bigint(20) unsigned NOT NULL,
            order_item_id bigint(20) unsigned NOT NULL DEFAULT 0,
            type varchar(30) NOT NULL DEFAULT 'earning',
            gross decimal(18,6) NOT NULL DEFAULT 0,
            platform_fee decimal(18,6) NOT NULL DEFAULT 0,
            tax decimal(18,6) NOT NULL DEFAULT 0,
            shipping decimal(18,6) NOT NULL DEFAULT 0,
            net decimal(18,6) NOT NULL DEFAULT 0,
            currency varchar(10) NOT NULL DEFAULT 'USD',
            status varchar(30) NOT NULL DEFAULT 'pending',
            available_at datetime NULL,
            payout_id bigint(20) unsigned NOT NULL DEFAULT 0,
            platform_payout_id varchar(190) NOT NULL DEFAULT '',
            stripe_transfer_id varchar(190) NOT NULL DEFAULT '',
            note text NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY order_item_type (order_id,order_item_id,type),
            KEY seller_status (seller_id,status),
            KEY payout_id (payout_id),
            KEY platform_payout (platform_payout_id),
            KEY stripe_transfer (stripe_transfer_id)
        ) $charset;";

        $queries[] = 'CREATE TABLE ' . tnm_table( 'payouts' ) . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            seller_id bigint(20) unsigned NOT NULL,
            amount decimal(18,6) NOT NULL DEFAULT 0,
            currency varchar(10) NOT NULL DEFAULT 'USD',
            method varchar(30) NOT NULL DEFAULT 'manual',
            destination varchar(190) NOT NULL DEFAULT '',
            external_id varchar(190) NOT NULL DEFAULT '',
            status varchar(30) NOT NULL DEFAULT 'requested',
            notes text NULL,
            requested_at datetime NOT NULL,
            processed_at datetime NULL,
            PRIMARY KEY  (id),
            KEY seller_status (seller_id,status)
        ) $charset;";

        // v3.9.0 (Phase 3) — audit log for manual ACH payout batches
        // created from WP Admin → Marketplace → Payouts.
        //
        // v3.13.30 Fix #12 — added `status`, `ach_reference`,
        // `ach_confirmed_at`, `ach_confirmed_by`. dbDelta will ADD (never
        // drop) the new columns on existing installs.
        $queries[] = 'CREATE TABLE ' . tnm_table( 'payout_batches' ) . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            created_at datetime NOT NULL,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            seller_count int unsigned NOT NULL DEFAULT 0,
            total_amount decimal(18,6) NOT NULL DEFAULT 0,
            row_count int unsigned NOT NULL DEFAULT 0,
            memo varchar(190) NOT NULL DEFAULT '',
            status varchar(24) NOT NULL DEFAULT 'pending',
            ach_reference varchar(64) NOT NULL DEFAULT '',
            ach_confirmed_at datetime NULL DEFAULT NULL,
            ach_confirmed_by bigint(20) unsigned NULL DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY created_at (created_at),
            KEY created_by (created_by),
            KEY status (status)
        ) $charset;";

        // v3.13.30 Fix #12 — immutable join between a manual payout batch
        // and the ledger rows it will pay. Rows are inserted at batch
        // creation and the ledger row status stays 'available' until an
        // admin confirms the ACH transfer via mark_paid + ach_reference.
        $queries[] = 'CREATE TABLE ' . tnm_table( 'payout_batch_rows' ) . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            batch_id bigint(20) unsigned NOT NULL,
            seller_id bigint(20) unsigned NOT NULL,
            ledger_row_id bigint(20) unsigned NOT NULL,
            amount decimal(18,6) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY batch_ledger (batch_id,ledger_row_id),
            KEY seller_batch (seller_id,batch_id)
        ) $charset;";

        $queries[] = 'CREATE TABLE ' . tnm_table( 'follows' ) . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            follower_id bigint(20) unsigned NOT NULL,
            following_id bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY follower_following (follower_id,following_id),
            KEY following_id (following_id)
        ) $charset;";

        $queries[] = 'CREATE TABLE ' . tnm_table( 'notifications' ) . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
            type varchar(50) NOT NULL,
            object_id bigint(20) unsigned NOT NULL DEFAULT 0,
            object_type varchar(50) NOT NULL DEFAULT '',
            title varchar(255) NOT NULL,
            message text NULL,
            url text NULL,
            is_read tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY user_read (user_id,is_read),
            KEY created_at (created_at)
        ) $charset;";

        // v3.7.86 — photo_attachments carries a JSON array of WP attachment
        // IDs owned by the sender, e.g. "[123,124,125]". Rendered by the
        // client via signed URLs from /messages/photo/{id}. Kept nullable so
        // existing rows (and text-only messages) don't need backfill.
        $queries[] = 'CREATE TABLE ' . tnm_table( 'messages' ) . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            sender_id bigint(20) unsigned NOT NULL,
            recipient_id bigint(20) unsigned NOT NULL,
            message text NOT NULL,
            photo_attachments text NULL,
            is_read tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY conversation_a (sender_id,recipient_id,created_at),
            KEY conversation_b (recipient_id,sender_id,created_at),
            KEY recipient_read (recipient_id,is_read)
        ) $charset;";

        // v3.7.98 — blog favorites so buyers can heart Fresh from the Nest posts
        // and find them again in Favorites. Kept separate from the trust-suite
        // product favorites table to keep the two lists distinct and to avoid
        // touching the trust-suite plugin.
        $queries[] = 'CREATE TABLE ' . tnm_table( 'blog_favorites' ) . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            post_id bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY user_post (user_id,post_id),
            KEY post_id (post_id)
        ) $charset;";

        $queries[] = 'CREATE TABLE ' . tnm_table( 'reviews' ) . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            reviewer_id bigint(20) unsigned NOT NULL,
            seller_id bigint(20) unsigned NOT NULL,
            order_id bigint(20) unsigned NOT NULL,
            product_id bigint(20) unsigned NOT NULL DEFAULT 0,
            variation_id bigint(20) unsigned NOT NULL DEFAULT 0,
            rating tinyint(2) unsigned NOT NULL,
            review text NULL,
            photo_ids text NULL,
            seller_response text NULL,
            seller_response_at datetime NULL,
            status varchar(20) NOT NULL DEFAULT 'approved',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY reviewer_order_product (reviewer_id,order_id,product_id),
            KEY seller_status (seller_id,status),
            KEY product_status (product_id,status)
        ) $charset;";

        // v3.7.101 — saved searches. Each row is one "alert me when new listings
        // match this search" subscription. `query_json` stores the full search
        // payload (text query + category + attribute filters + price range) so
        // the cron can replay it. `last_checked_at` moves forward every run so
        // we only push products created since. `last_matched_product_id` is the
        // high-water mark used to filter out re-notifications for the same item
        // if a seller re-publishes it.
        $queries[] = 'CREATE TABLE ' . tnm_table( 'saved_searches' ) . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            label varchar(200) NOT NULL,
            query_hash char(40) NOT NULL,
            query_json longtext NOT NULL,
            notify tinyint(1) NOT NULL DEFAULT 1,
            last_checked_at datetime NOT NULL,
            last_matched_product_id bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY user_hash (user_id,query_hash),
            KEY notify_checked (notify,last_checked_at)
        ) $charset;";

        global $wpdb;

        // v3.7.122.16 — pending signups.
        // A signup does NOT create a wp_users row anymore. Step 1
        // (/auth/signup/start) stashes the desired credentials + a 6-digit
        // code + a magic-link token here. Step 2 (/auth/signup/verify)
        // consumes the row and creates the real user via wp_create_user.
        // v3.13.0 — Customization requests. Buyer opens a request against a
        // seller's product marked `_mnu_customizable=yes`. Seller can post
        // messages, attach a quote (price + lead time), then buyer accepts and
        // pays through a private one-off SKU (see class-mnu-custom-requests.php).
        // Status flow: open → quoted → accepted → paid → completed
        //                            → declined | withdrawn
        $queries[] = 'CREATE TABLE ' . tnm_table( 'custom_requests' ) . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            buyer_id bigint(20) unsigned NOT NULL,
            seller_id bigint(20) unsigned NOT NULL,
            product_id bigint(20) unsigned NOT NULL DEFAULT 0,
            title varchar(200) NOT NULL,
            description text NOT NULL,
            budget_cents int(11) unsigned NOT NULL DEFAULT 0,
            quantity smallint(5) unsigned NOT NULL DEFAULT 1,
            reference_photo_ids text NULL,
            status varchar(20) NOT NULL DEFAULT 'open',
            quoted_price_cents int(11) unsigned NOT NULL DEFAULT 0,
            quoted_lead_days smallint(5) unsigned NOT NULL DEFAULT 0,
            quoted_at datetime NULL,
            quote_note text NULL,
            decline_reason varchar(500) NULL,
            private_product_id bigint(20) unsigned NOT NULL DEFAULT 0,
            order_id bigint(20) unsigned NOT NULL DEFAULT 0,
            last_activity_at datetime NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY buyer_status (buyer_id,status,last_activity_at),
            KEY seller_status (seller_id,status,last_activity_at),
            KEY product_id (product_id),
            KEY private_product_id (private_product_id),
            KEY order_id (order_id)
        ) $charset;";

        // v3.13.0 — thread of messages per custom request. Kept separate from
        // the omnibus messages table so we can enforce request-scoped
        // permissions without leaking DM history. `kind` distinguishes plain
        // buyer/seller chat from system events ("seller quoted", "buyer
        // accepted") so the UI can render them differently.
        $queries[] = 'CREATE TABLE ' . tnm_table( 'custom_request_messages' ) . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            request_id bigint(20) unsigned NOT NULL,
            sender_id bigint(20) unsigned NOT NULL,
            kind varchar(20) NOT NULL DEFAULT 'message',
            body text NOT NULL,
            photo_attachments text NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY request_created (request_id,created_at)
        ) $charset;";

        // v3.13.2 — Abandoned-cart snapshots. One row per buyer whose cart
        // has been modified but not checked out. Updated on every cart
        // mutation, cleared on order placement. A daily WP-Cron sweep
        // emails a single reminder for rows older than 24h that haven't
        // been reminded or dismissed yet.
        $queries[] = 'CREATE TABLE ' . tnm_table( 'abandoned_carts' ) . " (
            user_id bigint(20) unsigned NOT NULL,
            line_count smallint(5) unsigned NOT NULL DEFAULT 0,
            total_cents int(11) unsigned NOT NULL DEFAULT 0,
            items_json longtext NOT NULL,
            updated_at datetime NOT NULL,
            reminded_at datetime NULL,
            dismissed_at datetime NULL,
            PRIMARY KEY  (user_id),
            KEY sweep_idx (reminded_at,dismissed_at,updated_at)
        ) $charset;";

        // Rows automatically expire after 24h; a daily cron purges them.
        $queries[] = 'CREATE TABLE ' . tnm_table( 'pending_signups' ) . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            email varchar(190) NOT NULL,
            username varchar(60) NOT NULL,
            display_name varchar(190) NOT NULL DEFAULT '',
            password_hash varchar(255) NOT NULL,
            code varchar(6) NOT NULL,
            token varchar(64) NOT NULL,
            attempts smallint(5) unsigned NOT NULL DEFAULT 0,
            last_sent_at int(11) unsigned NOT NULL DEFAULT 0,
            created_at int(11) unsigned NOT NULL,
            expires_at int(11) unsigned NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY email (email),
            UNIQUE KEY username (username),
            KEY token (token),
            KEY expires_at (expires_at)
        ) $charset;";

        $queries[] = 'CREATE TABLE ' . $wpdb->prefix . "mnu_import_jobs (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            seller_id bigint(20) unsigned NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'ready',
            total_rows int(11) unsigned NOT NULL DEFAULT 0,
            processed int(11) unsigned NOT NULL DEFAULT 0,
            created int(11) unsigned NOT NULL DEFAULT 0,
            updated int(11) unsigned NOT NULL DEFAULT 0,
            failed int(11) unsigned NOT NULL DEFAULT 0,
            columns_json longtext NULL,
            rows_json longtext NULL,
            errors_json longtext NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY seller_status (seller_id,status)
        ) $charset;";

        foreach ( $queries as $query ) {
            dbDelta( $query );
        }

        // dbDelta adds columns and indexes but deliberately does not remove an
        // obsolete index. Drop the pre-v3.12 seller/order uniqueness rule after
        // every install/upgrade, then let the CREATE statement above add the
        // product-aware key idempotently.
        $review_indexes = $wpdb->get_results( 'SHOW INDEX FROM ' . tnm_table( 'reviews' ), ARRAY_A );
        foreach ( (array) $review_indexes as $index ) {
            if ( 'reviewer_seller_order' === (string) ( $index['Key_name'] ?? '' ) ) {
                $wpdb->query( 'ALTER TABLE ' . tnm_table( 'reviews' ) . ' DROP INDEX reviewer_seller_order' );
                break;
            }
        }
    }

    public static function create_pages(): void {
        $pages = array(
            'feed' => array( 'title' => 'Nest Feed', 'slug' => 'nest-feed', 'content' => '[the_nest_feed]' ),
            'browse' => array( 'title' => 'Browse', 'slug' => 'browse', 'content' => '[mynest_browse]' ),
            'create' => array( 'title' => 'Create', 'slug' => 'create', 'content' => '[mynest_create_hub]' ),
            'notifications' => array( 'title' => 'Notifications', 'slug' => 'notifications', 'content' => '[mynest_notifications]' ),
            'profile' => array( 'title' => 'Profile', 'slug' => 'profile', 'content' => '[mynest_profile]' ),
            'become_seller' => array( 'title' => 'Become a Seller', 'slug' => 'become-a-seller', 'content' => '[mynest_become_seller]' ),
            'seller_application' => array( 'title' => 'Seller Application', 'slug' => 'seller-application', 'content' => '[mynest_seller_application]' ),
            'seller_login' => array( 'title' => 'Seller Login', 'slug' => 'seller-login', 'content' => '[mynest_seller_login]' ),
            'seller_dashboard' => array( 'title' => 'Seller Dashboard', 'slug' => 'seller-dashboard', 'content' => '[the_nest_seller_dashboard]' ),
            'seller_orders' => array( 'title' => 'Seller Orders', 'slug' => 'seller-orders', 'content' => '[mynest_seller_orders]' ),
            'seller_order' => array( 'title' => 'Seller Order', 'slug' => 'seller-order', 'content' => '[mynest_seller_order]' ),
            'seller_add_product' => array( 'title' => 'Add Product', 'slug' => 'seller-add-product', 'content' => '[mynest_seller_add_product]' ),
            'create_blog' => array( 'title' => 'Create Blog Post', 'slug' => 'create-blog', 'content' => '[mynest_create_blog]' ),
            'my_purchases' => array( 'title' => 'My Purchases', 'slug' => 'my-purchases', 'content' => '[mynest_my_purchases]' ),
            'reviews' => array( 'title' => 'Reviews', 'slug' => 'reviews', 'content' => '[mynest_reviews]' ),
            'seller_payouts' => array( 'title' => 'Seller Earnings', 'slug' => 'seller-payouts', 'content' => '[mynest_seller_payouts]' ),
            'shop' => array( 'title' => 'Shop', 'slug' => 'shop', 'content' => '' ),
            'cart' => array( 'title' => 'Cart', 'slug' => 'cart', 'content' => '[woocommerce_cart]' ),
            'checkout' => array( 'title' => 'Checkout', 'slug' => 'checkout', 'content' => '[woocommerce_checkout]' ),
            'my_account' => array( 'title' => 'My Account', 'slug' => 'my-account', 'content' => '[woocommerce_my_account]' ),
            'seller_terms' => array( 'title' => 'Seller Terms', 'slug' => 'seller-terms', 'content' => '<h2>MyNest Seller Terms</h2><p>Replace this starter text with your reviewed seller agreement before launch.</p>' ),
            // v3.7.94 — point at the short canonical slugs owned by
            // shopmynest-legal-pages. When that plugin is active, get_page_by_path
            // will resolve these to the real editable pages instead of creating
            // duplicate placeholder posts. When it isn't, wp_insert_post will
            // still create a starter page at the short slug so the URL is stable.
            'privacy_policy' => array( 'title' => 'Privacy Policy', 'slug' => 'privacy', 'content' => '<h2>Privacy Policy</h2><p>Replace this starter text with a privacy policy reviewed for your marketplace, mobile app, payment processors, and jurisdiction.</p>' ),
            'terms' => array( 'title' => 'Terms of Service', 'slug' => 'terms', 'content' => '<h2>Terms of Service</h2><p>Replace this starter text with your reviewed marketplace terms before launch.</p>' ),
            'refund_policy' => array( 'title' => 'Refund Policy', 'slug' => 'refunds', 'content' => '<h2>Refund Policy</h2><p>Replace this starter text with your reviewed refund and dispute policy before launch.</p>' ),
            'shipping_policy' => array( 'title' => 'Shipping Policy', 'slug' => 'shipping', 'content' => '<h2>Shipping Policy</h2><p>Replace this starter text with your reviewed shipping policy before launch.</p>' ),
            'data_deletion' => array( 'title' => 'Account & Data Deletion', 'slug' => 'data-deletion', 'content' => '<h2>Account & Data Deletion</h2><p>Contact help@shopmynest.com to request account and data deletion.</p>' ),
        );

        foreach ( $pages as $key => $page ) {
            $existing = (int) get_option( 'tnm_page_' . $key, 0 );
            if ( $existing && 'trash' !== get_post_status( $existing ) ) {
                continue;
            }

            $found = get_page_by_path( $page['slug'] );
            if ( $found ) {
                update_option( 'tnm_page_' . $key, (int) $found->ID, false );
                continue;
            }

            $page_id = wp_insert_post(
                array(
                    'post_title'   => $page['title'],
                    'post_name'    => $page['slug'],
                    'post_content' => $page['content'],
                    'post_status'  => 'publish',
                    'post_type'    => 'page',
                ),
                true
            );
            if ( ! is_wp_error( $page_id ) ) {
                update_option( 'tnm_page_' . $key, (int) $page_id, false );
            }
        }

        $woocommerce_pages = array(
            'woocommerce_shop_page_id'      => 'shop',
            'woocommerce_cart_page_id'      => 'cart',
            'woocommerce_checkout_page_id'  => 'checkout',
            'woocommerce_myaccount_page_id' => 'my_account',
        );
        foreach ( $woocommerce_pages as $option => $key ) {
            if ( ! (int) get_option( $option, 0 ) ) {
                update_option( $option, (int) get_option( 'tnm_page_' . $key, 0 ), false );
            }
        }

        if ( ! (int) get_option( 'wp_page_for_privacy_policy', 0 ) ) {
            update_option( 'wp_page_for_privacy_policy', (int) get_option( 'tnm_page_privacy_policy', 0 ), false );
        }
    }
}
