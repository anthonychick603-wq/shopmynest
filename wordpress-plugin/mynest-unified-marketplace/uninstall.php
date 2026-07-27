<?php
/**
 * MyNest marketplace data is preserved by default.
 *
 * Permanent removal only runs when TNM_REMOVE_DATA_ON_UNINSTALL is deliberately
 * defined as true before the plugin is deleted.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! defined( 'TNM_REMOVE_DATA_ON_UNINSTALL' ) || true !== TNM_REMOVE_DATA_ON_UNINSTALL ) {
    return;
}

global $wpdb;

wp_clear_scheduled_hook( 'tnm_daily_maintenance' );

foreach ( array( 'ledger', 'payouts', 'follows', 'notifications', 'messages', 'reviews' ) as $table ) {
    $table_name = $wpdb->prefix . 'tnm_' . $table;
    $wpdb->query( "DROP TABLE IF EXISTS `{$table_name}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is generated from a fixed allowlist.
}

$options = array(
    'tnm_settings',
    'tnm_db_version',
    'tnm_last_auto_payout_run',
    'mnu_version',
    'mnu_db_version',
    'mnu_migration_version',
    'mnu_last_successful_boot',
    'thenest_native_checkout_settings',
    'thenest_shipping_labels_settings',
    'thenest_google_places_api_key',
    'thenest_shippo_api_token',
    'thenest_label_mode',
);

$page_keys = array(
    'feed',
    'browse',
    'create',
    'notifications',
    'profile',
    'become_seller',
    'seller_application',
    'seller_login',
    'seller_dashboard',
    'seller_orders',
    'seller_order',
    'seller_add_product',
    'create_blog',
    'my_purchases',
    'reviews',
    'seller_payouts',
    'shop',
    'cart',
    'checkout',
    'my_account',
    'seller_terms',
    'privacy_policy',
    'terms',
    'refund_policy',
);

foreach ( $page_keys as $page_key ) {
    $options[] = 'tnm_page_' . $page_key;
}
foreach ( $options as $option ) {
    delete_option( $option );
}

foreach ( array( 'administrator', 'shop_manager' ) as $role_name ) {
    $role = get_role( $role_name );
    if ( ! $role ) {
        continue;
    }
    foreach ( array( 'tnm_manage_marketplace', 'tnm_manage_store', 'tnm_view_earnings', 'tnm_request_payout', 'mnu_manage_marketplace' ) as $capability ) {
        $role->remove_cap( $capability );
    }
}

remove_role( 'tnm_seller' );
remove_role( 'mynest_seller' );
