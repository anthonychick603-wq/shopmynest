<?php
/**
 * MNU Admin Tables — wp-admin browsers for marketplace custom tables.
 *
 * Historically the marketplace stored follows, notifications, messages,
 * reviews, and import jobs in custom tables without any wp-admin UI, which
 * meant support tasks had to go through direct DB access or CLI. This class
 * adds read-friendly management screens under the existing "Marketplace"
 * top-level menu:
 *
 *   Marketplace ▸ Follows
 *   Marketplace ▸ Notifications
 *   Marketplace ▸ Messages
 *   Marketplace ▸ Reviews
 *   Marketplace ▸ Import Jobs
 *
 * Each screen offers:
 *   - Paginated table (50/page) with search filters
 *   - CSV export (respects current filter)
 *   - Row delete with wp_nonce guard (only for non-financial tables — the
 *     ledger and payouts screens keep their existing surfaces)
 *   - Sortable columns where safe
 *
 * All actions require the `manage_woocommerce` capability.
 *
 * @package MyNest_Unified_Marketplace
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MNU_Admin_Tables {

	private const CAP        = 'manage_woocommerce';
	private const PER_PAGE   = 50;
	private const NONCE_KEY  = 'mnu_admin_table_action';
	private const PARENT     = 'tnm-marketplace';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menus' ), 20 );
		add_action( 'admin_post_mnu_table_delete', array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_post_mnu_table_export', array( __CLASS__, 'handle_export' ) );
	}

	public static function register_menus(): void {
		add_submenu_page( self::PARENT, 'Follows', 'Follows', self::CAP, 'mnu-follows', array( __CLASS__, 'screen_follows' ) );
		add_submenu_page( self::PARENT, 'Notifications', 'Notifications', self::CAP, 'mnu-notifications', array( __CLASS__, 'screen_notifications' ) );
		add_submenu_page( self::PARENT, 'Messages', 'Messages', self::CAP, 'mnu-messages', array( __CLASS__, 'screen_messages' ) );
		add_submenu_page( self::PARENT, 'Reviews', 'Reviews', self::CAP, 'mnu-reviews', array( __CLASS__, 'screen_reviews' ) );
		add_submenu_page( self::PARENT, 'Import Jobs', 'Import Jobs', self::CAP, 'mnu-import-jobs', array( __CLASS__, 'screen_import_jobs' ) );
	}

	/* ==================================================================
	 * Screen renderers
	 * ================================================================== */

	public static function screen_follows(): void {
		self::render_screen(
			array(
				'title'       => 'Follows',
				'page'        => 'mnu-follows',
				'table'       => tnm_table( 'follows' ),
				'order_by'    => 'created_at DESC, id DESC',
				'search_cols' => array(),
				'search_ids'  => array( 'follower_id', 'following_id' ),
				'columns'     => array(
					'id'            => 'ID',
					'follower_id'   => 'Follower',
					'following_id'  => 'Following (seller)',
					'created_at'    => 'Since',
				),
				'renderers'   => array(
					'follower_id'  => array( __CLASS__, 'render_user' ),
					'following_id' => array( __CLASS__, 'render_user' ),
				),
			)
		);
	}

	public static function screen_notifications(): void {
		self::render_screen(
			array(
				'title'       => 'Notifications',
				'page'        => 'mnu-notifications',
				'table'       => tnm_table( 'notifications' ),
				'order_by'    => 'created_at DESC, id DESC',
				'search_cols' => array( 'type', 'object_type', 'title', 'message' ),
				'search_ids'  => array( 'user_id', 'actor_id', 'object_id' ),
				'columns'     => array(
					'id'          => 'ID',
					'user_id'     => 'Recipient',
					'actor_id'    => 'Actor',
					'type'        => 'Type',
					'object_type' => 'Object',
					'object_id'   => 'Object ID',
					'title'       => 'Title',
					'is_read'     => 'Read',
					'created_at'  => 'When',
				),
				'renderers'   => array(
					'user_id'  => array( __CLASS__, 'render_user' ),
					'actor_id' => array( __CLASS__, 'render_user' ),
					'is_read'  => array( __CLASS__, 'render_bool' ),
				),
			)
		);
	}

	public static function screen_messages(): void {
		self::render_screen(
			array(
				'title'       => 'Messages',
				'page'        => 'mnu-messages',
				'table'       => tnm_table( 'messages' ),
				'order_by'    => 'created_at DESC, id DESC',
				'search_cols' => array( 'message' ),
				'search_ids'  => array( 'sender_id', 'recipient_id' ),
				'columns'     => array(
					'id'           => 'ID',
					'sender_id'    => 'From',
					'recipient_id' => 'To',
					'message'      => 'Message',
					'is_read'      => 'Read',
					'created_at'   => 'When',
				),
				'renderers'   => array(
					'sender_id'    => array( __CLASS__, 'render_user' ),
					'recipient_id' => array( __CLASS__, 'render_user' ),
					'is_read'      => array( __CLASS__, 'render_bool' ),
					'message'      => array( __CLASS__, 'render_truncated' ),
				),
			)
		);
	}

	public static function screen_reviews(): void {
		self::render_screen(
			array(
				'title'       => 'Reviews',
				'page'        => 'mnu-reviews',
				'table'       => tnm_table( 'reviews' ),
				'order_by'    => 'created_at DESC, id DESC',
				'search_cols' => array( 'status', 'review' ),
				'search_ids'  => array( 'reviewer_id', 'seller_id', 'order_id' ),
				'columns'     => array(
					'id'          => 'ID',
					'reviewer_id' => 'Reviewer',
					'seller_id'   => 'Seller',
					'order_id'    => 'Order',
					'rating'      => 'Rating',
					'status'      => 'Status',
					'review'      => 'Review',
					'created_at'  => 'When',
				),
				'renderers'   => array(
					'reviewer_id' => array( __CLASS__, 'render_user' ),
					'seller_id'   => array( __CLASS__, 'render_user' ),
					'order_id'    => array( __CLASS__, 'render_order' ),
					'rating'      => array( __CLASS__, 'render_rating' ),
					'review'      => array( __CLASS__, 'render_truncated' ),
				),
			)
		);
	}

	public static function screen_import_jobs(): void {
		global $wpdb;
		self::render_screen(
			array(
				'title'       => 'Import Jobs',
				'page'        => 'mnu-import-jobs',
				'table'       => $wpdb->prefix . 'mnu_import_jobs',
				'order_by'    => 'created_at DESC, id DESC',
				'search_cols' => array( 'status' ),
				'search_ids'  => array( 'seller_id' ),
				'columns'     => array(
					'id'           => 'ID',
					'seller_id'    => 'Seller',
					'status'       => 'Status',
					'total_rows'   => 'Rows',
					'processed'    => 'Processed',
					'created'      => 'Created',
					'updated'      => 'Updated',
					'failed'       => 'Failed',
					'created_at'   => 'When',
				),
				'renderers'   => array(
					'seller_id' => array( __CLASS__, 'render_user' ),
					'status'    => array( __CLASS__, 'render_status' ),
				),
			)
		);
	}

	/* ==================================================================
	 * Generic table screen
	 * ================================================================== */

	/**
	 * @param array<string,mixed> $cfg
	 */
	private static function render_screen( array $cfg ): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Insufficient permission.' );
		}
		global $wpdb;

		$page    = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$offset  = ( $page - 1 ) * self::PER_PAGE;
		$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : '';
		$id_val  = isset( $_GET['id_val'] ) ? (int) $_GET['id_val'] : 0;
		$id_col  = isset( $_GET['id_col'] ) ? sanitize_key( (string) $_GET['id_col'] ) : '';

		list( $where_sql, $where_args ) = self::build_where( $search, $cfg['search_cols'], $id_val, $id_col, $cfg['search_ids'] );

		// Total
		$count_sql = 'SELECT COUNT(*) FROM ' . $cfg['table'] . ' ' . $where_sql;
		$total     = (int) ( $where_args
			? $wpdb->get_var( $wpdb->prepare( $count_sql, $where_args ) ) // phpcs:ignore
			: $wpdb->get_var( $count_sql ) // phpcs:ignore
		);

		// Rows
		$rows_sql = 'SELECT * FROM ' . $cfg['table'] . ' ' . $where_sql . ' ORDER BY ' . $cfg['order_by'] . ' LIMIT %d OFFSET %d';
		$args     = array_merge( $where_args, array( self::PER_PAGE, $offset ) );
		$rows     = (array) $wpdb->get_results( $wpdb->prepare( $rows_sql, $args ), ARRAY_A ); // phpcs:ignore

		$total_pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$export_url  = wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'mnu_table_export',
					'page'   => $cfg['page'],
					's'      => $search,
					'id_val' => $id_val,
					'id_col' => $id_col,
				),
				admin_url( 'admin-post.php' )
			),
			self::NONCE_KEY
		);

		$notice = get_transient( 'mnu_admin_notice_' . get_current_user_id() );
		if ( $notice ) {
			delete_transient( 'mnu_admin_notice_' . get_current_user_id() );
		}
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php echo esc_html( $cfg['title'] ); ?></h1>
			<a href="<?php echo esc_url( $export_url ); ?>" class="page-title-action">Export CSV</a>
			<hr class="wp-header-end">

			<?php if ( $notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( (string) ( $notice['type'] ?? 'success' ) ); ?> is-dismissible">
					<p><?php echo esc_html( (string) ( $notice['message'] ?? '' ) ); ?></p>
				</div>
			<?php endif; ?>

			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( $cfg['page'] ); ?>">
				<p class="search-box">
					<?php if ( ! empty( $cfg['search_cols'] ) ) : ?>
						<label class="screen-reader-text" for="mnu-search">Search</label>
						<input id="mnu-search" type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Search text…">
					<?php endif; ?>

					<?php if ( ! empty( $cfg['search_ids'] ) ) : ?>
						<select name="id_col">
							<option value="">— filter by ID —</option>
							<?php foreach ( $cfg['search_ids'] as $col ) : ?>
								<option value="<?php echo esc_attr( $col ); ?>" <?php selected( $id_col, $col ); ?>><?php echo esc_html( $col ); ?></option>
							<?php endforeach; ?>
						</select>
						<input type="number" name="id_val" value="<?php echo esc_attr( $id_val ?: '' ); ?>" placeholder="ID" min="0">
					<?php endif; ?>

					<input type="submit" class="button" value="Filter">
					<?php if ( $search || $id_val ) : ?>
						<a class="button-link" href="<?php echo esc_url( admin_url( 'admin.php?page=' . $cfg['page'] ) ); ?>">Reset</a>
					<?php endif; ?>
				</p>
			</form>

			<p class="description"><?php echo esc_html( number_format( $total ) ); ?> total row<?php echo 1 === $total ? '' : 's'; ?>.</p>

			<table class="widefat striped">
				<thead>
					<tr>
						<?php foreach ( $cfg['columns'] as $label ) : ?>
							<th><?php echo esc_html( $label ); ?></th>
						<?php endforeach; ?>
						<th style="width:80px">Actions</th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! $rows ) : ?>
						<tr><td colspan="<?php echo (int) ( count( $cfg['columns'] ) + 1 ); ?>">No rows.</td></tr>
					<?php endif; ?>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<?php foreach ( $cfg['columns'] as $col => $_ ) :
								$val      = $row[ $col ] ?? '';
								$renderer = $cfg['renderers'][ $col ] ?? null;
								?>
								<td><?php echo $renderer ? call_user_func( $renderer, $val, $row ) : esc_html( (string) $val ); // phpcs:ignore ?></td>
							<?php endforeach; ?>
							<td>
								<a class="button-link-delete"
								   onclick="return confirm('Delete row #<?php echo (int) $row['id']; ?>? This is permanent.')"
								   href="<?php echo esc_url( wp_nonce_url(
									add_query_arg(
										array(
											'action'   => 'mnu_table_delete',
											'page'     => $cfg['page'],
											'row_id'   => (int) $row['id'],
										),
										admin_url( 'admin-post.php' )
									),
									self::NONCE_KEY
								) ); ?>">Delete</a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav bottom">
					<div class="tablenav-pages">
						<?php
						echo paginate_links(
							array(
								'base'      => add_query_arg( 'paged', '%#%' ),
								'format'    => '',
								'current'   => $page,
								'total'     => $total_pages,
								'prev_text' => '‹',
								'next_text' => '›',
							)
						); // phpcs:ignore
						?>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/* ==================================================================
	 * Filter builder + shared cell renderers
	 * ================================================================== */

	/**
	 * @param string[]                              $search_cols
	 * @param string[]                              $search_ids
	 * @return array{0:string,1:array<int,mixed>}
	 */
	private static function build_where( string $search, array $search_cols, int $id_val, string $id_col, array $search_ids ): array {
		$clauses = array();
		$args    = array();

		if ( $search !== '' && $search_cols ) {
			$or = array();
			foreach ( $search_cols as $c ) {
				$or[]   = '`' . $c . '` LIKE %s';
				$args[] = '%' . $GLOBALS['wpdb']->esc_like( $search ) . '%';
			}
			$clauses[] = '(' . implode( ' OR ', $or ) . ')';
		}

		if ( $id_val > 0 && $id_col && in_array( $id_col, $search_ids, true ) ) {
			$clauses[] = '`' . $id_col . '` = %d';
			$args[]    = $id_val;
		}

		return array(
			$clauses ? 'WHERE ' . implode( ' AND ', $clauses ) : '',
			$args,
		);
	}

	public static function render_user( $id, array $row = array() ): string {
		$id = (int) $id;
		if ( ! $id ) {
			return '<em>—</em>';
		}
		$u = get_userdata( $id );
		if ( ! $u ) {
			return '<em>(deleted)</em> #' . $id;
		}
		$url = get_edit_user_link( $id );
		return '<a href="' . esc_url( $url ) . '">' . esc_html( $u->display_name ?: $u->user_login ) . '</a> <span style="color:#888">#' . $id . '</span>';
	}

	public static function render_order( $id, array $row = array() ): string {
		$id = (int) $id;
		if ( ! $id ) {
			return '<em>—</em>';
		}
		if ( ! get_post( $id ) ) {
			return '<em>(deleted)</em> #' . $id;
		}
		$url = admin_url( 'post.php?post=' . $id . '&action=edit' );
		return '<a href="' . esc_url( $url ) . '">#' . $id . '</a>';
	}

	public static function render_bool( $v, array $row = array() ): string {
		return ( (int) $v ) ? '<span style="color:#437A22">✓</span>' : '<span style="color:#888">—</span>';
	}

	public static function render_truncated( $v, array $row = array() ): string {
		$v = (string) $v;
		return esc_html( mb_strimwidth( $v, 0, 90, '…' ) );
	}

	public static function render_rating( $v, array $row = array() ): string {
		$v = (int) $v;
		return esc_html( str_repeat( '★', max( 0, min( 5, $v ) ) ) ) . '<span style="color:#ccc">' . str_repeat( '★', max( 0, 5 - $v ) ) . '</span>';
	}

	public static function render_status( $v, array $row = array() ): string {
		$v     = (string) $v;
		$color = array(
			'ready'      => '#888',
			'processing' => '#0673ba',
			'complete'   => '#437A22',
			'failed'     => '#A13544',
			'approved'   => '#437A22',
			'pending'    => '#B0553A',
			'rejected'   => '#A13544',
			'hidden'     => '#888',
		)[ strtolower( $v ) ] ?? '#333';
		return '<strong style="color:' . esc_attr( $color ) . '">' . esc_html( ucfirst( $v ) ) . '</strong>';
	}

	/* ==================================================================
	 * Row delete + CSV export
	 * ================================================================== */

	public static function handle_delete(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Insufficient permission.' );
		}
		check_admin_referer( self::NONCE_KEY );

		$page   = isset( $_GET['page'] ) ? sanitize_key( (string) $_GET['page'] ) : '';
		$row_id = isset( $_GET['row_id'] ) ? (int) $_GET['row_id'] : 0;

		$table = self::table_for_page( $page );
		if ( ! $table || ! $row_id ) {
			self::notice( 'error', 'Invalid delete request.' );
			wp_safe_redirect( admin_url( 'admin.php?page=' . $page ) );
			exit;
		}

		global $wpdb;
		$rows = (int) $wpdb->delete( $table, array( 'id' => $row_id ), array( '%d' ) );
		self::notice(
			$rows ? 'success' : 'error',
			$rows ? sprintf( 'Deleted row #%d.', $row_id ) : 'Row could not be deleted (already gone?).'
		);
		wp_safe_redirect( admin_url( 'admin.php?page=' . $page ) );
		exit;
	}

	public static function handle_export(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Insufficient permission.' );
		}
		check_admin_referer( self::NONCE_KEY );

		global $wpdb;
		$page   = isset( $_GET['page'] ) ? sanitize_key( (string) $_GET['page'] ) : '';
		$table  = self::table_for_page( $page );
		if ( ! $table ) {
			wp_die( 'Unknown export target.' );
		}
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : '';
		$id_val = isset( $_GET['id_val'] ) ? (int) $_GET['id_val'] : 0;
		$id_col = isset( $_GET['id_col'] ) ? sanitize_key( (string) $_GET['id_col'] ) : '';

		$cfg = self::config_for_page( $page );
		list( $where_sql, $where_args ) = self::build_where( $search, $cfg['search_cols'], $id_val, $id_col, $cfg['search_ids'] );

		$rows = (array) ( $where_args
			? $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $table . ' ' . $where_sql . ' ORDER BY ' . $cfg['order_by'] . ' LIMIT 50000', $where_args ), ARRAY_A ) // phpcs:ignore
			: $wpdb->get_results( 'SELECT * FROM ' . $table . ' ' . $where_sql . ' ORDER BY ' . $cfg['order_by'] . ' LIMIT 50000', ARRAY_A ) // phpcs:ignore
		);

		$filename = sanitize_file_name( $page . '-' . gmdate( 'Ymd-His' ) . '.csv' );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$out = fopen( 'php://output', 'w' );
		if ( $rows ) {
			fputcsv( $out, array_keys( $rows[0] ) );
			foreach ( $rows as $r ) {
				fputcsv( $out, $r );
			}
		} else {
			fputcsv( $out, array( 'no_rows' ) );
		}
		fclose( $out );
		exit;
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function config_for_page( string $page ): array {
		// Mirrors the config passed into render_screen() — kept here purely so
		// the export handler can rebuild the WHERE clause with the same rules.
		switch ( $page ) {
			case 'mnu-follows':
				return array( 'search_cols' => array(), 'search_ids' => array( 'follower_id', 'following_id' ), 'order_by' => 'created_at DESC, id DESC' );
			case 'mnu-notifications':
				return array( 'search_cols' => array( 'type', 'object_type', 'title', 'message' ), 'search_ids' => array( 'user_id', 'actor_id', 'object_id' ), 'order_by' => 'created_at DESC, id DESC' );
			case 'mnu-messages':
				return array( 'search_cols' => array( 'message' ), 'search_ids' => array( 'sender_id', 'recipient_id' ), 'order_by' => 'created_at DESC, id DESC' );
			case 'mnu-reviews':
				return array( 'search_cols' => array( 'status', 'review' ), 'search_ids' => array( 'reviewer_id', 'seller_id', 'order_id' ), 'order_by' => 'created_at DESC, id DESC' );
			case 'mnu-import-jobs':
				return array( 'search_cols' => array( 'status' ), 'search_ids' => array( 'seller_id' ), 'order_by' => 'created_at DESC, id DESC' );
		}
		return array( 'search_cols' => array(), 'search_ids' => array(), 'order_by' => 'id DESC' );
	}

	private static function table_for_page( string $page ): string {
		global $wpdb;
		switch ( $page ) {
			case 'mnu-follows':
				return tnm_table( 'follows' );
			case 'mnu-notifications':
				return tnm_table( 'notifications' );
			case 'mnu-messages':
				return tnm_table( 'messages' );
			case 'mnu-reviews':
				return tnm_table( 'reviews' );
			case 'mnu-import-jobs':
				return $wpdb->prefix . 'mnu_import_jobs';
		}
		return '';
	}

	private static function notice( string $type, string $message ): void {
		set_transient( 'mnu_admin_notice_' . get_current_user_id(), array( 'type' => $type, 'message' => $message ), 60 );
	}
}
