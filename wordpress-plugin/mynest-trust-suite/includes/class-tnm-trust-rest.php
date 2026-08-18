<?php
/**
 * REST API routes under the `nest-trust/v1` namespace.
 *
 * @package MyNest_Trust_Suite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TNM_Trust_REST {

	const NS = TNM_TRUST_REST_NS;

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register all REST routes.
	 */
	public static function register_routes() {

		// ---------------------------------------------------------------
		// Feature 1 — Disputes.
		// ---------------------------------------------------------------
		register_rest_route(
			self::NS,
			'/disputes',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'create_dispute' ),
					'permission_callback' => array( __CLASS__, 'permission_logged_in' ),
					'args'                => array(
						'order_id'   => array( 'required' => true, 'type' => 'integer' ),
						'reason'     => array( 'required' => true, 'type' => 'string' ),
						'description' => array( 'required' => true, 'type' => 'string' ),
					),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'list_disputes' ),
					'permission_callback' => array( __CLASS__, 'permission_logged_in' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/disputes/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_dispute' ),
					'permission_callback' => array( __CLASS__, 'permission_logged_in' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( __CLASS__, 'update_dispute' ),
					'permission_callback' => array( __CLASS__, 'permission_logged_in' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/disputes/(?P<id>\d+)/escalate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'escalate_dispute' ),
				'permission_callback' => array( __CLASS__, 'permission_logged_in' ),
			)
		);

		register_rest_route(
			self::NS,
			'/disputes/(?P<id>\d+)/resolve',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'resolve_dispute' ),
				'permission_callback' => array( __CLASS__, 'permission_admin' ),
			)
		);

		// ---------------------------------------------------------------
		// Feature 2 — Seller Performance Badge.
		// ---------------------------------------------------------------
		register_rest_route(
			self::NS,
			'/sellers/(?P<id>\d+)/badge',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_seller_badge' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NS,
			'/sellers/(?P<id>\d+)/pro-status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_seller_pro_status' ),
				'permission_callback' => '__return_true',
			)
		);

		// ---------------------------------------------------------------
		// Feature 3 — Favorites + Feed.
		// ---------------------------------------------------------------
		register_rest_route(
			self::NS,
			'/favorites',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'toggle_favorite' ),
					'permission_callback' => array( __CLASS__, 'permission_logged_in' ),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'list_favorites' ),
					'permission_callback' => array( __CLASS__, 'permission_logged_in' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/favorites/(?P<product_id>\d+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'delete_favorite' ),
				'permission_callback' => array( __CLASS__, 'permission_logged_in' ),
			)
		);

		register_rest_route(
			self::NS,
			'/products/(?P<id>\d+)/favorites-count',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_favorites_count' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NS,
			'/feed',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_feed' ),
				'permission_callback' => '__return_true',
			)
		);

		// ---------------------------------------------------------------
		// Feature 6 — Boosts.
		// ---------------------------------------------------------------
		register_rest_route(
			self::NS,
			'/boosts',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'create_boost' ),
				'permission_callback' => array( __CLASS__, 'permission_seller' ),
			)
		);
	}

	/**
	 * Permission callback: any logged-in user.
	 *
	 * @return bool
	 */
	public static function permission_logged_in() {
		return is_user_logged_in();
	}

	/**
	 * Permission callback: seller (tnm_seller role) or admin.
	 *
	 * @return bool
	 */
	public static function permission_seller() {
		return is_user_logged_in() && TNM_Trust_Compat::current_user_is_seller();
	}

	/**
	 * Permission callback: admin/shop_manager only.
	 *
	 * @return bool
	 */
	public static function permission_admin() {
		return is_user_logged_in() && TNM_Trust_Compat::current_user_is_admin();
	}

	/**
	 * Standardize WP_Error → WP_REST_Response conversion.
	 *
	 * @param mixed $result Result from a feature class method.
	 * @return WP_REST_Response|WP_Error
	 */
	protected static function respond( $result ) {
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( $result, 200 );
	}

	/* ------------------------------------------------------------------
	 * Feature 1 — Disputes callbacks.
	 * ------------------------------------------------------------------ */

	public static function create_dispute( WP_REST_Request $request ) {
		$args = array(
			'buyer_id'            => get_current_user_id(),
			'order_id'            => absint( $request->get_param( 'order_id' ) ),
			'order_item_id'       => $request->get_param( 'order_item_id' ),
			'reason'              => sanitize_key( (string) $request->get_param( 'reason' ) ),
			'description'         => (string) $request->get_param( 'description' ),
			'evidence'            => $request->get_param( 'evidence' ),
			'contacted_seller_at' => $request->get_param( 'contacted_seller_at' ),
		);

		$result = TNM_Trust_Disputes::create_dispute( $args );

		return self::respond( $result );
	}

	public static function list_disputes( WP_REST_Request $request ) {
		$filters = array( 'status' => sanitize_key( (string) $request->get_param( 'status' ) ) );
		$result   = TNM_Trust_Disputes::list_disputes_for_user( get_current_user_id(), $filters );

		return self::respond( $result );
	}

	public static function get_dispute( WP_REST_Request $request ) {
		$id      = absint( $request->get_param( 'id' ) );
		$dispute = TNM_Trust_Disputes::get_dispute( $id );

		if ( ! $dispute ) {
			return new WP_Error( 'tnm_trust_not_found', __( 'Dispute not found.', 'nest-trust' ), array( 'status' => 404 ) );
		}

		if ( ! TNM_Trust_Disputes::user_can_view( $dispute, get_current_user_id() ) ) {
			return new WP_Error( 'tnm_trust_forbidden', __( 'You are not allowed to view this dispute.', 'nest-trust' ), array( 'status' => 403 ) );
		}

		return self::respond( $dispute );
	}

	public static function update_dispute( WP_REST_Request $request ) {
		$id   = absint( $request->get_param( 'id' ) );
		$args = array(
			'status'          => $request->get_param( 'status' ),
			'resolution_note' => $request->get_param( 'resolution_note' ),
			'refund_amount'   => $request->get_param( 'refund_amount' ),
		);

		$result = TNM_Trust_Disputes::update_dispute( $id, $args, get_current_user_id() );

		return self::respond( $result );
	}

	public static function escalate_dispute( WP_REST_Request $request ) {
		$id     = absint( $request->get_param( 'id' ) );
		$result = TNM_Trust_Disputes::escalate_dispute( $id, get_current_user_id() );

		return self::respond( $result );
	}

	public static function resolve_dispute( WP_REST_Request $request ) {
		$id   = absint( $request->get_param( 'id' ) );
		$args = array(
			'status'          => $request->get_param( 'status' ),
			'resolution_note' => $request->get_param( 'resolution_note' ),
			'refund_amount'   => $request->get_param( 'refund_amount' ),
		);

		$result = TNM_Trust_Disputes::resolve_dispute( $id, $args );

		return self::respond( $result );
	}

	/* ------------------------------------------------------------------
	 * Feature 2 — Badge callbacks.
	 * ------------------------------------------------------------------ */

	public static function get_seller_badge( WP_REST_Request $request ) {
		$seller_id = absint( $request->get_param( 'id' ) );
		return self::respond( TNM_Trust_Seller_Badge::get_badge( $seller_id ) );
	}

	public static function get_seller_pro_status( WP_REST_Request $request ) {
		$seller_id = absint( $request->get_param( 'id' ) );
		return self::respond(
			array(
				'seller_id' => $seller_id,
				'pro_seller' => TNM_Trust_Boosts::is_pro_seller( $seller_id ),
			)
		);
	}

	/* ------------------------------------------------------------------
	 * Feature 3 — Favorites + Feed callbacks.
	 * ------------------------------------------------------------------ */

	public static function toggle_favorite( WP_REST_Request $request ) {
		$product_id = absint( $request->get_param( 'product_id' ) );
		$result      = TNM_Trust_Favorites::toggle( get_current_user_id(), $product_id );

		return self::respond( $result );
	}

	public static function delete_favorite( WP_REST_Request $request ) {
		$product_id = absint( $request->get_param( 'product_id' ) );
		$result      = TNM_Trust_Favorites::remove( get_current_user_id(), $product_id );

		return self::respond( $result );
	}

	public static function list_favorites( WP_REST_Request $request ) {
		return self::respond( TNM_Trust_Favorites::get_user_favorites( get_current_user_id() ) );
	}

	public static function get_favorites_count( WP_REST_Request $request ) {
		$product_id = absint( $request->get_param( 'id' ) );
		return self::respond(
			array(
				'product_id' => $product_id,
				'count'      => TNM_Trust_Favorites::get_favorites_count( $product_id ),
			)
		);
	}

	public static function get_feed( WP_REST_Request $request ) {
		$args = array(
			'user_id'  => get_current_user_id(),
			'page'     => absint( $request->get_param( 'page' ) ) ?: 1,
			'per_page' => absint( $request->get_param( 'per_page' ) ) ?: 20,
			'category' => $request->get_param( 'category' ),
		);

		return self::respond( TNM_Trust_Feed::get_feed( $args ) );
	}

	/* ------------------------------------------------------------------
	 * Feature 6 — Boosts callbacks.
	 * ------------------------------------------------------------------ */

	public static function create_boost( WP_REST_Request $request ) {
		$product_id = absint( $request->get_param( 'product_id' ) );
		$tier        = sanitize_key( (string) $request->get_param( 'tier' ) );

		return self::respond( TNM_Trust_Boosts::create_boost_order( get_current_user_id(), $product_id, $tier ) );
	}
}
