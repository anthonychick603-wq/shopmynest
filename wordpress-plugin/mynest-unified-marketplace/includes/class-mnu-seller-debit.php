<?php
/**
 * MNU_Seller_Debit — admin-only endpoint that writes a negative ledger row
 * against a seller with a case id, reason code, and evidence string.
 *
 * v3.13.30 Fix #17. Before this existed, the only way to reduce a seller's
 * balance was a full refund of a specific order. That doesn't cover:
 *   - Off-order chargeback offsets
 *   - Recovery from a SNAD payout we shouldn't have paid
 *   - Manual accounting adjustments (postage clawback outside auto-label)
 *
 * The endpoint writes into the same ledger table as earnings/refunds so
 * balance calculations, batch payout SUMs, and reconciliation views all
 * see it automatically.
 *
 * @package MyNestUnifiedMarketplace
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MNU_Seller_Debit {

	public const ACTION      = 'mnu_seller_debit';
	public const NONCE       = 'mnu_seller_debit_nonce';
	public const MAX_DEBIT   = 50000; // $500.00 cap unless the mnu_allow_debt filter overrides.

	/** Allowed reason codes. Free-text 'reason' is stored in the note. */
	public const REASONS = array(
		'snad'                => 'SNAD (item not as described payout claw-back)',
		'fraud'               => 'Fraud recovery',
		'chargeback_offset'   => 'Chargeback offset',
		'manual_adjustment'   => 'Manual accounting adjustment',
	);

	public static function init(): void {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_post' ) );
	}

	/**
	 * Render the debit form fragment. Called by MNU_Payouts_Admin.
	 */
	public static function render_form( int $seller_id ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$post_url = admin_url( 'admin-post.php' );
		?>
		<form method="post" action="<?php echo esc_url( $post_url ); ?>" class="mnu-seller-debit-form" style="display:inline-block;margin:0;">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
			<input type="hidden" name="seller_id" value="<?php echo esc_attr( (string) $seller_id ); ?>" />
			<details style="margin:8px 0;">
				<summary style="cursor:pointer;color:#a00;"><?php esc_html_e( 'Debit seller\u2026', 'mynest-unified-marketplace' ); ?></summary>
				<div style="padding:8px;background:#fff5f5;border:1px solid #f5c2c2;border-radius:4px;margin-top:6px;">
					<p style="margin:0 0 6px;">
						<label>
							<?php esc_html_e( 'Amount ($)', 'mynest-unified-marketplace' ); ?><br>
							<input type="number" step="0.01" min="0.01" max="500.00" name="amount" required style="width:120px;" />
						</label>
					</p>
					<p style="margin:0 0 6px;">
						<label>
							<?php esc_html_e( 'Reason', 'mynest-unified-marketplace' ); ?><br>
							<select name="reason_code" required>
								<option value="">&mdash;</option>
								<?php foreach ( self::REASONS as $code => $label ) : ?>
									<option value="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
					</p>
					<p style="margin:0 0 6px;">
						<label>
							<?php esc_html_e( 'Case ID (required)', 'mynest-unified-marketplace' ); ?><br>
							<input type="text" name="case_id" required maxlength="60" style="width:220px;" placeholder="e.g. CB-2026-08-24-001" />
						</label>
					</p>
					<p style="margin:0 0 6px;">
						<label>
							<?php esc_html_e( 'Evidence / notes (min 20 chars, required)', 'mynest-unified-marketplace' ); ?><br>
							<textarea name="evidence" required minlength="20" maxlength="1000" rows="3" style="width:100%;"></textarea>
						</label>
					</p>
					<p style="margin:0;">
						<button type="submit" class="button button-secondary" onclick="return confirm('<?php echo esc_js( __( 'Debit this seller? A negative ledger row will be created immediately.', 'mynest-unified-marketplace' ) ); ?>');">
							<?php esc_html_e( 'Debit seller', 'mynest-unified-marketplace' ); ?>
						</button>
					</p>
				</div>
			</details>
		</form>
		<?php
	}

	/**
	 * admin_post handler. Writes a single negative-net ledger row of type
	 * 'adjustment_debit_{reason_code}' and notifies the seller.
	 */
	public static function handle_post(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'mynest-unified-marketplace' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::NONCE );

		$seller_id   = isset( $_POST['seller_id'] ) ? (int) $_POST['seller_id'] : 0;
		$amount_raw  = isset( $_POST['amount'] ) ? (float) wp_unslash( $_POST['amount'] ) : 0.0;
		$reason_code = isset( $_POST['reason_code'] ) ? sanitize_key( wp_unslash( $_POST['reason_code'] ) ) : '';
		$case_id     = isset( $_POST['case_id'] ) ? sanitize_text_field( wp_unslash( $_POST['case_id'] ) ) : '';
		$evidence    = isset( $_POST['evidence'] ) ? sanitize_textarea_field( wp_unslash( $_POST['evidence'] ) ) : '';

		$errors = array();
		if ( $seller_id <= 0 || ! get_userdata( $seller_id ) ) {
			$errors[] = __( 'Unknown seller.', 'mynest-unified-marketplace' );
		}
		if ( $amount_raw <= 0 ) {
			$errors[] = __( 'Amount must be greater than zero.', 'mynest-unified-marketplace' );
		}
		if ( ! isset( self::REASONS[ $reason_code ] ) ) {
			$errors[] = __( 'Invalid reason code.', 'mynest-unified-marketplace' );
		}
		if ( strlen( $case_id ) < 3 ) {
			$errors[] = __( 'Case id is required (min 3 chars).', 'mynest-unified-marketplace' );
		}
		if ( strlen( $evidence ) < 20 ) {
			$errors[] = __( 'Evidence must be at least 20 characters.', 'mynest-unified-marketplace' );
		}

		// Integer-cents cap. Filter can raise the limit or allow debt.
		$amount_cents = (int) round( $amount_raw * 100 );
		$max_cents    = (int) apply_filters( 'mnu_seller_debit_max_cents', self::MAX_DEBIT, $seller_id, $reason_code );
		$allow_debt   = (bool) apply_filters( 'mnu_allow_debt', false, $seller_id, $reason_code );

		if ( ! $allow_debt ) {
			// Cap at the seller's currently available balance so we never
			// leave them owing money without an explicit filter opt-in.
			$available_cents = self::seller_available_cents( $seller_id );
			if ( $amount_cents > $available_cents ) {
				$errors[] = sprintf(
					/* translators: %s: available balance dollars */
					__( 'Debit exceeds seller available balance ($%s). Enable mnu_allow_debt to override.', 'mynest-unified-marketplace' ),
					number_format( $available_cents / 100, 2 )
				);
			}
		}
		if ( $amount_cents > $max_cents ) {
			$errors[] = sprintf(
				/* translators: %s: max debit dollars */
				__( 'Debit exceeds per-action cap ($%s).', 'mynest-unified-marketplace' ),
				number_format( $max_cents / 100, 2 )
			);
		}

		if ( ! empty( $errors ) ) {
			self::redirect_back( implode( ' ', $errors ), 'error' );
		}

		global $wpdb;
		$ledger = tnm_table( 'ledger' );
		$now    = current_time( 'mysql', true );
		$admin  = wp_get_current_user();

		$type = 'adjustment_debit_' . $reason_code;
		$note = sprintf(
			"admin_debit case=%s reason=%s by=%d(%s) at=%s\nEvidence: %s",
			$case_id,
			$reason_code,
			(int) $admin->ID,
			$admin->user_login,
			$now,
			$evidence
		);

		// Insert a single row with net = -amount, status='available' so it
		// hits the seller balance immediately and gets picked up by the
		// next payout batch (or reduces the balance owed).
		$net = -( $amount_cents / 100 );

		$inserted = $wpdb->query( $wpdb->prepare(
			"INSERT INTO {$ledger}
			 ( seller_id, order_id, order_item_id, type, gross, platform_fee, tax, shipping, net,
			   currency, status, available_at, payout_id, note, created_at, updated_at )
			 VALUES ( %d, 0, 0, %s, 0, 0, 0, 0, %f, %s, 'available', %s, 0, %s, %s, %s )",
			$seller_id,
			$type,
			$net,
			get_woocommerce_currency() ?: 'USD',
			$now,
			$note,
			$now,
			$now
		) );

		if ( false === $inserted ) {
			self::redirect_back(
				sprintf( __( 'Database error while writing debit row: %s', 'mynest-unified-marketplace' ), $wpdb->last_error ),
				'error'
			);
		}

		$ledger_row_id = (int) $wpdb->insert_id;

		if ( class_exists( 'MNU_Ops' ) ) {
			MNU_Ops::notify_user(
				$seller_id,
				__( 'Adjustment to your seller balance', 'mynest-unified-marketplace' ),
				sprintf(
					"An adjustment of -$%s was recorded against your seller balance.\nReason: %s\nCase ID: %s\nIf you believe this is incorrect, please reply to support with the case id.",
					number_format( $amount_cents / 100, 2 ),
					self::REASONS[ $reason_code ] ?? $reason_code,
					$case_id
				),
				array(
					'ledger_row_id' => $ledger_row_id,
					'case_id'       => $case_id,
					'reason_code'   => $reason_code,
				)
			);
			MNU_Ops::notify_admin(
				sprintf( 'Debit posted \u2014 seller %d, -$%s', $seller_id, number_format( $amount_cents / 100, 2 ) ),
				sprintf( "Ledger row #%d\nReason: %s\nCase: %s\nBy: %d(%s)\nEvidence: %s",
					$ledger_row_id,
					$reason_code,
					$case_id,
					(int) $admin->ID,
					$admin->user_login,
					$evidence
				),
				array( 'seller_id' => $seller_id, 'ledger_row_id' => $ledger_row_id )
			);
		}

		self::redirect_back(
			sprintf(
				/* translators: 1: dollars, 2: seller id */
				__( 'Debit of $%1$s recorded against seller #%2$d.', 'mynest-unified-marketplace' ),
				number_format( $amount_cents / 100, 2 ),
				$seller_id
			),
			'success'
		);
	}

	/**
	 * Seller currently-available balance in cents. Mirrors the SUM in the
	 * payout-batch code path but scoped to a single seller.
	 */
	private static function seller_available_cents( int $seller_id ): int {
		global $wpdb;
		$ledger = tnm_table( 'ledger' );
		$sum = (float) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(net),0) FROM {$ledger}
			 WHERE seller_id=%d
			   AND status='available'
			   AND ( type='earning' OR type LIKE 'refund_%%' OR type LIKE 'adjustment%%' OR type='postage' )",
			$seller_id
		) );
		return (int) round( $sum * 100 );
	}

	private static function redirect_back( string $message, string $notice_type ): void {
		if ( class_exists( 'MNU_Payouts_Admin' ) && method_exists( 'MNU_Payouts_Admin', 'push_notice' ) ) {
			MNU_Payouts_Admin::push_notice( $notice_type, $message );
		}
		$ref = wp_get_referer();
		wp_safe_redirect( $ref ?: admin_url( 'admin.php?page=mnu-payouts' ) );
		exit;
	}
}

MNU_Seller_Debit::init();
