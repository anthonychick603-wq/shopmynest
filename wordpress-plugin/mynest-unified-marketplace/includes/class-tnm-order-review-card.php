<?php
/**
 * TNM_Order_Review_Card
 *
 * v3.7.108 - Post-purchase review nudge on the buyer's my-account view-order
 * page. For each seller on a paid order the buyer has not yet reviewed, we
 * render a compact "Leave a review" card (star input + note textarea) that
 * POSTs to admin-post.php. Successful submissions delegate to
 * TNM_Social::submit_review() so the web and app share one dedup path.
 *
 * @package MyNest_Unified_Marketplace
 */

defined( 'ABSPATH' ) || exit;

final class TNM_Order_Review_Card {

	public static function init(): void {
		add_action( 'woocommerce_order_details_after_order_table', array( __CLASS__, 'render' ), 30, 1 );
		add_action( 'admin_post_tnm_leave_seller_review', array( __CLASS__, 'handle_submit' ) );
	}

	/**
	 * Render one review card per seller on the order the buyer has not yet
	 * reviewed. Silently returns for orders that are not paid, orders that
	 * belong to a different user, or when the reviews table has no eligible
	 * sellers to prompt for.
	 */
	public static function render( WC_Order $order ): void {
		if ( ! is_user_logged_in() ) {
			return;
		}
		$user_id = (int) get_current_user_id();
		if ( $user_id !== (int) $order->get_customer_id() ) {
			return;
		}
		if ( ! in_array( $order->get_status(), array( 'processing', 'completed' ), true ) ) {
			return;
		}
		if ( ! class_exists( 'TNM_Social' ) || ! function_exists( 'tnm_table' ) ) {
			return;
		}

		$seller_ids = self::order_seller_ids( $order );
		if ( empty( $seller_ids ) ) {
			return;
		}

		global $wpdb;
		$reviews_table    = tnm_table( 'reviews' );
		$placeholders     = implode( ',', array_fill( 0, count( $seller_ids ), '%d' ) );
		$params           = array_merge( array( $user_id, (int) $order->get_id() ), $seller_ids );
		$sql              = "SELECT DISTINCT seller_id FROM {$reviews_table} WHERE reviewer_id = %d AND order_id = %d AND seller_id IN ({$placeholders})";
		$already_reviewed = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $sql, $params ) ) );

		$pending = array_values( array_diff( $seller_ids, $already_reviewed ) );
		if ( empty( $pending ) ) {
			return;
		}

		// One-time flash from admin-post handler.
		$flash = get_transient( 'tnm_review_flash_' . $user_id . '_' . $order->get_id() );
		if ( $flash ) {
			delete_transient( 'tnm_review_flash_' . $user_id . '_' . $order->get_id() );
		}

		echo '<section class="tnm-order-review-card" style="margin-top:24px;padding:20px;border:1px solid #e5e0d5;border-radius:12px;background:#fdfaf3;">';
		echo '<h3 style="margin:0 0 6px;font-size:18px;">Leave a review</h3>';
		echo '<p style="margin:0 0 16px;color:#7a6d5c;">Your feedback helps other buyers and rewards great sellers.</p>';

		if ( is_array( $flash ) && ! empty( $flash['message'] ) ) {
			$cls = ! empty( $flash['error'] ) ? 'is-error' : 'is-success';
			printf(
				'<p class="tnm-order-review-flash %1$s" style="margin:0 0 12px;padding:8px 12px;border-radius:6px;background:%3$s;color:%4$s;">%2$s</p>',
				esc_attr( $cls ),
				esc_html( $flash['message'] ),
				! empty( $flash['error'] ) ? '#fdecec' : '#e7f5e7',
				! empty( $flash['error'] ) ? '#8a1f1f' : '#1f5e2c'
			);
		}

		foreach ( $pending as $seller_id ) {
			$seller_name = function_exists( 'tnm_seller_display_name' ) ? tnm_seller_display_name( (int) $seller_id ) : ( 'Seller #' . (int) $seller_id );
			self::render_seller_form( $order, (int) $seller_id, (string) $seller_name );
		}

		echo '</section>';
	}

	private static function render_seller_form( WC_Order $order, int $seller_id, string $seller_name ): void {
		$order_id = (int) $order->get_id();
		$action   = admin_url( 'admin-post.php' );
		$nonce    = wp_create_nonce( 'tnm_leave_review_' . $order_id . '_' . $seller_id );
		?>
		<form method="post" action="<?php echo esc_url( $action ); ?>" style="margin-bottom:16px;padding:14px;border:1px solid #ece5d4;border-radius:10px;background:#ffffff;">
			<input type="hidden" name="action" value="tnm_leave_seller_review" />
			<input type="hidden" name="order_id" value="<?php echo esc_attr( (string) $order_id ); ?>" />
			<input type="hidden" name="seller_id" value="<?php echo esc_attr( (string) $seller_id ); ?>" />
			<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>" />
			<div style="margin-bottom:10px;font-weight:600;"><?php echo esc_html( $seller_name ); ?></div>
			<label style="display:block;margin-bottom:6px;font-size:14px;color:#5c5142;">Your rating</label>
			<select name="rating" required style="margin-bottom:12px;padding:6px 10px;border:1px solid #d4c8a8;border-radius:6px;background:#fff;">
				<option value="">Pick a rating</option>
				<option value="5">5 &mdash; Excellent</option>
				<option value="4">4 &mdash; Great</option>
				<option value="3">3 &mdash; Okay</option>
				<option value="2">2 &mdash; Below expectations</option>
				<option value="1">1 &mdash; Poor</option>
			</select>
			<label style="display:block;margin-bottom:6px;font-size:14px;color:#5c5142;">Add a short note (optional)</label>
			<textarea name="review" rows="3" maxlength="1000" style="width:100%;padding:8px 10px;border:1px solid #d4c8a8;border-radius:6px;background:#fff;box-sizing:border-box;"></textarea>
			<div style="margin-top:12px;">
				<button type="submit" class="button" style="background:#01696f;color:#fff;border-color:#01696f;">Submit review</button>
			</div>
		</form>
		<?php
	}

	/**
	 * @param WC_Order $order
	 * @return int[]
	 */
	private static function order_seller_ids( WC_Order $order ): array {
		if ( ! function_exists( 'tnm_get_order_item_seller_id' ) ) {
			return array();
		}
		$out = array();
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$sid = (int) tnm_get_order_item_seller_id( $item );
			if ( $sid > 0 ) {
				$out[ $sid ] = true;
			}
		}
		return array_map( 'intval', array_keys( $out ) );
	}

	public static function handle_submit(): void {
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url() );
			exit;
		}
		$user_id   = (int) get_current_user_id();
		$order_id  = isset( $_POST['order_id'] ) ? (int) $_POST['order_id'] : 0;
		$seller_id = isset( $_POST['seller_id'] ) ? (int) $_POST['seller_id'] : 0;
		$rating    = isset( $_POST['rating'] ) ? (int) $_POST['rating'] : 0;
		$review    = isset( $_POST['review'] ) ? (string) wp_unslash( $_POST['review'] ) : '';

		$order = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof WC_Order || (int) $order->get_customer_id() !== $user_id ) {
			self::flash_and_back( $user_id, $order_id, 'You cannot review this order.', true, $order );
			return;
		}

		$nonce_action = 'tnm_leave_review_' . $order_id . '_' . $seller_id;
		if ( empty( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), $nonce_action ) ) {
			self::flash_and_back( $user_id, $order_id, 'Session expired, please refresh and try again.', true, $order );
			return;
		}

		if ( $rating < 1 || $rating > 5 || $seller_id <= 0 ) {
			self::flash_and_back( $user_id, $order_id, 'Please pick a rating between 1 and 5.', true, $order );
			return;
		}

		if ( ! class_exists( 'TNM_Social' ) ) {
			self::flash_and_back( $user_id, $order_id, 'Reviews are temporarily unavailable.', true, $order );
			return;
		}

		$result = TNM_Social::submit_review( $user_id, $seller_id, $rating, $review, $order_id );
		if ( is_wp_error( $result ) ) {
			self::flash_and_back( $user_id, $order_id, $result->get_error_message(), true, $order );
			return;
		}

		self::flash_and_back( $user_id, $order_id, 'Thanks for the review!', false, $order );
	}

	private static function flash_and_back( int $user_id, int $order_id, string $message, bool $is_error, ?WC_Order $order ): void {
		if ( $user_id && $order_id ) {
			set_transient(
				'tnm_review_flash_' . $user_id . '_' . $order_id,
				array( 'message' => $message, 'error' => $is_error ),
				MINUTE_IN_SECONDS * 5
			);
		}
		$redirect = $order ? $order->get_view_order_url() : wc_get_account_endpoint_url( 'orders' );
		wp_safe_redirect( $redirect );
		exit;
	}
}
