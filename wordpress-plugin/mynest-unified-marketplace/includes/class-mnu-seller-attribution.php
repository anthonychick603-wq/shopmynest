<?php
/**
 * Seller attribution diagnostics & backfill.
 *
 * Item 2 of the Top 25 polish list: make sure every product carries an
 * unambiguous _tnm_seller_id BEFORE it can appear on an order, and give ops
 * an admin surface to spot orders where seller resolution collapsed on the
 * checkout path.
 *
 * @package MyNest_Unified_Marketplace
 * @since 3.7.56
 */

defined( 'ABSPATH' ) || exit;

final class MNU_Seller_Attribution {

	private const MENU_PARENT = 'tnm-marketplace';
	private const MENU_SLUG   = 'mnu-seller-attribution';
	private const CAP         = 'manage_woocommerce';
	private const NONCE_KEY   = 'mnu_seller_attribution_action';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 20 );
		add_action( 'admin_post_mnu_seller_attribution_backfill', array( __CLASS__, 'handle_backfill' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest' ) );
	}

	/**
	 * Admin-only REST endpoint that returns everything we know about an order
	 * from the seller-attribution point of view: line stamping, seller-transfer
	 * order meta, ledger rows, and any collapsed-resolution flags. Used from
	 * the two-seller verification flow.
	 */
	public static function register_rest(): void {
		register_rest_route(
			'mnu/v1',
			'/admin/ledger',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_ledger' ),
				'permission_callback' => static function () {
					return current_user_can( self::CAP );
				},
				'args'                => array(
					'order_id' => array( 'required' => true, 'type' => 'integer' ),
				),
			)
		);

		register_rest_route(
			'mnu/v1',
			'/admin/shippo_state',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_shippo_state' ),
				'permission_callback' => static function () {
					return current_user_can( self::CAP );
				},
			)
		);
	}

	/**
	 * Diagnostic: report what mnu_labels_settings() currently sees for the
	 * Shippo token, without echoing the token itself.
	 */
	public static function rest_shippo_state( WP_REST_Request $request ): WP_REST_Response {
		$raw_labels_option = (array) get_option( 'thenest_shipping_labels_settings', array() );
		$raw_legacy_token  = (string) get_option( 'thenest_shippo_api_token', '' );

		$settings = function_exists( 'mnu_labels_settings' ) ? mnu_labels_settings() : array();
		$token    = (string) ( $settings['shippo_token'] ?? '' );

		return rest_ensure_response(
			array(
				'has_settings_option'   => ! empty( $raw_labels_option ),
				'settings_option_keys'  => array_keys( $raw_labels_option ),
				'settings_has_token'    => ! empty( $raw_labels_option['shippo_token'] ),
				'legacy_token_present'  => '' !== $raw_legacy_token,
				'legacy_token_length'   => strlen( $raw_legacy_token ),
				'legacy_token_prefix'   => '' !== $raw_legacy_token ? substr( $raw_legacy_token, 0, 12 ) : '',
				'resolved_token_length' => strlen( $token ),
				'resolved_token_prefix' => '' !== $token ? substr( $token, 0, 12 ) : '',
				'resolved_test_mode'    => (int) ( $settings['test_mode'] ?? 0 ),
				'labels_settings_fn'    => function_exists( 'mnu_labels_settings' ),
			)
		);
	}

	public static function rest_ledger( WP_REST_Request $req ): WP_REST_Response {
		global $wpdb;
		$order_id = (int) $req->get_param( 'order_id' );
		$out      = array( 'order_id' => $order_id );

		$table = function_exists( 'tnm_table' ) ? tnm_table( 'ledger' ) : $wpdb->prefix . 'tnm_ledger';
		$rows  = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . $table . ' WHERE order_id = %d ORDER BY id ASC', $order_id ),
			ARRAY_A
		);
		$out['ledger_rows'] = $rows ?: array();

		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( $order ) {
			$transfers_raw       = $order->get_meta( '_mnu_seller_transfers', true );
			$out['transfers']    = is_string( $transfers_raw ) ? json_decode( $transfers_raw, true ) : $transfers_raw;
			$out['seller_ids']   = $order->get_meta( '_tnm_seller_ids', true );
			$out['collapsed']    = (bool) $order->get_meta( '_tnm_seller_resolution_collapsed', true );
			$out['stripe_pi']    = $order->get_meta( '_thenest_stripe_payment_intent', true );
			$out['status']       = $order->get_status();
			$out['total']        = $order->get_total();
			$out['line_items']   = array();
			foreach ( $order->get_items() as $item_id => $item ) {
				$out['line_items'][] = array(
					'item_id'       => $item_id,
					'product_id'    => $item->get_product_id(),
					'seller_id'     => (int) $item->get_meta( '_tnm_seller_id', true ),
					'store_name'    => $item->get_meta( '_tnm_store_name', true ),
					'subtotal'      => $item->get_subtotal(),
					'total'         => $item->get_total(),
					'fee_percent'   => $item->get_meta( '_tnm_fee_percent', true ),
					'platform_fee'  => $item->get_meta( '_tnm_platform_fee', true ),
					'net_before_sh' => $item->get_meta( '_tnm_seller_net_before_shipping', true ),
				);
			}
		}

		return new WP_REST_Response( $out, 200 );
	}

	public static function register_menu(): void {
		add_submenu_page(
			self::MENU_PARENT,
			'Seller Attribution',
			'Seller Attribution',
			self::CAP,
			self::MENU_SLUG,
			array( __CLASS__, 'render_screen' )
		);
	}

	/**
	 * Return counters describing seller-attribution coverage across the
	 * product catalog. All queries hit wp_postmeta directly so no cached
	 * WC_Product objects can hide drift.
	 *
	 * @return array<string,int>
	 */
	public static function catalog_stats(): array {
		global $wpdb;
		$stats = array(
			'total_products'         => 0,
			'products_with_meta'     => 0,
			'products_no_meta'       => 0,
			'products_author_only'   => 0,
			'products_orphaned'      => 0,
		);
		$stats['total_products'] = (int) $wpdb->get_var(
			"SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type='product' AND post_status IN ('publish','private','draft','pending')"
		);
		if ( 0 === $stats['total_products'] ) {
			return $stats;
		}
		$stats['products_with_meta'] = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT p.ID)
			 FROM {$wpdb->posts} p
			 JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
			 WHERE p.post_type='product'
			   AND p.post_status IN ('publish','private','draft','pending')
			   AND pm.meta_key IN ('_tnm_seller_id','_mynest_seller_id','_wcv_vendor_id','_dokan_vendor_id')
			   AND pm.meta_value > 0"
		);
		$stats['products_no_meta']     = $stats['total_products'] - $stats['products_with_meta'];
		$stats['products_author_only'] = (int) $wpdb->get_var(
			"SELECT COUNT(p.ID)
			 FROM {$wpdb->posts} p
			 WHERE p.post_type='product'
			   AND p.post_status IN ('publish','private','draft','pending')
			   AND p.post_author > 0
			   AND NOT EXISTS (
				SELECT 1 FROM {$wpdb->postmeta} pm
				WHERE pm.post_id = p.ID
				  AND pm.meta_key IN ('_tnm_seller_id','_mynest_seller_id','_wcv_vendor_id','_dokan_vendor_id')
				  AND pm.meta_value > 0
			   )"
		);
		$stats['products_orphaned'] = (int) $wpdb->get_var(
			"SELECT COUNT(p.ID)
			 FROM {$wpdb->posts} p
			 WHERE p.post_type='product'
			   AND p.post_status IN ('publish','private','draft','pending')
			   AND ( p.post_author = 0 OR p.post_author IS NULL )
			   AND NOT EXISTS (
				SELECT 1 FROM {$wpdb->postmeta} pm
				WHERE pm.post_id = p.ID
				  AND pm.meta_key IN ('_tnm_seller_id','_mynest_seller_id','_wcv_vendor_id','_dokan_vendor_id')
				  AND pm.meta_value > 0
			   )"
		);
		return $stats;
	}

	/**
	 * Return the product ids that would be touched by backfill: those
	 * without a seller meta but with a valid post_author.
	 *
	 * @param int $limit
	 * @return array<int,array<string,int>>
	 */
	public static function backfill_candidates( int $limit = 500 ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID AS id, p.post_author AS author, p.post_title AS title
				 FROM {$wpdb->posts} p
				 WHERE p.post_type='product'
				   AND p.post_status IN ('publish','private','draft','pending')
				   AND p.post_author > 0
				   AND NOT EXISTS (
					SELECT 1 FROM {$wpdb->postmeta} pm
					WHERE pm.post_id = p.ID
					  AND pm.meta_key IN ('_tnm_seller_id','_mynest_seller_id','_wcv_vendor_id','_dokan_vendor_id')
					  AND pm.meta_value > 0
				   )
				 ORDER BY p.ID ASC
				 LIMIT %d",
				$limit
			),
			ARRAY_A
		);
		return $rows ?: array();
	}

	/**
	 * Return orders where the checkout stamping detected seller-resolution
	 * collapse (see TNM_Marketplace::stamp_order_sellers()).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function collapsed_orders( int $limit = 50 ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.post_id AS order_id, p.post_date AS created, p.post_status AS status
				 FROM {$wpdb->postmeta} pm
				 JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = '_tnm_seller_resolution_collapsed'
				   AND pm.meta_value = '1'
				 ORDER BY p.post_date DESC
				 LIMIT %d",
				$limit
			),
			ARRAY_A
		);
		return $rows ?: array();
	}

	/**
	 * Backfill missing _tnm_seller_id from post_author for every eligible
	 * product. Called from the admin screen behind a capability check and
	 * a nonce.
	 *
	 * @return int Number of products stamped.
	 */
	public static function run_backfill(): int {
		$candidates = self::backfill_candidates( 2000 );
		$stamped    = 0;
		foreach ( $candidates as $row ) {
			$pid    = (int) $row['id'];
			$author = (int) $row['author'];
			if ( $pid <= 0 || $author <= 0 ) {
				continue;
			}
			if ( update_post_meta( $pid, '_tnm_seller_id', $author ) ) {
				update_post_meta( $pid, '_mynest_seller_id', $author );
				$stamped++;
			}
		}
		return $stamped;
	}

	public static function handle_backfill(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Not allowed.', 403 );
		}
		check_admin_referer( self::NONCE_KEY );
		$stamped = self::run_backfill();
		set_transient(
			'mnu_seller_attribution_notice_' . get_current_user_id(),
			array(
				'type'    => 'success',
				'message' => sprintf( 'Stamped _tnm_seller_id on %d product(s) from post_author.', $stamped ),
			),
			60
		);
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
		exit;
	}

	public static function render_screen(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Not allowed.', 403 );
		}
		$stats      = self::catalog_stats();
		$candidates = self::backfill_candidates( 25 );
		$collapsed  = self::collapsed_orders( 25 );
		$notice     = get_transient( 'mnu_seller_attribution_notice_' . get_current_user_id() );
		if ( is_array( $notice ) ) {
			delete_transient( 'mnu_seller_attribution_notice_' . get_current_user_id() );
		}
		?>
		<div class="wrap">
			<h1>Seller Attribution</h1>
			<p style="max-width:780px;">
				Coverage and drift for <code>_tnm_seller_id</code> across the product catalog.
				Backfill stamps <code>_tnm_seller_id</code> and <code>_mynest_seller_id</code>
				from <code>post_author</code> so nothing can fall through to the
				checkout-time <code>post_author</code> fallback and end up on the wrong seller
				after a user swap.
			</p>

			<?php if ( is_array( $notice ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible">
					<p><?php echo esc_html( $notice['message'] ); ?></p>
				</div>
			<?php endif; ?>

			<h2>Catalog coverage</h2>
			<table class="widefat striped" style="max-width:640px;">
				<tbody>
					<tr><th>Total products</th><td><?php echo (int) $stats['total_products']; ?></td></tr>
					<tr><th>Products with a seller meta</th><td><?php echo (int) $stats['products_with_meta']; ?></td></tr>
					<tr><th>Products relying on <code>post_author</code> only</th><td><?php echo (int) $stats['products_author_only']; ?></td></tr>
					<tr><th>Orphaned products (no meta, no author)</th><td><?php echo (int) $stats['products_orphaned']; ?></td></tr>
				</tbody>
			</table>

			<?php if ( $candidates ) : ?>
				<h2>Backfill candidates (first <?php echo count( $candidates ); ?>)</h2>
				<p>These products would be stamped <code>_tnm_seller_id = post_author</code>.</p>
				<table class="widefat striped">
					<thead><tr><th>Product ID</th><th>Title</th><th>post_author</th></tr></thead>
					<tbody>
					<?php foreach ( $candidates as $row ) : ?>
						<tr>
							<td>#<?php echo (int) $row['id']; ?></td>
							<td><?php echo esc_html( $row['title'] ?: '(no title)' ); ?></td>
							<td><?php echo (int) $row['author']; ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:16px;">
					<?php wp_nonce_field( self::NONCE_KEY ); ?>
					<input type="hidden" name="action" value="mnu_seller_attribution_backfill" />
					<button type="submit" class="button button-primary">Backfill <?php echo (int) $stats['products_author_only']; ?> product(s) now</button>
				</form>
			<?php else : ?>
				<h2>Catalog is clean</h2>
				<p>Every eligible product already carries an explicit seller meta.</p>
			<?php endif; ?>

			<h2 style="margin-top:32px;">Recent orders with collapsed seller resolution</h2>
			<p>Orders flagged with <code>_tnm_seller_resolution_collapsed = 1</code>
			   at checkout stamping time. Empty is what you want.</p>
			<?php if ( $collapsed ) : ?>
				<table class="widefat striped" style="max-width:720px;">
					<thead><tr><th>Order</th><th>Status</th><th>Created</th></tr></thead>
					<tbody>
					<?php foreach ( $collapsed as $row ) : ?>
						<tr>
							<td><a href="<?php echo esc_url( admin_url( 'post.php?post=' . (int) $row['order_id'] . '&action=edit' ) ); ?>">#<?php echo (int) $row['order_id']; ?></a></td>
							<td><?php echo esc_html( $row['status'] ); ?></td>
							<td><?php echo esc_html( $row['created'] ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p><em>No collapsed-resolution orders on record.</em></p>
			<?php endif; ?>
		</div>
		<?php
	}
}

MNU_Seller_Attribution::init();
