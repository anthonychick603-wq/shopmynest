<?php
/**
 * Keyword search filter for marketplace product queries.
 *
 * Default WP_Query `s` matches the entire phrase as a substring against
 * post_title/post_content/post_excerpt. That means:
 *   - "hair bow" doesn't match a product titled "hairbow"
 *   - "hairbows" doesn't match "hairbow" (plural mismatch)
 *   - "hair accessories" doesn't match "hairbows" or "hairclips" even
 *     though those are the same category
 *
 * This filter replaces the default MySQL LIKE clause with per-token
 * matching plus a whitespace-normalized column so compound-word forms
 * ("hairbow" ↔ "hair bow") match, and expands each token with a small
 * plural/singular rule set.
 *
 * Only active when the caller marks the query with `mnu_keyword_search=1`
 * so we don't affect WordPress admin search or third-party queries.
 *
 * @package MyNest
 * @since 3.7.111
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNU_Keyword_Search {

	/**
	 * Wire the filter. Called once from the plugin bootstrap.
	 */
	public static function init(): void {
		add_filter( 'posts_search', array( __CLASS__, 'filter_search' ), 10, 2 );
		// Also apply to front-end shop searches (WooCommerce product
		// archive with ?s=…) so the browsing site behaves the same as
		// the mobile app.
		add_action( 'pre_get_posts', array( __CLASS__, 'flag_shop_search' ), 30 );
	}

	/**
	 * Turn on our keyword-search filter for the main product-archive query
	 * when a search term is present. Runs after WooCommerce's own
	 * pre_get_posts so we don't disturb its setup.
	 */
	public static function flag_shop_search( \WP_Query $q ): void {
		if ( is_admin() || ! $q->is_main_query() ) {
			return;
		}
		$term = trim( (string) $q->get( 's' ) );
		if ( $term === '' ) {
			return;
		}
		// Only touch product-related searches. WooCommerce sets post_type
		// to 'product' on the shop archive; the front-page search bar
		// (post_type any) can also land here.
		$pt = $q->get( 'post_type' );
		if ( $pt === 'product' || ( is_array( $pt ) && in_array( 'product', $pt, true ) ) || is_shop() || is_product_taxonomy() ) {
			$q->set( 'mnu_keyword_search', 1 );
		}
	}

	/**
	 * Replace WP's built-in search SQL with per-token matching.
	 *
	 * @param string    $search   The existing WHERE fragment (starts with " AND ...").
	 * @param \WP_Query $wp_query
	 * @return string
	 */
	public static function filter_search( string $search, \WP_Query $wp_query ): string {
		// Only run when the caller opted in — protects wp-admin search,
		// front-end site search, and third-party plugin queries.
		if ( ! $wp_query->get( 'mnu_keyword_search' ) ) {
			return $search;
		}
		$term = trim( (string) $wp_query->get( 's' ) );
		if ( $term === '' ) {
			return $search;
		}

		global $wpdb;
		$tokens = self::tokenize( $term );
		if ( empty( $tokens ) ) {
			return $search;
		}

		// Each token expands to a set of variants (singular/plural).
		// All expanded terms for a single token are OR'd together;
		// tokens themselves are AND'd, so "hair bow" requires both
		// "hair*" AND "bow*" (each with variants) to appear.
		$per_token = array();
		foreach ( $tokens as $tok ) {
			$variants = self::expand( $tok );
			$or_parts = array();
			foreach ( $variants as $variant ) {
				$like = '%' . $wpdb->esc_like( $variant ) . '%';
				// Match against title, excerpt, content, AND a
				// whitespace-stripped copy of the title so "hair bow"
				// hits a product literally titled "hairbow".
				$or_parts[] = $wpdb->prepare(
					"({$wpdb->posts}.post_title LIKE %s "
					. "OR {$wpdb->posts}.post_excerpt LIKE %s "
					. "OR {$wpdb->posts}.post_content LIKE %s "
					. "OR REPLACE({$wpdb->posts}.post_title, ' ', '') LIKE %s)",
					$like,
					$like,
					$like,
					'%' . $wpdb->esc_like( str_replace( ' ', '', $variant ) ) . '%'
				);
			}
			$per_token[] = '(' . implode( ' OR ', $or_parts ) . ')';
		}

		return ' AND (' . implode( ' AND ', $per_token ) . ') ';
	}

	/**
	 * Split the raw phrase into meaningful tokens. Drops stopwords and
	 * anything shorter than 2 characters (to keep results relevant).
	 *
	 * @param string $phrase
	 * @return string[]
	 */
	private static function tokenize( string $phrase ): array {
		$phrase = strtolower( trim( $phrase ) );
		// Also expand "hairbow" style compound queries — the buyer typed
		// it as one word, but we want each of "hair" and "bow" as
		// candidates. Achieved by keeping the original as a token AND
		// letting the REPLACE(' ', '') clause pick up compound matches.
		$phrase = preg_replace( '/[^a-z0-9\s]/', ' ', $phrase );
		$parts  = preg_split( '/\s+/', $phrase, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $parts ) ) {
			return array();
		}
		$stop = self::stopwords();
		$out  = array();
		foreach ( $parts as $p ) {
			if ( strlen( $p ) < 2 ) {
				continue;
			}
			if ( isset( $stop[ $p ] ) ) {
				continue;
			}
			$out[] = $p;
		}
		// If every word was a stopword (e.g. buyer literally searched
		// "the"), fall back to the original tokens so they get zero
		// results rather than everything.
		if ( empty( $out ) && ! empty( $parts ) ) {
			$out = $parts;
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Generate singular/plural variants of a token so "hairbow" matches
	 * "hairbows" and vice versa. Kept intentionally simple — no full
	 * stemmer. Rules:
	 *   - drop trailing "s" if len > 3 (bows → bow)
	 *   - drop trailing "es" if len > 4 (boxes → box)
	 *   - drop trailing "ies" → "y" if len > 4 (candies → candy)
	 *   - append "s" for the reverse (bow → bows)
	 *
	 * @param string $token
	 * @return string[]
	 */
	private static function expand( string $token ): array {
		$out = array( $token );
		$len = strlen( $token );

		// Singularize.
		if ( $len > 4 && substr( $token, -3 ) === 'ies' ) {
			$out[] = substr( $token, 0, -3 ) . 'y';
		} elseif ( $len > 4 && substr( $token, -2 ) === 'es' ) {
			$out[] = substr( $token, 0, -2 );
		} elseif ( $len > 3 && substr( $token, -1 ) === 's' ) {
			$out[] = substr( $token, 0, -1 );
		}

		// Pluralize.
		if ( $len >= 3 && substr( $token, -1 ) !== 's' ) {
			if ( substr( $token, -1 ) === 'y' && $len > 3 ) {
				$out[] = substr( $token, 0, -1 ) . 'ies';
			} else {
				$out[] = $token . 's';
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Small English stopword set. Buyers using natural phrases like
	 * "shirt for baby" shouldn't have "for" narrow their results.
	 *
	 * @return array<string,bool>
	 */
	private static function stopwords(): array {
		static $set = null;
		if ( $set !== null ) {
			return $set;
		}
		$words = array( 'a','an','and','are','as','at','be','but','by','for','from','has','he','in','is','it','its','of','on','or','she','that','the','to','was','were','will','with','my','your','our' );
		$set   = array_flip( $words );
		return $set;
	}
}

MNU_Keyword_Search::init();
