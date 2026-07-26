<?php

defined( 'ABSPATH' ) || exit;

final class TNM_Social {
    public static function init(): void {
        add_action( 'wp_insert_post', array( __CLASS__, 'stamp_post_author' ), 10, 3 );
    }

    public static function stamp_post_author( int $post_id, WP_Post $post, bool $update ): void {
        if ( 'tnm_post' !== $post->post_type || wp_is_post_revision( $post_id ) ) {
            return;
        }
        update_post_meta( $post_id, '_tnm_author_store', tnm_seller_display_name( (int) $post->post_author ) );
    }

    public static function follow( int $follower_id, int $following_id ): bool|WP_Error {
        global $wpdb;
        if ( ! $follower_id ) {
            return tnm_json_error( 'login_required', 'You must be logged in to follow a seller.', 401 );
        }
        if ( $follower_id === $following_id ) {
            return tnm_json_error( 'cannot_follow_self', 'You cannot follow yourself.', 422 );
        }
        if ( ! tnm_is_seller( $following_id ) ) {
            return tnm_json_error( 'seller_not_found', 'Seller not found.', 404 );
        }
        $wpdb->query(
            $wpdb->prepare(
                'INSERT IGNORE INTO ' . tnm_table( 'follows' ) . ' (follower_id,following_id,created_at) VALUES (%d,%d,%s)',
                $follower_id,
                $following_id,
                current_time( 'mysql', true )
            )
        );
        if ( $wpdb->rows_affected ) {
            tnm_notify( $following_id, $follower_id, 'new_follower', 'You have a new follower', get_the_author_meta( 'display_name', $follower_id ) . ' followed your shop.', $follower_id, 'user' );
        }
        return true;
    }

    public static function unfollow( int $follower_id, int $following_id ): bool {
        global $wpdb;
        return false !== $wpdb->delete( tnm_table( 'follows' ), array( 'follower_id' => $follower_id, 'following_id' => $following_id ), array( '%d', '%d' ) );
    }

    public static function is_following( int $follower_id, int $following_id ): bool {
        global $wpdb;
        return (bool) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT id FROM ' . tnm_table( 'follows' ) . ' WHERE follower_id=%d AND following_id=%d',
                $follower_id,
                $following_id
            )
        );
    }

    public static function follower_count( int $seller_id ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . tnm_table( 'follows' ) . ' WHERE following_id=%d', $seller_id ) );
    }

    public static function following_ids( int $user_id ): array {
        global $wpdb;
        return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT following_id FROM ' . tnm_table( 'follows' ) . ' WHERE follower_id=%d', $user_id ) ) );
    }

    public static function create_post( int $user_id, array $data ): int|WP_Error {
        if ( ! $user_id || ( ! tnm_is_seller( $user_id ) && ! tnm_is_admin_or_manager( $user_id ) ) ) {
            return tnm_json_error( 'post_permission_denied', 'Only approved sellers can publish Nest posts.', 403 );
        }
        $title   = sanitize_text_field( (string) tnm_array_get( $data, 'title', '' ) );
        $content = wp_kses_post( (string) tnm_array_get( $data, 'content', '' ) );
        if ( ! $title || ! $content ) {
            return tnm_json_error( 'invalid_post', 'Post title and content are required.', 422 );
        }
        $post_id = wp_insert_post(
            array(
                'post_type'      => 'tnm_post',
                'post_status'    => 'publish',
                'post_author'    => $user_id,
                'post_title'     => $title,
                'post_content'   => $content,
                'comment_status' => 'open',
            ),
            true
        );
        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }
        if ( ! empty( $data['image_id'] ) && tnm_user_can_use_attachment( $user_id, absint( $data['image_id'] ) ) ) {
            set_post_thumbnail( $post_id, absint( $data['image_id'] ) );
        }
        return (int) $post_id;
    }

    public static function feed( int $user_id = 0, int $page = 1, int $per_page = 20 ): array {
        $following = $user_id ? self::following_ids( $user_id ) : array();
        $authors   = $following ?: array();
        $query_args = array(
            'post_type'      => array( 'tnm_post', 'product' ),
            'post_status'    => 'publish',
            'posts_per_page' => max( 1, min( 50, $per_page ) ),
            'paged'          => max( 1, $page ),
            'orderby'        => 'date',
            'order'          => 'DESC',
        );
        if ( $authors ) {
            $query_args['author__in'] = $authors;
        }
        $query = new WP_Query( $query_args );
        $posts = array_values( array_filter( array_map( array( __CLASS__, 'feed_item_to_array' ), $query->posts ) ) );
        return array(
            'items'       => $posts,
            'page'        => max( 1, $page ),
            'total'       => (int) $query->found_posts,
            'total_pages' => (int) $query->max_num_pages,
            'mode'        => $authors ? 'following' : 'discover',
        );
    }

    public static function feed_item_to_array( WP_Post $post ): array {
        if ( 'tnm_post' === $post->post_type ) {
            return self::post_to_array( $post );
        }
        if ( 'product' === $post->post_type ) {
            $product = wc_get_product( $post->ID );
            if ( ! $product || ! $product->is_visible() ) {
                return array();
            }
            $seller_id = tnm_get_product_seller_id( $product );
            return array(
                'type'       => 'product',
                'id'         => $product->get_id(),
                'title'      => $product->get_name(),
                'content'    => wp_strip_all_tags( $product->get_description() ),
                'excerpt'    => wp_trim_words( wp_strip_all_tags( $product->get_short_description() ?: $product->get_description() ), 35 ),
                'image'      => wp_get_attachment_image_url( $product->get_image_id(), 'large' ) ?: wc_placeholder_img_src(),
                'permalink'  => get_permalink( $product->get_id() ),
                'date'       => get_post_time( DATE_ATOM, true, $post ),
                'price'      => (float) $product->get_price(),
                'price_html' => $product->get_price_html(),
                'author'     => array(
                    'id'         => $seller_id,
                    'store_name' => tnm_seller_display_name( $seller_id ),
                    'avatar'     => tnm_user_avatar_url( $seller_id, 256 ),
                ),
                'comments'   => (int) get_comments_number( $post ),
            );
        }
        return array();
    }

    public static function post_to_array( WP_Post $post ): array {
        $author_id = (int) $post->post_author;
        return array(
            'type'      => 'post',
            'id'        => $post->ID,
            'title'     => get_the_title( $post ),
            'content'   => apply_filters( 'the_content', $post->post_content ),
            'excerpt'   => wp_trim_words( wp_strip_all_tags( $post->post_content ), 35 ),
            'image'     => get_the_post_thumbnail_url( $post, 'large' ) ?: '',
            'permalink' => get_permalink( $post ),
            'date'      => get_post_time( DATE_ATOM, true, $post ),
            'author'    => array(
                'id'         => $author_id,
                'store_name' => tnm_seller_display_name( $author_id ),
                'avatar'     => tnm_user_avatar_url( $author_id, 256 ),
            ),
            'comments'  => (int) get_comments_number( $post ),
        );
    }

    /**
     * Shape a single WP_Comment for the mobile app, mirroring the author/avatar
     * shape used by post_to_array() (id + name + avatar built via tnm_user_avatar_url).
     */
    public static function comment_to_array( WP_Comment $comment ): array {
        $author_id = (int) $comment->user_id;
        return array(
            'id'         => (int) $comment->comment_ID,
            'content'    => $comment->comment_content,
            'created_at' => mysql2date( 'Y-m-d\TH:i:s', $comment->comment_date_gmt ),
            'author'     => array(
                'id'     => $author_id,
                'name'   => get_the_author_meta( 'display_name', $author_id ),
                'avatar' => tnm_user_avatar_url( $author_id, 128 ),
            ),
        );
    }

    /**
     * A published tnm_post or a 404 WP_Error, shared by the comment read/write paths.
     */
    private static function published_post_or_error( int $post_id ): WP_Post|WP_Error {
        $post = get_post( $post_id );
        if ( ! $post || 'tnm_post' !== $post->post_type || 'publish' !== $post->post_status ) {
            return tnm_json_error( 'post_not_found', 'Post not found.', 404 );
        }
        return $post;
    }

    /**
     * Approved comments on a post, oldest-first, paginated.
     */
    public static function post_comments( int $post_id, int $page = 1, int $per_page = 20 ): array|WP_Error {
        $post = self::published_post_or_error( $post_id );
        if ( is_wp_error( $post ) ) {
            return $post;
        }
        $per_page = max( 1, min( 50, $per_page ) );
        $page     = max( 1, $page );
        $total    = (int) get_comments(
            array( 'post_id' => $post_id, 'status' => 'approve', 'count' => true )
        );
        $comments = get_comments(
            array(
                'post_id' => $post_id,
                'status'  => 'approve',
                'orderby' => 'comment_date_gmt',
                'order'   => 'ASC',
                'number'  => $per_page,
                'offset'  => ( $page - 1 ) * $per_page,
            )
        );
        return array(
            'comments' => array_map( array( __CLASS__, 'comment_to_array' ), $comments ),
            'total'    => $total,
            'pages'    => (int) ceil( $total / $per_page ),
        );
    }

    /**
     * Create an auto-approved comment authored by a logged-in app user.
     */
    public static function add_comment( int $user_id, int $post_id, string $content ): array|WP_Error {
        $post = self::published_post_or_error( $post_id );
        if ( is_wp_error( $post ) ) {
            return $post;
        }
        $content = trim( sanitize_textarea_field( $content ) );
        if ( '' === $content ) {
            return tnm_json_error( 'empty_comment', 'Comment cannot be empty.', 400 );
        }
        if ( mb_strlen( $content ) > 2000 ) {
            return tnm_json_error( 'comment_too_long', 'Comment cannot exceed 2,000 characters.', 400 );
        }
        $user       = get_userdata( $user_id );
        $comment_id = wp_insert_comment(
            array(
                'comment_post_ID'      => $post_id,
                'user_id'              => $user_id,
                'comment_content'      => $content,
                'comment_approved'     => 1,
                'comment_type'         => 'comment',
                'comment_author'       => $user ? $user->display_name : '',
                'comment_author_email' => $user ? $user->user_email : '',
            )
        );
        if ( ! $comment_id ) {
            return tnm_json_error( 'comment_failed', 'Could not save your comment.', 500 );
        }
        return self::comment_to_array( get_comment( $comment_id ) );
    }

    public static function notifications( int $user_id, int $page = 1, int $per_page = 30 ): array {
        global $wpdb;
        $per_page = max( 1, min( 100, $per_page ) );
        $offset   = ( max( 1, $page ) - 1 ) * $per_page;
        $total    = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . tnm_table( 'notifications' ) . ' WHERE user_id=%d', $user_id ) );
        $unread   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . tnm_table( 'notifications' ) . ' WHERE user_id=%d AND is_read=0', $user_id ) );
        $rows     = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . tnm_table( 'notifications' ) . ' WHERE user_id=%d ORDER BY created_at DESC,id DESC LIMIT %d OFFSET %d',
                $user_id,
                $per_page,
                $offset
            ),
            ARRAY_A
        );
        foreach ( $rows as &$row ) {
            $row['id']        = (int) $row['id'];
            $row['user_id']   = (int) $row['user_id'];
            $row['actor_id']  = (int) $row['actor_id'];
            $row['object_id'] = (int) $row['object_id'];
            $row['is_read']   = (bool) $row['is_read'];
            $row['actor']     = $row['actor_id'] ? array(
                'display_name' => get_the_author_meta( 'display_name', $row['actor_id'] ),
                'avatar'       => tnm_user_avatar_url( (int) $row['actor_id'], 128 ),
            ) : null;
        }
        return array( 'items' => $rows, 'unread' => $unread, 'total' => $total, 'page' => max( 1, $page ), 'total_pages' => (int) ceil( $total / $per_page ) );
    }

    public static function mark_notifications_read( int $user_id, array $ids = array() ): int {
        global $wpdb;
        if ( $ids ) {
            $ids = array_filter( array_map( 'absint', $ids ) );
            if ( ! $ids ) {
                return 0;
            }
            $id_sql = implode( ',', $ids );
            return (int) $wpdb->query( $wpdb->prepare( 'UPDATE ' . tnm_table( 'notifications' ) . " SET is_read=1 WHERE user_id=%d AND id IN ($id_sql)", $user_id ) );
        }
        return (int) $wpdb->update( tnm_table( 'notifications' ), array( 'is_read' => 1 ), array( 'user_id' => $user_id, 'is_read' => 0 ), array( '%d' ), array( '%d', '%d' ) );
    }

    public static function send_message( int $sender_id, int $recipient_id, string $message ): int|WP_Error {
        global $wpdb;
        $message = sanitize_textarea_field( $message );
        if ( ! $sender_id ) {
            return tnm_json_error( 'login_required', 'You must be logged in to send a message.', 401 );
        }
        if ( ! $recipient_id || ! get_userdata( $recipient_id ) || $sender_id === $recipient_id ) {
            return tnm_json_error( 'invalid_recipient', 'Choose a valid recipient.', 422 );
        }
        if ( ! $message ) {
            return tnm_json_error( 'empty_message', 'Message cannot be empty.', 422 );
        }
        if ( strlen( $message ) > 5000 ) {
            return tnm_json_error( 'message_too_long', 'Message cannot exceed 5,000 characters.', 422 );
        }
        $wpdb->insert(
            tnm_table( 'messages' ),
            array(
                'sender_id'    => $sender_id,
                'recipient_id' => $recipient_id,
                'message'      => $message,
                'is_read'      => 0,
                'created_at'   => current_time( 'mysql', true ),
            ),
            array( '%d', '%d', '%s', '%d', '%s' )
        );
        $message_id = (int) $wpdb->insert_id;
        tnm_notify( $recipient_id, $sender_id, 'new_message', 'New message from ' . get_the_author_meta( 'display_name', $sender_id ), wp_trim_words( $message, 15 ), $message_id, 'message' );
        return $message_id;
    }

    public static function conversations( int $user_id ): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT m.* FROM ' . tnm_table( 'messages' ) . ' m INNER JOIN (SELECT CASE WHEN sender_id=%1$d THEN recipient_id ELSE sender_id END AS other_id, MAX(id) AS max_id FROM ' . tnm_table( 'messages' ) . ' WHERE sender_id=%1$d OR recipient_id=%1$d GROUP BY other_id) x ON m.id=x.max_id ORDER BY m.created_at DESC',
                $user_id
            ),
            ARRAY_A
        );
        return array_map(
            static function ( array $row ) use ( $user_id ): array {
                $other_id = (int) ( (int) $row['sender_id'] === $user_id ? $row['recipient_id'] : $row['sender_id'] );
                return array(
                    'user' => array(
                        'id'           => $other_id,
                        'display_name' => get_the_author_meta( 'display_name', $other_id ),
                        'store_name'   => tnm_seller_display_name( $other_id ),
                        'avatar'       => tnm_user_avatar_url( $other_id, 128 ),
                    ),
                    'last_message' => $row['message'],
                    'date'         => $row['created_at'],
                    'unread'       => (int) $row['recipient_id'] === $user_id && ! (bool) $row['is_read'],
                );
            },
            $rows
        );
    }

    public static function conversation( int $user_id, int $other_id, int $limit = 100 ): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . tnm_table( 'messages' ) . ' WHERE (sender_id=%d AND recipient_id=%d) OR (sender_id=%d AND recipient_id=%d) ORDER BY created_at ASC,id ASC LIMIT %d',
                $user_id,
                $other_id,
                $other_id,
                $user_id,
                max( 1, min( 200, $limit ) )
            ),
            ARRAY_A
        );
        $wpdb->query( $wpdb->prepare( 'UPDATE ' . tnm_table( 'messages' ) . ' SET is_read=1 WHERE sender_id=%d AND recipient_id=%d AND is_read=0', $other_id, $user_id ) );
        return array_map(
            static fn( array $row ): array => array(
                'id'           => (int) $row['id'],
                'sender_id'    => (int) $row['sender_id'],
                'recipient_id' => (int) $row['recipient_id'],
                'message'      => $row['message'],
                'is_read'      => (bool) $row['is_read'],
                'created_at'   => $row['created_at'],
            ),
            $rows
        );
    }

    public static function can_review( int $reviewer_id, int $seller_id, int $order_id = 0 ): int|false {
        if ( ! $reviewer_id || ! $seller_id || $reviewer_id === $seller_id ) {
            return false;
        }
        $query_args = array(
            'customer_id' => $reviewer_id,
            'status'      => array( 'wc-processing', 'wc-completed' ),
            'limit'       => 100,
            'orderby'     => 'date',
            'order'       => 'DESC',
            'return'      => 'objects',
        );
        if ( $order_id ) {
            $query_args['include'] = array( $order_id );
        }
        $orders = wc_get_orders( $query_args );
        foreach ( $orders as $order ) {
            if ( tnm_order_contains_seller( $order, $seller_id ) ) {
                return $order->get_id();
            }
        }
        return false;
    }

    public static function submit_review( int $reviewer_id, int $seller_id, int $rating, string $review, int $order_id = 0 ): int|WP_Error {
        global $wpdb;
        $rating = max( 1, min( 5, $rating ) );
        $review = sanitize_textarea_field( $review );
        if ( ! tnm_is_seller( $seller_id ) ) {
            return tnm_json_error( 'seller_not_found', 'Seller not found.', 404 );
        }
        $verified_order = self::can_review( $reviewer_id, $seller_id, $order_id );
        if ( 'yes' === tnm_get_option( 'verified_reviews_only', 'yes' ) && ! $verified_order ) {
            return tnm_json_error( 'review_not_verified', 'Only customers who purchased from this seller can leave a review.', 403 );
        }
        $order_id = $verified_order ?: $order_id;
        if ( ! $order_id ) {
            return tnm_json_error( 'review_order_required', 'A related order is required.', 422 );
        }
        $existing = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT id FROM ' . tnm_table( 'reviews' ) . ' WHERE reviewer_id=%d AND seller_id=%d AND order_id=%d',
                $reviewer_id,
                $seller_id,
                $order_id
            )
        );
        $data = array(
            'rating'     => $rating,
            'review'     => $review,
            'status'     => 'approved',
            'updated_at' => current_time( 'mysql', true ),
        );
        if ( $existing ) {
            $wpdb->update( tnm_table( 'reviews' ), $data, array( 'id' => (int) $existing ), array( '%d', '%s', '%s', '%s' ), array( '%d' ) );
            $review_id = (int) $existing;
        } else {
            $data += array(
                'reviewer_id' => $reviewer_id,
                'seller_id'   => $seller_id,
                'order_id'    => $order_id,
                'created_at'  => current_time( 'mysql', true ),
            );
            $wpdb->insert( tnm_table( 'reviews' ), $data, array( '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%s' ) );
            $review_id = (int) $wpdb->insert_id;
        }
        tnm_notify( $seller_id, $reviewer_id, 'seller_review', 'You received a ' . $rating . '-star review', wp_trim_words( $review, 20 ), $review_id, 'review' );
        return $review_id;
    }

    public static function seller_reviews( int $seller_id, int $page = 1, int $per_page = 20 ): array {
        global $wpdb;
        $per_page = max( 1, min( 100, $per_page ) );
        $offset   = ( max( 1, $page ) - 1 ) * $per_page;
        $summary  = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS total, AVG(rating) AS average FROM " . tnm_table( 'reviews' ) . " WHERE seller_id=%d AND status='approved'", $seller_id ), ARRAY_A );
        $rows     = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM " . tnm_table( 'reviews' ) . " WHERE seller_id=%d AND status='approved' ORDER BY created_at DESC,id DESC LIMIT %d OFFSET %d",
                $seller_id,
                $per_page,
                $offset
            ),
            ARRAY_A
        );
        foreach ( $rows as &$row ) {
            $row['id']          = (int) $row['id'];
            $row['reviewer_id'] = (int) $row['reviewer_id'];
            $row['seller_id']   = (int) $row['seller_id'];
            $row['order_id']    = (int) $row['order_id'];
            $row['rating']      = (int) $row['rating'];
            $row['reviewer']    = array(
                'display_name' => get_the_author_meta( 'display_name', $row['reviewer_id'] ),
                'avatar'       => tnm_user_avatar_url( (int) $row['reviewer_id'], 128 ),
            );
        }
        return array(
            'items'       => $rows,
            'total'       => (int) ( $summary['total'] ?? 0 ),
            'average'     => round( (float) ( $summary['average'] ?? 0 ), 2 ),
            'page'        => max( 1, $page ),
            'total_pages' => (int) ceil( (int) ( $summary['total'] ?? 0 ) / $per_page ),
        );
    }

    public static function seller_profile( int $seller_id, int $viewer_id = 0 ): array|WP_Error {
        $user = get_userdata( $seller_id );
        if ( ! $user || ! tnm_is_seller( $seller_id ) ) {
            return tnm_json_error( 'seller_not_found', 'Seller not found.', 404 );
        }
        $reviews = self::seller_reviews( $seller_id, 1, 5 );

        // This seller's most recent published posts, shaped identically to the
        // feed so the app can render them with the same components.
        $posts = array_map(
            array( self::class, 'post_to_array' ),
            get_posts(
                array(
                    'post_type'   => 'tnm_post',
                    'author'      => $seller_id,
                    'post_status' => 'publish',
                    'numberposts' => 20,
                    'orderby'     => 'date',
                    'order'       => 'DESC',
                )
            )
        );

        return array(
            'id'           => $seller_id,
            'store_name'   => tnm_seller_display_name( $seller_id ),
            'display_name' => $user->display_name,
            'about'        => (string) get_user_meta( $seller_id, 'tnm_store_about', true ),
            'avatar'       => tnm_user_avatar_url( $seller_id, 512 ),
            'banner'       => wp_get_attachment_image_url( (int) get_user_meta( $seller_id, 'tnm_store_banner_id', true ), 'full' ) ?: '',
            'followers'    => self::follower_count( $seller_id ),
            'is_following' => $viewer_id ? self::is_following( $viewer_id, $seller_id ) : false,
            'rating'       => $reviews['average'],
            'review_count' => $reviews['total'],
            'joined'       => $user->user_registered,
            'posts'        => $posts,
        );
    }
}
