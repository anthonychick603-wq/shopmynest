<?php
/**
 * MyNest — Web Parity (v3.7.105 / Build request "Implement app features into website")
 *
 * The mobile app already exposes: favorites list, saved searches with new-listing
 * alerts, blog feed with comments + hearts, and the seller "duplicate listing"
 * flow. Every one of those already has a REST endpoint on the WordPress side
 * (registered in class-tnm-rest.php and class-mnu-blog.php). What was missing
 * was the *web surface* to reach them.
 *
 * This class fills that gap:
 *
 *  - [mynest_favorites] shortcode           → /favorites page
 *  - [mynest_saved_searches] shortcode      → /saved-searches page
 *  - [mynest_blog] shortcode                → /nest-blog page (feed + single)
 *  - Save-alert pill on the shop archive    → hooked via woocommerce_before_shop_loop
 *  - Seller-rating stars on product cards   → hooked via woocommerce_after_shop_loop_item_title
 *  - Auto-creates the 3 pages on activation
 *
 * Auth: all REST calls originate from a logged-in browser, so we ride the
 * standard WP cookie auth + a wp_rest nonce. Guests get login gates instead of
 * hitting endpoints anonymously.
 *
 * Duplicate button lives on the seller dashboard directly (see class-tnm-shortcodes.php);
 * it re-uses the existing `tnm_dashboard` nonce/form pattern rather than REST.
 */

defined( 'ABSPATH' ) || exit;

final class MNU_Web_Parity {

    const PAGES_OPTION = 'mnu_web_parity_pages';
    const HANDLE_JS    = 'mnu-web-parity';
    const HANDLE_CSS   = 'mnu-web-parity';

    public static function init(): void {
        add_shortcode( 'mynest_favorites', array( __CLASS__, 'shortcode_favorites' ) );
        add_shortcode( 'mynest_saved_searches', array( __CLASS__, 'shortcode_saved_searches' ) );
        add_shortcode( 'mynest_blog', array( __CLASS__, 'shortcode_blog' ) );

        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );

        // Save-alert pill above the shop grid.
        add_action( 'woocommerce_before_shop_loop', array( __CLASS__, 'render_save_alert_pill' ), 5 );

        // Seller rating stars under product-card title.
        add_action( 'woocommerce_after_shop_loop_item_title', array( __CLASS__, 'render_card_rating' ), 6 );
        // Seller rating stars on single product summary (near seller badge).
        add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'render_single_rating' ), 12 );
    }

    /* --------------------------------------------------------- Activation */

    /**
     * Called from MNU_Install::activate() (idempotent).
     * Ensures /favorites, /saved-searches, /nest-blog pages exist with the
     * matching shortcode as their content.
     */
    /**
     * Version-gated wrapper for ensure_pages(). Bound to the 'init' hook so
     * WP_Rewrite is already initialized before wp_insert_post -> get_permalink
     * runs. Runs at most once per plugin version.
     */
    public static function ensure_pages_once(): void {
        if ( get_option( 'mnu_web_parity_pages_version', '' ) === MNU_VERSION ) {
            return;
        }
        self::ensure_pages();
        update_option( 'mnu_web_parity_pages_version', MNU_VERSION, false );
    }

    public static function ensure_pages(): void {
        $wanted = array(
            'favorites'      => array( 'slug' => 'favorites',      'title' => 'Favorites',      'shortcode' => '[mynest_favorites]' ),
            'saved_searches' => array( 'slug' => 'saved-searches', 'title' => 'Saved Searches', 'shortcode' => '[mynest_saved_searches]' ),
            'blog'           => array( 'slug' => 'nest-blog',      'title' => 'Nest Blog',      'shortcode' => '[mynest_blog]' ),
        );
        $ids = (array) get_option( self::PAGES_OPTION, array() );
        foreach ( $wanted as $key => $spec ) {
            $existing_id = isset( $ids[ $key ] ) ? (int) $ids[ $key ] : 0;
            if ( $existing_id && get_post_status( $existing_id ) === 'publish' ) {
                continue;
            }
            $page = get_page_by_path( $spec['slug'] );
            if ( $page && 'page' === $page->post_type ) {
                $ids[ $key ] = (int) $page->ID;
                continue;
            }
            $new_id = wp_insert_post(
                array(
                    'post_type'    => 'page',
                    'post_status'  => 'publish',
                    'post_title'   => $spec['title'],
                    'post_name'    => $spec['slug'],
                    'post_content' => $spec['shortcode'],
                    'comment_status' => 'closed',
                    'ping_status'    => 'closed',
                )
            );
            if ( ! is_wp_error( $new_id ) && $new_id ) {
                $ids[ $key ] = (int) $new_id;
            }
        }
        update_option( self::PAGES_OPTION, $ids, false );
    }

    public static function page_url( string $key ): string {
        $ids = (array) get_option( self::PAGES_OPTION, array() );
        $id  = isset( $ids[ $key ] ) ? (int) $ids[ $key ] : 0;
        if ( $id ) {
            $link = get_permalink( $id );
            if ( $link ) {
                return (string) $link;
            }
        }
        // Fallback: guess by slug so links never 404 outright before activation
        // has run for older installs.
        $slug = 'favorites' === $key ? 'favorites' : ( 'saved_searches' === $key ? 'saved-searches' : 'nest-blog' );
        return home_url( '/' . $slug . '/' );
    }

    /* ----------------------------------------------------------- Assets */

    public static function register_assets(): void {
        wp_register_style( self::HANDLE_CSS, MNU_URL . 'assets/css/web-parity.css', array(), MNU_VERSION );
        wp_register_script( self::HANDLE_JS, MNU_URL . 'assets/js/web-parity.js', array(), MNU_VERSION, true );
    }

    private static function enqueue_assets(): void {
        static $done = false;
        if ( $done ) return;
        $done = true;
        wp_enqueue_style( self::HANDLE_CSS );
        wp_enqueue_script( self::HANDLE_JS );
        wp_localize_script(
            self::HANDLE_JS,
            'MNUWebParity',
            array(
                'restRoot'  => trailingslashit( rest_url() ),
                'restNonce' => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
                'userId'    => (int) get_current_user_id(),
                'loginUrl'  => wp_login_url( home_url( add_query_arg( array() ) ) ),
                'pages'     => array(
                    'favorites'       => self::page_url( 'favorites' ),
                    'saved_searches'  => self::page_url( 'saved_searches' ),
                    'blog'            => self::page_url( 'blog' ),
                ),
                'currency'  => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, get_bloginfo( 'charset' ) ),
            )
        );
    }

    /* ------------------------------------------------ Save-alert pill hook */

    /**
     * Render the "Save this search" pill above the product grid on the shop
     * archive and search results. Any of ?s, ?product_cat, ?min_price,
     * ?max_price, ?orderby, ?pa_* triggers the pill.
     */
    public static function render_save_alert_pill(): void {
        // Only on the shop archive / product taxonomy / search.
        if ( ! ( is_shop() || is_product_taxonomy() || ( is_search() && get_query_var( 'post_type' ) === 'product' ) ) ) {
            return;
        }
        self::enqueue_assets();

        // Read current filter state from the query string.
        $search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : '';
        $cat      = 0;
        if ( isset( $_GET['product_cat'] ) ) {
            $term = get_term_by( 'slug', sanitize_title( wp_unslash( (string) $_GET['product_cat'] ) ), 'product_cat' );
            if ( $term && ! is_wp_error( $term ) ) {
                $cat = (int) $term->term_id;
            }
        } elseif ( is_product_taxonomy() ) {
            $queried = get_queried_object();
            if ( $queried && isset( $queried->term_id ) ) {
                $cat = (int) $queried->term_id;
            }
        }
        $min_price = isset( $_GET['min_price'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['min_price'] ) ) : '';
        $max_price = isset( $_GET['max_price'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['max_price'] ) ) : '';
        $sort      = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( (string) $_GET['orderby'] ) ) : '';
        $condition = isset( $_GET['filter_condition'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['filter_condition'] ) ) : '';

        // Any criterion at all -> show the pill. Empty shop with no filters, no pill.
        $has_any = $search || $cat || $min_price || $max_price || $sort || $condition;
        if ( ! $has_any ) {
            return;
        }

        $label_hint = 'Shop filter';
        if ( $search ) {
            $label_hint = $search;
        } elseif ( $cat ) {
            $term_obj = get_term( $cat, 'product_cat' );
            if ( $term_obj && ! is_wp_error( $term_obj ) ) {
                $label_hint = (string) $term_obj->name;
            }
        }
        $payload    = wp_json_encode(
            array(
                'label'     => $label_hint,
                'search'    => $search,
                'category'  => $cat,
                'min_price' => $min_price,
                'max_price' => $max_price,
                'sort'      => $sort,
                'pa_condition' => $condition,
            )
        );
        ?>
        <div class="mnu-save-alert-wrap">
            <?php if ( is_user_logged_in() ) : ?>
                <button
                    type="button"
                    class="mnu-save-alert-pill"
                    data-mnu-save-search
                    data-payload='<?php echo esc_attr( (string) $payload ); ?>'
                >
                    <span class="mnu-save-alert-bell" aria-hidden="true">🔔</span>
                    <span class="mnu-save-alert-label">Save alert</span>
                </button>
                <p class="mnu-save-alert-hint" data-mnu-save-search-msg></p>
            <?php else : ?>
                <a class="mnu-save-alert-pill mnu-save-alert-pill--guest" href="<?php echo esc_url( wp_login_url( home_url( add_query_arg( array() ) ) ) ); ?>">
                    <span class="mnu-save-alert-bell" aria-hidden="true">🔔</span>
                    <span class="mnu-save-alert-label">Sign in to save this search</span>
                </a>
            <?php endif; ?>
        </div>
        <?php
    }

    /* ---------------------------------------- Product card / single rating */

    /**
     * Attach a small "★ 4.8 (12)" line under the product title on shop cards,
     * pulling from the seller-review aggregate rather than product reviews.
     * Silent when the seller has no reviews yet.
     */
    public static function render_card_rating(): void {
        global $product;
        if ( ! $product instanceof WC_Product ) return;
        $seller_id = (int) get_post_field( 'post_author', $product->get_id() );
        if ( ! $seller_id ) return;
        $summary = self::seller_rating_summary( $seller_id );
        if ( ! $summary['total'] ) return;
        echo '<div class="mnu-card-rating"><span class="mnu-card-rating__star" aria-hidden="true">★</span> <span class="mnu-card-rating__value">' . esc_html( number_format_i18n( $summary['average'], 1 ) ) . '</span> <span class="mnu-card-rating__count">(' . esc_html( (string) $summary['total'] ) . ')</span></div>';
    }

    public static function render_single_rating(): void {
        global $product;
        if ( ! $product instanceof WC_Product ) return;
        $seller_id = (int) get_post_field( 'post_author', $product->get_id() );
        if ( ! $seller_id ) return;
        $summary = self::seller_rating_summary( $seller_id );
        if ( ! $summary['total'] ) return;
        echo '<div class="mnu-single-rating"><span class="mnu-card-rating__star" aria-hidden="true">★</span> <strong>' . esc_html( number_format_i18n( $summary['average'], 1 ) ) . '</strong> <span class="mnu-single-rating__count">from ' . esc_html( (string) $summary['total'] ) . ' seller ' . esc_html( $summary['total'] === 1 ? 'review' : 'reviews' ) . '</span></div>';
    }

    /**
     * Cheap object-cache wrapper around TNM_Social::seller_reviews summary so
     * every product card on a 24-product grid doesn't run a fresh SUM query.
     */
    private static function seller_rating_summary( int $seller_id ): array {
        $key    = 'mnu_seller_rating_' . $seller_id;
        $cached = wp_cache_get( $key, 'mnu' );
        if ( is_array( $cached ) ) return $cached;

        $result = array( 'average' => 0.0, 'total' => 0 );
        if ( class_exists( 'TNM_Social' ) && method_exists( 'TNM_Social', 'seller_reviews' ) ) {
            $data = TNM_Social::seller_reviews( $seller_id, 1, 1 );
            $result['average'] = isset( $data['average'] ) ? (float) $data['average'] : 0.0;
            $result['total']   = isset( $data['total'] )   ? (int) $data['total']    : 0;
        }
        wp_cache_set( $key, $result, 'mnu', 300 );
        return $result;
    }

    /* ------------------------------------------------- Favorites shortcode */

    public static function shortcode_favorites(): string {
        self::enqueue_assets();
        if ( ! is_user_logged_in() ) {
            return self::guest_gate( 'Sign in to see your favorites.' );
        }

        // Render server-side to avoid an extra REST round-trip and to reuse
        // WooCommerce's product-loop template (matching the shop card look
        // and picking up the existing heart button + rating badge hooks).
        $rows = array();
        if ( class_exists( 'TNM_Trust_Favorites' ) && method_exists( 'TNM_Trust_Favorites', 'get_user_favorites' ) ) {
            $rows = TNM_Trust_Favorites::get_user_favorites( get_current_user_id() );
        }
        $product_ids = array_values( array_filter( array_map( static fn( $r ): int => (int) ( $r['product_id'] ?? 0 ), (array) $rows ) ) );

        ob_start();
        ?>
        <div class="mnu-parity mnu-parity--favorites">
            <header class="mnu-parity__header">
                <h1>Your favorites</h1>
                <p>All the pieces you've hearted across ShopMyNest.</p>
            </header>
            <?php if ( empty( $product_ids ) ) : ?>
                <p class="mnu-parity__empty">No favorites yet. Tap the heart on any <a href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>">listing</a> to save it here.</p>
            <?php else :
                $query = new WP_Query(
                    array(
                        'post_type'      => 'product',
                        'post_status'    => 'publish',
                        'post__in'       => $product_ids,
                        'orderby'        => 'post__in',
                        'posts_per_page' => count( $product_ids ),
                        'no_found_rows'  => true,
                    )
                );
                if ( $query->have_posts() ) : ?>
                    <ul class="products columns-4 mnu-favorites-grid">
                    <?php while ( $query->have_posts() ) : $query->the_post();
                        wc_get_template_part( 'content', 'product' );
                    endwhile; ?>
                    </ul>
                <?php wp_reset_postdata(); else : ?>
                    <p class="mnu-parity__empty">Your favorites are all unpublished or unavailable right now.</p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /* ---------------------------------------------- Saved-searches shortcode */

    public static function shortcode_saved_searches(): string {
        self::enqueue_assets();
        if ( ! is_user_logged_in() ) {
            return self::guest_gate( 'Sign in to see your saved searches.' );
        }

        $rows = array();
        if ( class_exists( 'MNU_SavedSearches' ) && method_exists( 'MNU_SavedSearches', 'list_for_user' ) ) {
            $rows = MNU_SavedSearches::list_for_user( get_current_user_id() );
        }

        ob_start();
        ?>
        <div class="mnu-parity mnu-parity--saved-searches" data-mnu-saved-searches>
            <header class="mnu-parity__header">
                <h1>Saved searches</h1>
                <p>We'll notify you when new listings match one of these.</p>
            </header>
            <?php if ( empty( $rows ) ) : ?>
                <p class="mnu-parity__empty">No saved searches yet. Open the <a href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>">shop</a>, filter to what you want, then tap "Save alert" above the grid.</p>
            <?php else : ?>
                <ul class="mnu-parity__list">
                    <?php foreach ( $rows as $row ) :
                        $rid    = (int) ( $row['id'] ?? 0 );
                        $label  = (string) ( $row['label'] ?? 'Saved search' );
                        $notify = ! empty( $row['notify'] );
                        $query  = (array) ( $row['query'] ?? array() );
                        $bits   = array();
                        if ( ! empty( $query['search'] ) )   { $bits[] = 'Keyword: ' . $query['search']; }
                        if ( ! empty( $query['category'] ) ) {
                            $term = get_term( (int) $query['category'], 'product_cat' );
                            if ( $term && ! is_wp_error( $term ) ) { $bits[] = 'Category: ' . $term->name; }
                        }
                        if ( ! empty( $query['min_price'] ) || ! empty( $query['max_price'] ) ) {
                            $bits[] = 'Price: ' . ( $query['min_price'] ?: '0' ) . ' - ' . ( $query['max_price'] ?: 'any' );
                        }
                        if ( ! empty( $query['pa_condition'] ) ) { $bits[] = 'Condition: ' . $query['pa_condition']; }
                        $summary = $bits ? implode( '  |  ', $bits ) : 'All new listings';
                        // Build a shop URL that reproduces the saved filters so
                        // the user can jump back into the search.
                        $shop_qs = array_filter( array(
                            's'                 => $query['search']    ?? '',
                            'min_price'         => $query['min_price'] ?? '',
                            'max_price'         => $query['max_price'] ?? '',
                            'filter_condition'  => $query['pa_condition'] ?? '',
                            'orderby'           => $query['sort']      ?? '',
                        ), static fn( $v ) => (string) $v !== '' );
                        $shop_url = add_query_arg( $shop_qs, wc_get_page_permalink( 'shop' ) );
                    ?>
                    <li class="mnu-parity__row" data-search-id="<?php echo esc_attr( (string) $rid ); ?>">
                        <div class="mnu-parity__row-main">
                            <a class="mnu-parity__row-label" href="<?php echo esc_url( $shop_url ); ?>"><?php echo esc_html( $label ); ?></a>
                            <p class="mnu-parity__row-summary"><?php echo esc_html( $summary ); ?></p>
                        </div>
                        <div class="mnu-parity__row-actions">
                            <label class="mnu-toggle">
                                <input type="checkbox" data-mnu-search-notify <?php checked( $notify ); ?>>
                                <span>Alerts <?php echo $notify ? 'on' : 'off'; ?></span>
                            </label>
                            <button type="button" class="mnu-parity__row-delete" data-mnu-search-delete aria-label="Delete saved search">Delete</button>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /* ------------------------------------------------------ Blog shortcode */

    public static function shortcode_blog( $atts ): string {
        self::enqueue_assets();
        $atts = shortcode_atts( array( 'view' => '' ), (array) $atts, 'mynest_blog' );

        // Single view when ?post= present.
        $post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
        if ( $post_id > 0 ) {
            return self::render_blog_single( $post_id );
        }

        // Feed view. Query the approved blog CPT directly (mirrors what
        // MNU_Blog::public_feed does for the REST payload, but keeps the
        // page fully SSR so search engines can index it).
        $cpt = class_exists( 'MNU_Blog' ) ? MNU_Blog::CPT : 'mnu_blog_post';
        // MNU_Blog::STATUSES maps 'approved' → 'publish'; that constant is
        // private so we hard-code the WordPress status name here.
        $approved_status = 'publish';
        $query = new WP_Query(
            array(
                'post_type'      => $cpt,
                'post_status'    => $approved_status,
                'posts_per_page' => 20,
                'orderby'        => 'date',
                'order'          => 'DESC',
                'no_found_rows'  => true,
            )
        );

        ob_start();
        ?>
        <div class="mnu-parity mnu-blog">
            <header class="mnu-parity__header">
                <h1>Fresh from the Nest</h1>
                <p>Updates, ideas, and behind-the-scenes from ShopMyNest sellers.</p>
            </header>
            <?php if ( ! $query->have_posts() ) : ?>
                <p class="mnu-parity__empty">No blog posts yet. Check back soon.</p>
            <?php else : ?>
                <div class="mnu-blog__grid">
                    <?php while ( $query->have_posts() ) : $query->the_post();
                        $bp        = get_post();
                        $bid       = (int) $bp->ID;
                        $author_id = (int) $bp->post_author;
                        $author    = function_exists( 'tnm_seller_display_name' ) ? tnm_seller_display_name( $author_id ) : get_the_author_meta( 'display_name', $author_id );
                        $avatar    = function_exists( 'tnm_user_avatar_url' ) ? tnm_user_avatar_url( $author_id, 96 ) : '';
                        $thumb_id  = (int) get_post_thumbnail_id( $bp );
                        $thumb     = $thumb_id ? (string) wp_get_attachment_image_url( $thumb_id, 'medium_large' ) : '';
                        $comments  = (int) get_comments_number( $bp );
                        $favs      = class_exists( 'MNU_Blog' ) ? MNU_Blog::favorites_count( $bid ) : 0;
                        $excerpt   = wp_strip_all_tags( wp_trim_words( $bp->post_content, 32, '…' ) );
                        $single    = add_query_arg( 'post', $bid, self::page_url( 'blog' ) );
                    ?>
                        <article class="mnu-blog__card">
                            <a class="mnu-blog__card-link" href="<?php echo esc_url( $single ); ?>">
                                <?php if ( $thumb ) : ?>
                                    <img class="mnu-blog__card-image" src="<?php echo esc_url( $thumb ); ?>" alt="" loading="lazy">
                                <?php endif; ?>
                                <div class="mnu-blog__card-body">
                                    <p class="mnu-blog__card-author">
                                        <?php if ( $avatar ) : ?><img src="<?php echo esc_url( $avatar ); ?>" alt=""><?php endif; ?>
                                        <span><?php echo esc_html( $author ); ?></span>
                                    </p>
                                    <p class="mnu-blog__card-excerpt"><?php echo esc_html( $excerpt ); ?></p>
                                    <p class="mnu-blog__card-meta">
                                        <span aria-hidden="true">♥</span> <?php echo esc_html( (string) $favs ); ?>
                                        &nbsp;&middot;&nbsp;
                                        <span aria-hidden="true">💬</span> <?php echo esc_html( (string) $comments ); ?>
                                    </p>
                                </div>
                            </a>
                        </article>
                    <?php endwhile; wp_reset_postdata(); ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Render a single blog post from cache/REST for /nest-blog/?post=<id>.
     * Only shows approved posts (matches REST /blog/posts public feed rule).
     */
    private static function render_blog_single( int $post_id ): string {
        $post = get_post( $post_id );
        if ( ! $post || 'mnu_blog_post' !== $post->post_type ) {
            return '<div class="mnu-parity"><p>Post not found. <a href="' . esc_url( self::page_url( 'blog' ) ) . '">Back to the blog</a>.</p></div>';
        }
        // Approved posts have post_status = publish (see MNU_Blog::STATUSES).
        // Anything else (pending, draft/rejected) is not public.
        if ( 'publish' !== $post->post_status ) {
            return '<div class="mnu-parity"><p>This post isn\'t published yet. <a href="' . esc_url( self::page_url( 'blog' ) ) . '">Back to the blog</a>.</p></div>';
        }

        $author_id = (int) $post->post_author;
        $author    = function_exists( 'tnm_seller_display_name' ) ? tnm_seller_display_name( $author_id ) : get_the_author_meta( 'display_name', $author_id );
        $avatar    = function_exists( 'tnm_user_avatar_url' ) ? tnm_user_avatar_url( $author_id, 128 ) : '';
        $thumb_id  = (int) get_post_thumbnail_id( $post );
        $img       = $thumb_id ? (string) wp_get_attachment_image_url( $thumb_id, 'large' ) : '';
        $favs      = class_exists( 'MNU_Blog' ) ? MNU_Blog::favorites_count( $post_id ) : 0;
        $me_faved  = ( is_user_logged_in() && class_exists( 'MNU_Blog' ) ) ? MNU_Blog::is_favorited( get_current_user_id(), $post_id ) : false;
        $created   = mysql_to_rfc3339( $post->post_date_gmt );

        ob_start();
        ?>
        <div class="mnu-parity mnu-blog-single" data-mnu-blog-single data-post-id="<?php echo esc_attr( (string) $post_id ); ?>">
            <p class="mnu-blog-single__back"><a href="<?php echo esc_url( self::page_url( 'blog' ) ); ?>">← Back to Fresh from the Nest</a></p>
            <?php if ( $img ) : ?>
                <img class="mnu-blog-single__image" src="<?php echo esc_url( $img ); ?>" alt="">
            <?php endif; ?>
            <div class="mnu-blog-single__meta">
                <?php if ( $avatar ) : ?>
                    <img class="mnu-blog-single__avatar" src="<?php echo esc_url( $avatar ); ?>" alt="">
                <?php endif; ?>
                <div>
                    <strong><?php echo esc_html( $author ); ?></strong>
                    <span class="mnu-blog-single__date"><?php echo esc_html( wp_date( get_option( 'date_format' ), (int) strtotime( $created ) ) ); ?></span>
                </div>
            </div>
            <article class="mnu-blog-single__body"><?php echo wp_kses_post( wpautop( $post->post_content ) ); ?></article>
            <div class="mnu-blog-single__actions">
                <button
                    type="button"
                    class="mnu-blog-fav<?php echo $me_faved ? ' is-faved' : ''; ?>"
                    data-mnu-blog-fav
                    aria-pressed="<?php echo $me_faved ? 'true' : 'false'; ?>"
                    <?php echo is_user_logged_in() ? '' : 'data-guest="1"'; ?>
                >
                    <span aria-hidden="true"><?php echo $me_faved ? '♥' : '♡'; ?></span>
                    <span class="mnu-blog-fav__count" data-mnu-blog-fav-count><?php echo esc_html( (string) $favs ); ?></span>
                </button>
            </div>
            <section class="mnu-blog-comments" data-mnu-blog-comments>
                <h2>Comments</h2>
                <div class="mnu-blog-comments__list" data-mnu-blog-comments-list>
                    <p class="mnu-parity__loading">Loading comments…</p>
                </div>
                <?php if ( is_user_logged_in() ) : ?>
                    <form class="mnu-blog-comments__form" data-mnu-blog-comment-form>
                        <label>
                            <span class="screen-reader-text">Your comment</span>
                            <textarea name="content" rows="3" placeholder="Say something kind…" required maxlength="1000"></textarea>
                        </label>
                        <button type="submit" class="mnu-parity__button">Post comment</button>
                    </form>
                <?php else : ?>
                    <p><a href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>">Sign in</a> to leave a comment.</p>
                <?php endif; ?>
            </section>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /* --------------------------------------------------------- Utilities */

    private static function guest_gate( string $line ): string {
        $login = wp_login_url( home_url( add_query_arg( array() ) ) );
        return '<div class="mnu-parity mnu-parity--gate"><p>' . esc_html( $line ) . '</p><p><a class="mnu-parity__button" href="' . esc_url( $login ) . '">Sign in</a></p></div>';
    }
}
