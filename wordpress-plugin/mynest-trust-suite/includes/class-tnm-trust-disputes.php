<?php
/**
 * Feature 1 — Disputes / Buyer Protection.
 *
 * @package MyNest_Trust_Suite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TNM_Trust_Disputes {

	const REASONS = array( 'not_as_described', 'damaged', 'not_arrived', 'other' );

	const STATUSES = array( 'open', 'awaiting_seller', 'awaiting_buyer', 'escalated', 'resolved_refund', 'resolved_no_refund', 'resolved_partial' );

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_filter( 'tnm_trust_can_release_earnings', array( __CLASS__, 'filter_can_release_earnings' ), 10, 2 );
	}

	/**
	 * Public static integration point — other plugin's ledger-release cron
	 * (or any code) can call this directly to check for an open dispute.
	 *
	 * @param int $order_id Order ID.
	 * @return bool
	 */
	public static function has_open_dispute( $order_id ) {
		global $wpdb;

		$order_id = absint( $order_id );
		if ( ! $order_id ) {
			return false;
		}

		$table = TNM_Trust_DB::table( 'disputes' );

		$open_statuses = array( 'open', 'awaiting_seller', 'awaiting_buyer', 'escalated' );
		$placeholders  = implode( ',', array_fill( 0, count( $open_statuses ), '%s' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above, all values passed through prepare().
		$query = $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE order_id = %d AND status IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			array_merge( array( $order_id ), $open_statuses )
		);

		$count = $wpdb->get_var( $query );

		return absint( $count ) > 0;
	}

	/**
	 * Filter callback: `tnm_trust_can_release_earnings`.
	 * Defaults true unless an open dispute exists for that order.
	 *
	 * @param bool $can_release Whether earnings may currently be released.
	 * @param int  $order_id    Order ID.
	 * @return bool
	 */
	public static function filter_can_release_earnings( $can_release, $order_id ) {
		if ( self::has_open_dispute( $order_id ) ) {
			return false;
		}

		return $can_release;
	}

	/**
	 * Validate that the current user purchased (is the buyer on) the order.
	 *
	 * @param int $order_id Order ID.
	 * @param int $user_id  User ID.
	 * @return \WC_Order|null Order object if the user is the buyer, else null.
	 */
	protected static function get_order_for_buyer( $order_id, $user_id ) {
		$order = wc_get_order( absint( $order_id ) );
		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return null;
		}

		if ( absint( $order->get_customer_id() ) !== absint( $user_id ) ) {
			return null;
		}

		return $order;
	}

	/**
	 * Create a new dispute row. Returns array( success, data|WP_Error ).
	 *
	 * @param array $args Sanitized dispute fields.
	 * @return array|WP_Error
	 */
	public static function create_dispute( $args ) {
		global $wpdb;

		$buyer_id = absint( $args['buyer_id'] );
		$order_id = absint( $args['order_id'] );

		$order = self::get_order_for_buyer( $order_id, $buyer_id );
		if ( ! $order ) {
			return new WP_Error( 'tnm_trust_invalid_order', __( 'Order not found or does not belong to this user.', 'nest-trust' ), array( 'status' => 404 ) );
		}

		// Claim window check.
		$claim_window_days = absint( get_option( 'tnm_trust_dispute_claim_window_days', 100 ) );
		$order_date         = $order->get_date_created();
		if ( $order_date ) {
			$days_since_order = ( time() - $order_date->getTimestamp() ) / DAY_IN_SECONDS;
			if ( $days_since_order > $claim_window_days ) {
				return new WP_Error(
					'tnm_trust_claim_window_expired',
					sprintf(
						/* translators: %d: number of days */
						__( 'The claim window of %d days has expired for this order.', 'nest-trust' ),
						$claim_window_days
					),
					array( 'status' => 403 )
				);
			}
		}

		$reason = in_array( $args['reason'], self::REASONS, true ) ? $args['reason'] : 'other';

		$seller_id = TNM_Trust_Compat::get_order_seller_id( $order );

		$warning = null;
		$contacted_seller_at = null;

		if ( ! empty( $args['contacted_seller_at'] ) ) {
			$timestamp = strtotime( (string) $args['contacted_seller_at'] );
			if ( false !== $timestamp ) {
				$contacted_seller_at = gmdate( 'Y-m-d H:i:s', $timestamp );
				$min_wait_hours       = absint( get_option( 'tnm_trust_dispute_min_wait_hours', 48 ) );
				$hours_elapsed         = ( time() - $timestamp ) / HOUR_IN_SECONDS;
				if ( $hours_elapsed < $min_wait_hours ) {
					$warning = sprintf(
						/* translators: %d: number of hours */
						__( 'Less than %d hours have passed since you contacted the seller. Consider waiting for a response before escalating.', 'nest-trust' ),
						$min_wait_hours
					);
				}
			}
		} else {
			$warning = __( 'No contacted_seller_at timestamp was supplied — proceeding, but please contact the seller first if you have not already.', 'nest-trust' );
		}

		$evidence = array();
		if ( ! empty( $args['evidence'] ) && is_array( $args['evidence'] ) ) {
			foreach ( $args['evidence'] as $url ) {
				$clean = sanitize_url( $url );
				if ( $clean ) {
					$evidence[] = $clean;
				}
			}
		}

		$now = current_time( 'mysql', true );

		$table = TNM_Trust_DB::table( 'disputes' );

		$inserted = $wpdb->insert(
			$table,
			array(
				'order_id'            => $order_id,
				'order_item_id'       => ! empty( $args['order_item_id'] ) ? absint( $args['order_item_id'] ) : null,
				'buyer_id'            => $buyer_id,
				'seller_id'           => $seller_id,
				'reason'              => $reason,
				'description'         => sanitize_textarea_field( $args['description'] ),
				'evidence'            => wp_json_encode( $evidence ),
				'status'              => 'open',
				'contacted_seller_at' => $contacted_seller_at,
				'created_at'          => $now,
				'updated_at'          => $now,
			),
			array( '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return new WP_Error( 'tnm_trust_db_error', __( 'Could not create dispute.', 'nest-trust' ), array( 'status' => 500 ) );
		}

		$dispute = self::get_dispute( $wpdb->insert_id );

		return array(
			'dispute' => $dispute,
			'warning' => $warning,
		);
	}

	/**
	 * Fetch a single dispute row by ID, decoded for API/UI use.
	 *
	 * @param int $id Dispute ID.
	 * @return array|null
	 */
	public static function get_dispute( $id ) {
		global $wpdb;

		$id    = absint( $id );
		$table = TNM_Trust_DB::table( 'disputes' );

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! $row ) {
			return null;
		}

		return self::format_dispute_row( $row );
	}

	/**
	 * Format a raw DB row for JSON output.
	 *
	 * @param array $row Raw row.
	 * @return array
	 */
	protected static function format_dispute_row( $row ) {
		$row['id']             = absint( $row['id'] );
		$row['order_id']       = absint( $row['order_id'] );
		$row['order_item_id']  = ! empty( $row['order_item_id'] ) ? absint( $row['order_item_id'] ) : null;
		$row['buyer_id']       = absint( $row['buyer_id'] );
		$row['seller_id']      = absint( $row['seller_id'] );
		$row['refund_amount']  = null !== $row['refund_amount'] ? (float) $row['refund_amount'] : null;
		$decoded_evidence      = json_decode( (string) $row['evidence'], true );
		$row['evidence']       = is_array( $decoded_evidence ) ? $decoded_evidence : array();

		return $row;
	}

	/**
	 * List disputes visible to the given user, filtered by their role.
	 *
	 * @param int   $user_id User ID.
	 * @param array $filters Optional filters: status.
	 * @return array
	 */
	public static function list_disputes_for_user( $user_id, $filters = array() ) {
		global $wpdb;

		$table = TNM_Trust_DB::table( 'disputes' );
		$user_id = absint( $user_id );

		$where  = array();
		$values = array();

		if ( TNM_Trust_Compat::current_user_is_admin() ) {
			// Admin sees all.
		} elseif ( TNM_Trust_Compat::is_seller( $user_id ) ) {
			$where[]  = '(seller_id = %d OR buyer_id = %d)';
			$values[] = $user_id;
			$values[] = $user_id;
		} else {
			$where[]  = 'buyer_id = %d';
			$values[] = $user_id;
		}

		if ( ! empty( $filters['status'] ) && in_array( $filters['status'], self::STATUSES, true ) ) {
			$where[]  = 'status = %s';
			$values[] = $filters['status'];
		}

		$sql = "SELECT * FROM {$table}";
		if ( ! empty( $where ) ) {
			$sql .= ' WHERE ' . implode( ' AND ', $where );
		}
		$sql .= ' ORDER BY created_at DESC LIMIT 200';

		if ( ! empty( $values ) ) {
			$prepared = $wpdb->prepare( $sql, $values ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			$prepared = $sql;
		}

		$rows = $wpdb->get_results( $prepared, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( ! $rows ) {
			return array();
		}

		return array_map( array( __CLASS__, 'format_dispute_row' ), $rows );
	}

	/**
	 * Whether the given user may view a specific dispute.
	 *
	 * @param array $dispute Formatted dispute row.
	 * @param int   $user_id User ID.
	 * @return bool
	 */
	public static function user_can_view( $dispute, $user_id ) {
		$user_id = absint( $user_id );
		if ( TNM_Trust_Compat::current_user_is_admin() ) {
			return true;
		}
		return ( absint( $dispute['buyer_id'] ) === $user_id || absint( $dispute['seller_id'] ) === $user_id );
	}

	/**
	 * Update a dispute (seller response or admin resolution fields).
	 *
	 * @param int   $id      Dispute ID.
	 * @param array $args    Fields to update.
	 * @param int   $user_id Acting user ID.
	 * @return array|WP_Error
	 */
	public static function update_dispute( $id, $args, $user_id ) {
		global $wpdb;

		$dispute = self::get_dispute( $id );
		if ( ! $dispute ) {
			return new WP_Error( 'tnm_trust_not_found', __( 'Dispute not found.', 'nest-trust' ), array( 'status' => 404 ) );
		}

		$is_admin  = TNM_Trust_Compat::current_user_is_admin();
		$is_seller = ( absint( $dispute['seller_id'] ) === absint( $user_id ) );

		if ( ! $is_admin && ! $is_seller ) {
			return new WP_Error( 'tnm_trust_forbidden', __( 'You are not allowed to update this dispute.', 'nest-trust' ), array( 'status' => 403 ) );
		}

		$table   = TNM_Trust_DB::table( 'disputes' );
		$update  = array();
		$formats = array();

		if ( $is_seller && ! $is_admin ) {
			if ( ! in_array( $dispute['status'], array( 'open', 'awaiting_seller' ), true ) ) {
				return new WP_Error( 'tnm_trust_invalid_state', __( 'This dispute is no longer open for a seller response.', 'nest-trust' ), array( 'status' => 409 ) );
			}
			if ( isset( $args['resolution_note'] ) ) {
				$update['resolution_note'] = sanitize_textarea_field( $args['resolution_note'] );
				$formats[]                  = '%s';
			}
			$update['status'] = 'awaiting_buyer';
			$formats[]          = '%s';
		}

		if ( $is_admin ) {
			if ( isset( $args['status'] ) && in_array( $args['status'], self::STATUSES, true ) ) {
				$update['status'] = $args['status'];
				$formats[]          = '%s';
				if ( in_array( $args['status'], array( 'resolved_refund', 'resolved_no_refund', 'resolved_partial' ), true ) ) {
					$update['resolved_at'] = current_time( 'mysql', true );
					$formats[]               = '%s';
				}
			}
			if ( isset( $args['resolution_note'] ) ) {
				$update['resolution_note'] = sanitize_textarea_field( $args['resolution_note'] );
				$formats[]                  = '%s';
			}
			if ( isset( $args['refund_amount'] ) && is_numeric( $args['refund_amount'] ) ) {
				$update['refund_amount'] = (float) $args['refund_amount'];
				$formats[]                 = '%f';
			}
		}

		if ( empty( $update ) ) {
			return new WP_Error( 'tnm_trust_no_changes', __( 'No valid fields were supplied to update.', 'nest-trust' ), array( 'status' => 400 ) );
		}

		$update['updated_at'] = current_time( 'mysql', true );
		$formats[]              = '%s';

		$updated = $wpdb->update(
			$table,
			$update,
			array( 'id' => absint( $id ) ),
			$formats,
			array( '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error( 'tnm_trust_db_error', __( 'Could not update dispute.', 'nest-trust' ), array( 'status' => 500 ) );
		}

		return self::get_dispute( $id );
	}

	/**
	 * Buyer escalates a dispute to admin review if seller hasn't resolved
	 * within the configured SLA.
	 *
	 * @param int $id      Dispute ID.
	 * @param int $user_id Acting (buyer) user ID.
	 * @return array|WP_Error
	 */
	public static function escalate_dispute( $id, $user_id ) {
		global $wpdb;

		$dispute = self::get_dispute( $id );
		if ( ! $dispute ) {
			return new WP_Error( 'tnm_trust_not_found', __( 'Dispute not found.', 'nest-trust' ), array( 'status' => 404 ) );
		}

		if ( absint( $dispute['buyer_id'] ) !== absint( $user_id ) && ! TNM_Trust_Compat::current_user_is_admin() ) {
			return new WP_Error( 'tnm_trust_forbidden', __( 'Only the buyer may escalate this dispute.', 'nest-trust' ), array( 'status' => 403 ) );
		}

		if ( in_array( $dispute['status'], array( 'resolved_refund', 'resolved_no_refund', 'resolved_partial' ), true ) ) {
			return new WP_Error( 'tnm_trust_already_resolved', __( 'This dispute is already resolved.', 'nest-trust' ), array( 'status' => 409 ) );
		}

		$sla_days     = absint( get_option( 'tnm_trust_dispute_sla_days', 5 ) );
		$created_time = strtotime( $dispute['created_at'] . ' UTC' );
		$days_open    = ( time() - $created_time ) / DAY_IN_SECONDS;

		if ( $days_open < $sla_days && ! TNM_Trust_Compat::current_user_is_admin() ) {
			return new WP_Error(
				'tnm_trust_sla_not_reached',
				sprintf(
					/* translators: %d: number of days */
					__( 'This dispute can be escalated after %d days without seller resolution.', 'nest-trust' ),
					$sla_days
				),
				array( 'status' => 403 )
			);
		}

		$table = TNM_Trust_DB::table( 'disputes' );

		$updated = $wpdb->update(
			$table,
			array(
				'status'       => 'escalated',
				'escalated_at' => current_time( 'mysql', true ),
				'updated_at'   => current_time( 'mysql', true ),
			),
			array( 'id' => absint( $id ) ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error( 'tnm_trust_db_error', __( 'Could not escalate dispute.', 'nest-trust' ), array( 'status' => 500 ) );
		}

		return self::get_dispute( $id );
	}

	/**
	 * Admin-only: resolve a dispute, optionally triggering a WooCommerce refund
	 * and a compensating ledger row in the other plugin's ledger table (if present).
	 *
	 * @param int   $id   Dispute ID.
	 * @param array $args status, resolution_note, refund_amount.
	 * @return array|WP_Error
	 */
	public static function resolve_dispute( $id, $args ) {
		global $wpdb;

		if ( ! TNM_Trust_Compat::current_user_is_admin() ) {
			return new WP_Error( 'tnm_trust_forbidden', __( 'Only an administrator may resolve disputes.', 'nest-trust' ), array( 'status' => 403 ) );
		}

		$dispute = self::get_dispute( $id );
		if ( ! $dispute ) {
			return new WP_Error( 'tnm_trust_not_found', __( 'Dispute not found.', 'nest-trust' ), array( 'status' => 404 ) );
		}

		$status = isset( $args['status'] ) ? $args['status'] : '';
		if ( ! in_array( $status, array( 'resolved_refund', 'resolved_no_refund', 'resolved_partial' ), true ) ) {
			return new WP_Error( 'tnm_trust_invalid_status', __( 'A valid resolution status is required (resolved_refund, resolved_no_refund, resolved_partial).', 'nest-trust' ), array( 'status' => 400 ) );
		}

		$refund_amount = isset( $args['refund_amount'] ) && is_numeric( $args['refund_amount'] ) ? (float) $args['refund_amount'] : 0.0;

		$refund_result = null;

		if ( in_array( $status, array( 'resolved_refund', 'resolved_partial' ), true ) && $refund_amount > 0 ) {
			$order = wc_get_order( $dispute['order_id'] );
			if ( $order ) {
				$refund_args = array(
					'amount'   => $refund_amount,
					'order_id' => $dispute['order_id'],
					'reason'   => sprintf(
						/* translators: %d: dispute ID */
						__( 'MyNest Trust Suite dispute #%d resolution', 'nest-trust' ),
						absint( $id )
					),
				);

				$refund = wc_create_refund( $refund_args );

				if ( is_wp_error( $refund ) ) {
					TNM_Trust_Compat::log( 'Refund failed for dispute ' . absint( $id ) . ': ' . $refund->get_error_message() );
					$refund_result = 'failed';
				} else {
					$refund_result = 'created';
					self::write_compensating_ledger_row( $dispute, $refund_amount );
				}
			} else {
				TNM_Trust_Compat::log( 'Could not load order for refund on dispute ' . absint( $id ) );
				$refund_result = 'order_not_found';
			}
		}

		$table = TNM_Trust_DB::table( 'disputes' );

		$updated = $wpdb->update(
			$table,
			array(
				'status'          => $status,
				'resolution_note' => isset( $args['resolution_note'] ) ? sanitize_textarea_field( $args['resolution_note'] ) : $dispute['resolution_note'],
				'refund_amount'   => $refund_amount > 0 ? $refund_amount : null,
				'resolved_at'     => current_time( 'mysql', true ),
				'updated_at'      => current_time( 'mysql', true ),
			),
			array( 'id' => absint( $id ) ),
			array( '%s', '%s', '%f', '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error( 'tnm_trust_db_error', __( 'Could not resolve dispute.', 'nest-trust' ), array( 'status' => 500 ) );
		}

		$result             = self::get_dispute( $id );
		$result['refund_result'] = $refund_result;

		return $result;
	}

	/**
	 * Write a compensating negative ledger row into the other plugin's
	 * `ledger` table, if it exists. Never fatals if it doesn't.
	 *
	 * @param array $dispute       Formatted dispute row.
	 * @param float $refund_amount Refund amount (positive number; written as negative).
	 */
	protected static function write_compensating_ledger_row( $dispute, $refund_amount ) {
		global $wpdb;

		$ledger_table = TNM_Trust_Compat::get_other_plugin_table( 'ledger' );

		if ( null === $ledger_table ) {
			TNM_Trust_Compat::log( 'Ledger table not found — skipping compensating ledger row for dispute refund on order ' . absint( $dispute['order_id'] ) . '.' );
			return;
		}

		// Defensively check that the expected columns exist before writing.
		$columns = $wpdb->get_col( "DESCRIBE {$ledger_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$required = array( 'order_id', 'seller_id', 'net', 'status' );
		foreach ( $required as $required_column ) {
			if ( ! in_array( $required_column, $columns, true ) ) {
				TNM_Trust_Compat::log( "Ledger table missing expected column '{$required_column}' — skipping compensating row." );
				return;
			}
		}

		$row = array(
			'order_id'  => absint( $dispute['order_id'] ),
			'seller_id' => absint( $dispute['seller_id'] ),
			'net'       => -1 * abs( (float) $refund_amount ),
			'status'    => 'dispute_refund',
		);

		$formats = array( '%d', '%d', '%f', '%s' );

		if ( in_array( 'gross', $columns, true ) ) {
			$row['gross'] = -1 * abs( (float) $refund_amount );
			$formats[]     = '%f';
		}

		if ( in_array( 'created_at', $columns, true ) ) {
			$row['created_at'] = current_time( 'mysql', true );
			$formats[]           = '%s';
		}

		$inserted = $wpdb->insert( $ledger_table, $row, $formats );

		if ( false === $inserted ) {
			TNM_Trust_Compat::log( 'Failed to write compensating ledger row for dispute refund on order ' . absint( $dispute['order_id'] ) . '.' );
		}
	}
}
