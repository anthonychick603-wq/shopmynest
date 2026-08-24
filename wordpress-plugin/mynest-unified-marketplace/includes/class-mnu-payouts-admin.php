<?php
/**
 * MNU_Payouts_Admin — v3.9.0 (Phase 3)
 *
 * WP Admin → Marketplace → Payouts.
 *
 * Under the v3.8.0 money model:
 *   • The platform collects 100 % of buyer payments to a single Stripe
 *     account; sellers are no longer connected via Stripe Connect.
 *   • Every ledger row (type=earning) is written with the new formula
 *     (net = gross * 0.90, available_at = paid + holding_days) and starts as
 *     status='pending'.  A cron flips rows to status='available' once
 *     their available_at is reached.
 *   • Payouts are then made manually by ACH from Bluevine business
 *     checking.  This screen is the operator console for that step.
 *
 * The screen lists sellers with a bank account on file.  The operator
 * ticks the sellers to include, taps “Copy payout lines” to paste the
 * batch into Bluevine one line at a time, then taps “Mark batch paid”
 * to flip the seller's available ledger rows to status='paid' and
 * record a row in the new tnm_payout_batches audit table.
 *
 * All state changes are nonce-verified and require manage_woocommerce.
 * Bank digits are decrypted per row only when the copy payload is
 * generated (via MNU_Bank_Account::reveal_for_admin) and are never
 * echoed into the HTML of the table itself.
 */
defined( 'ABSPATH' ) || exit;

final class MNU_Payouts_Admin {

	public const MENU_SLUG   = 'mnu-payouts';
	public const PARENT_SLUG = 'tnm-marketplace';

	public static function init(): void {
		add_action( 'admin_menu',      array( __CLASS__, 'register_menu' ), 22 );
		add_action( 'admin_post_' . self::MENU_SLUG . '_mark_paid', array( __CLASS__, 'handle_mark_paid' ) );
		add_action( 'admin_post_' . self::MENU_SLUG . '_copy',      array( __CLASS__, 'handle_copy' ) );
	}

	public static function register_menu(): void {
		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Payouts', 'mynest-unified-marketplace' ),
			__( 'Payouts', 'mynest-unified-marketplace' ),
			'manage_woocommerce',
			self::MENU_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/* --------------------------------------------------------------
	 * Data
	 * -------------------------------------------------------------- */

	/**
	 * Return one row per seller who has a bank account on file, with the
	 * pending + available totals summed from the ledger.  Pending totals
	 * are informational (not yet payable).
	 *
	 * @return array<int,array{
	 *   seller_id:int,
	 *   display_name:string,
	 *   last4:string,
	 *   holder_name:string,
	 *   available:float,
	 *   pending:float,
	 *   available_rows:int,
	 *   updated_at:string
	 * }>
	 */
	public static function collect_rows(): array {
		global $wpdb;

		if ( ! class_exists( 'MNU_Bank_Account' ) ) {
			return array();
		}

		// Sellers with a bank on file are exactly the users with a
		// non-empty _mnu_bank_last4 meta value.  usermeta is the source
		// of truth — we don't cross-check role here because an admin can
		// also have bank details (see MNU_Bank_Account::rest_save).
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT u.ID AS seller_id,
				        u.display_name AS display_name,
				        um1.meta_value AS last4,
				        um2.meta_value AS holder_name,
				        um3.meta_value AS updated_at
				 FROM {$wpdb->users} u
				 INNER JOIN {$wpdb->usermeta} um1
				         ON um1.user_id = u.ID AND um1.meta_key = %s
				 LEFT JOIN  {$wpdb->usermeta} um2
				         ON um2.user_id = u.ID AND um2.meta_key = %s
				 LEFT JOIN  {$wpdb->usermeta} um3
				         ON um3.user_id = u.ID AND um3.meta_key = %s
				 WHERE um1.meta_value <> ''
				 ORDER BY u.display_name ASC, u.ID ASC",
				MNU_Bank_Account::META_LAST4,
				MNU_Bank_Account::META_HOLDER,
				MNU_Bank_Account::META_UPDATED
			),
			ARRAY_A
		);

		if ( ! $rows ) {
			return array();
		}

		$ledger  = tnm_table( 'ledger' );
		$ids     = array_map( 'intval', array_column( $rows, 'seller_id' ) );
		$in      = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// v3.13.28 — Aggregate available and pending balances across ALL
		// settlement-affecting types, not only earnings. Previously we
		// filtered to type='earning' which excluded refund_<id> adjustment
		// rows (created with negative net) written by
		// TNM_Ledger::record_refund(). That meant a refunded seller could
		// still be paid the full pre-refund amount via manual ACH.
		//
		// The v3.13.27 audit found there is no code path that "applies the
		// refund to the earning row" and no separate seller_debt table —
		// the exclusion was based on a stale comment. Include earning +
		// refund_% + adjustment% rows so SUM(net) reflects true owed
		// balance; if it goes negative, treat the seller as having a debt
		// balance (they cannot receive a payout until it's cleared).
		$sql = "SELECT seller_id,
		               COALESCE(SUM(CASE WHEN status='available' THEN net ELSE 0 END),0) AS available_total,
		               COALESCE(SUM(CASE WHEN status='pending'   THEN net ELSE 0 END),0) AS pending_total,
		               COALESCE(SUM(CASE WHEN status='available' AND type='earning' THEN 1 ELSE 0 END),0) AS available_rows
		        FROM {$ledger}
		        WHERE seller_id IN ($in)
		          AND ( type='earning' OR type LIKE 'refund_%' OR type LIKE 'adjustment%' OR type='postage' )
		        GROUP BY seller_id";
		$totals_raw = $wpdb->get_results( $wpdb->prepare( $sql, $ids ), ARRAY_A );
		$totals     = array();
		foreach ( $totals_raw as $t ) {
			$totals[ (int) $t['seller_id'] ] = $t;
		}

		$out = array();
		foreach ( $rows as $r ) {
			$sid                 = (int) $r['seller_id'];
			$t                   = $totals[ $sid ] ?? array();
			$out[]               = array(
				'seller_id'      => $sid,
				'display_name'   => (string) $r['display_name'],
				'last4'          => (string) $r['last4'],
				'holder_name'    => (string) ( $r['holder_name'] ?? '' ),
				'available'      => (float)  ( $t['available_total'] ?? 0 ),
				'pending'        => (float)  ( $t['pending_total']   ?? 0 ),
				'available_rows' => (int)    ( $t['available_rows']  ?? 0 ),
				'updated_at'     => (string) ( $r['updated_at']      ?? '' ),
			);
		}
		return $out;
	}

	/* --------------------------------------------------------------
	 * Render
	 * -------------------------------------------------------------- */

	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'mynest-unified-marketplace' ) );
		}

		$notice = self::pop_notice();
		$rows   = self::collect_rows();

		$fmt      = static function ( $n ) { return '$' . number_format( (float) $n, 2 ); };
		$post_url = esc_url( admin_url( 'admin-post.php' ) );

		// Copy payload — full holder / routing / account per selected seller.
		// Rendered as JSON on a hidden <script> so the copy JS can pluck it
		// per checked row without hitting the network again.  The payload is
		// scoped to the page render (i.e. only sellers with a bank on file)
		// and only visible to manage_woocommerce users.
		$copy_map = array();
		foreach ( $rows as $r ) {
			$reveal = MNU_Bank_Account::reveal_for_admin( (int) $r['seller_id'] );
			if ( ! is_array( $reveal ) ) {
				continue;
			}
			$copy_map[ (int) $r['seller_id'] ] = array(
				'holder'  => $reveal['holder_name'],
				'routing' => $reveal['routing_number'],
				'account' => $reveal['account_number'],
			);
		}
		$copy_json = wp_json_encode( $copy_map );

		$batches = self::recent_batches( 10 );

		?>
		<div class="wrap mnu-payouts-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Marketplace Payouts', 'mynest-unified-marketplace' ); ?></h1>
			<p class="description" style="max-width:780px;">
				<?php esc_html_e( 'Sellers with a bank account on file. Available balance = earnings past the 7-day holding window. Tick the sellers to include in this batch, copy the payout lines into Bluevine, then click "Mark batch paid" to record the batch and flip those ledger rows to paid.', 'mynest-unified-marketplace' ); ?>
			</p>

			<?php if ( $notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible">
					<p><?php echo esc_html( $notice['message'] ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( empty( $rows ) ) : ?>
				<div class="notice notice-info inline"><p><?php esc_html_e( 'No sellers have a bank account on file yet.', 'mynest-unified-marketplace' ); ?></p></div>
			<?php else : ?>

			<form id="mnu-payouts-form" method="post" action="<?php echo $post_url; ?>">
				<?php wp_nonce_field( self::MENU_SLUG . '_mark_paid' ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::MENU_SLUG . '_mark_paid' ); ?>" />

				<div class="tablenav top">
					<div class="alignleft actions">
						<button type="button" class="button button-secondary" id="mnu-copy-btn">
							<?php esc_html_e( 'Copy payout lines', 'mynest-unified-marketplace' ); ?>
						</button>
						<span id="mnu-copy-status" style="margin-left:8px;color:#646970;"></span>
					</div>
					<div class="alignright actions">
						<label style="margin-right:8px;">
							<?php esc_html_e( 'Batch memo (optional):', 'mynest-unified-marketplace' ); ?>
							<input type="text" name="memo" maxlength="190" style="width:240px;" placeholder="<?php echo esc_attr__( 'e.g. 2026-08-21 Bluevine ACH', 'mynest-unified-marketplace' ); ?>" />
						</label>
						<button type="submit" class="button button-primary" id="mnu-mark-paid-btn"
						        onclick="return confirm('<?php echo esc_js( __( 'Flip the selected sellers\' available balances to paid? This creates a new payout batch record and cannot be undone.', 'mynest-unified-marketplace' ) ); ?>');">
							<?php esc_html_e( 'Mark batch paid', 'mynest-unified-marketplace' ); ?>
						</button>
					</div>
				</div>

				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<td class="manage-column column-cb check-column">
								<input type="checkbox" id="mnu-select-all" />
							</td>
							<th class="manage-column"><?php esc_html_e( 'Seller', 'mynest-unified-marketplace' ); ?></th>
							<th class="manage-column"><?php esc_html_e( 'Account', 'mynest-unified-marketplace' ); ?></th>
							<th class="manage-column" style="text-align:right;"><?php esc_html_e( 'Available', 'mynest-unified-marketplace' ); ?></th>
							<th class="manage-column" style="text-align:right;"><?php esc_html_e( 'Pending', 'mynest-unified-marketplace' ); ?></th>
							<th class="manage-column"><?php esc_html_e( 'Bank updated', 'mynest-unified-marketplace' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $r ) :
							$has_available = $r['available'] > 0.001;
							$has_reveal    = isset( $copy_map[ (int) $r['seller_id'] ] );
						?>
							<tr class="<?php echo $has_available ? 'mnu-payable' : 'mnu-zero'; ?>">
								<th scope="row" class="check-column">
									<input type="checkbox"
									       name="seller_ids[]"
									       value="<?php echo esc_attr( (int) $r['seller_id'] ); ?>"
									       class="mnu-row-cb"
									       data-available="<?php echo esc_attr( number_format( $r['available'], 2, '.', '' ) ); ?>"
									       <?php echo $has_available ? '' : 'disabled'; ?>
									       <?php echo $has_reveal    ? '' : 'disabled'; ?> />
								</th>
								<td>
									<strong><?php echo esc_html( $r['display_name'] ?: ( '#' . $r['seller_id'] ) ); ?></strong>
									<div style="color:#646970;font-size:12px;">
										<?php echo esc_html( $r['holder_name'] ); ?>
									</div>
								</td>
								<td><code>&bull;&bull;&bull;&bull;<?php echo esc_html( $r['last4'] ); ?></code>
									<?php if ( ! $has_reveal ) : ?>
										<div style="color:#b32d2e;font-size:12px;">
											<?php esc_html_e( 'Cannot decrypt (skipped)', 'mynest-unified-marketplace' ); ?>
										</div>
									<?php endif; ?>
								</td>
								<td style="text-align:right;font-variant-numeric:tabular-nums;">
									<strong><?php echo esc_html( $fmt( $r['available'] ) ); ?></strong>
									<?php if ( $r['available_rows'] > 0 ) : ?>
										<div style="color:#646970;font-size:12px;">
											<?php echo esc_html( sprintf(
												/* translators: %d = ledger row count */
												_n( '%d row', '%d rows', $r['available_rows'], 'mynest-unified-marketplace' ),
												$r['available_rows']
											) ); ?>
										</div>
									<?php endif; ?>
								</td>
								<td style="text-align:right;color:#646970;font-variant-numeric:tabular-nums;">
									<?php echo esc_html( $fmt( $r['pending'] ) ); ?>
								</td>
								<td style="color:#646970;">
									<?php echo esc_html( $r['updated_at'] ?: '\u2014' ); ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<div class="tablenav bottom" style="margin-top:10px;">
					<div class="alignleft" style="color:#646970;">
						<span id="mnu-batch-summary">0 sellers &middot; $0.00</span>
					</div>
				</div>
			</form>

			<script id="mnu-copy-map" type="application/json"><?php echo $copy_json; ?></script>
			<script>
			(function(){
				var selectAll = document.getElementById('mnu-select-all');
				var rows      = document.querySelectorAll('.mnu-row-cb');
				var summary   = document.getElementById('mnu-batch-summary');
				var copyBtn   = document.getElementById('mnu-copy-btn');
				var copyStat  = document.getElementById('mnu-copy-status');
				var copyMap   = {};
				try { copyMap = JSON.parse(document.getElementById('mnu-copy-map').textContent || '{}'); } catch (e) {}

				function refresh() {
					var count = 0, total = 0;
					rows.forEach(function(cb){
						if (cb.checked && !cb.disabled) {
							count++;
							total += parseFloat(cb.getAttribute('data-available') || '0');
						}
					});
					summary.textContent = count + ' seller' + (count === 1 ? '' : 's') + ' \u00b7 $' + total.toFixed(2);
				}

				if (selectAll) {
					selectAll.addEventListener('change', function(){
						rows.forEach(function(cb){ if (!cb.disabled) { cb.checked = selectAll.checked; } });
						refresh();
					});
				}
				rows.forEach(function(cb){ cb.addEventListener('change', refresh); });
				refresh();

				copyBtn.addEventListener('click', function(){
					var lines = [];
					rows.forEach(function(cb){
						if (!cb.checked || cb.disabled) return;
						var sid  = cb.value;
						var amt  = parseFloat(cb.getAttribute('data-available') || '0');
						var info = copyMap[sid];
						if (!info) return;
						lines.push(info.holder + ' | ' + info.routing + ' | ' + info.account + ' | $' + amt.toFixed(2));
					});
					if (!lines.length) {
						copyStat.textContent = '<?php echo esc_js( __( 'Nothing selected.', 'mynest-unified-marketplace' ) ); ?>';
						copyStat.style.color = '#b32d2e';
						return;
					}
					var text = lines.join('\n');
					var done = function(ok){
						copyStat.textContent = ok
							? ('<?php echo esc_js( __( 'Copied ', 'mynest-unified-marketplace' ) ); ?>' + lines.length + '<?php echo esc_js( __( ' line(s).', 'mynest-unified-marketplace' ) ); ?>')
							: '<?php echo esc_js( __( 'Copy failed — highlight & copy the fallback textarea.', 'mynest-unified-marketplace' ) ); ?>';
						copyStat.style.color = ok ? '#008a20' : '#b32d2e';
					};
					if (navigator.clipboard && navigator.clipboard.writeText) {
						navigator.clipboard.writeText(text).then(function(){ done(true); }, function(){ fallback(text, done); });
					} else {
						fallback(text, done);
					}
				});

				function fallback(text, done) {
					var ta = document.createElement('textarea');
					ta.value = text;
					ta.style.position = 'fixed'; ta.style.left = '-1000px';
					document.body.appendChild(ta);
					ta.select();
					try { done(document.execCommand('copy')); } catch (e) { done(false); }
					document.body.removeChild(ta);
				}
			})();
			</script>
			<?php endif; ?>

			<?php if ( ! empty( $batches ) ) : ?>
				<h2 style="margin-top:32px;"><?php esc_html_e( 'Recent payout batches', 'mynest-unified-marketplace' ); ?></h2>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Batch ID', 'mynest-unified-marketplace' ); ?></th>
							<th><?php esc_html_e( 'When', 'mynest-unified-marketplace' ); ?></th>
							<th><?php esc_html_e( 'Operator', 'mynest-unified-marketplace' ); ?></th>
							<th style="text-align:right;"><?php esc_html_e( 'Sellers', 'mynest-unified-marketplace' ); ?></th>
							<th style="text-align:right;"><?php esc_html_e( 'Total', 'mynest-unified-marketplace' ); ?></th>
							<th><?php esc_html_e( 'Memo', 'mynest-unified-marketplace' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $batches as $b ) : ?>
							<tr>
								<td><code>#<?php echo esc_html( (int) $b['id'] ); ?></code></td>
								<td><?php echo esc_html( $b['created_at'] ); ?></td>
								<td><?php echo esc_html( $b['operator'] ); ?></td>
								<td style="text-align:right;font-variant-numeric:tabular-nums;"><?php echo esc_html( (int) $b['seller_count'] ); ?></td>
								<td style="text-align:right;font-variant-numeric:tabular-nums;"><?php echo esc_html( $fmt( (float) $b['total_amount'] ) ); ?></td>
								<td style="color:#646970;"><?php echo esc_html( $b['memo'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<style>
			.mnu-payouts-wrap tr.mnu-zero td, .mnu-payouts-wrap tr.mnu-zero th { color:#8c8f94; }
		</style>
		<?php
	}

	/* --------------------------------------------------------------
	 * Actions
	 * -------------------------------------------------------------- */

	public static function handle_mark_paid(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'mynest-unified-marketplace' ) );
		}
		check_admin_referer( self::MENU_SLUG . '_mark_paid' );

		$seller_ids = isset( $_POST['seller_ids'] ) && is_array( $_POST['seller_ids'] )
			? array_values( array_unique( array_filter( array_map( 'intval', wp_unslash( $_POST['seller_ids'] ) ) ) ) )
			: array();
		$memo = isset( $_POST['memo'] ) ? sanitize_text_field( wp_unslash( $_POST['memo'] ) ) : '';

		if ( empty( $seller_ids ) ) {
			self::push_notice( 'error', __( 'No sellers were selected.', 'mynest-unified-marketplace' ) );
			wp_safe_redirect( self::menu_url() );
			exit;
		}

		global $wpdb;
		$ledger = tnm_table( 'ledger' );
		$now    = current_time( 'mysql', true );

		// Sum eligible net + count rows per seller BEFORE the flip so the
		// batch row records exactly what we paid.  We re-query each seller
		// with FOR UPDATE would be ideal, but WP $wpdb doesn't expose a
		// transaction wrapper; instead we do a bounded WHERE on the UPDATE
		// so late-arriving rows aren't accidentally swept into this batch.
		// v3.13.28 — The batch sum previously filtered to type='earning',
		// which meant refund_% / adjustment% rows with negative net were
		// ignored. A refunded seller could get paid their full pre-refund
		// amount. Now we sum ALL settlement types together, so refunds
		// naturally offset earnings. If the net is <= 0 the seller has a
		// zero-or-negative balance and is skipped from the batch.
		//
		// Wrap the whole thing in a DB transaction. Two admin tabs
		// submitting simultaneously would previously each read the same
		// available balance and each generate an ACH instruction; now the
		// second one either sees an already-flipped state (0 rows) and
		// gets a no-op, or blocks until the first commits.
		$as_of      = $now;
		$total_paid = 0.0;
		$paid_rows  = 0;
		$affected   = array();

		$wpdb->query( 'START TRANSACTION' );

		foreach ( $seller_ids as $sid ) {
			// Net balance across earning + refund + adjustment + postage rows.
			// FOR UPDATE keeps a concurrent tab from double-computing this.
			$sum = (float) $wpdb->get_var( $wpdb->prepare(
				"SELECT COALESCE(SUM(net),0) FROM {$ledger}
				 WHERE seller_id=%d
				   AND ( type='earning' OR type LIKE 'refund_%%' OR type LIKE 'adjustment%%' OR type='postage' )
				   AND status='available'
				   AND updated_at <= %s
				 FOR UPDATE",
				$sid, $as_of
			) );
			$cnt = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$ledger}
				 WHERE seller_id=%d
				   AND ( type='earning' OR type LIKE 'refund_%%' OR type LIKE 'adjustment%%' OR type='postage' )
				   AND status='available'
				   AND updated_at <= %s",
				$sid, $as_of
			) );
			if ( $sum > 0.001 && $cnt > 0 ) {
				$affected[ $sid ] = array( 'amount' => $sum, 'rows' => $cnt );
				$total_paid      += $sum;
				$paid_rows       += $cnt;
			}
		}

		if ( empty( $affected ) ) {
			$wpdb->query( 'ROLLBACK' );
			self::push_notice( 'error', __( 'Selected sellers have no available balance to pay out (refunds may have zeroed it out).', 'mynest-unified-marketplace' ) );
			wp_safe_redirect( self::menu_url() );
			exit;
		}

		// Insert batch row first so we can stamp payout_id onto the
		// ledger rows before flipping status.
		$batches_table = tnm_table( 'payout_batches' );
		$user          = wp_get_current_user();
		$wpdb->insert(
			$batches_table,
			array(
				'created_at'   => $now,
				'created_by'   => (int) ( $user->ID ?? 0 ),
				'seller_count' => count( $affected ),
				'total_amount' => $total_paid,
				'row_count'    => $paid_rows,
				'memo'         => $memo,
			),
			array( '%s', '%d', '%d', '%f', '%d', '%s' )
		);
		$batch_id = (int) $wpdb->insert_id;

		if ( ! $batch_id ) {
			$wpdb->query( 'ROLLBACK' );
			self::push_notice( 'error', __( 'Could not create the batch record; ledger was not modified.', 'mynest-unified-marketplace' ) );
			wp_safe_redirect( self::menu_url() );
			exit;
		}

		// v3.13.28 — Flip ALL settlement rows (earning + refund + adjustment
		// + postage) so refund offsets are consumed in the same batch that
		// paid the underlying earning. Otherwise the negative rows would sit
		// in "available" forever, silently reducing every FUTURE batch too.
		foreach ( $affected as $sid => $_info ) {
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$ledger}
				 SET status='paid', payout_id=%d, updated_at=%s
				 WHERE seller_id=%d
				   AND ( type='earning' OR type LIKE 'refund_%%' OR type LIKE 'adjustment%%' OR type='postage' )
				   AND status='available'
				   AND updated_at <= %s",
				$batch_id, $now, $sid, $as_of
			) );
		}

		$wpdb->query( 'COMMIT' );

		self::push_notice(
			'success',
			sprintf(
				/* translators: 1: batch id, 2: seller count, 3: total */
				__( 'Batch #%1$d recorded: %2$d sellers, $%3$s total. Ledger rows flipped to paid.', 'mynest-unified-marketplace' ),
				$batch_id,
				count( $affected ),
				number_format( $total_paid, 2 )
			)
		);
		wp_safe_redirect( self::menu_url() );
		exit;
	}

	public static function handle_copy(): void {
		// Copy is a client-side action; this endpoint exists only so a
		// future no-JS fallback can render the same payload server-side.
		wp_safe_redirect( self::menu_url() );
		exit;
	}

	/* --------------------------------------------------------------
	 * Batch history
	 * -------------------------------------------------------------- */

	protected static function recent_batches( int $limit = 10 ): array {
		global $wpdb;
		$table = tnm_table( 'payout_batches' );
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ),
			ARRAY_A
		);
		if ( ! $rows ) {
			return array();
		}
		$out = array();
		foreach ( $rows as $r ) {
			$u          = get_userdata( (int) ( $r['created_by'] ?? 0 ) );
			$out[]      = array(
				'id'           => (int)    $r['id'],
				'created_at'   => (string) $r['created_at'],
				'operator'     => $u ? $u->display_name : ( '#' . (int) ( $r['created_by'] ?? 0 ) ),
				'seller_count' => (int)    $r['seller_count'],
				'total_amount' => (float)  $r['total_amount'],
				'row_count'    => (int)    $r['row_count'],
				'memo'         => (string) $r['memo'],
			);
		}
		return $out;
	}

	/* --------------------------------------------------------------
	 * Helpers
	 * -------------------------------------------------------------- */

	protected static function menu_url(): string {
		return admin_url( 'admin.php?page=' . self::MENU_SLUG );
	}

	protected static function push_notice( string $type, string $message ): void {
		set_transient(
			'mnu_payouts_notice_' . get_current_user_id(),
			array( 'type' => $type, 'message' => $message ),
			60
		);
	}

	protected static function pop_notice(): ?array {
		$key = 'mnu_payouts_notice_' . get_current_user_id();
		$n   = get_transient( $key );
		if ( $n ) {
			delete_transient( $key );
			return $n;
		}
		return null;
	}
}

MNU_Payouts_Admin::init();
