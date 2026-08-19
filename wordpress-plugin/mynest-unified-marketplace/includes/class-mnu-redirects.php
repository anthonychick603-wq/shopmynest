<?php
/**
 * Lightweight page-redirect manager for MyNest Unified Marketplace.
 *
 * Duplicate policy / cart / checkout / dashboard pages accumulated over the
 * marketplace's life; rather than requiring a third-party redirect plugin,
 * this class ships a small option-backed table (`mnu_redirects`) and a
 * template_redirect hook that issues 301s.
 *
 * The seed defaults collapse the duplicates identified in the 2026-08-13
 * audit into a single canonical URL for each intent.
 *
 * @package MyNestUnifiedMarketplace
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'MNU_Redirects' ) ) {

	class MNU_Redirects {

		const OPTION = 'mnu_redirects';

		/**
		 * Default set of redirects seeded on first activation.
		 * key: source path (leading slash, no domain). value: target path.
		 *
		 * @var array<string,string>
		 */
		public const DEFAULT_MAP = array(
			// Cart / checkout duplicates -> canonical WooCommerce endpoints.
			'/cart-classic'      => '/cart',
			'/cart-classic-2'    => '/cart',
			'/checkout-classic'  => '/checkout',

			// Policy page duplicates. Canonicals are OWNED by the ShopMyNest
			// Legal Pages plugin. As of legal-pages v1.1.4 canonical for the
			// privacy page is /privacy-policy/ (not /privacy/), so this plugin
			// no longer maps /privacy-policy anywhere — that mapping formed a
			// new loop against legal-pages and produced 0-byte responses.
			// v3.7.122.2 removes the /privacy-policy source key and adds
			// flip_policy_redirects_v2_once() to prune it from the stored
			// option on upgrade.
			'/terms-of-service'  => '/terms',
			'/terms-of-use'      => '/terms',
			'/refund-policy'     => '/refunds',
			'/returns-refunds'   => '/refunds',
			'/shipping-policy'   => '/shipping',

			// Legacy dashboard aliases. Canonical seller pages live at their own
			// slugs (/seller-dashboard/, /seller-orders/, /seller-payouts/,
			// /seller-login/) — do NOT redirect those away. Only redirect true
			// legacy duplicates that never had their own canonical page.
			'/seller-order'           => '/seller-orders',
			'/seller-earnings'        => '/seller-payouts',
			'/seller-order-portal'    => '/seller-orders',
			'/detailed-order-breakdown' => '/seller-orders',
			'/demo-shops'             => '/shop',
		);

		/**
		 * Legacy redirect keys that shipped in v3.7.x and pointed to the
		 * non-existent /seller-portal/ page. On v3.7.37 upgrade we remove
		 * these so the canonical /seller-dashboard/, /seller-orders/,
		 * /seller-payouts/, /seller-login/ pages become reachable.
		 *
		 * @var string[]
		 */
		private const REMOVED_KEYS = array(
			'/seller-dashboard',
			'/seller-orders',
			'/seller-payouts',
			'/seller-login',
		);

		/**
		 * v3.7.94: legacy short-slug policy redirects that shipped in v3.7.11+
		 * and formed a loop against shopmynest-legal-pages. On upgrade these
		 * are stripped from the stored option once, then merge_new_defaults
		 * adds the correct long -> short direction.
		 *
		 * @var string[]
		 */
		private const POLICY_FLIP_LEGACY_KEYS = array(
			'/privacy',
			'/terms',
			'/refunds',
			'/shipping',
		);

		/**
		 * v3.7.122.2: after legal-pages v1.1.4 flipped privacy canonical
		 * to /privacy-policy/, the stored /privacy-policy => /privacy
		 * mapping from v3.7.94 formed a new redirect loop against
		 * legal-pages' /privacy => /privacy-policy canonical. Removing
		 * it on upgrade breaks the loop and restores the Privacy Policy
		 * page.
		 *
		 * @var string[]
		 */
		private const POLICY_FLIP_V2_LEGACY_KEYS = array(
			'/privacy-policy',
		);

		public static function init(): void {
			self::seed_defaults_once();
			self::purge_removed_keys_once();
			self::flip_policy_redirects_once();
			self::flip_policy_redirects_v2_once();
			self::merge_new_defaults();
			add_action( 'template_redirect', array( __CLASS__, 'maybe_redirect' ), 1 );
		}

		/**
		 * v3.7.122.2 one-shot: remove /privacy-policy from the stored
		 * option so it no longer loops against legal-pages v1.1.4's
		 * canonical direction.
		 */
		public static function flip_policy_redirects_v2_once(): void {
			$sentinel = get_option( 'mnu_redirects_policy_flip_v2', 0 );
			if ( 1 === (int) $sentinel ) {
				return;
			}
			$current = get_option( self::OPTION, array() );
			if ( ! is_array( $current ) ) {
				$current = array();
			}
			$changed = false;
			foreach ( self::POLICY_FLIP_V2_LEGACY_KEYS as $lk ) {
				if ( array_key_exists( $lk, $current ) ) {
					unset( $current[ $lk ] );
					$changed = true;
				}
			}
			if ( $changed ) {
				update_option( self::OPTION, $current, false );
			}
			update_option( 'mnu_redirects_policy_flip_v2', 1, false );
		}

		/**
		 * v3.7.94 one-shot: remove the four legacy short -> long policy
		 * mappings from the stored option. Combined with the new DEFAULT_MAP
		 * direction (long -> short) picked up by merge_new_defaults, this
		 * breaks the redirect loop with shopmynest-legal-pages and restores
		 * the four canonical pages on the live site.
		 */
		public static function flip_policy_redirects_once(): void {
			$sentinel = get_option( 'mnu_redirects_policy_flip_v1', 0 );
			if ( 1 === (int) $sentinel ) {
				return;
			}
			$current = get_option( self::OPTION, array() );
			if ( ! is_array( $current ) ) {
				$current = array();
			}
			$changed = false;
			foreach ( self::POLICY_FLIP_LEGACY_KEYS as $lk ) {
				if ( array_key_exists( $lk, $current ) ) {
					unset( $current[ $lk ] );
					$changed = true;
				}
			}
			if ( $changed ) {
				update_option( self::OPTION, $current, false );
			}
			update_option( 'mnu_redirects_policy_flip_v1', 1, false );
		}

		/**
		 * Remove keys in REMOVED_KEYS from the stored option, but only once
		 * per REMOVED_KEYS revision. Prevents re-adding on every request while
		 * still allowing admins to intentionally re-add a mapping later.
		 */
		public static function purge_removed_keys_once(): void {
			$sentinel = get_option( 'mnu_redirects_purge_v1', 0 );
			if ( 1 === (int) $sentinel ) {
				return;
			}
			$current = get_option( self::OPTION, array() );
			if ( ! is_array( $current ) ) {
				$current = array();
			}
			$changed = false;
			foreach ( self::REMOVED_KEYS as $rk ) {
				if ( array_key_exists( $rk, $current ) ) {
					unset( $current[ $rk ] );
					$changed = true;
				}
			}
			if ( $changed ) {
				update_option( self::OPTION, $current, false );
			}
			update_option( 'mnu_redirects_purge_v1', 1, false );
		}

		/**
		 * Merge any DEFAULT_MAP entries that were added in later versions.
		 * Non-destructive: existing custom mappings win, but new default keys
		 * are added on plugin upgrade.
		 */
		public static function merge_new_defaults(): void {
			$current = get_option( self::OPTION, array() );
			if ( ! is_array( $current ) ) {
				$current = array();
			}
			$changed = false;
			foreach ( self::DEFAULT_MAP as $src => $dest ) {
				if ( ! array_key_exists( $src, $current ) ) {
					$current[ $src ] = $dest;
					$changed = true;
				}
			}
			if ( $changed ) {
				update_option( self::OPTION, $current, false );
			}
		}

		/**
		 * Populate the option with defaults if it has never been saved before.
		 * Idempotent: once a real value (even an empty array) is present, we
		 * do not overwrite it, so admins can prune entries without them
		 * being re-added on the next page load.
		 */
		public static function seed_defaults_once(): void {
			if ( false === get_option( self::OPTION, false ) ) {
				add_option( self::OPTION, self::DEFAULT_MAP, '', false );
			}
		}

		/**
		 * Return the current redirect map.
		 *
		 * @return array<string,string>
		 */
		public static function get_map(): array {
			$stored = get_option( self::OPTION, self::DEFAULT_MAP );
			if ( ! is_array( $stored ) ) {
				return array();
			}
			$clean = array();
			foreach ( $stored as $src => $target ) {
				$src    = self::normalize_path( (string) $src );
				$target = (string) $target;
				if ( '' === $src || '' === $target || $src === $target ) {
					continue;
				}
				$clean[ $src ] = $target;
			}
			return $clean;
		}

		/**
		 * Normalize a path for comparison: leading slash, no trailing slash
		 * (except for root), no querystring.
		 */
		public static function normalize_path( string $path ): string {
			$path = trim( $path );
			if ( '' === $path ) {
				return '';
			}
			$path = strtok( $path, '?' ); // drop querystring
			$path = strtok( $path, '#' ); // drop fragment
			if ( '' === $path ) {
				return '';
			}
			if ( $path[0] !== '/' ) {
				$path = '/' . $path;
			}
			if ( strlen( $path ) > 1 && substr( $path, -1 ) === '/' ) {
				$path = rtrim( $path, '/' );
			}
			return $path;
		}

		/**
		 * Hooked to template_redirect. Issues a 301 when the current request
		 * path matches a configured source, preserving the querystring.
		 */
		public static function maybe_redirect(): void {
			if ( is_admin() ) {
				return;
			}
			if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
				return;
			}
			if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
				return;
			}
			$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
			if ( '' === $request_uri ) {
				return;
			}
			$parsed = wp_parse_url( $request_uri );
			$path   = self::normalize_path( (string) ( $parsed['path'] ?? '' ) );
			$query  = isset( $parsed['query'] ) ? '?' . $parsed['query'] : '';
			if ( '' === $path ) {
				return;
			}
			$map = self::get_map();
			if ( ! isset( $map[ $path ] ) ) {
				return;
			}
			$target = $map[ $path ];
			// Avoid same-page loops.
			if ( self::normalize_path( $target ) === $path ) {
				return;
			}
			if ( 0 !== strpos( $target, 'http' ) ) {
				$target = home_url( $target );
			}
			wp_safe_redirect( $target . $query, 301 );
			exit;
		}
	}

	MNU_Redirects::init();
}
