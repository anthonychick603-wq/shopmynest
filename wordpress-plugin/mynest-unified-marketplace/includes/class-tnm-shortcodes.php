<?php

defined( 'ABSPATH' ) || exit;

final class TNM_Shortcodes {
    public static function init(): void {
        add_shortcode( 'the_nest_seller_application', array( __CLASS__, 'application' ) );
        add_shortcode( 'the_nest_seller_dashboard', array( __CLASS__, 'dashboard' ) );
        add_shortcode( 'the_nest_feed', array( __CLASS__, 'feed' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
    }

    public static function register_assets(): void {
        wp_register_style( 'tnm-frontend', TNM_URL . 'assets/css/frontend.css', array(), TNM_VERSION );
        wp_register_script( 'tnm-frontend', TNM_URL . 'assets/js/frontend.js', array(), TNM_VERSION, true );
    }

    private static function assets(): void {
        static $localized = false;

        wp_enqueue_style( 'tnm-frontend' );
        wp_enqueue_style( 'dashicons' );
        wp_enqueue_script( 'tnm-frontend' );

        if ( ! $localized ) {
            $settings = function_exists( 'mnu_labels_settings' ) ? mnu_labels_settings() : array( 'shippo_token' => '', 'test_mode' => 1 );
            wp_localize_script(
                'tnm-frontend',
                'TNMFrontend',
                array(
                    'restRoot'          => trailingslashit( rest_url() ),
                    'restNonce'         => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
                    'shippoConfigured'  => ! empty( $settings['shippo_token'] ),
                    'shippoTestMode'    => ! empty( $settings['test_mode'] ),
                    'currencySymbol'    => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, get_bloginfo( 'charset' ) ),
                )
            );
            $localized = true;
        }

        // Product cards use WooCommerce's normal AJAX add-to-cart behavior.
        if ( wp_script_is( 'wc-add-to-cart', 'registered' ) ) {
            wp_enqueue_script( 'wc-add-to-cart' );
        }
    }

    private static function product_quick_add( int $product_id ): string {
        if ( ! function_exists( 'woocommerce_template_loop_add_to_cart' ) ) {
            return '';
        }

        $feed_product = wc_get_product( $product_id );
        if ( ! $feed_product || ! $feed_product->is_visible() ) {
            return '';
        }

        global $product;
        $previous_product = $product;
        $product          = $feed_product;

        ob_start();
        woocommerce_template_loop_add_to_cart();
        $button = (string) ob_get_clean();

        $product = $previous_product;
        return $button;
    }

    public static function application(): string {
        self::assets();
        if ( ! is_user_logged_in() ) {
            return '<div class="tnm-notice">Please <a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">sign in</a> before applying to sell.</div>';
        }
        if ( tnm_is_marketplace_user() ) {
            return '<div class="tnm-notice tnm-success">Your seller account is active. <a href="' . esc_url( tnm_page_url( 'seller_dashboard' ) ) . '">Open Seller Dashboard</a>.</div>';
        }

        $message = '';
        if ( isset( $_POST['tnm_action'] ) && 'apply' === $_POST['tnm_action'] ) {
            if ( ! isset( $_POST['tnm_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tnm_nonce'] ) ), 'tnm_apply' ) ) {
                $message = '<div class="tnm-notice tnm-error">Security check failed. Refresh and try again.</div>';
            } else {
                $result = TNM_Applications::submit(
                    get_current_user_id(),
                    array(
                        'store_name'   => wp_unslash( $_POST['store_name'] ?? '' ),
                        'about'        => wp_unslash( $_POST['about'] ?? '' ),
                        'products'     => wp_unslash( $_POST['products'] ?? '' ),
                        'website'      => wp_unslash( $_POST['website'] ?? '' ),
                        'accept_terms' => ! empty( $_POST['accept_terms'] ),
                    )
                );
                $message = is_wp_error( $result ) ? '<div class="tnm-notice tnm-error">' . esc_html( $result->get_error_message() ) . '</div>' : sprintf( '<div class="tnm-notice tnm-success">Application submitted. The %s team will review it.</div>', esc_html( get_bloginfo( 'name' ) ) );
            }
        }

        ob_start();
        echo $message; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        ?>
        <div class="tnm-card tnm-form-card">
            <h2>Start Your Nest</h2>
            <p>Tell us about your shop, what you make, and what buyers can expect.</p>
            <form method="post" class="tnm-form">
                <?php wp_nonce_field( 'tnm_apply', 'tnm_nonce' ); ?>
                <input type="hidden" name="tnm_action" value="apply">
                <label>Store name<input type="text" name="store_name" required maxlength="120"></label>
                <label>About you and your shop<textarea name="about" required rows="6"></textarea></label>
                <label>What do you plan to sell?<textarea name="products" required rows="5"></textarea></label>
                <label>Website or social link <span class="tnm-muted">(optional)</span><input type="url" name="website"></label>
                <label class="tnm-checkbox"><input type="checkbox" name="accept_terms" value="1" required> I agree to the <a href="<?php echo esc_url( tnm_page_url( 'seller_terms' ) ); ?>" target="_blank" rel="noopener">Seller Terms</a>.</label>
                <button type="submit" class="tnm-button">Submit application</button>
            </form>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    public static function dashboard(): string {
        self::assets();
        if ( ! is_user_logged_in() ) {
            return '<div class="tnm-notice">Please <a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">sign in</a> to open the seller dashboard.</div>';
        }
        if ( ! tnm_is_seller() && ! tnm_is_admin_or_manager() ) {
            return '<div class="tnm-notice">You need an approved seller account. <a href="' . esc_url( tnm_page_url( 'seller_application' ) ) . '">Apply to sell</a>.</div>';
        }

        $seller_id = get_current_user_id();
        $notice    = self::handle_dashboard_action( $seller_id );
        $balances  = TNM_Ledger::balances( $seller_id );
        $products  = self::seller_product_query( $seller_id );
        $orders    = TNM_Marketplace::seller_orders( $seller_id, 1, 25 );
        $ledger           = TNM_Ledger::entries( $seller_id, 1, 50 );
        $payouts          = TNM_Payouts::list_for_seller( $seller_id );
        $shipping_profile = function_exists( 'mnu_ship_get_profile' ) ? mnu_ship_get_profile( $seller_id ) : array();
        $label_settings   = function_exists( 'mnu_labels_settings' ) ? mnu_labels_settings() : array( 'shippo_token' => '', 'test_mode' => 1 );
        // v3.7.107 - Use fresh_status() so a seller who finished onboarding in
        // Stripe still sees "Connected & ready" here even if the account.updated
        // webhook never made it to us. fresh_status() only hits Stripe when the
        // cache says onboarding is incomplete, and is rate-limited to once/min.
        $connect          = class_exists( 'MNU_Connect' ) ? MNU_Connect::fresh_status( $seller_id ) : null;
        $listing_blocked  = tnm_seller_listing_blocked( $seller_id );

        ob_start();
        echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        ?>
        <?php
            $unread_msgs    = TNM_Social::unread_message_count( $seller_id );
            $unread_threads = TNM_Social::unread_thread_count( $seller_id );
            $messages_url   = home_url( '/messages/' );
        ?>
        <div class="tnm-dashboard">
            <header class="tnm-dashboard-header">
                <div><h1><?php echo esc_html( tnm_seller_display_name( $seller_id ) ); ?></h1><p>Seller Dashboard</p></div>
                <div class="tnm-dashboard-header__actions">
                    <a class="tnm-button tnm-inbox-btn<?php echo $unread_msgs ? ' has-unread' : ''; ?>" href="<?php echo esc_url( $messages_url ); ?>">
                        <span class="dashicons dashicons-email-alt" aria-hidden="true"></span>
                        <span>Messages</span>
                        <?php if ( $unread_msgs ) : ?><span class="tnm-inbox-badge" aria-label="<?php echo esc_attr( $unread_msgs . ' unread' ); ?>"><?php echo esc_html( $unread_msgs > 99 ? '99+' : (string) $unread_msgs ); ?></span><?php endif; ?>
                    </a>
                    <a class="tnm-button tnm-button-secondary" href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>">View shop</a>
                </div>
            </header>

            <div class="tnm-stats">
                <div class="tnm-stat"><span>Available</span><strong><?php echo esc_html( tnm_money( $balances['available'], $balances['currency'] ) ); ?></strong></div>
                <div class="tnm-stat"><span>Pending</span><strong><?php echo esc_html( tnm_money( $balances['pending'], $balances['currency'] ) ); ?></strong></div>
                <div class="tnm-stat"><span>In payout</span><strong><?php echo esc_html( tnm_money( $balances['reserved'], $balances['currency'] ) ); ?></strong></div>
                <div class="tnm-stat"><span>Paid</span><strong><?php echo esc_html( tnm_money( $balances['paid'], $balances['currency'] ) ); ?></strong></div>
            </div>

            <nav class="tnm-tabs" aria-label="Seller dashboard sections">
                <button type="button" data-tnm-tab="overview" class="is-active">Overview</button>
                <button type="button" data-tnm-tab="products">Products</button>
                <button type="button" data-tnm-tab="import">Import CSV</button>
                <button type="button" data-tnm-tab="shipping">Shipping</button>
                <button type="button" data-tnm-tab="orders">Orders</button>
                <button type="button" data-tnm-tab="earnings">Earnings</button>
                <button type="button" data-tnm-tab="payouts">Payouts</button>
                <button type="button" data-tnm-tab="post">Create post</button>
                <button type="button" data-tnm-tab="profile">Profile</button>
            </nav>

            <section class="tnm-tab-panel is-active" data-tnm-panel="overview">
                <div class="tnm-grid-2">
                    <div class="tnm-card"><h2>Marketplace fee</h2><p><strong><?php echo esc_html( tnm_fee_percent() ); ?>%</strong> <?php echo esc_html( tnm_fee_label() ); ?> is deducted from each item sale. Tax is tracked separately and shipping is allocated to seller earnings.</p></div>
                    <div class="tnm-card"><h2>Quick actions</h2><p><button type="button" class="tnm-button" data-tnm-open-tab="products">Add a product</button> <button type="button" class="tnm-button tnm-button-secondary" data-tnm-open-tab="post">Share an update</button> <a class="tnm-button tnm-button-secondary" href="<?php echo esc_url( $messages_url ); ?>">Open inbox<?php echo $unread_threads ? ' (' . esc_html( (string) $unread_threads ) . ')' : ''; ?></a></p></div>
                </div>
                <div class="tnm-card tnm-inbox-card">
                    <h2>Buyer messages<?php echo $unread_threads ? ' <span class="tnm-pill tnm-pill--brand">' . esc_html( (string) $unread_threads ) . ' new</span>' : ''; ?></h2>
                    <?php if ( $unread_msgs ) : ?>
                        <p>You have <strong><?php echo esc_html( (string) $unread_msgs ); ?></strong> unread <?php echo $unread_msgs === 1 ? 'message' : 'messages'; ?> across <strong><?php echo esc_html( (string) $unread_threads ); ?></strong> <?php echo $unread_threads === 1 ? 'conversation' : 'conversations'; ?>. <a href="<?php echo esc_url( $messages_url ); ?>">Open inbox &rarr;</a></p>
                    <?php else : ?>
                        <p>No unread messages. Buyers can reach out from any product page or your shop profile — all replies land here. <a href="<?php echo esc_url( $messages_url ); ?>">Open inbox</a>.</p>
                    <?php endif; ?>
                </div>
                <div class="tnm-card"><h2>Recent orders</h2><?php self::render_orders_table( array_slice( $orders['orders'], 0, 5 ) ); ?></div>
            </section>

            <section class="tnm-tab-panel" data-tnm-panel="products">
                <div class="tnm-grid-2">
                    <div class="tnm-card">
                        <h2 data-tnm-form-title>Add product</h2>
                        <?php if ( $listing_blocked ) : ?>
                            <div class="tnm-notice tnm-error">
                                <p><strong>Connect your bank account first.</strong></p>
                                <p>Connect your Stripe account before you can list products for sale, so you can get paid when something sells.</p>
                                <p><button type="button" class="tnm-button" data-tnm-open-tab="payouts">Connect with Stripe</button></p>
                            </div>
                        <?php endif; ?>
                        <?php // Hidden rather than omitted while onboarding is unfinished: editing an existing listing is still allowed, and the edit button reuses this same form. ?>
                        <div data-tnm-listing-gate="<?php echo $listing_blocked ? 'blocked' : 'open'; ?>"<?php echo $listing_blocked ? ' hidden' : ''; ?>>
                        <form method="post" enctype="multipart/form-data" class="tnm-form" data-tnm-product-form>
                            <?php wp_nonce_field( 'tnm_dashboard', 'tnm_nonce' ); ?>
                            <input type="hidden" name="tnm_action" value="create_product" data-tnm-action-field>
                            <input type="hidden" name="product_id" value="" data-tnm-product-id>
                            <label>Product name<input type="text" name="product_name" required></label>
                            <label>Description<textarea name="product_description" rows="6" required></textarea></label>
                            <div class="tnm-grid-2"><label>Price<input type="number" name="product_price" min="0" step="0.01" required></label><label>Quantity<input type="number" name="product_stock" min="0" step="1" value="1" required></label></div>
                            <label>SKU <span class="tnm-muted">(optional)</span><input type="text" name="product_sku"></label>
                            <fieldset class="tnm-fieldset">
                                <legend>Shipping package</legend>
                                <p class="tnm-muted">Leave a field blank to use the defaults from your Shipping tab.</p>
                                <?php $tnm_presets = function_exists( 'mnu_ship_package_presets' ) ? mnu_ship_package_presets() : array(); ?>
                                <div class="tnm-grid-2">
                                    <label>Weight (oz)<input type="number" name="shipping_weight_oz" min="0.1" step="0.1" inputmode="decimal"></label>
                                    <label>Package size
                                        <select name="shipping_package_size" data-tnm-package-size>
                                            <?php foreach ( $tnm_presets as $tnm_size => $tnm_preset ) : ?>
                                                <option value="<?php echo esc_attr( $tnm_size ); ?>"><?php echo esc_html( sprintf( '%s — %s×%s×%s in', (string) ( $tnm_preset['label'] ?? ucfirst( $tnm_size ) ), $tnm_preset['length_in'], $tnm_preset['width_in'], $tnm_preset['height_in'] ) ); ?></option>
                                            <?php endforeach; ?>
                                            <option value="custom" selected>Custom dimensions</option>
                                        </select>
                                    </label>
                                </div>
                                <label>Processing time<input type="text" name="shipping_processing_time" placeholder="3-5 business days"></label>
                                <div class="tnm-grid-3" data-tnm-dimensions>
                                    <label>Length (in)<input type="number" name="shipping_length_in" min="0.1" step="0.1" inputmode="decimal"></label>
                                    <label>Width (in)<input type="number" name="shipping_width_in" min="0.1" step="0.1" inputmode="decimal"></label>
                                    <label>Height (in)<input type="number" name="shipping_height_in" min="0.1" step="0.1" inputmode="decimal"></label>
                                </div>
                            </fieldset>
                            <label data-tnm-image-label>Product image <span class="tnm-required">(required)</span><input type="file" name="image" accept="image/*" required></label>
                            <div class="tnm-form-actions">
                                <button class="tnm-button" type="submit" data-tnm-submit>Create product</button>
                                <button class="tnm-button tnm-button-secondary" type="button" data-tnm-cancel-edit hidden>Cancel edit</button>
                            </div>
                        </form>
                        </div>
                    </div>
                    <div class="tnm-card"><h2>Your products</h2><?php self::render_products_table( $products ); ?></div>
                </div>
            </section>

            <section class="tnm-tab-panel" data-tnm-panel="import">
                <script>
                    // Guarantee the importer has REST config even if wp_localize_script
                    // didn't run on this page (some caching/rendering paths skip it).
                    window.TNMFrontend = window.TNMFrontend || {};
                    if (!window.TNMFrontend.restRoot)  { window.TNMFrontend.restRoot  = <?php echo wp_json_encode( trailingslashit( rest_url() ) ); ?>; }
                    if (!window.TNMFrontend.restNonce) { window.TNMFrontend.restNonce = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>; }
                </script>
                <div class="tnm-card">
                    <h2>Bulk product import</h2>
                    <p class="tnm-muted">Upload a WooCommerce product export (.csv). Each row becomes a product in your shop. If a row's SKU matches a product you already own, that product is updated instead of duplicated. Max 500 rows, 10&nbsp;MB.</p>

                    <div data-tnm-import-step="pick">
                        <form data-tnm-import-form enctype="multipart/form-data" onsubmit="return false;">
                            <label style="display:block;margin:12px 0;">
                                <span>CSV file</span>
                                <input type="file" name="file" accept=".csv,text/csv" data-tnm-import-file required>
                            </label>
                            <button type="button" class="tnm-button" data-tnm-import-upload>Upload &amp; preview</button>
                        </form>
                    </div>

                    <div data-tnm-import-step="preview" hidden>
                        <h3 style="margin-top:16px;">Preview</h3>
                        <p data-tnm-import-summary></p>
                        <div data-tnm-import-unrecognized></div>
                        <div data-tnm-import-errors></div>
                        <div data-tnm-import-preview></div>
                        <p style="margin-top:16px;">
                            <button type="button" class="tnm-button" data-tnm-import-run>Start import</button>
                            <button type="button" class="tnm-button tnm-button-secondary" data-tnm-import-reset>Pick a different file</button>
                        </p>
                    </div>

                    <div data-tnm-import-step="progress" hidden>
                        <h3 style="margin-top:16px;">Importing…</h3>
                        <div style="background:#e5e7eb;border-radius:6px;height:10px;overflow:hidden;margin:12px 0;">
                            <div data-tnm-import-bar style="background:#7C5A3A;height:100%;width:0%;transition:width .4s;"></div>
                        </div>
                        <p data-tnm-import-progress></p>
                        <p class="tnm-muted" style="font-size:13px;">You can leave this tab open — the import continues on the server.</p>
                    </div>

                    <div data-tnm-import-step="done" hidden>
                        <h3 style="margin-top:16px;">Import complete</h3>
                        <p data-tnm-import-final></p>
                        <div data-tnm-import-final-errors></div>
                        <p><button type="button" class="tnm-button" data-tnm-import-reset>Import another file</button></p>
                    </div>

                    <div data-tnm-import-error class="tnm-notice tnm-error" hidden style="margin-top:12px;"></div>
                </div>
            </section>

            <section class="tnm-tab-panel" data-tnm-panel="shipping">
                <div class="tnm-grid-2">
                    <div class="tnm-card tnm-form-card">
                        <h2>Shipping profile</h2>
                        <p class="tnm-muted">Shippo uses this return address and these package defaults when your product does not have its own dimensions.</p>
                        <form method="post" class="tnm-form">
                            <?php wp_nonce_field( 'tnm_dashboard', 'tnm_nonce' ); ?>
                            <input type="hidden" name="tnm_action" value="save_shipping_profile">
                            <div class="tnm-grid-2">
                                <label>Ship-from name<input type="text" name="ship_from_name" value="<?php echo esc_attr( (string) ( $shipping_profile['ship_from_name'] ?? '' ) ); ?>" required></label>
                                <label>Company <span class="tnm-muted">(optional)</span><input type="text" name="ship_from_company" value="<?php echo esc_attr( (string) ( $shipping_profile['ship_from_company'] ?? '' ) ); ?>"></label>
                            </div>
                            <label>Street address<input type="text" name="ship_from_street1" value="<?php echo esc_attr( (string) ( $shipping_profile['ship_from_street1'] ?? '' ) ); ?>" required></label>
                            <label>Apartment, suite, etc. <span class="tnm-muted">(optional)</span><input type="text" name="ship_from_street2" value="<?php echo esc_attr( (string) ( $shipping_profile['ship_from_street2'] ?? '' ) ); ?>"></label>
                            <div class="tnm-grid-3">
                                <label>City<input type="text" name="ship_from_city" value="<?php echo esc_attr( (string) ( $shipping_profile['ship_from_city'] ?? '' ) ); ?>" required></label>
                                <label>State<input type="text" name="ship_from_state" value="<?php echo esc_attr( (string) ( $shipping_profile['ship_from_state'] ?? '' ) ); ?>" maxlength="3" required></label>
                                <label>ZIP code<input type="text" name="ship_from_zip" value="<?php echo esc_attr( (string) ( $shipping_profile['ship_from_zip'] ?? '' ) ); ?>" required></label>
                            </div>
                            <div class="tnm-grid-2">
                                <label>Country code<input type="text" name="ship_from_country" value="<?php echo esc_attr( (string) ( $shipping_profile['ship_from_country'] ?? 'US' ) ); ?>" maxlength="2" required></label>
                                <label>Phone<input type="tel" name="ship_from_phone" value="<?php echo esc_attr( (string) ( $shipping_profile['ship_from_phone'] ?? '' ) ); ?>"></label>
                            </div>
                            <label>Default processing time<input type="text" name="processing_time" value="<?php echo esc_attr( (string) ( $shipping_profile['processing_time'] ?? '3-5 business days' ) ); ?>"></label>
                            <fieldset class="tnm-fieldset">
                                <legend>Default package</legend>
                                <div class="tnm-grid-2">
                                    <label>Weight (oz)<input type="number" name="default_weight_oz" min="0.1" step="0.1" value="<?php echo esc_attr( (string) ( $shipping_profile['default_weight_oz'] ?? '8' ) ); ?>" required></label>
                                    <label>Length (in)<input type="number" name="default_length_in" min="0.1" step="0.1" value="<?php echo esc_attr( (string) ( $shipping_profile['default_length_in'] ?? '8' ) ); ?>" required></label>
                                </div>
                                <div class="tnm-grid-2">
                                    <label>Width (in)<input type="number" name="default_width_in" min="0.1" step="0.1" value="<?php echo esc_attr( (string) ( $shipping_profile['default_width_in'] ?? '6' ) ); ?>" required></label>
                                    <label>Height (in)<input type="number" name="default_height_in" min="0.1" step="0.1" value="<?php echo esc_attr( (string) ( $shipping_profile['default_height_in'] ?? '2' ) ); ?>" required></label>
                                </div>
                            </fieldset>
                            <button class="tnm-button" type="submit">Save shipping profile</button>
                        </form>
                    </div>
                    <div class="tnm-card">
                        <h2>Shippo connection</h2>
                        <?php if ( ! empty( $label_settings['shippo_token'] ) ) : ?>
                            <p class="tnm-connection-status is-connected"><strong>Connected</strong></p>
                            <p><?php echo ! empty( $label_settings['test_mode'] ) ? 'Test mode is enabled. Live-token label purchases are blocked.' : 'Live mode is enabled. Purchasing a label can charge the connected Shippo account.'; ?></p>
                        <?php else : ?>
                            <p class="tnm-connection-status is-disconnected"><strong>Not configured</strong></p>
                            <p>An administrator must add a Shippo API token under <strong>The Nest → Shipping Labels</strong>.</p>
                        <?php endif; ?>
                        <h3>How label purchasing works</h3>
                        <ol class="tnm-steps">
                            <li>Save your return address and package defaults.</li>
                            <li>Open the Orders tab and choose Get shipping rates.</li>
                            <li>Select a service and purchase the label.</li>
                            <li>Open the PDF, print it, and attach it to the package.</li>
                        </ol>
                        <p class="tnm-muted">Purchasing a successful label automatically saves tracking and marks your portion of the order shipped.</p>
                    </div>
                </div>
            </section>

            <section class="tnm-tab-panel" data-tnm-panel="orders"><div class="tnm-card"><h2>Orders</h2><?php self::render_orders_table( $orders['orders'], true ); ?></div></section>
            <section class="tnm-tab-panel" data-tnm-panel="earnings"><div class="tnm-card"><h2>Transaction ledger</h2>
                <form method="post" class="tnm-inline-form tnm-backfill-form">
                    <?php wp_nonce_field( 'tnm_dashboard', 'tnm_nonce' ); ?>
                    <input type="hidden" name="tnm_action" value="backfill_earnings">
                    <button type="submit" class="tnm-small-button">Recalculate earnings</button>
                    <span class="tnm-muted">Rebuilds ledger rows from your paid orders if any are missing.</span>
                </form>
                <?php self::render_ledger_table( $ledger['entries'] ); ?></div></section>

            <section class="tnm-tab-panel" data-tnm-panel="payouts">
                <?php if ( null !== $connect ) : ?>
                <div class="tnm-card" data-tnm-connect-card>
                    <h2>Bank payouts (Stripe)</h2>
                    <?php if ( ! $connect['connected'] ) : ?>
                        <p class="tnm-connection-status is-disconnected"><strong>Not connected</strong></p>
                        <p>Connect your bank account with Stripe so your sales are paid directly to you. You must finish this before you can list new products.</p>
                        <p><a class="tnm-button" data-tnm-connect-onboard href="<?php echo esc_url( home_url( '/mnu-connect-start/' ) ); ?>">Connect your bank account with Stripe</a></p>
                    <?php elseif ( ! $connect['payouts_enabled'] || ! $connect['charges_enabled'] ) : ?>
                        <p class="tnm-connection-status is-disconnected"><strong>Onboarding incomplete</strong></p>
                        <p>Your Stripe account still needs more information before payouts can be enabled. Finish onboarding to start selling.</p>
                        <p>
                            <a class="tnm-button" data-tnm-connect-onboard href="<?php echo esc_url( home_url( '/mnu-connect-start/' ) ); ?>">Finish Stripe onboarding</a>
                            <button type="button" class="tnm-button tnm-button-secondary" data-tnm-connect-dashboard>View Stripe balance &amp; payout history</button>
                        </p>
                    <?php else : ?>
                        <p class="tnm-connection-status is-connected"><strong>Connected &amp; ready</strong></p>
                        <p>Your earnings are transferred to your connected Stripe account automatically as orders complete.</p>
                        <p><button type="button" class="tnm-button tnm-button-secondary" data-tnm-connect-dashboard>View Stripe balance &amp; payout history</button></p>
                    <?php endif; ?>
                    <p class="tnm-muted" data-tnm-connect-msg hidden></p>
                </div>
                <?php endif; ?>
                <?php /* v3.7.107 — Legacy manual/PayPal "Request payout" card removed.
                   ShopMyNest pays sellers automatically via Stripe Connect (see the
                   Bank payouts card above). The manual/PayPal form was a holdover
                   from The Nest Marketplace baseline plugin. Historical payout rows
                   are still shown so sellers can see any legacy manual entries. */ ?>
                <div class="tnm-card"><h2>Payout history</h2><?php self::render_payouts_table( $payouts ); ?></div>
            </section>

            <section class="tnm-tab-panel" data-tnm-panel="post"><div class="tnm-card tnm-form-card"><h2>Create a Nest post</h2>
                <form method="post" enctype="multipart/form-data" class="tnm-form">
                    <?php wp_nonce_field( 'tnm_dashboard', 'tnm_nonce' ); ?>
                    <input type="hidden" name="tnm_action" value="create_post">
                    <label>Title<input type="text" name="title" required></label>
                    <label>Post<textarea name="content" rows="8" required></textarea></label>
                    <label>Image <span class="tnm-muted">(optional)</span><input type="file" name="image" accept="image/*"></label>
                    <button class="tnm-button" type="submit">Publish post</button>
                </form>
            </div></section>

            <section class="tnm-tab-panel" data-tnm-panel="profile"><div class="tnm-card tnm-form-card"><h2>Shop profile</h2>
                <form method="post" class="tnm-form">
                    <?php wp_nonce_field( 'tnm_dashboard', 'tnm_nonce' ); ?>
                    <input type="hidden" name="tnm_action" value="save_profile">
                    <label>Store name<input type="text" name="store_name" value="<?php echo esc_attr( tnm_seller_display_name( $seller_id ) ); ?>" required></label>
                    <label>Shop tagline <span style="color:#7a6b57;font-weight:400;font-size:.85em">(one short line, shown on the Discover Shops page)</span><input type="text" name="tagline" maxlength="140" value="<?php echo esc_attr( (string) get_user_meta( $seller_id, 'tnm_store_tagline', true ) ); ?>"></label>
                    <label>About your shop<textarea name="about" rows="7"><?php echo esc_textarea( (string) get_user_meta( $seller_id, 'tnm_store_about', true ) ); ?></textarea>
                    <?php /* v3.7.107 — PayPal payout email field removed from seller-facing Profile tab.
                       Payouts run on Stripe Connect (Bank payouts card on Earnings & payouts tab). */ ?></label>
                    <label class="tnm-form-check"><input type="checkbox" name="email_optout_messages" value="1" <?php checked( '1', (string) get_user_meta( $seller_id, 'tnm_email_optout_messages', true ) ); ?>> Don't email me when I get a new buyer message. (You'll still see unread messages on the dashboard and in the app.)</label>
                    <button class="tnm-button" type="submit">Save profile</button>
                </form>
            </div></section>
        </div>
        <?php
        if ( null !== $connect ) {
            self::enqueue_connect_script();
        }
        return (string) ob_get_clean();
    }

    /**
     * Attach the Stripe Connect card JS to the tnm-frontend handle so it prints
     * in the footer AFTER the localized TNMFrontend object and after
     * frontend.js. Printing it inline in the shortcode body ran it before the
     * footer-localized TNMFrontend existed, so the handler never bound.
     */
    private static function enqueue_connect_script(): void {
        static $added = false;
        if ( $added ) {
            return;
        }
        $added = true;

        $js = <<<'JS'
        (function(){
            var card = document.querySelector('[data-tnm-connect-card]');
            if (!card || typeof TNMFrontend === 'undefined') { return; }
            var msg = card.querySelector('[data-tnm-connect-msg]');
            function show(text){ if (msg){ msg.textContent = text; msg.hidden = false; } }
            function post(path, body){
                return fetch(TNMFrontend.restRoot + path, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': TNMFrontend.restNonce },
                    body: JSON.stringify(body || {})
                }).then(function(r){ return r.json(); });
            }
            var onboard = card.querySelector('[data-tnm-connect-onboard]');
            if (onboard) {
                onboard.addEventListener('click', function(ev){
                    // If this is a plain anchor with an href (e.g. /mnu-connect-start/),
                    // let the browser do a normal same-tab navigation. The server
                    // route creates/updates the Stripe account and 302s to Stripe,
                    // so no XHR is needed and the flow works even if TNMFrontend
                    // localize didn't land in the footer.
                    if (onboard.tagName === 'A' && onboard.getAttribute('href')) {
                        show('Opening Stripe onboarding…');
                        return; // do not preventDefault; navigate via href
                    }
                    if (ev && ev.preventDefault) { ev.preventDefault(); }
                    onboard.disabled = true;
                    show('Opening Stripe onboarding…');
                    post('nest-connect/v1/onboard-link', { return_url: window.location.href, refresh_url: window.location.href })
                        .then(function(res){
                            if (res && res.url) { window.location = res.url; }
                            else { onboard.disabled = false; show((res && res.message) || 'Could not start Stripe onboarding.'); }
                        })
                        .catch(function(){ onboard.disabled = false; show('Could not start Stripe onboarding.'); });
                });
            }
            var dash = card.querySelector('[data-tnm-connect-dashboard]');
            if (dash) {
                dash.addEventListener('click', function(){
                    dash.disabled = true;
                    show('Opening your Stripe dashboard…');
                    post('nest-connect/v1/dashboard-link', {})
                        .then(function(res){
                            dash.disabled = false;
                            if (res && res.url) { window.open(res.url, '_blank'); show(''); if (msg) { msg.hidden = true; } }
                            else { show((res && res.message) || 'Could not open the Stripe dashboard.'); }
                        })
                        .catch(function(){ dash.disabled = false; show('Could not open the Stripe dashboard.'); });
                });
            }
        })();
        JS;

        wp_add_inline_script( 'tnm-frontend', $js );
    }

    private static function handle_dashboard_action( int $seller_id ): string {
        if ( empty( $_POST['tnm_action'] ) ) {
            return '';
        }
        if ( ! isset( $_POST['tnm_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tnm_nonce'] ) ), 'tnm_dashboard' ) ) {
            return '<div class="tnm-notice tnm-error">Security check failed. Refresh and try again.</div>';
        }
        $action = sanitize_key( wp_unslash( $_POST['tnm_action'] ) );
        $result = true;
        if ( 'create_product' === $action ) {
            // Checked before the upload so a blocked attempt does not leave an
            // orphaned attachment in the media library. TNM_Marketplace::create_product()
            // enforces the same rule again for every other caller.
            if ( tnm_seller_listing_blocked( $seller_id ) ) {
                return '<div class="tnm-notice tnm-error">' . esc_html( tnm_seller_listing_blocked_message() ) . '</div>';
            }
            // v3.7.77 — short-circuit if no photo was attached so we don't
            // even try to upload. create_product() re-checks for every caller.
            if ( empty( $_FILES['image']['tmp_name'] ) ) {
                return '<div class="tnm-notice tnm-error">A photo is required. Please attach at least one image before saving your listing.</div>';
            }
            $image_id = tnm_upload_image_from_request( 'image' );
            if ( is_wp_error( $image_id ) ) {
                $result = $image_id;
            } else {
                $result = TNM_Marketplace::create_product(
                    $seller_id,
                    array(
                        'name'        => wp_unslash( $_POST['product_name'] ?? $_POST['name'] ?? '' ),
                        'description' => wp_unslash( $_POST['product_description'] ?? $_POST['description'] ?? '' ),
                        'price'       => wp_unslash( $_POST['product_price'] ?? $_POST['price'] ?? '' ),
                        'stock'       => absint( $_POST['product_stock'] ?? $_POST['stock'] ?? 0 ),
                        'sku'         => wp_unslash( $_POST['product_sku'] ?? $_POST['sku'] ?? '' ),
                        'image_id'    => is_int( $image_id ) ? $image_id : 0,
                    )
                );
                if ( ! is_wp_error( $result ) && function_exists( 'mnu_ship_save_product_shipping' ) ) {
                    mnu_ship_save_product_shipping(
                        (int) $result,
                        array(
                            'weight_oz'      => wp_unslash( $_POST['shipping_weight_oz'] ?? '' ),
                            'length_in'      => wp_unslash( $_POST['shipping_length_in'] ?? '' ),
                            'width_in'       => wp_unslash( $_POST['shipping_width_in'] ?? '' ),
                            'height_in'      => wp_unslash( $_POST['shipping_height_in'] ?? '' ),
                            'package_size'   => wp_unslash( $_POST['shipping_package_size'] ?? '' ),
                            'processing_time'=> wp_unslash( $_POST['shipping_processing_time'] ?? '' ),
                        )
                    );
                }
            }
        } elseif ( 'update_product' === $action ) {
            $product_id = absint( $_POST['product_id'] ?? 0 );
            $image_id   = tnm_upload_image_from_request( 'image' );
            if ( is_wp_error( $image_id ) ) {
                $result = $image_id;
            } else {
                $data = array(
                    'name'        => wp_unslash( $_POST['product_name'] ?? $_POST['name'] ?? '' ),
                    'description' => wp_unslash( $_POST['product_description'] ?? $_POST['description'] ?? '' ),
                    'price'       => wp_unslash( $_POST['product_price'] ?? $_POST['price'] ?? '' ),
                    'stock'       => absint( $_POST['product_stock'] ?? $_POST['stock'] ?? 0 ),
                );
                if ( is_int( $image_id ) && $image_id > 0 ) {
                    $data['image_id'] = $image_id;
                }
                $result = TNM_Marketplace::update_product( $seller_id, $product_id, $data );
                if ( ! is_wp_error( $result ) && ( isset( $_POST['product_sku'] ) || isset( $_POST['sku'] ) ) ) {
                    $sku_product = wc_get_product( $product_id );
                    if ( $sku_product ) {
                        try {
                            $sku_product->set_sku( wc_clean( wp_unslash( $_POST['product_sku'] ?? $_POST['sku'] ?? '' ) ) );
                            $sku_product->save();
                        } catch ( WC_Data_Exception $exception ) {
                            $result = new WP_Error( 'invalid_sku', $exception->getMessage() );
                        }
                    }
                }
                if ( ! is_wp_error( $result ) && function_exists( 'mnu_ship_save_product_shipping' ) ) {
                    mnu_ship_save_product_shipping(
                        $product_id,
                        array(
                            'weight_oz'       => wp_unslash( $_POST['shipping_weight_oz'] ?? '' ),
                            'length_in'       => wp_unslash( $_POST['shipping_length_in'] ?? '' ),
                            'width_in'        => wp_unslash( $_POST['shipping_width_in'] ?? '' ),
                            'height_in'       => wp_unslash( $_POST['shipping_height_in'] ?? '' ),
                            'package_size'    => wp_unslash( $_POST['shipping_package_size'] ?? '' ),
                            'processing_time' => wp_unslash( $_POST['shipping_processing_time'] ?? '' ),
                        )
                    );
                }
            }
        } elseif ( 'delete_product' === $action ) {
            $result = TNM_Marketplace::delete_product( $seller_id, absint( $_POST['product_id'] ?? 0 ) );
        } elseif ( 'duplicate_product' === $action ) {
            // v3.7.105 (Build #3 web parity) - clone an existing listing as a
            // fresh draft with " (Copy)" appended. Mirrors the mobile
            // /the-nest/v1/seller/products/{id}/duplicate endpoint.
            $duplicate_id = TNM_Marketplace::duplicate_product( $seller_id, absint( $_POST['product_id'] ?? 0 ) );
            if ( is_wp_error( $duplicate_id ) ) {
                $result = $duplicate_id;
            } elseif ( is_int( $duplicate_id ) && $duplicate_id > 0 ) {
                $edit_url = get_permalink( $duplicate_id );
                return '<div class="tnm-notice tnm-success">Draft copy created. <a href="' . esc_url( (string) $edit_url ) . '">Open the new draft</a> to edit it before publishing.</div>';
            } else {
                $result = new WP_Error( 'duplicate_failed', 'Could not duplicate that product. Refresh and try again.' );
            }
        } elseif ( 'update_order' === $action ) {
            $result = TNM_Marketplace::update_seller_order_status( $seller_id, absint( $_POST['order_id'] ?? 0 ), sanitize_key( wp_unslash( $_POST['status'] ?? '' ) ), sanitize_text_field( wp_unslash( $_POST['tracking_number'] ?? '' ) ) );
        } elseif ( 'save_shipping_profile' === $action ) {
            if ( ! function_exists( 'mnu_ship_save_profile' ) ) {
                $result = new WP_Error( 'shipping_unavailable', 'Shipping profiles are unavailable. Refresh and try again.' );
            } else {
                $required = array( 'ship_from_name', 'ship_from_street1', 'ship_from_city', 'ship_from_state', 'ship_from_zip', 'ship_from_country', 'default_weight_oz', 'default_length_in', 'default_width_in', 'default_height_in' );
                $missing  = array();
                foreach ( $required as $field ) {
                    if ( '' === trim( (string) wp_unslash( $_POST[ $field ] ?? '' ) ) ) {
                        $missing[] = ucwords( str_replace( '_', ' ', $field ) );
                    }
                }
                if ( $missing ) {
                    $result = new WP_Error( 'incomplete_shipping_profile', 'Complete these shipping fields: ' . implode( ', ', $missing ) . '.' );
                } else {
                    mnu_ship_save_profile(
                        $seller_id,
                        array(
                            'ship_from_name'        => wp_unslash( $_POST['ship_from_name'] ?? '' ),
                            'ship_from_company'     => wp_unslash( $_POST['ship_from_company'] ?? '' ),
                            'ship_from_street1'     => wp_unslash( $_POST['ship_from_street1'] ?? '' ),
                            'ship_from_street2'     => wp_unslash( $_POST['ship_from_street2'] ?? '' ),
                            'ship_from_city'        => wp_unslash( $_POST['ship_from_city'] ?? '' ),
                            'ship_from_state'       => wp_unslash( $_POST['ship_from_state'] ?? '' ),
                            'ship_from_zip'         => wp_unslash( $_POST['ship_from_zip'] ?? '' ),
                            'ship_from_country'     => wp_unslash( $_POST['ship_from_country'] ?? 'US' ),
                            'ship_from_phone'       => wp_unslash( $_POST['ship_from_phone'] ?? '' ),
                            'processing_time'       => wp_unslash( $_POST['processing_time'] ?? '' ),
                            'default_weight_oz'     => wp_unslash( $_POST['default_weight_oz'] ?? '' ),
                            'default_length_in'     => wp_unslash( $_POST['default_length_in'] ?? '' ),
                            'default_width_in'      => wp_unslash( $_POST['default_width_in'] ?? '' ),
                            'default_height_in'     => wp_unslash( $_POST['default_height_in'] ?? '' ),
                        )
                    );
                }
            }
        } elseif ( 'request_payout' === $action ) {
            $result = TNM_Payouts::request( $seller_id, (float) ( $_POST['amount'] ?? 0 ), sanitize_key( wp_unslash( $_POST['method'] ?? '' ) ), sanitize_text_field( wp_unslash( $_POST['destination'] ?? '' ) ) );
        } elseif ( 'save_profile' === $action ) {
            // v3.7.107 — PayPal email removed from seller-facing profile. Payouts
            // run on Stripe Connect. `tnm_paypal_email` meta is left untouched
            // to preserve any historical value for admin-side manual payouts.
            $store_name = sanitize_text_field( wp_unslash( $_POST['store_name'] ?? '' ) );
            if ( ! $store_name ) {
                $result = new WP_Error( 'invalid_profile', 'Enter a store name.' );
            } else {
                update_user_meta( $seller_id, 'tnm_store_name', $store_name );
                $tagline = mb_substr( sanitize_text_field( wp_unslash( $_POST['tagline'] ?? '' ) ), 0, 140 );
                update_user_meta( $seller_id, 'tnm_store_tagline', $tagline );
                update_user_meta( $seller_id, 'tnm_store_about', sanitize_textarea_field( wp_unslash( $_POST['about'] ?? '' ) ) );
                update_user_meta( $seller_id, 'tnm_email_optout_messages', empty( $_POST['email_optout_messages'] ) ? '' : '1' );
            }
        } elseif ( 'create_post' === $action ) {
            $image_id = tnm_upload_image_from_request( 'image' );
            if ( is_wp_error( $image_id ) ) {
                $result = $image_id;
            } else {
                $result = TNM_Social::create_post( $seller_id, array( 'title' => wp_unslash( $_POST['title'] ?? '' ), 'content' => wp_unslash( $_POST['content'] ?? '' ), 'image_id' => is_int( $image_id ) ? $image_id : 0 ) );
            }
        } elseif ( 'backfill_earnings' === $action ) {
            $summary = TNM_Ledger::backfill_seller( $seller_id );
            $added   = max( 0, (int) $summary['rows_after'] - (int) $summary['rows_before'] );
            return '<div class="tnm-notice tnm-success">Recalculated earnings from ' . esc_html( (string) $summary['orders'] ) . ' order(s). ' . esc_html( (string) $added ) . ' new ledger row(s) added.</div>';
        }
        if ( is_wp_error( $result ) ) {
            return '<div class="tnm-notice tnm-error">' . esc_html( $result->get_error_message() ) . '</div>';
        }
        return '<div class="tnm-notice tnm-success">Saved successfully.</div>';
    }

    private static function seller_product_query( int $seller_id ): array {
        // v3.13.13 — hydrate IDs directly instead of round-tripping through a
        // second WP_Query. WP_Query silently drops draft/pending/private
        // rows for callers without read_private_products (tnm_seller lacks
        // that cap), which is exactly why the web seller dashboard was
        // showing an under-count of products for sellers viewing their own
        // dashboard. tnm_seller_product_ids already returns date-desc.
        $product_ids = tnm_seller_product_ids( $seller_id, array( 'publish', 'pending', 'draft', 'private' ) );
        $product_ids = array_slice( $product_ids, 0, 100 );
        $products    = array();
        foreach ( $product_ids as $pid ) {
            $product = wc_get_product( $pid );
            if ( $product ) {
                $products[] = $product;
            }
        }
        return $products;
    }

    private static function render_products_table( array $products ): void {
        if ( ! $products ) {
            echo '<p class="tnm-muted">No products yet.</p>';
            return;
        }
        echo '<div class="tnm-table-wrap"><table class="tnm-table"><thead><tr><th>Product</th><th>Price</th><th>Stock</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
        foreach ( $products as $product ) {
            $pid   = (int) $product->get_id();
            $name  = (string) $product->get_name();
            $desc  = (string) $product->get_description();
            $price = (string) $product->get_regular_price();
            $stock = (string) $product->get_stock_quantity();
            $sku      = (string) $product->get_sku();
            $shipping = function_exists( 'mnu_ship_get_product_shipping' ) ? mnu_ship_get_product_shipping( $pid ) : array();

            echo '<tr data-product-id="' . esc_attr( (string) $pid ) . '">';
            echo '<td><a href="' . esc_url( get_permalink( $pid ) ) . '">' . esc_html( $name ) . '</a></td>';
            echo '<td>' . wp_kses_post( wc_price( $product->get_price() ) ) . '</td>';
            echo '<td>' . esc_html( $stock ) . '</td>';
            echo '<td>' . esc_html( ucfirst( $product->get_status() ) ) . '</td>';
            echo '<td class="tnm-product-actions">';
            echo '<button type="button" class="tnm-small-button tnm-edit-product" data-tnm-edit-product'
                . ' data-product-id="' . esc_attr( (string) $pid ) . '"'
                . ' data-name="' . esc_attr( $name ) . '"'
                . ' data-description="' . esc_attr( $desc ) . '"'
                . ' data-price="' . esc_attr( $price ) . '"'
                . ' data-stock="' . esc_attr( $stock ) . '"'
                . ' data-sku="' . esc_attr( $sku ) . '"'
                . ' data-weight-oz="' . esc_attr( (string) ( $shipping['weight_oz'] ?? '' ) ) . '"'
                . ' data-length-in="' . esc_attr( (string) ( $shipping['length_in'] ?? '' ) ) . '"'
                . ' data-width-in="' . esc_attr( (string) ( $shipping['width_in'] ?? '' ) ) . '"'
                . ' data-height-in="' . esc_attr( (string) ( $shipping['height_in'] ?? '' ) ) . '"'
                . ' data-package-size="' . esc_attr( (string) ( $shipping['package_size'] ?? 'custom' ) ) . '"'
                . ' data-processing-time="' . esc_attr( (string) ( $shipping['processing_time'] ?? '' ) ) . '">Edit</button>';
            // v3.7.105 (Build #3 web parity) - Duplicate button, adjacent to
            // Edit / Delete. Server clones the listing as a draft copy so the
            // seller can adjust the copy before publishing.
            echo '<form method="post" class="tnm-inline-form tnm-duplicate-form">';
            wp_nonce_field( 'tnm_dashboard', 'tnm_nonce' );
            echo '<input type="hidden" name="tnm_action" value="duplicate_product">';
            echo '<input type="hidden" name="product_id" value="' . esc_attr( (string) $pid ) . '">';
            echo '<button type="submit" class="tnm-small-button tnm-button-secondary">Duplicate</button>';
            echo '</form>';
            echo '<form method="post" class="tnm-inline-form tnm-delete-form" onsubmit="return confirm(\'Delete this product? This cannot be undone.\');">';
            wp_nonce_field( 'tnm_dashboard', 'tnm_nonce' );
            echo '<input type="hidden" name="tnm_action" value="delete_product">';
            echo '<input type="hidden" name="product_id" value="' . esc_attr( (string) $pid ) . '">';
            echo '<button type="submit" class="tnm-small-button tnm-danger">Delete</button>';
            echo '</form>';
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    private static function render_orders_table( array $orders, bool $editable = false ): void {
        if ( ! $orders ) {
            echo '<p class="tnm-muted">No seller orders found.</p>';
            return;
        }

        echo '<div class="tnm-table-wrap"><table class="tnm-table"><thead><tr><th>Order</th><th>Date</th><th>Items</th><th>Gross</th><th>' . esc_html( tnm_fee_label() ) . '</th><th>Status &amp; shipping</th></tr></thead><tbody>';
        foreach ( $orders as $order ) {
            $item_names = implode( ', ', array_map( static fn( array $item ): string => $item['quantity'] . '× ' . $item['name'], $order['items'] ) );
            echo '<tr><td><strong>#' . esc_html( $order['number'] ) . '</strong><br><small>' . esc_html( $order['customer']['name'] ) . '</small></td><td>' . esc_html( $order['date_created'] ? wp_date( get_option( 'date_format' ), strtotime( $order['date_created'] ) ) : '—' ) . '</td><td>' . esc_html( $item_names ) . '</td><td>' . esc_html( tnm_money( $order['gross'], $order['currency'] ) ) . '</td><td>-' . esc_html( tnm_money( $order['platform_fee'], $order['currency'] ) ) . '</td><td><strong>' . esc_html( ucfirst( $order['seller_status'] ) ) . '</strong>';

            if ( $editable ) {
                echo '<form method="post" class="tnm-inline-form tnm-order-status-form">';
                wp_nonce_field( 'tnm_dashboard', 'tnm_nonce' );
                echo '<input type="hidden" name="tnm_action" value="update_order"><input type="hidden" name="order_id" value="' . esc_attr( $order['id'] ) . '"><select name="status"><option value="processing">Processing</option><option value="shipped">Shipped</option><option value="completed">Completed</option><option value="cancelled">Cancelled</option></select><input type="text" name="tracking_number" placeholder="Tracking #" value="' . esc_attr( $order['tracking_number'] ) . '"><button type="submit" class="tnm-small-button">Update</button></form>';

                $wc_order = wc_get_order( (int) $order['id'] );
                $label    = $wc_order && function_exists( 'mnu_labels_payload' ) ? mnu_labels_payload( $wc_order, get_current_user_id() ) : array();
                echo '<div class="tnm-shipping-actions" data-tnm-shipping-order="' . esc_attr( (string) $order['id'] ) . '">';
                echo '<div class="tnm-label-summary" data-tnm-label-summary>';
                if ( ! empty( $label['label_url'] ) ) {
                    self::render_label_summary( $label );
                } elseif ( ! empty( $label['transaction'] ) ) {
                    echo '<p class="tnm-label-pending"><strong>Label processing</strong><br><span>Shippo has the purchase and is still preparing the PDF.</span></p>';
                    echo '<button type="button" class="tnm-small-button tnm-button-secondary" data-tnm-refresh-label>Refresh label</button>';
                } else {
                    echo '<button type="button" class="tnm-small-button" data-tnm-get-rates>Get shipping rates</button>';
                }
                echo '</div><div class="tnm-rate-results" data-tnm-rate-results hidden></div><div class="tnm-shipping-message" data-tnm-shipping-message aria-live="polite"></div></div>';
            }

            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    /**
     * @param array<string,mixed> $label
     */
    private static function render_label_summary( array $label ): void {
        $service = trim( (string) ( $label['carrier'] ?? '' ) . ' ' . (string) ( $label['service'] ?? '' ) );
        echo '<div class="tnm-label-ready"><p><strong>Label ready</strong>';
        if ( $service ) {
            echo '<br><span>' . esc_html( $service ) . '</span>';
        }
        if ( ! empty( $label['tracking_number'] ) ) {
            echo '<br><span>Tracking: ' . esc_html( (string) $label['tracking_number'] ) . '</span>';
        }
        echo '</p><a class="tnm-small-button" href="' . esc_url( (string) $label['label_url'] ) . '" target="_blank" rel="noopener noreferrer">Open PDF label</a></div>';
    }

    private static function render_ledger_table( array $entries ): void {
        if ( ! $entries ) {
            echo '<p class="tnm-muted">No transactions yet.</p>';
            return;
        }
        echo '<div class="tnm-table-wrap"><table class="tnm-table"><thead><tr><th>Date</th><th>Order</th><th>Type</th><th>Gross</th><th>Fee</th><th>Shipping</th><th>Net</th><th>Status</th></tr></thead><tbody>';
        foreach ( $entries as $entry ) {
            echo '<tr><td>' . esc_html( wp_date( get_option( 'date_format' ), strtotime( $entry['created_at'] . ' UTC' ) ) ) . '</td><td>#' . esc_html( $entry['order_id'] ) . '</td><td>' . esc_html( ucfirst( str_replace( '_', ' ', $entry['type'] ) ) ) . '</td><td>' . esc_html( tnm_money( $entry['gross'], $entry['currency'] ) ) . '</td><td>' . esc_html( tnm_money( $entry['platform_fee'], $entry['currency'] ) ) . '</td><td>' . esc_html( tnm_money( $entry['shipping'], $entry['currency'] ) ) . '</td><td><strong>' . esc_html( tnm_money( $entry['net'], $entry['currency'] ) ) . '</strong></td><td>' . esc_html( ucfirst( $entry['status'] ) ) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    private static function render_payouts_table( array $payouts ): void {
        if ( ! $payouts ) {
            echo '<p class="tnm-muted">No payouts yet.</p>';
            return;
        }
        echo '<div class="tnm-table-wrap"><table class="tnm-table"><thead><tr><th>Date</th><th>Amount</th><th>Method</th><th>Status</th></tr></thead><tbody>';
        foreach ( $payouts as $payout ) {
            echo '<tr><td>' . esc_html( wp_date( get_option( 'date_format' ), strtotime( $payout['requested_at'] . ' UTC' ) ) ) . '</td><td>' . esc_html( tnm_money( $payout['amount'], $payout['currency'] ) ) . '</td><td>' . esc_html( ucfirst( $payout['method'] ) ) . '</td><td>' . esc_html( ucfirst( $payout['status'] ) ) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    public static function feed(): string {
        self::assets();
        $feed = TNM_Social::feed( get_current_user_id(), max( 1, absint( $_GET['nest_page'] ?? 1 ) ), 20 );
        ob_start();
        ?>
        <div class="tnm-feed">
            <header class="tnm-feed-header"><h1>The Nest</h1><p><?php echo esc_html( 'following' === $feed['mode'] ? 'Updates from shops you follow' : 'Discover makers, artisans, and new listings' ); ?></p></header>
            <?php if ( ! $feed['items'] ) : ?><div class="tnm-card"><p>No posts yet. Browse the shop and follow sellers to personalize your feed.</p></div><?php endif; ?>
            <?php
            foreach ( $feed['items'] as $item ) :
                // The seller's public page is the WordPress author archive for the
                // seller/user id carried on every feed item. The marketplace has no
                // separate storefront route, so this is the canonical "shop or seller"
                // link. A social post click navigates here; a product click keeps
                // going to the product page, but its author byline still links here.
                $seller_id  = (int) $item['author']['id'];
                $seller_url = $seller_id > 0 ? get_author_posts_url( $seller_id ) : '';
                // Where the whole item (title / "read more") points.
                $item_url = 'product' === $item['type'] ? $item['permalink'] : ( $seller_url ?: $item['permalink'] );
                ?>
                <article class="tnm-card tnm-feed-post">
                    <div class="tnm-feed-author"><img src="<?php echo esc_url( $item['author']['avatar'] ); ?>" alt=""><div><strong><?php if ( $seller_url ) : ?><a href="<?php echo esc_url( $seller_url ); ?>"><?php echo esc_html( $item['author']['store_name'] ); ?></a><?php else : ?><?php echo esc_html( $item['author']['store_name'] ); ?><?php endif; ?></strong><small><?php echo esc_html( wp_date( get_option( 'date_format' ), strtotime( $item['date'] ) ) ); ?></small></div></div>
                    <h2><a href="<?php echo esc_url( $item_url ); ?>"><?php echo esc_html( $item['title'] ); ?></a></h2>
                    <?php if ( $item['image'] ) : ?><img class="tnm-feed-image" src="<?php echo esc_url( $item['image'] ); ?>" alt=""><?php endif; ?>
                    <?php if ( 'product' === $item['type'] && ! empty( $item['price_html'] ) ) : ?><div class="tnm-feed-price"><?php echo wp_kses_post( $item['price_html'] ); ?></div><?php endif; ?>
                    <p><?php echo esc_html( $item['excerpt'] ); ?></p>
                    <?php if ( 'product' === $item['type'] ) : ?>
                        <div class="tnm-feed-actions">
                            <?php echo self::product_quick_add( (int) $item['id'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                            <a class="tnm-product-details-link" href="<?php echo esc_url( $item['permalink'] ); ?>">View details</a>
                        </div>
                    <?php else : ?>
                        <a href="<?php echo esc_url( $item_url ); ?>">Visit shop</a>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}
