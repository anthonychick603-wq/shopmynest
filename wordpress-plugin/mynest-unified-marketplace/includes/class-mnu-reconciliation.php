<?php
/**
 * Marketplace reconciliation report.
 *
 * Scans recent WooCommerce orders and flags anything where the Stripe intent /
 * transfer state, the WooCommerce order state, and the marketplace ledger
 * disagree. The old orphan-charge audit was a one-shot script; this turns it
 * into a repeatable admin page + a nightly WP-Cron digest so nothing rots
 * silently.
 *
 * Categories detected:
 *
 *  - paid_no_charge         WC order is paid but has no Stripe intent id (or
 *                           the intent has no captured charge).
 *  - charge_no_transfers    Charge captured on a multi-seller order but the
 *                           split guardrail marks it 'held' (missing / held /
 *                           failed / unknown transfer records).
 *  - transfers_no_ledger    Transfers stamped on the order but no available /
 *                           paid ledger 'earning' rows exist for the sellers.
 *  - ledger_available_unsent
 *                           Ledger rows for the order are 'available' with an
 *                           empty stripe_transfer_id — a transfer that never
 *                           fired.
 *  - refund_no_reversal     Order status is 'refunded' but at least one
 *                           transfer meta row is still 'sent' (should be
 *                           'reversed').
 *  - seller_mismatch        _tnm_seller_ids on the order disagrees with the
 *                           seller ids that have ledger rows on the order.
 *
 * @package MyNest\Marketplace
 */

defined( 'ABSPATH' ) || exit;

final class MNU_Reconciliation {

	const MENU_SLUG          = 'mnu-reconciliation';
	const CRON_HOOK          = 'mnu_reconciliation_daily';
	const DIGEST_OPTION      = 'mnu_reconciliation_last_digest';
	const DIGEST_EMAIL_OPT   = 'mnu_reconciliation_digest_email';
	const DEFAULT_WINDOW_DAYS = 30;

	/** All discrepancy categories the report can produce. */
	const CATEGORIES = array(
		'paid_no_charge'          => 'Paid, no Stripe charge captured',
		'charge_no_transfers'     => 'Charge captured, transfers missing / held / failed',
		'transfers_no_ledger'     => 'Transfers issued, no matching ledger rows',
		'ledger_available_unsent' => 'Ledger available but no transfer id (stuck queue)',
		'refund_no_reversal'      => 'Refunded, transfers not reversed',
		'seller_mismatch'         => 'Order seller ids disagree with ledger sellers',
	);

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 25 );
		add_action( 'admin_post_mnu_reconciliation_export', array( __CLASS__, 'handle_export' ) );
		add_action( 'admin_post_mnu_reconciliation_settings', array( __CLASS__, 'handle_settings' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_daily_digest' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );

		// Schedule the daily digest on plugin load if not already scheduled.
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	public static function register_menu(): void {
		add_submenu_page(
			'tnm-marketplace',
			__( 'Reconciliation', 'mynest-unified-marketplace' ),
			__( 'Reconciliation', 'mynest-unified-marketplace' ),
			'manage_woocommerce',
			self::MENU_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Read-only REST endpoint so a future dashboard can consume the summary
	 * without opening wp-admin. Same permission model as the admin page.
	 */
	public static function register_routes(): void {
		register_rest_route( 'mnu/v1', '/admin/reconciliation', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'rest_summary' ),
			'permission_callback' => function () {
				return current_user_can( 'manage_woocommerce' );
			},
			'args' => array(
				'days' => array(
					'type'    => 'integer',
					'default' => self::DEFAULT_WINDOW_DAYS,
				),
			),
		) );
	}

	public static function rest_summary( WP_REST_Request $req ): WP_REST_Response {
		$days   = max( 1, min( 180, (int) $req->get_param( 'days' ) ) );
		$report = self::scan( $days );
		return rest_ensure_response( array(
			'window_days' => $days,
			'summary'     => $report['summary'],
			'total'       => count( $report['rows'] ),
		) );
	}

	/**
	 * Core scan. Returns an array with:
	 *   summary  => [ category => count ]
	 *   rows     => list of { order_id, order_number, order_status, total,
	 *                          intent_id, seller_ids, categories, issues,
	 *                          date_created }
	 *
	 * @param int $days Lookback window.
	 */
	public static function scan( int $days = self::DEFAULT_WINDOW_DAYS ): array {
		$days = max( 1, min( 180, $days ) );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		$orders = wc_get_orders( array(
			'limit'        => 500,
			'status'       => array( 'processing', 'completed', 'refunded', 'on-hold' ),
			'orderby'      => 'date',
			'order'        => 'DESC',
			'date_created' => '>' . $cutoff,
			'return'       => 'objects',
		) );

		$summary = array_fill_keys( array_keys( self::CATEGORIES ), 0 );
		$rows    = array();

		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			$eval = self::evaluate_order( $order );
			if ( ! $eval['categories'] ) {
				continue;
			}
			foreach ( $eval['categories'] as $cat ) {
				if ( isset( $summary[ $cat ] ) ) {
					$summary[ $cat ]++;
				}
			}
			$rows[] = $eval;
		}

		return array(
			'summary' => $summary,
			'rows'    => $rows,
		);
	}

	/**
	 * Evaluate one order. Returns the row shape the scan produces, with
	 * empty `categories` when everything reconciles.
	 */
	public static function evaluate_order( WC_Order $order ): array {
		global $wpdb;

		$categories = array();
		$issues     = array();

		$intent_id   = (string) $order->get_meta( '_stripe_intent_id', true );
		$is_paid     = $order->is_paid();
		$is_refunded = 'refunded' === $order->get_status();
		$transfers_raw = (string) $order->get_meta( '_mnu_seller_transfers', true );
		$transfers   = '' !== $transfers_raw ? (array) json_decode( $transfers_raw, true ) : array();

		// Sellers expected on the order — prefer per-line stamps, fall back
		// to the aggregated csv for older orders.
		$expected = array();
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof WC_Order_Item_Product ) {
				$sid = (int) $item->get_meta( '_tnm_seller_id', true );
				if ( $sid > 0 ) {
					$expected[ $sid ] = true;
				}
			}
		}
		if ( ! $expected ) {
			$csv = (string) $order->get_meta( '_tnm_seller_ids', true );
			foreach ( array_filter( array_map( 'absint', explode( ',', $csv ) ) ) as $sid ) {
				$expected[ (int) $sid ] = true;
			}
		}
		$expected_ids = array_keys( $expected );

		// (1) paid but no Stripe intent id at all.
		if ( $is_paid && '' === $intent_id ) {
			$categories[] = 'paid_no_charge';
			$issues[]     = 'Order is paid but has no _stripe_intent_id.';
		}

		// (2) multi-seller charge captured but guardrail says transfers are held.
		if ( $is_paid && count( $expected_ids ) >= 2 && function_exists( 'mnu_native_check_split_guardrail' ) ) {
			$g = mnu_native_check_split_guardrail( $order );
			if ( 'held' === ( $g['status'] ?? '' ) ) {
				$categories[] = 'charge_no_transfers';
				foreach ( (array) ( $g['issues'] ?? array() ) as $msg ) {
					$issues[] = (string) $msg;
				}
			}
		}

		// (3)+(4)+(6) inspect the ledger.
		$ledger_table = tnm_table( 'ledger' );
		$ledger_rows  = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT seller_id, status, net, stripe_transfer_id FROM {$ledger_table} WHERE order_id=%d AND type='earning'",
				$order->get_id()
			)
		);

		if ( $is_paid ) {
			$ledger_seller_ids = array();
			$has_available_unsent = false;
			foreach ( $ledger_rows as $row ) {
				$sid = (int) $row->seller_id;
				$ledger_seller_ids[ $sid ] = true;
				if ( 'available' === (string) $row->status && '' === (string) $row->stripe_transfer_id && (float) $row->net > 0 ) {
					$has_available_unsent = true;
				}
			}

			if ( count( $expected_ids ) >= 1 && ! $ledger_seller_ids ) {
				// Only surface this when the order has actual per-line seller stamps
				// AND transfers have been recorded — the "true orphan" case.
				if ( $transfers ) {
					$categories[] = 'transfers_no_ledger';
					$issues[]     = 'Transfers stamped on order but no earning ledger rows exist.';
				}
			}

			if ( $has_available_unsent ) {
				$categories[] = 'ledger_available_unsent';
				$issues[]     = 'One or more earning rows are available with no stripe_transfer_id (transfer never issued).';
			}

			$ledger_ids   = array_map( 'intval', array_keys( $ledger_seller_ids ) );
			$expected_int = array_map( 'intval', $expected_ids );
			$only_ledger  = array_diff( $ledger_ids, $expected_int );
			$only_order   = array_diff( $expected_int, $ledger_ids );
			// Only flag mismatch when the ledger has rows to compare against.
			if ( $ledger_seller_ids && ( $only_ledger || $only_order ) ) {
				$categories[] = 'seller_mismatch';
				if ( $only_ledger ) {
					$issues[] = 'Ledger rows for sellers not on the order: ' . implode( ', ', $only_ledger );
				}
				if ( $only_order ) {
					$issues[] = 'Order sellers with no ledger rows: ' . implode( ', ', $only_order );
				}
			}
		}

		// (5) refunded but transfers not reversed.
		if ( $is_refunded && $transfers ) {
			$unreversed = array();
			foreach ( $transfers as $sid => $entry ) {
				$status = is_array( $entry ) ? (string) ( $entry['status'] ?? '' ) : '';
				if ( 'sent' === $status ) {
					$unreversed[] = (int) $sid;
				}
			}
			if ( $unreversed ) {
				$categories[] = 'refund_no_reversal';
				$issues[]     = 'Refunded order still has un-reversed transfers for seller(s): ' . implode( ', ', $unreversed );
			}
		}

		// De-dupe & preserve order.
		$categories = array_values( array_unique( $categories ) );

		return array(
			'order_id'     => $order->get_id(),
			'order_number' => $order->get_order_number(),
			'order_status' => $order->get_status(),
			'total'        => (float) $order->get_total(),
			'currency'     => $order->get_currency(),
			'intent_id'    => $intent_id,
			'seller_ids'   => $expected_ids,
			'categories'   => $categories,
			'issues'       => $issues,
			'date_created' => $order->get_date_created() ? $order->get_date_created()->date( DATE_ATOM ) : '',
		);
	}

	// -------------------------------------------------------------------
	// Admin screen
	// -------------------------------------------------------------------

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$days = isset( $_GET['days'] ) ? max( 1, min( 180, (int) $_GET['days'] ) ) : self::DEFAULT_WINDOW_DAYS;
		$report = self::scan( $days );
		$digest_email = self::digest_email();

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Marketplace reconciliation', 'mynest-unified-marketplace' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Compares Stripe intents/transfers, WooCommerce order status, and the marketplace ledger. Anything listed below is out of sync and worth a manual look before payouts settle.', 'mynest-unified-marketplace' ); ?>
			</p>

			<form method="get" style="margin: 12px 0;">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>" />
				<label>
					<?php esc_html_e( 'Window (days):', 'mynest-unified-marketplace' ); ?>
					<input type="number" min="1" max="180" name="days" value="<?php echo esc_attr( $days ); ?>" />
				</label>
				<?php submit_button( __( 'Rescan', 'mynest-unified-marketplace' ), 'secondary', 'submit', false ); ?>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mnu_reconciliation_export&days=' . $days ), 'mnu_reconciliation_export' ) ); ?>">
					<?php esc_html_e( 'Export CSV', 'mynest-unified-marketplace' ); ?>
				</a>
			</form>

			<h2><?php esc_html_e( 'Summary', 'mynest-unified-marketplace' ); ?></h2>
			<table class="widefat striped" style="max-width: 720px;">
				<thead><tr><th><?php esc_html_e( 'Category', 'mynest-unified-marketplace' ); ?></th><th><?php esc_html_e( 'Orders flagged', 'mynest-unified-marketplace' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( self::CATEGORIES as $key => $label ) : ?>
					<tr>
						<td><code><?php echo esc_html( $key ); ?></code> — <?php echo esc_html( $label ); ?></td>
						<td><strong><?php echo (int) $report['summary'][ $key ]; ?></strong></td>
					</tr>
				<?php endforeach; ?>
					<tr>
						<th><?php esc_html_e( 'Total flagged orders (deduped)', 'mynest-unified-marketplace' ); ?></th>
						<th><?php echo (int) count( $report['rows'] ); ?></th>
					</tr>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Flagged orders', 'mynest-unified-marketplace' ); ?></h2>
			<?php if ( ! $report['rows'] ) : ?>
				<p><em><?php esc_html_e( 'Everything reconciles in this window.', 'mynest-unified-marketplace' ); ?></em></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
					<tr>
						<th><?php esc_html_e( 'Order', 'mynest-unified-marketplace' ); ?></th>
						<th><?php esc_html_e( 'Status', 'mynest-unified-marketplace' ); ?></th>
						<th><?php esc_html_e( 'Total', 'mynest-unified-marketplace' ); ?></th>
						<th><?php esc_html_e( 'Sellers', 'mynest-unified-marketplace' ); ?></th>
						<th><?php esc_html_e( 'Categories', 'mynest-unified-marketplace' ); ?></th>
						<th><?php esc_html_e( 'Issues', 'mynest-unified-marketplace' ); ?></th>
					</tr>
					</thead>
					<tbody>
					<?php foreach ( $report['rows'] as $row ) :
						$edit_link = admin_url( 'post.php?post=' . (int) $row['order_id'] . '&action=edit' );
					?>
						<tr>
							<td>
								<a href="<?php echo esc_url( $edit_link ); ?>">#<?php echo esc_html( $row['order_number'] ); ?></a>
								<?php if ( $row['intent_id'] ) : ?>
									<br><small><code><?php echo esc_html( $row['intent_id'] ); ?></code></small>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $row['order_status'] ); ?></td>
							<td><?php echo esc_html( wc_price( $row['total'], array( 'currency' => $row['currency'] ) ) ); ?></td>
							<td><?php echo esc_html( implode( ', ', array_map( 'intval', $row['seller_ids'] ) ) ); ?></td>
							<td>
								<?php foreach ( $row['categories'] as $c ) : ?>
									<span class="mnu-recon-cat"><?php echo esc_html( $c ); ?></span><br>
								<?php endforeach; ?>
							</td>
							<td><ul style="margin:0; padding-left:14px;">
								<?php foreach ( $row['issues'] as $issue ) : ?>
									<li><?php echo esc_html( $issue ); ?></li>
								<?php endforeach; ?>
							</ul></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<hr style="margin-top: 32px;">
			<h2><?php esc_html_e( 'Nightly digest', 'mynest-unified-marketplace' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Once per day the scan runs automatically and — if anything is flagged — emails a summary to this address.', 'mynest-unified-marketplace' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'mnu_reconciliation_settings' ); ?>
				<input type="hidden" name="action" value="mnu_reconciliation_settings" />
				<p>
					<label>
						<?php esc_html_e( 'Digest email:', 'mynest-unified-marketplace' ); ?>
						<input type="email" name="digest_email" value="<?php echo esc_attr( $digest_email ); ?>" class="regular-text" />
					</label>
				</p>
				<?php submit_button( __( 'Save digest email', 'mynest-unified-marketplace' ) ); ?>
			</form>
		</div>
		<?php
	}

	// -------------------------------------------------------------------
	// CSV export
	// -------------------------------------------------------------------

	public static function handle_export(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'forbidden', 403 );
		}
		check_admin_referer( 'mnu_reconciliation_export' );
		$days   = isset( $_GET['days'] ) ? max( 1, min( 180, (int) $_GET['days'] ) ) : self::DEFAULT_WINDOW_DAYS;
		$report = self::scan( $days );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="mnu-reconciliation-' . gmdate( 'Y-m-d' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'order_id', 'order_number', 'order_status', 'total', 'currency', 'intent_id', 'sellers', 'categories', 'issues', 'date_created' ) );
		foreach ( $report['rows'] as $row ) {
			fputcsv( $out, array(
				$row['order_id'],
				$row['order_number'],
				$row['order_status'],
				number_format( (float) $row['total'], 2, '.', '' ),
				$row['currency'],
				$row['intent_id'],
				implode( '|', array_map( 'intval', $row['seller_ids'] ) ),
				implode( '|', $row['categories'] ),
				implode( ' | ', $row['issues'] ),
				$row['date_created'],
			) );
		}
		fclose( $out );
		exit;
	}

	// -------------------------------------------------------------------
	// Digest settings + cron
	// -------------------------------------------------------------------

	public static function handle_settings(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'forbidden', 403 );
		}
		check_admin_referer( 'mnu_reconciliation_settings' );
		$email = isset( $_POST['digest_email'] ) ? sanitize_email( wp_unslash( $_POST['digest_email'] ) ) : '';
		update_option( self::DIGEST_EMAIL_OPT, $email, false );
		wp_safe_redirect( add_query_arg( array( 'page' => self::MENU_SLUG, 'saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function digest_email(): string {
		$saved = (string) get_option( self::DIGEST_EMAIL_OPT, '' );
		if ( $saved && is_email( $saved ) ) {
			return $saved;
		}
		return (string) get_option( 'admin_email', '' );
	}

	public static function run_daily_digest(): void {
		$report = self::scan( self::DEFAULT_WINDOW_DAYS );
		update_option( self::DIGEST_OPTION, array(
			'ran_at'  => gmdate( DATE_ATOM ),
			'summary' => $report['summary'],
			'flagged' => count( $report['rows'] ),
		), false );
		if ( ! $report['rows'] ) {
			return;
		}
		$email = self::digest_email();
		if ( ! $email ) {
			return;
		}

		$lines   = array();
		$lines[] = 'Marketplace reconciliation digest for ' . get_bloginfo( 'name' );
		$lines[] = 'Window: last ' . self::DEFAULT_WINDOW_DAYS . ' days';
		$lines[] = '';
		$lines[] = 'Flagged orders: ' . count( $report['rows'] );
		$lines[] = '';
		foreach ( self::CATEGORIES as $key => $label ) {
			if ( ! empty( $report['summary'][ $key ] ) ) {
				$lines[] = sprintf( '  - %-28s %d — %s', $key, (int) $report['summary'][ $key ], $label );
			}
		}
		$lines[] = '';
		$lines[] = 'Top 10 flagged orders:';
		foreach ( array_slice( $report['rows'], 0, 10 ) as $row ) {
			$lines[] = sprintf(
				'  #%s (%s) — %s — %s',
				$row['order_number'],
				$row['order_status'],
				implode( '/', $row['categories'] ),
				admin_url( 'post.php?post=' . (int) $row['order_id'] . '&action=edit' )
			);
		}
		$lines[] = '';
		$lines[] = 'Full report: ' . admin_url( 'admin.php?page=' . self::MENU_SLUG );

		wp_mail(
			$email,
			sprintf( '[%s] Reconciliation: %d flagged order(s)', get_bloginfo( 'name' ), count( $report['rows'] ) ),
			implode( "\n", $lines )
		);
	}

	/** Called from plugin deactivation to keep cron clean. */
	public static function deactivate(): void {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
		}
	}
}

MNU_Reconciliation::init();
