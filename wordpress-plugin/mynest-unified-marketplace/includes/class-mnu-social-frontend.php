<?php
/**
 * v3.7.33 — Front-end UI for the seller directory, follow, activity feed,
 * public shop profiles, and buyer↔seller / seller↔seller messaging.
 *
 * All shortcodes are server-rendered shells; the JS enhancement fetches live
 * data via the-nest/v1 REST endpoints already exposed by TNM_REST. The
 * shortcodes gracefully degrade to a "Please enable JavaScript" message.
 *
 * Shortcodes:
 *   [mnu_seller_directory]   — /sellers/ page: search + list all approved sellers.
 *   [mnu_seller_shop]        — /shop-profile/?seller=<id> page: one shop's public page.
 *   [mnu_following]          — /following/ page: list of shops the viewer follows.
 *   [mnu_following_feed]     — activity feed widget (recent listings from followed shops).
 *   [mnu_messages]           — /messages/ page: inbox + thread view.
 *   [mnu_message_button]     — button that opens a message composer to a given user_id.
 */

defined( 'ABSPATH' ) || exit;

final class MNU_Social_Frontend {

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register_shortcodes' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue' ), 20 );
		// Extend the site header search to also match sellers by store name.
		add_filter( 'the_content', array( __CLASS__, 'noop_marker' ), 1 );
		// Render "Ask the seller about this item" on single product pages.
		add_action( 'woocommerce_after_add_to_cart_button', array( __CLASS__, 'render_product_ask_seller' ), 30 );
	}

	public static function noop_marker( $content ) {
		return $content;
	}

	public static function register_shortcodes(): void {
		add_shortcode( 'mnu_seller_directory', array( __CLASS__, 'sc_directory' ) );
		add_shortcode( 'mnu_seller_shop', array( __CLASS__, 'sc_shop_profile' ) );
		add_shortcode( 'mnu_following', array( __CLASS__, 'sc_following' ) );
		add_shortcode( 'mnu_following_feed', array( __CLASS__, 'sc_following_feed' ) );
		add_shortcode( 'mnu_messages', array( __CLASS__, 'sc_messages' ) );
		add_shortcode( 'mnu_message_button', array( __CLASS__, 'sc_message_button' ) );
	}

	public static function maybe_enqueue(): void {
		$has = false;
		if ( function_exists( 'is_product' ) && is_product() ) {
			$has = true; // single product needs .mnu-msg-cta styles
		}
		if ( ! $has && is_singular() ) {
			global $post;
			if ( $post ) {
				$tags = array( 'mnu_seller_directory', 'mnu_seller_shop', 'mnu_following', 'mnu_following_feed', 'mnu_messages', 'mnu_message_button' );
				foreach ( $tags as $tag ) {
					if ( has_shortcode( $post->post_content, $tag ) ) {
						$has = true;
						break;
					}
				}
			}
		}
		if ( ! $has ) {
			return;
		}
		// Piggyback on the existing tnm-frontend handle: it already localizes
		// TNMFrontend.restRoot + restNonce, and the CSS provides base styles.
		if ( ! wp_style_is( 'tnm-frontend', 'registered' ) ) {
			wp_register_style( 'tnm-frontend', TNM_URL . 'assets/css/frontend.css', array(), TNM_VERSION );
		}
		if ( ! wp_script_is( 'tnm-frontend', 'registered' ) ) {
			wp_register_script( 'tnm-frontend', TNM_URL . 'assets/js/frontend.js', array(), TNM_VERSION, true );
		}
		wp_enqueue_style( 'tnm-frontend' );
		wp_enqueue_script( 'tnm-frontend' );
		wp_localize_script(
			'tnm-frontend',
			'TNMFrontend',
			array(
				'restRoot'   => trailingslashit( rest_url() ),
				'restNonce'  => wp_create_nonce( 'wp_rest' ),
				'currentUid' => (int) get_current_user_id(),
			)
		);
		wp_add_inline_style( 'tnm-frontend', self::inline_css() );
		wp_add_inline_script( 'tnm-frontend', self::inline_js() );
	}

	/* =========================================================
	 *  SHORTCODES
	 * ========================================================= */

	public static function sc_directory( $atts = array() ): string {
		ob_start();
		?>
		<div class="mnu-directory" data-mnu-directory>
			<div class="mnu-directory__header">
				<h1>Discover shops</h1>
				<p class="mnu-muted">Browse independent makers and follow the shops you love.</p>
				<form class="mnu-search" data-mnu-directory-search onsubmit="return false;">
					<input type="search" placeholder="Search shops by name…" data-mnu-directory-q autocomplete="off" />
				</form>
			</div>
			<div class="mnu-directory__grid" data-mnu-directory-grid>
				<p class="mnu-muted">Loading shops…</p>
			</div>
			<div class="mnu-directory__pager" data-mnu-directory-pager></div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	public static function sc_shop_profile( $atts = array() ): string {
		$atts     = shortcode_atts( array( 'id' => 0 ), $atts, 'mnu_seller_shop' );
		$sid      = (int) ( $atts['id'] ?: ( isset( $_GET['seller'] ) ? absint( $_GET['seller'] ) : 0 ) );
		if ( ! $sid ) {
			return '<div class="mnu-notice">No shop selected. <a href="' . esc_url( home_url( '/sellers/' ) ) . '">Browse all shops</a>.</div>';
		}
		if ( ! tnm_is_seller( $sid ) ) {
			return '<div class="mnu-notice">Shop not found. <a href="' . esc_url( home_url( '/sellers/' ) ) . '">Browse all shops</a>.</div>';
		}
		ob_start();
		?>
		<div class="mnu-shop" data-mnu-shop data-seller-id="<?php echo esc_attr( $sid ); ?>">
			<div class="mnu-shop__hero" data-mnu-shop-hero>
				<div class="mnu-muted">Loading shop…</div>
			</div>
			<div class="mnu-shop__products">
				<h2>Products</h2>
				<div class="mnu-shop__grid" data-mnu-shop-products>
					<p class="mnu-muted">Loading products…</p>
				</div>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	public static function sc_following( $atts = array() ): string {
		if ( ! is_user_logged_in() ) {
			return '<div class="mnu-notice">Please <a href="' . esc_url( wp_login_url( home_url( '/following/' ) ) ) . '">sign in</a> to see the shops you follow.</div>';
		}
		ob_start();
		?>
		<div class="mnu-following" data-mnu-following>
			<h1>Shops you follow</h1>
			<div class="mnu-following__grid" data-mnu-following-grid>
				<p class="mnu-muted">Loading…</p>
			</div>
			<h2 style="margin-top:2rem">Recent listings from shops you follow</h2>
			<div class="mnu-following__feed" data-mnu-following-feed>
				<p class="mnu-muted">Loading…</p>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	public static function sc_following_feed( $atts = array() ): string {
		if ( ! is_user_logged_in() ) {
			return '';
		}
		ob_start();
		?>
		<div class="mnu-following__feed" data-mnu-following-feed>
			<p class="mnu-muted">Loading…</p>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	public static function sc_messages( $atts = array() ): string {
		if ( ! is_user_logged_in() ) {
			return '<div class="mnu-notice">Please <a href="' . esc_url( wp_login_url( home_url( '/messages/' ) ) ) . '">sign in</a> to view your messages.</div>';
		}
		$preselected = isset( $_GET['to'] ) ? absint( $_GET['to'] ) : 0;
		$product_ctx = isset( $_GET['product'] ) ? absint( $_GET['product'] ) : 0;
		ob_start();
		?>
		<div class="mnu-messages" data-mnu-messages data-preselect="<?php echo esc_attr( $preselected ); ?>" data-product-context="<?php echo esc_attr( $product_ctx ); ?>">
			<aside class="mnu-messages__sidebar" data-mnu-conversations>
				<div class="mnu-messages__sidebar-head">
					<h1>Messages</h1>
					<button type="button" class="mnu-messages__refresh" data-mnu-refresh aria-label="Refresh" title="Refresh">
						<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-3-6.7"/><path d="M21 3v6h-6"/></svg>
					</button>
				</div>
				<div class="mnu-messages__list" data-mnu-conversation-list>
					<div class="mnu-messages__skeleton">
						<div class="mnu-skel-row"></div><div class="mnu-skel-row"></div><div class="mnu-skel-row"></div>
					</div>
				</div>
			</aside>
			<section class="mnu-messages__thread" data-mnu-thread>
				<div class="mnu-messages__empty" data-mnu-thread-empty>
					<div class="mnu-empty-icon" aria-hidden="true">
						<svg viewBox="0 0 24 24" width="48" height="48" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H8l-5 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
					</div>
					<h2>Select a conversation</h2>
					<p class="mnu-muted">Pick a chat on the left, or open a shop and tap <em>Message shop</em> to start a new one.</p>
				</div>
			</section>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	public static function sc_message_button( $atts = array() ): string {
		$atts = shortcode_atts( array( 'to' => 0, 'product' => 0, 'label' => 'Message shop' ), $atts, 'mnu_message_button' );
		$to   = (int) $atts['to'];
		if ( ! $to || $to === get_current_user_id() ) {
			return '';
		}
		$url  = add_query_arg( array_filter( array( 'to' => $to, 'product' => (int) $atts['product'] ) ), home_url( '/messages/' ) );
		if ( ! is_user_logged_in() ) {
			$url = wp_login_url( $url );
		}
		return '<a class="tnm-button mnu-msg-btn" href="' . esc_url( $url ) . '">' . esc_html( $atts['label'] ) . '</a>';
	}

	/**
	 * Render an "Ask the seller about this item" CTA under Woo's add-to-cart button.
	 * Uses the marketplace-native /messages/?to=<seller>&product=<id> route.
	 */
	public static function render_product_ask_seller(): void {
		global $product;
		if ( ! $product || ! is_a( $product, 'WC_Product' ) ) return;
		$pid       = (int) $product->get_id();
		$seller_id = (int) get_post_field( 'post_author', $pid );
		if ( ! $seller_id ) return;
		$viewer_id = get_current_user_id();
		if ( $viewer_id && (int) $viewer_id === $seller_id ) return; // don't ask yourself
		$url = add_query_arg( array( 'to' => $seller_id, 'product' => $pid ), home_url( '/messages/' ) );
		if ( ! $viewer_id ) {
			$url = wp_login_url( $url );
		}
		echo '<a class="mnu-msg-cta mnu-msg-cta--product" href="' . esc_url( $url ) . '">'
			. '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H8l-5 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>'
			. '<span>Ask the seller about this item</span></a>';
	}

	/* =========================================================
	 *  INLINE CSS + JS
	 * ========================================================= */

	private static function inline_css(): string {
		return <<<CSS
		.mnu-directory,.mnu-shop,.mnu-following,.mnu-messages{max-width:1100px;margin:0 auto;padding:1rem}
		.mnu-directory__header h1,.mnu-following h1,.mnu-messages h1{margin:0 0 .25rem}
		.mnu-directory__self-banner{max-width:1100px;margin:1rem auto 0;padding:1rem 1.25rem;background:#fffdf7;border:1px solid #ecdfcd;border-radius:12px;display:flex;flex-wrap:wrap;gap:1rem;align-items:center;justify-content:space-between}
		.mnu-directory__self-copy{display:flex;flex-direction:column;gap:.15rem}
		.mnu-directory__self-copy strong{color:#2b2016}
		.mnu-button{display:inline-block;padding:.55rem 1.1rem;border-radius:999px;font-weight:600;font-size:.9rem;text-decoration:none;border:1px solid transparent;cursor:pointer}
		.mnu-button--primary{background:#245f4b;color:#fff}
		.mnu-button--primary:hover{background:#1c4c3c;color:#fff}
		.mnu-muted{color:#666}
		.mnu-search input[type=search]{width:100%;max-width:520px;padding:.75rem 1rem;border:1px solid #d4c9b6;border-radius:999px;font-size:1rem}
		.mnu-directory__grid,.mnu-following__grid,.mnu-shop__grid,.mnu-following__feed{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:1rem;margin-top:1rem}
		.mnu-shop-card{border:1px solid #ecdfcd;border-radius:12px;padding:1rem;background:#fffdf7;display:flex;flex-direction:column;gap:.5rem}
		.mnu-shop-card__row{display:flex;align-items:center;gap:.75rem}
		.mnu-shop-card__avatar{width:56px;height:56px;border-radius:50%;object-fit:cover;background:#f4ecdc}
		.mnu-shop-card__name{font-weight:600;font-size:1.05rem;color:#2b2016;text-decoration:none}
		.mnu-shop-card__name:hover{text-decoration:underline}
		.mnu-shop-card__meta{font-size:.8rem;color:#7a6b57}
		.mnu-follow-btn{cursor:pointer;background:#245f4b;color:#fff;border:0;border-radius:999px;padding:.4rem .9rem;font-weight:600;font-size:.85rem}
		.mnu-follow-btn.is-following{background:#f4ecdc;color:#245f4b;border:1px solid #245f4b}
		.mnu-follow-btn[disabled]{opacity:.6;cursor:wait}
		.mnu-notice{background:#fff8e1;border:1px solid #f0dfa4;border-radius:8px;padding:.75rem 1rem;margin:1rem auto;max-width:640px}
		.mnu-shop__hero{display:flex;gap:1.5rem;align-items:flex-start;padding:1.5rem;background:#fffdf7;border:1px solid #ecdfcd;border-radius:16px}
		.mnu-shop__banner{width:100%;height:180px;object-fit:cover;border-radius:12px;background:#f4ecdc;margin-bottom:1rem}
		.mnu-shop__avatar{width:96px;height:96px;border-radius:50%;object-fit:cover;background:#f4ecdc;flex-shrink:0}
		.mnu-shop__head-body{flex:1;min-width:0}
		.mnu-shop__head-body h1{margin:0 0 .25rem;font-size:1.6rem}
		.mnu-shop__actions{display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.75rem}
		.mnu-product-card{border:1px solid #ecdfcd;border-radius:12px;background:#fffdf7;overflow:hidden;display:flex;flex-direction:column}
		.mnu-product-card img{width:100%;aspect-ratio:1;object-fit:cover;background:#f4ecdc}
		.mnu-product-card__body{padding:.75rem;display:flex;flex-direction:column;gap:.25rem}
		.mnu-product-card__title{font-weight:600;color:#2b2016;text-decoration:none;font-size:.95rem}
		.mnu-product-card__price{font-size:.9rem;color:#245f4b;font-weight:600}
		/* --- Messages (app parity: cream surface, brown text, coral own bubbles) --- */
		.mnu-messages{--mnu-surface:#FFF8EF;--mnu-surface-2:#FFFFFF;--mnu-onSurface:#3E2723;--mnu-muted:#7A5C4E;--mnu-brand:#E2856E;--mnu-onBrand:#FFFFFF;--mnu-border:#EEDDCC;--mnu-shadow:0 1px 2px rgba(62,39,35,.06),0 4px 12px rgba(62,39,35,.05);
			display:grid;grid-template-columns:320px 1fr;gap:1rem;min-height:calc(100vh - 220px);max-width:1100px;margin:0 auto;padding:1rem;color:var(--mnu-onSurface)}
		@media(max-width:820px){.mnu-messages{grid-template-columns:1fr;min-height:auto}
			.mnu-messages.is-thread-open .mnu-messages__sidebar{display:none}
			.mnu-messages:not(.is-thread-open) .mnu-messages__thread{display:none}}
		.mnu-messages__sidebar,.mnu-messages__thread{background:var(--mnu-surface-2);border:1px solid var(--mnu-border);border-radius:16px;box-shadow:var(--mnu-shadow);display:flex;flex-direction:column;overflow:hidden;min-height:0}
		.mnu-messages__sidebar-head{display:flex;align-items:center;justify-content:space-between;gap:.5rem;padding:1rem 1.1rem;border-bottom:1px solid var(--mnu-border)}
		.mnu-messages__sidebar-head h1{margin:0;font-size:1.25rem;font-weight:800;color:var(--mnu-onSurface);letter-spacing:-.01em}
		.mnu-messages__refresh{background:transparent;border:0;border-radius:999px;width:36px;height:36px;display:inline-flex;align-items:center;justify-content:center;color:var(--mnu-muted);cursor:pointer;transition:background .15s,color .15s}
		.mnu-messages__refresh:hover{background:var(--mnu-surface);color:var(--mnu-onSurface)}
		.mnu-messages__refresh.is-spinning svg{animation:mnu-spin 1s linear infinite}
		@keyframes mnu-spin{to{transform:rotate(360deg)}}
		.mnu-messages__list{flex:1;overflow-y:auto;padding:.5rem}
		.mnu-messages__skeleton{padding:.75rem}
		.mnu-skel-row{height:56px;border-radius:10px;background:linear-gradient(90deg,#F5EBDC 0%,#FBF3E7 50%,#F5EBDC 100%);background-size:200% 100%;animation:mnu-shimmer 1.4s infinite;margin-bottom:.5rem}
		@keyframes mnu-shimmer{0%{background-position:200% 0}100%{background-position:-200% 0}}
		.mnu-conv-row{display:flex;gap:.75rem;align-items:center;padding:.65rem .75rem;border-radius:12px;cursor:pointer;transition:background .15s;position:relative}
		.mnu-conv-row:hover{background:var(--mnu-surface)}
		.mnu-conv-row.is-active{background:var(--mnu-surface)}
		.mnu-conv-row.is-active::before{content:"";position:absolute;left:0;top:12px;bottom:12px;width:3px;border-radius:0 3px 3px 0;background:var(--mnu-brand)}
		.mnu-conv-row__avatar{position:relative;flex-shrink:0}
		.mnu-conv-row__avatar img,.mnu-conv-row__avatar .mnu-avatar-fallback{width:44px;height:44px;border-radius:50%;object-fit:cover;background:var(--mnu-surface);display:flex;align-items:center;justify-content:center;color:var(--mnu-brand);font-weight:700;font-size:1rem}
		.mnu-conv-row__unread-dot{position:absolute;top:-2px;right:-2px;width:12px;height:12px;border-radius:50%;background:var(--mnu-brand);border:2px solid var(--mnu-surface-2)}
		.mnu-conv-row__body{flex:1;min-width:0}
		.mnu-conv-row__top{display:flex;justify-content:space-between;align-items:baseline;gap:.5rem}
		.mnu-conv-row__name{font-size:.95rem;font-weight:600;color:var(--mnu-onSurface);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
		.mnu-conv-row.is-unread .mnu-conv-row__name{font-weight:800}
		.mnu-conv-row__time{font-size:.75rem;color:var(--mnu-muted);flex-shrink:0}
		.mnu-conv-row__preview{font-size:.85rem;color:var(--mnu-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:2px}
		.mnu-conv-row.is-unread .mnu-conv-row__preview{color:var(--mnu-onSurface)}
		.mnu-messages__empty{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:2rem;gap:.5rem}
		.mnu-empty-icon{color:var(--mnu-brand);opacity:.7;margin-bottom:.5rem}
		.mnu-messages__empty h2{margin:0;font-size:1.1rem;font-weight:700;color:var(--mnu-onSurface)}
		.mnu-messages__empty p{margin:0;max-width:320px}
		.mnu-thread__head{display:flex;gap:.75rem;align-items:center;padding:.85rem 1.1rem;border-bottom:1px solid var(--mnu-border);cursor:pointer;background:var(--mnu-surface-2)}
		.mnu-thread__head:hover{background:var(--mnu-surface)}
		.mnu-thread__back{background:transparent;border:0;padding:0 .25rem 0 0;color:var(--mnu-muted);cursor:pointer;display:none;align-items:center;justify-content:center}
		@media(max-width:820px){.mnu-thread__back{display:inline-flex}}
		.mnu-thread__head img,.mnu-thread__head .mnu-avatar-fallback{width:40px;height:40px;border-radius:50%;object-fit:cover;background:var(--mnu-surface);display:flex;align-items:center;justify-content:center;color:var(--mnu-brand);font-weight:700}
		.mnu-thread__head-info{flex:1;min-width:0}
		.mnu-thread__head-name{font-weight:700;color:var(--mnu-onSurface);font-size:1rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
		.mnu-thread__head-sub{font-size:.75rem;color:var(--mnu-muted)}
		.mnu-thread__msgs{flex:1;overflow-y:auto;padding:1rem 1.1rem;display:flex;flex-direction:column;gap:2px}
		.mnu-thread__time-header{align-self:center;font-size:.72rem;color:var(--mnu-muted);text-transform:uppercase;letter-spacing:.05em;margin:.75rem 0 .35rem;font-weight:600}
		.mnu-msg{max-width:75%;padding:.55rem .8rem;border-radius:18px;font-size:.95rem;line-height:1.35;white-space:pre-wrap;word-wrap:break-word;overflow-wrap:anywhere}
		.mnu-msg + .mnu-msg{margin-top:2px}
		.mnu-msg.mine{align-self:flex-end;background:var(--mnu-brand);color:var(--mnu-onBrand);border-bottom-right-radius:6px}
		.mnu-msg.mine.is-pending{opacity:.7}
		.mnu-msg.theirs{align-self:flex-start;background:var(--mnu-surface);color:var(--mnu-onSurface);border-bottom-left-radius:6px;border:1px solid var(--mnu-border)}
		.mnu-msg a{color:inherit;text-decoration:underline;text-underline-offset:2px}
		.mnu-msg-form{padding:.65rem .85rem;border-top:1px solid var(--mnu-border);background:var(--mnu-surface-2);display:flex;gap:.5rem;align-items:flex-end}
		.mnu-msg-form textarea{flex:1;resize:none;min-height:40px;max-height:160px;padding:.55rem .85rem;border:1px solid var(--mnu-border);border-radius:20px;font-family:inherit;font-size:.95rem;background:var(--mnu-surface);color:var(--mnu-onSurface);line-height:1.35;outline:none;transition:border-color .15s}
		.mnu-msg-form textarea:focus{border-color:var(--mnu-brand)}
		.mnu-msg-form button{background:var(--mnu-brand);color:var(--mnu-onBrand);border:0;border-radius:999px;width:40px;height:40px;flex-shrink:0;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;transition:transform .1s,opacity .15s}
		.mnu-msg-form button:hover:not([disabled]){transform:scale(1.05)}
		.mnu-msg-form button[disabled]{opacity:.4;cursor:not-allowed}
		.mnu-msg-form button svg{width:18px;height:18px}
		.mnu-thread__error{padding:.5rem .85rem;background:#FEECEB;color:#B0322A;border-top:1px solid #F4CCCA;font-size:.85rem;text-align:center}
		/* --- Shared Message CTA (used on shop profile + product detail) --- */
		.mnu-msg-cta{display:inline-flex;align-items:center;gap:.4rem;padding:.55rem 1rem;background:#fff;color:#E2856E;border:1px solid #E2856E;border-radius:999px;font-weight:700;font-size:.95rem;text-decoration:none;line-height:1;transition:background .15s,color .15s,transform .1s}
		.mnu-msg-cta:hover{background:#E2856E;color:#fff;transform:translateY(-1px)}
		.mnu-msg-cta svg{flex-shrink:0}
		.mnu-msg-cta--product{display:flex;justify-content:center;margin:.75rem 0 0;width:100%}
		/* --- Shop hero: actions + inline bio --- */
		.mnu-shop__actions-row{display:flex;flex-wrap:wrap;align-items:center;gap:.75rem 1rem;margin-top:.85rem}
		.mnu-shop__actions{display:flex;flex-wrap:wrap;gap:.5rem;flex-shrink:0}
		.mnu-shop__bio{margin:0;flex:1;min-width:260px;color:#3E2723;font-size:.95rem;line-height:1.45;white-space:pre-wrap;overflow-wrap:anywhere;padding-left:1rem;border-left:2px solid #EEDDCC}
		@media(max-width:640px){.mnu-shop__bio{flex-basis:100%;padding-left:0;border-left:0;padding-top:.5rem;border-top:1px solid #EEDDCC;margin-top:.25rem}}
		.mnu-pill{display:inline-block;padding:.15rem .55rem;background:#245f4b;color:#fff;border-radius:999px;font-size:.75rem;font-weight:600;margin-left:.25rem}
CSS;
	}

	private static function inline_js(): string {
		return <<<'JS'
		(function(){
		if (typeof TNMFrontend === 'undefined') { return; }
		var esc = function(s){ return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];}); };
		var api = function(path, opts){
			opts = opts || {};
			var headers = { 'Content-Type':'application/json', 'X-WP-Nonce': TNMFrontend.restNonce };
			return fetch(TNMFrontend.restRoot + 'the-nest/v1/' + path, {
				method: opts.method || 'GET',
				headers: headers,
				body: opts.body ? JSON.stringify(opts.body) : undefined,
				credentials: 'same-origin'
			}).then(function(r){ return r.json().then(function(j){ return { ok:r.ok, status:r.status, data:j }; }); });
		};

		/* ---------- FOLLOW BUTTON (reusable) ---------- */
		function followBtn(sid, isFollowing) {
			var b = document.createElement('button');
			b.className = 'mnu-follow-btn' + (isFollowing ? ' is-following' : '');
			b.type = 'button';
			b.textContent = isFollowing ? 'Following' : 'Follow';
			b.addEventListener('click', function(){
				if (!TNMFrontend.currentUid) { window.location = '/wp-login.php?redirect_to=' + encodeURIComponent(window.location.href); return; }
				b.disabled = true;
				var following = b.classList.contains('is-following');
				api('sellers/' + sid + '/follow', { method: following ? 'DELETE' : 'POST' }).then(function(r){
					b.disabled = false;
					if (r.ok) {
						b.classList.toggle('is-following');
						b.textContent = following ? 'Follow' : 'Following';
					}
				});
			});
			return b;
		}

		/* ---------- SELLER DIRECTORY ---------- */
		var dir = document.querySelector('[data-mnu-directory]');
		if (dir) {
			var q = dir.querySelector('[data-mnu-directory-q]');
			var grid = dir.querySelector('[data-mnu-directory-grid]');
			var timer;
			function loadDir(search){
				grid.innerHTML = '<p class="mnu-muted">Loading shops…</p>';
				api('sellers?search=' + encodeURIComponent(search || '') + '&per_page=48').then(function(r){
					if (!r.ok || !r.data.items) { grid.innerHTML = '<p class="mnu-muted">Could not load shops.</p>'; return; }
					if (r.data.items.length === 0) { grid.innerHTML = '<p class="mnu-muted">No shops match your search.</p>'; return; }
					grid.innerHTML = '';
					r.data.items.forEach(function(s){
						var card = document.createElement('div');
						card.className = 'mnu-shop-card';
						card.innerHTML =
							'<div class="mnu-shop-card__row">' +
								'<img class="mnu-shop-card__avatar" src="' + esc(s.avatar) + '" alt="" />' +
								'<div style="flex:1;min-width:0"><a class="mnu-shop-card__name" href="' + esc(s.shop_url) + '">' + esc(s.store_name) + '</a>' +
								'<div class="mnu-shop-card__meta">' + s.product_count + ' products · ' + s.follower_count + ' followers</div></div>' +
							'</div>' +
							(s.about_snippet ? '<div class="mnu-muted" style="font-size:.85rem">' + esc(s.about_snippet) + '</div>' : '') +
							'<div class="mnu-shop-card__actions"></div>';
						card.querySelector('.mnu-shop-card__actions').appendChild(followBtn(s.id, !!s.is_following));
						grid.appendChild(card);
					});
				});
			}
			q.addEventListener('input', function(){
				clearTimeout(timer);
				timer = setTimeout(function(){ loadDir(q.value); }, 250);
			});
			loadDir('');
		}

		/* ---------- SHOP PROFILE ---------- */
		var shop = document.querySelector('[data-mnu-shop]');
		if (shop) {
			var sid = shop.getAttribute('data-seller-id');
			var hero = shop.querySelector('[data-mnu-shop-hero]');
			var grid = shop.querySelector('[data-mnu-shop-products]');
			api('sellers/' + sid).then(function(r){
				if (!r.ok) { hero.innerHTML = '<p>Shop not found.</p>'; return; }
				var s = r.data;
				var banner = s.banner ? '<img class="mnu-shop__banner" src="' + esc(s.banner) + '" alt="" />' : '';
				hero.innerHTML =
					banner +
					'<div style="display:flex;gap:1.5rem;align-items:flex-start;width:100%">' +
						'<img class="mnu-shop__avatar" src="' + esc(s.avatar) + '" alt="" />' +
						'<div class="mnu-shop__head-body">' +
							'<h1>' + esc(s.store_name) + '</h1>' +
							'<div class="mnu-muted">' + s.followers + ' followers' + (s.review_count ? ' · ' + s.rating.toFixed(1) + '★ (' + s.review_count + ')' : '') + '</div>' +
							'<div class="mnu-shop__actions-row">' +
								'<div class="mnu-shop__actions"></div>' +
								(s.about ? '<p class="mnu-shop__bio">' + esc(s.about) + '</p>' : '') +
							'</div>' +
						'</div>' +
					'</div>';
				var actions = hero.querySelector('.mnu-shop__actions');
				actions.appendChild(followBtn(s.id, !!s.is_following));
				if (TNMFrontend.currentUid && TNMFrontend.currentUid !== s.id) {
					var msg = document.createElement('a');
					msg.className = 'mnu-msg-cta';
					msg.href = '/messages/?to=' + s.id;
					msg.innerHTML = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H8l-5 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg><span>Message shop</span>';
					actions.appendChild(msg);
				}
			});
			api('sellers/' + sid + '/products').then(function(r){
				if (!r.ok || !r.data.items) { grid.innerHTML = '<p class="mnu-muted">No products yet.</p>'; return; }
				var items = r.data.items || r.data;
				if (!items.length) { grid.innerHTML = '<p class="mnu-muted">No products yet.</p>'; return; }
				grid.innerHTML = '';
				items.forEach(function(p){
					var img = p.image || p.thumbnail || '';
					var url = p.permalink || p.url || '#';
					var title = p.title || p.name || 'Product';
					var price = p.price_html || p.price || '';
					var card = document.createElement('div');
					card.className = 'mnu-product-card';
					card.innerHTML = '<a href="' + esc(url) + '"><img src="' + esc(img) + '" alt="" /></a>' +
						'<div class="mnu-product-card__body"><a class="mnu-product-card__title" href="' + esc(url) + '">' + esc(title) + '</a><div class="mnu-product-card__price">' + price + '</div></div>';
					grid.appendChild(card);
				});
			});
		}

		/* ---------- FOLLOWING ---------- */
		var followWrap = document.querySelector('[data-mnu-following]');
		if (followWrap) {
			var fgrid = followWrap.querySelector('[data-mnu-following-grid]');
			var ffeed = followWrap.querySelector('[data-mnu-following-feed]');
			api('following').then(function(r){
				if (!r.ok || !r.data.length) { fgrid.innerHTML = '<p class="mnu-muted">You are not following any shops yet. <a href="/sellers/">Browse shops</a>.</p>'; return; }
				fgrid.innerHTML = '';
				r.data.forEach(function(s){
					var card = document.createElement('div');
					card.className = 'mnu-shop-card';
					card.innerHTML =
						'<div class="mnu-shop-card__row">' +
							'<img class="mnu-shop-card__avatar" src="' + esc(s.avatar) + '" alt="" />' +
							'<div style="flex:1;min-width:0"><a class="mnu-shop-card__name" href="' + esc(s.shop_url) + '">' + esc(s.store_name) + '</a>' +
							'<div class="mnu-shop-card__meta">' + s.product_count + ' products · ' + s.follower_count + ' followers</div></div>' +
						'</div><div class="mnu-shop-card__actions"></div>';
					card.querySelector('.mnu-shop-card__actions').appendChild(followBtn(s.id, true));
					fgrid.appendChild(card);
				});
			});
			api('following/feed').then(function(r){
				if (!r.ok || !r.data.length) { ffeed.innerHTML = '<p class="mnu-muted">No recent listings from shops you follow.</p>'; return; }
				ffeed.innerHTML = '';
				r.data.forEach(function(p){
					var card = document.createElement('div');
					card.className = 'mnu-product-card';
					card.innerHTML = '<a href="' + esc(p.permalink) + '"><img src="' + esc(p.image) + '" alt="" /></a>' +
						'<div class="mnu-product-card__body">' +
							'<a class="mnu-product-card__title" href="' + esc(p.permalink) + '">' + esc(p.title) + '</a>' +
							'<div class="mnu-product-card__price">' + p.price_html + '</div>' +
							'<div class="mnu-shop-card__meta"><a href="' + esc(p.seller.shop_url) + '">' + esc(p.seller.store_name) + '</a></div>' +
						'</div>';
					ffeed.appendChild(card);
				});
			});
		}

		/* ---------- MESSAGES ---------- */
		var msg = document.querySelector('[data-mnu-messages]');
		if (msg) {
			var listEl = msg.querySelector('[data-mnu-conversation-list]');
			var threadEl = msg.querySelector('[data-mnu-thread]');
			var refreshBtn = msg.querySelector('[data-mnu-refresh]');
			var preselect = parseInt(msg.getAttribute('data-preselect'), 10) || 0;
			var productCtx = parseInt(msg.getAttribute('data-product-context'), 10) || 0;
			var current = null;
			var cache = {}; // uid -> {user, msgs}

			function initials(name) {
				var parts = String(name || '').trim().split(/\s+/).filter(Boolean);
				if (!parts.length) return '?';
				if (parts.length === 1) return parts[0].charAt(0).toUpperCase();
				return (parts[0].charAt(0) + parts[parts.length-1].charAt(0)).toUpperCase();
			}

			function avatarHtml(user, cls) {
				var name = user && (user.store_name || user.display_name || '');
				var url = user && user.avatar;
				if (url && String(url).indexOf('gravatar') === -1) {
					return '<img src="' + esc(url) + '" alt="" onerror="this.replaceWith(Object.assign(document.createElement(\'div\'),{className:\'mnu-avatar-fallback\',textContent:'+JSON.stringify(initials(name))+'}))" />';
				}
				return '<div class="mnu-avatar-fallback">' + esc(initials(name)) + '</div>';
			}

			function parseDate(s) {
				if (!s) return null;
				// backend returns "YYYY-MM-DD HH:MM:SS" (MySQL UTC)
				var iso = String(s).indexOf('T') === -1 ? String(s).replace(' ', 'T') + 'Z' : s;
				var d = new Date(iso);
				return isNaN(d.getTime()) ? null : d;
			}

			function relTime(s) {
				var d = parseDate(s); if (!d) return '';
				var diff = (Date.now() - d.getTime()) / 1000;
				if (diff < 60) return 'now';
				if (diff < 3600) return Math.floor(diff/60) + 'm';
				if (diff < 86400) return Math.floor(diff/3600) + 'h';
				if (diff < 604800) return Math.floor(diff/86400) + 'd';
				return d.toLocaleDateString(undefined, { month:'short', day:'numeric' });
			}

			function timeHeader(s) {
				var d = parseDate(s); if (!d) return '';
				var now = new Date();
				var sameDay = d.toDateString() === now.toDateString();
				var yest = new Date(now); yest.setDate(now.getDate()-1);
				var isYest = d.toDateString() === yest.toDateString();
				var time = d.toLocaleTimeString(undefined, { hour:'numeric', minute:'2-digit' });
				if (sameDay) return time;
				if (isYest) return 'Yesterday · ' + time;
				return d.toLocaleDateString(undefined, { weekday:'short', month:'short', day:'numeric' }) + ' · ' + time;
			}

			function linkify(text) {
				var safe = esc(text);
				return safe.replace(/(https?:\/\/[^\s<]+)/g, function(u){ return '<a href="' + u + '" target="_blank" rel="noopener noreferrer">' + u + '</a>'; });
			}

			function renderConversations(items) {
				if (!items.length && !preselect) {
					listEl.innerHTML = '<div class="mnu-messages__empty" style="padding:1.5rem;"><p class="mnu-muted">No messages yet. Open a shop and click <em>Message shop</em> to start a conversation.</p></div>';
					return;
				}
				listEl.innerHTML = '';
				items.forEach(function(c){
					var row = document.createElement('div');
					row.className = 'mnu-conv-row' + (c.unread ? ' is-unread' : '');
					row.setAttribute('data-user-id', c.user.id);
					row.setAttribute('role', 'button');
					row.setAttribute('tabindex', '0');
					row.innerHTML =
						'<div class="mnu-conv-row__avatar">' + avatarHtml(c.user) + (c.unread ? '<span class="mnu-conv-row__unread-dot" aria-label="Unread"></span>' : '') + '</div>' +
						'<div class="mnu-conv-row__body">' +
							'<div class="mnu-conv-row__top">' +
								'<span class="mnu-conv-row__name">' + esc(c.user.store_name || c.user.display_name) + '</span>' +
								'<span class="mnu-conv-row__time">' + esc(relTime(c.date)) + '</span>' +
							'</div>' +
							'<div class="mnu-conv-row__preview">' + esc(c.last_message) + '</div>' +
						'</div>';
					var open = function(){ openThread(c.user.id, c.user); };
					row.addEventListener('click', open);
					row.addEventListener('keydown', function(e){ if (e.key==='Enter'||e.key===' ') { e.preventDefault(); open(); } });
					listEl.appendChild(row);
				});
				if (preselect) {
					var pre = items.find(function(c){ return c.user.id === preselect; });
					if (pre) { openThread(preselect, pre.user); }
					else { openThread(preselect, null); }
					preselect = 0; // one-shot
				}
			}

			function renderMessages(msgsEl, msgs) {
				msgsEl.innerHTML = '';
				var lastTime = 0;
				msgs.forEach(function(m){
					var d = parseDate(m.created_at);
					var t = d ? d.getTime() : 0;
					if (t && t - lastTime > 15*60*1000) {
						var h = document.createElement('div');
						h.className = 'mnu-thread__time-header';
						h.textContent = timeHeader(m.created_at);
						msgsEl.appendChild(h);
					}
					lastTime = t || lastTime;
					var el = document.createElement('div');
					el.className = 'mnu-msg ' + (m.sender_id === TNMFrontend.currentUid ? 'mine' : 'theirs');
					el.innerHTML = linkify(m.message);
					msgsEl.appendChild(el);
				});
			}

			function openThread(uid, userMeta) {
				current = uid;
				msg.classList.add('is-thread-open');
				Array.prototype.forEach.call(listEl.querySelectorAll('.mnu-conv-row'), function(r){
					var active = parseInt(r.getAttribute('data-user-id'),10) === uid;
					r.classList.toggle('is-active', active);
					if (active) r.classList.remove('is-unread');
				});
				threadEl.innerHTML = '<div class="mnu-messages__empty"><p class="mnu-muted">Loading conversation…</p></div>';
				var metaPromise = userMeta ? Promise.resolve(userMeta) : api('sellers/' + uid).then(function(r){ return r.ok ? { id: uid, store_name: r.data.store_name, avatar: r.data.avatar } : { id: uid, store_name: 'Shop', avatar: '' }; });
				Promise.all([ metaPromise, api('messages/' + uid) ]).then(function(res){
					if (current !== uid) return; // user switched away
					var user = res[0];
					var msgs = (res[1].ok && Array.isArray(res[1].data)) ? res[1].data : [];
					cache[uid] = { user: user, msgs: msgs };
					var shopUrl = user.shop_url || (user.id ? '/shop-profile/?seller=' + user.id : '#');
					threadEl.innerHTML =
						'<div class="mnu-thread__head" data-mnu-thread-head role="button" tabindex="0">' +
							'<button type="button" class="mnu-thread__back" data-mnu-thread-back aria-label="Back to conversations">' +
								'<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>' +
							'</button>' +
							avatarHtml(user) +
							'<div class="mnu-thread__head-info">' +
								'<div class="mnu-thread__head-name">' + esc(user.store_name || 'Shop') + '</div>' +
								'<div class="mnu-thread__head-sub">Tap to view shop</div>' +
							'</div>' +
						'</div>' +
						'<div class="mnu-thread__msgs" data-mnu-thread-msgs></div>' +
						'<form class="mnu-msg-form" data-mnu-msg-form>' +
							'<textarea placeholder="Message…" data-mnu-msg-body required rows="1" maxlength="5000"></textarea>' +
							'<button type="submit" aria-label="Send" disabled>' +
								'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4 20-7z"/></svg>' +
							'</button>' +
						'</form>';

					var head = threadEl.querySelector('[data-mnu-thread-head]');
					var back = threadEl.querySelector('[data-mnu-thread-back]');
					head.addEventListener('click', function(e){
						if (back && back.contains(e.target)) return;
						window.location.href = shopUrl;
					});
					head.addEventListener('keydown', function(e){ if (e.key==='Enter') window.location.href = shopUrl; });
					back && back.addEventListener('click', function(ev){ ev.stopPropagation(); msg.classList.remove('is-thread-open'); current = null; });

					var msgsEl = threadEl.querySelector('[data-mnu-thread-msgs]');
					renderMessages(msgsEl, msgs);
					msgsEl.scrollTop = msgsEl.scrollHeight;

					var form = threadEl.querySelector('[data-mnu-msg-form]');
					var ta = form.querySelector('[data-mnu-msg-body]');
					var btn = form.querySelector('button');
					var autosize = function(){ ta.style.height = 'auto'; ta.style.height = Math.min(160, ta.scrollHeight) + 'px'; };
					ta.addEventListener('input', function(){ autosize(); btn.disabled = !ta.value.trim(); });
					ta.addEventListener('keydown', function(e){ if (e.key==='Enter' && !e.shiftKey) { e.preventDefault(); form.requestSubmit ? form.requestSubmit() : form.dispatchEvent(new Event('submit', {cancelable:true})); } });

					form.addEventListener('submit', function(ev){
						ev.preventDefault();
						var text = ta.value.trim(); if (!text) return;
						btn.disabled = true;
						// optimistic pending bubble
						var pending = document.createElement('div');
						pending.className = 'mnu-msg mine is-pending';
						pending.innerHTML = linkify(text);
						msgsEl.appendChild(pending);
						msgsEl.scrollTop = msgsEl.scrollHeight;
						ta.value = ''; autosize();
						var body = { recipient_id: uid, message: text };
						if (productCtx) { body.product_id = productCtx; }
						api('messages', { method: 'POST', body: body }).then(function(r){
							if (r.ok) {
								pending.classList.remove('is-pending');
								productCtx = 0;
								// refresh sidebar previews
								loadConversations();
							} else {
								pending.remove();
								ta.value = text; autosize(); btn.disabled = false;
								var err = document.createElement('div');
								err.className = 'mnu-thread__error';
								err.textContent = (r.data && r.data.message) || 'Could not send message. Please try again.';
								form.parentNode.insertBefore(err, form);
								setTimeout(function(){ err.remove(); }, 4000);
							}
						});
					});
					ta.focus();
				});
			}

			function loadConversations() {
				if (refreshBtn) refreshBtn.classList.add('is-spinning');
				return api('messages').then(function(r){
					var items = (r.ok && Array.isArray(r.data)) ? r.data : [];
					renderConversations(items);
					if (refreshBtn) refreshBtn.classList.remove('is-spinning');
				});
			}

			refreshBtn && refreshBtn.addEventListener('click', loadConversations);
			document.addEventListener('visibilitychange', function(){ if (!document.hidden) loadConversations(); });
			loadConversations();
		}
		})();
JS;
	}
}
