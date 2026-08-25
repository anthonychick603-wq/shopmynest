<?php
/**
 * MNU_Contact_Guard
 *
 * v3.13.32 — Enforce that every buyer and every seller has an email address,
 * a phone number, and a complete shipping / ship-from address on file BEFORE
 * a purchase or a listing can happen. The existing ship-from guard and the
 * seller-readiness endpoint already covered the address side for sellers; this
 * file adds the missing email + phone requirements and extends the buyer side
 * (which had no gate at all) to the payment-intent creation path.
 *
 * Three enforcement points:
 *
 *   1. Buyer create-intent (mobile app):     rest_pre_dispatch on
 *      /the-nest/v1/checkout/create-intent — rejects with `buyer_contact_incomplete`
 *      when the account is missing an email, a phone, or the incoming shipping
 *      address is not complete (first_name, last_name, address_1, city, state,
 *      postcode, country, and a phone number reachable for shipping questions).
 *
 *   2. Seller product publish:               piggy-backs on the existing
 *      MNU_Ship_From_Guard filters — checks seller contact fields alongside the
 *      ship-from profile and returns a single actionable error message telling
 *      the seller which field to fill in.
 *
 *   3. Seller-readiness API:                 adds a `contact` step to the
 *      /seller/readiness checklist so the mobile onboarding UI shows a green
 *      checkmark once email + phone are on file.
 *
 * All checks are pure meta reads — no DB writes, no Stripe calls, no Shippo
 * calls. Admins with `manage_woocommerce` bypass everything so support can
 * still list on behalf of a seller during onboarding help sessions.
 *
 * @package MyNest_Unified_Marketplace
 * @since 3.13.32
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

final class MNU_Contact_Guard {

	/**
	 * Minimum phone length (digits only). Ten covers US NANP; the loose
	 * digit check also accepts +country prefixes trimmed of formatting.
	 */
	private const MIN_PHONE_DIGITS = 10;

	public static function init(): void {
		// v3.13.32 Fix — buyer create-intent gate. The native checkout endpoint
		// is registered inside class-mnu-native-checkout.php on rest_api_init;
		// we hook rest_pre_dispatch so we can veto BEFORE that callback runs
		// and creates a Woo order + payment intent for an unqualified buyer.
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'buyer_intent_gate' ), 10, 3 );

		// Seller-readiness augmentation: append a "contact" step to whatever
		// the readiness endpoint returns so the mobile dashboard shows the
		// same checkmarks as the underlying enforcement rules.
		add_filter( 'rest_request_after_callbacks', array( __CLASS__, 'readiness_add_contact_step' ), 10, 3 );
	}

	/* ------------------------------------------------------------------ *
	 *  Buyer side
	 * ------------------------------------------------------------------ */

	/**
	 * Reject POST /the-nest/v1/checkout/create-intent when the buyer's
	 * account is missing an email, a phone number, or when the incoming
	 * shipping address is incomplete. Runs BEFORE the endpoint callback
	 * so nothing is created (no Woo order, no PaymentIntent, no ledger).
	 *
	 * @param mixed           $result  Response override, or null to continue.
	 * @param WP_REST_Server  $server  Unused.
	 * @param WP_REST_Request $request Current request.
	 * @return mixed
	 */
	public static function buyer_intent_gate( mixed $result, WP_REST_Server $server, WP_REST_Request $request ): mixed {
		unset( $server );
		if ( $result instanceof WP_REST_Response || is_wp_error( $result ) ) {
			return $result;
		}
		if ( 'POST' !== $request->get_method() ) {
			return $result;
		}
		$route = (string) $request->get_route();
		if ( '/the-nest/v1/checkout/create-intent' !== $route ) {
			return $result;
		}
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			// Let the endpoint's own permission callback / auth check produce
			// the canonical 401. We only fill the gap for authenticated users
			// who are missing contact info.
			return $result;
		}
		// Admins (manage_woocommerce) can override, e.g. for test purchases.
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return $result;
		}
		$missing = self::buyer_missing_fields( $user_id, (array) $request->get_json_params() );
		if ( empty( $missing ) ) {
			return $result;
		}
		return new WP_Error(
			'buyer_contact_incomplete',
			self::format_buyer_message( $missing ),
			array(
				'status'         => 422,
				'missing_fields' => array_values( $missing ),
				'action_url'     => '/(tabs)/(more)/me/address-edit',
			)
		);
	}

	/**
	 * Compute the ordered list of missing buyer fields.
	 *
	 * Email + phone come from the user account (WordPress `user_email` and
	 * WooCommerce `billing_phone` meta). Shipping address fields come from
	 * the incoming create-intent payload since the mobile app always sends
	 * a full destination address there.
	 *
	 * @param int   $user_id
	 * @param array $payload
	 * @return array<int,string>
	 */
	public static function buyer_missing_fields( int $user_id, array $payload ): array {
		$missing = array();
		$user    = get_userdata( $user_id );

		// Account email.
		$email = $user ? (string) $user->user_email : '';
		if ( '' === $email || ! is_email( $email ) ) {
			$missing[] = 'account_email';
		}

		// Account phone (WooCommerce standard field). Fallback to any phone in
		// the incoming shipping address — a buyer who typed one on the address
		// form has effectively supplied one even if the account meta is stale.
		$phone   = (string) get_user_meta( $user_id, 'billing_phone', true );
		$payload_addr = self::extract_shipping_from_payload( $payload );
		if ( '' === $phone ) {
			$phone = (string) ( $payload_addr['phone'] ?? '' );
		}
		if ( ! self::has_enough_digits( $phone ) ) {
			$missing[] = 'account_phone';
		}

		// Complete shipping address (required for shippable purchases). If the
		// payload doesn't carry one, look at the customer's saved default
		// shipping address instead so a repeat buyer with a saved profile
		// isn't forced to re-type it.
		$addr = $payload_addr;
		if ( self::is_address_empty( $addr ) ) {
			$addr = self::saved_shipping_address( $user_id );
		}
		foreach ( self::address_required_fields() as $field ) {
			if ( '' === trim( (string) ( $addr[ $field ] ?? '' ) ) ) {
				$missing[] = 'shipping_' . $field;
			}
		}

		return $missing;
	}

	/**
	 * The address-form fields that must all be present for us to buy a
	 * shipping label. `phone` is the buyer's reachable number attached to the
	 * label; the carrier uses it for delivery issues.
	 *
	 * @return array<int,string>
	 */
	public static function address_required_fields(): array {
		return array( 'first_name', 'last_name', 'address_1', 'city', 'state', 'postcode', 'country', 'phone' );
	}

	private static function extract_shipping_from_payload( array $payload ): array {
		$shipping = is_array( $payload['shipping'] ?? null ) ? $payload['shipping'] : array();
		$addr     = is_array( $payload['shipping_address'] ?? null ) ? $payload['shipping_address'] : $shipping;
		return is_array( $addr ) ? $addr : array();
	}

	private static function is_address_empty( array $addr ): bool {
		foreach ( self::address_required_fields() as $field ) {
			if ( '' !== trim( (string) ( $addr[ $field ] ?? '' ) ) ) {
				return false;
			}
		}
		return true;
	}

	private static function saved_shipping_address( int $user_id ): array {
		$map = array(
			'first_name' => 'shipping_first_name',
			'last_name'  => 'shipping_last_name',
			'address_1'  => 'shipping_address_1',
			'city'       => 'shipping_city',
			'state'      => 'shipping_state',
			'postcode'   => 'shipping_postcode',
			'country'    => 'shipping_country',
			'phone'      => 'shipping_phone',
		);
		$out = array();
		foreach ( $map as $key => $meta ) {
			$val = (string) get_user_meta( $user_id, $meta, true );
			if ( 'phone' === $key && '' === $val ) {
				$val = (string) get_user_meta( $user_id, 'billing_phone', true );
			}
			$out[ $key ] = $val;
		}
		return $out;
	}

	/**
	 * Turn a list of missing keys into a friendly single-sentence message.
	 * Ordered so account issues surface first, then per-address fields.
	 */
	private static function format_buyer_message( array $missing ): string {
		$labels = self::field_labels();
		$parts  = array();
		foreach ( $missing as $key ) {
			$parts[] = $labels[ $key ] ?? $key;
		}
		$parts = array_unique( $parts );
		return sprintf(
			/* translators: %s: comma-separated list of missing fields */
			__( 'Before you can check out, please add: %s. Open Account → Shipping address to finish.', 'mynest-unified-marketplace' ),
			implode( ', ', $parts )
		);
	}

	/* ------------------------------------------------------------------ *
	 *  Seller side (piggy-backs on ship-from guard)
	 * ------------------------------------------------------------------ */

	/**
	 * v3.13.32 — public helper so the ship-from guard (and the seller
	 * readiness endpoint) can ask "is this seller reachable?" without
	 * duplicating the field list.
	 *
	 * @return string Empty when complete; otherwise the first missing
	 *                machine-readable field key ('email' | 'phone').
	 */
	public static function seller_missing_contact_field( int $seller_id ): string {
		if ( $seller_id <= 0 ) {
			return 'email';
		}
		$user  = get_userdata( $seller_id );
		$email = $user ? (string) $user->user_email : '';
		if ( '' === $email || ! is_email( $email ) ) {
			return 'email';
		}
		$phone = (string) get_user_meta( $seller_id, 'billing_phone', true );
		if ( '' === $phone ) {
			// Also accept a phone stashed on the ship-from profile since some
			// sellers only ever supply one there.
			if ( function_exists( 'mnu_ship_get_profile' ) ) {
				$profile = mnu_ship_get_profile( $seller_id );
				$phone   = (string) ( $profile['ship_from_phone'] ?? '' );
			}
		}
		if ( ! self::has_enough_digits( $phone ) ) {
			return 'phone';
		}
		return '';
	}

	public static function seller_has_contact( int $seller_id ): bool {
		return '' === self::seller_missing_contact_field( $seller_id );
	}

	/* ------------------------------------------------------------------ *
	 *  Seller readiness API augmentation
	 * ------------------------------------------------------------------ */

	/**
	 * Add a `contact` step to the readiness checklist. Runs after the
	 * canonical MNU_Seller_Readiness::build() so we don't have to modify
	 * that class — the mobile UI just sees one more step in the list.
	 */
	public static function readiness_add_contact_step( mixed $response, array $handler, WP_REST_Request $request ): mixed {
		unset( $handler );
		if ( ! ( $response instanceof WP_REST_Response ) ) {
			return $response;
		}
		if ( '/the-nest/v1/seller/readiness' !== (string) $request->get_route() ) {
			return $response;
		}
		$data = $response->get_data();
		if ( ! is_array( $data ) || ! isset( $data['steps'] ) || ! is_array( $data['steps'] ) ) {
			return $response;
		}
		$seller_id = (int) ( $data['seller_id'] ?? 0 );
		if ( $seller_id <= 0 ) {
			return $response;
		}
		$missing = self::seller_missing_contact_field( $seller_id );
		$ok      = ( '' === $missing );
		$detail  = '';
		if ( ! $ok ) {
			$detail = 'email' === $missing
				? __( 'Missing account email.', 'mynest-unified-marketplace' )
				: __( 'Missing phone number.', 'mynest-unified-marketplace' );
		}

		// Insert as the first step so a brand-new seller sees "add contact"
		// before "add bank" / "add ship-from" (which both depend on being
		// reachable anyway).
		array_unshift(
			$data['steps'],
			array(
				'key'          => 'contact',
				'label'        => __( 'Add email and phone number', 'mynest-unified-marketplace' ),
				'description'  => __( 'ShopMyNest emails you order and payout notifications; the phone number is what carriers use if a shipment needs attention.', 'mynest-unified-marketplace' ),
				'ok'           => $ok,
				'blocking'     => true,
				'action_url'   => '/(tabs)/(more)/me/address-edit',
				'action_label' => $ok ? __( 'Edit', 'mynest-unified-marketplace' ) : __( 'Add contact', 'mynest-unified-marketplace' ),
				'detail'       => $detail,
			)
		);

		// Recompute completed / total / ready_to_sell for the new step.
		$completed = 0;
		$blocking_incomplete = false;
		foreach ( $data['steps'] as $step ) {
			if ( ! empty( $step['ok'] ) ) {
				$completed++;
			}
			if ( ! empty( $step['blocking'] ) && empty( $step['ok'] ) ) {
				$blocking_incomplete = true;
			}
		}
		$data['completed']     = $completed;
		$data['total']         = count( $data['steps'] );
		$data['ready_to_sell'] = ! $blocking_incomplete;

		$response->set_data( $data );
		return $response;
	}

	/* ------------------------------------------------------------------ *
	 *  Utilities
	 * ------------------------------------------------------------------ */

	public static function has_enough_digits( string $raw ): bool {
		$digits = preg_replace( '/\D+/', '', $raw ) ?? '';
		return strlen( (string) $digits ) >= self::MIN_PHONE_DIGITS;
	}

	/**
	 * Machine-key → human-facing label map. Kept small on purpose — the mobile
	 * app also uses the machine keys (`missing_fields[]` array in the error
	 * response) to drive per-field UI, so the labels here are only for the
	 * fallback plain-language message.
	 *
	 * @return array<string,string>
	 */
	public static function field_labels(): array {
		return array(
			'account_email'      => __( 'an account email', 'mynest-unified-marketplace' ),
			'account_phone'      => __( 'a phone number', 'mynest-unified-marketplace' ),
			'shipping_first_name'=> __( 'shipping first name', 'mynest-unified-marketplace' ),
			'shipping_last_name' => __( 'shipping last name', 'mynest-unified-marketplace' ),
			'shipping_address_1' => __( 'shipping street address', 'mynest-unified-marketplace' ),
			'shipping_city'      => __( 'shipping city', 'mynest-unified-marketplace' ),
			'shipping_state'     => __( 'shipping state', 'mynest-unified-marketplace' ),
			'shipping_postcode'  => __( 'shipping ZIP', 'mynest-unified-marketplace' ),
			'shipping_country'   => __( 'shipping country', 'mynest-unified-marketplace' ),
			'shipping_phone'     => __( 'a phone number for delivery', 'mynest-unified-marketplace' ),
		);
	}
}

MNU_Contact_Guard::init();
