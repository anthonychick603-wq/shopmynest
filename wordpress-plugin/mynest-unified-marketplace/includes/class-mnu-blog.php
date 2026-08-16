<?php
/**
 * Blog: a moderated public feed any logged-in account can post to.
 *
 * Submissions are held for review and only reach the public feed once an
 * administrator approves them. Moderation state rides on the native WordPress
 * post status so counts, trash/restore and the list screens work without a
 * custom table: pending => `pending`, approved => `publish`, rejected =>
 * `draft` (the same rejected state TNM_Applications uses, so a rejection is
 * recoverable rather than scheduled for deletion).
 */

defined( 'ABSPATH' ) || exit;

final class MNU_Blog {

    const CPT       = 'mnu_blog_post';
    const NS        = 'the-nest/v1';
    const MENU_SLUG = 'mnu-blog';
    const PER_PAGE  = 20;

    /** Moderation state => WordPress post status. */
    private const STATUSES = array(
        'pending'  => 'pending',
        'approved' => 'publish',
        'rejected' => 'draft',
    );

    public static function init(): void {
        add_action( 'init', array( __CLASS__, 'register_post_type' ) );
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
        // TNM_Admin registers the `tnm-marketplace` parent menu at priority 5.
        add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
        add_action( 'admin_post_mnu_blog_moderate', array( __CLASS__, 'handle_moderation' ) );
        add_action( 'admin_notices', array( __CLASS__, 'pending_notice' ) );
    }

    public static function register_post_type(): void {
        if ( post_type_exists( self::CPT ) ) {
            return;
        }
        register_post_type(
            self::CPT,
            array(
                'labels'              => array(
                    'name'          => __( 'Blog', 'mynest-unified-marketplace' ),
                    'singular_name' => __( 'Blog Post', 'mynest-unified-marketplace' ),
                ),
                'public'              => false,
                // Moderation happens on the custom Blog screen below, not in the
                // standard post editor.
                'show_ui'             => false,
                'show_in_menu'        => false,
                'supports'            => array( 'title', 'editor', 'author', 'thumbnail' ),
                'capability_type'     => 'post',
                'map_meta_cap'        => true,
                'exclude_from_search' => true,
                // Exposed only through the routes below, so pending submissions
                // have no alternate read path.
                'show_in_rest'        => false,
            )
        );
    }

    /* ---------------------------------------------------------------- REST */

    public static function register_routes(): void {
        register_rest_route(
            self::NS,
            '/blog/posts',
            array(
                array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'public_feed' ), 'permission_callback' => '__return_true' ),
                array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'submit' ), 'permission_callback' => array( __CLASS__, 'logged_in' ) ),
            )
        );
        register_rest_route(
            self::NS,
            '/blog/moderation/posts',
            array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'moderation_feed' ), 'permission_callback' => array( __CLASS__, 'admin' ) )
        );
        register_rest_route(
            self::NS,
            '/blog/moderation/posts/(?P<id>\d+)/approve',
            array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'approve' ), 'permission_callback' => array( __CLASS__, 'admin' ) )
        );
        register_rest_route(
            self::NS,
            '/blog/moderation/posts/(?P<id>\d+)/reject',
            array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'reject' ), 'permission_callback' => array( __CLASS__, 'admin' ) )
        );
    }

    public static function logged_in(): bool|WP_Error {
        return get_current_user_id() ? true : tnm_json_error( 'rest_login_required', 'Authentication is required.', 401 );
    }

    /**
     * Same site-owner check the plugin uses for its other admin-side actions
     * (TNM_Admin's menu and settings, TNM_Applications' approve/reject).
     */
    public static function admin(): bool|WP_Error {
        if ( ! get_current_user_id() ) {
            return tnm_json_error( 'rest_login_required', 'Authentication is required.', 401 );
        }
        return tnm_is_admin_or_manager() ? true : tnm_json_error( 'rest_forbidden', 'Marketplace administrator access is required.', 403 );
    }

    public static function public_feed( WP_REST_Request $request ): WP_REST_Response {
        $response = rest_ensure_response( self::query( 'approved', $request ) );
        // v3.7.67 — the WPCOM Atomic edge cache was holding the anonymous
        // JSON feed after an approval, so approved posts didn't surface in
        // the app until the CDN entry aged out. Tell every intermediate cache
        // never to store this response; the moderation flow also purges the
        // path explicitly on approve/reject below.
        $response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
        $response->header( 'Pragma', 'no-cache' );
        $response->header( 'Expires', '0' );
        return $response;
    }

    public static function moderation_feed( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $state = sanitize_key( (string) ( $request->get_param( 'status' ) ?: 'pending' ) );
        if ( ! isset( self::STATUSES[ $state ] ) ) {
            return tnm_json_error( 'blog_invalid_status', 'Status must be pending, approved, or rejected.', 422 );
        }
        return rest_ensure_response(
            array_merge(
                self::query( $state, $request ),
                array( 'status' => $state, 'pending_count' => self::pending_count() )
            )
        );
    }

    public static function submit( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $user_id = get_current_user_id();
        $caption = wp_kses_post( (string) $request->get_param( 'caption' ) );
        if ( '' === trim( wp_strip_all_tags( $caption ) ) ) {
            return tnm_json_error( 'blog_caption_required', 'A caption is required.', 422 );
        }

        $post_id = wp_insert_post(
            array(
                'post_type'    => self::CPT,
                'post_status'  => self::STATUSES['pending'],
                'post_author'  => $user_id,
                'post_title'   => sprintf( 'Blog post by %s', get_the_author_meta( 'display_name', $user_id ) ),
                'post_content' => $caption,
            ),
            true
        );
        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }
        $post_id = (int) $post_id;

        $attachment_id = tnm_upload_image_from_request( 'image' );
        if ( is_wp_error( $attachment_id ) ) {
            wp_delete_post( $post_id, true );
            return $attachment_id;
        }
        if ( $attachment_id ) {
            if ( ! wp_attachment_is_image( $attachment_id ) ) {
                wp_delete_attachment( $attachment_id, true );
                wp_delete_post( $post_id, true );
                return tnm_json_error( 'blog_invalid_image', 'The uploaded file must be an image.', 422 );
            }
            wp_update_post( array( 'ID' => $attachment_id, 'post_parent' => $post_id, 'post_author' => $user_id ) );
            set_post_thumbnail( $post_id, $attachment_id );
        }

        self::notify_admins( $post_id, $user_id );

        return new WP_REST_Response( self::post_to_array( get_post( $post_id ) ), 201 );
    }

    public static function approve( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        return self::moderate_response( absint( $request['id'] ), 'approved' );
    }

    public static function reject( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        return self::moderate_response( absint( $request['id'] ), 'rejected' );
    }

    private static function moderate_response( int $post_id, string $state ): WP_REST_Response|WP_Error {
        $result = self::set_state( $post_id, $state );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return rest_ensure_response( array( 'success' => true, 'post' => self::post_to_array( $result ) ) );
    }

    /**
     * v3.7.67 — nudge every layer that might cache the public blog feed.
     * WPCOM Atomic exposes wpcom_vip_purge_edge_cache_for_url() on managed
     * hosts; page-cache plugins expose their own hooks. We call whatever is
     * defined and stay silent otherwise so the moderation call keeps working
     * on plain LAMP installs.
     */
    private static function purge_public_feed_cache(): void {
        $url = rest_url( self::NS . '/blog/posts' );
        if ( function_exists( 'wpcom_vip_purge_edge_cache_for_url' ) ) {
            wpcom_vip_purge_edge_cache_for_url( $url );
        }
        if ( function_exists( 'wpcom_invalidate_wpcom_shortlinks_for_post' ) ) {
            // no-op guard; keeps the call defensive on non-VIP hosts.
        }
        // WP Super Cache / W3 Total Cache / LiteSpeed hooks.
        do_action( 'litespeed_purge_url', $url );
        if ( function_exists( 'wp_cache_post_change' ) ) {
            wp_cache_post_change( get_option( 'siteurl' ) );
        }
        // Generic action for anything else listening (our own admin dashboards).
        do_action( 'mnu_blog_feed_purged', $url );
    }

    /* ------------------------------------------------------------ internals */

    /**
     * @return array{items:array<int,array<string,mixed>>,page:int,total:int,total_pages:int}
     */
    private static function query( string $state, WP_REST_Request $request ): array {
        $page     = max( 1, (int) $request->get_param( 'page' ) );
        $per_page = max( 1, min( 50, (int) ( $request->get_param( 'per_page' ) ?: self::PER_PAGE ) ) );
        $query    = new WP_Query(
            array(
                'post_type'      => self::CPT,
                'post_status'    => self::STATUSES[ $state ],
                'posts_per_page' => $per_page,
                'paged'          => $page,
                'orderby'        => 'date',
                'order'          => 'DESC',
            )
        );
        return array(
            'items'       => array_map( array( __CLASS__, 'post_to_array' ), $query->posts ),
            'page'        => $page,
            'total'       => (int) $query->found_posts,
            'total_pages' => (int) $query->max_num_pages,
        );
    }

    private static function set_state( int $post_id, string $state ): WP_Post|WP_Error {
        $post = get_post( $post_id );
        if ( ! $post instanceof WP_Post || self::CPT !== $post->post_type ) {
            return tnm_json_error( 'blog_post_not_found', 'Blog post not found.', 404 );
        }
        if ( $post->post_status !== self::STATUSES[ $state ] ) {
            wp_update_post( array( 'ID' => $post_id, 'post_status' => self::STATUSES[ $state ] ) );
            // v3.7.67 — purge caches on any state change so approve/reject from
            // REST *and* wp-admin surface immediately in the public feed.
            self::purge_public_feed_cache();
        }
        return get_post( $post_id );
    }

    private static function state_of( WP_Post $post ): string {
        $state = array_search( $post->post_status, self::STATUSES, true );
        return is_string( $state ) ? $state : 'pending';
    }

    public static function pending_count(): int {
        $counts = wp_count_posts( self::CPT );
        return (int) ( $counts->{self::STATUSES['pending']} ?? 0 );
    }

    /**
     * @return array<string,mixed>
     */
    public static function post_to_array( WP_Post $post ): array {
        $author_id     = (int) $post->post_author;
        $attachment_id = (int) get_post_thumbnail_id( $post );
        return array(
            'id'         => (int) $post->ID,
            'status'     => self::state_of( $post ),
            'caption'    => wp_kses_post( $post->post_content ),
            'image_id'   => $attachment_id,
            'image'      => $attachment_id ? (string) wp_get_attachment_image_url( $attachment_id, 'large' ) : '',
            'thumbnail'  => $attachment_id ? (string) wp_get_attachment_image_url( $attachment_id, 'medium' ) : '',
            'author'     => array(
                'id'     => $author_id,
                // v3.7.69 — surface the store label (nickname / tnm_store_name)
                // instead of the raw WP display_name, so blog posts read
                // "That Jo Chick" not "Johanna Chick".
                'name'   => tnm_seller_display_name( $author_id ),
                'avatar' => tnm_user_avatar_url( $author_id, 256 ),
            ),
            'created_at' => mysql_to_rfc3339( $post->post_date_gmt ),
        );
    }

    private static function notify_admins( int $post_id, int $author_id ): void {
        $admins = get_users( array( 'role__in' => array( 'administrator', 'shop_manager' ), 'fields' => 'ID' ) );
        foreach ( $admins as $admin_id ) {
            tnm_notify(
                (int) $admin_id,
                $author_id,
                'blog_post_submitted',
                'New blog post awaiting approval',
                (string) get_the_author_meta( 'display_name', $author_id ),
                $post_id,
                self::CPT,
                admin_url( 'admin.php?page=' . self::MENU_SLUG )
            );
        }
    }

    /* ---------------------------------------------------------- wp-admin UI */

    public static function menu(): void {
        $pending = self::pending_count();
        $label   = __( 'Blog', 'mynest-unified-marketplace' );
        if ( $pending > 0 ) {
            // Same bubble markup WordPress uses for the Comments menu.
            $label .= ' <span class="awaiting-mod"><span class="pending-count">' . number_format_i18n( $pending ) . '</span></span>';
        }
        add_submenu_page( 'tnm-marketplace', __( 'Blog', 'mynest-unified-marketplace' ), $label, 'manage_woocommerce', self::MENU_SLUG, array( __CLASS__, 'render_screen' ) );
    }

    public static function pending_notice(): void {
        $screen = get_current_screen();
        if ( ! $screen || ! in_array( $screen->id, array( 'dashboard', 'plugins' ), true ) || ! tnm_is_admin_or_manager() ) {
            return;
        }
        $pending = self::pending_count();
        if ( $pending < 1 ) {
            return;
        }
        printf(
            '<div class="notice notice-info"><p>%s <a href="%s">%s</a></p></div>',
            esc_html( sprintf( _n( '%s blog post is waiting for approval.', '%s blog posts are waiting for approval.', $pending, 'mynest-unified-marketplace' ), number_format_i18n( $pending ) ) ),
            esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ),
            esc_html__( 'Review the Blog queue', 'mynest-unified-marketplace' )
        );
    }

    public static function handle_moderation(): void {
        if ( ! tnm_is_admin_or_manager() ) {
            wp_die( 'You do not have permission to moderate blog posts.' );
        }
        $post_id = absint( $_GET['post_id'] ?? 0 );
        check_admin_referer( 'mnu_blog_moderate_' . $post_id );

        $decision = sanitize_key( $_GET['decision'] ?? '' );
        $state    = 'approve' === $decision ? 'approved' : ( 'reject' === $decision ? 'rejected' : '' );
        $result   = $state ? self::set_state( $post_id, $state ) : tnm_json_error( 'blog_invalid_decision', 'Unknown moderation decision.', 400 );

        $redirect = add_query_arg(
            array(
                'page'      => self::MENU_SLUG,
                'status'    => sanitize_key( $_GET['status'] ?? 'pending' ),
                'paged'     => max( 1, absint( $_GET['paged'] ?? 1 ) ),
                'mnu_blog'  => is_wp_error( $result ) ? 'error' : $state,
            ),
            admin_url( 'admin.php' )
        );
        wp_safe_redirect( $redirect );
        exit;
    }

    public static function render_screen(): void {
        if ( ! tnm_is_admin_or_manager() ) {
            wp_die( 'You do not have permission to moderate blog posts.' );
        }

        $state = sanitize_key( $_GET['status'] ?? 'pending' );
        if ( ! isset( self::STATUSES[ $state ] ) ) {
            $state = 'pending';
        }
        $paged  = max( 1, absint( $_GET['paged'] ?? 1 ) );
        $counts = wp_count_posts( self::CPT );
        $query  = new WP_Query(
            array(
                'post_type'      => self::CPT,
                'post_status'    => self::STATUSES[ $state ],
                'posts_per_page' => self::PER_PAGE,
                'paged'          => $paged,
                'orderby'        => 'date',
                'order'          => 'DESC',
            )
        );

        echo '<div class="wrap"><h1>' . esc_html__( 'Blog', 'mynest-unified-marketplace' ) . '</h1>';
        echo '<p>' . esc_html__( 'Posts submitted from the app. Only approved posts appear in the public blog feed.', 'mynest-unified-marketplace' ) . '</p>';
        self::render_result_notice();
        self::render_tabs( $state, $counts );

        echo '<table class="widefat striped"><thead><tr>';
        echo '<th style="width:120px">' . esc_html__( 'Photo', 'mynest-unified-marketplace' ) . '</th>';
        echo '<th>' . esc_html__( 'Caption', 'mynest-unified-marketplace' ) . '</th>';
        echo '<th style="width:220px">' . esc_html__( 'Author', 'mynest-unified-marketplace' ) . '</th>';
        echo '<th style="width:180px">' . esc_html__( 'Actions', 'mynest-unified-marketplace' ) . '</th>';
        echo '</tr></thead><tbody>';

        if ( ! $query->posts ) {
            echo '<tr><td colspan="4">' . esc_html__( 'No posts in this view.', 'mynest-unified-marketplace' ) . '</td></tr>';
        }
        foreach ( $query->posts as $post ) {
            self::render_row( $post, $state, $paged );
        }

        echo '</tbody></table>';
        self::render_pagination( $query, $state );
        echo '</div>';
    }

    private static function render_result_notice(): void {
        $result = sanitize_key( $_GET['mnu_blog'] ?? '' );
        if ( ! $result ) {
            return;
        }
        $messages = array(
            'approved' => __( 'Post approved and published to the blog feed.', 'mynest-unified-marketplace' ),
            'rejected' => __( 'Post rejected and removed from the blog feed.', 'mynest-unified-marketplace' ),
            'error'    => __( 'That post could not be updated.', 'mynest-unified-marketplace' ),
        );
        if ( ! isset( $messages[ $result ] ) ) {
            return;
        }
        printf(
            '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
            'error' === $result ? 'error' : 'success',
            esc_html( $messages[ $result ] )
        );
    }

    /**
     * @param object $counts Result of wp_count_posts().
     */
    private static function render_tabs( string $current, object $counts ): void {
        $labels = array(
            'pending'  => __( 'Pending', 'mynest-unified-marketplace' ),
            'approved' => __( 'Approved', 'mynest-unified-marketplace' ),
            'rejected' => __( 'Rejected', 'mynest-unified-marketplace' ),
        );
        $links = array();
        foreach ( $labels as $state => $label ) {
            $count  = (int) ( $counts->{self::STATUSES[ $state ]} ?? 0 );
            $url    = add_query_arg( array( 'page' => self::MENU_SLUG, 'status' => $state ), admin_url( 'admin.php' ) );
            $class  = $state === $current ? ' class="current"' : '';
            $links[] = '<a href="' . esc_url( $url ) . '"' . $class . '>' . esc_html( $label ) . ' <span class="count">(' . esc_html( number_format_i18n( $count ) ) . ')</span></a>';
        }
        echo '<ul class="subsubsub"><li>' . implode( ' |</li><li>', $links ) . '</li></ul><br class="clear">';
    }

    private static function render_row( WP_Post $post, string $state, int $paged ): void {
        $attachment_id = (int) get_post_thumbnail_id( $post );
        $author        = get_userdata( (int) $post->post_author );
        $base          = admin_url( 'admin-post.php?action=mnu_blog_moderate&post_id=' . (int) $post->ID . '&status=' . rawurlencode( $state ) . '&paged=' . $paged );

        echo '<tr><td>';
        if ( $attachment_id ) {
            printf(
                '<a href="%s" target="_blank" rel="noopener"><img src="%s" alt="" style="max-width:100px;height:auto;border-radius:6px"></a>',
                esc_url( (string) wp_get_attachment_url( $attachment_id ) ),
                esc_url( (string) wp_get_attachment_image_url( $attachment_id, 'thumbnail' ) )
            );
        } else {
            echo '&mdash;';
        }
        echo '</td><td>' . wp_kses_post( wpautop( $post->post_content ) ) . '</td>';
        echo '<td>' . esc_html( $author ? $author->display_name : __( 'Unknown', 'mynest-unified-marketplace' ) ) . '<br><small>' . esc_html( $post->post_date ) . '</small></td><td>';
        if ( 'approved' !== $state ) {
            echo '<a class="button button-primary" href="' . esc_url( wp_nonce_url( $base . '&decision=approve', 'mnu_blog_moderate_' . $post->ID ) ) . '">' . esc_html__( 'Approve', 'mynest-unified-marketplace' ) . '</a> ';
        }
        if ( 'rejected' !== $state ) {
            echo '<a class="button" href="' . esc_url( wp_nonce_url( $base . '&decision=reject', 'mnu_blog_moderate_' . $post->ID ) ) . '">' . esc_html__( 'Reject', 'mynest-unified-marketplace' ) . '</a>';
        }
        echo '</td></tr>';
    }

    private static function render_pagination( WP_Query $query, string $state ): void {
        if ( (int) $query->max_num_pages < 2 ) {
            return;
        }
        $links = paginate_links(
            array(
                // Built by hand: add_query_arg() would URL-encode paginate_links'
                // %#% page placeholder.
                'base'      => admin_url( 'admin.php?page=' . self::MENU_SLUG . '&status=' . rawurlencode( $state ) . '&paged=%#%' ),
                'format'    => '',
                'current'   => max( 1, (int) $query->query_vars['paged'] ),
                'total'     => (int) $query->max_num_pages,
                'prev_text' => '&laquo;',
                'next_text' => '&raquo;',
            )
        );
        if ( $links ) {
            echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( $links ) . '</div></div>';
        }
    }
}
