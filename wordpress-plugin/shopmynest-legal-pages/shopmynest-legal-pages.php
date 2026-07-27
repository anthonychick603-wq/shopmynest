<?php
/**
 * Plugin Name: ShopMyNest Legal Pages
 * Plugin URI:  https://shopmynest.com/
 * Description: Seeds Terms of Service, Privacy Policy, Return & Refund Policy, and Shipping Policy pages on activation. Provides a settings screen so the legal entity name, business address, contact email, and effective date can be updated without editing page content. Values are substituted at render time via the_content filter.
 * Version:     1.1.0
 * Author:      MyNest
 * Text Domain: shopmynest-legal-pages
 * Requires at least: 6.5
 * Requires PHP: 8.0
 * License:     GPLv2 or later
 */

defined( 'ABSPATH' ) || exit;

final class ShopMyNest_Legal_Pages {
    private const VERSION      = '1.1.0';
    private const OPT          = 'shopmynest_legal_settings';
    private const OPT_PAGE_IDS = 'shopmynest_legal_page_ids';

    /**
     * Page definitions. Slugs are stable; content files are relative to
     * this plugin's content/ directory and are loaded at activation time.
     */
    private static function pages(): array {
        return array(
            'terms'    => array(
                'slug'    => 'terms',
                'title'   => 'Terms of Service',
                'file'    => 'terms-of-service.html',
            ),
            'privacy'  => array(
                'slug'    => 'privacy',
                'title'   => 'Privacy Policy',
                'file'    => 'privacy-policy.html',
            ),
            'refunds'  => array(
                'slug'    => 'refunds',
                'title'   => 'Return & Refund Policy',
                'file'    => 'refund-policy.html',
            ),
            'shipping' => array(
                'slug'    => 'shipping',
                'title'   => 'Shipping Policy',
                'file'    => 'shipping-policy.html',
            ),
        );
    }

    public static function init(): void {
        add_action( 'admin_menu', array( __CLASS__, 'register_settings_page' ) );
        add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );

        // Substitute [LEGAL ENTITY], [BUSINESS ADDRESS], [CONTACT EMAIL],
        // [EFFECTIVE DATE] at render time, on the seeded pages only.
        add_filter( 'the_content', array( __CLASS__, 'substitute_placeholders' ), 20 );

        // Ensure the seeded pages remain published; recreate any that were
        // trashed so operators can un-trash from the settings screen.
        add_action( 'admin_notices', array( __CLASS__, 'missing_pages_notice' ) );

        // 301 redirect legacy legal URLs to canonical plugin-managed slugs.
        // Prevents duplicate-content SEO issues and ensures a single source
        // of truth when the settings screen updates placeholders.
        add_action( 'template_redirect', array( __CLASS__, 'redirect_legacy_slugs' ), 1 );
    }

    /**
     * Map of legacy path (with leading slash) -> canonical slug (bare).
     * Legacy URLs 301 to the plugin-seeded pages so both search engines
     * and existing bookmarks land on the current content.
     */
    private static function legacy_redirects(): array {
        return array(
            '/terms-of-service' => 'terms',
            '/terms-of-use'     => 'terms',
            '/privacy-policy'   => 'privacy',
            '/refund-policy'    => 'refunds',
            '/returns-refunds'  => 'refunds',
            '/shipping-policy'  => 'shipping',
        );
    }

    public static function redirect_legacy_slugs(): void {
        // Only handle GET/HEAD to avoid interfering with form posts.
        $method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET';
        if ( 'GET' !== $method && 'HEAD' !== $method ) {
            return;
        }

        $request_uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
        $request_path = rtrim( strtok( $request_uri, '?' ), '/' );
        if ( '' === $request_path ) {
            return;
        }

        $map = self::legacy_redirects();
        if ( ! array_key_exists( $request_path, $map ) ) {
            return;
        }

        $target = home_url( '/' . $map[ $request_path ] . '/' );
        wp_safe_redirect( $target, 301 );
        exit;
    }

    public static function activate(): void {
        $ids = get_option( self::OPT_PAGE_IDS, array() );
        if ( ! is_array( $ids ) ) {
            $ids = array();
        }

        foreach ( self::pages() as $key => $page ) {
            // Idempotent: if we already created and remembered a page, and it
            // still exists, do nothing. If it was trashed we leave it trashed
            // so operators can restore it manually. If we have no record but a
            // page with the target slug already exists, adopt it.
            if ( isset( $ids[ $key ] ) && get_post( $ids[ $key ] ) instanceof WP_Post ) {
                continue;
            }

            $existing = get_page_by_path( $page['slug'] );
            if ( $existing instanceof WP_Post ) {
                $ids[ $key ] = $existing->ID;
                continue;
            }

            $content_path = plugin_dir_path( __FILE__ ) . 'content/' . $page['file'];
            $content      = is_readable( $content_path ) ? file_get_contents( $content_path ) : '';

            $post_id = wp_insert_post(
                array(
                    'post_type'     => 'page',
                    'post_status'   => 'publish',
                    'post_title'    => $page['title'],
                    'post_name'     => $page['slug'],
                    'post_content'  => $content,
                    'comment_status'=> 'closed',
                    'ping_status'   => 'closed',
                ),
                true
            );

            if ( ! is_wp_error( $post_id ) ) {
                $ids[ $key ] = (int) $post_id;
            }
        }

        update_option( self::OPT_PAGE_IDS, $ids );

        if ( false === get_option( self::OPT ) ) {
            add_option(
                self::OPT,
                array(
                    'legal_entity'   => 'ShopMyNest',
                    'business_addr'  => '',
                    'contact_email'  => get_option( 'admin_email' ),
                    'effective_date' => gmdate( 'F j, Y' ),
                )
            );
        }
    }

    public static function register_settings_page(): void {
        add_options_page(
            'ShopMyNest Legal Pages',
            'ShopMyNest Legal',
            'manage_options',
            'shopmynest-legal',
            array( __CLASS__, 'render_settings_page' )
        );
    }

    public static function register_settings(): void {
        register_setting(
            'shopmynest_legal',
            self::OPT,
            array(
                'type'              => 'array',
                'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
                'default'           => array(),
            )
        );
    }

    public static function sanitize_settings( $input ): array {
        $input = is_array( $input ) ? $input : array();
        return array(
            'legal_entity'   => sanitize_text_field( $input['legal_entity']   ?? '' ),
            'business_addr'  => sanitize_textarea_field( $input['business_addr'] ?? '' ),
            'contact_email'  => sanitize_email( $input['contact_email']  ?? '' ),
            'effective_date' => sanitize_text_field( $input['effective_date'] ?? '' ),
        );
    }

    public static function render_settings_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $opts = wp_parse_args(
            (array) get_option( self::OPT, array() ),
            array(
                'legal_entity'   => 'ShopMyNest',
                'business_addr'  => '',
                'contact_email'  => get_option( 'admin_email' ),
                'effective_date' => '',
            )
        );
        $ids = (array) get_option( self::OPT_PAGE_IDS, array() );
        ?>
        <div class="wrap">
            <h1>ShopMyNest Legal Pages</h1>
            <p>These values are substituted at render time on the seeded Terms, Privacy, Refund, and Shipping pages. Changing them here updates every page immediately.</p>

            <form method="post" action="options.php">
                <?php settings_fields( 'shopmynest_legal' ); ?>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="sml-entity">Legal entity</label></th>
                            <td><input name="<?php echo esc_attr( self::OPT ); ?>[legal_entity]" id="sml-entity" type="text" class="regular-text" value="<?php echo esc_attr( $opts['legal_entity'] ); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="sml-addr">Business address</label></th>
                            <td><textarea name="<?php echo esc_attr( self::OPT ); ?>[business_addr]" id="sml-addr" rows="3" class="large-text"><?php echo esc_textarea( $opts['business_addr'] ); ?></textarea></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="sml-email">Contact email</label></th>
                            <td><input name="<?php echo esc_attr( self::OPT ); ?>[contact_email]" id="sml-email" type="email" class="regular-text" value="<?php echo esc_attr( $opts['contact_email'] ); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="sml-date">Effective date</label></th>
                            <td><input name="<?php echo esc_attr( self::OPT ); ?>[effective_date]" id="sml-date" type="text" class="regular-text" value="<?php echo esc_attr( $opts['effective_date'] ); ?>" />
                            <p class="description">Any human-readable date string, for example <em>July 26, 2026</em>.</p></td>
                        </tr>
                    </tbody>
                </table>
                <?php submit_button(); ?>
            </form>

            <h2>Seeded pages</h2>
            <table class="widefat striped" style="max-width:720px;">
                <thead><tr><th>Page</th><th>Status</th><th>View</th><th>Edit</th></tr></thead>
                <tbody>
                <?php foreach ( self::pages() as $key => $page ) :
                    $post_id = isset( $ids[ $key ] ) ? (int) $ids[ $key ] : 0;
                    $post    = $post_id ? get_post( $post_id ) : null;
                    ?>
                    <tr>
                        <td><?php echo esc_html( $page['title'] ); ?><br /><code>/<?php echo esc_html( $page['slug'] ); ?></code></td>
                        <td><?php echo $post ? esc_html( $post->post_status ) : '<em>missing</em>'; ?></td>
                        <td><?php echo $post ? '<a href="' . esc_url( get_permalink( $post ) ) . '">View</a>' : '&mdash;'; ?></td>
                        <td><?php echo $post ? '<a href="' . esc_url( get_edit_post_link( $post ) ) . '">Edit</a>' : '&mdash;'; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <h2>Missing a page?</h2>
            <p>Deactivate and reactivate this plugin to recreate any page whose slug is not currently in use. Existing pages with matching slugs are adopted, never overwritten.</p>
        </div>
        <?php
    }

    public static function missing_pages_notice(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $ids     = (array) get_option( self::OPT_PAGE_IDS, array() );
        $missing = array();
        foreach ( self::pages() as $key => $page ) {
            if ( empty( $ids[ $key ] ) || ! ( get_post( (int) $ids[ $key ] ) instanceof WP_Post ) ) {
                $missing[] = $page['title'];
            }
        }
        if ( empty( $missing ) ) {
            return;
        }
        printf(
            '<div class="notice notice-warning"><p><strong>ShopMyNest Legal Pages:</strong> %s. <a href="%s">Reactivate the plugin</a> to recreate them.</p></div>',
            esc_html( 'missing pages: ' . implode( ', ', $missing ) ),
            esc_url( admin_url( 'plugins.php' ) )
        );
    }

    /**
     * Substitute placeholder tokens in seeded pages only. Runs after
     * wpautop / block rendering because the tokens are plain text.
     */
    public static function substitute_placeholders( string $content ): string {
        if ( ! is_singular( 'page' ) ) {
            return $content;
        }
        $page_id = (int) get_queried_object_id();
        $ids     = (array) get_option( self::OPT_PAGE_IDS, array() );
        if ( ! in_array( $page_id, array_map( 'intval', $ids ), true ) ) {
            return $content;
        }
        $opts = wp_parse_args(
            (array) get_option( self::OPT, array() ),
            array(
                'legal_entity'   => 'ShopMyNest',
                'business_addr'  => '',
                'contact_email'  => get_option( 'admin_email' ),
                'effective_date' => '',
            )
        );

        // esc_html so operator input never injects markup.
        $legal_entity   = esc_html( $opts['legal_entity'] );
        $business_addr  = nl2br( esc_html( $opts['business_addr'] ) );
        $effective_date = esc_html( $opts['effective_date'] );

        // Contact email: keep human-readable but wrap in mailto: for convenience.
        $email_raw     = $opts['contact_email'];
        $contact_email = $email_raw
            ? '<a href="' . esc_url( 'mailto:' . $email_raw ) . '">' . esc_html( $email_raw ) . '</a>'
            : '';

        $replacements = array(
            '[LEGAL ENTITY]'     => $legal_entity,
            '[BUSINESS ADDRESS]' => $business_addr,
            '[CONTACT EMAIL]'    => $contact_email,
            '[EFFECTIVE DATE]'   => $effective_date,
        );

        return strtr( $content, $replacements );
    }
}

register_activation_hook( __FILE__, array( 'ShopMyNest_Legal_Pages', 'activate' ) );
ShopMyNest_Legal_Pages::init();
