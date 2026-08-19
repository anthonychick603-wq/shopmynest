<?php
/**
 * Plugin Name: ShopMyNest Legal Pages
 * Plugin URI:  https://shopmynest.com/
 * Description: Seeds Terms of Service, Privacy Policy, Return & Refund Policy, and Shipping Policy pages on activation. Provides a settings screen so the legal entity name, business address, contact email, and effective date can be updated without editing page content. Values are substituted at render time via the_content filter.
 * Version:     1.1.4
 * Author:      MyNest
 * Text Domain: shopmynest-legal-pages
 * Requires at least: 6.5
 * Requires PHP: 8.0
 * License:     GPLv2 or later
 */

defined( 'ABSPATH' ) || exit;

final class ShopMyNest_Legal_Pages {
    private const VERSION      = '1.1.4';
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
                // v1.1.4 — canonical slug matches WordPress core's designated
                // Privacy Policy convention. WP core's redirect_canonical()
                // treats /privacy-policy/ as authoritative for the site's
                // designated Privacy Policy page, so aligning with it stops
                // the /privacy/ <-> /privacy-policy/ 301 loop. Legacy
                // /privacy/ requests still 301 forward via the map below.
                'slug'    => 'privacy-policy',
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

        // One-time migration in v1.1.1: retire any operator-personal
        // contact_email in the seeded legal pages and lock it to the
        // marketplace's buyer-facing address. Runs once on any admin load.
        add_action( 'admin_init', array( __CLASS__, 'maybe_migrate_contact_email' ) );

        // v1.1.4 — heal the OPT_PAGE_IDS map on any admin load so a plugin
        // upgrade that changes a canonical slug (e.g. privacy -> privacy-policy)
        // picks up the correct existing page without needing a manual
        // deactivate/reactivate. Runs quickly and only rewrites the map when
        // the current mapping doesn't resolve to a page with the expected slug.
        add_action( 'admin_init', array( __CLASS__, 'maybe_heal_page_map' ) );

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

        // v1.1.3 — block anyone else (WP core Privacy Policy setting, old SEO
        // plugins, orphan pages) from redirecting our canonical slugs BACK to
        // legacy paths. Without this the site can enter a 301 loop between
        // /privacy/ and /privacy-policy/. Filter runs on every wp_redirect().
        add_filter( 'wp_redirect', array( __CLASS__, 'block_canonical_reverse_loop' ), 1, 2 );
    }

    /**
     * If the CURRENT request path is one of our canonical slugs (/privacy/,
     * /terms/, /refunds/, /shipping/) and something is trying to 301 us to
     * one of the legacy paths, drop the redirect. Prevents infinite loops
     * with WP core's Privacy Policy setting and other plugins that assume a
     * different canonical slug.
     *
     * @param string $location Proposed redirect target.
     * @param int    $status   HTTP status code.
     * @return string|false    False cancels the redirect.
     */
    public static function block_canonical_reverse_loop( $location, $status ) {
        $request_uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
        $request_path = '/' . trim( strtok( $request_uri, '?' ), '/' );
        $canonical    = array( '/privacy-policy', '/terms', '/refunds', '/shipping' );
        if ( ! in_array( $request_path, $canonical, true ) ) {
            return $location;
        }
        $legacy_paths = array_keys( self::legacy_redirects() );
        $target_path  = '/' . trim( wp_parse_url( (string) $location, PHP_URL_PATH ) ?: '', '/' );
        if ( in_array( $target_path, $legacy_paths, true ) ) {
            return false; // Cancel the loop-inducing redirect.
        }
        return $location;
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
            // v1.1.4 — privacy canonical is now /privacy-policy/ (see pages()),
            // so /privacy/ is the legacy path that redirects forward.
            '/privacy'          => 'privacy-policy',
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

    /**
     * Reconcile OPT_PAGE_IDS with the current canonical slugs from pages().
     * If a mapped page has a slug that no longer matches the canonical, adopt
     * whatever page currently owns the canonical slug (or fall back to the
     * existing mapping if none does). Safe to run on every admin load.
     */
    public static function maybe_heal_page_map(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $ids     = get_option( self::OPT_PAGE_IDS, array() );
        if ( ! is_array( $ids ) ) {
            $ids = array();
        }
        $changed = false;
        foreach ( self::pages() as $key => $page ) {
            $canonical_slug = $page['slug'];
            $current_post   = isset( $ids[ $key ] ) ? get_post( $ids[ $key ] ) : null;
            if ( $current_post instanceof WP_Post && $current_post->post_name === $canonical_slug ) {
                continue;
            }
            $adopt = get_page_by_path( $canonical_slug );
            if ( $adopt instanceof WP_Post ) {
                $ids[ $key ] = $adopt->ID;
                $changed     = true;
            }
        }
        if ( $changed ) {
            update_option( self::OPT_PAGE_IDS, $ids );
        }
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
     * Retire any personal contact email that was seeded into the option
     * from a bare WordPress admin_email at activation. Runs once, tracked
     * via a marker option, and is a no-op after v1.1.1.
     *
     * The marketplace-facing default is help@shopmynest.com, which routes
     * to buyer/seller support. Operators can override in Settings →
     * ShopMyNest Legal.
     */
    public static function maybe_migrate_contact_email(): void {
        if ( get_option( 'shopmynest_legal_v111_migrated' ) ) {
            return;
        }
        $opts = (array) get_option( self::OPT, array() );
        $current = isset( $opts['contact_email'] ) ? (string) $opts['contact_email'] : '';

        // Anything ending in @shopmynest.com is fine; leave it alone.
        $host = strtolower( substr( strrchr( $current, '@' ) ?: '', 1 ) );
        if ( 'shopmynest.com' !== $host ) {
            $opts['contact_email'] = 'help@shopmynest.com';
            update_option( self::OPT, $opts );
        }
        update_option( 'shopmynest_legal_v111_migrated', 1 );
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
