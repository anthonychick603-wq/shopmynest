<?php

defined( 'ABSPATH' ) || exit;

/**
 * Buyer accounts, guest checkout, navigation, and WooCommerce presentation.
 */
final class MNU_Buyer_Experience {
    private static bool $navigation_account_added = false;

    public static function init(): void {
        add_action( 'init', array( __CLASS__, 'maybe_configure_accounts' ), 55 );
        add_action( 'wp', array( __CLASS__, 'ensure_classic_catalog_button' ), 40 );

        add_filter( 'body_class', array( __CLASS__, 'body_classes' ) );
        add_filter( 'woocommerce_create_account_default_checked', '__return_false' );
        add_filter( 'woocommerce_product_add_to_cart_text', array( __CLASS__, 'quick_add_text' ), 20, 2 );
        add_filter( 'woocommerce_loop_add_to_cart_args', array( __CLASS__, 'catalog_button_args' ), 20, 2 );

        add_action( 'woocommerce_before_customer_login_form', array( __CLASS__, 'account_intro' ) );
        add_action( 'woocommerce_register_form_start', array( __CLASS__, 'registration_fields' ) );
        add_filter( 'woocommerce_registration_errors', array( __CLASS__, 'validate_registration_fields' ), 10, 3 );
        add_action( 'woocommerce_created_customer', array( __CLASS__, 'save_registration_fields' ), 10, 3 );
        add_action( 'woocommerce_thankyou', array( __CLASS__, 'guest_account_prompt' ), 25 );

        /*
         * Assembler's stock archive-product template omits the Product Button
         * block. Patch the rendered template from this plugin so theme updates
         * cannot remove quick add from the marketplace again.
         */
        add_filter( 'get_block_templates', array( __CLASS__, 'add_catalog_buttons_to_templates' ), 120, 3 );
        add_filter( 'render_block_data', array( __CLASS__, 'add_catalog_button_to_product_template' ), 30, 2 );
        add_filter( 'render_block', array( __CLASS__, 'filter_rendered_blocks' ), 20, 2 );
    }

    /**
     * Keep the buyer-account behavior requested for The Nest consistent.
     */
    public static function configure_accounts(): void {
        $settings = array(
            'woocommerce_enable_guest_checkout'                  => 'yes',
            'woocommerce_enable_signup_and_login_from_checkout' => 'yes',
            'woocommerce_enable_myaccount_registration'          => 'yes',
            'woocommerce_registration_generate_username'         => 'yes',
            'woocommerce_registration_generate_password'         => 'yes',
        );

        foreach ( $settings as $option => $value ) {
            if ( $value !== get_option( $option, '' ) ) {
                update_option( $option, $value, false );
            }
        }

        update_option( 'mnu_buyer_accounts_configured', MNU_VERSION, false );
    }

    public static function maybe_configure_accounts(): void {
        if ( MNU_VERSION !== (string) get_option( 'mnu_buyer_accounts_configured', '' ) ) {
            self::configure_accounts();
        }
    }

    public static function body_classes( array $classes ): array {
        if ( function_exists( 'is_woocommerce' ) && ( is_woocommerce() || is_cart() || is_checkout() || is_account_page() ) ) {
            $classes[] = 'mnu-woocommerce-ui';
        }
        $classes[] = is_user_logged_in() ? 'mnu-customer-signed-in' : 'mnu-customer-signed-out';
        return $classes;
    }

    /**
     * Restore the standard WooCommerce catalog button if another theme or
     * extension removed it from classic product loops.
     */
    public static function ensure_classic_catalog_button(): void {
        if ( false === has_action( 'woocommerce_after_shop_loop_item', 'woocommerce_template_loop_add_to_cart' ) ) {
            add_action( 'woocommerce_after_shop_loop_item', 'woocommerce_template_loop_add_to_cart', 10 );
        }
    }

    /**
     * Give simple, in-stock products a clear quick-purchase label while
     * retaining WooCommerce's normal labels for variations and other types.
     */
    public static function quick_add_text( string $text, $product ): string {
        if (
            $product instanceof WC_Product
            && $product->is_type( 'simple' )
            && $product->is_purchasable()
            && $product->is_in_stock()
        ) {
            return 'Quick add';
        }

        return $text;
    }

    /**
     * Add a stable class used by both classic and block catalog buttons.
     */
    public static function catalog_button_args( array $args, $product ): array {
        $classes       = preg_split( '/\s+/', trim( (string) ( $args['class'] ?? '' ) ) ) ?: array();
        $classes[]     = 'mnu-quick-add-button';
        $args['class'] = implode( ' ', array_unique( array_filter( $classes ) ) );
        return $args;
    }

    /**
     * Add a WooCommerce Product Button block to catalog templates that omit it.
     */
    public static function add_catalog_buttons_to_templates( array $templates, array $query, string $template_type ): array {
        if ( 'wp_template' !== $template_type ) {
            return $templates;
        }

        $catalog_templates = array(
            'archive-product',
            'product-search-results',
            'taxonomy-product_cat',
            'taxonomy-product_tag',
            'taxonomy-product_attribute',
        );

        foreach ( $templates as $index => $template ) {
            if ( ! is_object( $template ) || ! isset( $template->slug, $template->content ) ) {
                continue;
            }

            if ( ! in_array( (string) $template->slug, $catalog_templates, true ) ) {
                continue;
            }

            $updated_content = self::inject_product_button_markup( (string) $template->content );
            if ( $updated_content === (string) $template->content ) {
                continue;
            }

            $patched          = clone $template;
            $patched->content = $updated_content;
            $templates[ $index ] = $patched;
        }

        return $templates;
    }

    /**
     * Runtime fallback for custom catalog templates that contain a product
     * template but no Product Button block.
     */
    public static function add_catalog_button_to_product_template( array $parsed_block, array $source_block ): array {
        if ( 'woocommerce/product-template' !== (string) ( $parsed_block['blockName'] ?? '' ) ) {
            return $parsed_block;
        }

        if ( self::block_tree_contains( $parsed_block['innerBlocks'] ?? array(), 'woocommerce/product-button' ) ) {
            return $parsed_block;
        }

        $parsed_block['innerBlocks'] = isset( $parsed_block['innerBlocks'] ) && is_array( $parsed_block['innerBlocks'] )
            ? $parsed_block['innerBlocks']
            : array();
        $parsed_block['innerContent'] = isset( $parsed_block['innerContent'] ) && is_array( $parsed_block['innerContent'] )
            ? $parsed_block['innerContent']
            : array();

        $parsed_block['innerBlocks'][] = array(
            'blockName'    => 'woocommerce/product-button',
            'attrs'        => array(
                'textAlign'               => 'center',
                'isDescendentOfQueryLoop' => true,
                'className'               => 'mnu-quick-add-block',
            ),
            'innerBlocks'  => array(),
            'innerHTML'    => '',
            'innerContent' => array(),
        );

        $parsed_block['innerContent'][] = "\n";
        $parsed_block['innerContent'][] = null;
        $parsed_block['innerContent'][] = "\n";

        return $parsed_block;
    }

    private static function inject_product_button_markup( string $content ): string {
        if (
            ! str_contains( $content, '<!-- wp:woocommerce/product-template' )
            || str_contains( $content, '<!-- wp:woocommerce/product-button' )
        ) {
            return $content;
        }

        $button = "\n\n<!-- wp:woocommerce/product-button {\"textAlign\":\"center\",\"isDescendentOfQueryLoop\":true,\"className\":\"mnu-quick-add-block\"} /-->\n";
        return str_replace( '<!-- /wp:woocommerce/product-template -->', $button . '<!-- /wp:woocommerce/product-template -->', $content );
    }

    private static function block_tree_contains( array $blocks, string $block_name ): bool {
        foreach ( $blocks as $block ) {
            if ( $block_name === (string) ( $block['blockName'] ?? '' ) ) {
                return true;
            }
            if ( ! empty( $block['innerBlocks'] ) && self::block_tree_contains( (array) $block['innerBlocks'], $block_name ) ) {
                return true;
            }
        }
        return false;
    }

    public static function account_intro(): void {
        ?>
        <section class="mnu-account-hero" aria-labelledby="mnu-account-heading">
            <div>
                <span class="mnu-eyebrow">Welcome to MyNest</span>
                <h1 id="mnu-account-heading">Your account, your orders, your favorite makers.</h1>
                <p>Create a free buyer account to track purchases, save addresses, leave verified reviews, and apply to open a shop later.</p>
            </div>
            <ul class="mnu-account-benefits" aria-label="Account benefits">
                <li>Track orders in one place</li>
                <li>Faster future checkout</li>
                <li>Save favorites and reviews</li>
            </ul>
        </section>
        <?php
    }

    public static function registration_fields(): void {
        $first_name = sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) );
        $last_name  = sanitize_text_field( wp_unslash( $_POST['last_name'] ?? '' ) );
        ?>
        <p class="form-row form-row-first">
            <label for="reg_first_name">First name&nbsp;<span class="required" aria-hidden="true">*</span><span class="screen-reader-text">Required</span></label>
            <input type="text" class="input-text" name="first_name" id="reg_first_name" autocomplete="given-name" value="<?php echo esc_attr( $first_name ); ?>" required>
        </p>
        <p class="form-row form-row-last">
            <label for="reg_last_name">Last name&nbsp;<span class="required" aria-hidden="true">*</span><span class="screen-reader-text">Required</span></label>
            <input type="text" class="input-text" name="last_name" id="reg_last_name" autocomplete="family-name" value="<?php echo esc_attr( $last_name ); ?>" required>
        </p>
        <div class="clear"></div>
        <?php
    }

    public static function validate_registration_fields( WP_Error $errors, string $username, string $email ): WP_Error {
        // Validate the dedicated My Account registration form without blocking
        // optional account creation handled by the classic or block checkout.
        if ( ! isset( $_POST['register'] ) ) {
            return $errors;
        }

        $first_name = sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) );
        $last_name  = sanitize_text_field( wp_unslash( $_POST['last_name'] ?? '' ) );

        if ( '' === $first_name ) {
            $errors->add( 'first_name_required', 'Please enter your first name.' );
        }
        if ( '' === $last_name ) {
            $errors->add( 'last_name_required', 'Please enter your last name.' );
        }
        return $errors;
    }

    public static function save_registration_fields( int $customer_id, array $new_customer_data = array(), bool $password_generated = false ): void {
        $first_name = sanitize_text_field(
            wp_unslash( $_POST['first_name'] ?? $_POST['billing_first_name'] ?? '' )
        );
        $last_name = sanitize_text_field(
            wp_unslash( $_POST['last_name'] ?? $_POST['billing_last_name'] ?? '' )
        );

        if ( $first_name ) {
            update_user_meta( $customer_id, 'first_name', $first_name );
            update_user_meta( $customer_id, 'billing_first_name', $first_name );
        }
        if ( $last_name ) {
            update_user_meta( $customer_id, 'last_name', $last_name );
            update_user_meta( $customer_id, 'billing_last_name', $last_name );
        }

        $display_name = trim( $first_name . ' ' . $last_name );
        if ( $display_name ) {
            wp_update_user(
                array(
                    'ID'           => $customer_id,
                    'display_name' => $display_name,
                )
            );
        }

        if ( function_exists( 'wc_update_new_customer_past_orders' ) ) {
            wc_update_new_customer_past_orders( $customer_id );
        }
    }

    public static function guest_account_prompt( int $order_id ): void {
        if ( is_user_logged_in() || ! $order_id ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order || $order->get_customer_id() ) {
            return;
        }

        $account_url = wc_get_page_permalink( 'myaccount' );
        ?>
        <section class="mnu-post-purchase-account">
            <div>
                <span class="mnu-eyebrow">Save this purchase</span>
                <h2>Create a free buyer account</h2>
                <p>Register with <strong><?php echo esc_html( $order->get_billing_email() ); ?></strong> and MyNest will connect eligible past guest orders to your account.</p>
            </div>
            <a class="tnm-button" href="<?php echo esc_url( $account_url ); ?>">Create account</a>
        </section>
        <?php
    }

    public static function filter_rendered_blocks( string $block_content, array $block ): string {
        $block_name = (string) ( $block['blockName'] ?? '' );

        /*
         * Assembler includes a placeholder "Learn more" button in its stock
         * header pattern. It has no destination and crowds the account/cart
         * controls on mobile, so remove only that unlinked placeholder button.
         */
        if ( 'core/button' === $block_name ) {
            $block_content = self::remove_header_learn_more_button( $block_content );
        }

        // Template-part fallback for cached or pre-rendered header markup.
        if ( 'core/template-part' === $block_name && self::is_header_template_part( $block ) ) {
            $block_content = self::remove_header_learn_more_button( $block_content );
        }

        if ( 'woocommerce/checkout' === $block_name && ! is_user_logged_in() ) {
            $account_url = wc_get_page_permalink( 'myaccount' );
            $notice      = '<section class="mnu-checkout-choice"><div><span class="mnu-eyebrow">Flexible checkout</span><strong>Checkout as a guest or create an account as you order.</strong><p>Guest checkout stays available. An account is optional and makes order tracking and future checkout easier.</p></div><a href="' . esc_url( $account_url ) . '">Sign in or register</a></section>';
            return $notice . $block_content;
        }

        if ( 'core/navigation' === $block_name && ! is_admin() ) {
            // Role-aware rewrites of hard-coded block-navigation links. The
            // block-editor markup bakes destinations at save time, so the
            // classic wp_nav_menu_objects filter does not reach them.
            $block_content = self::rewrite_block_nav_links( $block_content );

            if ( ! self::$navigation_account_added ) {
                self::$navigation_account_added = true;
                $account_url = wc_get_page_permalink( 'myaccount' );
                $label       = is_user_logged_in() ? 'Account' : 'Sign in / Register';
                $item        = '<li class="wp-block-navigation-item wp-block-navigation-link mnu-header-account"><a class="wp-block-navigation-item__content" href="' . esc_url( $account_url ) . '"><span class="wp-block-navigation-item__label">' . esc_html( $label ) . '</span></a></li>';
                $position    = strrpos( $block_content, '</ul>' );
                if ( false !== $position ) {
                    $block_content = substr_replace( $block_content, $item, $position, 0 );
                }
            }
        }

        return $block_content;
    }

    /**
     * Rewrite block-navigation anchors for role-aware Shop and Sell on MyNest tabs.
     *
     *   Shop link ( href=/shop/ )     -> href=/sellers/, label "Discover shops" (all users)
     *   Sell on MyNest link           -> for approved sellers: href=/seller-dashboard/, label "Seller Dashboard"
     */
    private static function rewrite_block_nav_links( string $html ): string {
        if ( '' === $html ) {
            return $html;
        }

        $is_seller = is_user_logged_in() && function_exists( 'tnm_is_marketplace_user' ) && tnm_is_marketplace_user();

        // Replace the Shop link's href and label. Match any block-nav anchor that
        // points at the WooCommerce /shop/ archive. The label span is the very
        // next element after the opening <a>.
        $sellers_url = esc_url( home_url( '/sellers/' ) );
        $html = preg_replace_callback(
            '#(<a\b[^>]*wp-block-navigation-item__content[^>]*\bhref=")([^"]*)("[^>]*>\s*<span class="wp-block-navigation-item__label">)([^<]*)(</span>\s*</a>)#i',
            static function ( $m ) use ( $sellers_url, $is_seller ) {
                $href  = html_entity_decode( (string) $m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
                $label = html_entity_decode( (string) $m[4], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
                $path  = (string) wp_parse_url( $href, PHP_URL_PATH );
                $trim  = '/' . trim( (string) $path, '/' ) . '/';

                // Shop tab -> Discover shops for everyone.
                if ( '/shop/' === $trim || strtolower( trim( $label ) ) === 'shop' ) {
                    return $m[1] . esc_url( $sellers_url ) . $m[3] . 'Discover shops' . $m[5];
                }

                // Sell on MyNest tab -> Seller Dashboard for approved sellers.
                $lower_label = strtolower( trim( $label ) );
                if ( $is_seller && ( '/seller-portal/' === $trim || $lower_label === 'sell on mynest' ) ) {
                    return $m[1] . esc_url( home_url( '/seller-dashboard/' ) ) . $m[3] . 'Seller Dashboard' . $m[5];
                }

                return $m[0];
            },
            $html
        );

        return (string) $html;
    }

    private static function is_header_template_part( array $block ): bool {
        $slug = sanitize_key( (string) ( $block['attrs']['slug'] ?? '' ) );
        $tag  = sanitize_key( (string) ( $block['attrs']['tagName'] ?? '' ) );

        return 'header' === $slug || 'header' === $tag || str_starts_with( $slug, 'header-' );
    }

    private static function remove_header_learn_more_button( string $block_content ): string {
        $pattern = "~<div\\b[^>]*class=([\"'])[^\"']*\\bwp-block-button\\b[^\"']*\\1[^>]*>\\s*<a\\b(?![^>]*\\bhref\\s*=)[^>]*class=([\"'])[^\"']*\\bwp-block-button__link\\b[^\"']*\\2[^>]*>\\s*Learn\\s+more\\s*</a>\\s*</div>~i";
        $updated = preg_replace( $pattern, '', $block_content );

        return is_string( $updated ) ? $updated : $block_content;
    }
}
