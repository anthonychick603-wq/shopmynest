<?php
/**
 * Abandoned-cart snapshots + one-shot reminder email.
 *
 * v3.13.2 — Captures the current WooCommerce cart contents for logged-in
 * buyers on every cart mutation, then a daily WP-Cron sweep emails a single
 * reminder for rows older than 24 hours that haven't been reminded or
 * dismissed. The mobile app reads /cart/abandoned to show a "You left N
 * items in your cart" banner on the Home screen.
 *
 * Data model (see class-mnu-install.php table `abandoned_carts`):
 *   user_id       PK — one row per buyer
 *   line_count    quick badge count for the mobile banner
 *   total_cents   snapshot cart total for the reminder email
 *   items_json    JSON array of { product_id, title, qty, image, unit_cents }
 *   updated_at    last time the cart was mutated
 *   reminded_at   set once when the reminder email goes out (never re-sent)
 *   dismissed_at  set when the buyer taps "dismiss" on the app banner
 *
 * Only logged-in users are captured — guest carts have no address to send
 * a reminder to and are out of scope for v1. Rows clear on order placement
 * (woocommerce_thankyou) and when the cart empties.
 *
 * @package MyNest\Marketplace
 */

defined( 'ABSPATH' ) || exit;

final class MNU_Abandoned_Carts {

	const NS         = 'the-nest/v1';
	const SWEEP_HOOK = 'mnu_abandoned_carts_sweep';
	const REMIND_AFTER = 24 * HOUR_IN_SECONDS;
	const MAX_BATCH  = 50;

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );

		// WooCommerce cart mutation hooks. `cart_updated` is the broadest —
		// it fires after Woo has finished recomputing totals. We also hook
		// item removed so an empty cart clears the row immediately without
		// waiting for the next total recalc.
		add_action( 'woocommerce_cart_updated', array( __CLASS__, 'snapshot_current_cart' ), 20 );
		add_action( 'woocommerce_add_to_cart', array( __CLASS__, 'snapshot_current_cart' ), 20 );
		add_action( 'woocommerce_cart_item_removed', array( __CLASS__, 'snapshot_current_cart' ), 20 );
		add_action( 'woocommerce_after_cart_item_quantity_update', array( __CLASS__, 'snapshot_current_cart' ), 20 );
		add_action( 'woocommerce_cart_emptied', array( __CLASS__, 'clear_current_user' ), 20 );
		// After an order is placed, drop the snapshot so we don't remind
		// the buyer about items they just paid for.
		add_action( 'woocommerce_thankyou', array( __CLASS__, 'clear_for_order' ), 20, 1 );

		// Daily cron for reminder emails.
		add_action( self::SWEEP_HOOK, array( __CLASS__, 'run_sweep' ) );
		add_action( 'init', array( __CLASS__, 'maybe_schedule_sweep' ) );
	}

	public static function maybe_schedule_sweep(): void {
		if ( ! wp_next_scheduled( self::SWEEP_HOOK ) ) {
			// Slightly offset from 00:00 to avoid piling up with other daily crons.
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::SWEEP_HOOK );
		}
	}

	/* ------------------------------------------------------------------ *
	 *  REST                                                              *
	 * ------------------------------------------------------------------ */

	public static function register_routes(): void {
		register_rest_route( self::NS, '/cart/abandoned', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_for_current_user' ),
			'permission_callback' => 'is_user_logged_in',
		) );
		register_rest_route( self::NS, '/cart/abandoned/dismiss', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'dismiss_for_current_user' ),
			'permission_callback' => 'is_user_logged_in',
		) );
	}

	public static function get_for_current_user(): WP_REST_Response {
		$user_id = get_current_user_id();
		$row     = self::fetch_row( $user_id );
		if ( ! $row || (int) $row->line_count <= 0 || ! empty( $row->dismissed_at ) ) {
			return rest_ensure_response( array( 'has_cart' => false ) );
		}
		$items = json_decode( (string) $row->items_json, true );
		if ( ! is_array( $items ) ) {
			$items = array();
		}
		return rest_ensure_response( array(
			'has_cart'    => true,
			'line_count'  => (int) $row->line_count,
			'total_cents' => (int) $row->total_cents,
			'items'       => $items,
			'updated_at'  => mysql2date( 'c', (string) $row->updated_at ),
		) );
	}

	public static function dismiss_for_current_user(): WP_REST_Response {
		global $wpdb;
		$user_id = get_current_user_id();
		$table   = tnm_table( 'abandoned_carts' );
		$now     = current_time( 'mysql' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET dismissed_at = %s WHERE user_id = %d", $now, $user_id ) );
		return rest_ensure_response( array( 'dismissed' => true ) );
	}

	/* ------------------------------------------------------------------ *
	 *  Capture                                                           *
	 * ------------------------------------------------------------------ */

	/**
	 * Snapshot the current logged-in user's cart into the abandoned_carts
	 * table. Guests are skipped. An empty cart clears any existing row.
	 */
	public static function snapshot_current_cart(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}
		$user_id = get_current_user_id();
		$cart    = WC()->cart;
		if ( $cart->is_empty() ) {
			self::clear_user( $user_id );
			return;
		}

		$items       = array();
		$line_count  = 0;
		$total_cents = 0;
		foreach ( $cart->get_cart() as $cart_item ) {
			$product = isset( $cart_item['data'] ) ? $cart_item['data'] : null;
			if ( ! $product instanceof WC_Product ) {
				continue;
			}
			$qty        = (int) ( $cart_item['quantity'] ?? 1 );
			$unit_cents = (int) round( ( (float) $product->get_price() ) * 100 );
			$line_total = $unit_cents * max( 1, $qty );
			$total_cents += $line_total;
			$line_count  += $qty;

			$image_url = '';
			$image_id  = (int) $product->get_image_id();
			if ( $image_id ) {
				$src = wp_get_attachment_image_src( $image_id, 'thumbnail' );
				if ( is_array( $src ) && ! empty( $src[0] ) ) {
					$image_url = (string) $src[0];
				}
			}

			$items[] = array(
				'product_id' => (int) $product->get_id(),
				'title'      => (string) $product->get_name(),
				'qty'        => $qty,
				'unit_cents' => $unit_cents,
				'image'      => $image_url,
				'permalink'  => (string) $product->get_permalink(),
			);
		}

		if ( 0 === $line_count ) {
			self::clear_user( $user_id );
			return;
		}

		self::upsert(
			$user_id,
			$line_count,
			$total_cents,
			$items
		);
	}

	/**
	 * Buyer just placed an order — drop their abandoned snapshot so they
	 * don't get a reminder for items already paid for. Called from the
	 * woocommerce_thankyou hook so it fires for both native and web
	 * checkout paths.
	 */
	public static function clear_for_order( $order_id ): void {
		$order_id = (int) $order_id;
		if ( ! $order_id ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$user_id = (int) $order->get_customer_id();
		if ( $user_id > 0 ) {
			self::clear_user( $user_id );
		}
	}

	public static function clear_current_user(): void {
		if ( is_user_logged_in() ) {
			self::clear_user( get_current_user_id() );
		}
	}

	/* ------------------------------------------------------------------ *
	 *  Cron sweep                                                        *
	 * ------------------------------------------------------------------ */

	public static function run_sweep(): void {
		global $wpdb;
		$table  = tnm_table( 'abandoned_carts' );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::REMIND_AFTER );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT user_id, line_count, total_cents, items_json, updated_at
			   FROM {$table}
			  WHERE reminded_at IS NULL
			    AND dismissed_at IS NULL
			    AND line_count > 0
			    AND updated_at <= %s
			  LIMIT %d",
			$cutoff,
			self::MAX_BATCH
		) );

		if ( ! $rows ) {
			return;
		}

		foreach ( $rows as $row ) {
			$user_id = (int) $row->user_id;
			$user    = get_user_by( 'id', $user_id );
			if ( ! $user || empty( $user->user_email ) ) {
				// Stamp anyway so we don't keep looking at this row forever.
				self::stamp_reminded( $user_id );
				continue;
			}
			$items = json_decode( (string) $row->items_json, true );
			if ( ! is_array( $items ) || 0 === count( $items ) ) {
				self::stamp_reminded( $user_id );
				continue;
			}
			self::send_reminder(
				$user,
				$items,
				(int) $row->total_cents,
				(int) $row->line_count
			);
			self::stamp_reminded( $user_id );
		}
	}

	private static function send_reminder( WP_User $user, array $items, int $total_cents, int $line_count ): void {
		$site_name = get_bloginfo( 'name' );
		$site_url  = home_url( '/' );
		$cart_url  = wc_get_cart_url();

		$subject = sprintf(
			/* translators: %s: site name */
			__( 'You left something in your %s cart', 'mynest-unified-marketplace' ),
			$site_name
		);

		$lines = array();
		foreach ( $items as $it ) {
			$title = isset( $it['title'] ) ? (string) $it['title'] : '';
			$qty   = isset( $it['qty'] ) ? (int) $it['qty'] : 1;
			$unit  = isset( $it['unit_cents'] ) ? (int) $it['unit_cents'] : 0;
			$lines[] = sprintf( '  • %s ×%d — %s', $title, $qty, self::fmt_cents( $unit * max( 1, $qty ) ) );
		}

		$body = sprintf(
			/* translators: 1: display name, 2: site name, 3: item list, 4: total, 5: cart URL */
			__(
				"Hi %1\$s,\n\nYou left %2\$d item(s) in your %3\$s cart:\n\n%4\$s\n\nCart total: %5\$s\n\nPick up where you left off:\n%6\$s\n\n– The %3\$s team",
				'mynest-unified-marketplace'
			),
			$user->display_name ?: $user->user_login,
			$line_count,
			$site_name,
			implode( "\n", $lines ),
			self::fmt_cents( $total_cents ),
			$cart_url
		);

		unset( $site_url );

		wp_mail( $user->user_email, $subject, $body );
	}

	/* ------------------------------------------------------------------ *
	 *  Helpers                                                           *
	 * ------------------------------------------------------------------ */

	private static function fetch_row( int $user_id ): ?object {
		global $wpdb;
		$table = tnm_table( 'abandoned_carts' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d", $user_id ) );
		return $row ?: null;
	}

	private static function upsert( int $user_id, int $line_count, int $total_cents, array $items ): void {
		global $wpdb;
		$table = tnm_table( 'abandoned_carts' );
		$now   = current_time( 'mysql' );
		$json  = wp_json_encode( $items );
		if ( ! is_string( $json ) ) {
			$json = '[]';
		}
		// Cart just changed — this is a "new" abandonment window, so we
		// clear reminded_at/dismissed_at. The buyer edits their cart,
		// we start the 24h clock over.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query( $wpdb->prepare(
			"INSERT INTO {$table} (user_id, line_count, total_cents, items_json, updated_at, reminded_at, dismissed_at)
			 VALUES (%d, %d, %d, %s, %s, NULL, NULL)
			 ON DUPLICATE KEY UPDATE
			   line_count   = VALUES(line_count),
			   total_cents  = VALUES(total_cents),
			   items_json   = VALUES(items_json),
			   updated_at   = VALUES(updated_at),
			   reminded_at  = NULL,
			   dismissed_at = NULL",
			$user_id,
			$line_count,
			$total_cents,
			$json,
			$now
		) );
	}

	private static function clear_user( int $user_id ): void {
		global $wpdb;
		$table = tnm_table( 'abandoned_carts' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->delete( $table, array( 'user_id' => $user_id ), array( '%d' ) );
	}

	private static function stamp_reminded( int $user_id ): void {
		global $wpdb;
		$table = tnm_table( 'abandoned_carts' );
		$now   = current_time( 'mysql' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET reminded_at = %s WHERE user_id = %d", $now, $user_id ) );
	}

	private static function fmt_cents( int $cents ): string {
		return '$' . number_format( $cents / 100, 2, '.', ',' );
	}
}

MNU_Abandoned_Carts::init();
