<?php
/**
 * Ship-from guardrail.
 *
 * Prevents a marketplace seller from publishing a product until their
 * ship-from address profile is complete. Without a complete ship-from,
 * the per-seller Shippo rate lookup returns "incomplete_ship_from" and
 * the buyer sees "No shipping options available" at checkout, which is
 * the failure mode we just fixed for one seller and want to prevent
 * from ever happening again.
 *
 * Admins and shop managers bypass this check so they can list on behalf
 * of sellers who have not yet completed their profile.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Fields on the ship-from profile that must all be non-empty for a
 * seller to be considered "ready to sell."
 *
 * @return array<int, string>
 */
function mnu_ship_from_required_fields(): array {
	return array(
		'ship_from_name',
		'ship_from_street1',
		'ship_from_city',
		'ship_from_state',
		'ship_from_zip',
		'ship_from_country',
	);
}

/**
 * True when the seller's ship-from profile is fully populated.
 */
function mnu_seller_has_complete_ship_from( int $seller_id ): bool {
	return '' === mnu_seller_ship_from_missing_field( $seller_id );
}

/**
 * v3.7.78 — return the specific first missing ship-from field for a seller,
 * or empty string when the profile is complete. Powers user-facing messages
 * ("Add your ship-from ZIP before publishing") and the admin diagnostic.
 */
function mnu_seller_ship_from_missing_field( int $seller_id ): string {
	if ( $seller_id <= 0 || ! function_exists( 'mnu_ship_get_profile' ) ) {
		return 'ship_from_profile';
	}
	$profile = mnu_ship_get_profile( $seller_id );
	foreach ( mnu_ship_from_required_fields() as $field ) {
		if ( '' === trim( (string) ( $profile[ $field ] ?? '' ) ) ) {
			return $field;
		}
	}
	return '';
}

/**
 * v3.7.78 — return the specific first missing package field for a product
 * (weight/length/width/height), or empty string when the product parcel is
 * complete. A non-custom package_size preset counts as complete for the
 * three dimensions but weight is always seller-entered.
 */
function mnu_product_parcel_missing_field( int $product_id ): string {
	if ( $product_id <= 0 || ! function_exists( 'mnu_ship_get_product_shipping' ) ) {
		return 'product_shipping_profile';
	}
	$shipping = mnu_ship_get_product_shipping( $product_id );
	$weight   = (float) ( $shipping['weight_oz'] ?? 0 );
	if ( $weight <= 0 ) {
		return 'weight_oz';
	}
	$package_size = (string) ( $shipping['package_size'] ?? 'custom' );
	if ( 'custom' === $package_size ) {
		foreach ( array( 'length_in', 'width_in', 'height_in' ) as $dim ) {
			if ( (float) ( $shipping[ $dim ] ?? 0 ) <= 0 ) {
				return $dim;
			}
		}
	}
	return '';
}

/**
 * v3.7.78 — human-readable label for a missing field so the message
 * says "Add your ship-from ZIP" not "Add your ship_from_zip".
 */
function mnu_missing_field_label( string $field ): string {
	$labels = array(
		'ship_from_name'    => 'ship-from name',
		'ship_from_street1' => 'ship-from street address',
		'ship_from_city'    => 'ship-from city',
		'ship_from_state'   => 'ship-from state',
		'ship_from_zip'     => 'ship-from ZIP',
		'ship_from_country' => 'ship-from country',
		'weight_oz'         => 'package weight (oz)',
		'length_in'         => 'package length (in)',
		'width_in'          => 'package width (in)',
		'height_in'         => 'package height (in)',
		'ship_from_profile' => 'ship-from address',
		'product_shipping_profile' => 'package details',
	);
	return $labels[ $field ] ?? $field;
}

/**
 * True for the caller that is allowed to bypass the guard.
 */
function mnu_ship_from_bypass_current_user(): bool {
	if ( function_exists( 'tnm_is_admin_or_manager' ) && tnm_is_admin_or_manager() ) {
		return true;
	}
	return current_user_can( 'manage_woocommerce' ) || current_user_can( 'edit_others_products' );
}

/**
 * Resolve the seller ID associated with a product being saved. Prefers the
 * marketplace meta the plugin sets during ownership assignment, and falls
 * back to the WordPress post_author.
 */
function mnu_ship_from_seller_for_product( int $product_id, array $postarr ): int {
	foreach ( array( '_tnm_seller_id', '_mynest_seller_id', '_wcv_vendor_id', '_dokan_vendor_id' ) as $key ) {
		$seller = (int) get_post_meta( $product_id, $key, true );
		if ( $seller > 0 ) {
			return $seller;
		}
	}
	if ( ! empty( $postarr['post_author'] ) ) {
		return (int) $postarr['post_author'];
	}
	return (int) get_post_field( 'post_author', $product_id );
}

/**
 * Stash a one-shot admin notice for a specific user.
 */
function mnu_ship_from_flash_notice( int $user_id, string $message ): void {
	if ( $user_id <= 0 || '' === $message ) {
		return;
	}
	set_transient( 'mnu_ship_from_notice_' . $user_id, $message, MINUTE_IN_SECONDS * 15 );
}

/**
 * Intercept product saves and prevent 'publish' when the seller's
 * ship-from profile is incomplete. The status is downgraded to 'draft'
 * so the record is preserved but not visible to buyers.
 *
 * @param array<string, mixed> $data
 * @param array<string, mixed> $postarr
 * @return array<string, mixed>
 */
function mnu_ship_from_guard_insert_data( array $data, array $postarr ): array {
	if ( 'product' !== ( $data['post_type'] ?? '' ) ) {
		return $data;
	}
	if ( 'publish' !== ( $data['post_status'] ?? '' ) ) {
		return $data;
	}
	if ( mnu_ship_from_bypass_current_user() ) {
		return $data;
	}

	$product_id = (int) ( $postarr['ID'] ?? 0 );
	$seller     = $product_id > 0
		? mnu_ship_from_seller_for_product( $product_id, $postarr )
		: (int) ( $postarr['post_author'] ?? get_current_user_id() );

	if ( $seller <= 0 ) {
		return $data;
	}

	$missing_from = mnu_seller_ship_from_missing_field( $seller );
	$missing_pkg  = $product_id > 0 ? mnu_product_parcel_missing_field( $product_id ) : '';
	if ( '' === $missing_from && '' === $missing_pkg ) {
		return $data;
	}

	$data['post_status'] = 'draft';
	$missing = '' !== $missing_from ? $missing_from : $missing_pkg;
	mnu_ship_from_flash_notice(
		$seller,
		sprintf(
			/* translators: %s: human-readable name of the missing shipping field */
			__( 'This product was saved as a draft because your %s is missing. Buyers cannot be quoted live shipping without it.', 'mynest-unified-marketplace' ),
			mnu_missing_field_label( $missing )
		)
	);

	return $data;
}
add_filter( 'wp_insert_post_data', 'mnu_ship_from_guard_insert_data', 20, 2 );

/**
 * Show the stashed notice on the seller's admin screens.
 */
function mnu_ship_from_admin_notice(): void {
	$user_id = get_current_user_id();
	if ( $user_id <= 0 ) {
		return;
	}
	$key     = 'mnu_ship_from_notice_' . $user_id;
	$message = get_transient( $key );
	if ( ! is_string( $message ) || '' === $message ) {
		return;
	}
	delete_transient( $key );
	echo '<div class="notice notice-warning is-dismissible"><p><strong>MyNest: </strong>' . esc_html( $message ) . '</p></div>';
}
add_action( 'admin_notices', 'mnu_ship_from_admin_notice' );

/**
 * Return an error from the mnu_ship_save_product_shipping_rest and
 * standard REST product save when a seller tries to publish without a
 * complete ship-from profile.
 *
 * WooCommerce's core REST product endpoint filters through the standard
 * wp_insert_post_data path above, which downgrades to draft — but that
 * looks silent to a REST caller expecting a 201. This filter surfaces an
 * explicit 400 before the write happens.
 */
function mnu_ship_from_guard_pre_rest_insert( mixed $prepared, WP_REST_Request $request ): mixed {
	if ( is_wp_error( $prepared ) ) {
		return $prepared;
	}
	if ( 'POST' !== $request->get_method() && 'PUT' !== $request->get_method() ) {
		return $prepared;
	}
	$status = (string) $request->get_param( 'status' );
	if ( 'publish' !== $status ) {
		return $prepared;
	}
	if ( mnu_ship_from_bypass_current_user() ) {
		return $prepared;
	}

	$product_id = (int) ( $request['id'] ?? 0 );
	$postarr    = array(
		'ID'          => $product_id,
		'post_author' => (int) ( $request->get_param( 'author' ) ?: get_current_user_id() ),
	);
	$seller = mnu_ship_from_seller_for_product( $product_id, $postarr );
	if ( $seller <= 0 ) {
		return $prepared;
	}
	$missing_from = mnu_seller_ship_from_missing_field( $seller );
	$missing_pkg  = $product_id > 0 ? mnu_product_parcel_missing_field( $product_id ) : '';
	if ( '' !== $missing_from || '' !== $missing_pkg ) {
		$missing = '' !== $missing_from ? $missing_from : $missing_pkg;
		return new WP_Error(
			'mnu_incomplete_shipping',
			sprintf(
				/* translators: %s: name of the missing shipping field */
				__( 'Add %s before publishing so buyers can be quoted live shipping.', 'mynest-unified-marketplace' ),
				mnu_missing_field_label( $missing )
			),
			array( 'status' => 400, 'missing_field' => $missing )
		);
	}
	return $prepared;
}
add_filter( 'rest_pre_insert_product', 'mnu_ship_from_guard_pre_rest_insert', 10, 2 );

/**
 * Also cover the WooCommerce Store API and the mobile app's product-write
 * routes: any code path that publishes a product ultimately runs through
 * wp_insert_post_data, which is filtered above. This second `save_post_product`
 * hook is a belt-and-suspenders for cases where post_status is written by
 * direct wp_update_post() calls that bypass the insert filter (e.g. some
 * bulk-action plugins).
 */
function mnu_ship_from_guard_save_post( int $post_id, WP_Post $post, bool $update ): void {
	if ( 'product' !== $post->post_type || 'publish' !== $post->post_status ) {
		return;
	}
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}
	if ( mnu_ship_from_bypass_current_user() ) {
		return;
	}
	$seller = mnu_ship_from_seller_for_product(
		$post_id,
		array( 'ID' => $post_id, 'post_author' => (int) $post->post_author )
	);
	if ( $seller <= 0 ) {
		return;
	}
	$missing_from = mnu_seller_ship_from_missing_field( $seller );
	$missing_pkg  = mnu_product_parcel_missing_field( $post_id );
	if ( '' === $missing_from && '' === $missing_pkg ) {
		return;
	}

	remove_action( 'save_post_product', 'mnu_ship_from_guard_save_post', 20 );
	wp_update_post(
		array(
			'ID'          => $post_id,
			'post_status' => 'draft',
		)
	);
	add_action( 'save_post_product', 'mnu_ship_from_guard_save_post', 20, 3 );

	$missing = '' !== $missing_from ? $missing_from : $missing_pkg;
	mnu_ship_from_flash_notice(
		$seller,
		sprintf(
			/* translators: %s: missing shipping field name */
			__( 'This product was reverted to draft because your %s is missing.', 'mynest-unified-marketplace' ),
			mnu_missing_field_label( $missing )
		)
	);
}
add_action( 'save_post_product', 'mnu_ship_from_guard_save_post', 20, 3 );
