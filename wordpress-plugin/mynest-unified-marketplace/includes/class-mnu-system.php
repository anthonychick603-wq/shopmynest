<?php

defined( 'ABSPATH' ) || exit;

final class MNU_System {
    private static bool $full_mode = false;
    private static bool $backend_conflict = false;

    public static function init( bool $full_mode, bool $backend_conflict = false ): void {
        self::$full_mode       = $full_mode;
        self::$backend_conflict = $backend_conflict;
        add_action( 'admin_menu', array( __CLASS__, 'menu' ), 30 );
        add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
        add_action( 'admin_post_mnu_run_migration', array( __CLASS__, 'run_migration' ) );
        add_action( 'admin_post_mnu_repair_roles', array( __CLASS__, 'repair_roles' ) );
    }

    public static function menu(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
            return;
        }
        add_submenu_page(
            'tnm-marketplace',
            'MyNest System Health',
            'System Health',
            'manage_woocommerce',
            'mnu-system-health',
            array( __CLASS__, 'page' )
        );
    }

    public static function notices(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
            return;
        }
        if ( self::$backend_conflict ) {
            echo '<div class="notice notice-error"><p><strong>MyNest Unified Marketplace:</strong> Another ' . esc_html( get_bloginfo( 'name' ) ) . ' Marketplace backend is active. Deactivate the older backend before using the unified marketplace engine.</p></div>';
            return;
        }
    }

    public static function page(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to view this page.', 'mynest-unified-marketplace' ) );
        }

        $current = wp_get_current_user();
        $checks  = self::checks();
        ?>
        <div class="wrap">
            <h1>MyNest Unified Marketplace — System Health</h1>
            <p>This screen verifies the single MyNest marketplace engine, its data, pages, integrations, and mobile APIs.</p>

            <?php if ( self::$backend_conflict ) : ?>
                <div class="notice notice-error inline"><p><strong>Backend conflict:</strong> an older custom marketplace backend is active. Deactivate that old custom plugin and reload this page.</p></div>
            <?php else : ?>
                <div class="notice notice-success inline"><p><strong>MyNest 3.0 is active.</strong> One plugin is handling seller tools, fees, orders, payouts, social features, shipping, native checkout, and app APIs.</p></div>
            <?php endif; ?>

            <table class="widefat striped" style="max-width:1100px;margin-top:20px">
                <thead><tr><th>Check</th><th>Status</th><th>Details</th></tr></thead>
                <tbody>
                <?php foreach ( $checks as $check ) : ?>
                    <tr><td><strong><?php echo esc_html( $check['label'] ); ?></strong></td><td><?php echo $check['ok'] ? '<span style="color:#147a3f;font-weight:700">Good</span>' : '<span style="color:#b42318;font-weight:700">Needs attention</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td><td><?php echo esc_html( $check['detail'] ); ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <h2>Safe maintenance actions</h2>
            <div style="display:flex;gap:12px;flex-wrap:wrap">
                <a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mnu_run_migration' ), 'mnu_run_migration' ) ); ?>">Re-run legacy data migration</a>
                <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mnu_repair_roles' ), 'mnu_repair_roles' ) ); ?>">Repair marketplace roles and capabilities</a>
            </div>
            <p class="description">These actions do not delete products, orders, customers, media, or legacy records. The plugin only adds seller roles; it never replaces an administrator role.</p>

            <h2>Mobile API base URLs</h2>
            <ul>
                <li><code><?php echo esc_html( rest_url( 'the-nest/v1/' ) ); ?></code> — marketplace, auth, products, sellers, social, orders, ledger, payouts</li>
                <li><code><?php echo esc_html( rest_url( 'nest-ops/v1/' ) ); ?></code> — addresses, push tokens, shipping operations, account photo</li>
                <li><code><?php echo esc_html( rest_url( 'nest-native/v1/' ) ); ?></code> — native checkout</li>
                <li><code><?php echo esc_html( rest_url( 'nest-labels/v1/' ) ); ?></code> — Shippo rates and labels</li>
                <li><code><?php echo esc_html( rest_url( 'nest-shipping/v1/' ) ); ?></code> — seller and product shipping profiles</li>
            </ul>
        </div>
        <?php
    }

    private static function checks(): array {
        global $wpdb;
        $tables = array( 'ledger', 'payouts', 'follows', 'notifications', 'messages', 'reviews' );
        $missing_tables = array();
        foreach ( $tables as $table ) {
            $name = tnm_table( $table );
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) ) !== $name ) {
                $missing_tables[] = $table;
            }
        }

        $pages = array( 'browse', 'notifications', 'profile', 'seller_dashboard', 'seller_application', 'seller_orders', 'seller_add_product', 'my_purchases', 'reviews', 'seller_payouts', 'shop', 'cart', 'checkout', 'my_account', 'seller_terms', 'privacy_policy', 'terms', 'refund_policy' );
        $missing_pages = array();
        foreach ( $pages as $page ) {
            $id = (int) get_option( 'tnm_page_' . $page, 0 );
            if ( ! $id || ! get_post( $id ) ) {
                $missing_pages[] = $page;
            }
        }

        $settings = function_exists( 'mnu_native_get_settings' ) ? mnu_native_get_settings() : array();
        $shippo   = function_exists( 'mnu_labels_settings' ) ? mnu_labels_settings() : array();
        return array(
            array( 'label' => 'WooCommerce', 'ok' => class_exists( 'WooCommerce' ), 'detail' => class_exists( 'WooCommerce' ) ? 'Active' : 'WooCommerce must be active.' ),
            array( 'label' => 'Marketplace engine', 'ok' => self::$full_mode && ! self::$backend_conflict, 'detail' => self::$backend_conflict ? 'Older backend conflict detected.' : 'MyNest Unified Marketplace ' . MNU_VERSION ),
            array( 'label' => 'Marketplace tables', 'ok' => ! $missing_tables, 'detail' => $missing_tables ? 'Missing: ' . implode( ', ', $missing_tables ) : 'All six data tables exist.' ),
            array( 'label' => 'Marketplace pages', 'ok' => ! $missing_pages, 'detail' => $missing_pages ? 'Missing: ' . implode( ', ', $missing_pages ) : 'Required pages are present.' ),
            array( 'label' => 'Seller roles', 'ok' => (bool) get_role( 'tnm_seller' ) && (bool) get_role( 'mynest_seller' ), 'detail' => 'Legacy and unified seller roles are supported without replacing administrator roles.' ),
            array( 'label' => 'Native Stripe checkout', 'ok' => ! empty( $settings['publishable_key'] ) && ! empty( $settings['secret_key'] ), 'detail' => ! empty( $settings['publishable_key'] ) && ! empty( $settings['secret_key'] ) ? 'Stripe keys are configured.' : sprintf( 'Configure keys under %s → Native Checkout, or use the active WooCommerce Stripe settings.', get_bloginfo( 'name' ) ) ),
            array( 'label' => 'Shippo labels', 'ok' => ! empty( $shippo['shippo_token'] ), 'detail' => ! empty( $shippo['shippo_token'] ) ? 'Shippo token is configured.' : sprintf( 'Configure Shippo under %s → Shipping Labels or Operations.', get_bloginfo( 'name' ) ) ),
            array( 'label' => 'Automatic payouts', 'ok' => 'yes' !== tnm_get_option( 'automatic_payouts', 'no' ), 'detail' => 'yes' === tnm_get_option( 'automatic_payouts', 'no' ) ? 'Enabled — verify sandbox and payout controls before live use.' : 'Disabled, which is safest during cutover.' ),
        );
    }

    public static function run_migration(): void {
        self::verify_action( 'mnu_run_migration' );
        delete_option( 'mnu_migration_version' );
        MNU_Compat::migrate_legacy_data();
        wp_safe_redirect( admin_url( 'admin.php?page=mnu-system-health&migrated=1' ) );
        exit;
    }

    public static function repair_roles(): void {
        self::verify_action( 'mnu_repair_roles' );
        MNU_Install::create_roles();
        wp_safe_redirect( admin_url( 'admin.php?page=mnu-system-health&roles=1' ) );
        exit;
    }


    private static function verify_action( string $nonce ): void {
        if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to perform this action.', 'mynest-unified-marketplace' ) );
        }
        check_admin_referer( $nonce );
    }
}
