<?php

defined( 'ABSPATH' ) || exit;

/**
 * Compatibility, cutover, migration, and legacy shortcode layer.
 *
 * This class deliberately preserves existing MyNest page shortcodes and data
 * while using the unified TNM services as the single source of truth.
 */
final class MNU_Compat {
    private const MIGRATION_VERSION = 4;

    public static function init( bool $full_mode ): void {
        add_action( 'init', array( __CLASS__, 'migrate_legacy_data' ), 40 );
        add_filter( 'wp_nav_menu_objects', array( __CLASS__, 'filter_seller_menu_items' ), 20, 2 );
        add_filter( 'woocommerce_package_rates', array( __CLASS__, 'remove_local_pickup' ), 100, 2 );

        if ( ! $full_mode ) {
            return;
        }

        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ), 20 );

        $shortcodes = array(
            'mynest_home_feed'          => 'home_feed',
            'mynest_browse'             => 'browse',
            'mynest_create_hub'         => 'create_hub',
            'mynest_notifications'      => 'notifications',
            'mynest_profile'            => 'profile',
            'mynest_become_seller'      => 'become_seller',
            'mynest_seller_application' => 'seller_application',
            'mynest_seller_login'       => 'seller_login',
            'mynest_seller_dashboard'   => 'seller_dashboard',
            'mynest_seller_orders'      => 'seller_orders',
            'mynest_seller_order'       => 'seller_order',
            'mynest_seller_add_product' => 'seller_add_product',
            'mynest_create_blog'        => 'create_blog',
            'mynest_my_purchases'       => 'my_purchases',
            'mynest_reviews'            => 'reviews',
            'mynest_order_breakdown'    => 'order_breakdown',
            'mynest_seller_payouts'     => 'seller_payouts',
        );
        foreach ( $shortcodes as $tag => $method ) {
            add_shortcode( $tag, array( __CLASS__, $method ) );
        }
    }

    public static function register_assets(): void {
        wp_enqueue_style( 'tnm-frontend' );
        wp_enqueue_script( 'tnm-frontend' );
    }

    private static function assets(): void {
        wp_enqueue_style( 'tnm-frontend' );
        wp_enqueue_script( 'tnm-frontend' );
    }

    public static function filter_seller_menu_items( array $items, object $args ): array {
        if ( ! is_user_logged_in() || ! tnm_is_marketplace_user() ) {
            return $items;
        }
        return array_filter(
            $items,
            static function ( $item ): bool {
                $haystack = strtolower( html_entity_decode( (string) $item->title . ' ' . (string) $item->url, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
                return ! str_contains( $haystack, 'become a seller' ) && ! str_contains( $haystack, 'become-a-seller' ) && ! str_contains( $haystack, 'seller-application' );
            }
        );
    }

    public static function remove_local_pickup( array $rates, array $package ): array {
        if ( 'yes' !== tnm_get_option( 'remove_local_pickup', 'yes' ) ) {
            return $rates;
        }
        foreach ( $rates as $rate_id => $rate ) {
            $method_id = is_object( $rate ) && isset( $rate->method_id ) ? (string) $rate->method_id : '';
            if ( 'local_pickup' === $method_id || str_contains( strtolower( (string) $rate_id ), 'local_pickup' ) ) {
                unset( $rates[ $rate_id ] );
            }
        }
        return $rates;
    }

    public static function home_feed(): string {
        return TNM_Shortcodes::feed();
    }

    private static function product_quick_add( WC_Product $product_object ): string {
        if ( ! function_exists( 'woocommerce_template_loop_add_to_cart' ) || ! $product_object->is_visible() ) {
            return '';
        }

        if ( wp_script_is( 'wc-add-to-cart', 'registered' ) ) {
            wp_enqueue_script( 'wc-add-to-cart' );
        }

        global $product;
        $previous_product = $product;
        $product          = $product_object;

        ob_start();
        woocommerce_template_loop_add_to_cart();
        $button = (string) ob_get_clean();

        $product = $previous_product;
        return $button;
    }

    public static function browse(): string {
        self::assets();
        if ( ! function_exists( 'wc_get_products' ) ) {
            return '<div class="tnm-notice tnm-error">WooCommerce is required.</div>';
        }

        $search      = sanitize_text_field( wp_unslash( $_GET['mynest_search'] ?? '' ) );
        $category_id = absint( $_GET['mynest_category'] ?? 0 );
        $args        = array(
            'status'  => 'publish',
            'limit'   => 24,
            'orderby' => 'date',
            'order'   => 'DESC',
        );
        if ( $search ) {
            $args['s'] = $search;
        }
        if ( $category_id ) {
            $args['category'] = array( get_term_field( 'slug', $category_id, 'product_cat' ) );
        }
        $products   = wc_get_products( $args );
        $categories = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );

        ob_start();
        ?>
        <div class="mynest-wrap tnm-dashboard">
            <header class="tnm-dashboard-header"><div><h1>Browse</h1><p>Discover handmade products from independent sellers.</p></div></header>
            <form method="get" class="tnm-card tnm-form">
                <label>Search<input type="search" name="mynest_search" value="<?php echo esc_attr( $search ); ?>" placeholder="Search products"></label>
                <label>Category<select name="mynest_category"><option value="0">All categories</option>
                    <?php foreach ( is_array( $categories ) ? $categories : array() as $category ) : ?>
                        <option value="<?php echo esc_attr( $category->term_id ); ?>" <?php selected( $category_id, $category->term_id ); ?>><?php echo esc_html( html_entity_decode( $category->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ); ?></option>
                    <?php endforeach; ?>
                </select></label>
                <button class="tnm-button" type="submit">Search</button>
            </form>
            <div class="mnu-product-list">
                <?php if ( ! $products ) : ?><div class="tnm-card">No products matched your search.</div><?php endif; ?>
                <?php foreach ( $products as $product ) : ?>
                    <article class="tnm-card mnu-product-card">
                        <a href="<?php echo esc_url( get_permalink( $product->get_id() ) ); ?>" class="mnu-product-image"><?php echo wp_kses_post( $product->get_image( 'large' ) ); ?></a>
                        <div><h2><a href="<?php echo esc_url( get_permalink( $product->get_id() ) ); ?>"><?php echo esc_html( $product->get_name() ); ?></a></h2>
                        <p class="tnm-muted">By <?php echo esc_html( tnm_seller_display_name( tnm_get_product_seller_id( $product ) ) ); ?></p>
                        <p class="tnm-feed-price"><?php echo wp_kses_post( $product->get_price_html() ); ?></p>
                        <div class="mnu-product-actions">
                            <?php echo self::product_quick_add( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                            <a class="tnm-button tnm-button-secondary" href="<?php echo esc_url( get_permalink( $product->get_id() ) ); ?>">View details</a>
                        </div></div>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    public static function create_hub(): string {
        self::assets();
        ob_start();
        ?>
        <div class="tnm-dashboard"><header class="tnm-dashboard-header"><div><h1>Create</h1><p>Share an update or list something you made.</p></div></header>
            <div class="tnm-grid-2">
                <div class="tnm-card"><h2>Write a post</h2><p>Share your process, a behind-the-scenes update, or a new project.</p><?php if ( tnm_is_marketplace_user() ) : ?><a class="tnm-button" href="<?php echo esc_url( home_url( '/create-blog/' ) ); ?>">Create post</a><?php else : ?><p class="tnm-muted">Seller approval is required to publish.</p><?php endif; ?></div>
                <div class="tnm-card"><h2>List an item</h2><p>Create a product listing for your shop.</p><?php if ( tnm_is_marketplace_user() ) : ?><a class="tnm-button" href="<?php echo esc_url( home_url( '/seller-add-product/' ) ); ?>">List an item</a><?php else : ?><a class="tnm-button" href="<?php echo esc_url( home_url( '/become-a-seller/' ) ); ?>">Become a seller</a><?php endif; ?></div>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    public static function notifications(): string {
        self::assets();
        if ( ! is_user_logged_in() ) {
            return '<div class="tnm-notice">Please <a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">sign in</a> to see notifications.</div>';
        }
        $data = TNM_Social::notifications( get_current_user_id(), 1, 50 );
        TNM_Social::mark_notifications_read( get_current_user_id() );
        ob_start();
        echo '<div class="tnm-dashboard"><header class="tnm-dashboard-header"><div><h1>Notifications</h1><p>' . esc_html( $data['unread'] ) . ' unread</p></div></header>';
        if ( empty( $data['items'] ) ) {
            echo '<div class="tnm-card">No notifications yet.</div>';
        }
        foreach ( $data['items'] as $item ) {
            echo '<article class="tnm-card"><h2>' . esc_html( $item['title'] ) . '</h2><p>' . esc_html( $item['message'] ) . '</p><p class="tnm-muted">' . esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $item['created_at'], true ) ) . '</p>';
            if ( ! empty( $item['url'] ) ) {
                echo '<p><a class="tnm-button tnm-button-secondary" href="' . esc_url( $item['url'] ) . '">Open</a></p>';
            }
            echo '</article>';
        }
        echo '</div>';
        return (string) ob_get_clean();
    }

    public static function profile(): string {
        self::assets();
        if ( ! is_user_logged_in() ) {
            $account_url = wc_get_page_permalink( 'myaccount' );
            return '<div class="mynest-wrap"><section class="mnu-auth-gateway"><span class="mnu-eyebrow">Buyer account</span><h1>Sign in or create your free MyNest account</h1><p>Track purchases, save addresses, leave verified reviews, and follow your favorite sellers. You can still shop and check out as a guest.</p><div class="mnu-auth-actions"><a class="tnm-button" href="' . esc_url( $account_url ) . '">Sign in or register</a><a class="tnm-button tnm-button-secondary" href="' . esc_url( wc_get_page_permalink( 'shop' ) ) . '">Continue shopping</a></div></section></div>';
        }
        $user      = wp_get_current_user();
        $photo_id  = (int) get_user_meta( $user->ID, 'thenest_profile_photo_id', true );
        $photo_url = tnm_user_avatar_url( $user->ID, 256 );
        ob_start();
        ?>
        <div class="tnm-dashboard"><header class="tnm-dashboard-header"><div><h1>Profile</h1><p>Your account and marketplace activity.</p></div></header>
            <div class="tnm-grid-2">
                <div class="tnm-card"><img src="<?php echo esc_url( $photo_url ); ?>" alt="" style="width:112px;height:112px;border-radius:50%;object-fit:cover"><h2><?php echo esc_html( $user->display_name ); ?></h2><p><?php echo esc_html( $user->user_email ); ?></p><p><a class="tnm-button tnm-button-secondary" href="<?php echo esc_url( get_edit_profile_url( $user->ID ) ); ?>">Edit profile</a></p></div>
                <div class="tnm-card"><h2>Account</h2><p><a href="<?php echo esc_url( home_url( '/my-purchases/' ) ); ?>">My purchases</a></p><?php if ( tnm_is_marketplace_user() ) : ?><p><a href="<?php echo esc_url( home_url( '/seller-dashboard/' ) ); ?>">Seller dashboard</a></p><p><a href="<?php echo esc_url( home_url( '/seller-payouts/' ) ); ?>">Seller earnings</a></p><?php else : ?><p><a href="<?php echo esc_url( home_url( '/become-a-seller/' ) ); ?>">Become a seller</a></p><?php endif; ?><p><a href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>">Sign out</a></p></div>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    public static function become_seller(): string {
        self::assets();
        if ( tnm_is_marketplace_user() ) {
            return '<div class="tnm-notice tnm-success">Your seller account is already active. <a href="' . esc_url( home_url( '/seller-dashboard/' ) ) . '">Open your seller dashboard</a>.</div>';
        }
        return '<div class="tnm-card tnm-form-card"><h1>Start Your Nest</h1><p>Apply to open your own shop and sell handmade products through MyNest.</p><a class="tnm-button" href="' . esc_url( home_url( '/seller-application/' ) ) . '">Start seller application</a></div>';
    }

    public static function seller_application(): string {
        return TNM_Shortcodes::application();
    }

    public static function seller_login(): string {
        self::assets();
        if ( is_user_logged_in() ) {
            return '<div class="tnm-notice tnm-success">You are signed in. <a href="' . esc_url( home_url( '/seller-dashboard/' ) ) . '">Open Seller Dashboard</a>.</div>';
        }
        ob_start();
        echo '<div class="tnm-card tnm-form-card"><h1>Seller Login</h1>';
        wp_login_form( array( 'redirect' => home_url( '/seller-dashboard/' ) ) );
        echo '</div>';
        return (string) ob_get_clean();
    }

    public static function seller_dashboard(): string {
        return TNM_Shortcodes::dashboard();
    }

    private static function dashboard_alias( string $tab ): string {
        $html = TNM_Shortcodes::dashboard();
        $html .= '<script>document.addEventListener("DOMContentLoaded",function(){var b=document.querySelector("[data-tnm-tab=\"' . esc_js( $tab ) . '\"]");if(b){b.click();}});</script>';
        return $html;
    }

    public static function seller_orders(): string {
        return self::dashboard_alias( 'orders' );
    }

    public static function seller_add_product(): string {
        return self::dashboard_alias( 'products' );
    }

    public static function seller_payouts(): string {
        return self::dashboard_alias( 'payouts' );
    }

    public static function seller_order(): string {
        self::assets();
        if ( ! is_user_logged_in() || ! tnm_is_marketplace_user() ) {
            return '<div class="tnm-notice">Seller access is required.</div>';
        }
        $order_id = absint( $_GET['order_id'] ?? 0 );
        $order    = $order_id ? wc_get_order( $order_id ) : false;
        if ( ! $order || ! tnm_order_contains_seller( $order, get_current_user_id() ) ) {
            return '<div class="tnm-notice tnm-error">Order not found or unavailable.</div>';
        }
        return self::render_order( $order, get_current_user_id(), true );
    }

    public static function create_blog(): string {
        self::assets();
        if ( ! is_user_logged_in() || ! tnm_is_marketplace_user() ) {
            return '<div class="tnm-notice">An approved seller account is required.</div>';
        }
        $message = '';
        if ( isset( $_POST['mnu_create_post'] ) ) {
            if ( ! isset( $_POST['mnu_post_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mnu_post_nonce'] ) ), 'mnu_create_post' ) ) {
                $message = '<div class="tnm-notice tnm-error">Security check failed.</div>';
            } else {
                $result = TNM_Social::create_post(
                    get_current_user_id(),
                    array(
                        'title'   => wp_unslash( $_POST['title'] ?? '' ),
                        'content' => wp_unslash( $_POST['content'] ?? '' ),
                    )
                );
                $message = is_wp_error( $result ) ? '<div class="tnm-notice tnm-error">' . esc_html( $result->get_error_message() ) . '</div>' : '<div class="tnm-notice tnm-success">Post published.</div>';
            }
        }
        ob_start();
        echo $message; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        ?>
        <div class="tnm-card tnm-form-card"><h1>Create a post</h1><form method="post" class="tnm-form"><?php wp_nonce_field( 'mnu_create_post', 'mnu_post_nonce' ); ?><input type="hidden" name="mnu_create_post" value="1"><label>Title<input type="text" name="title" required></label><label>Post<textarea name="content" rows="10" required></textarea></label><button class="tnm-button" type="submit">Publish post</button></form></div>
        <?php
        return (string) ob_get_clean();
    }

    public static function my_purchases(): string {
        self::assets();
        if ( ! is_user_logged_in() ) {
            return '<div class="tnm-notice">Please sign in to view purchases.</div>';
        }
        $orders = wc_get_orders(
            array(
                'customer_id' => get_current_user_id(),
                'limit'       => 30,
                'orderby'     => 'date',
                'order'       => 'DESC',
                'return'      => 'objects',
            )
        );
        ob_start();
        echo '<div class="tnm-dashboard"><header class="tnm-dashboard-header"><div><h1>My Purchases</h1><p>Your recent orders.</p></div></header>';
        if ( ! $orders ) {
            echo '<div class="tnm-card">No purchases yet.</div>';
        }
        foreach ( $orders as $order ) {
            echo self::render_order( $order, 0, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
        echo '</div>';
        return (string) ob_get_clean();
    }

    public static function reviews(): string {
        self::assets();
        $seller_id = absint( $_GET['seller_id'] ?? 0 );
        $message   = '';
        if ( isset( $_POST['mnu_submit_review'] ) && is_user_logged_in() ) {
            if ( ! isset( $_POST['mnu_review_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mnu_review_nonce'] ) ), 'mnu_submit_review' ) ) {
                $message = '<div class="tnm-notice tnm-error">Security check failed.</div>';
            } else {
                $seller_id = absint( $_POST['seller_id'] ?? 0 );
                $result    = TNM_Social::submit_review( get_current_user_id(), $seller_id, absint( $_POST['rating'] ?? 5 ), wp_unslash( $_POST['review'] ?? '' ), absint( $_POST['order_id'] ?? 0 ) );
                $message   = is_wp_error( $result ) ? '<div class="tnm-notice tnm-error">' . esc_html( $result->get_error_message() ) . '</div>' : '<div class="tnm-notice tnm-success">Review submitted.</div>';
            }
        }
        $data = $seller_id ? TNM_Social::seller_reviews( $seller_id, 1, 50 ) : array( 'items' => array(), 'average' => 0, 'total' => 0 );
        ob_start();
        echo '<div class="tnm-dashboard"><header class="tnm-dashboard-header"><div><h1>Reviews</h1><p>Verified marketplace feedback.</p></div></header>' . $message;
        if ( $seller_id ) {
            echo '<div class="tnm-card"><h2>' . esc_html( tnm_seller_display_name( $seller_id ) ) . '</h2><p>' . esc_html( $data['average'] ) . ' / 5 from ' . esc_html( $data['total'] ) . ' reviews</p></div>';
            if ( is_user_logged_in() ) {
                $order_id = TNM_Social::can_review( get_current_user_id(), $seller_id );
                if ( $order_id ) {
                    ?>
                    <form method="post" class="tnm-card tnm-form"><?php wp_nonce_field( 'mnu_submit_review', 'mnu_review_nonce' ); ?><input type="hidden" name="mnu_submit_review" value="1"><input type="hidden" name="seller_id" value="<?php echo esc_attr( $seller_id ); ?>"><input type="hidden" name="order_id" value="<?php echo esc_attr( $order_id ); ?>"><label>Rating<select name="rating"><option value="5">5 stars</option><option value="4">4 stars</option><option value="3">3 stars</option><option value="2">2 stars</option><option value="1">1 star</option></select></label><label>Review<textarea name="review" rows="5" required></textarea></label><button class="tnm-button" type="submit">Submit review</button></form>
                    <?php
                }
            }
        } else {
            echo '<div class="tnm-card">Open a seller profile to view their reviews.</div>';
        }
        foreach ( $data['items'] as $item ) {
            echo '<article class="tnm-card"><p>' . esc_html( str_repeat( '★', (int) $item['rating'] ) ) . '</p><p>' . esc_html( $item['review'] ) . '</p><p class="tnm-muted">' . esc_html( $item['reviewer']['display_name'] ) . '</p></article>';
        }
        echo '</div>';
        return (string) ob_get_clean();
    }

    public static function order_breakdown(): string {
        self::assets();
        $order_id = absint( $_GET['order_id'] ?? 0 );
        $order    = $order_id ? wc_get_order( $order_id ) : false;
        if ( ! $order ) {
            return '<div class="tnm-notice tnm-error">Order not found.</div>';
        }
        $can_view = current_user_can( 'manage_woocommerce' ) || (int) $order->get_customer_id() === get_current_user_id() || ( tnm_is_seller() && tnm_order_contains_seller( $order, get_current_user_id() ) );
        return $can_view ? self::render_order( $order, tnm_is_seller() ? get_current_user_id() : 0, tnm_is_seller() ) : '<div class="tnm-notice tnm-error">You cannot view this order.</div>';
    }

    private static function render_order( WC_Order $order, int $seller_id = 0, bool $seller_context = false ): string {
        ob_start();
        ?>
        <article class="tnm-card">
            <h2>Order #<?php echo esc_html( $order->get_order_number() ); ?></h2>
            <p>Status: <strong><?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?></strong></p>
            <div class="tnm-table-wrap"><table class="tnm-table"><thead><tr><th>Item</th><th>Qty</th><th>Subtotal</th><?php if ( $seller_context ) : ?><th><?php echo esc_html( tnm_fee_label() ); ?></th><th>Seller net</th><?php endif; ?></tr></thead><tbody>
            <?php foreach ( $order->get_items() as $item ) :
                if ( $seller_id && tnm_get_order_item_seller_id( $item ) !== $seller_id ) {
                    continue;
                }
                $gross    = (float) $item->get_total();
                $fee_meta = $item->get_meta( '_tnm_platform_fee', true );
                if ( '' === $fee_meta ) {
                    $fee_meta = $item->get_meta( '_mynest_nestkeeper_fee', true );
                }
                $fee = '' === $fee_meta ? round( $gross * ( tnm_fee_percent() / 100 ), wc_get_price_decimals() ) : (float) $fee_meta;
                ?>
                <tr><td><?php echo esc_html( $item->get_name() ); ?></td><td><?php echo esc_html( $item->get_quantity() ); ?></td><td><?php echo wp_kses_post( wc_price( $gross, array( 'currency' => $order->get_currency() ) ) ); ?></td><?php if ( $seller_context ) : ?><td>-<?php echo wp_kses_post( wc_price( $fee, array( 'currency' => $order->get_currency() ) ) ); ?></td><td><?php echo wp_kses_post( wc_price( max( 0, $gross - $fee ), array( 'currency' => $order->get_currency() ) ) ); ?></td><?php endif; ?></tr>
            <?php endforeach; ?>
            </tbody></table></div>
            <p><strong>Shipping:</strong> <?php echo wp_kses_post( wc_price( (float) $order->get_shipping_total(), array( 'currency' => $order->get_currency() ) ) ); ?><br><strong>Tax:</strong> <?php echo wp_kses_post( wc_price( (float) $order->get_total_tax(), array( 'currency' => $order->get_currency() ) ) ); ?><br><strong>Order total:</strong> <?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></p>
        </article>
        <?php
        return (string) ob_get_clean();
    }

    public static function migrate_legacy_data(): void {
        if ( (int) get_option( 'mnu_migration_version', 0 ) >= self::MIGRATION_VERSION ) {
            return;
        }
        if ( ! function_exists( 'tnm_table' ) || ! class_exists( 'MNU_Install' ) ) {
            return;
        }

        MNU_Install::create_roles();
        MNU_Install::create_tables();
        self::migrate_settings();
        self::migrate_seller_roles();
        self::migrate_products();
        self::migrate_follows();
        self::migrate_applications();
        self::migrate_reviews();
        self::migrate_order_metadata();

        update_option( 'mnu_migration_version', self::MIGRATION_VERSION, false );
    }

    private static function migrate_settings(): void {
        $legacy = get_option( 'mynest_core_settings', array() );
        if ( ! is_array( $legacy ) || ! $legacy ) {
            return;
        }
        $settings = get_option( 'tnm_settings', array() );
        $map      = array(
            'fee_percent' => 'fee_percent',
            'fee_label'   => 'fee_label',
            'hold_days'   => 'holding_days',
        );
        foreach ( $map as $old => $new ) {
            if ( isset( $legacy[ $old ] ) && '' !== (string) $legacy[ $old ] ) {
                $settings[ $new ] = $legacy[ $old ];
            }
        }
        if ( isset( $legacy['seller_products_pending'] ) ) {
            $settings['seller_can_publish'] = 'yes' === $legacy['seller_products_pending'] ? 'no' : 'yes';
        }
        // Automatic money movement always remains disabled after migration.
        $settings['automatic_payouts'] = 'no';
        update_option( 'tnm_settings', $settings, false );
    }

    private static function migrate_seller_roles(): void {
        $users = get_users( array( 'role' => 'mynest_seller', 'fields' => 'all' ) );
        foreach ( $users as $user ) {
            if ( ! in_array( 'tnm_seller', (array) $user->roles, true ) ) {
                $user->add_role( 'tnm_seller' );
            }
        }
    }

    private static function migrate_products(): void {
        $ids = get_posts(
            array(
                'post_type'      => 'product',
                'post_status'    => 'any',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'meta_query'     => array(
                    array( 'key' => '_mynest_seller_id', 'compare' => 'EXISTS' ),
                ),
            )
        );
        foreach ( $ids as $product_id ) {
            $seller_id = (int) get_post_meta( $product_id, '_mynest_seller_id', true );
            if ( $seller_id && ! get_post_meta( $product_id, '_tnm_seller_id', true ) ) {
                update_post_meta( $product_id, '_tnm_seller_id', $seller_id );
            }
        }
    }

    private static function migrate_follows(): void {
        global $wpdb;
        $users = get_users( array( 'meta_key' => '_mynest_following', 'fields' => 'ID' ) );
        foreach ( $users as $user_id ) {
            $following = get_user_meta( $user_id, '_mynest_following', true );
            foreach ( array_filter( array_map( 'absint', is_array( $following ) ? $following : array() ) ) as $following_id ) {
                $wpdb->query(
                    $wpdb->prepare(
                        'INSERT IGNORE INTO ' . tnm_table( 'follows' ) . ' (follower_id,following_id,created_at) VALUES (%d,%d,%s)',
                        $user_id,
                        $following_id,
                        current_time( 'mysql', true )
                    )
                );
            }
        }
    }

    private static function migrate_applications(): void {
        $apps = get_posts( array( 'post_type' => 'mynest_application', 'post_status' => 'any', 'posts_per_page' => -1 ) );
        foreach ( $apps as $app ) {
            $existing = get_posts(
                array(
                    'post_type'      => 'tnm_application',
                    'post_status'    => 'any',
                    'posts_per_page' => 1,
                    'fields'         => 'ids',
                    'meta_key'       => '_mnu_legacy_application_id',
                    'meta_value'     => $app->ID,
                )
            );
            if ( $existing ) {
                continue;
            }
            $status = strtolower( (string) get_post_meta( $app->ID, '_mynest_status', true ) );
            $new_id = wp_insert_post(
                array(
                    'post_type'    => 'tnm_application',
                    'post_status'  => in_array( $status, array( 'approved', 'publish' ), true ) ? 'publish' : 'pending',
                    'post_author'  => $app->post_author,
                    'post_title'   => $app->post_title,
                    'post_content' => $app->post_content,
                ),
                true
            );
            if ( is_wp_error( $new_id ) ) {
                continue;
            }
            update_post_meta( $new_id, '_mnu_legacy_application_id', $app->ID );
            update_post_meta( $new_id, '_tnm_status', in_array( $status, array( 'approved', 'publish' ), true ) ? 'approved' : 'pending' );
            update_post_meta( $new_id, '_tnm_store_name', get_post_meta( $app->ID, '_mynest_shop_name', true ) );
        }
    }

    private static function migrate_reviews(): void {
        global $wpdb;
        $reviews = get_posts( array( 'post_type' => 'mynest_review', 'post_status' => array( 'publish', 'pending' ), 'posts_per_page' => -1 ) );
        foreach ( $reviews as $review ) {
            if ( 'seller' !== (string) get_post_meta( $review->ID, '_mynest_review_type', true ) ) {
                continue;
            }
            $seller_id = (int) get_post_meta( $review->ID, '_mynest_target_id', true );
            $order_id  = (int) get_post_meta( $review->ID, '_mynest_order_id', true );
            if ( ! $seller_id || ! $order_id || ! $review->post_author ) {
                continue;
            }
            $wpdb->query(
                $wpdb->prepare(
                    'INSERT IGNORE INTO ' . tnm_table( 'reviews' ) . ' (reviewer_id,seller_id,order_id,rating,review,status,created_at,updated_at) VALUES (%d,%d,%d,%d,%s,%s,%s,%s)',
                    $review->post_author,
                    $seller_id,
                    $order_id,
                    max( 1, min( 5, (int) get_post_meta( $review->ID, '_mynest_rating', true ) ) ),
                    $review->post_content,
                    'publish' === $review->post_status ? 'approved' : 'pending',
                    get_gmt_from_date( $review->post_date ),
                    get_gmt_from_date( $review->post_modified )
                )
            );
        }
    }

    private static function migrate_order_metadata(): void {
        if ( ! function_exists( 'wc_get_orders' ) ) {
            return;
        }
        $orders = wc_get_orders( array( 'limit' => 250, 'orderby' => 'date', 'order' => 'DESC', 'return' => 'objects', 'status' => array_keys( wc_get_order_statuses() ) ) );
        foreach ( $orders as $order ) {
            $seller_ids = array();
            foreach ( $order->get_items() as $item ) {
                $seller_id = tnm_get_order_item_seller_id( $item );
                if ( ! $seller_id ) {
                    continue;
                }
                $seller_ids[ $seller_id ] = true;
                if ( ! $item->get_meta( '_tnm_seller_id', true ) ) {
                    $item->update_meta_data( '_tnm_seller_id', $seller_id );
                }
                if ( '' === $item->get_meta( '_tnm_platform_fee', true ) && '' !== $item->get_meta( '_mynest_nestkeeper_fee', true ) ) {
                    $item->update_meta_data( '_tnm_platform_fee', (float) $item->get_meta( '_mynest_nestkeeper_fee', true ) );
                }
                $item->save();

                $legacy_status   = $order->get_meta( '_mynest_seller_status_' . $seller_id, true );
                $legacy_tracking = $order->get_meta( '_mynest_tracking_number_' . $seller_id, true );
                if ( $legacy_status && ! $order->get_meta( '_tnm_seller_status_' . $seller_id, true ) ) {
                    $order->update_meta_data( '_tnm_seller_status_' . $seller_id, $legacy_status );
                }
                if ( $legacy_tracking && ! $order->get_meta( '_tnm_tracking_' . $seller_id, true ) ) {
                    $order->update_meta_data( '_tnm_tracking_' . $seller_id, $legacy_tracking );
                }
            }
            if ( $seller_ids ) {
                $order->update_meta_data( '_tnm_seller_ids', ',' . implode( ',', array_map( 'absint', array_keys( $seller_ids ) ) ) . ',' );
                $order->save();
            }
        }
    }
}
