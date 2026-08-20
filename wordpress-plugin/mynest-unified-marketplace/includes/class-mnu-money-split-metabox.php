<?php
/**
 * MNU_Money_Split_Metabox
 *
 * Woo Admin order sidebar metabox that shows the full money split for an
 * order: buyer paid → Stripe fee → platform net → seller transfers →
 * refunds. Everything is sourced from meta the plugin already stamps
 * (no Stripe API calls on page load).
 *
 * Metabox is registered on both the legacy `shop_order` screen and the
 * new HPOS `woocommerce_page_wc-orders` screen.
 *
 * @package MyNest_Unified_Marketplace
 * @since 3.7.122.10
 *
 * v3.7.122.12 — show Stripe refund keep-fee (30¢) on refund rows so the true
 * platform loss is visible per refund.
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MNU_Money_Split_Metabox {

	public static function init(): void {
		add_action( 'add_meta_boxes', array( __CLASS__, 'register' ), 30, 2 );
	}

	/**
	 * Register on both legacy CPT and HPOS admin screens.
	 */
	public static function register( string $screen_id, $post_or_order ): void {
		$is_legacy = ( 'shop_order' === $screen_id );
		$is_hpos   = function_exists( 'wc_get_page_screen_id' )
			&& wc_get_page_screen_id( 'shop-order' ) === $screen_id;
		if ( ! $is_legacy && ! $is_hpos ) {
			return;
		}
		add_meta_box(
			'mnu_money_split',
			__( 'ShopMyNest — Money Split', 'mynest-unified-marketplace' ),
			array( __CLASS__, 'render' ),
			$screen_id,
			'side',
			'default'
		);
	}

	public static function render( $post_or_order ): void {
		$order = self::resolve_order( $post_or_order );
		if ( ! $order instanceof WC_Order ) {
			echo '<p><em>Order not loaded.</em></p>';
			return;
		}

		$intent_id = (string) $order->get_meta( '_thenest_stripe_payment_intent', true );
		if ( '' === $intent_id ) {
			echo '<p><em>This order has no Stripe payment intent (not paid via ShopMyNest native checkout).</em></p>';
			return;
		}

		$data = self::compute_split( $order );

		// Style locally to keep the metabox tidy without loading a stylesheet.
		?>
		<style>
			.mnu-ms { font-size: 12px; line-height: 1.5; }
			.mnu-ms table { width: 100%; border-collapse: collapse; }
			.mnu-ms td { padding: 2px 0; vertical-align: top; }
			.mnu-ms td.mnu-ms-val { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
			.mnu-ms .mnu-ms-sub td { color: #6b6b6b; padding-left: 12px; }
			.mnu-ms .mnu-ms-total td { border-top: 1px solid #dcdcde; padding-top: 6px; font-weight: 600; }
			.mnu-ms h4 { margin: 10px 0 4px; font-size: 12px; text-transform: uppercase; letter-spacing: 0.04em; color: #50575e; }
			.mnu-ms .mnu-ms-neg { color: #a12c7b; }
			.mnu-ms .mnu-ms-pos { color: #437a22; }
			.mnu-ms .mnu-ms-warn { color: #964219; }
			.mnu-ms .mnu-ms-tag { display: inline-block; padding: 1px 6px; border-radius: 3px; background: #f0f0f1; font-size: 10px; color: #50575e; text-transform: uppercase; }
			.mnu-ms .mnu-ms-tag-good { background: #e6f3e0; color: #437a22; }
			.mnu-ms .mnu-ms-tag-bad { background: #f7e0ee; color: #a12c7b; }
			.mnu-ms .mnu-ms-note { color: #6b6b6b; font-size: 11px; margin-top: 6px; }
			.mnu-ms code { background: #f6f7f7; padding: 0 3px; font-size: 11px; }
		</style>
		<div class="mnu-ms">
			<table>
				<tr>
					<td>Buyer paid</td>
					<td class="mnu-ms-val"><strong><?php echo esc_html( self::money( $data['buyer_paid_cents'] ) ); ?></strong></td>
				</tr>
				<tr class="mnu-ms-sub"><td>Product subtotal</td><td class="mnu-ms-val"><?php echo esc_html( self::money( $data['subtotal_cents'] ) ); ?></td></tr>
				<tr class="mnu-ms-sub"><td>Shipping</td><td class="mnu-ms-val"><?php echo esc_html( self::money( $data['shipping_cents'] ) ); ?></td></tr>
				<tr class="mnu-ms-sub"><td>Tax</td><td class="mnu-ms-val"><?php echo esc_html( self::money( $data['tax_cents'] ) ); ?></td></tr>

				<tr>
					<td>Stripe processing fee <span class="mnu-ms-tag" title="Estimated at 2.9% + 30¢ on the full charge total. Recovered from the seller via application_fee_amount top-up.">est</span></td>
					<td class="mnu-ms-val mnu-ms-neg">−<?php echo esc_html( self::money( $data['stripe_fee_cents'] ) ); ?></td>
				</tr>
				<tr>
					<td>Application fee (platform)</td>
					<td class="mnu-ms-val mnu-ms-pos">+<?php echo esc_html( self::money( $data['app_fee_cents'] ) ); ?></td>
				</tr>
				<tr class="mnu-ms-sub"><td>&nbsp;&nbsp;of which platform 8%</td><td class="mnu-ms-val"><?php echo esc_html( self::money( $data['platform_fee_cents'] ) ); ?></td></tr>
				<tr class="mnu-ms-sub"><td>&nbsp;&nbsp;of which Stripe fee recovery</td><td class="mnu-ms-val"><?php echo esc_html( self::money( $data['stripe_fee_recovered_cents'] ) ); ?></td></tr>

				<tr class="mnu-ms-total">
					<td>Platform net</td>
					<td class="mnu-ms-val <?php echo $data['platform_net_cents'] >= 0 ? 'mnu-ms-pos' : 'mnu-ms-warn'; ?>">
						<?php echo esc_html( self::money( $data['platform_net_cents'] ) ); ?>
					</td>
				</tr>
			</table>

			<?php if ( ! empty( $data['seller_transfers'] ) ) : ?>
				<h4>Seller transfers</h4>
				<table>
					<?php foreach ( $data['seller_transfers'] as $s ) : ?>
						<tr>
							<td>
								<strong><?php echo esc_html( $s['seller_name'] ); ?></strong>
								<?php if ( ! empty( $s['status_tag_class'] ) ) : ?>
									<span class="mnu-ms-tag <?php echo esc_attr( $s['status_tag_class'] ); ?>"><?php echo esc_html( $s['status_label'] ); ?></span>
								<?php endif; ?>
							</td>
							<td class="mnu-ms-val"><strong><?php echo esc_html( self::money( $s['net_cents'] ) ); ?></strong></td>
						</tr>
						<tr class="mnu-ms-sub"><td>gross</td><td class="mnu-ms-val"><?php echo esc_html( self::money( $s['gross_cents'] ) ); ?></td></tr>
						<tr class="mnu-ms-sub"><td>− platform fee</td><td class="mnu-ms-val">−<?php echo esc_html( self::money( $s['fee_cents'] ) ); ?></td></tr>
						<?php if ( $s['stripe_fee_share_cents'] > 0 ) : ?>
							<tr class="mnu-ms-sub"><td>− Stripe fee share</td><td class="mnu-ms-val">−<?php echo esc_html( self::money( $s['stripe_fee_share_cents'] ) ); ?></td></tr>
						<?php endif; ?>
						<?php if ( ! empty( $s['reversed_cents'] ) ) : ?>
							<tr class="mnu-ms-sub"><td>reversed (refund)</td><td class="mnu-ms-val mnu-ms-neg">−<?php echo esc_html( self::money( $s['reversed_cents'] ) ); ?></td></tr>
						<?php endif; ?>
						<?php if ( ! empty( $s['account_id'] ) ) : ?>
							<tr class="mnu-ms-sub">
								<td colspan="2"><code><?php echo esc_html( $s['account_id'] ); ?></code></td>
							</tr>
						<?php endif; ?>
					<?php endforeach; ?>
				</table>
			<?php endif; ?>

			<?php if ( ! empty( $data['refunds'] ) ) : ?>
				<h4>Refunds</h4>
				<table>
					<?php foreach ( $data['refunds'] as $r ) : ?>
						<tr>
							<td>
								<code><?php echo esc_html( $r['id'] ); ?></code>
								<span class="mnu-ms-tag <?php echo esc_attr( $r['origin_tag_class'] ); ?>"><?php echo esc_html( $r['origin_label'] ); ?></span>
							</td>
							<td class="mnu-ms-val mnu-ms-neg">−<?php echo esc_html( self::money( $r['amount_cents'] ) ); ?></td>
						</tr>
						<?php if ( ! empty( $r['stripe_keep_cents'] ) ) : ?>
							<tr class="mnu-ms-sub"><td>&nbsp;&nbsp;Stripe refund keep-fee</td><td class="mnu-ms-val mnu-ms-warn">−<?php echo esc_html( self::money( (int) $r['stripe_keep_cents'] ) ); ?></td></tr>
						<?php endif; ?>
						<?php if ( ! empty( $r['status_line'] ) ) : ?>
							<tr class="mnu-ms-sub"><td colspan="2"><?php echo esc_html( $r['status_line'] ); ?></td></tr>
						<?php endif; ?>
					<?php endforeach; ?>
				</table>
			<?php endif; ?>

			<p class="mnu-ms-note">
				Stripe processing fee is an estimate (2.9% + 30¢ on the full charge). Actual fee is on the Stripe charge's balance transaction.
				<?php if ( '' !== $intent_id ) : ?>
					<br><a href="<?php echo esc_url( self::stripe_intent_url( $intent_id ) ); ?>" target="_blank" rel="noopener">Open in Stripe →</a>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}

	/* ---------------------- Data ---------------------- */

	/**
	 * @return array{
	 *   buyer_paid_cents:int, subtotal_cents:int, shipping_cents:int, tax_cents:int,
	 *   stripe_fee_cents:int, app_fee_cents:int, platform_fee_cents:int,
	 *   stripe_fee_recovered_cents:int, platform_net_cents:int,
	 *   seller_transfers:array<int, array<string,mixed>>,
	 *   refunds:array<int, array<string,mixed>>
	 * }
	 */
	private static function compute_split( WC_Order $order ): array {
		$buyer_paid = (int) round( (float) $order->get_total() * 100 );
		$subtotal   = (int) round( (float) $order->get_subtotal() * 100 );
		$shipping   = (int) round( (float) $order->get_shipping_total() * 100 );
		$tax        = (int) round(
			( (float) $order->get_total_tax() ) * 100
		);

		$stripe_fee_stamp  = (int) $order->get_meta( '_mnu_stripe_fee_estimate_cents', true );
		$platform_fee_stamp = (int) $order->get_meta( '_mnu_platform_fee_cents', true );
		$app_fee_stamp     = (int) $order->get_meta( '_mnu_application_fee_cents', true );

		// Fallback for pre-v3.7.122.10 orders (no stamps) — recompute best-effort.
		if ( 0 === $stripe_fee_stamp && function_exists( 'mnu_native_estimate_stripe_fee_cents' ) ) {
			$stripe_fee_stamp = mnu_native_estimate_stripe_fee_cents( $buyer_paid );
		}
		if ( 0 === $platform_fee_stamp ) {
			$splits = function_exists( 'mnu_native_seller_splits' ) ? mnu_native_seller_splits( $order ) : array();
			foreach ( $splits as $row ) {
				$platform_fee_stamp += (int) $row['fee_cents'];
			}
		}
		if ( 0 === $app_fee_stamp ) {
			// Pre-v3.7.122.10 the app fee was just the platform fee.
			$app_fee_stamp = $platform_fee_stamp;
		}
		$stripe_fee_recovered = max( 0, $app_fee_stamp - $platform_fee_stamp );

		$platform_net = $app_fee_stamp - $stripe_fee_stamp;

		// Per-seller breakdown.
		$transfers_raw   = $order->get_meta( '_mnu_seller_transfers', true );
		$transfers       = is_string( $transfers_raw ) ? json_decode( $transfers_raw, true ) : $transfers_raw;
		$transfers       = is_array( $transfers ) ? $transfers : array();
		$fee_shares_raw  = $order->get_meta( '_mnu_stripe_fee_seller_shares', true );
		$fee_shares      = is_string( $fee_shares_raw ) ? json_decode( $fee_shares_raw, true ) : $fee_shares_raw;
		$fee_shares      = is_array( $fee_shares ) ? $fee_shares : array();
		$splits_by_seller = function_exists( 'mnu_native_seller_splits' ) ? mnu_native_seller_splits( $order ) : array();

		$seller_rows = array();

		if ( ! empty( $transfers ) ) {
			// Multi-seller SCT path.
			foreach ( $transfers as $seller_id => $t ) {
				if ( ! is_array( $t ) ) {
					continue;
				}
				$sid   = (int) $seller_id;
				$split = $splits_by_seller[ $sid ] ?? array( 'gross_cents' => 0, 'fee_cents' => 0, 'net_cents' => 0 );
				$seller_rows[] = self::format_seller_row(
					$sid,
					(int) $split['gross_cents'],
					(int) $split['fee_cents'],
					(int) ( $t['stripe_fee_share_cents'] ?? ( $fee_shares[ (string) $sid ] ?? 0 ) ),
					(int) ( $t['net_cents'] ?? 0 ),
					(int) ( $t['reversed_cents'] ?? 0 ),
					(string) ( $t['status'] ?? '' )
				);
			}
		} elseif ( ! empty( $splits_by_seller ) ) {
			// Single-seller destination charge path. There's exactly one seller.
			$sid          = (int) array_key_first( $splits_by_seller );
			$split        = $splits_by_seller[ $sid ];
			$share        = (int) ( $fee_shares[ (string) $sid ] ?? $stripe_fee_stamp );
			// Seller's transferred net = gross − platform fee − Stripe fee share.
			$net_transfer = max( 0, (int) $split['gross_cents'] - (int) $split['fee_cents'] - $share );
			$seller_rows[] = self::format_seller_row(
				$sid,
				(int) $split['gross_cents'],
				(int) $split['fee_cents'],
				$share,
				$net_transfer,
				0,
				'destination_charge'
			);
		}

		// Refunds — combine WC refunds + guardrail records.
		$refund_rows = array();
		$recovered_raw = $order->get_meta( '_mnu_dashboard_refund_recovered', true );
		$recovered     = is_string( $recovered_raw ) ? json_decode( $recovered_raw, true ) : $recovered_raw;
		$recovered     = is_array( $recovered ) ? $recovered : array();

		foreach ( $order->get_refunds() as $wc_refund ) {
			$rid    = (string) $wc_refund->get_refund_id();
			$cents  = (int) round( abs( (float) $wc_refund->get_amount() ) * 100 );
			$refund_rows[] = array(
				'id'                => 'WC #' . $rid,
				'amount_cents'      => $cents,
				'stripe_keep_cents' => self::stripe_refund_keep_fee_cents( $cents ),
				'origin_label'      => 'via plugin',
				'origin_tag_class'  => 'mnu-ms-tag-good',
				'status_line'       => (string) $wc_refund->get_reason(),
			);
		}
		foreach ( $recovered as $refund_id => $entry ) {
			$cents = (int) ( $entry['refund_cents'] ?? $entry['fee_refund_cents'] ?? 0 );
			$refund_rows[] = array(
				'id'                => (string) $refund_id,
				'amount_cents'      => $cents,
				'stripe_keep_cents' => self::stripe_refund_keep_fee_cents( $cents ),
				'origin_label'      => 'dashboard · guardrail',
				'origin_tag_class'  => 'mnu-ms-tag-bad',
				'status_line'       => (string) ( $entry['status'] ?? '' ),
			);
		}

		return array(
			'buyer_paid_cents'           => $buyer_paid,
			'subtotal_cents'             => $subtotal,
			'shipping_cents'             => $shipping,
			'tax_cents'                  => $tax,
			'stripe_fee_cents'           => $stripe_fee_stamp,
			'app_fee_cents'              => $app_fee_stamp,
			'platform_fee_cents'         => $platform_fee_stamp,
			'stripe_fee_recovered_cents' => $stripe_fee_recovered,
			'platform_net_cents'         => $platform_net,
			'seller_transfers'           => $seller_rows,
			'refunds'                    => $refund_rows,
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function format_seller_row( int $seller_id, int $gross_cents, int $fee_cents, int $stripe_fee_share_cents, int $net_cents, int $reversed_cents, string $status ): array {
		$user       = get_userdata( $seller_id );
		$seller_name = $user ? ( $user->display_name ?: $user->user_login ) : ( '#' . $seller_id );
		$account_id  = class_exists( 'MNU_Connect' ) ? (string) MNU_Connect::account_id( $seller_id ) : '';

		$status_map = array(
			'sent'              => array( 'sent',       'mnu-ms-tag-good' ),
			'held'              => array( 'held',       'mnu-ms-tag-bad'  ),
			'failed'            => array( 'failed',     'mnu-ms-tag-bad'  ),
			'destination_charge'=> array( 'auto-routed','mnu-ms-tag-good' ),
		);
		$tag = $status_map[ $status ] ?? array( $status, '' );

		return array(
			'seller_name'            => $seller_name,
			'account_id'             => $account_id,
			'gross_cents'            => $gross_cents,
			'fee_cents'              => $fee_cents,
			'stripe_fee_share_cents' => $stripe_fee_share_cents,
			'net_cents'              => $net_cents,
			'reversed_cents'         => $reversed_cents,
			'status_label'           => $tag[0],
			'status_tag_class'       => $tag[1],
		);
	}

	/* ---------------------- Helpers ---------------------- */

	private static function resolve_order( $post_or_order ): ?WC_Order {
		if ( $post_or_order instanceof WC_Order ) {
			return $post_or_order;
		}
		if ( is_object( $post_or_order ) && isset( $post_or_order->ID ) ) {
			$o = wc_get_order( (int) $post_or_order->ID );
			return $o instanceof WC_Order ? $o : null;
		}
		return null;
	}

	private static function money( int $cents ): string {
		$sign   = $cents < 0 ? '−' : '';
		$abs    = abs( $cents );
		return $sign . '$' . number_format( $abs / 100, 2 );
	}

	private static function stripe_intent_url( string $intent_id ): string {
		return 'https://dashboard.stripe.com/payments/' . rawurlencode( $intent_id );
	}

	/**
	 * Estimate Stripe's non-refundable keep-fee on a refund.
	 *
	 * As of 2023 Stripe returns the 2.9% percentage portion of the original
	 * processing fee on refund but keeps the 30¢ fixed portion. Platform
	 * eats that 30¢ per refunded charge. Filterable via
	 * `mnu_stripe_refund_keep_fee_cents` so we can adjust without a
	 * redeploy if Stripe's terms change.
	 *
	 * @param int $refund_cents Amount refunded to the buyer, in cents.
	 * @return int Cents Stripe keeps as the refund fee.
	 */
	private static function stripe_refund_keep_fee_cents( int $refund_cents ): int {
		$keep = $refund_cents > 0 ? 30 : 0;
		/**
		 * Filter Stripe's refund keep-fee estimate.
		 *
		 * @param int $keep_cents  Default 30¢ per refunded charge.
		 * @param int $refund_cents Refund amount in cents.
		 */
		$keep = (int) apply_filters( 'mnu_stripe_refund_keep_fee_cents', $keep, $refund_cents );
		return max( 0, $keep );
	}
}

MNU_Money_Split_Metabox::init();
