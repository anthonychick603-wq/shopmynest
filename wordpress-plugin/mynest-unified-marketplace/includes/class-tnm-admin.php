<?php

defined( 'ABSPATH' ) || exit;

final class TNM_Admin {
    public static function init(): void {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ), 5 );
        add_action( 'admin_post_tnm_save_settings', array( __CLASS__, 'save_settings' ) );
        add_action( 'admin_post_tnm_platform_payout', array( __CLASS__, 'platform_payout' ) );
        add_filter( 'plugin_action_links_' . plugin_basename( TNM_FILE ), array( __CLASS__, 'action_links' ) );
    }

    public static function menu(): void {
        add_menu_page( get_bloginfo( 'name' ) . ' Marketplace', get_bloginfo( 'name' ), 'manage_woocommerce', 'tnm-marketplace', array( __CLASS__, 'dashboard' ), 'dashicons-store', 56 );
        add_submenu_page( 'tnm-marketplace', 'Marketplace Dashboard', 'Dashboard', 'manage_woocommerce', 'tnm-marketplace', array( __CLASS__, 'dashboard' ) );
        add_submenu_page( 'tnm-marketplace', 'Payouts', 'Payouts', 'manage_woocommerce', 'tnm-payouts', array( __CLASS__, 'payouts' ) );
        add_submenu_page( 'tnm-marketplace', 'Settings', 'Settings', 'manage_woocommerce', 'tnm-settings', array( __CLASS__, 'settings' ) );
        add_submenu_page( 'tnm-marketplace', 'App API', 'App API', 'manage_woocommerce', 'tnm-api', array( __CLASS__, 'api' ) );
    }

    public static function action_links( array $links ): array {
        array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=tnm-settings' ) ) . '">Settings</a>' );
        return $links;
    }

    public static function dashboard(): void {
        global $wpdb;
        $seller_count      = count( array_unique( get_users( array( 'role__in' => array( 'tnm_seller', 'mynest_seller' ), 'fields' => 'ID' ) ) ) );
        $pending_apps      = (int) wp_count_posts( 'tnm_application' )->pending;
        $requested_payouts = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . tnm_table( 'payouts' ) . " WHERE status IN ('requested','processing')" );
        $available_total   = (float) $wpdb->get_var( "SELECT COALESCE(SUM(net),0) FROM " . tnm_table( 'ledger' ) . " WHERE status='available'" );

        $platform     = TNM_Ledger::platform_balances();
        $withdrawable = TNM_Ledger::withdrawable_platform_fees();
        $fee_currency = $withdrawable['currency'];
        $minimum      = max( 0, (float) tnm_get_option( 'minimum_payout', 25 ) );
        $can_withdraw = $withdrawable['amount'] >= $minimum && $withdrawable['amount'] > 0;
        $notice       = get_transient( 'tnm_platform_payout_notice_' . get_current_user_id() );
        if ( $notice ) {
            delete_transient( 'tnm_platform_payout_notice_' . get_current_user_id() );
        }
        ?>
        <div class="wrap">
            <h1>The Nest Marketplace</h1>
            <p>Marketplace operations, seller activity, earnings, and mobile app integration.</p>
            <?php if ( is_array( $notice ) && ! empty( $notice['message'] ) ) : ?>
                <div class="notice notice-<?php echo 'success' === ( $notice['type'] ?? '' ) ? 'success' : 'error'; ?> is-dismissible"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
            <?php endif; ?>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;max-width:1000px;margin:24px 0;">
                <?php self::metric( 'Approved sellers', (string) $seller_count ); ?>
                <?php self::metric( 'Pending applications', (string) $pending_apps ); ?>
                <?php self::metric( 'Open payouts', (string) $requested_payouts ); ?>
                <?php self::metric( 'Seller funds available', tnm_money( $available_total ) ); ?>
            </div>

            <h2 style="max-width:1000px">Platform Fee Revenue</h2>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;max-width:1000px;margin:12px 0;">
                <?php self::metric( 'Fees pending', tnm_money( $platform['pending'], $fee_currency ) ); ?>
                <?php self::metric( 'Fees available', tnm_money( $platform['available'], $fee_currency ) ); ?>
                <?php self::metric( 'Fees paid', tnm_money( $platform['paid'], $fee_currency ) ); ?>
            </div>
            <div class="card" style="max-width:1000px">
                <h2>Withdraw platform fees to your bank</h2>
                <p>Accumulated platform fee revenue can be paid from your Stripe balance to your connected bank account. Available to withdraw now: <strong><?php echo esc_html( tnm_money( $withdrawable['amount'], $fee_currency ) ); ?></strong>.</p>
                <p class="description">This moves <strong>real money</strong>. The exact amount sent is capped at your live Stripe available balance — recent charges stay pending in Stripe for about two days before they can be paid out, so the final payout may be smaller than the figure above. Minimum withdrawal: <?php echo esc_html( tnm_money( $minimum, $fee_currency ) ); ?>.</p>
                <?php if ( $can_withdraw ) : ?>
                    <form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" onsubmit="return confirm('This will withdraw up to <?php echo esc_js( tnm_money( $withdrawable['amount'], $fee_currency ) ); ?> of platform fee revenue from your Stripe balance to your bank account. This moves real money. Continue?');">
                        <?php wp_nonce_field( 'tnm_platform_payout' ); ?>
                        <input type="hidden" name="action" value="tnm_platform_payout">
                        <?php submit_button( sprintf( 'Withdraw %s to bank via Stripe', tnm_money( $withdrawable['amount'], $fee_currency ) ), 'primary', 'submit', false ); ?>
                    </form>
                <?php else : ?>
                    <p><em><?php echo esc_html( $withdrawable['amount'] > 0 ? sprintf( 'The available amount is below the %s minimum withdrawal.', tnm_money( $minimum, $fee_currency ) ) : 'No platform fees are available to withdraw yet.' ); ?></em></p>
                <?php endif; ?>
                <?php self::platform_payout_log(); ?>
            </div>
            <div class="card" style="max-width:1000px">
                <h2>Launch checklist</h2>
                <ol>
                    <li>Review and replace the generated Terms, Seller Terms, Privacy Policy, and Refund Policy pages.</li>
                    <li>Set your platform fee, holding period, and payout minimum under <a href="<?php echo esc_url( admin_url( 'admin.php?page=tnm-settings' ) ); ?>">Settings</a>.</li>
                    <li>Test checkout, refunds, seller order updates, and payouts on a staging site.</li>
                    <li>Connect the mobile app to <code><?php echo esc_html( rest_url( 'the-nest/v1/' ) ); ?></code>.</li>
                    <li>Use PayPal sandbox until payout testing is complete.</li>
                </ol>
            </div>
        </div>
        <?php
    }

    private static function metric( string $label, string $value ): void {
        echo '<div class="card" style="margin:0"><div style="font-size:13px;color:#646970">' . esc_html( $label ) . '</div><div style="font-size:28px;font-weight:700;margin-top:6px">' . esc_html( $value ) . '</div></div>';
    }

    public static function settings(): void {
        $settings = get_option( 'tnm_settings', array() );
        ?>
        <div class="wrap">
            <h1>The Nest Settings</h1>
            <?php if ( isset( $_GET['updated'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Settings saved.</p></div><?php endif; ?>
            <form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
                <?php wp_nonce_field( 'tnm_save_settings' ); ?>
                <input type="hidden" name="action" value="tnm_save_settings">
                <h2>Marketplace</h2>
                <table class="form-table" role="presentation">
                    <?php self::number_row( 'Platform fee percentage', 'fee_percent', $settings, 8, '0.01', '0', '100', 'Applied to each product line after discounts, excluding tax and shipping.' ); ?>
                    <?php self::text_row( 'Fee label', 'fee_label', $settings, 'Nest Service Fee', 'Shown in seller transaction breakdowns.' ); ?>
                    <?php self::number_row( 'Holding period (days)', 'holding_days', $settings, 7, '1', '0', '180', 'Completed-order earnings become available after this period.' ); ?>
                    <?php self::number_row( 'Minimum payout', 'minimum_payout', $settings, 25, '0.01', '0', '', 'A seller cannot request less than this available balance.' ); ?>
                    <?php self::select_row( 'Seller product publishing', 'seller_can_publish', $settings, array( 'yes' => 'Publish immediately', 'no' => 'Require admin review' ), 'yes' ); ?>
                    <?php self::select_row( 'Verified seller reviews', 'verified_reviews_only', $settings, array( 'yes' => 'Purchased customers only', 'no' => 'Allow any logged-in buyer' ), 'yes' ); ?>
                    <?php self::select_row( 'Buyer registration', 'allow_buyer_registration', $settings, array( 'yes' => 'Enabled', 'no' => 'Disabled' ), 'yes' ); ?>
                    <?php self::number_row( 'App token lifetime (days)', 'token_lifetime_days', $settings, 30, '1', '1', '365', 'Mobile bearer tokens expire after this many days.' ); ?>
                    <?php self::select_row( 'Buyer fee disclosure', 'buyer_sees_seller_fee', $settings, array( 'no' => 'Keep seller fee private', 'yes' => 'Disclose seller fee on buyer order details' ), 'no' ); ?>
                    <?php self::select_row( 'Detailed order emails', 'order_breakdown_emails', $settings, array( 'yes' => 'Enabled', 'no' => 'Disabled' ), 'yes' ); ?>
                    <?php self::select_row( 'Seller new-order emails', 'seller_order_emails', $settings, array( 'yes' => 'Enabled', 'no' => 'Disabled' ), 'yes' ); ?>
                    <?php self::select_row( 'Local pickup', 'remove_local_pickup', $settings, array( 'yes' => 'Remove local pickup rates', 'no' => 'Allow local pickup rates' ), 'yes' ); ?>
                </table>

                <h2>Shipping &amp; Shippo</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="thenest_shippo_api_token">Shippo API token</label></th>
                        <td>
                            <input type="password" class="regular-text" id="thenest_shippo_api_token" name="thenest_shippo_api_token" value="" autocomplete="new-password" placeholder="Leave blank to keep the saved token">
                            <p class="description"><?php echo get_option( 'thenest_shippo_api_token', '' ) ? 'A Shippo token is configured.' : 'No Shippo token is configured. Start with a token beginning with shippo_test_.'; ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="thenest_shippo_test_mode">Shippo mode</label></th>
                        <td>
                            <?php $shippo_settings = function_exists( 'mnu_labels_settings' ) ? mnu_labels_settings() : array( 'test_mode' => 1 ); ?>
                            <select id="thenest_shippo_test_mode" name="thenest_shippo_test_mode">
                                <option value="1" <?php selected( ! empty( $shippo_settings['test_mode'] ) ); ?>>Test mode — block live-token purchases</option>
                                <option value="0" <?php selected( empty( $shippo_settings['test_mode'] ) ); ?>>Live mode — allow real postage purchases</option>
                            </select>
                            <p class="description">Keep test mode selected until rates and labels have been fully tested.</p>
                        </td>
                    </tr>
                </table>

                <h2>Payouts</h2>
                <table class="form-table" role="presentation">
                    <?php self::select_row( 'Default payout method', 'payout_method', $settings, array( 'manual' => 'Manual', 'paypal' => 'PayPal Payouts' ), 'manual' ); ?>
                    <?php self::select_row( 'Automatic payouts', 'automatic_payouts', $settings, array( 'no' => 'Disabled', 'yes' => 'Enabled' ), 'no' ); ?>
                    <?php self::select_row( 'Automatic payout frequency', 'payout_schedule', $settings, array( 'weekly' => 'Weekly', 'biweekly' => 'Every two weeks', 'monthly' => 'Every 30 days' ), 'weekly' ); ?>
                    <?php self::select_row( 'PayPal environment', 'paypal_environment', $settings, array( 'sandbox' => 'Sandbox', 'live' => 'Live' ), 'sandbox' ); ?>
                    <?php self::text_row( 'PayPal client ID', 'paypal_client_id', $settings, '', 'Use credentials for the selected environment.' ); ?>
                    <tr><th scope="row"><label for="paypal_client_secret">PayPal client secret</label></th><td><input type="password" class="regular-text" id="paypal_client_secret" name="paypal_client_secret" value="" autocomplete="new-password"><p class="description">Leave blank to keep the currently saved secret. Store production secrets in a protected environment whenever possible.</p></td></tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    private static function text_row( string $label, string $key, array $settings, string $default, string $description = '' ): void {
        echo '<tr><th scope="row"><label for="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td><input type="text" class="regular-text" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( (string) ( $settings[ $key ] ?? $default ) ) . '">';
        if ( $description ) {
            echo '<p class="description">' . esc_html( $description ) . '</p>';
        }
        echo '</td></tr>';
    }

    private static function number_row( string $label, string $key, array $settings, float|int $default, string $step, string $min, string $max, string $description = '' ): void {
        echo '<tr><th scope="row"><label for="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td><input type="number" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( (string) ( $settings[ $key ] ?? $default ) ) . '" step="' . esc_attr( $step ) . '" min="' . esc_attr( $min ) . '"' . ( '' !== $max ? ' max="' . esc_attr( $max ) . '"' : '' ) . '>';
        if ( $description ) {
            echo '<p class="description">' . esc_html( $description ) . '</p>';
        }
        echo '</td></tr>';
    }

    private static function select_row( string $label, string $key, array $settings, array $options, string $default ): void {
        $value = (string) ( $settings[ $key ] ?? $default );
        echo '<tr><th scope="row"><label for="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td><select id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '">';
        foreach ( $options as $option_value => $option_label ) {
            echo '<option value="' . esc_attr( $option_value ) . '" ' . selected( $value, $option_value, false ) . '>' . esc_html( $option_label ) . '</option>';
        }
        echo '</select></td></tr>';
    }

    public static function save_settings(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'You do not have permission to save marketplace settings.' );
        }
        check_admin_referer( 'tnm_save_settings' );
        $old = get_option( 'tnm_settings', array() );
        $new = array(
            'fee_percent'              => max( 0, min( 100, (float) ( $_POST['fee_percent'] ?? 8 ) ) ),
            'fee_label'                => sanitize_text_field( wp_unslash( $_POST['fee_label'] ?? 'Nest Service Fee' ) ),
            'holding_days'             => max( 0, min( 180, absint( $_POST['holding_days'] ?? 7 ) ) ),
            'minimum_payout'           => max( 0, (float) ( $_POST['minimum_payout'] ?? 25 ) ),
            'seller_can_publish'       => 'no' === ( $_POST['seller_can_publish'] ?? 'yes' ) ? 'no' : 'yes',
            'verified_reviews_only'    => 'no' === ( $_POST['verified_reviews_only'] ?? 'yes' ) ? 'no' : 'yes',
            'allow_buyer_registration' => 'no' === ( $_POST['allow_buyer_registration'] ?? 'yes' ) ? 'no' : 'yes',
            'token_lifetime_days'      => max( 1, min( 365, absint( $_POST['token_lifetime_days'] ?? 30 ) ) ),
            'buyer_sees_seller_fee'    => 'yes' === ( $_POST['buyer_sees_seller_fee'] ?? 'no' ) ? 'yes' : 'no',
            'order_breakdown_emails'   => 'no' === ( $_POST['order_breakdown_emails'] ?? 'yes' ) ? 'no' : 'yes',
            'seller_order_emails'      => 'no' === ( $_POST['seller_order_emails'] ?? 'yes' ) ? 'no' : 'yes',
            'remove_local_pickup'      => 'no' === ( $_POST['remove_local_pickup'] ?? 'yes' ) ? 'no' : 'yes',
            'payout_method'            => 'paypal' === ( $_POST['payout_method'] ?? 'manual' ) ? 'paypal' : 'manual',
            'automatic_payouts'        => 'yes' === ( $_POST['automatic_payouts'] ?? 'no' ) ? 'yes' : 'no',
            'payout_schedule'          => in_array( $_POST['payout_schedule'] ?? '', array( 'weekly', 'biweekly', 'monthly' ), true ) ? sanitize_key( $_POST['payout_schedule'] ) : 'weekly',
            'paypal_environment'       => 'live' === ( $_POST['paypal_environment'] ?? 'sandbox' ) ? 'live' : 'sandbox',
            'paypal_client_id'         => sanitize_text_field( wp_unslash( $_POST['paypal_client_id'] ?? '' ) ),
            'paypal_client_secret'     => ! empty( $_POST['paypal_client_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['paypal_client_secret'] ) ) : (string) ( $old['paypal_client_secret'] ?? '' ),
            'require_application'      => (string) ( $old['require_application'] ?? 'yes' ),
        );
        update_option( 'tnm_settings', array_merge( is_array( $old ) ? $old : array(), $new ), false );

        $label_settings = (array) get_option( 'thenest_shipping_labels_settings', array() );
        if ( ! empty( $_POST['thenest_shippo_api_token'] ) ) {
            $shippo_token = sanitize_text_field( wp_unslash( $_POST['thenest_shippo_api_token'] ) );
            update_option( 'thenest_shippo_api_token', $shippo_token, false );
            $label_settings['shippo_token'] = $shippo_token;
        }
        $label_settings['test_mode'] = ! empty( $_POST['thenest_shippo_test_mode'] ) ? 1 : 0;
        update_option( 'thenest_shipping_labels_settings', $label_settings, false );
        update_option( 'thenest_label_mode', ! empty( $label_settings['test_mode'] ) ? 'test' : 'live', false );

        wp_safe_redirect( admin_url( 'admin.php?page=tnm-settings&updated=1' ) );
        exit;
    }

    public static function payouts(): void {
        global $wpdb;
        $rows = $wpdb->get_results( 'SELECT * FROM ' . tnm_table( 'payouts' ) . ' ORDER BY requested_at DESC,id DESC LIMIT 500', ARRAY_A );
        ?>
        <div class="wrap"><h1>Seller Payouts</h1><p>PayPal payouts should be tested in sandbox before using live credentials.</p>
        <table class="widefat striped"><thead><tr><th>ID</th><th>Seller</th><th>Amount</th><th>Method</th><th>Destination</th><th>Status</th><th>Requested</th><th>Actions</th></tr></thead><tbody>
        <?php if ( ! $rows ) : ?><tr><td colspan="8">No payouts found.</td></tr><?php endif; ?>
        <?php foreach ( $rows as $row ) :
            $base = admin_url( 'admin-post.php?action=tnm_payout_action&payout_id=' . absint( $row['id'] ) );
            ?>
            <tr><td>#<?php echo esc_html( $row['id'] ); ?></td><td><?php echo esc_html( tnm_seller_display_name( (int) $row['seller_id'] ) ); ?></td><td><?php echo esc_html( tnm_money( (float) $row['amount'], $row['currency'] ) ); ?></td><td><?php echo esc_html( ucfirst( $row['method'] ) ); ?></td><td><?php echo esc_html( $row['destination'] ); ?></td><td><strong><?php echo esc_html( ucfirst( $row['status'] ) ); ?></strong><?php if ( $row['external_id'] ) : ?><br><small><?php echo esc_html( $row['external_id'] ); ?></small><?php endif; ?></td><td><?php echo esc_html( $row['requested_at'] ); ?></td><td>
                <?php if ( 'requested' === $row['status'] && 'paypal' === $row['method'] ) : ?><a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( $base . '&payout_action=process', 'tnm_payout_' . $row['id'] ) ); ?>">Send via PayPal</a><?php endif; ?>
                <?php if ( in_array( $row['status'], array( 'requested', 'processing' ), true ) ) : ?><a class="button" href="<?php echo esc_url( wp_nonce_url( $base . '&payout_action=paid', 'tnm_payout_' . $row['id'] ) ); ?>">Mark paid</a> <a class="button" href="<?php echo esc_url( wp_nonce_url( $base . '&payout_action=cancel', 'tnm_payout_' . $row['id'] ) ); ?>" onclick="return confirm('Cancel this payout and release the reserved balance?')">Cancel</a><?php endif; ?>
            </td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
        <?php
    }

    public static function api(): void {
        ?>
        <div class="wrap"><h1>The Nest App API</h1>
            <p>Base URL: <code><?php echo esc_html( rest_url( 'the-nest/v1/' ) ); ?></code></p>
            <p>The app signs in through <code>POST auth/login</code> and sends the returned token in <code>Authorization: Bearer TOKEN</code>. HTTPS is required for production.</p>
            <table class="widefat striped" style="max-width:1100px"><thead><tr><th>Area</th><th>Endpoints</th></tr></thead><tbody>
                <tr><td>Authentication</td><td><code>auth/register</code>, <code>auth/login</code>, <code>auth/logout</code>, <code>auth/me</code></td></tr>
                <tr><td>Shopping</td><td><code>config</code>, <code>categories</code>, <code>products</code>, <code>products/{id}</code></td></tr>
                <tr><td>Social</td><td><code>feed</code>, <code>posts</code>, <code>sellers/{id}</code>, follows, reviews, notifications, messages</td></tr>
                <tr><td>Seller</td><td><code>seller/application</code>, <code>seller/dashboard</code>, products, orders, earnings, payouts, profile</td></tr>
                <tr><td>Media</td><td><code>POST media</code> using multipart field <code>file</code></td></tr>
            </tbody></table>
            <p>Full endpoint details and request examples are included in <code>docs/API.md</code> inside the plugin package.</p>
        </div>
        <?php
    }

    /**
     * Real bank payout of accumulated platform fee revenue via Stripe.
     *
     * Admin-only, wp-admin-only (admin-post + capability + nonce). Never exposes
     * the Stripe secret key. The amount sent is capped at BOTH the ledger's
     * withdrawable platform-fee total AND Stripe's live available balance,
     * because the Stripe balance is a single pool that also holds money owed to
     * sellers and recent charges that are still pending.
     */
    public static function platform_payout(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'You do not have permission to withdraw platform fees.' );
        }
        check_admin_referer( 'tnm_platform_payout' );

        $uid    = get_current_user_id();
        $notify = static function ( string $type, string $message ) use ( $uid ): void {
            set_transient( 'tnm_platform_payout_notice_' . $uid, array( 'type' => $type, 'message' => $message ), 60 );
            wp_safe_redirect( admin_url( 'admin.php?page=tnm-marketplace' ) );
            exit;
        };

        $withdrawable = TNM_Ledger::withdrawable_platform_fees();
        $available    = (float) $withdrawable['amount'];
        $currency     = (string) $withdrawable['currency'];
        $minimum      = max( 0, (float) tnm_get_option( 'minimum_payout', 25 ) );

        if ( $available <= 0 ) {
            $notify( 'error', 'No platform fees are available to withdraw.' );
        }
        if ( $available < $minimum ) {
            $notify( 'error', sprintf( 'The available amount (%s) is below the %s minimum withdrawal.', tnm_money( $available, $currency ), tnm_money( $minimum, $currency ) ) );
        }
        if ( ! function_exists( 'mnu_native_stripe_get' ) || ! function_exists( 'mnu_native_stripe_request' ) ) {
            $notify( 'error', 'Stripe is not available on this site.' );
        }

        // Cap at Stripe's real available balance for this currency.
        $balance = mnu_native_stripe_get( '/balance' );
        if ( is_wp_error( $balance ) ) {
            $notify( 'error', 'Could not read your Stripe balance: ' . $balance->get_error_message() );
        }
        $stripe_available = 0.0;
        foreach ( (array) ( $balance['available'] ?? array() ) as $entry ) {
            if ( isset( $entry['currency'] ) && strtolower( (string) $entry['currency'] ) === strtolower( $currency ) ) {
                $stripe_available += (float) ( $entry['amount'] ?? 0 ) / 100;
            }
        }

        $amount = min( $available, $stripe_available );
        $amount = round( $amount, wc_get_price_decimals() );
        if ( $amount <= 0 ) {
            $notify( 'error', sprintf( 'Your Stripe available balance for %s is %s. Recent charges stay pending in Stripe for about two days before they can be paid out. Try again once funds settle.', strtoupper( $currency ), tnm_money( $stripe_available, $currency ) ) );
        }
        if ( $amount < $minimum ) {
            $notify( 'error', sprintf( 'Only %s can be paid out from Stripe right now (live available balance), which is below the %s minimum. Try again once more charges settle.', tnm_money( $amount, $currency ), tnm_money( $minimum, $currency ) ) );
        }

        $cents  = (int) round( $amount * 100 );
        $payout = mnu_native_stripe_request(
            '/payouts',
            array(
                'amount'                    => $cents,
                'currency'                  => strtolower( $currency ),
                'description'               => 'MyNest platform fee withdrawal',
                'metadata[source]'          => 'mnu_platform_fee_withdrawal',
                'metadata[initiated_by]'    => (string) $uid,
            ),
            'tnm_platform_payout_' . gmdate( 'YmdHi' ) . '_' . $cents
        );
        if ( is_wp_error( $payout ) ) {
            $notify( 'error', 'Stripe payout failed: ' . $payout->get_error_message() );
        }

        $payout_id = sanitize_text_field( (string) ( $payout['id'] ?? '' ) );
        $paid      = isset( $payout['amount'] ) ? (float) $payout['amount'] / 100 : $amount;
        if ( '' === $payout_id ) {
            $notify( 'error', 'Stripe did not return a payout id. No ledger rows were marked paid; please check your Stripe dashboard before retrying.' );
        }

        TNM_Ledger::mark_platform_fees_paid( $payout_id, $paid );
        self::log_platform_payout( $payout_id, $paid, $currency, $uid );

        $notify( 'success', sprintf( 'Withdrawal of %s to your bank has been initiated via Stripe (payout %s).', tnm_money( $paid, $currency ), $payout_id ) );
    }

    /**
     * Render the recent platform-fee withdrawal history.
     */
    private static function platform_payout_log(): void {
        $log = get_option( 'tnm_platform_payouts_log', array() );
        if ( ! is_array( $log ) || ! $log ) {
            return;
        }
        echo '<h3 style="margin-top:20px">Recent platform withdrawals</h3>';
        echo '<table class="widefat striped" style="max-width:1000px"><thead><tr><th>Date</th><th>Amount</th><th>Stripe payout</th></tr></thead><tbody>';
        foreach ( array_slice( $log, 0, 20 ) as $entry ) {
            echo '<tr><td>' . esc_html( (string) ( $entry['date'] ?? '' ) ) . '</td><td>' . esc_html( tnm_money( (float) ( $entry['amount'] ?? 0 ), (string) ( $entry['currency'] ?? '' ) ) ) . '</td><td><code>' . esc_html( (string) ( $entry['payout_id'] ?? '' ) ) . '</code></td></tr>';
        }
        echo '</tbody></table>';
    }

    /**
     * Append a withdrawal to the capped log option.
     */
    private static function log_platform_payout( string $payout_id, float $amount, string $currency, int $user_id ): void {
        $log = get_option( 'tnm_platform_payouts_log', array() );
        if ( ! is_array( $log ) ) {
            $log = array();
        }
        array_unshift(
            $log,
            array(
                'date'      => current_time( 'mysql' ),
                'amount'    => round( $amount, wc_get_price_decimals() ),
                'currency'  => $currency,
                'payout_id' => $payout_id,
                'user_id'   => $user_id,
            )
        );
        update_option( 'tnm_platform_payouts_log', array_slice( $log, 0, 100 ), false );
    }
}
