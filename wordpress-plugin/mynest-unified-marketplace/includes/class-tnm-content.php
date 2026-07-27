<?php

defined( 'ABSPATH' ) || exit;

final class TNM_Content {
    public static function init(): void {
        add_action( 'init', array( __CLASS__, 'register_post_types' ) );
        add_action( 'transition_post_status', array( __CLASS__, 'notify_followers' ), 10, 3 );
    }

    public static function register_post_types(): void {
        if ( ! post_type_exists( 'tnm_application' ) ) {
            register_post_type(
                'tnm_application',
            array(
                'labels' => array(
                    'name'          => __( 'Seller Applications', 'the-nest-marketplace' ),
                    'singular_name' => __( 'Seller Application', 'the-nest-marketplace' ),
                ),
                'public'              => false,
                'show_ui'             => true,
                'show_in_menu'        => 'tnm-marketplace',
                'supports'            => array( 'title', 'editor', 'author', 'custom-fields' ),
                'capability_type'      => 'post',
                'map_meta_cap'         => true,
                'exclude_from_search'  => true,
            )
            );
        }

        if ( ! post_type_exists( 'tnm_post' ) ) {
            register_post_type(
                'tnm_post',
            array(
                'labels' => array(
                    'name'          => __( 'Nest Posts', 'the-nest-marketplace' ),
                    'singular_name' => __( 'Nest Post', 'the-nest-marketplace' ),
                    'add_new_item'  => __( 'Add Nest Post', 'the-nest-marketplace' ),
                ),
                'public'          => true,
                'show_ui'         => true,
                'show_in_menu'    => 'tnm-marketplace',
                'show_in_rest'    => true,
                'rest_base'       => 'nest-posts',
                'supports'        => array( 'title', 'editor', 'author', 'thumbnail', 'comments' ),
                'has_archive'     => true,
                'rewrite'         => array( 'slug' => 'nest-posts' ),
                'capability_type' => 'post',
                'map_meta_cap'    => true,
            )
            );
        }
    }

    public static function notify_followers( string $new_status, string $old_status, WP_Post $post ): void {
        if ( 'tnm_post' !== $post->post_type || 'publish' !== $new_status || 'publish' === $old_status ) {
            return;
        }
        global $wpdb;
        $followers = $wpdb->get_col(
            $wpdb->prepare(
                'SELECT follower_id FROM ' . tnm_table( 'follows' ) . ' WHERE following_id = %d',
                (int) $post->post_author
            )
        );
        foreach ( $followers as $follower_id ) {
            tnm_notify(
                (int) $follower_id,
                (int) $post->post_author,
                'new_post',
                sprintf( '%s shared a new post', tnm_seller_display_name( (int) $post->post_author ) ),
                get_the_title( $post ),
                $post->ID,
                'tnm_post',
                get_permalink( $post )
            );
        }
    }
}
