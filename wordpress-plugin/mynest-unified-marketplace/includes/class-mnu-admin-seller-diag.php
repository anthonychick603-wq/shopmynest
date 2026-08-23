<?php
/**
 * MNU Admin — Seller Product Diagnostic (v3.13.8+)
 *
 * Adds a WP admin page at "Marketplace ▸ Seller Diagnostic" that dumps the
 * exact state the mobile app's Your Listings screen would see for any seller.
 * We built this because sellers reported their newly-created listings
 * silently disappearing from the app — the ship-from guard was reverting
 * them to `draft` (working as intended) but the mobile Drafts tab was
 * empty even after v1.0.146 landed, and we had no way to see server-side
 * what the endpoint actually returned without their bearer token.
 *
 * This page runs everything the /seller/products REST endpoint runs, in
 * the same order, but presents the raw output on screen. Admin-only.
 *
 * @package MyNest_Unified_Marketplace
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MNU_Admin_Seller_Diag {

	private const CAP    = 'manage_woocommerce';
	private const PARENT = 'tnm-marketplace';
	private const SLUG   = 'mnu-seller-diag';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register' ), 25 );
	}

	public static function register(): void {
		add_submenu_page(
			self::PARENT,
			'Seller Diagnostic',
			'Seller Diagnostic',
			self::CAP,
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Insufficient permissions.' );
		}

		$seller_id = isset( $_GET['seller_id'] ) ? absint( $_GET['seller_id'] ) : 0;

		echo '<div class="wrap"><h1>Seller Product Diagnostic</h1>';
		echo '<p>Enter a seller user ID to see exactly what the mobile app\'s Your Listings screen would receive for that user.</p>';
		echo '<form method="get" style="margin-bottom:20px;">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"/>';
		echo '<label>Seller ID: <input type="number" name="seller_id" value="' . esc_attr( (string) $seller_id ) . '" min="1" style="width:120px;"/></label> ';
		echo '<button type="submit" class="button button-primary">Run Diagnostic</button>';
		echo '</form>';

		if ( $seller_id <= 0 ) {
			echo '<p><em>Tip: to find a seller ID, go to Users and look at the URL when editing that user.</em></p>';
			echo '</div>';
			return;
		}

		$user = get_userdata( $seller_id );
		if ( ! $user ) {
			echo '<div class="notice notice-error"><p>No user found with ID ' . esc_html( (string) $seller_id ) . '.</p></div></div>';
			return;
		}

		echo '<h2>' . esc_html( $user->display_name ) . ' (' . esc_html( $user->user_login ) . ' — ID ' . esc_html( (string) $seller_id ) . ')</h2>';
		echo '<p><strong>Roles:</strong> ' . esc_html( implode( ', ', (array) $user->roles ) ) . '</p>';

		// ---- Step 1: tnm_seller_product_ids ----
		$statuses    = array( 'publish', 'pending', 'draft', 'private' );
		$product_ids = function_exists( 'tnm_seller_product_ids' ) ? tnm_seller_product_ids( $seller_id, $statuses ) : array();

		echo '<h3>Step 1: tnm_seller_product_ids()</h3>';
		echo '<p>Statuses queried: <code>' . esc_html( implode( ', ', $statuses ) ) . '</code></p>';
		echo '<p><strong>Returned ' . esc_html( (string) count( $product_ids ) ) . ' product IDs.</strong></p>';

		// ---- Step 2: dedupe by author vs meta ----
		$by_author = get_posts( array(
			'post_type'      => 'product',
			'post_status'    => $statuses,
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'author'         => $seller_id,
			'no_found_rows'  => true,
		) );
		$by_meta_tnm = get_posts( array(
			'post_type'      => 'product',
			'post_status'    => $statuses,
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_key'       => '_tnm_seller_id',
			'meta_value'     => $seller_id,
			'no_found_rows'  => true,
		) );
		echo '<p>Products with <code>post_author = ' . esc_html( (string) $seller_id ) . '</code>: <strong>' . esc_html( (string) count( $by_author ) ) . '</strong></p>';
		echo '<p>Products with <code>_tnm_seller_id = ' . esc_html( (string) $seller_id ) . '</code>: <strong>' . esc_html( (string) count( $by_meta_tnm ) ) . '</strong></p>';

		if ( empty( $product_ids ) ) {
			echo '<div class="notice notice-warning inline"><p>No products found for this seller.</p></div></div>';
			return;
		}

		// ---- Step 3: what the WP_Query in seller_products() returns ----
		$query = new WP_Query( array(
			'post_type'      => 'product',
			'post_status'    => $statuses,
			'post__in'       => $product_ids,
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'perm'           => 'editable',
		) );

		echo '<h3>Step 2: WP_Query with perm=editable</h3>';
		echo '<p>Query found_posts: <strong>' . esc_html( (string) $query->found_posts ) . '</strong> (out of ' . esc_html( (string) count( $product_ids ) ) . ' seller IDs)</p>';

		if ( count( $product_ids ) !== (int) $query->found_posts ) {
			echo '<div class="notice notice-error inline"><p><strong>Mismatch:</strong> tnm_seller_product_ids returned ' . esc_html( (string) count( $product_ids ) ) . ' but WP_Query only found ' . esc_html( (string) $query->found_posts ) . '. Some products are being filtered out.</p></div>';
		}

		// ---- Step 4: per-product breakdown ----
		echo '<h3>Step 3: Per-product status breakdown</h3>';
		echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Title</th><th>Status</th><th>Author</th><th>_tnm_seller_id</th><th>stock_status</th><th>stock_qty</th><th>In seller_products?</th></tr></thead><tbody>';

		$found_ids = wp_list_pluck( $query->posts, 'ID' );

		foreach ( $product_ids as $pid ) {
			$post   = get_post( $pid );
			$prod   = wc_get_product( $pid );
			$meta   = get_post_meta( $pid, '_tnm_seller_id', true );
			$in     = in_array( (int) $pid, array_map( 'intval', $found_ids ), true );
			$title  = $post ? $post->post_title : '(missing post)';
			$status = $post ? $post->post_status : '?';
			$author = $post ? $post->post_author : '?';
			$sstat  = $prod ? $prod->get_stock_status() : '?';
			$sqty   = $prod ? (string) $prod->get_stock_quantity() : '?';
			$row    = '<tr>';
			$row   .= '<td><a href="' . esc_url( get_edit_post_link( $pid ) ) . '">' . esc_html( (string) $pid ) . '</a></td>';
			$row   .= '<td>' . esc_html( $title ) . '</td>';
			$row   .= '<td>' . esc_html( (string) $status ) . '</td>';
			$row   .= '<td>' . esc_html( (string) $author ) . ( (int) $author === $seller_id ? ' ✓' : '' ) . '</td>';
			$row   .= '<td>' . esc_html( (string) $meta ) . ( (int) $meta === $seller_id ? ' ✓' : '' ) . '</td>';
			$row   .= '<td>' . esc_html( $sstat ) . '</td>';
			$row   .= '<td>' . esc_html( $sqty ) . '</td>';
			$row   .= '<td>' . ( $in ? '<span style="color:green;">✓</span>' : '<span style="color:red;font-weight:bold;">MISSING</span>' ) . '</td>';
			$row   .= '</tr>';
			echo $row; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</tbody></table>';

		echo '<h3>Step 4: What the mobile app receives (sample of first 5)</h3>';
		echo '<pre style="background:#f6f7f7;padding:12px;border:1px solid #dcdcde;overflow:auto;max-height:500px;">';
		$sample = array();
		$count  = 0;
		foreach ( $query->posts as $post ) {
			if ( $count >= 5 ) {
				break;
			}
			$product = wc_get_product( $post->ID );
			if ( $product && class_exists( 'TNM_Marketplace' ) ) {
				$sample[] = TNM_Marketplace::product_to_array( $product, true );
				$count++;
			}
		}
		echo esc_html( wp_json_encode( $sample, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		echo '</pre>';

		echo '</div>';
	}
}

MNU_Admin_Seller_Diag::init();
