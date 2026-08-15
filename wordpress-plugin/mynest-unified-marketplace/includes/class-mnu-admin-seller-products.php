<?php
/**
 * MNU Admin — Add Product for a Seller.
 *
 * Adds a wp-admin screen under Marketplace ▸ "Add Seller Product" that lets
 * an admin quickly create a WooCommerce product on behalf of a specific
 * marketplace seller. Ownership is assigned in the two places the plugin
 * cares about:
 *
 *   post_author  = the seller's user ID (matches WordPress content
 *                  ownership model + Woo's default vendor lookup)
 *   _tnm_seller_id meta = the seller's user ID (matches the meta the rest
 *                  of the marketplace plugin uses for order-time
 *                  attribution, ledger rows, Connect transfers, and
 *                  shipping label generation)
 *
 * Products always start with:
 *   - status = draft (admin reviews on Woo's edit screen before publishing)
 *   - stock_status = outofstock, manage_stock = yes, stock_quantity = 0
 *     (respects the "no inadvertent sales" invariant)
 *
 * @package MyNest_Unified_Marketplace
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MNU_Admin_Seller_Products {

	private const CAP        = 'manage_woocommerce';
	private const NONCE_KEY  = 'mnu_add_seller_product';
	private const PARENT     = 'tnm-marketplace';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 25 );
		add_action( 'admin_post_mnu_add_seller_product', array( __CLASS__, 'handle_create' ) );
	}

	public static function register_menu(): void {
		add_submenu_page(
			self::PARENT,
			'Add Product for Seller',
			'Add Seller Product',
			self::CAP,
			'mnu-add-seller-product',
			array( __CLASS__, 'screen' )
		);
	}

	/**
	 * Returns all users that have either the tnm_seller or mynest_seller role.
	 *
	 * @return WP_User[]
	 */
	public static function get_sellers(): array {
		$results = array();
		foreach ( array( 'tnm_seller', 'mynest_seller' ) as $role ) {
			$users = get_users(
				array(
					'role'    => $role,
					'orderby' => 'display_name',
					'order'   => 'ASC',
					'fields'  => array( 'ID', 'display_name', 'user_login', 'user_email' ),
				)
			);
			foreach ( $users as $u ) {
				$results[ (int) $u->ID ] = $u;
			}
		}
		ksort( $results );
		return array_values( $results );
	}

	public static function screen(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Insufficient permission.' );
		}
		if ( ! class_exists( 'WC_Product_Simple' ) ) {
			echo '<div class="wrap"><h1>Add Product for Seller</h1><div class="notice notice-error"><p>WooCommerce is required for this screen.</p></div></div>';
			return;
		}
		$sellers = self::get_sellers();

		$notice = get_transient( 'mnu_add_product_notice_' . get_current_user_id() );
		if ( $notice ) {
			delete_transient( 'mnu_add_product_notice_' . get_current_user_id() );
		}

		$preselect = isset( $_GET['seller_id'] ) ? (int) $_GET['seller_id'] : 0;
		$categories = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);
		if ( is_wp_error( $categories ) ) {
			$categories = array();
		}
		?>
		<div class="wrap">
			<h1>Add Product for Seller</h1>
			<p class="description">Creates a new WooCommerce product assigned to the chosen seller. The product starts as a <strong>draft</strong> with <strong>stock 0</strong>; you can finish editing on WooCommerce's normal product screen afterward.</p>

			<?php if ( $notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( (string) ( $notice['type'] ?? 'success' ) ); ?> is-dismissible">
					<p><?php echo wp_kses_post( (string) ( $notice['message'] ?? '' ) ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( ! $sellers ) : ?>
				<div class="notice notice-warning"><p>No users with the <code>tnm_seller</code> or <code>mynest_seller</code> role were found. Approve a seller application first, then come back.</p></div>
				<?php return; ?>
			<?php endif; ?>

			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="mnu_add_seller_product">
				<?php wp_nonce_field( self::NONCE_KEY ); ?>

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="seller_id">Seller</label></th>
							<td>
								<select id="seller_id" name="seller_id" required style="min-width:340px">
									<option value="">— pick a seller —</option>
									<?php foreach ( $sellers as $s ) : ?>
										<option value="<?php echo (int) $s->ID; ?>" <?php selected( $preselect, (int) $s->ID ); ?>>
											<?php echo esc_html( $s->display_name ?: $s->user_login ); ?> — <?php echo esc_html( $s->user_email ); ?> (#<?php echo (int) $s->ID; ?>)
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description">Only users with the <code>tnm_seller</code> or <code>mynest_seller</code> role appear here.</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="product_name">Product name</label></th>
							<td><input type="text" id="product_name" name="product_name" class="regular-text" required maxlength="200"></td>
						</tr>
						<tr>
							<th scope="row"><label for="regular_price">Regular price (USD)</label></th>
							<td>
								<input type="number" id="regular_price" name="regular_price" step="0.01" min="0" required class="small-text">
								&nbsp;&nbsp;
								<label for="sale_price">Sale price (optional)</label>
								<input type="number" id="sale_price" name="sale_price" step="0.01" min="0" class="small-text">
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="stock_quantity">Stock quantity</label></th>
							<td>
								<input type="number" id="stock_quantity" name="stock_quantity" min="0" value="0" required class="small-text">
								<p class="description">Defaults to <strong>0</strong> so the product cannot sell until you confirm. Change it here or on the product edit screen.</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="sku">SKU (optional)</label></th>
							<td><input type="text" id="sku" name="sku" class="regular-text" maxlength="100"></td>
						</tr>
						<tr>
							<th scope="row"><label for="short_description">Short description</label></th>
							<td><textarea id="short_description" name="short_description" rows="3" class="large-text"></textarea></td>
						</tr>
						<tr>
							<th scope="row"><label for="description">Full description</label></th>
							<td><textarea id="description" name="description" rows="6" class="large-text"></textarea></td>
						</tr>
						<tr>
							<th scope="row">Categories</th>
							<td>
								<?php if ( $categories ) : ?>
									<div style="max-height:180px;overflow:auto;border:1px solid #dcdcde;padding:8px;background:#fff;max-width:400px">
										<?php foreach ( $categories as $cat ) : ?>
											<label style="display:block"><input type="checkbox" name="product_cat[]" value="<?php echo (int) $cat->term_id; ?>"> <?php echo esc_html( $cat->name ); ?></label>
										<?php endforeach; ?>
									</div>
								<?php else : ?>
									<em>No product categories exist yet.</em>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="product_image">Featured image</label></th>
							<td>
								<input type="file" id="product_image" name="product_image" accept="image/*">
								<p class="description">JPEG/PNG/WebP. Uploaded to the Media Library and set as featured image.</p>
							</td>
						</tr>
						<tr>
							<th scope="row">Shipping (Shippo)</th>
							<td>
								<label>Weight (lbs) <input type="number" name="weight" step="0.01" min="0" class="small-text"></label>
								&nbsp;
								<label>L <input type="number" name="length" step="0.1" min="0" class="small-text"></label>
								<label>W <input type="number" name="width" step="0.1" min="0" class="small-text"></label>
								<label>H <input type="number" name="height" step="0.1" min="0" class="small-text"></label>
								<span class="description">(inches)</span>
							</td>
						</tr>
					</tbody>
				</table>

				<?php submit_button( 'Create draft product', 'primary', 'submit', true ); ?>
			</form>
		</div>
		<?php
	}

	public static function handle_create(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Insufficient permission.' );
		}
		check_admin_referer( self::NONCE_KEY );
		if ( ! class_exists( 'WC_Product_Simple' ) ) {
			self::notice( 'error', 'WooCommerce is not active.' );
			self::redirect_back();
		}

		$seller_id = isset( $_POST['seller_id'] ) ? (int) $_POST['seller_id'] : 0;
		$seller    = $seller_id ? get_userdata( $seller_id ) : null;
		if ( ! $seller || ( ! in_array( 'tnm_seller', (array) $seller->roles, true ) && ! in_array( 'mynest_seller', (array) $seller->roles, true ) ) ) {
			self::notice( 'error', 'The selected user is not a seller.' );
			self::redirect_back( $seller_id );
		}

		$name = isset( $_POST['product_name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['product_name'] ) ) : '';
		if ( '' === $name ) {
			self::notice( 'error', 'Product name is required.' );
			self::redirect_back( $seller_id );
		}

		$regular_price = isset( $_POST['regular_price'] ) ? (float) $_POST['regular_price'] : 0.0;
		if ( $regular_price <= 0 ) {
			self::notice( 'error', 'Regular price must be greater than zero.' );
			self::redirect_back( $seller_id );
		}
		$sale_price_raw = isset( $_POST['sale_price'] ) ? trim( (string) $_POST['sale_price'] ) : '';
		$sale_price     = $sale_price_raw !== '' ? (float) $sale_price_raw : null;
		if ( null !== $sale_price && ( $sale_price <= 0 || $sale_price >= $regular_price ) ) {
			self::notice( 'error', 'Sale price must be greater than zero and less than the regular price.' );
			self::redirect_back( $seller_id );
		}

		$stock             = isset( $_POST['stock_quantity'] ) ? max( 0, (int) $_POST['stock_quantity'] ) : 0;
		$sku               = isset( $_POST['sku'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['sku'] ) ) : '';
		$short_description = isset( $_POST['short_description'] ) ? wp_kses_post( wp_unslash( (string) $_POST['short_description'] ) ) : '';
		$description       = isset( $_POST['description'] ) ? wp_kses_post( wp_unslash( (string) $_POST['description'] ) ) : '';
		$cats              = isset( $_POST['product_cat'] ) && is_array( $_POST['product_cat'] ) ? array_map( 'absint', $_POST['product_cat'] ) : array();
		$weight            = isset( $_POST['weight'] ) && '' !== $_POST['weight'] ? (string) (float) $_POST['weight'] : '';
		$length            = isset( $_POST['length'] ) && '' !== $_POST['length'] ? (string) (float) $_POST['length'] : '';
		$width             = isset( $_POST['width'] ) && '' !== $_POST['width'] ? (string) (float) $_POST['width'] : '';
		$height            = isset( $_POST['height'] ) && '' !== $_POST['height'] ? (string) (float) $_POST['height'] : '';

		// -------------------------------------------------------------
		// Build product
		// -------------------------------------------------------------
		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_status( 'draft' );
		$product->set_regular_price( (string) $regular_price );
		if ( null !== $sale_price ) {
			$product->set_sale_price( (string) $sale_price );
		}
		$product->set_manage_stock( true );
		$product->set_stock_quantity( $stock );
		$product->set_stock_status( $stock > 0 ? 'instock' : 'outofstock' );
		if ( '' !== $sku ) {
			$product->set_sku( $sku );
		}
		if ( '' !== $short_description ) {
			$product->set_short_description( $short_description );
		}
		if ( '' !== $description ) {
			$product->set_description( $description );
		}
		if ( '' !== $weight ) {
			$product->set_weight( $weight );
		}
		if ( '' !== $length ) {
			$product->set_length( $length );
		}
		if ( '' !== $width ) {
			$product->set_width( $width );
		}
		if ( '' !== $height ) {
			$product->set_height( $height );
		}
		if ( $cats ) {
			$product->set_category_ids( $cats );
		}
		$product->update_meta_data( '_tnm_seller_id', $seller_id );
		$product->update_meta_data( '_mynest_seller_id', $seller_id );

		$product_id = $product->save();

		if ( is_wp_error( $product_id ) || ! $product_id ) {
			$msg = is_wp_error( $product_id ) ? $product_id->get_error_message() : 'Unknown error.';
			self::notice( 'error', 'Failed to create product: ' . esc_html( $msg ) );
			self::redirect_back( $seller_id );
		}

		// Assign post_author to the seller — needed for the marketplace's
		// author-based ownership fallback and for /author/<slug> shopfronts.
		wp_update_post(
			array(
				'ID'          => $product_id,
				'post_author' => $seller_id,
			)
		);

		// Optional featured image
		if ( ! empty( $_FILES['product_image']['name'] ) && (int) $_FILES['product_image']['size'] > 0 ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			$attach_id = media_handle_upload( 'product_image', $product_id );
			if ( is_wp_error( $attach_id ) ) {
				self::notice(
					'warning',
					sprintf(
						'Product created (#%d) but image upload failed: %s',
						$product_id,
						esc_html( $attach_id->get_error_message() )
					)
				);
				self::redirect_back( $seller_id );
			}
			set_post_thumbnail( $product_id, $attach_id );
			// Attach image to the seller as owner too.
			wp_update_post(
				array(
					'ID'          => $attach_id,
					'post_author' => $seller_id,
				)
			);
		}

		$edit_url = admin_url( 'post.php?post=' . $product_id . '&action=edit' );
		$view_url = get_permalink( $product_id );
		self::notice(
			'success',
			sprintf(
				'Draft product created for <strong>%s</strong>: <a href="%s">edit #%d</a>%s',
				esc_html( $seller->display_name ?: $seller->user_login ),
				esc_url( $edit_url ),
				(int) $product_id,
				$view_url ? ' · <a href="' . esc_url( $view_url ) . '" target="_blank" rel="noopener">preview</a>' : ''
			)
		);
		self::redirect_back( $seller_id );
	}

	private static function notice( string $type, string $message ): void {
		set_transient(
			'mnu_add_product_notice_' . get_current_user_id(),
			array( 'type' => $type, 'message' => $message ),
			120
		);
	}

	private static function redirect_back( int $seller_id = 0 ): void {
		$args = array( 'page' => 'mnu-add-seller-product' );
		if ( $seller_id > 0 ) {
			$args['seller_id'] = $seller_id;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
