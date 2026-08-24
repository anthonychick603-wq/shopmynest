<?php
/**
 * Bluevine payout reconciliation view.
 *
 * v3.13.21 — adds WP Admin → MyNest → Reconciliation. Read-only page that
 * pairs Stripe payouts (the wire that lands money in Bluevine) with the
 * plugin's own ledger totals for the same window, and optionally overlays
 * a Bluevine transaction CSV so you can see which Stripe payouts have
 * actually arrived.
 *
 * Nothing on this page moves money or edits the ledger. The buttons are:
 *   - "Refresh from Stripe": re-fetches the last 30 Stripe payouts
 *   - "Upload Bluevine CSV": overlays confirmed deposits on the same table
 *   - "Clear CSV overlay": drops the uploaded file from the option store
 *
 * How matching works
 *  Stripe → Bluevine ties on: arrival_date (± window) + amount ± $0.01.
 *  Ledger side: for each Stripe payout, we sum ledger rows whose order was
 *  paid between (arrival_date − 7d) and (arrival_date − 1d), which brackets
 *  Stripe's normal 2-day settlement window. Best-effort; the goal is to
 *  spot mismatches, not book them.
 *
 * @package MyNest_Unified_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class MNU_Reconciliation_Admin {

	// v3.13.22 — renamed from 'mnu-reconciliation' (which collided with the
	// existing MNU_Reconciliation report page) to 'mnu-bluevine-recon'.
	public const MENU_SLUG   = 'mnu-bluevine-recon';
	public const PARENT_SLUG = 'tnm-marketplace';
	public const CSV_OPTION  = 'mnu_reconciliation_bluevine_csv';
	public const CACHE_KEY   = 'mnu_reconciliation_stripe_payouts_v1';
	public const CACHE_TTL   = 300; // 5 minutes; short so the page feels live.

	public static function init(): void {
		add_action( 'admin_menu',      array( __CLASS__, 'register_menu' ), 24 );
		add_action( 'admin_post_' . self::MENU_SLUG . '_upload_csv', array( __CLASS__, 'handle_upload_csv' ) );
		add_action( 'admin_post_' . self::MENU_SLUG . '_clear_csv', array( __CLASS__, 'handle_clear_csv' ) );
		add_action( 'admin_post_' . self::MENU_SLUG . '_refresh',   array( __CLASS__, 'handle_refresh' ) );
	}

	public static function register_menu(): void {
		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Bluevine Reconciliation', 'mynest-unified-marketplace' ),
			__( 'Bluevine Reconciliation', 'mynest-unified-marketplace' ),
			'manage_woocommerce',
			self::MENU_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/* --------------------------------------------------------------
	 * Stripe payout fetch
	 * -------------------------------------------------------------- */

	/**
	 * Return the last N Stripe payouts, oldest first. Cached briefly so the
	 * page renders instantly on refresh clicks that don't hit "Refresh".
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected static function stripe_payouts( int $limit = 30, bool $bust_cache = false ): array {
		if ( ! $bust_cache ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		if ( ! function_exists( 'mnu_native_stripe_get' ) ) {
			return array();
		}

		$limit    = max( 1, min( 100, $limit ) );
		$response = mnu_native_stripe_get( '/payouts?limit=' . $limit );
		if ( is_wp_error( $response ) ) {
			set_transient( self::CACHE_KEY . '_error', $response->get_error_message(), 60 );
			return array();
		}
		$rows = array();
		foreach ( (array) ( $response['data'] ?? array() ) as $payout ) {
			if ( ! is_array( $payout ) ) { continue; }
			$rows[] = array(
				'id'            => (string) ( $payout['id'] ?? '' ),
				'amount'        => (float) ( ( (int) ( $payout['amount'] ?? 0 ) ) / 100 ),
				'currency'      => strtoupper( (string) ( $payout['currency'] ?? 'usd' ) ),
				'arrival_date'  => (int) ( $payout['arrival_date'] ?? 0 ),
				'created'       => (int) ( $payout['created'] ?? 0 ),
				'status'        => (string) ( $payout['status'] ?? '' ),
				'method'        => (string) ( $payout['method'] ?? '' ),
				'type'          => (string) ( $payout['type'] ?? '' ),
				'destination'   => (string) ( $payout['destination'] ?? '' ),
				'description'   => (string) ( $payout['description'] ?? '' ),
				'statement_dsc' => (string) ( $payout['statement_descriptor'] ?? '' ),
			);
		}
		// Oldest first so the table reads top-to-bottom chronologically.
		usort( $rows, static function ( $a, $b ) {
			return ( $a['arrival_date'] ?? 0 ) <=> ( $b['arrival_date'] ?? 0 );
		} );
		set_transient( self::CACHE_KEY, $rows, self::CACHE_TTL );
		delete_transient( self::CACHE_KEY . '_error' );
		return $rows;
	}

	/* --------------------------------------------------------------
	 * Ledger sums for a payout window
	 * -------------------------------------------------------------- */

	/**
	 * Return the sum of ledger fields for orders paid between two timestamps.
	 * Used to tell the operator "your Stripe payout of $X on Aug 20 covered
	 * orders totalling $Y in ledger terms." Best-effort — Stripe payouts
	 * lump every charge that settled in the window, plus any refunds; small
	 * mismatches are normal (rounding, Stripe fees, adjustments).
	 *
	 * @return array{gross:float,platform_fee:float,seller_net:float,shipping_kept:float,orders:int}
	 */
	protected static function ledger_window_sum( int $start_ts, int $end_ts ): array {
		global $wpdb;
		if ( $end_ts <= $start_ts ) {
			return array( 'gross' => 0.0, 'platform_fee' => 0.0, 'seller_net' => 0.0, 'shipping_kept' => 0.0, 'orders' => 0 );
		}
		$table_ledger = tnm_table( 'ledger' );
		$start_mysql  = gmdate( 'Y-m-d H:i:s', $start_ts );
		$end_mysql    = gmdate( 'Y-m-d H:i:s', $end_ts );

		// Ledger has no paid_date column of its own; join to postmeta for
		// order paid_date. Simpler + reliable: pull distinct order_ids from
		// ledger and check each order's paid date via WC. In the common case
		// this touches at most a few dozen orders per window.
		$order_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT order_id FROM {$table_ledger} WHERE created_at BETWEEN %s AND %s",
			gmdate( 'Y-m-d H:i:s', $start_ts - DAY_IN_SECONDS ),
			gmdate( 'Y-m-d H:i:s', $end_ts + DAY_IN_SECONDS )
		) );

		$sum = array( 'gross' => 0.0, 'platform_fee' => 0.0, 'seller_net' => 0.0, 'shipping_kept' => 0.0, 'orders' => 0 );
		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( (int) $order_id );
			if ( ! $order ) { continue; }
			$paid = $order->get_date_paid();
			if ( ! $paid ) { continue; }
			$ts = $paid->getTimestamp();
			if ( $ts < $start_ts || $ts > $end_ts ) { continue; }
			$sum['orders']++;
			// Sum the ledger rows for this order.
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT gross, platform_fee, net, shipping, type FROM {$table_ledger} WHERE order_id=%d",
				(int) $order_id
			), ARRAY_A );
			foreach ( $rows as $row ) {
				if ( 'earning' === $row['type'] ) {
					$sum['gross']         += (float) $row['gross'];
					$sum['platform_fee']  += (float) $row['platform_fee'];
					$sum['seller_net']    += (float) $row['net'];
					$sum['shipping_kept'] += (float) $row['shipping'];
				}
			}
			// v3.8.0 platform-kept shipping lives on the order meta, not the
			// ledger. For legacy orders the shipping already appears on earning
			// rows; we do NOT read the meta there or we'd double-count.
			if ( '1' === (string) $order->get_meta( '_mnu_v380_model', true ) ) {
				$kept_cents = (int) $order->get_meta( '_mnu_platform_shipping_kept_cents', true );
				if ( $kept_cents > 0 ) {
					$sum['shipping_kept'] += $kept_cents / 100;
				}
			}
		}
		return $sum;
	}

	/* --------------------------------------------------------------
	 * Bluevine CSV parsing
	 * -------------------------------------------------------------- */

	/**
	 * Read the stored Bluevine CSV (if any) and return normalized rows.
	 * We're tolerant about column names because Bluevine's export headers
	 * have changed over the years. We try a few common variants.
	 *
	 * @return array<int,array{date:int,amount:float,description:string,raw:string}>
	 */
	protected static function bluevine_rows(): array {
		$blob = (string) get_option( self::CSV_OPTION, '' );
		if ( '' === $blob ) { return array(); }
		$rows = array();
		$lines = preg_split( '/\r\n|\n|\r/', $blob );
		if ( empty( $lines ) ) { return array(); }
		$header = str_getcsv( array_shift( $lines ) );
		$header = array_map( static function ( $h ) { return strtolower( trim( (string) $h ) ); }, $header );
		$idx = static function ( array $candidates ) use ( $header ) {
			foreach ( $candidates as $name ) {
				$pos = array_search( $name, $header, true );
				if ( false !== $pos ) { return (int) $pos; }
			}
			return -1;
		};
		$date_col   = $idx( array( 'date', 'transaction date', 'posted date', 'settlement date' ) );
		$amount_col = $idx( array( 'amount', 'credit', 'deposit', 'amount ($)' ) );
		$desc_col   = $idx( array( 'description', 'memo', 'details', 'transaction description' ) );
		if ( $date_col < 0 || $amount_col < 0 ) {
			return array(); // Unknown format; ignore silently.
		}
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) { continue; }
			$parts = str_getcsv( $line );
			$raw_amount = isset( $parts[ $amount_col ] ) ? preg_replace( '/[^0-9\.\-]/', '', (string) $parts[ $amount_col ] ) : '';
			if ( '' === $raw_amount ) { continue; }
			$amount = (float) $raw_amount;
			if ( $amount <= 0 ) { continue; } // deposits only
			$date_raw = isset( $parts[ $date_col ] ) ? (string) $parts[ $date_col ] : '';
			$ts       = strtotime( $date_raw );
			if ( ! $ts ) { continue; }
			$desc = $desc_col >= 0 && isset( $parts[ $desc_col ] ) ? (string) $parts[ $desc_col ] : '';
			$rows[] = array(
				'date'        => (int) $ts,
				'amount'      => $amount,
				'description' => $desc,
				'raw'         => $line,
			);
		}
		return $rows;
	}

	/**
	 * Find a Bluevine deposit matching a Stripe payout: amount ± $0.01 and
	 * arrival within ± window days. If more than one deposit matches, pick
	 * the closest by date; if none matches, return null.
	 *
	 * @param array<int,array{date:int,amount:float,description:string,raw:string}> $rows
	 * @return array{date:int,amount:float,description:string,raw:string}|null
	 */
	protected static function match_bluevine( float $amount, int $arrival_ts, array $rows, int $window_days = 3 ): ?array {
		if ( empty( $rows ) || $arrival_ts <= 0 ) { return null; }
		$window = $window_days * DAY_IN_SECONDS;
		$best   = null;
		$best_gap = PHP_INT_MAX;
		foreach ( $rows as $row ) {
			if ( abs( $row['amount'] - $amount ) > 0.01 ) { continue; }
			$gap = abs( $row['date'] - $arrival_ts );
			if ( $gap > $window ) { continue; }
			if ( $gap < $best_gap ) {
				$best_gap = $gap;
				$best     = $row;
			}
		}
		return $best;
	}

	/* --------------------------------------------------------------
	 * Actions
	 * -------------------------------------------------------------- */

	public static function handle_refresh(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( 'Forbidden' ); }
		check_admin_referer( self::MENU_SLUG . '_refresh' );
		self::stripe_payouts( 30, true );
		wp_safe_redirect( self::page_url( array( 'refreshed' => 1 ) ) );
		exit;
	}

	public static function handle_upload_csv(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( 'Forbidden' ); }
		check_admin_referer( self::MENU_SLUG . '_upload_csv' );
		if ( empty( $_FILES['bluevine_csv']['tmp_name'] ) || ! is_uploaded_file( $_FILES['bluevine_csv']['tmp_name'] ) ) {
			wp_safe_redirect( self::page_url( array( 'csv_error' => 'nofile' ) ) );
			exit;
		}
		$size = (int) ( $_FILES['bluevine_csv']['size'] ?? 0 );
		if ( $size <= 0 || $size > 5 * MB_IN_BYTES ) {
			wp_safe_redirect( self::page_url( array( 'csv_error' => 'size' ) ) );
			exit;
		}
		$contents = file_get_contents( $_FILES['bluevine_csv']['tmp_name'] );
		if ( false === $contents ) {
			wp_safe_redirect( self::page_url( array( 'csv_error' => 'read' ) ) );
			exit;
		}
		// Strip UTF-8 BOM if present.
		if ( substr( $contents, 0, 3 ) === "\xEF\xBB\xBF" ) {
			$contents = substr( $contents, 3 );
		}
		update_option( self::CSV_OPTION, $contents, false );
		wp_safe_redirect( self::page_url( array( 'csv_uploaded' => 1 ) ) );
		exit;
	}

	public static function handle_clear_csv(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( 'Forbidden' ); }
		check_admin_referer( self::MENU_SLUG . '_clear_csv' );
		delete_option( self::CSV_OPTION );
		wp_safe_redirect( self::page_url( array( 'csv_cleared' => 1 ) ) );
		exit;
	}

	/* --------------------------------------------------------------
	 * Render
	 * -------------------------------------------------------------- */

	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( 'Forbidden' ); }
		$payouts       = self::stripe_payouts( 30, false );
		$stripe_error  = get_transient( self::CACHE_KEY . '_error' );
		$bluevine_rows = self::bluevine_rows();
		$has_csv       = ! empty( $bluevine_rows );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Bluevine Payout Reconciliation', 'mynest-unified-marketplace' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Pairs Stripe payouts (money on its way to Bluevine) with the plugin\'s ledger totals for the same window. Nothing on this page moves money.', 'mynest-unified-marketplace' ) . '</p>';

		// Notices.
		if ( ! empty( $_GET['refreshed'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Refreshed Stripe payouts.', 'mynest-unified-marketplace' ) . '</p></div>';
		}
		if ( ! empty( $_GET['csv_uploaded'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Bluevine CSV uploaded. Overlay is active until you clear it.', 'mynest-unified-marketplace' ) . '</p></div>';
		}
		if ( ! empty( $_GET['csv_cleared'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Bluevine CSV overlay cleared.', 'mynest-unified-marketplace' ) . '</p></div>';
		}
		if ( ! empty( $_GET['csv_error'] ) ) {
			$msg = 'read' === $_GET['csv_error'] ? __( 'Could not read the uploaded CSV.', 'mynest-unified-marketplace' )
				: ( 'size' === $_GET['csv_error'] ? __( 'CSV must be under 5 MB.', 'mynest-unified-marketplace' )
				: __( 'No file was uploaded.', 'mynest-unified-marketplace' ) );
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}
		if ( $stripe_error ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Stripe API error:', 'mynest-unified-marketplace' ) . ' ' . esc_html( (string) $stripe_error ) . '</p></div>';
		}

		// Action bar.
		echo '<div style="display:flex;gap:12px;align-items:center;margin:12px 0 20px;flex-wrap:wrap;">';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:0;">';
		wp_nonce_field( self::MENU_SLUG . '_refresh' );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::MENU_SLUG . '_refresh' ) . '" />';
		echo '<button type="submit" class="button">' . esc_html__( 'Refresh from Stripe', 'mynest-unified-marketplace' ) . '</button>';
		echo '</form>';

		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:0;display:flex;gap:8px;align-items:center;">';
		wp_nonce_field( self::MENU_SLUG . '_upload_csv' );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::MENU_SLUG . '_upload_csv' ) . '" />';
		echo '<label for="bluevine_csv">' . esc_html__( 'Bluevine transactions CSV:', 'mynest-unified-marketplace' ) . '</label>';
		echo '<input type="file" name="bluevine_csv" id="bluevine_csv" accept=".csv,text/csv" required />';
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Upload', 'mynest-unified-marketplace' ) . '</button>';
		echo '</form>';

		if ( $has_csv ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:0;" onsubmit="return confirm(\'Clear the current Bluevine CSV overlay?\');">';
			wp_nonce_field( self::MENU_SLUG . '_clear_csv' );
			echo '<input type="hidden" name="action" value="' . esc_attr( self::MENU_SLUG . '_clear_csv' ) . '" />';
			echo '<button type="submit" class="button">' . esc_html__( 'Clear CSV overlay', 'mynest-unified-marketplace' ) . '</button>';
			echo '</form>';
			echo '<span class="description">' . sprintf(
				/* translators: %d rows in the uploaded CSV */
				esc_html__( 'Overlay active: %d Bluevine deposit(s) loaded.', 'mynest-unified-marketplace' ),
				count( $bluevine_rows )
			) . '</span>';
		}
		echo '</div>';

		if ( empty( $payouts ) ) {
			echo '<p><em>' . esc_html__( 'No Stripe payouts returned. Either none have been created yet, or the Stripe key on file is missing / test-mode.', 'mynest-unified-marketplace' ) . '</em></p>';
			echo '</div>';
			return;
		}

		echo '<table class="widefat striped" style="max-width:1400px">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Arrival', 'mynest-unified-marketplace' ) . '</th>';
		echo '<th>' . esc_html__( 'Stripe payout', 'mynest-unified-marketplace' ) . '</th>';
		echo '<th style="text-align:right;">' . esc_html__( 'Amount', 'mynest-unified-marketplace' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'mynest-unified-marketplace' ) . '</th>';
		echo '<th style="text-align:right;">' . esc_html__( 'Ledger gross', 'mynest-unified-marketplace' ) . '</th>';
		echo '<th style="text-align:right;">' . esc_html__( 'Seller net', 'mynest-unified-marketplace' ) . '</th>';
		echo '<th style="text-align:right;">' . esc_html__( 'Platform fee', 'mynest-unified-marketplace' ) . '</th>';
		echo '<th style="text-align:right;">' . esc_html__( 'Orders', 'mynest-unified-marketplace' ) . '</th>';
		if ( $has_csv ) {
			echo '<th>' . esc_html__( 'Bluevine', 'mynest-unified-marketplace' ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $payouts as $payout ) {
			$arrival = (int) $payout['arrival_date'];
			// Ledger window: (arrival − 7d) to (arrival − 1d) captures the
			// usual Stripe 2-day settlement plus a couple of days of slack
			// so recurring auto-payouts still line up.
			$window_end   = $arrival > 0 ? $arrival - DAY_IN_SECONDS : 0;
			$window_start = $arrival > 0 ? $arrival - 7 * DAY_IN_SECONDS : 0;
			$sum = $arrival > 0
				? self::ledger_window_sum( $window_start, $window_end )
				: array( 'gross' => 0.0, 'platform_fee' => 0.0, 'seller_net' => 0.0, 'shipping_kept' => 0.0, 'orders' => 0 );

			$bluevine = $has_csv ? self::match_bluevine( (float) $payout['amount'], $arrival, $bluevine_rows ) : null;

			$row_style = '';
			if ( $has_csv ) {
				$row_style = $bluevine ? 'background:#eafaf1;' : 'background:#fdecea;';
			}
			echo '<tr style="' . esc_attr( $row_style ) . '">';
			echo '<td>' . esc_html( $arrival ? gmdate( 'Y-m-d', $arrival ) : '—' ) . '</td>';
			echo '<td><code>' . esc_html( $payout['id'] ) . '</code>';
			if ( $payout['method'] ) {
				echo '<br><small>' . esc_html( ucfirst( $payout['method'] ) ) . '</small>';
			}
			echo '</td>';
			echo '<td style="text-align:right;"><strong>' . esc_html( tnm_money( $payout['amount'], $payout['currency'] ) ) . '</strong></td>';
			echo '<td>' . esc_html( $payout['status'] ) . '</td>';
			echo '<td style="text-align:right;">' . esc_html( tnm_money( $sum['gross'], $payout['currency'] ) ) . '</td>';
			echo '<td style="text-align:right;">' . esc_html( tnm_money( $sum['seller_net'], $payout['currency'] ) ) . '</td>';
			echo '<td style="text-align:right;">' . esc_html( tnm_money( $sum['platform_fee'], $payout['currency'] ) ) . '</td>';
			echo '<td style="text-align:right;">' . (int) $sum['orders'] . '</td>';
			if ( $has_csv ) {
				if ( $bluevine ) {
					echo '<td><span title="' . esc_attr( $bluevine['description'] ) . '">✓ '
						. esc_html( gmdate( 'Y-m-d', $bluevine['date'] ) )
						. '</span></td>';
				} else {
					echo '<td><span style="color:#a12c7b;">Not found</span></td>';
				}
			}
			echo '</tr>';
		}
		echo '</tbody></table>';

		// Footer notes.
		echo '<p class="description" style="margin-top:14px;max-width:900px;">';
		echo esc_html__( 'Ledger window: for each Stripe payout, we sum ledger rows for orders paid between (arrival − 7d) and (arrival − 1d). Stripe normally settles 2 business days after the charge, so small overlaps are expected. Ledger gross ≠ Stripe payout amount because Stripe deducts card-processing fees and rolls in refunds; use the ledger totals as a sanity check, not a to-the-cent tie.', 'mynest-unified-marketplace' );
		echo '</p>';
		if ( $has_csv ) {
			echo '<p class="description" style="max-width:900px;">';
			echo esc_html__( 'Bluevine match: same-amount deposit within ± 3 days of the Stripe arrival date. Missing matches (red rows) mean the Stripe payout has not landed in Bluevine yet — or the CSV is stale.', 'mynest-unified-marketplace' );
			echo '</p>';
		}

		echo '</div>';
	}

	/* --------------------------------------------------------------
	 * URL helper
	 * -------------------------------------------------------------- */

	protected static function page_url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::MENU_SLUG ), $args ), admin_url( 'admin.php' ) );
	}
}

MNU_Reconciliation_Admin::init();
