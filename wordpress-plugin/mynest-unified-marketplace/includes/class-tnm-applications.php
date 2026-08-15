<?php

defined( 'ABSPATH' ) || exit;

final class TNM_Applications {
    public static function init(): void {
        add_action( 'add_meta_boxes_tnm_application', array( __CLASS__, 'add_meta_box' ) );
        add_action( 'admin_post_tnm_application_decision', array( __CLASS__, 'handle_decision' ) );
        add_action( 'transition_post_status', array( __CLASS__, 'maybe_promote_on_publish' ), 10, 3 );
        add_filter( 'manage_tnm_application_posts_columns', array( __CLASS__, 'columns' ) );
        add_action( 'manage_tnm_application_posts_custom_column', array( __CLASS__, 'column_content' ), 10, 2 );
    }

    public static function submit( int $user_id, array $data ): int|WP_Error {
        if ( ! $user_id ) {
            return tnm_json_error( 'login_required', 'You must be logged in to apply.', 401 );
        }
        if ( tnm_is_marketplace_user( $user_id ) ) {
            return tnm_json_error( 'already_seller', 'This account is already a seller.', 409 );
        }

        $existing = get_posts(
            array(
                'post_type'      => 'tnm_application',
                'post_status'    => array( 'pending', 'publish', 'draft' ),
                'author'         => $user_id,
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'meta_query'     => array(
                    array(
                        'key'     => '_tnm_status',
                        'value'   => array( 'pending', 'approved' ),
                        'compare' => 'IN',
                    ),
                ),
            )
        );
        if ( $existing ) {
            return tnm_json_error( 'application_exists', 'You already have an active seller application.', 409, array( 'application_id' => (int) $existing[0] ) );
        }

        $store_name = sanitize_text_field( (string) tnm_array_get( $data, 'store_name', '' ) );
        $about      = sanitize_textarea_field( (string) tnm_array_get( $data, 'about', '' ) );
        $products   = sanitize_textarea_field( (string) tnm_array_get( $data, 'products', '' ) );
        $website    = esc_url_raw( (string) tnm_array_get( $data, 'website', '' ) );
        $terms      = ! empty( $data['accept_terms'] );

        if ( ! $store_name || ! $about || ! $products || ! $terms ) {
            return tnm_json_error( 'missing_application_fields', 'Store name, about, products, and acceptance of seller terms are required.', 422 );
        }

        $application_id = wp_insert_post(
            array(
                'post_type'    => 'tnm_application',
                'post_status'  => 'pending',
                'post_author'  => $user_id,
                'post_title'   => $store_name . ' — ' . get_the_author_meta( 'display_name', $user_id ),
                'post_content' => $about,
            ),
            true
        );
        if ( is_wp_error( $application_id ) ) {
            return $application_id;
        }

        update_post_meta( $application_id, '_tnm_status', 'pending' );
        update_post_meta( $application_id, '_tnm_store_name', $store_name );
        update_post_meta( $application_id, '_tnm_products', $products );
        update_post_meta( $application_id, '_tnm_website', $website );
        update_post_meta( $application_id, '_tnm_terms_accepted_at', current_time( 'mysql', true ) );
        update_user_meta( $user_id, 'tnm_application_id', $application_id );

        $admins = get_users( array( 'role__in' => array( 'administrator', 'shop_manager' ), 'fields' => 'ID' ) );
        foreach ( $admins as $admin_id ) {
            tnm_notify( (int) $admin_id, $user_id, 'seller_application', 'New seller application', $store_name, (int) $application_id, 'tnm_application', get_edit_post_link( $application_id, 'raw' ) ?: '' );
        }

        return (int) $application_id;
    }

    public static function approve( int $application_id, int $reviewer_id ): bool|WP_Error {
        $result = self::promote( $application_id, $reviewer_id );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        // Publishing is the canonical "approved" post state. This fires
        // transition_post_status → maybe_promote_on_publish(), but promote() is
        // idempotent so that second call is a harmless no-op.
        if ( 'publish' !== get_post_status( $application_id ) ) {
            wp_update_post( array( 'ID' => $application_id, 'post_status' => 'publish' ) );
        }
        return true;
    }

    /**
     * Promote an application's author to the seller role and stamp the approval
     * metadata. Idempotent by design: re-running it on an already-approved
     * application simply re-applies the role, which is also the repair path for
     * accounts approved before role promotion was wired to the Publish action.
     */
    public static function promote( int $application_id, int $reviewer_id ): bool|WP_Error {
        $application = get_post( $application_id );
        if ( ! $application || 'tnm_application' !== $application->post_type ) {
            return tnm_json_error( 'invalid_application', 'Seller application not found.', 404 );
        }
        $user = get_userdata( (int) $application->post_author );
        if ( ! $user ) {
            return tnm_json_error( 'invalid_application_user', 'Application user not found.', 404 );
        }

        // A user counts as "already a seller" when they carry either the legacy
        // (tnm_seller) or the unified (mynest_seller) role. Everything downstream
        // treats these as equivalent, so we mirror them on promotion and use OR
        // logic to decide whether this is a first-time approval.
        $roles          = (array) $user->roles;
        $newly_promoted = ! in_array( 'tnm_seller', $roles, true ) && ! in_array( 'mynest_seller', $roles, true );

        // Add BOTH seller roles without replacing any existing role (e.g. customer).
        // Keeping them in sync means capability checks against either role succeed.
        $user->add_role( 'tnm_seller' );
        $user->add_role( 'mynest_seller' );
        update_user_meta( $user->ID, 'tnm_store_name', sanitize_text_field( (string) get_post_meta( $application_id, '_tnm_store_name', true ) ) );
        update_user_meta( $user->ID, 'tnm_store_about', sanitize_textarea_field( $application->post_content ) );
        if ( ! get_user_meta( $user->ID, 'tnm_seller_approved_at', true ) ) {
            update_user_meta( $user->ID, 'tnm_seller_approved_at', current_time( 'mysql', true ) );
        }
        update_post_meta( $application_id, '_tnm_status', 'approved' );
        update_post_meta( $application_id, '_tnm_reviewed_by', $reviewer_id );
        update_post_meta( $application_id, '_tnm_reviewed_at', current_time( 'mysql', true ) );

        // Notify/email the applicant only the first time the role is granted.
        if ( $newly_promoted ) {
            tnm_notify( $user->ID, $reviewer_id, 'application_approved', 'Your seller application was approved', sprintf( 'Welcome to %s. Your seller dashboard is ready.', get_bloginfo( 'name' ) ), $application_id, 'tnm_application', tnm_page_url( 'seller_dashboard' ) );
            wp_mail( $user->user_email, sprintf( 'Your %s seller application was approved', get_bloginfo( 'name' ) ), 'Your seller account is active. Sign in to open your Seller Dashboard.' );
        }
        return true;
    }

    /**
     * Approving a seller application in wp-admin must promote the applicant's WP
     * role regardless of *how* it was approved. The custom "Approve" button and
     * WordPress's own Publish button both transition the post to `publish` and
     * land here, so the role promotion runs either way.
     */
    public static function maybe_promote_on_publish( string $new_status, string $old_status, WP_Post $post ): void {
        if ( 'tnm_application' !== $post->post_type || 'publish' !== $new_status || wp_is_post_revision( $post ) ) {
            return;
        }
        self::promote( (int) $post->ID, get_current_user_id() );
    }

    public static function reject( int $application_id, int $reviewer_id, string $reason = '' ): bool|WP_Error {
        $application = get_post( $application_id );
        if ( ! $application || 'tnm_application' !== $application->post_type ) {
            return tnm_json_error( 'invalid_application', 'Seller application not found.', 404 );
        }
        update_post_meta( $application_id, '_tnm_status', 'rejected' );
        update_post_meta( $application_id, '_tnm_reviewed_by', $reviewer_id );
        update_post_meta( $application_id, '_tnm_reviewed_at', current_time( 'mysql', true ) );
        update_post_meta( $application_id, '_tnm_rejection_reason', sanitize_textarea_field( $reason ) );
        wp_update_post( array( 'ID' => $application_id, 'post_status' => 'draft' ) );

        $user = get_userdata( (int) $application->post_author );
        if ( $user ) {
            tnm_notify( $user->ID, $reviewer_id, 'application_rejected', 'Seller application update', $reason ?: 'Your application was not approved at this time.', $application_id, 'tnm_application' );
            wp_mail( $user->user_email, sprintf( 'Update on your %s seller application', get_bloginfo( 'name' ) ), $reason ?: 'Your seller application was not approved at this time.' );
        }
        return true;
    }

    public static function add_meta_box(): void {
        add_meta_box( 'tnm-application-review', 'Application Review', array( __CLASS__, 'render_meta_box' ), 'tnm_application', 'side', 'high' );
        add_meta_box( 'tnm-application-details', 'Application Details', array( __CLASS__, 'render_details' ), 'tnm_application', 'normal', 'high' );
    }

    public static function render_meta_box( WP_Post $post ): void {
        $status = get_post_meta( $post->ID, '_tnm_status', true ) ?: 'pending';
        echo '<p><strong>Status:</strong> ' . esc_html( ucfirst( $status ) ) . '</p>';
        if ( 'pending' === $status ) {
            $base = admin_url( 'admin-post.php?action=tnm_application_decision&application_id=' . $post->ID );
            echo '<p><a class="button button-primary" href="' . esc_url( wp_nonce_url( $base . '&decision=approve', 'tnm_application_' . $post->ID ) ) . '">Approve</a></p>';
            echo '<p><a class="button" href="' . esc_url( wp_nonce_url( $base . '&decision=reject', 'tnm_application_' . $post->ID ) ) . '" onclick="return confirm(\'Reject this application?\');">Reject</a></p>';
        }
    }

    public static function render_details( WP_Post $post ): void {
        $user = get_userdata( (int) $post->post_author );
        echo '<table class="widefat striped"><tbody>';
        echo '<tr><th>Applicant</th><td>' . esc_html( $user ? $user->display_name . ' (' . $user->user_email . ')' : 'Unknown' ) . '</td></tr>';
        echo '<tr><th>Store name</th><td>' . esc_html( (string) get_post_meta( $post->ID, '_tnm_store_name', true ) ) . '</td></tr>';
        echo '<tr><th>Products</th><td>' . nl2br( esc_html( (string) get_post_meta( $post->ID, '_tnm_products', true ) ) ) . '</td></tr>';
        $website = (string) get_post_meta( $post->ID, '_tnm_website', true );
        echo '<tr><th>Website/social</th><td>' . ( $website ? '<a href="' . esc_url( $website ) . '" target="_blank" rel="noopener">' . esc_html( $website ) . '</a>' : '—' ) . '</td></tr>';
        echo '<tr><th>About</th><td>' . nl2br( esc_html( $post->post_content ) ) . '</td></tr>';
        echo '</tbody></table>';
    }

    public static function handle_decision(): void {
        if ( ! current_user_can( 'tnm_manage_marketplace' ) && ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'You do not have permission to review applications.' );
        }
        $application_id = absint( $_GET['application_id'] ?? 0 );
        check_admin_referer( 'tnm_application_' . $application_id );
        $decision = sanitize_key( $_GET['decision'] ?? '' );
        if ( 'approve' === $decision ) {
            self::approve( $application_id, get_current_user_id() );
        } elseif ( 'reject' === $decision ) {
            self::reject( $application_id, get_current_user_id() );
        }
        wp_safe_redirect( get_edit_post_link( $application_id, 'raw' ) ?: admin_url( 'edit.php?post_type=tnm_application' ) );
        exit;
    }

    public static function columns( array $columns ): array {
        $columns['tnm_status'] = 'Status';
        $columns['tnm_user']   = 'Applicant';
        return $columns;
    }

    public static function column_content( string $column, int $post_id ): void {
        if ( 'tnm_status' === $column ) {
            echo esc_html( ucfirst( (string) get_post_meta( $post_id, '_tnm_status', true ) ) );
        }
        if ( 'tnm_user' === $column ) {
            $user = get_userdata( (int) get_post_field( 'post_author', $post_id ) );
            echo esc_html( $user ? $user->display_name . ' — ' . $user->user_email : 'Unknown' );
        }
    }
}
