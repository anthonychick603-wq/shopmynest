<?php
/**
 * Admin menu: top-level "Nest Trust Suite" menu with Settings, Disputes
 * review, and Pro Sellers screens.
 *
 * @package MyNest_Trust_Suite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TNM_Trust_Admin {

	const CAP = 'manage_woocommerce';

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_settings_save' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_dispute_resolve' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_pro_seller_toggle' ) );
	}

	/**
	 * Register the top-level admin menu and its subpages.
	 */
	public static function register_menu() {
		add_menu_page(
			__( 'Nest Trust Suite', 'nest-trust' ),
			__( 'Nest Trust Suite', 'nest-trust' ),
			self::CAP,
			'nest-trust-settings',
			array( __CLASS__, 'render_settings_page' ),
			'dashicons-shield',
			58
		);

		add_submenu_page(
			'nest-trust-settings',
			__( 'Settings', 'nest-trust' ),
			__( 'Settings', 'nest-trust' ),
			self::CAP,
			'nest-trust-settings',
			array( __CLASS__, 'render_settings_page' )
		);

		add_submenu_page(
			'nest-trust-settings',
			__( 'Disputes', 'nest-trust' ),
			__( 'Disputes', 'nest-trust' ),
			self::CAP,
			'nest-trust-disputes',
			array( __CLASS__, 'render_disputes_page' )
		);

		add_submenu_page(
			'nest-trust-settings',
			__( 'Pro Sellers', 'nest-trust' ),
			__( 'Pro Sellers', 'nest-trust' ),
			self::CAP,
			'nest-trust-pro-sellers',
			array( __CLASS__, 'render_pro_sellers_page' )
		);
	}

	/**
	 * Handle the settings form submission (nonce + capability checked).
	 */
	public static function handle_settings_save() {
		if ( ! isset( $_POST['tnm_trust_settings_nonce'] ) ) {
			return;
		}

		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tnm_trust_settings_nonce'] ) ), 'tnm_trust_settings_save' ) ) {
			return;
		}

		$fields = array(
			'tnm_trust_dispute_claim_window_days'          => 'absint',
			'tnm_trust_dispute_min_wait_hours'              => 'absint',
			'tnm_trust_dispute_sla_days'                    => 'absint',
			'tnm_trust_badge_min_orders'                    => 'absint',
			'tnm_trust_badge_min_gmv'                        => 'floatval',
			'tnm_trust_badge_ontime_threshold'              => 'floatval',
			'tnm_trust_badge_rating_threshold'              => 'floatval',
			'tnm_trust_badge_response_threshold'            => 'floatval',
			'tnm_trust_badge_default_processing_days'       => 'absint',
			'tnm_trust_bundle_first_item_discount_pct'      => 'floatval',
			'tnm_trust_bundle_additional_item_discount_pct' => 'floatval',
			'tnm_trust_boost_price_3day'                      => 'floatval',
			'tnm_trust_boost_price_7day'                       => 'floatval',
			'tnm_trust_pro_seller_fee_discount_points'       => 'floatval',
		);

		foreach ( $fields as $option_name => $sanitizer ) {
			if ( isset( $_POST[ $option_name ] ) ) {
				$raw   = sanitize_text_field( wp_unslash( $_POST[ $option_name ] ) );
				$value = ( 'absint' === $sanitizer ) ? absint( $raw ) : (float) $raw;
				update_option( $option_name, $value );
			}
		}

		add_settings_error( 'tnm_trust_settings', 'tnm_trust_settings_saved', __( 'Settings saved.', 'nest-trust' ), 'success' );

		set_transient( 'tnm_trust_admin_notice', __( 'Settings saved.', 'nest-trust' ), 30 );
	}

	/**
	 * Handle admin dispute resolution form submission.
	 */
	public static function handle_dispute_resolve() {
		if ( ! isset( $_POST['tnm_trust_resolve_dispute_nonce'], $_POST['dispute_id'] ) ) {
			return;
		}

		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tnm_trust_resolve_dispute_nonce'] ) ), 'tnm_trust_resolve_dispute' ) ) {
			return;
		}

		$id   = absint( $_POST['dispute_id'] );
		$args = array(
			'status'          => isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '',
			'resolution_note' => isset( $_POST['resolution_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['resolution_note'] ) ) : '',
			'refund_amount'   => isset( $_POST['refund_amount'] ) ? (float) $_POST['refund_amount'] : 0,
		);

		require_once TNM_TRUST_DIR . 'includes/class-tnm-trust-disputes.php';
		$result = TNM_Trust_Disputes::resolve_dispute( $id, $args );

		if ( is_wp_error( $result ) ) {
			set_transient( 'tnm_trust_admin_notice', $result->get_error_message(), 30 );
		} else {
			set_transient( 'tnm_trust_admin_notice', __( 'Dispute resolved.', 'nest-trust' ), 30 );
		}
	}

	/**
	 * Handle the Pro Seller toggle form submission.
	 */
	public static function handle_pro_seller_toggle() {
		if ( ! isset( $_POST['tnm_trust_pro_seller_nonce'], $_POST['seller_id'] ) ) {
			return;
		}

		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tnm_trust_pro_seller_nonce'] ) ), 'tnm_trust_pro_seller_toggle' ) ) {
			return;
		}

		$seller_id = absint( $_POST['seller_id'] );
		$is_pro     = ! empty( $_POST['is_pro'] );

		require_once TNM_TRUST_DIR . 'includes/class-tnm-trust-boosts.php';
		TNM_Trust_Boosts::set_pro_seller( $seller_id, $is_pro );

		set_transient( 'tnm_trust_admin_notice', __( 'Pro Seller status updated.', 'nest-trust' ), 30 );
	}

	/**
	 * Render any pending admin notice set via transient.
	 */
	protected static function render_notice() {
		$notice = get_transient( 'tnm_trust_admin_notice' );
		if ( $notice ) {
			delete_transient( 'tnm_trust_admin_notice' );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $notice ) . '</p></div>';
		}
	}

	/**
	 * Render the Settings admin page.
	 */
	public static function render_settings_page() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'nest-trust' ) );
		}

		self::render_notice();

		$options = array(
			'tnm_trust_dispute_claim_window_days'          => array( __( 'Dispute claim window (days)', 'nest-trust' ), 100 ),
			'tnm_trust_dispute_min_wait_hours'              => array( __( 'Minimum wait after contacting seller (hours)', 'nest-trust' ), 48 ),
			'tnm_trust_dispute_sla_days'                    => array( __( 'Seller resolution SLA before escalation (days)', 'nest-trust' ), 5 ),
			'tnm_trust_badge_min_orders'                    => array( __( 'Badge: minimum completed orders (90d)', 'nest-trust' ), 5 ),
			'tnm_trust_badge_min_gmv'                        => array( __( 'Badge: minimum GMV (90d, $)', 'nest-trust' ), 300 ),
			'tnm_trust_badge_ontime_threshold'              => array( __( 'Badge: on-time shipping threshold (%)', 'nest-trust' ), 95 ),
			'tnm_trust_badge_rating_threshold'              => array( __( 'Badge: average rating threshold (out of 5)', 'nest-trust' ), 4.8 ),
			'tnm_trust_badge_response_threshold'            => array( __( 'Badge: response rate threshold (%, within 24h)', 'nest-trust' ), 95 ),
			'tnm_trust_badge_default_processing_days'       => array( __( 'Default processing time (days) if product does not set one', 'nest-trust' ), 3 ),
			'tnm_trust_bundle_first_item_discount_pct'      => array( __( 'Bundle shipping: first item discount (%)', 'nest-trust' ), 0 ),
			'tnm_trust_bundle_additional_item_discount_pct' => array( __( 'Bundle shipping: additional item discount (%)', 'nest-trust' ), 20 ),
			'tnm_trust_boost_price_3day'                      => array( __( 'Boost price — 3 day ($)', 'nest-trust' ), 5.00 ),
			'tnm_trust_boost_price_7day'                       => array( __( 'Boost price — 7 day ($)', 'nest-trust' ), 9.00 ),
			'tnm_trust_pro_seller_fee_discount_points'       => array( __( 'Pro Seller fee discount (percentage points)', 'nest-trust' ), 3.5 ),
		);
		?>
		<div class="wrap tnm-trust-admin-wrap">
			<h1><?php esc_html_e( 'Nest Trust Suite — Settings', 'nest-trust' ); ?></h1>
			<form method="post">
				<?php wp_nonce_field( 'tnm_trust_settings_save', 'tnm_trust_settings_nonce' ); ?>
				<table class="form-table" role="presentation">
					<tbody>
					<?php foreach ( $options as $name => $meta ) : ?>
						<tr>
							<th scope="row"><label for="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $meta[0] ); ?></label></th>
							<td>
								<input
									type="number"
									step="0.01"
									id="<?php echo esc_attr( $name ); ?>"
									name="<?php echo esc_attr( $name ); ?>"
									value="<?php echo esc_attr( get_option( $name, $meta[1] ) ); ?>"
									class="regular-text"
								/>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php submit_button( __( 'Save Settings', 'nest-trust' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the Disputes admin review page.
	 */
	public static function render_disputes_page() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'nest-trust' ) );
		}

		self::render_notice();

		require_once TNM_TRUST_DIR . 'includes/class-tnm-trust-disputes.php';
		$disputes = TNM_Trust_Disputes::list_disputes_for_user( get_current_user_id(), array( 'status' => 'open' ) );
		$all_open = array_filter(
			$disputes,
			function ( $d ) {
				return in_array( $d['status'], array( 'open', 'awaiting_seller', 'awaiting_buyer', 'escalated' ), true );
			}
		);
		?>
		<div class="wrap tnm-trust-admin-wrap">
			<h1><?php esc_html_e( 'Nest Trust Suite — Disputes', 'nest-trust' ); ?></h1>

			<?php if ( empty( $all_open ) ) : ?>
				<p><?php esc_html_e( 'No open disputes.', 'nest-trust' ); ?></p>
			<?php else : ?>
				<?php foreach ( $all_open as $dispute ) : ?>
					<div class="tnm-trust-admin-dispute-card">
						<h3>
							<?php
							printf(
								/* translators: 1: dispute ID, 2: order ID */
								esc_html__( 'Dispute #%1$d — Order #%2$d', 'nest-trust' ),
								absint( $dispute['id'] ),
								absint( $dispute['order_id'] )
							);
							?>
						</h3>
						<p>
							<strong><?php esc_html_e( 'Status:', 'nest-trust' ); ?></strong> <?php echo esc_html( $dispute['status'] ); ?><br />
							<strong><?php esc_html_e( 'Reason:', 'nest-trust' ); ?></strong> <?php echo esc_html( $dispute['reason'] ); ?><br />
							<strong><?php esc_html_e( 'Buyer ID:', 'nest-trust' ); ?></strong> <?php echo esc_html( $dispute['buyer_id'] ); ?><br />
							<strong><?php esc_html_e( 'Seller ID:', 'nest-trust' ); ?></strong> <?php echo esc_html( $dispute['seller_id'] ); ?>
						</p>
						<p><?php echo esc_html( $dispute['description'] ); ?></p>

						<form method="post">
							<?php wp_nonce_field( 'tnm_trust_resolve_dispute', 'tnm_trust_resolve_dispute_nonce' ); ?>
							<input type="hidden" name="dispute_id" value="<?php echo esc_attr( $dispute['id'] ); ?>" />
							<label>
								<?php esc_html_e( 'Resolution', 'nest-trust' ); ?>
								<select name="status">
									<option value="resolved_refund"><?php esc_html_e( 'Resolve — Full Refund', 'nest-trust' ); ?></option>
									<option value="resolved_partial"><?php esc_html_e( 'Resolve — Partial Refund', 'nest-trust' ); ?></option>
									<option value="resolved_no_refund"><?php esc_html_e( 'Resolve — No Refund', 'nest-trust' ); ?></option>
								</select>
							</label>
							<label>
								<?php esc_html_e( 'Refund amount ($)', 'nest-trust' ); ?>
								<input type="number" step="0.01" min="0" name="refund_amount" />
							</label>
							<label>
								<?php esc_html_e( 'Resolution note', 'nest-trust' ); ?>
								<textarea name="resolution_note" class="large-text"></textarea>
							</label>
							<?php submit_button( __( 'Resolve Dispute', 'nest-trust' ) ); ?>
						</form>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the Pro Sellers admin page (manual toggle per seller).
	 */
	public static function render_pro_sellers_page() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'nest-trust' ) );
		}

		self::render_notice();

		require_once TNM_TRUST_DIR . 'includes/class-tnm-trust-boosts.php';

		$sellers = get_users(
			array(
				'role__in' => array( 'tnm_seller', 'mynest_seller' ),
				'number'   => 100,
			)
		);
		?>
		<div class="wrap tnm-trust-admin-wrap">
			<h1><?php esc_html_e( 'Nest Trust Suite — Pro Sellers', 'nest-trust' ); ?></h1>
			<p>
				<?php esc_html_e( 'Manually flag sellers as "Pro Seller". This is a v1 manual toggle — no automated recurring billing is included. See README.md for how the other plugin can optionally apply a reduced platform fee for Pro sellers.', 'nest-trust' ); ?>
			</p>

			<?php if ( empty( $sellers ) ) : ?>
				<p><?php esc_html_e( 'No users with a seller role (tnm_seller / mynest_seller) were found.', 'nest-trust' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Seller', 'nest-trust' ); ?></th>
							<th><?php esc_html_e( 'Pro Seller?', 'nest-trust' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $sellers as $seller ) : ?>
						<tr>
							<td><?php echo esc_html( $seller->display_name ); ?> (#<?php echo esc_html( $seller->ID ); ?>)</td>
							<td><?php echo TNM_Trust_Boosts::is_pro_seller( $seller->ID ) ? esc_html__( 'Yes', 'nest-trust' ) : esc_html__( 'No', 'nest-trust' ); ?></td>
							<td>
								<form method="post" style="display:inline;">
									<?php wp_nonce_field( 'tnm_trust_pro_seller_toggle', 'tnm_trust_pro_seller_nonce' ); ?>
									<input type="hidden" name="seller_id" value="<?php echo esc_attr( $seller->ID ); ?>" />
									<input type="hidden" name="is_pro" value="<?php echo TNM_Trust_Boosts::is_pro_seller( $seller->ID ) ? '0' : '1'; ?>" />
									<button type="submit" class="button">
										<?php echo TNM_Trust_Boosts::is_pro_seller( $seller->ID ) ? esc_html__( 'Revoke Pro', 'nest-trust' ) : esc_html__( 'Grant Pro', 'nest-trust' ); ?>
									</button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
