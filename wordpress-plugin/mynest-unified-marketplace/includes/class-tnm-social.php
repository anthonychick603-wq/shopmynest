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

    public static function send_message( int $sender_id, int $recipient_id, string $message, int $product_id = 0, array $photo_ids = array() ): int|WP_Error {
        global $wpdb;
        $message = sanitize_textarea_field( $message );
        // v3.7.86 — photo_ids are WP attachment IDs uploaded via
        // /messages/photo_upload. Sanitize hard: cap at 5, keep ints only,
        // dedupe, and confirm each attachment is (a) owned by the sender and
        // (b) actually marked as a messages photo. This blocks a caller from
        // attaching arbitrary media library items they don't own.
        $photo_ids = array_values( array_unique( array_filter( array_map( 'absint', $photo_ids ) ) ) );
        if ( count( $photo_ids ) > 5 ) {
            $photo_ids = array_slice( $photo_ids, 0, 5 );
        }
        $valid_photo_ids = array();
        foreach ( $photo_ids as $aid ) {
            $author = (int) get_post_field( 'post_author', $aid );
            $tag    = (string) get_post_meta( $aid, '_mnu_message_photo', true );
            if ( $author === $sender_id && $tag === '1' ) {
                $valid_photo_ids[] = $aid;
            }
        }
        if ( ! $sender_id ) {
            return tnm_json_error( 'login_required', 'You must be logged in to send a message.', 401 );
        }
        if ( ! $recipient_id || ! get_userdata( $recipient_id ) || $sender_id === $recipient_id ) {
            return tnm_json_error( 'invalid_recipient', 'Choose a valid recipient.', 422 );
        }
        if ( ! $message && empty( $valid_photo_ids ) ) {
            return tnm_json_error( 'empty_message', 'Message cannot be empty.', 422 );
        }
        if ( strlen( $message ) > 5000 ) {
            return tnm_json_error( 'message_too_long', 'Message cannot exceed 5,000 characters.', 422 );
        }
        // Rate limit: max 20 messages per hour per sender (skipped for admins).
        if ( ! tnm_is_admin_or_manager( $sender_id ) ) {
            $recent = (int) $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT COUNT(*) FROM ' . tnm_table( 'messages' ) . ' WHERE sender_id=%d AND created_at >= %s',
                    $sender_id,
                    gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS )
                )
            );
            if ( $recent >= 20 ) {
                return tnm_json_error( 'rate_limited', 'You have sent too many messages in the last hour. Try again later.', 429 );
            }
        }
        // If the first message references a product, prepend a small context line.
        if ( $product_id > 0 ) {
            $product = wc_get_product( $product_id );
            if ( $product ) {
                $existing = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        'SELECT COUNT(*) FROM ' . tnm_table( 'messages' ) . ' WHERE (sender_id=%d AND recipient_id=%d) OR (sender_id=%d AND recipient_id=%d)',
                        $sender_id,
                        $recipient_id,
                        $recipient_id,
                        $sender_id
                    )
                );
                if ( 0 === $existing ) {
                    $message = 'Re: ' . $product->get_name() . ' — ' . get_permalink( $product_id ) . "\n\n" . $message;
                }
            }
        }
        $photo_json = ! empty( $valid_photo_ids ) ? wp_json_encode( $valid_photo_ids ) : null;
        $wpdb->insert(
            tnm_table( 'messages' ),
            array(
                'sender_id'         => $sender_id,
                'recipient_id'      => $recipient_id,
                'message'           => $message,
                'photo_attachments' => $photo_json,
                'is_read'           => 0,
                'created_at'        => current_time( 'mysql', true ),
            ),
            array( '%d', '%d', '%s', '%s', '%d', '%s' )
        );
        $message_id = (int) $wpdb->insert_id;
        // v3.7.86 — link each attachment to its parent message so admins can
        // trace a photo back to the thread it was sent in without having to
        // JOIN the messages table on JSON.
        foreach ( $valid_photo_ids as $aid ) {
            update_post_meta( $aid, '_mnu_message_id', $message_id );
            update_post_meta( $aid, '_mnu_recipient_id', $recipient_id );
        }
        // Notification body reflects photo-only sends so the push preview is
        // meaningful even when there's no accompanying text.
        $notif_body = $message !== '' ? wp_trim_words( $message, 15 ) : sprintf( _n( '%d photo', '%d photos', count( $valid_photo_ids ), 'mynest' ), count( $valid_photo_ids ) );
        tnm_notify( $recipient_id, $sender_id, 'new_message', 'New message from ' . get_the_author_meta( 'display_name', $sender_id ), $notif_body, $message_id, 'message' );
        // Fire the email notification for the recipient (throttled to at most
        // one email per 15 minutes per sender/recipient pair so a rapid flurry
        // collapses into a single alert).
        self::maybe_email_new_message( $sender_id, $recipient_id, $message );
        return $message_id;
    }

    /**
     * Send an email to the recipient of a new message unless they opted out
     * or we already emailed them about this same sender within the last 15
     * minutes.
     */
    private static function maybe_email_new_message( int $sender_id, int $recipient_id, string $message ): void {
        $optout = get_user_meta( $recipient_id, 'tnm_email_optout_messages', true );
        if ( '1' === (string) $optout ) {
            return;
        }
        $throttle_key = 'tnm_msg_email_' . $recipient_id . '_' . $sender_id;
        if ( get_transient( $throttle_key ) ) {
            return;
        }
        $recipient = get_userdata( $recipient_id );
        if ( ! $recipient || empty( $recipient->user_email ) ) {
            return;
        }
        $sender_name    = get_the_author_meta( 'display_name', $sender_id );
        $recipient_name = $recipient->display_name ?: $recipient->user_login;
        $site_name      = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
        $inbox_url      = home_url( '/messages/' );
        $thread_url     = add_query_arg( array( 'to' => $sender_id ), $inbox_url );
        $unsub_url      = add_query_arg( array( 'tnm_email_optout' => 'messages', 'uid' => $recipient_id, 'k' => wp_hash( 'optout|messages|' . $recipient_id ) ), home_url( '/' ) );
        $preview        = wp_trim_words( wp_strip_all_tags( $message ), 40 );
        $subject        = sprintf( '[%s] New message from %s', $site_name, $sender_name );
        $body           = 'Hi ' . $recipient_name . ",\n\n";
        $body          .= $sender_name . " just sent you a new message on " . $site_name . ":\n\n";
        $body          .= '“' . $preview . "”\n\n";
        $body          .= "Reply here: " . $thread_url . "\n";
        $body          .= "Open your inbox: " . $inbox_url . "\n\n";
        $body          .= "— The " . $site_name . " team\n\n";
        $body          .= "You're receiving this because someone messaged you on " . $site_name . ". To stop these emails, click: " . $unsub_url . "\n";
        wp_mail( $recipient->user_email, $subject, $body );
        set_transient( $throttle_key, 1, 15 * MINUTE_IN_SECONDS );
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

    /** Count of message threads with at least one unread message for this user. */
    public static function unread_thread_count( int $user_id ): int {
        global $wpdb;
        if ( $user_id <= 0 ) { return 0; }
        return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT sender_id) FROM ' . tnm_table( 'messages' ) . ' WHERE recipient_id=%d AND is_read=0', $user_id ) );
    }

    /** Count of individual unread messages for this user. */
    public static function unread_message_count( int $user_id ): int {
        global $wpdb;
        if ( $user_id <= 0 ) { return 0; }
        return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . tnm_table( 'messages' ) . ' WHERE recipient_id=%d AND is_read=0', $user_id ) );
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
                // v3.7.86 — hydrate photo attachments so the client can render
                // the grid without a second round-trip. Each entry carries a
                // 24h signed URL scoped to the viewer.
                'photos'       => self::hydrate_message_photos( isset( $row['photo_attachments'] ) ? (string) $row['photo_attachments'] : '', $user_id ),
            ),
            $rows
        );
    }

    /**
     * v3.7.86 — turn a message row's photo_attachments JSON into an array of
     * viewer-facing photo objects with a fresh signed URL. Hidden-by-report
     * attachments are returned with hidden=true and no URL so the client can
     * render a placeholder instead of a broken image.
     *
     * @return array<int,array{id:int,url:string,w:int,h:int,mime:string,hidden:bool}>
     */
    public static function hydrate_message_photos( string $json, int $viewer_id ): array {
        if ( $json === '' ) { return array(); }
        $ids = json_decode( $json, true );
        if ( ! is_array( $ids ) ) { return array(); }
        $out = array();
        foreach ( $ids as $aid ) {
            $aid = absint( $aid );
            if ( $aid <= 0 ) { continue; }
            $meta   = wp_get_attachment_metadata( $aid );
            $mime   = (string) get_post_mime_type( $aid );
            $hidden = get_post_meta( $aid, '_mnu_photo_hidden', true ) === '1';
            $out[] = array(
                'id'     => $aid,
                'url'    => $hidden ? '' : self::signed_photo_url( $aid, $viewer_id ),
                'w'      => (int) ( $meta['width']  ?? 0 ),
                'h'      => (int) ( $meta['height'] ?? 0 ),
                'mime'   => $mime ?: 'image/jpeg',
                'hidden' => $hidden,
            );
        }
        return $out;
    }

    /**
     * v3.7.86 — build a 24h HMAC-signed URL to the photo endpoint. The
     * signature binds the attachment ID + viewer ID + expiry so a leaked URL
     * can't be used by anyone else and expires on its own. The signing key
     * is AUTH_KEY so it rotates only if the site's WP salts rotate.
     */
    public static function signed_photo_url( int $attachment_id, int $viewer_id ): string {
        $expires = time() + DAY_IN_SECONDS;
        $payload = $attachment_id . '|' . $viewer_id . '|' . $expires;
        $sig     = hash_hmac( 'sha256', $payload, defined( 'AUTH_KEY' ) ? AUTH_KEY : 'mnu-fallback' );
        return rest_url( 'the-nest/v1/messages/photo/' . $attachment_id ) . '?u=' . $viewer_id . '&e=' . $expires . '&s=' . $sig;
    }

    /**
     * v3.7.86 — verify a signed photo URL. Returns true iff the signature is
     * valid, the URL hasn't expired, and the caller matches the viewer that
     * the signature was minted for. This lets the endpoint be public (no
     * cookie auth required) while still gating access to the two thread
     * participants.
     */
    public static function verify_signed_photo( int $attachment_id, int $claimed_viewer_id, int $expires, string $sig ): bool {
        if ( $expires < time() ) { return false; }
        $payload  = $attachment_id . '|' . $claimed_viewer_id . '|' . $expires;
        $expected = hash_hmac( 'sha256', $payload, defined( 'AUTH_KEY' ) ? AUTH_KEY : 'mnu-fallback' );
        return hash_equals( $expected, $sig );
    }

    /**
     * v3.7.86 — persist an uploaded message photo. Runs after wp_handle_upload
     * has moved the file into place. Creates a WP attachment owned by the
     * uploader, tags it as a message photo (both for cleanup and to gate
     * send_message() attach requests), and generates metadata so the client
     * has width/height for grid layout.
     */
    public static function create_message_photo_attachment( array $upload, int $sender_id, int $recipient_id ): int|WP_Error {
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        $attachment = array(
            'post_mime_type' => $upload['type'],
            'post_title'     => 'Message photo',
            'post_content'   => '',
            'post_status'    => 'private',
            'post_author'    => $sender_id,
        );
        $attach_id = wp_insert_attachment( $attachment, $upload['file'] );
        if ( is_wp_error( $attach_id ) ) { return $attach_id; }
        $metadata = wp_generate_attachment_metadata( $attach_id, $upload['file'] );
        wp_update_attachment_metadata( $attach_id, $metadata );
        update_post_meta( $attach_id, '_mnu_message_photo', '1' );
        update_post_meta( $attach_id, '_mnu_sender_id', $sender_id );
        update_post_meta( $attach_id, '_mnu_recipient_id', $recipient_id );
        return (int) $attach_id;
    }

    /**
     * v3.7.86 — flag a photo as hidden by report. Only participants of the
     * message thread (and admins) can report. Reports write a note to the
     * ops feed so admins can review or restore.
     */
    public static function report_message_photo( int $reporter_id, int $attachment_id, string $reason ): array|WP_Error {
        $reason = sanitize_textarea_field( $reason );
        if ( strlen( $reason ) > 500 ) { $reason = substr( $reason, 0, 500 ); }
        $sender_id    = (int) get_post_meta( $attachment_id, '_mnu_sender_id', true );
        $recipient_id = (int) get_post_meta( $attachment_id, '_mnu_recipient_id', true );
        $is_message   = get_post_meta( $attachment_id, '_mnu_message_photo', true ) === '1';
        if ( ! $is_message || ! $sender_id ) {
            return tnm_json_error( 'invalid_photo', 'That photo is not a message attachment.', 404 );
        }
        if ( $reporter_id !== $sender_id && $reporter_id !== $recipient_id && ! tnm_is_admin_or_manager( $reporter_id ) ) {
            return tnm_json_error( 'not_participant', 'You cannot report a photo you did not receive.', 403 );
        }
        update_post_meta( $attachment_id, '_mnu_photo_hidden', '1' );
        update_post_meta( $attachment_id, '_mnu_photo_report_reason', $reason );
        update_post_meta( $attachment_id, '_mnu_photo_reported_by', $reporter_id );
        update_post_meta( $attachment_id, '_mnu_photo_reported_at', current_time( 'mysql', true ) );
        return array( 'ok' => true, 'attachment_id' => $attachment_id, 'hidden' => true );
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

    /**
     * Lightweight per-seller rating aggregate. Kept separate from
     * seller_reviews() so seller-list endpoints can attach {rating,
     * review_count} without paying for the full paginated review payload.
     * Static cache dedups the query when the same seller shows up more than
     * once (product feed with 20 items from 3 sellers -> 3 queries, not 20).
     *
     * @return array{rating: float, review_count: int}
     */
    public static function seller_rating_summary( int $seller_id ): array {
        static $cache = array();
        if ( isset( $cache[ $seller_id ] ) ) {
            return $cache[ $seller_id ];
        }
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT COUNT(*) AS total, AVG(rating) AS average FROM ' . tnm_table( 'reviews' ) . " WHERE seller_id=%d AND status='approved'",
                $seller_id
            ),
            ARRAY_A
        );
        $summary = array(
            'rating'       => round( (float) ( $row['average'] ?? 0 ), 2 ),
            'review_count' => (int) ( $row['total'] ?? 0 ),
        );
        $cache[ $seller_id ] = $summary;
        return $summary;
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

    /**
     * List approved sellers with optional search + pagination.
     * Used by the /sellers/ directory page and header search.
     */
    public static function sellers_list( string $search = '', int $page = 1, int $per_page = 24, int $viewer_id = 0 ): array {
        $page     = max( 1, $page );
        $per_page = max( 1, min( 100, $per_page ) );
        $args     = array(
            'role__in' => array( 'tnm_seller', 'mynest_seller', 'seller', 'vendor', 'wcv_vendor', 'dokan_vendor', 'shop_vendor', 'wc_product_vendors_vendor' ),
            'fields'   => 'ID',
            'number'   => $per_page,
            'offset'   => ( $page - 1 ) * $per_page,
            'orderby'  => 'registered',
            'order'    => 'DESC',
        );
        if ( '' !== $search ) {
            // Search across display_name, user_login, and store-name meta.
            $args['search']         = '*' . esc_attr( $search ) . '*';
            $args['search_columns'] = array( 'user_login', 'user_nicename', 'display_name' );
            $args['meta_query']     = array(
                'relation' => 'OR',
                array(
                    'key'     => 'tnm_store_name',
                    'value'   => $search,
                    'compare' => 'LIKE',
                ),
            );
        }
        $q       = new WP_User_Query( $args );
        $ids     = array_map( 'intval', (array) $q->get_results() );
        $total   = (int) $q->get_total();

        // Merge-in a second query that searches meta only (store name), so a
        // shop with no matching login/display-name still surfaces. WP_User_Query
        // treats `search` and `meta_query` as AND, so we need the union.
        if ( '' !== $search ) {
            $q2 = new WP_User_Query(
                array(
                    'role__in'   => $args['role__in'],
                    'fields'     => 'ID',
                    'number'     => $per_page,
                    'meta_query' => array( array( 'key' => 'tnm_store_name', 'value' => $search, 'compare' => 'LIKE' ) ),
                )
            );
            $extra_ids = array_map( 'intval', (array) $q2->get_results() );
            $ids       = array_values( array_unique( array_merge( $ids, $extra_ids ) ) );
            $total     = max( $total, count( $ids ) );
        }

        $items = array();
        foreach ( $ids as $sid ) {
            if ( ! tnm_is_seller( $sid ) ) {
                continue;
            }
            $user = get_userdata( $sid );
            if ( ! $user ) {
                continue;
            }
            // v3.7.67 — hide phantom stores from the public directory: any
            // seller with zero published products just adds noise (duplicate
            // display names collide visually and the tap-through lands on an
            // empty shop). Show them in the admin dashboard only.
            $product_count = (int) count_user_posts( $sid, 'product', true );
            if ( $product_count < 1 && ! current_user_can( 'manage_woocommerce' ) ) {
                continue;
            }
            $rating = self::seller_rating_summary( $sid );
            $items[] = array(
                'id'             => $sid,
                'store_name'     => tnm_seller_display_name( $sid ),
                'display_name'   => $user->display_name,
                'avatar'         => tnm_user_avatar_url( $sid, 256 ),
                'tagline'        => (string) get_user_meta( $sid, 'tnm_store_tagline', true ),
                'about_snippet'  => wp_trim_words( (string) get_user_meta( $sid, 'tnm_store_about', true ), 20 ),
                'follower_count' => self::follower_count( $sid ),
                'is_following'   => $viewer_id ? self::is_following( $viewer_id, $sid ) : false,
                'product_count'  => $product_count,
                'rating'         => $rating['rating'],
                'review_count'   => $rating['review_count'],
                'shop_url'       => home_url( '/shop-profile/?seller=' . $sid ),
            );
        }

        return array(
            'items'    => $items,
            'page'     => $page,
            'per_page' => $per_page,
            'total'    => count( $items ),
        );
    }

    /**
     * Return the list of sellers the given user is following, with light profile data.
     */
    public static function following_list( int $user_id ): array {
        if ( ! $user_id ) {
            return array();
        }
        $ids   = self::following_ids( $user_id );
        $items = array();
        foreach ( $ids as $sid ) {
            if ( ! tnm_is_seller( $sid ) ) {
                continue;
            }
            $user = get_userdata( $sid );
            if ( ! $user ) {
                continue;
            }
            $rating = self::seller_rating_summary( $sid );
            $items[] = array(
                'id'             => $sid,
                'store_name'     => tnm_seller_display_name( $sid ),
                'avatar'         => tnm_user_avatar_url( $sid, 256 ),
                'follower_count' => self::follower_count( $sid ),
                'product_count'  => count_user_posts( $sid, 'product', true ),
                'rating'         => $rating['rating'],
                'review_count'   => $rating['review_count'],
                'shop_url'       => home_url( '/shop-profile/?seller=' . $sid ),
            );
        }
        return $items;
    }

    /**
     * Recent product listings from shops the user follows (activity feed).
     */
    public static function following_feed( int $user_id, int $limit = 20 ): array {
        if ( ! $user_id ) {
            return array();
        }
        $ids = self::following_ids( $user_id );
        if ( ! $ids ) {
            return array();
        }
        $products = get_posts(
            array(
                'post_type'      => 'product',
                'post_status'    => 'publish',
                'author__in'     => $ids,
                'posts_per_page' => max( 1, min( 50, $limit ) ),
                'orderby'        => 'date',
                'order'          => 'DESC',
            )
        );
        $out = array();
        foreach ( $products as $p ) {
            $wc = wc_get_product( $p );
            if ( ! $wc ) {
                continue;
            }
            $sid = (int) $p->post_author;
            $out[] = array(
                'id'         => $p->ID,
                'title'      => $p->post_title,
                'permalink'  => get_permalink( $p->ID ),
                'price_html' => $wc->get_price_html(),
                'image'      => wp_get_attachment_image_url( $wc->get_image_id(), 'medium' ) ?: wc_placeholder_img_src( 'medium' ),
                'date'       => $p->post_date_gmt,
                'seller'     => array(
                    'id'         => $sid,
                    'store_name' => tnm_seller_display_name( $sid ),
                    'avatar'     => tnm_user_avatar_url( $sid, 128 ),
                    'shop_url'   => home_url( '/shop-profile/?seller=' . $sid ),
                ),
            );
        }
        return $out;
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
            'tagline'      => (string) get_user_meta( $seller_id, 'tnm_store_tagline', true ),
            'about'        => (string) get_user_meta( $seller_id, 'tnm_store_about', true ),
            'avatar'       => tnm_user_avatar_url( $seller_id, 512 ),
            'banner'       => wp_get_attachment_image_url( (int) get_user_meta( $seller_id, 'tnm_store_banner_id', true ), 'full' ) ?: '',
            'followers'    => self::follower_count( $seller_id ),
            'is_following' => $viewer_id ? self::is_following( $viewer_id, $seller_id ) : false,
            'rating'       => $reviews['average'],
            'review_count' => $reviews['total'],
            'joined'       => $user->user_registered,
            'posts'        => $posts,
            'email_optout_messages' => (string) get_user_meta( $seller_id, 'tnm_email_optout_messages', true ) === '1',
        );
    }

    /**
     * One-click unsubscribe endpoint. Hits home_url('/?tnm_email_optout=messages&uid=<id>&k=<hash>').
     * Sets the tnm_email_optout_messages user meta and prints a small confirmation page.
     */
    public static function handle_email_optout(): void {
        if ( empty( $_GET['tnm_email_optout'] ) ) {
            return;
        }
        $topic = sanitize_key( wp_unslash( (string) $_GET['tnm_email_optout'] ) );
        $uid   = isset( $_GET['uid'] ) ? (int) $_GET['uid'] : 0;
        $k     = isset( $_GET['k'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['k'] ) ) : '';
        if ( 'messages' !== $topic || $uid <= 0 || ! hash_equals( wp_hash( 'optout|messages|' . $uid ), $k ) ) {
            wp_die( 'Invalid unsubscribe link.', 'Unsubscribe', array( 'response' => 400 ) );
        }
        update_user_meta( $uid, 'tnm_email_optout_messages', '1' );
        wp_die(
            "<h1 style='font-family:sans-serif'>You're unsubscribed.</h1><p style='font-family:sans-serif'>You won't get emails for new messages anymore. You can re-enable them in your account settings on the site.</p>",
            'Unsubscribed',
            array( 'response' => 200 )
        );
    }
}
add_action( 'init', array( 'TNM_Social', 'handle_email_optout' ) );
