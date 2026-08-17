<?php
/**
 * MNU_Seller_Ownership
 *
 * Defensive layer that enforces seller ownership on every path that
 * can create or import a product:
 *
 *   1. Seller-facing REST create endpoints (already good via
 *      TNM_Marketplace::create_product) - we only observe here.
 *   2. WooCommerce core REST /wc/v3/products - previously unstamped;
 *      this class rejects inserts by admins/managers without an
 *      explicit seller_id and auto-stamps inserts by seller users.
 *   3. Standard wp-admin "New product" screen - if an admin publishes
 *      without picking a seller, block the transition instead of
 *      silently claiming the product for the admin user.
 *   4. CSV importer - already routes through TNM_Marketplace::create_product;
 *      we double-stamp to catch race conditions.
 *
 * Also provides a Marketplace → Seller Ownership admin page that lists
 * "orphan" products (missing seller id, or seller id points at an
 * admin/manager) and lets an admin reassign them in bulk.
 *
 * A daily WP-Cron audit emails admin@ when new orphan products appear.
 *
 * @package MyNest_Unified_Marketplace
 * @since 3.7.92
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MNU_Seller_Ownership {

	public const NS        = 'the-nest/v1';
	public const CRON_HOOK = 'mnu_seller_ownership_daily';
	public const PAGE_SLUG = 'mnu-seller-ownership';
	public const CAP       = 'manage_woocommerce';

	public static function init(): void {
		// Guard rails on product create / update paths.
		add_action( 'save_post_product', array( __CLASS__, 'guard_admin_publish' ), 5, 3 );
		add_filter( 'wp_insert_post_data', array( __CLASS__, 'guard_publish_transition' ), 20, 2 );
		add_filter( 'woocommerce_rest_pre_insert_product_object', array( __CLASS__, 'guard_wc_rest_insert' ), 10, 3 );
		add_filter( 'woocommerce_rest_pre_insert_product_variable_object', array( __CLASS__, 'guard_wc_rest_insert' ), 10, 3 );
		add_filter( 'woocommerce_rest_pre_insert_product_external_object', array( __CLASS__, 'guard_wc_rest_insert' ), 10, 3 );
		add_action( 'woocommerce_rest_insert_product_object', array( __CLASS__, 'stamp_after_wc_rest_insert' ), 10, 3 );

		// Admin page + cron.
		add_action( 'admin_menu',    array( __CLASS__, 'register_menu' ), 62 );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
		add_action( 'admin_post_mnu_seller_ownership_reassign', array( __CLASS__, 'handle_reassign' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );

		add_action( self::CRON_HOOK, array( __CLASS__, 'run_daily_audit' ) );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 20 * MINUTE_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	public static function deactivate(): void {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
		}
	}

	/* ---------------- Helpers ---------------- */

	/**
	 * True if the user is a marketplace seller (has the tnm_seller or
	 * mynest_seller role). Admins/managers are NOT considered sellers here.
	 */
	private static function is_seller_user( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		if ( function_exists( 'tnm_is_seller' ) ) {
			return (bool) tnm_is_seller( $user_id );
		}
		$user = get_userdata( $user_id );
		if ( ! $user || empty( $user->roles ) ) {
			return false;
		}
		return in_array( 'tnm_seller', (array) $user->roles, true )
			|| in_array( 'mynest_seller', (array) $user->roles, true );
	}

	/**
	 * Resolve the seller_id from a payload or POST array. Accepts either
	 * `_tnm_seller_id` (meta_data or top-level), `seller_id`, or a nested
	 * meta_data entry.
	 */
	private static function extract_seller_id_from_request( WP_REST_Request $request ): int {
		$candidates = array(
			(int) $request->get_param( '_tnm_seller_id' ),
			(int) $request->get_param( 'seller_id' ),
			(int) $request->get_param( 'mynest_seller_id' ),
		);
		$meta = $request->get_param( 'meta_data' );
		if ( is_array( $meta ) ) {
			foreach ( $meta as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}
				$key = (string) ( $entry['key'] ?? '' );
				if ( in_array( $key, array( '_tnm_seller_id', '_mynest_seller_id' ), true ) ) {
					$candidates[] = (int) ( $entry['value'] ?? 0 );
				}
			}
		}
		foreach ( $candidates as $c ) {
			if ( $c > 0 ) {
				return $c;
			}
		}
		return 0;
	}

	/* ---------------- Guards ---------------- */

	/**
	 * WooCommerce core REST product insert. Applies BEFORE the product is
	 * saved. Blocks admins from creating products with no seller assignment;
	 * auto-stamps seller users to themselves.
	 *
	 * @param WC_Product      $product  The product object being inserted.
	 * @param WP_REST_Request $request  The REST request.
	 * @param bool            $creating True on insert, false on update.
	 */
	public static function guard_wc_rest_insert( $product, WP_REST_Request $request, bool $creating ) {
		if ( ! $creating || ! is_object( $product ) ) {
			return $product;
		}
		$user_id  = get_current_user_id();
		$declared = self::extract_seller_id_from_request( $request );

		// If the caller explicitly declared a valid seller id, honor it.
		if ( $declared > 0 && self::is_seller_user( $declared ) ) {
			if ( method_exists( $product, 'update_meta_data' ) ) {
				$product->update_meta_data( '_tnm_seller_id', $declared );
				$product->update_meta_data( '_mynest_seller_id', $declared );
			}
			return $product;
		}
		if ( $declared > 0 && ! self::is_seller_user( $declared ) ) {
			return new WP_Error(
				'invalid_seller',
				'The provided seller_id does not belong to a marketplace seller.',
				array( 'status' => 422, 'seller_id' => $declared )
			);
		}

		// No declared seller: auto-stamp seller users to themselves.
		if ( self::is_seller_user( $user_id ) ) {
			if ( method_exists( $product, 'update_meta_data' ) ) {
				$product->update_meta_data( '_tnm_seller_id', $user_id );
				$product->update_meta_data( '_mynest_seller_id', $user_id );
			}
			return $product;
		}

		// No declared seller and the caller is not a seller: reject.
		return new WP_Error(
			'seller_required',
			'A seller must be assigned before this product can be created. Pass seller_id or a meta_data entry keyed _tnm_seller_id.',
			array( 'status' => 422 )
		);
	}

	/**
	 * Ensure post_author matches the stamped seller after a WC REST insert.
	 */
	public static function stamp_after_wc_rest_insert( $product, WP_REST_Request $request, bool $creating ): void {
		if ( ! $creating || ! is_object( $product ) ) {
			return;
		}
		$seller_id = method_exists( $product, 'get_meta' ) ? (int) $product->get_meta( '_tnm_seller_id', true ) : 0;
		if ( $seller_id > 0 && self::is_seller_user( $seller_id ) ) {
			$product_id = method_exists( $product, 'get_id' ) ? (int) $product->get_id() : 0;
			if ( $product_id > 0 ) {
				wp_update_post( array( 'ID' => $product_id, 'post_author' => $seller_id ) );
			}
		}
	}

	/**
	 * Block a draft → publish transition on wp-admin when the product still
	 * has no valid seller. This catches the "admin edits an auto-draft and
	 * hits Publish" path that the save_post_product guard misses because
	 * $update is true.
	 *
	 * @param array<string,mixed> $data
	 * @param array<string,mixed> $postarr
	 * @return array<string,mixed>
	 */
	public static function guard_publish_transition( array $data, array $postarr ): array {
		if ( ( $data['post_type'] ?? '' ) !== 'product' ) {
			return $data;
		}
		$new_status = (string) ( $data['post_status'] ?? '' );
		if ( ! in_array( $new_status, array( 'publish', 'pending' ), true ) ) {
			return $data;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return $data;
		}
		$post_id = (int) ( $postarr['ID'] ?? 0 );
		if ( $post_id <= 0 ) {
			return $data;
		}
		$stamped = (int) get_post_meta( $post_id, '_tnm_seller_id', true );
		if ( $stamped > 0 && self::is_seller_user( $stamped ) ) {
			return $data;
		}
		$author = (int) ( $data['post_author'] ?? $postarr['post_author'] ?? 0 );
		if ( self::is_seller_user( $author ) ) {
			return $data;
		}
		// POSTed seller_id already handled by save_admin_product_seller earlier.
		if ( isset( $_POST['_tnm_seller_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$posted = absint( wp_unslash( $_POST['_tnm_seller_id'] ) );
			if ( $posted > 0 && self::is_seller_user( $posted ) ) {
				return $data;
			}
		}
		// Force back to draft and mark orphan; the render side will show a notice.
		$data['post_status'] = 'draft';
		update_post_meta( $post_id, '_mnu_orphan_owner', 1 );
		set_transient(
			'mnu_seller_ownership_notice_' . get_current_user_id(),
			sprintf(
				'Product #%d stayed as Draft because no marketplace seller was assigned. Pick a seller in the "The Nest seller" box or reassign it from Marketplace → Seller Ownership.',
				$post_id
			),
			60
		);
		return $data;
	}

	/**
	 * Guard the wp-admin "New product" screen. Runs at priority 5, before
	 * TNM_Marketplace::stamp_product_seller (priority 20). If the current
	 * user is an admin/manager and there is no explicit _tnm_seller_id yet,
	 * demote the transition to `draft` and record a notice rather than
	 * silently claiming the product for the admin.
	 *
	 * This is intentionally quiet on updates - existing admin-owned
	 * products are reassigned via the ownership page.
	 */
	public static function guard_admin_publish( int $post_id, WP_Post $post, bool $update ): void {
		if ( wp_is_post_revision( $post_id ) || 'product' !== $post->post_type ) {
			return;
		}
		// Only care about new inserts, not updates.
		if ( $update ) {
			return;
		}
		// Autosave / auto-draft transitions have no meaningful content yet.
		if ( 'auto-draft' === $post->post_status ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		if ( self::is_seller_user( (int) $post->post_author ) ) {
			return;
		}
		$stamped = (int) get_post_meta( $post_id, '_tnm_seller_id', true );
		if ( $stamped > 0 && self::is_seller_user( $stamped ) ) {
			return;
		}
		if ( isset( $_POST['_tnm_seller_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$posted = absint( wp_unslash( $_POST['_tnm_seller_id'] ) );
			if ( $posted > 0 && self::is_seller_user( $posted ) ) {
				return;
			}
		}

		// Admin created a product without a seller. Demote to draft, tag
		// it as orphan, and keep the admin notice queue for the redirect.
		if ( 'publish' === $post->post_status || 'pending' === $post->post_status ) {
			remove_action( 'save_post_product', array( __CLASS__, 'guard_admin_publish' ), 5 );
			wp_update_post( array( 'ID' => $post_id, 'post_status' => 'draft' ) );
			add_action( 'save_post_product', array( __CLASS__, 'guard_admin_publish' ), 5, 3 );
		}
		update_post_meta( $post_id, '_mnu_orphan_owner', 1 );
		set_transient(
			'mnu_seller_ownership_notice_' . get_current_user_id(),
			sprintf(
				'Product #%d was moved to Draft because no marketplace seller was assigned. Assign a seller from Marketplace → Seller Ownership.',
				$post_id
			),
			60
		);
	}

	/* ---------------- Orphan discovery ---------------- */

	/**
	 * Products whose ownership is unclear. Two flavors:
	 *   - No _tnm_seller_id / _mynest_seller_id at all (post_author is
	 *     unknown or an admin).
	 *   - _tnm_seller_id points at a user that isn't a seller.
	 *
	 * @return array<int,array{id:int,title:string,status:string,author:int,seller_id:int,reason:string}>
	 */
	public static function orphan_products( int $limit = 200 ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID AS id, p.post_title AS title, p.post_status AS status, p.post_author AS author,
					(SELECT pm.meta_value FROM {$wpdb->postmeta} pm WHERE pm.post_id=p.ID AND pm.meta_key='_tnm_seller_id' LIMIT 1) AS seller_meta
				 FROM {$wpdb->posts} p
				 WHERE p.post_type='product'
				   AND p.post_status IN ('publish','private','draft','pending')
				 ORDER BY p.ID DESC
				 LIMIT %d",
				$limit * 3 // over-fetch since we filter
			),
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $r ) {
			$seller_id = (int) ( $r['seller_meta'] ?? 0 );
			$author    = (int) $r['author'];
			if ( $seller_id > 0 && self::is_seller_user( $seller_id ) ) {
				continue; // owned cleanly
			}
			$reason = 'no_seller_meta';
			if ( $seller_id > 0 && ! self::is_seller_user( $seller_id ) ) {
				$reason = 'seller_not_seller_role';
			} elseif ( $seller_id === 0 && self::is_seller_user( $author ) ) {
				// Common recoverable case - has a valid seller as author but no meta.
				$reason = 'author_only';
			} elseif ( $seller_id === 0 && ! self::is_seller_user( $author ) ) {
				$reason = 'admin_authored';
			}
			$out[] = array(
				'id'        => (int) $r['id'],
				'title'     => (string) $r['title'],
				'status'    => (string) $r['status'],
				'author'    => $author,
				'seller_id' => $seller_id,
				'reason'    => $reason,
			);
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * List of active sellers for the reassignment dropdown.
	 *
	 * @return array<int,string>
	 */
	public static function seller_choices(): array {
		$users = get_users( array(
			'role__in' => array( 'tnm_seller', 'mynest_seller' ),
			'orderby'  => 'display_name',
			'order'    => 'ASC',
			'fields'   => array( 'ID', 'display_name', 'user_email' ),
			'number'   => 500,
		) );
		$choices = array();
		foreach ( $users as $u ) {
			$name             = function_exists( 'tnm_seller_display_name' )
				? tnm_seller_display_name( (int) $u->ID )
				: $u->display_name;
			$choices[ (int) $u->ID ] = sprintf( '%s — %s', $name, $u->user_email );
		}
		return $choices;
	}

	/* ---------------- Admin page ---------------- */

	public static function register_menu(): void {
		add_submenu_page(
			'tnm-marketplace',
			'Seller Ownership',
			'Seller Ownership',
			self::CAP,
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function render_page(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Insufficient permissions.' );
		}
		$orphans   = self::orphan_products();
		$sellers   = self::seller_choices();
		$reassigned = isset( $_GET['reassigned'] ) ? (int) $_GET['reassigned'] : null;
		$reassign_err = isset( $_GET['reassign_error'] ) ? (string) $_GET['reassign_error'] : '';
		$reason_labels = array(
			'no_seller_meta'         => 'Missing seller meta',
			'seller_not_seller_role' => 'Assigned to a non-seller user',
			'author_only'            => 'Author is a seller but meta missing',
			'admin_authored'         => 'Admin-authored, no seller',
		);
		?>
		<div class="wrap">
			<h1>Seller Ownership</h1>
			<p class="description">
				Products below are missing a valid marketplace seller. This can happen when a product is created via
				the WooCommerce REST API without a seller, via wp-admin by an admin, or when a seller's role changes.
				Products created by admins from the WooCommerce "New product" screen without a seller are now
				auto-demoted to draft.
			</p>
			<?php if ( null !== $reassigned ) : ?>
				<div class="notice notice-success is-dismissible"><p>Reassigned <?php echo (int) $reassigned; ?> product<?php echo 1 === $reassigned ? '' : 's'; ?>.</p></div>
			<?php endif; ?>
			<?php if ( $reassign_err ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php echo esc_html( $reassign_err ); ?></p></div>
			<?php endif; ?>

			<?php if ( empty( $orphans ) ) : ?>
				<div class="notice notice-success"><p><strong>All products have a valid seller.</strong></p></div>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'mnu_seller_ownership_reassign' ); ?>
					<input type="hidden" name="action" value="mnu_seller_ownership_reassign" />

					<div style="margin: 1em 0; display:flex; gap:1em; align-items:center;">
						<label><strong>Assign selected to:</strong>
							<select name="seller_id" required>
								<option value="">— Choose seller —</option>
								<?php foreach ( $sellers as $sid => $label ) : ?>
									<option value="<?php echo (int) $sid; ?>"><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						<button type="submit" class="button button-primary">Reassign</button>
					</div>

					<table class="widefat striped">
						<thead>
							<tr>
								<th style="width:1%;"><input type="checkbox" onclick="jQuery('.mnu-orphan-check').prop('checked', this.checked)" /></th>
								<th>ID</th>
								<th>Title</th>
								<th>Status</th>
								<th>Reason</th>
								<th>Current author</th>
								<th>Current seller meta</th>
								<th>Actions</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $orphans as $o ) : ?>
								<tr>
									<td><input type="checkbox" class="mnu-orphan-check" name="ids[]" value="<?php echo (int) $o['id']; ?>" /></td>
									<td><?php echo (int) $o['id']; ?></td>
									<td><a href="<?php echo esc_url( admin_url( 'post.php?post=' . (int) $o['id'] . '&action=edit' ) ); ?>"><?php echo esc_html( $o['title'] ); ?></a></td>
									<td><code><?php echo esc_html( $o['status'] ); ?></code></td>
									<td><?php echo esc_html( $reason_labels[ $o['reason'] ] ?? $o['reason'] ); ?></td>
									<td>
										<?php
										if ( $o['author'] ) {
											$u = get_userdata( $o['author'] );
											echo esc_html( $u ? sprintf( '#%d %s', $o['author'], $u->user_login ) : '#' . $o['author'] );
										} else {
											echo '—';
										}
										?>
									</td>
									<td><?php echo $o['seller_id'] ? (int) $o['seller_id'] : '—'; ?></td>
									<td>
										<a class="button button-small" href="<?php echo esc_url( admin_url( 'post.php?post=' . (int) $o['id'] . '&action=edit' ) ); ?>">Edit</a>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</form>
			<?php endif; ?>

			<h2 class="title" style="margin-top:2em;">REST</h2>
			<p><code>GET <?php echo esc_html( rest_url( self::NS . '/admin/seller-ownership' ) ); ?></code></p>
			<p class="description">Returns the same orphan list. Requires <code>manage_woocommerce</code>.</p>
		</div>
		<?php
	}

	public static function handle_reassign(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Not allowed.', 403 );
		}
		check_admin_referer( 'mnu_seller_ownership_reassign' );
		$seller_id = absint( wp_unslash( $_POST['seller_id'] ?? 0 ) );
		$ids       = array_filter( array_map( 'absint', (array) ( $_POST['ids'] ?? array() ) ) );

		if ( ! $seller_id || ! self::is_seller_user( $seller_id ) ) {
			wp_safe_redirect( add_query_arg(
				array( 'page' => self::PAGE_SLUG, 'reassign_error' => 'Choose a valid marketplace seller.' ),
				admin_url( 'admin.php' )
			) );
			exit;
		}
		if ( empty( $ids ) ) {
			wp_safe_redirect( add_query_arg(
				array( 'page' => self::PAGE_SLUG, 'reassign_error' => 'Select at least one product.' ),
				admin_url( 'admin.php' )
			) );
			exit;
		}

		$done = 0;
		foreach ( $ids as $pid ) {
			$p = get_post( $pid );
			if ( ! $p || 'product' !== $p->post_type ) {
				continue;
			}
			update_post_meta( $pid, '_tnm_seller_id', $seller_id );
			update_post_meta( $pid, '_mynest_seller_id', $seller_id );
			wp_update_post( array( 'ID' => $pid, 'post_author' => $seller_id ) );
			delete_post_meta( $pid, '_mnu_orphan_owner' );
			$done++;
		}

		wp_safe_redirect( add_query_arg(
			array( 'page' => self::PAGE_SLUG, 'reassigned' => $done ),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/* ---------------- Admin notice ---------------- */

	public static function admin_notice(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$key    = 'mnu_seller_ownership_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( $notice ) {
			delete_transient( $key );
			echo '<div class="notice notice-warning is-dismissible"><p><strong>MyNest ownership guard:</strong> ' . esc_html( $notice ) . '</p></div>';
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return;
		}
		$show = 'dashboard' === $screen->id
			|| 0 === strpos( $screen->id, 'marketplace_page_' )
			|| 'toplevel_page_tnm-marketplace' === $screen->id
			|| ( isset( $screen->post_type ) && 'product' === $screen->post_type );
		if ( ! $show ) {
			return;
		}
		$count = count( self::orphan_products( 5 ) );
		if ( $count > 0 ) {
			$url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
			echo '<div class="notice notice-warning"><p><strong>MyNest seller ownership:</strong> '
				. (int) $count
				. '+ product'
				. ( 1 === $count ? '' : 's' )
				. ' need a valid seller assignment. '
				. '<a href="' . esc_url( $url ) . '">Review and reassign</a>.'
				. '</p></div>';
		}
	}

	/* ---------------- REST ---------------- */

	public static function register_routes(): void {
		register_rest_route(
			self::NS,
			'/admin/seller-ownership',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'rest_orphans' ),
				'permission_callback' => static function () {
					return current_user_can( self::CAP );
				},
			)
		);
	}

	public static function rest_orphans( WP_REST_Request $request ): WP_REST_Response {
		$limit = max( 1, min( 500, (int) $request->get_param( 'limit' ) ?: 200 ) );
		return rest_ensure_response( array(
			'count'   => count( self::orphan_products( $limit ) ),
			'orphans' => self::orphan_products( $limit ),
		) );
	}

	/* ---------------- Cron ---------------- */

	public static function run_daily_audit(): void {
		$orphans = self::orphan_products( 100 );
		if ( empty( $orphans ) ) {
			return;
		}
		$to = get_option( 'admin_email' );
		if ( ! $to ) {
			return;
		}
		$lines   = array();
		$lines[] = 'MyNest seller-ownership audit flagged ' . count( $orphans ) . ' product(s) without a valid marketplace seller.';
		$lines[] = '';
		$lines[] = 'Top 20:';
		foreach ( array_slice( $orphans, 0, 20 ) as $o ) {
			$lines[] = sprintf(
				'  #%d [%s] %s — %s',
				$o['id'],
				$o['status'],
				$o['reason'],
				get_the_title( $o['id'] )
			);
		}
		$lines[] = '';
		$lines[] = 'Review: ' . admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		wp_mail( $to, 'MyNest: product ownership drift', implode( "\n", $lines ) );
	}
}

MNU_Seller_Ownership::init();
