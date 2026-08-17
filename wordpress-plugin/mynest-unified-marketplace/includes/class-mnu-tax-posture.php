<?php
/**
 * MNU_Tax_Posture
 *
 * Declares and defends the marketplace's expected WooCommerce tax posture:
 *
 *   woocommerce_calc_taxes         = 'no'
 *   woocommerce_prices_include_tax = 'no'
 *   woocommerce_tax_based_on       = 'shipping'
 *   woocommerce_tax_display_shop   = 'excl'
 *   woocommerce_tax_display_cart   = 'excl'
 *
 * A marketplace sale ships from many different states and each seller is
 * responsible for their own sales-tax registration. Turning on Woo's
 * tax engine (or Stripe Tax in Live mode without a per-seller config)
 * would silently start charging buyers tax the marketplace can't remit
 * for. This class:
 *
 *   1. Provides one canonical description of the expected values.
 *   2. Detects drift on every request (cheap: options are always cached).
 *   3. Renders a Marketplace → Tax Posture admin page showing current vs
 *      expected, with a single "Restore expected posture" action.
 *   4. Runs daily via WP-Cron and emails admin when drift is found.
 *   5. Adds a persistent WooCommerce settings-page banner when the
 *      Stripe Tax connector for WooCommerce is switched into Live mode
 *      without an explicit acknowledgement, so the "marketplace-off"
 *      posture cannot be undone silently.
 *   6. Exposes GET /the-nest/v1/admin/tax-posture for dashboards and the
 *      recurring reconciliation report.
 *
 * @package MyNest_Unified_Marketplace
 * @since 3.7.91
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MNU_Tax_Posture {

	public const NS         = 'the-nest/v1';
	public const CRON_HOOK  = 'mnu_tax_posture_daily';
	public const ACK_OPTION = 'mnu_tax_posture_ack';
	public const PAGE_SLUG  = 'mnu-tax-posture';

	/**
	 * Expected values that make up the marketplace posture. Keeping this
	 * as a method so it can be filtered later without touching admin UI.
	 *
	 * @return array<string,string>
	 */
	public static function expected(): array {
		return apply_filters(
			'mnu_tax_posture_expected',
			array(
				'woocommerce_calc_taxes'         => 'no',
				'woocommerce_prices_include_tax' => 'no',
				'woocommerce_tax_based_on'       => 'shipping',
				'woocommerce_tax_display_shop'   => 'excl',
				'woocommerce_tax_display_cart'   => 'excl',
			)
		);
	}

	/**
	 * Wire hooks.
	 */
	public static function init(): void {
		add_action( 'admin_menu',    array( __CLASS__, 'register_menu' ), 60 );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
		add_action( 'admin_post_mnu_tax_posture_restore', array( __CLASS__, 'handle_restore' ) );
		add_action( 'admin_post_mnu_tax_posture_ack',     array( __CLASS__, 'handle_ack' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );

		add_action( self::CRON_HOOK, array( __CLASS__, 'run_daily_check' ) );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 15 * MINUTE_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Called from plugin deactivation.
	 */
	public static function deactivate(): void {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
		}
	}

	/* ---------------- Drift detection ---------------- */

	/**
	 * Read current tax posture from the database (no cache reset).
	 *
	 * @return array{expected:array<string,string>,current:array<string,string>,drift:array<int,array{option:string,expected:string,current:string}>,stripe_tax:array{present:bool,mode:string,live:bool},acknowledged:bool}
	 */
	public static function status(): array {
		$expected = self::expected();
		$current  = array();
		$drift    = array();
		foreach ( $expected as $opt => $want ) {
			$have          = (string) get_option( $opt, '' );
			$current[ $opt ] = $have;
			if ( $have !== $want ) {
				$drift[] = array(
					'option'   => $opt,
					'expected' => $want,
					'current'  => $have,
				);
			}
		}

		$stripe = self::stripe_tax_info();

		// Consider Stripe Tax in Live mode as unwanted drift unless the user
		// has explicitly acknowledged (see handle_ack). Sandbox is fine —
		// the bridge already has a Sandbox fallback.
		$ack = (string) get_option( self::ACK_OPTION, '' );
		$acknowledged = ( 'stripe-tax-live-' . self::stripe_tax_ack_fingerprint( $stripe ) === $ack );

		if ( $stripe['present'] && $stripe['live'] && ! $acknowledged ) {
			$drift[] = array(
				'option'   => 'stripe_tax_mode',
				'expected' => 'test/sandbox (or acknowledged)',
				'current'  => 'live',
			);
		}

		return array(
			'expected'     => $expected,
			'current'      => $current,
			'drift'        => $drift,
			'stripe_tax'   => $stripe,
			'acknowledged' => $acknowledged,
		);
	}

	/**
	 * Inspect the Stripe Tax for WooCommerce plugin state.
	 *
	 * @return array{present:bool,mode:string,live:bool}
	 */
	private static function stripe_tax_info(): array {
		$options_class = '\\Stripe\\StripeTaxForWooCommerce\\WordPress\\Options';
		if ( ! class_exists( $options_class ) ) {
			return array( 'present' => false, 'mode' => 'not_installed', 'live' => false );
		}
		$mode_type = is_callable( array( $options_class, 'get_mode_type' ) )
			? (int) $options_class::get_mode_type()
			: -1;
		$test_mode = defined( $options_class . '::MODE_TEST' )
			? (int) constant( $options_class . '::MODE_TEST' )
			: 1;
		$live      = ( $mode_type !== $test_mode ) && ( $mode_type !== -1 );
		if ( is_callable( array( $options_class, 'is_live_mode_enabled' ) ) ) {
			$live = $live && (bool) $options_class::is_live_mode_enabled();
		}
		return array(
			'present' => true,
			'mode'    => $live ? 'live' : 'test',
			'live'    => $live,
		);
	}

	private static function stripe_tax_ack_fingerprint( array $stripe ): string {
		return md5( ( $stripe['live'] ? 'live' : 'test' ) . '|' . ( $stripe['mode'] ?? '' ) );
	}

	/* ---------------- Restore action ---------------- */

	/**
	 * Push the expected values back into wp_options. Returns the count of
	 * options that were changed.
	 */
	public static function restore(): int {
		$expected = self::expected();
		$changed  = 0;
		foreach ( $expected as $opt => $want ) {
			$have = (string) get_option( $opt, '' );
			if ( $have !== $want ) {
				update_option( $opt, $want, true );
				$changed++;
			}
		}
		return $changed;
	}

	public static function handle_restore(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Insufficient permissions.' );
		}
		check_admin_referer( 'mnu_tax_posture_restore' );
		$changed = self::restore();
		wp_safe_redirect( add_query_arg(
			array( 'page' => self::PAGE_SLUG, 'restored' => $changed ),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	public static function handle_ack(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Insufficient permissions.' );
		}
		check_admin_referer( 'mnu_tax_posture_ack' );
		$stripe = self::stripe_tax_info();
		update_option( self::ACK_OPTION, 'stripe-tax-live-' . self::stripe_tax_ack_fingerprint( $stripe ), false );
		wp_safe_redirect( add_query_arg(
			array( 'page' => self::PAGE_SLUG, 'acknowledged' => 1 ),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/* ---------------- Admin page ---------------- */

	public static function register_menu(): void {
		add_submenu_page(
			'tnm-marketplace',
			'Tax Posture',
			'Tax Posture',
			'manage_woocommerce',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Insufficient permissions.' );
		}
		$state = self::status();
		$drift = $state['drift'];
		$restored     = isset( $_GET['restored'] ) ? (int) $_GET['restored'] : null;
		$acknowledged = isset( $_GET['acknowledged'] );
		?>
		<div class="wrap">
			<h1>Tax Posture</h1>
			<p class="description">
				ShopMyNest is a marketplace, so each seller is responsible for their own sales-tax registration.
				The marketplace itself must not collect or remit tax. This page defends that posture: if any of
				the WooCommerce options below drift from the expected values, or Stripe Tax is switched to Live mode,
				checkout could silently start charging buyers tax the marketplace cannot remit.
			</p>
			<?php if ( null !== $restored ) : ?>
				<div class="notice notice-success is-dismissible"><p>Restored <?php echo (int) $restored; ?> option<?php echo 1 === $restored ? '' : 's'; ?>.</p></div>
			<?php endif; ?>
			<?php if ( $acknowledged ) : ?>
				<div class="notice notice-success is-dismissible"><p>Stripe Tax Live-mode configuration acknowledged. The banner will only reappear if the mode changes again.</p></div>
			<?php endif; ?>

			<?php if ( empty( $drift ) ) : ?>
				<div class="notice notice-success"><p><strong>Tax posture is intact.</strong> All expected values match the current site configuration.</p></div>
			<?php else : ?>
				<div class="notice notice-error">
					<p><strong>Tax posture has drifted from expected.</strong> <?php echo count( $drift ); ?> issue<?php echo 1 === count( $drift ) ? '' : 's'; ?> to review.</p>
				</div>
			<?php endif; ?>

			<h2 class="title">Expected vs Current</h2>
			<table class="widefat striped">
				<thead>
					<tr><th>Option</th><th>Expected</th><th>Current</th><th>Status</th></tr>
				</thead>
				<tbody>
					<?php foreach ( $state['expected'] as $opt => $want ) : ?>
						<?php $have = (string) ( $state['current'][ $opt ] ?? '' ); $ok = ( $have === $want ); ?>
						<tr>
							<td><code><?php echo esc_html( $opt ); ?></code></td>
							<td><code><?php echo esc_html( $want ); ?></code></td>
							<td><code><?php echo esc_html( '' === $have ? '(empty)' : $have ); ?></code></td>
							<td>
								<?php if ( $ok ) : ?>
									<span style="color:#136a2e;">&#10003; expected</span>
								<?php else : ?>
									<span style="color:#a71b1b;">&#9888; drift</span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					<tr>
						<td><code>Stripe Tax for WooCommerce</code></td>
						<td><code>test/sandbox (or acknowledged live)</code></td>
						<td>
							<code>
							<?php if ( ! $state['stripe_tax']['present'] ) : ?>
								not installed
							<?php else : ?>
								<?php echo esc_html( $state['stripe_tax']['mode'] ); ?>
							<?php endif; ?>
							</code>
						</td>
						<td>
							<?php if ( ! $state['stripe_tax']['present'] || ! $state['stripe_tax']['live'] || $state['acknowledged'] ) : ?>
								<span style="color:#136a2e;">&#10003; ok</span>
							<?php else : ?>
								<span style="color:#a71b1b;">&#9888; live — needs acknowledgement</span>
							<?php endif; ?>
						</td>
					</tr>
				</tbody>
			</table>

			<h2 class="title" style="margin-top:2em;">Actions</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:1em;">
				<?php wp_nonce_field( 'mnu_tax_posture_restore' ); ?>
				<input type="hidden" name="action" value="mnu_tax_posture_restore" />
				<button type="submit" class="button button-primary" <?php echo empty( $drift ) ? 'disabled' : ''; ?>>Restore expected posture</button>
			</form>
			<?php if ( $state['stripe_tax']['present'] && $state['stripe_tax']['live'] && ! $state['acknowledged'] ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
					<?php wp_nonce_field( 'mnu_tax_posture_ack' ); ?>
					<input type="hidden" name="action" value="mnu_tax_posture_ack" />
					<button type="submit" class="button">Acknowledge Stripe Tax Live mode</button>
				</form>
				<p class="description">Acknowledge only after you've configured per-seller Stripe Tax collection or a manual remittance workflow.</p>
			<?php endif; ?>

			<h2 class="title" style="margin-top:2em;">REST</h2>
			<p><code>GET <?php echo esc_html( rest_url( self::NS . '/admin/tax-posture' ) ); ?></code></p>
			<p class="description">Requires <code>manage_woocommerce</code>. Used by the daily reconciliation digest.</p>
		</div>
		<?php
	}

	/* ---------------- Admin notices ---------------- */

	/**
	 * Show a banner on WC settings + our own admin pages when drift is found.
	 */
	public static function admin_notice(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return;
		}
		$allowed_screens = array( 'dashboard', 'plugins', 'woocommerce_page_wc-settings' );
		$allowed_prefix  = 'toplevel_page_tnm-marketplace';
		$show = in_array( $screen->id, $allowed_screens, true )
			|| 0 === strpos( $screen->id, 'marketplace_page_' )
			|| $screen->id === $allowed_prefix;
		if ( ! $show ) {
			return;
		}
		$state = self::status();
		if ( empty( $state['drift'] ) ) {
			return;
		}
		$url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		echo '<div class="notice notice-error"><p><strong>MyNest tax posture drift:</strong> '
			. (int) count( $state['drift'] )
			. ' setting'
			. ( 1 === count( $state['drift'] ) ? '' : 's' )
			. ' differ from the marketplace-off configuration. '
			. '<a href="' . esc_url( $url ) . '">Review and restore</a>.'
			. '</p></div>';
	}

	/* ---------------- REST ---------------- */

	public static function register_routes(): void {
		register_rest_route(
			self::NS,
			'/admin/tax-posture',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'rest_status' ),
				'permission_callback' => static function () {
					return current_user_can( 'manage_woocommerce' );
				},
			)
		);
	}

	public static function rest_status(): WP_REST_Response {
		return rest_ensure_response( self::status() );
	}

	/* ---------------- Cron ---------------- */

	public static function run_daily_check(): void {
		$state = self::status();
		if ( empty( $state['drift'] ) ) {
			return;
		}
		$to = get_option( 'admin_email' );
		if ( ! $to ) {
			return;
		}
		$lines = array(
			'MyNest tax posture drift was detected during the daily check.',
			'',
			'Expected marketplace-off configuration:',
		);
		foreach ( $state['expected'] as $opt => $want ) {
			$have = (string) ( $state['current'][ $opt ] ?? '' );
			$ok   = ( $have === $want ) ? 'OK' : 'DRIFT';
			$lines[] = sprintf( '  %s  %s = %s (expected %s)', $ok, $opt, ( '' === $have ? '(empty)' : $have ), $want );
		}
		$lines[] = '';
		$lines[] = 'Stripe Tax for WooCommerce: ' . ( $state['stripe_tax']['present'] ? $state['stripe_tax']['mode'] : 'not installed' )
			. ( $state['stripe_tax']['live'] && ! $state['acknowledged'] ? ' (LIVE, unacknowledged)' : '' );
		$lines[] = '';
		$lines[] = 'Review at ' . admin_url( 'admin.php?page=' . self::PAGE_SLUG );

		wp_mail( $to, 'MyNest: tax posture drift', implode( "\n", $lines ) );
	}
}

MNU_Tax_Posture::init();
