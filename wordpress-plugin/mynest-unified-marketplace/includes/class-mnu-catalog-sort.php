<?php
/**
 * Force shop / category / tag archives to sort newest-first, and add a
 * marketplace-specific "In stock first" sort option so buyers see purchasable
 * products before out-of-stock listings.
 *
 * Overrides the customizer default ("Default sorting") to date DESC. Explicit
 * user choices (price, popularity, rating) still work — only the neutral
 * "menu_order" bucket is remapped. The new "in_stock" option is offered in
 * the catalog dropdown and pins stock_status = 'instock' rows to the top,
 * then falls back to newest-first inside each bucket.
 */
defined( 'ABSPATH' ) || exit;

final class MNU_Catalog_Sort {
	const IN_STOCK_KEY = 'in_stock';

	public static function init(): void {
		add_filter( 'woocommerce_default_catalog_orderby', array( __CLASS__, 'default_orderby' ) );
		// v3.7.75 — do NOT re-register "In stock first" as a Woo catalog option.
		// Out-of-stock is hidden from listings entirely, so a stock-first option
		// is redundant and could reveal that OOS items exist.
		// add_filter( 'woocommerce_catalog_orderby', array( __CLASS__, 'catalog_options' ) );
		// add_filter( 'woocommerce_default_catalog_orderby_options', array( __CLASS__, 'catalog_options' ) );
		// v3.7.75 — hide out-of-stock products from the shop / category / tag
		// archives, the block-based Product Collection, and the classic
		// [products] shortcode. Mirrors Woo's "Hide out of stock" option but
		// applied programmatically so it can't be toggled off from settings.
		add_filter( 'woocommerce_product_query_tax_query', array( __CLASS__, 'exclude_outofstock_tax_query' ), 20, 2 );
		add_filter( 'woocommerce_shortcode_products_query', array( __CLASS__, 'exclude_outofstock_shortcode_query' ), 20, 3 );
		add_filter( 'woocommerce_get_catalog_ordering_args', array( __CLASS__, 'ordering_args' ), 10, 3 );
		add_filter( 'woocommerce_shortcode_products_query', array( __CLASS__, 'shortcode_query' ), 10, 3 );
		// v3.7.74 — the block-based Product Collection block (used in the
		// archive-product FSE template) ignores the classic Woo shortcode
		// filters. Hook into its query too so the dropdown selection actually
		// changes the on-screen ordering.
		add_filter( 'pre_get_posts', array( __CLASS__, 'apply_product_collection_orderby' ), 20 );
		add_filter( 'posts_clauses', array( __CLASS__, 'apply_in_stock_clauses' ), 20, 2 );
		add_shortcode( 'shopmynest_shop_sort', array( __CLASS__, 'render_sort_dropdown' ) );
	}

	/**
	 * Render a lightweight sort dropdown for the Shop landing page (and any
	 * other page that lists the [products] shortcode). Updates ?orderby=
	 * without JS by using a native <form> that submits GET; falls back to
	 * a tiny inline handler for instant client-side navigation. We deliberately
	 * avoid enqueueing a script so this works everywhere including block
	 * template contexts.
	 */
	public static function render_sort_dropdown( $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'align' => 'right', // 'left' | 'right' | 'center'
				'label' => __( 'Sort', 'mynest-unified-marketplace' ),
			),
			$atts,
			'shopmynest_shop_sort'
		);
		$current = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'date';
		// v3.7.75 — do NOT expose "In stock first" as a user-facing option.
		// Out-of-stock items are hidden from the shop entirely (see
		// exclude_outofstock_products / product_query_tax_query below), so
		// there's nothing to sort against.
		$options = array(
			'date'       => __( 'Newest', 'mynest-unified-marketplace' ),
			'price'      => __( 'Price: low to high', 'mynest-unified-marketplace' ),
			'price-desc' => __( 'Price: high to low', 'mynest-unified-marketplace' ),
			'popularity' => __( 'Best selling', 'mynest-unified-marketplace' ),
			'rating'     => __( 'Top rated', 'mynest-unified-marketplace' ),
		);
		$align_class = 'mnu-shop-sort--' . sanitize_html_class( $atts['align'], 'right' );

		ob_start();
		?>
		<form class="mnu-shop-sort <?php echo esc_attr( $align_class ); ?>" method="get" role="search" aria-label="<?php echo esc_attr( $atts['label'] ); ?>">
			<?php
			// Preserve every other query parameter so pagination + filters survive.
			foreach ( (array) $_GET as $key => $value ) {
				if ( 'orderby' === $key || 'paged' === $key ) {
					continue;
				}
				if ( is_array( $value ) ) {
					foreach ( $value as $v ) {
						echo '<input type="hidden" name="' . esc_attr( $key . '[]' ) . '" value="' . esc_attr( wp_unslash( $v ) ) . '">';
					}
				} else {
					echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( wp_unslash( $value ) ) . '">';
				}
			}
			?>
			<label class="mnu-shop-sort__label" for="mnu-shop-sort-select"><?php echo esc_html( $atts['label'] ); ?>:</label>
			<select id="mnu-shop-sort-select" name="orderby" onchange="this.form.submit()">
				<?php foreach ( $options as $val => $label ) : ?>
					<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $current, $val ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<noscript><button type="submit" class="mnu-shop-sort__go"><?php esc_html_e( 'Apply', 'mynest-unified-marketplace' ); ?></button></noscript>
		</form>
		<style>
			.mnu-shop-sort { display:flex; align-items:center; gap:.5rem; margin:.5rem 0 1rem; font-size:.95rem; }
			.mnu-shop-sort--right { justify-content:flex-end; }
			.mnu-shop-sort--left  { justify-content:flex-start; }
			.mnu-shop-sort--center{ justify-content:center; }
			.mnu-shop-sort__label { color:#26295F; font-weight:600; }
			.mnu-shop-sort select {
				padding:.4rem .7rem; border:1px solid #E4DED4; border-radius:.5rem;
				background:#FFFFFF; color:#1B1A21; font:inherit; min-height:2.25rem;
			}
			.mnu-shop-sort select:focus { outline:2px solid #3A3D8A; outline-offset:1px; }
		</style>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Default sort when the shopper hasn't chosen anything: in-stock first,
	 * newest inside each bucket. Buyers should never land on a page whose
	 * first row is out of stock.
	 */
	public static function default_orderby( $orderby ) {
		return self::IN_STOCK_KEY;
	}

	/**
	 * Register the "In stock first" option in the Woo catalog dropdown. The
	 * filter is called twice — for the visible options and for the "default"
	 * customizer selector — so we register the same label for both. Keep the
	 * new option at the top so it reads as the primary browse mode.
	 *
	 * @param array<string,string> $options
	 * @return array<string,string>
	 */
	public static function catalog_options( $options ) {
		if ( ! is_array( $options ) ) {
			return $options;
		}
		return array_merge(
			array( self::IN_STOCK_KEY => __( 'In stock first', 'mynest-unified-marketplace' ) ),
			$options
		);
	}

	/**
	 * Map orderby → WP_Query args. Explicit price/popularity/rating requests
	 * pass through untouched. Our IN_STOCK_KEY and the neutral menu_order
	 * bucket both route through the stock-aware branch; posts_clauses does
	 * the final ORDER BY rewrite because WP_Query alone can't express
	 * "stock_status ASC, then post_date DESC".
	 */
	public static function ordering_args( $args, $orderby = '', $order = '' ) {
		$requested = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : '';
		if ( '' !== $requested && 'menu_order' !== $requested && self::IN_STOCK_KEY !== $requested ) {
			return $args;
		}
		// Signal downstream (posts_clauses filter) that this query should be
		// sorted stock-first, newest-second. We keep orderby set to 'date'
		// so any theme code inspecting $args still sees a sensible value.
		$args['orderby']         = 'date';
		$args['order']           = 'DESC';
		$args['meta_key']        = '';
		$args['mnu_stock_first'] = true;
		return $args;
	}

	/**
	 * v3.7.74 — the Woo Product Collection block calls WP_Query directly
	 * (bypassing woocommerce_shortcode_products_query and
	 * woocommerce_get_catalog_ordering_args). When we're on a product archive
	 * or the front-end Shop page and the shopper picked an orderby via our
	 * dropdown, translate it onto the WP_Query the block is about to run so
	 * the on-screen listing matches the selection.
	 */
	public static function apply_product_collection_orderby( $query ) {
		if ( is_admin() || ! ( $query instanceof WP_Query ) ) {
			return;
		}
		$post_types = (array) $query->get( 'post_type' );
		if ( ! $post_types || ! in_array( 'product', $post_types, true ) ) {
			return;
		}

		// v3.7.75 — exclude the outofstock visibility term from every product
		// query on the front end. Merges with any existing tax_query.
		self::inject_outofstock_exclusion( $query );

		$requested = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : '';
		if ( '' === $requested ) {
			// Nothing chosen — default to stock-first only on the primary shop archive
			// and category / tag archives, not on unrelated product queries.
			if ( ( function_exists( 'is_shop' ) && is_shop() ) ||
			     ( function_exists( 'is_product_taxonomy' ) && is_product_taxonomy() ) ) {
				$query->set( 'mnu_stock_first', true );
				$query->set( 'orderby', 'date' );
				$query->set( 'order', 'DESC' );
			}
			return;
		}
		switch ( $requested ) {
			case self::IN_STOCK_KEY:
			case 'menu_order':
				// v3.7.75 — no longer a user-visible option, but any stale link
				// bookmarked with ?orderby=in_stock still lands somewhere sensible.
				$query->set( 'orderby', 'date' );
				$query->set( 'order', 'DESC' );
				break;
			case 'date':
				$query->set( 'orderby', 'date' );
				$query->set( 'order', 'DESC' );
				break;
			case 'price':
				$query->set( 'meta_key', '_price' );
				$query->set( 'orderby', 'meta_value_num' );
				$query->set( 'order', 'ASC' );
				break;
			case 'price-desc':
				$query->set( 'meta_key', '_price' );
				$query->set( 'orderby', 'meta_value_num' );
				$query->set( 'order', 'DESC' );
				break;
			case 'popularity':
				$query->set( 'meta_key', 'total_sales' );
				$query->set( 'orderby', 'meta_value_num' );
				$query->set( 'order', 'DESC' );
				break;
			case 'rating':
				$query->set( 'meta_key', '_wc_average_rating' );
				$query->set( 'orderby', 'meta_value_num' );
				$query->set( 'order', 'DESC' );
				break;
		}
	}

	public static function shortcode_query( $query_args, $attributes, $type ) {
		if ( empty( $query_args['orderby'] ) || 'menu_order' === $query_args['orderby'] || self::IN_STOCK_KEY === $query_args['orderby'] ) {
			$query_args['orderby'] = 'date';
			$query_args['order']   = 'DESC';
		}
		return $query_args;
	}

	/**
	 * v3.7.75 — append the outofstock visibility term as a NOT IN clause on
	 * the WP_Query tax_query. Idempotent: only adds the clause once. Called by
	 * pre_get_posts on all front-end product queries.
	 */
	protected static function inject_outofstock_exclusion( WP_Query $query ): void {
		$tax_query = (array) $query->get( 'tax_query' );
		foreach ( $tax_query as $clause ) {
			if ( is_array( $clause )
				&& isset( $clause['taxonomy'], $clause['terms'] )
				&& 'product_visibility' === $clause['taxonomy']
				&& in_array( 'outofstock', (array) $clause['terms'], true )
				&& isset( $clause['operator'] ) && 'NOT IN' === $clause['operator']
			) {
				return;
			}
		}
		$tax_query[] = array(
			'taxonomy' => 'product_visibility',
			'field'    => 'name',
			'terms'    => array( 'outofstock' ),
			'operator' => 'NOT IN',
		);
		$query->set( 'tax_query', $tax_query );
	}

	/**
	 * v3.7.75 — add outofstock exclusion to Woo's classic product query
	 * (used by shop / category loops that go through wc_get_products()).
	 */
	public static function exclude_outofstock_tax_query( $tax_query, $query = null ) {
		if ( ! is_array( $tax_query ) ) {
			$tax_query = array();
		}
		foreach ( $tax_query as $clause ) {
			if ( is_array( $clause )
				&& isset( $clause['taxonomy'], $clause['terms'] )
				&& 'product_visibility' === $clause['taxonomy']
				&& in_array( 'outofstock', (array) $clause['terms'], true )
				&& isset( $clause['operator'] ) && 'NOT IN' === $clause['operator']
			) {
				return $tax_query;
			}
		}
		$tax_query[] = array(
			'taxonomy' => 'product_visibility',
			'field'    => 'name',
			'terms'    => array( 'outofstock' ),
			'operator' => 'NOT IN',
		);
		return $tax_query;
	}

	/**
	 * v3.7.75 — hide out-of-stock from the [products] shortcode too.
	 */
	public static function exclude_outofstock_shortcode_query( $query_args, $attributes = array(), $type = '' ) {
		if ( ! isset( $query_args['tax_query'] ) || ! is_array( $query_args['tax_query'] ) ) {
			$query_args['tax_query'] = array();
		}
		foreach ( $query_args['tax_query'] as $clause ) {
			if ( is_array( $clause )
				&& isset( $clause['taxonomy'], $clause['terms'] )
				&& 'product_visibility' === $clause['taxonomy']
				&& in_array( 'outofstock', (array) $clause['terms'], true )
				&& isset( $clause['operator'] ) && 'NOT IN' === $clause['operator']
			) {
				return $query_args;
			}
		}
		$query_args['tax_query'][] = array(
			'taxonomy' => 'product_visibility',
			'field'    => 'name',
			'terms'    => array( 'outofstock' ),
			'operator' => 'NOT IN',
		);
		return $query_args;
	}

	/**
	 * Rewrite the SQL ORDER BY when the query has been flagged as stock-first.
	 * We join the WooCommerce product lookup table when it's available (fast,
	 * indexed on stock_status), and fall back to the _stock_status postmeta
	 * for hosts that haven't materialized the lookup yet.
	 */
	public static function apply_in_stock_clauses( $clauses, $query ) {
		global $wpdb;
		if ( ! ( $query instanceof WP_Query ) ) {
			return $clauses;
		}
		if ( ! $query->get( 'mnu_stock_first' ) ) {
			return $clauses;
		}
		$post_types = (array) $query->get( 'post_type' );
		if ( $post_types && ! in_array( 'product', $post_types, true ) ) {
			return $clauses;
		}

		$lookup = $wpdb->prefix . 'wc_product_meta_lookup';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		$has_lookup = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $lookup ) );
		// phpcs:enable

		if ( $has_lookup ) {
			$clauses['join']   .= " LEFT JOIN {$lookup} AS mnu_stock_lookup ON mnu_stock_lookup.product_id = {$wpdb->posts}.ID ";
			// stock_status 'instock' | 'onbackorder' come before 'outofstock' when sorted ASC.
			$clauses['orderby'] = " CASE mnu_stock_lookup.stock_status WHEN 'instock' THEN 0 WHEN 'onbackorder' THEN 1 ELSE 2 END ASC, {$wpdb->posts}.post_date DESC ";
		} else {
			$clauses['join']   .= " LEFT JOIN {$wpdb->postmeta} AS mnu_stock_meta ON mnu_stock_meta.post_id = {$wpdb->posts}.ID AND mnu_stock_meta.meta_key = '_stock_status' ";
			$clauses['orderby'] = " CASE mnu_stock_meta.meta_value WHEN 'instock' THEN 0 WHEN 'onbackorder' THEN 1 ELSE 2 END ASC, {$wpdb->posts}.post_date DESC ";
			// LEFT JOIN can multiply rows if a product has two stock rows (shouldn't happen but guard).
			if ( false === strpos( $clauses['groupby'], "{$wpdb->posts}.ID" ) ) {
				$clauses['groupby'] = "{$wpdb->posts}.ID";
			}
		}

		return $clauses;
	}
}
