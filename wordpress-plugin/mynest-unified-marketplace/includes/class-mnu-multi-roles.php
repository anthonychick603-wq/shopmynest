<?php
/**
 * Multi-role editor for WP users.
 *
 * WordPress supports multiple roles per user natively (via WP_User::add_role /
 * remove_role), but the built-in Users screen only exposes a single-role
 * dropdown. This class adds:
 *
 *   1. A "Roles" checkbox group on the user edit screen (all available roles).
 *   2. A save handler that syncs the checked set to WP's native role storage.
 *   3. A "Roles" column on the Users list table showing every assigned role.
 *   4. Bulk actions "Add role…" and "Remove role…" for multi-user changes.
 *
 * The primary role dropdown is left untouched so third-party code that reads
 * $user->roles[0] keeps working. When the checkbox list is saved, the first
 * checkbox becomes the primary role only if it differs from the current one.
 *
 * @package MyNest_Unified_Marketplace
 * @since 3.7.23
 */

defined( 'ABSPATH' ) || exit;

final class MNU_Multi_Roles {

    private const NONCE_ACTION = 'mnu_multi_roles_save';
    private const NONCE_FIELD  = 'mnu_multi_roles_nonce';

    public static function init(): void {
        add_action( 'show_user_profile',   array( __CLASS__, 'render_field' ) );
        add_action( 'edit_user_profile',   array( __CLASS__, 'render_field' ) );
        add_action( 'personal_options_update', array( __CLASS__, 'save' ) );
        add_action( 'edit_user_profile_update', array( __CLASS__, 'save' ) );

        add_filter( 'manage_users_columns',       array( __CLASS__, 'add_roles_column' ) );
        add_filter( 'manage_users_custom_column', array( __CLASS__, 'render_roles_column' ), 10, 3 );

        add_filter( 'bulk_actions-users',        array( __CLASS__, 'register_bulk_actions' ) );
        add_filter( 'handle_bulk_actions-users', array( __CLASS__, 'handle_bulk_actions' ), 10, 3 );
        add_action( 'admin_notices',             array( __CLASS__, 'bulk_notice' ) );
    }

    /**
     * Render the checkbox list under the primary-role dropdown.
     */
    public static function render_field( WP_User $user ): void {
        if ( ! current_user_can( 'promote_users' ) ) {
            return;
        }
        // Non-admins can't edit administrator's roles.
        if ( user_can( $user->ID, 'manage_options' ) && ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $all_roles     = self::available_roles();
        $current_roles = (array) $user->roles;
        wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
        ?>
        <h2><?php esc_html_e( 'Additional roles', 'mynest-unified-marketplace' ); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label><?php esc_html_e( 'Roles', 'mynest-unified-marketplace' ); ?></label></th>
                <td>
                    <fieldset>
                        <legend class="screen-reader-text"><?php esc_html_e( 'Roles', 'mynest-unified-marketplace' ); ?></legend>
                        <?php foreach ( $all_roles as $slug => $label ) : ?>
                            <label style="display:block; margin-bottom:4px;">
                                <input type="checkbox"
                                       name="mnu_user_roles[]"
                                       value="<?php echo esc_attr( $slug ); ?>"
                                       <?php checked( in_array( $slug, $current_roles, true ) ); ?> />
                                <?php echo esc_html( $label ); ?>
                                <code style="opacity:.6"><?php echo esc_html( $slug ); ?></code>
                            </label>
                        <?php endforeach; ?>
                        <p class="description">
                            <?php esc_html_e( 'A user can hold multiple roles at once. The primary role shown in the dropdown above is only used for the default label; capabilities come from the combined set of roles checked here.', 'mynest-unified-marketplace' ); ?>
                        </p>
                    </fieldset>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Save the checked roles to WP_User.
     */
    public static function save( int $user_id ): void {
        if ( ! current_user_can( 'promote_users' ) ) {
            return;
        }
        if ( ! isset( $_POST[ self::NONCE_FIELD ] ) ) {
            return; // Field wasn't rendered on this screen.
        }
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
            return;
        }
        if ( ! isset( $_POST['mnu_user_roles'] ) ) {
            return; // Field submitted with no checkboxes -> leave native single-role save alone.
        }

        $user = get_user_by( 'id', $user_id );
        if ( ! $user ) {
            return;
        }
        // Non-admins can't edit administrator's roles.
        if ( user_can( $user_id, 'manage_options' ) && ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $submitted = array_map( 'sanitize_key', (array) wp_unslash( $_POST['mnu_user_roles'] ) );
        $valid     = array_keys( self::available_roles() );
        $desired   = array_values( array_intersect( $submitted, $valid ) );

        if ( empty( $desired ) ) {
            // Safety: don't strip every role. Leave user with primary role dropdown value.
            return;
        }

        // Prevent a non-admin from granting administrator to anyone.
        if ( in_array( 'administrator', $desired, true ) && ! current_user_can( 'manage_options' ) ) {
            $desired = array_diff( $desired, array( 'administrator' ) );
        }

        // Sync: remove roles not in desired, add roles missing.
        $current = (array) $user->roles;
        foreach ( array_diff( $current, $desired ) as $to_remove ) {
            $user->remove_role( $to_remove );
        }
        foreach ( array_diff( $desired, $current ) as $to_add ) {
            $user->add_role( $to_add );
        }
    }

    public static function add_roles_column( array $columns ): array {
        $new = array();
        foreach ( $columns as $key => $label ) {
            $new[ $key ] = $label;
            if ( 'role' === $key ) {
                $new['mnu_all_roles'] = __( 'All roles', 'mynest-unified-marketplace' );
            }
        }
        return $new;
    }

    public static function render_roles_column( string $output, string $column_name, int $user_id ): string {
        if ( 'mnu_all_roles' !== $column_name ) {
            return $output;
        }
        $user = get_user_by( 'id', $user_id );
        if ( ! $user ) {
            return $output;
        }
        $all = self::available_roles();
        $labels = array();
        foreach ( (array) $user->roles as $slug ) {
            $labels[] = $all[ $slug ] ?? $slug;
        }
        return $labels ? esc_html( implode( ', ', $labels ) ) : '<span style="opacity:.5">—</span>';
    }

    public static function register_bulk_actions( array $actions ): array {
        foreach ( self::available_roles() as $slug => $label ) {
            $actions[ 'mnu_add_role_' . $slug ]    = sprintf( __( 'Add role: %s', 'mynest-unified-marketplace' ), $label );
            $actions[ 'mnu_remove_role_' . $slug ] = sprintf( __( 'Remove role: %s', 'mynest-unified-marketplace' ), $label );
        }
        return $actions;
    }

    public static function handle_bulk_actions( string $redirect, string $action, array $user_ids ): string {
        if ( ! current_user_can( 'promote_users' ) ) {
            return $redirect;
        }
        $add    = str_starts_with( $action, 'mnu_add_role_' );
        $remove = str_starts_with( $action, 'mnu_remove_role_' );
        if ( ! $add && ! $remove ) {
            return $redirect;
        }
        $role = $add ? substr( $action, strlen( 'mnu_add_role_' ) ) : substr( $action, strlen( 'mnu_remove_role_' ) );
        if ( ! array_key_exists( $role, self::available_roles() ) ) {
            return $redirect;
        }
        // Prevent bulk-granting administrator from non-admin.
        if ( 'administrator' === $role && ! current_user_can( 'manage_options' ) ) {
            return $redirect;
        }

        $changed = 0;
        foreach ( $user_ids as $uid ) {
            $user = get_user_by( 'id', (int) $uid );
            if ( ! $user ) {
                continue;
            }
            if ( user_can( $user->ID, 'manage_options' ) && ! current_user_can( 'manage_options' ) ) {
                continue;
            }
            if ( $add && ! in_array( $role, (array) $user->roles, true ) ) {
                $user->add_role( $role );
                $changed++;
            } elseif ( $remove && in_array( $role, (array) $user->roles, true ) ) {
                // Never leave a user with zero roles via bulk remove.
                if ( count( (array) $user->roles ) > 1 ) {
                    $user->remove_role( $role );
                    $changed++;
                }
            }
        }

        return add_query_arg( array(
            'mnu_role_bulk' => $add ? 'added' : 'removed',
            'mnu_role_name' => $role,
            'mnu_role_ct'   => $changed,
        ), $redirect );
    }

    public static function bulk_notice(): void {
        if ( empty( $_GET['mnu_role_bulk'] ) ) {
            return;
        }
        $action = sanitize_key( $_GET['mnu_role_bulk'] );
        $role   = sanitize_key( $_GET['mnu_role_name'] ?? '' );
        $count  = (int) ( $_GET['mnu_role_ct'] ?? 0 );
        $all    = self::available_roles();
        $label  = $all[ $role ] ?? $role;
        printf(
            '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
            esc_html( sprintf(
                _n( '%1$d user updated (%2$s: %3$s).', '%1$d users updated (%2$s: %3$s).', $count, 'mynest-unified-marketplace' ),
                $count,
                'added' === $action ? __( 'added role', 'mynest-unified-marketplace' ) : __( 'removed role', 'mynest-unified-marketplace' ),
                $label
            ) )
        );
    }

    /**
     * All editable roles, keyed by slug, value = display name.
     * Filters out roles the current user can't grant.
     */
    private static function available_roles(): array {
        $editable = get_editable_roles();
        $out      = array();
        foreach ( $editable as $slug => $data ) {
            $out[ $slug ] = translate_user_role( $data['name'] );
        }
        asort( $out );
        return $out;
    }
}
