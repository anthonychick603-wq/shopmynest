# MyNest Trust & Growth Suite

A standalone WordPress/WooCommerce companion plugin for **The Nest** (shopmynest.com) that adds Etsy/Vinted-style buyer protection, seller performance badges, favorites + personalized feed ranking, bundles/offers, structured product attributes, and listing boosts / a Pro Seller tier.

This plugin is designed to install and run **independently** alongside the existing "MyNest Unified Marketplace" plugin. It never modifies that plugin's files or writes to its database tables — it only reads from them, defensively, and degrades gracefully (logs a warning and skips) if a table, function, or class it expects isn't present. **WooCommerce is the only hard dependency.**

## Install (no SSH / WP-CLI required)

1. Zip the `mynest-trust-suite` folder (the folder containing `mynest-trust-suite.php`) into `mynest-trust-suite.zip`.
2. In wp-admin, go to **Plugins → Add New → Upload Plugin**.
3. Choose the zip file and click **Install Now**, then **Activate**.
4. On activation the plugin will:
   - Create its own database tables (`wp_tnm_trust_disputes`, `wp_tnm_trust_favorites`, `wp_tnm_trust_offers`, `wp_tnm_trust_boosts`).
   - Register the `pa_condition` (with fixed terms), `pa_size`, and `pa_brand` global WooCommerce attributes.
   - Create a hidden virtual "Listing Boost" WooCommerce product used for boost purchases.
   - Schedule an hourly WP-Cron event used to auto-expire stale offers and boosts.
5. Configure thresholds and prices under **wp-admin → Nest Trust Suite → Settings**.

If WooCommerce is not active, the plugin will show an admin notice and disable itself safely (no fatal error).

## REST API — namespace `nest-trust/v1`

All write routes require an authenticated WordPress session (cookie + `X-WP-Nonce` header — the same pattern any logged-in browser session or the mobile app bridge already uses for WP REST). Read `GET /disputes`, `POST /disputes`, etc. require login; admin-only routes additionally require `manage_woocommerce` capability; `POST /boosts` requires the `tnm_seller`/`mynest_seller` role or admin.

### Disputes (Feature 1)

- `POST /wp-json/nest-trust/v1/disputes`
  ```json
  { "order_id": 4821, "reason": "not_arrived", "description": "Item never arrived after 3 weeks.", "contacted_seller_at": "2026-07-10T12:00:00Z", "evidence": ["https://example.com/photo1.jpg"] }
  ```
  Response:
  ```json
  { "dispute": { "id": 12, "order_id": 4821, "status": "open", "...": "..." }, "warning": null }
  ```
  Claim window defaults to 100 days from order date (configurable). If `contacted_seller_at` is omitted, the dispute is still created but a `warning` is returned; if supplied and less than the configured minimum wait (default 48h) has elapsed, a `warning` is also returned.

- `GET /wp-json/nest-trust/v1/disputes` — buyer sees their own; seller sees disputes on their orders; admin sees all. Optional `?status=open`.
- `GET /wp-json/nest-trust/v1/disputes/{id}`
- `PUT /wp-json/nest-trust/v1/disputes/{id}` — seller can add a `resolution_note` while status is `open`/`awaiting_seller` (moves to `awaiting_buyer`); admin can set `status`, `resolution_note`, `refund_amount`.
- `POST /wp-json/nest-trust/v1/disputes/{id}/escalate` — buyer escalates to admin review once the configured SLA (default 5 days) has passed without resolution.
- `POST /wp-json/nest-trust/v1/disputes/{id}/resolve` — **admin only**. Body: `{ "status": "resolved_refund", "refund_amount": 24.99, "resolution_note": "..." }`. On `resolved_refund`/`resolved_partial`, triggers a WooCommerce refund via `wc_create_refund()` and, if the other plugin's `ledger` table exists, writes a compensating negative `dispute_refund` row referencing the order.

### Seller Performance Badge (Feature 2)

- `GET /wp-json/nest-trust/v1/sellers/{id}/badge` — **public**.
  ```json
  {
    "tier": "trusted_seller",
    "tier_label": "Trusted Seller",
    "metrics": { "on_time_rate": 97.5, "avg_rating": 4.9, "response_rate": 96.2, "completed_orders": 18, "gmv": 1240.50 },
    "meets_minimum_volume": true
  }
  ```
  Computed on demand and cached in a 1-hour transient per seller (`tnm_trust_badge_{seller_id}`). Rolling 90-day window. Tiers: `none` / `rising_seller` ("Rising Seller") / `trusted_seller` ("Trusted Seller"). Thresholds configurable under Settings.

### Favorites + Feed (Feature 3)

- `POST /wp-json/nest-trust/v1/favorites` — body `{ "product_id": 123 }`, toggles favorite on/off for the current user.
- `DELETE /wp-json/nest-trust/v1/favorites/{product_id}`
- `GET /wp-json/nest-trust/v1/favorites` — current user's favorited product IDs.
- `GET /wp-json/nest-trust/v1/products/{id}/favorites-count` — **public**.
- `GET /wp-json/nest-trust/v1/feed?page=1&per_page=20&category=vintage-denim` — **public, NEW endpoint**. Does **not** override the other plugin's existing `/feed` route — **switch your site/app to this URL to get personalization**. Ranks WooCommerce products by a weighted score combining recency, favorites count, whether the requesting user follows the seller (read defensively from the other plugin's `follows` table), seller badge tier (Feature 2), total sales, and active boosts (Feature 6). Weights are filterable — see **Filters this plugin provides** below.

### Bundles + Make an Offer (Feature 4)

- `POST /wp-json/nest-trust/v1/offers` — body `{ "type": "bundle", "product_ids": [123, 456], "offer_price": 45.00 }`. All products must share exactly one seller.
- `GET /wp-json/nest-trust/v1/offers?status=pending` — buyer/seller filtered list.
- `PUT /wp-json/nest-trust/v1/offers/{id}` — body `{ "action": "accept" }` / `{ "action": "decline" }` / `{ "action": "counter", "counter_price": 40.00 }`. Seller can accept/decline/counter a `pending` offer; buyer can accept/decline a `countered` offer. Accepting generates a single-use checkout token (24h expiry).
- `POST /wp-json/nest-trust/v1/offers/checkout/start` — body `{ "token": "..." }`. **Browser/session clients only.** Adds the offer's product(s) to the WC cart and flags the session so the negotiated price is applied at checkout (via `woocommerce_before_calculate_totals`). Pair with shortcode `[nest_trust_offer_checkout token="..."]` or `?nest_offer_token=...` on your checkout page. This relies on a PHP session tied to browser cookies, so it will silently fail to apply pricing for native/API clients that don't share a cookie jar across requests (e.g. mobile apps).
- `POST /wp-json/nest-trust/v1/offers/checkout/order` — body `{ "token": "..." }`. **Native/API clients (e.g. the mobile app).** Creates a real `WC_Order` directly at the negotiated offer price — no cart or session required — and returns `{ "order_id": ..., "checkout_url": "..." }`. Open `checkout_url` in a browser or in-app WebView to complete payment through WooCommerce's normal checkout, the same pattern used for boost purchases (`/boosts`). The offer's checkout token is marked used automatically once the resulting order reaches `processing`/`completed` status.
- `POST /wp-json/nest-trust/v1/bundle-builder` — body `{ "product_id": 123 }`. Adds a product to the buyer's session-based bundle builder (grouped per seller).
- `GET /wp-json/nest-trust/v1/bundle-builder` — current session's bundle-builder contents.
- Offers auto-expire hourly via WP-Cron (`tnm_trust_hourly_event`) when `pending`/`countered` and past `expires_at` (48h from creation by default).
- Bundle shipping discount: when 2+ items from the same seller are in the cart via an accepted bundle offer, shipping rates are discounted via `woocommerce_package_rates` (first item / additional item discount % configurable under Settings).

### Structured Attributes (Feature 5)

No custom REST needed — `pa_condition`, `pa_size`, `pa_brand` are native WooCommerce global attributes/taxonomies once registered, so the existing WooCommerce REST API already supports them, e.g.:

```
GET /wp-json/wc/v3/products?attribute=pa_condition&attribute_term=<term_id>
```

Shortcode `[nest_trust_filters]` renders condition/size/brand checkboxes for shop/category archive pages using WooCommerce's native attribute filtering (submits via query string).

### Boosts + Pro Seller (Feature 6)

- `POST /wp-json/nest-trust/v1/boosts` — **seller or admin only**. Body `{ "product_id": 123, "tier": "3day" }`. Creates a WooCommerce order for the hidden "Listing Boost" product and returns `{ "checkout_url": "...", "order_id": 987, "boost_id": 5 }`. The boost activates automatically once that order's status changes to `completed`/`processing`.
- `GET /wp-json/nest-trust/v1/sellers/{id}/pro-status` — **public**. `{ "seller_id": 42, "pro_seller": true }`.
- Pro Seller status is a manual admin toggle for v1 (**wp-admin → Nest Trust Suite → Pro Sellers**) — no automated recurring billing. A note is included below on wiring in WooCommerce Subscriptions later if desired.

## Shortcodes

| Shortcode | Description |
|---|---|
| `[nest_trust_my_disputes]` | Buyer-facing dispute list + "open a new dispute" form. |
| `[nest_trust_seller_disputes]` | Seller-facing list of disputes against their orders, with a respond form. |
| `[nest_trust_seller_badge id="123"]` | Renders the seller's performance badge (inline SVG, no external assets) with a metrics tooltip. Renders nothing if the seller has no badge. |
| `[nest_trust_favorite_button product_id="123"]` | Standalone favorite heart button (also auto-injected on shop loop items and single product pages). |
| `[nest_trust_feed]` | Responsive CSS-grid personalized product feed (consumes `GET /feed` via fetch). |
| `[nest_trust_make_offer product_id="123"]` | "Make an offer" button/modal + "Add to Bundle" button for a single product page. |
| `[nest_trust_offer_checkout token="..."]` | Applies an accepted offer's negotiated price to the cart and redirects to checkout. Token can also be passed as `?nest_offer_token=...`. |
| `[nest_trust_filters]` | Condition / Size / Brand filter checkboxes for shop/category archives. |

## Filters & hooks this plugin PROVIDES (for the other plugin, or any code, to consume)

These are hooks **this plugin registers and calls** — no changes to this plugin are required to use them from anywhere else in WordPress (theme, other plugins, etc).

- **`tnm_trust_can_release_earnings`** (filter) — `apply_filters( 'tnm_trust_can_release_earnings', true, $order_id )`. Returns `false` if an order has an open dispute. Also exposed directly as a public static method: `TNM_Trust_Disputes::has_open_dispute( $order_id )`.
- **`tnm_trust_feed_weights`** (filter) — `apply_filters( 'tnm_trust_feed_weights', $weights )` where `$weights` is an associative array with keys `recency`, `favorites`, `follows`, `badge`, `sales`, `boost`. Adjust to retune feed ranking.
- **`tnm_trust_seller_fee_percent`** (filter, **provided but not yet called by anything** — see integration point #2 below) — `apply_filters( 'tnm_trust_seller_fee_percent', $default_fee_percent, $seller_id )`. This plugin hooks this filter and returns a reduced fee for Pro Sellers.

## Two integration points that need a tiny one-line hook added to the OTHER plugin's code

Because we don't have write access to the "MyNest Unified Marketplace" plugin's source, these two features are **fully built and ready on this plugin's side**, but need a developer with file access to that plugin to add one line each. Everything else in this plugin works with zero changes to the other plugin.

### 1. Ledger-release / dispute hold (Feature 1)

Wherever the other plugin's ledger-release cron/job currently decides an order's held earnings are eligible for payout, add this check immediately before releasing funds:

```php
// Add this check before releasing an order's held earnings to a seller's payout.
if ( ! apply_filters( 'tnm_trust_can_release_earnings', true, $order_id ) ) {
    // Skip this order for now — it has an open buyer-protection dispute.
    continue; // or `return;`, depending on the surrounding loop structure
}
```

If the Trust Suite plugin is inactive, `apply_filters()` is a safe no-op and simply returns `true` (default), so this line is safe to add permanently regardless of whether Trust Suite is installed.

### 2. Pro Seller reduced platform fee (Feature 6)

Wherever the other plugin computes the platform fee percent for a seller (e.g. inside or near `tnm_fee_percent()`), add:

```php
// Allow the MyNest Trust Suite plugin to reduce the fee for Pro Sellers.
$fee_percent = apply_filters( 'tnm_trust_seller_fee_percent', $fee_percent, $seller_id );
```

Same safety note applies — if Trust Suite is inactive this is a no-op.

## Defensive integration — how this plugin avoids ever fataling on the other plugin

- All reads of the other plugin's DB tables (`ledger`, `reviews`, `follows`, `messages`) go through `TNM_Trust_Compat::get_other_plugin_table( $short_name )`, which uses `tnm_table()` if it exists (else falls back to the documented `{$wpdb->prefix}tnm_{name}` convention) **and** verifies the table actually exists via `SHOW TABLES LIKE` before returning its name. If the table doesn't exist, the caller receives `null` and skips that read gracefully (logged only when `WP_DEBUG_LOG` is enabled).
- Column-level schema drift is also handled defensively: before reading specific columns (e.g. `rating`, `seller_id`, `thread_id`), the plugin runs `DESCRIBE` on the table and checks the columns exist first.
- Seller/product/order associations are resolved by trying the documented meta key conventions (`_seller_id`, `seller_id`, `_tnm_seller_id`) in order, falling back to `post_author` for products or scanning line items for orders.
- `tnm_fee_percent()` and `tnm_table()` are only called via `function_exists()` guards.
- This plugin **never writes** to any table it did not create itself.
- WooCommerce is the only hard dependency (`class_exists( 'WooCommerce' )` guard at bootstrap, with an admin notice + safe early return, and self-deactivation on activation if missing).

## Uninstall

Deactivating the plugin only clears its scheduled cron event — no data is deleted. **Deleting** the plugin via wp-admin → Plugins runs `uninstall.php`, which:

- Drops this plugin's own tables (`wp_tnm_trust_disputes`, `wp_tnm_trust_favorites`, `wp_tnm_trust_offers`, `wp_tnm_trust_boosts`).
- Deletes all `tnm_trust_*` options.
- Deletes the `_tnm_trust_pro_seller` user meta for all users.
- Clears cached seller badge transients.
- Unschedules the hourly cron event.

It never touches any table or option belonging to the "MyNest Unified Marketplace" plugin or WooCommerce itself.

## File structure

```
mynest-trust-suite/
├── mynest-trust-suite.php          Main plugin bootstrap file
├── uninstall.php                    Clean removal of all plugin data
├── README.md                        This file
├── includes/
│   ├── class-tnm-trust-compat.php       Defensive cross-plugin read helpers
│   ├── class-tnm-trust-db.php            dbDelta table creation + default options
│   ├── class-tnm-trust-disputes.php     Feature 1 — Disputes / Buyer Protection
│   ├── class-tnm-trust-seller-badge.php Feature 2 — Seller Performance Badge
│   ├── class-tnm-trust-favorites.php    Feature 3a — Favorites
│   ├── class-tnm-trust-feed.php          Feature 3b — Personalized Feed Ranking
│   ├── class-tnm-trust-offers.php       Feature 4 — Bundles + Make an Offer
│   ├── class-tnm-trust-attributes.php   Feature 5 — Structured Attributes
│   ├── class-tnm-trust-boosts.php       Feature 6 — Boosts + Pro Seller Tier
│   ├── class-tnm-trust-rest.php          REST route registration + callbacks
│   ├── class-tnm-trust-shortcodes.php   All front-end shortcodes
│   ├── class-tnm-trust-admin.php        wp-admin settings/disputes/pro-sellers pages
│   ├── class-tnm-trust-cron.php          WP-Cron scheduling safety net
│   └── class-tnm-trust-assets.php       CSS/JS enqueueing + wp_localize_script
└── assets/
    ├── css/
    │   ├── nest-trust-frontend.css      Front-end styles (buttons, feed grid, panels)
    │   └── nest-trust-admin.css          wp-admin screen styles
    └── js/
        ├── nest-trust-frontend.js        All front-end interactivity (fetch + REST)
        └── nest-trust-admin.js            Minor wp-admin confirmation UX
```

## Compatibility notes

- Plugin header declares `Requires PHP: 8.0`, `Requires at least: 6.5`, and `Requires Plugins: woocommerce`.
- No JS build step or bundler — all JS/CSS are plain enqueued files, safe for a zip-upload-only deployment (no SSH/WP-Cli).
- Does not redeclare or conflict with the other plugin's REST namespaces (`the-nest/v1`, `nest-ops/v1`, `nest-native/v1`, `nest-labels/v1`, `nest-shipping/v1`) or shortcodes (`[the_nest_seller_dashboard]`, `[mynest_seller_dashboard]`, `[the_nest_feed]`, `[mynest_home_feed]`).
