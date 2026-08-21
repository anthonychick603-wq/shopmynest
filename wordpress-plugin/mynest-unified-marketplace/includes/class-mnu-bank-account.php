<?php
/**
 * MNU_Bank_Account
 *
 * Seller-side bank account storage under the v3.8.0 money model. Sellers
 * are no longer on Stripe Connect; the platform pays them via manual ACH
 * from a business checking account after the 7-day holding window. Each
 * seller supplies:
 *
 *   - Account holder name
 *   - 9-digit ABA routing number  (format-checked, no checksum in v3.8.0)
 *   - 4-17 digit account number   (format-checked)
 *
 * Format-only validation is intentional (per product decision) — a
 * checksum-passing routing number is not proof the seller entered THEIR
 * routing number, and micro-deposit verification is Phase 3+. Sellers
 * can freely edit their info at any time; the change takes effect on
 * the next payout batch.
 *
 * Storage is encrypted at rest with libsodium's XChaCha20-Poly1305 AEAD.
 * The key is derived once from WordPress's own AUTH_KEY / SECURE_AUTH_KEY
 * pair, so a database dump alone leaks nothing: an attacker also needs
 * wp-config.php. The last 4 digits of the account number are stored in
 * PLAINTEXT for the "on file" masked display; that is the only value ever
 * returned by the REST GET.
 *
 * REST endpoints (namespace the-nest/v1):
 *
 *   GET  /seller/bank-account  -> has_bank, last4?, holder_name?, updated_at?
 *   POST /seller/bank-account  -> { holder_name, routing_number, account_number }
 *
 * @package MyNest_Unified_Marketplace
 * @since 3.8.1
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MNU_Bank_Account {

	public const NS               = 'the-nest/v1';
	public const META_CIPHERTEXT  = '_mnu_bank_ciphertext';
	public const META_NONCE       = '_mnu_bank_nonce';
	public const META_LAST4       = '_mnu_bank_last4';
	public const META_HOLDER      = '_mnu_bank_holder_name';
	public const META_UPDATED     = '_mnu_bank_updated_at';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route(
			self::NS,
			'/seller/bank-account',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'rest_get' ),
					'permission_callback' => static function () {
						return is_user_logged_in();
					},
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'rest_save' ),
					'permission_callback' => static function () {
						return is_user_logged_in();
					},
				),
			)
		);
	}

	/**
	 * Return a masked summary of the on-file bank account. Never returns
	 * the routing number, and never returns more than the last 4 of the
	 * account number. Safe to log.
	 */
	public static function rest_get( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$seller_id = self::resolve_seller_id( $request );
		if ( is_wp_error( $seller_id ) ) {
			return $seller_id;
		}
		$last4      = (string) get_user_meta( $seller_id, self::META_LAST4, true );
		$holder     = (string) get_user_meta( $seller_id, self::META_HOLDER, true );
		$updated_at = (string) get_user_meta( $seller_id, self::META_UPDATED, true );
		$has_bank   = '' !== $last4;
		return rest_ensure_response(
			array(
				'has_bank'    => $has_bank,
				'last4'       => $has_bank ? $last4 : '',
				'holder_name' => $has_bank ? $holder : '',
				'updated_at'  => $has_bank ? $updated_at : '',
			)
		);
	}

	public static function rest_save( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$seller_id = self::resolve_seller_id( $request );
		if ( is_wp_error( $seller_id ) ) {
			return $seller_id;
		}

		if ( ! self::is_libsodium_available() ) {
			return new WP_Error(
				'crypto_unavailable',
				'This server is missing libsodium. Ask an admin to enable the PHP sodium extension before saving bank details.',
				array( 'status' => 500 )
			);
		}

		$data           = (array) $request->get_json_params();
		$holder_name    = self::sanitize_holder( (string) ( $data['holder_name']    ?? '' ) );
		$routing_number = self::digits_only( (string) ( $data['routing_number'] ?? '' ) );
		$account_number = self::digits_only( (string) ( $data['account_number'] ?? '' ) );

		$missing = array();
		if ( '' === $holder_name )    { $missing[] = 'holder_name'; }
		if ( strlen( $routing_number ) !== 9 ) { $missing[] = 'routing_number'; }
		if ( strlen( $account_number ) < 4 || strlen( $account_number ) > 17 ) { $missing[] = 'account_number'; }
		if ( ! empty( $missing ) ) {
			return new WP_Error(
				'invalid_bank',
				'Please double-check your bank details: ' . implode( ', ', $missing ) . '.',
				array( 'status' => 422, 'missing' => $missing )
			);
		}

		$plaintext = wp_json_encode(
			array(
				'routing_number' => $routing_number,
				'account_number' => $account_number,
				// Store the holder name inside the ciphertext blob too so the
				// clear-text meta stays best-effort; the encrypted copy is
				// authoritative.
				'holder_name'    => $holder_name,
				'saved_at'       => gmdate( 'c' ),
			)
		);
		if ( ! is_string( $plaintext ) ) {
			return new WP_Error( 'json_error', 'Could not serialize bank details.', array( 'status' => 500 ) );
		}

		try {
			$nonce      = random_bytes( SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES );
			$key        = self::derive_key( $seller_id );
			$ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
				$plaintext,
				(string) $seller_id, // additional data — binds ciphertext to seller
				$nonce,
				$key
			);
		} catch ( Throwable $e ) {
			return new WP_Error(
				'crypto_failed',
				'Could not encrypt bank details. Please try again or contact support.',
				array( 'status' => 500 )
			);
		}
		if ( ! is_string( $ciphertext ) || '' === $ciphertext ) {
			return new WP_Error( 'crypto_failed', 'Encryption returned an empty result.', array( 'status' => 500 ) );
		}

		$last4 = substr( $account_number, -4 );
		update_user_meta( $seller_id, self::META_CIPHERTEXT, base64_encode( $ciphertext ) );
		update_user_meta( $seller_id, self::META_NONCE,      base64_encode( $nonce ) );
		update_user_meta( $seller_id, self::META_LAST4,      $last4 );
		update_user_meta( $seller_id, self::META_HOLDER,     $holder_name );
		update_user_meta( $seller_id, self::META_UPDATED,    gmdate( 'c' ) );

		// Wipe the plaintext copies from memory.
		if ( function_exists( 'sodium_memzero' ) ) {
			try { sodium_memzero( $plaintext ); } catch ( Throwable $e ) {} // phpcs:ignore
			try { sodium_memzero( $key );       } catch ( Throwable $e ) {} // phpcs:ignore
		}

		return rest_ensure_response(
			array(
				'has_bank'    => true,
				'last4'       => $last4,
				'holder_name' => $holder_name,
				'updated_at'  => (string) get_user_meta( $seller_id, self::META_UPDATED, true ),
			)
		);
	}

	/**
	 * Return true when the given seller has a bank account on file.
	 *
	 * Used by MNU_Seller_Readiness to gate listing / order eligibility.
	 */
	public static function has_bank_account( int $seller_id ): bool {
		return '' !== (string) get_user_meta( $seller_id, self::META_LAST4, true );
	}

	/**
	 * Decrypt and return the on-file bank details for admin payout use.
	 * Callers must have manage_woocommerce capability. Returns null when
	 * decryption fails or no bank is on file.
	 *
	 * @return array{holder_name:string,routing_number:string,account_number:string}|null
	 */
	public static function reveal_for_admin( int $seller_id ): ?array {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return null;
		}
		if ( ! self::is_libsodium_available() ) {
			return null;
		}
		$cipher_b64 = (string) get_user_meta( $seller_id, self::META_CIPHERTEXT, true );
		$nonce_b64  = (string) get_user_meta( $seller_id, self::META_NONCE, true );
		if ( '' === $cipher_b64 || '' === $nonce_b64 ) {
			return null;
		}
		$ciphertext = base64_decode( $cipher_b64, true );
		$nonce      = base64_decode( $nonce_b64, true );
		if ( ! is_string( $ciphertext ) || ! is_string( $nonce ) ) {
			return null;
		}
		try {
			$key       = self::derive_key( $seller_id );
			$plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
				$ciphertext,
				(string) $seller_id,
				$nonce,
				$key
			);
			if ( function_exists( 'sodium_memzero' ) ) {
				try { sodium_memzero( $key ); } catch ( Throwable $e ) {} // phpcs:ignore
			}
		} catch ( Throwable $e ) {
			return null;
		}
		if ( ! is_string( $plaintext ) ) {
			return null;
		}
		$decoded = json_decode( $plaintext, true );
		if ( function_exists( 'sodium_memzero' ) ) {
			try { sodium_memzero( $plaintext ); } catch ( Throwable $e ) {} // phpcs:ignore
		}
		if ( ! is_array( $decoded ) ) {
			return null;
		}
		return array(
			'holder_name'    => (string) ( $decoded['holder_name']    ?? '' ),
			'routing_number' => (string) ( $decoded['routing_number'] ?? '' ),
			'account_number' => (string) ( $decoded['account_number'] ?? '' ),
		);
	}

	// -----------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------

	private static function resolve_seller_id( WP_REST_Request $request ): int|WP_Error {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return new WP_Error( 'not_logged_in', 'You must be signed in.', array( 'status' => 401 ) );
		}
		$target = $user_id;
		$asked  = (int) $request->get_param( 'seller_id' );
		if ( $asked > 0 && current_user_can( 'manage_woocommerce' ) ) {
			$target = $asked;
		}
		if ( ! self::is_seller_user( $target ) ) {
			return new WP_Error(
				'not_a_seller',
				'Bank account details are only available to marketplace sellers.',
				array( 'status' => 403 )
			);
		}
		return $target;
	}

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
			|| in_array( 'mynest_seller', (array) $user->roles, true )
			|| in_array( 'administrator', (array) $user->roles, true );
	}

	private static function is_libsodium_available(): bool {
		return function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_encrypt' )
			&& defined( 'SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES' );
	}

	/**
	 * Derive a stable 32-byte encryption key for the given seller.
	 *
	 * The base secret is a concatenation of WordPress's AUTH_KEY and
	 * SECURE_AUTH_KEY, mixed with the seller ID via HKDF-SHA256. Falls
	 * back to wp_salt() when the raw constants are unavailable, so this
	 * still works on hosts that customize the auth constants.
	 */
	private static function derive_key( int $seller_id ): string {
		$base = '';
		if ( defined( 'AUTH_KEY' ) )        { $base .= (string) AUTH_KEY; }
		if ( defined( 'SECURE_AUTH_KEY' ) ) { $base .= (string) SECURE_AUTH_KEY; }
		if ( '' === $base ) {
			$base = (string) wp_salt( 'auth' ) . (string) wp_salt( 'secure_auth' );
		}
		// HKDF-SHA256: extract then expand, seller-bound info tag.
		$prk = hash_hmac( 'sha256', $base, 'mnu_v380_bank_account', true );
		$okm = hash_hmac( 'sha256', 'mnu_v380_bank_account|' . $seller_id . "\x01", $prk, true );
		return $okm; // 32 bytes -> XChaCha20-Poly1305 key size
	}

	private static function sanitize_holder( string $value ): string {
		$value = sanitize_text_field( $value );
		return trim( mb_substr( $value, 0, 100 ) );
	}

	private static function digits_only( string $value ): string {
		return preg_replace( '/\D+/', '', $value ) ?? '';
	}
}

MNU_Bank_Account::init();
