<?php
/**
 * All front-end shortcodes for MyNest Trust & Growth Suite.
 * Rendering is plain PHP/HTML; interactivity is handled by
 * assets/js/nest-trust-frontend.js via fetch() calls against the
 * `nest-trust/v1` REST routes, authenticated with the WP REST nonce.
 *
 * @package MyNest_Trust_Suite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TNM_Trust_Shortcodes {

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_shortcode( 'nest_trust_my_disputes', array( __CLASS__, 'render_my_disputes' ) );
		add_shortcode( 'nest_trust_seller_disputes', array( __CLASS__, 'render_seller_disputes' ) );
		add_shortcode( 'nest_trust_seller_badge', array( __CLASS__, 'render_seller_badge' ) );
		add_shortcode( 'nest_trust_favorite_button', array( __CLASS__, 'render_favorite_button' ) );
		add_shortcode( 'nest_trust_feed', array( __CLASS__, 'render_feed' ) );
		add_shortcode( 'nest_trust_filters', array( __CLASS__, 'render_filters' ) );
	}

	/**
	 * [nest_trust_my_disputes] — buyer-facing list/detail of their disputes.
	 */
	public static function render_my_disputes() {
		if ( ! is_user_logged_in() ) {
			return '<p class="tnm-trust-notice">' . esc_html__( 'Please log in to view your disputes.', 'nest-trust' ) . '</p>';
		}

		ob_start();
		?>
		<div id="tnm-trust-my-disputes" class="tnm-trust-panel" data-role="buyer">
			<h3><?php esc_html_e( 'My Disputes', 'nest-trust' ); ?></h3>
			<div class="tnm-trust-dispute-form">
				<h4><?php esc_html_e( 'Open a New Dispute', 'nest-trust' ); ?></h4>
				<label>
					<?php esc_html_e( 'Order ID', 'nest-trust' ); ?>
					<input type="number" class="tnm-trust-input" id="tnm-trust-dispute-order-id" min="1" />
				</label>
				<label>
					<?php esc_html_e( 'Reason', 'nest-trust' ); ?>
					<select id="tnm-trust-dispute-reason" class="tnm-trust-input">
						<option value="not_as_described"><?php esc_html_e( 'Not as described', 'nest-trust' ); ?></option>
						<option value="damaged"><?php esc_html_e( 'Damaged', 'nest-trust' ); ?></option>
						<option value="not_arrived"><?php esc_html_e( 'Not arrived', 'nest-trust' ); ?></option>
						<option value="other"><?php esc_html_e( 'Other', 'nest-trust' ); ?></option>
					</select>
				</label>
				<label>
					<?php esc_html_e( 'Description', 'nest-trust' ); ?>
					<textarea id="tnm-trust-dispute-description" class="tnm-trust-input"></textarea>
				</label>
				<button type="button" id="tnm-trust-submit-dispute" class="tnm-trust-btn"><?php esc_html_e( 'Submit Dispute', 'nest-trust' ); ?></button>
				<p class="tnm-trust-form-message" id="tnm-trust-dispute-form-message" aria-live="polite"></p>
			</div>
			<div id="tnm-trust-disputes-list" class="tnm-trust-list" aria-live="polite">
				<p><?php esc_html_e( 'Loading your disputes…', 'nest-trust' ); ?></p>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * [nest_trust_seller_disputes] — seller-facing list/detail + respond form.
	 */
	public static function render_seller_disputes() {
		if ( ! is_user_logged_in() || ! TNM_Trust_Compat::current_user_is_seller() ) {
			return '<p class="tnm-trust-notice">' . esc_html__( 'This area is only available to sellers.', 'nest-trust' ) . '</p>';
		}

		ob_start();
		?>
		<div id="tnm-trust-seller-disputes" class="tnm-trust-panel" data-role="seller">
			<h3><?php esc_html_e( 'Disputes Against My Orders', 'nest-trust' ); ?></h3>
			<div id="tnm-trust-seller-disputes-list" class="tnm-trust-list" aria-live="polite">
				<p><?php esc_html_e( 'Loading disputes…', 'nest-trust' ); ?></p>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * [nest_trust_seller_badge id="123"] — inline SVG badge + tooltip metrics.
	 *
	 * @param array $atts Shortcode attributes.
	 */
	public static function render_seller_badge( $atts ) {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts );
		$seller_id = absint( $atts['id'] );

		if ( ! $seller_id ) {
			return '';
		}

		$badge = TNM_Trust_Seller_Badge::get_badge( $seller_id );

		if ( 'none' === $badge['tier'] ) {
			return '';
		}

		$color = ( 'trusted_seller' === $badge['tier'] ) ? '#1a7f4e' : '#a0620a';

		$metrics_summary = array();
		if ( null !== $badge['metrics']['on_time_rate'] ) {
			$metrics_summary[] = sprintf(
				/* translators: %s: percentage */
				esc_html__( 'On-time: %s%%', 'nest-trust' ),
				esc_html( $badge['metrics']['on_time_rate'] )
			);
		}
		if ( null !== $badge['metrics']['avg_rating'] ) {
			$metrics_summary[] = sprintf(
				/* translators: %s: rating out of 5 */
				esc_html__( 'Rating: %s/5', 'nest-trust' ),
				esc_html( $badge['metrics']['avg_rating'] )
			);
		}
		if ( null !== $badge['metrics']['response_rate'] ) {
			$metrics_summary[] = sprintf(
				/* translators: %s: percentage */
				esc_html__( 'Response: %s%%', 'nest-trust' ),
				esc_html( $badge['metrics']['response_rate'] )
			);
		}

		$tooltip = implode( ' · ', $metrics_summary );

		ob_start();
		?>
		<span class="tnm-trust-seller-badge" style="color: <?php echo esc_attr( $color ); ?>;" title="<?php echo esc_attr( $tooltip ); ?>">
			<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
				<path fill="currentColor" d="M12 1l3.09 6.26L22 8.27l-5 4.87 1.18 6.86L12 16.9l-6.18 3.1L7 13.14 2 8.27l6.91-1.01L12 1z"></path>
			</svg>
			<span class="tnm-trust-seller-badge-label"><?php echo esc_html( $badge['tier_label'] ); ?></span>
		</span>
		<?php
		return ob_get_clean();
	}

	/**
	 * [nest_trust_favorite_button product_id="123"].
	 *
	 * @param array $atts Shortcode attributes.
	 */
	public static function render_favorite_button( $atts ) {
		$atts = shortcode_atts( array( 'product_id' => 0 ), $atts );
		$product_id = absint( $atts['product_id'] );

		if ( ! $product_id ) {
			global $product;
			if ( $product && is_a( $product, 'WC_Product' ) ) {
				$product_id = $product->get_id();
			}
		}

		return TNM_Trust_Favorites::render_button( $product_id );
	}

	/**
	 * [nest_trust_feed] — responsive grid consuming the personalized feed endpoint.
	 */
	public static function render_feed() {
		ob_start();
		?>
		<div id="tnm-trust-feed" class="tnm-trust-feed-grid" aria-live="polite">
			<p><?php esc_html_e( 'Loading feed…', 'nest-trust' ); ?></p>
		</div>
		<button type="button" id="tnm-trust-feed-load-more" class="tnm-trust-btn tnm-trust-feed-load-more" style="display:none;"><?php esc_html_e( 'Load more', 'nest-trust' ); ?></button>
		<?php
		return ob_get_clean();
	}

	/**
	 * [nest_trust_filters] — condition/size/brand filter checkboxes for
	 * shop/category archive pages, submitting via WooCommerce's native
	 * attribute query string filtering.
	 */
	public static function render_filters() {
		if ( ! function_exists( 'wc_get_attribute_taxonomies' ) ) {
			return '';
		}

		$slugs = array( 'condition', 'size', 'brand' );

		ob_start();
		?>
		<form class="tnm-trust-filters" method="get">
			<?php foreach ( $slugs as $slug ) : ?>
				<?php
				$taxonomy = 'pa_' . $slug;
				if ( ! taxonomy_exists( $taxonomy ) ) {
					continue;
				}
				$terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) );
				if ( is_wp_error( $terms ) || empty( $terms ) ) {
					continue;
				}
				$query_var = 'filter_' . $slug;
				$current   = isset( $_GET[ $query_var ] ) ? sanitize_text_field( wp_unslash( $_GET[ $query_var ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only GET filter display.
				$selected  = $current ? explode( ',', $current ) : array();
				?>
				<fieldset class="tnm-trust-filter-group">
					<legend><?php echo esc_html( wc_attribute_label( $taxonomy ) ); ?></legend>
					<?php foreach ( $terms as $term ) : ?>
						<label class="tnm-trust-filter-option">
							<input
								type="checkbox"
								name="<?php echo esc_attr( $query_var ); ?>[]"
								value="<?php echo esc_attr( $term->slug ); ?>"
								<?php checked( in_array( $term->slug, $selected, true ) ); ?>
							/>
							<?php echo esc_html( $term->name ); ?>
						</label>
					<?php endforeach; ?>
				</fieldset>
			<?php endforeach; ?>
			<button type="submit" class="tnm-trust-btn"><?php esc_html_e( 'Apply Filters', 'nest-trust' ); ?></button>
		</form>
		<?php
		return ob_get_clean();
	}
}
