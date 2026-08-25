# MyNest Unified Marketplace

**Version 3.13.38 — operational consistency and checkout hardening**

MyNest Unified Marketplace is the only custom marketplace plugin needed for the MyNest website and The Nest mobile app. It replaces the overlapping MyNest, The Nest, NorthCraft, seller portal, fee, payout, order breakdown, mobile bridge, native checkout, operations, tracking, and shipping plugins.

It is intentionally packaged with the same plugin folder and main file as version 2.x so WordPress can replace the current plugin in place rather than install a second marketplace engine.

## Keep these standard plugins active

- WooCommerce
- WooCommerce Analytics
- WooCommerce Stripe Gateway
- Stripe Tax
- Jetpack
- Akismet
- Page Optimize and other WordPress.com-managed services

All older custom MyNest, The Nest, and NorthCraft plugins should remain inactive.

## Included systems

### Marketplace and seller administration

- Additive `tnm_seller` and legacy `mynest_seller` roles
- Seller application, approval, rejection, and account status
- Seller profiles, storefront information, product ownership, media ownership, and product CRUD
- Seller-specific orders, item visibility, fulfillment status, tracking, and notifications
- Admin seller assignment and marketplace dashboard
- Administrator and shop-manager access without changing their roles

### Fees, earnings, refunds, and payouts

- Configurable marketplace percentage and fee label
- Immutable seller and fee snapshots on each WooCommerce order item
- Detailed buyer, seller, admin, and email order breakdowns
- Pending, available, reserved, paid, refunded, and void ledger states
- Holding period and minimum payout settings
- Manual payout workflow and optional PayPal payout adapter
- Automatic payouts forced off during every installation and upgrade

### Social marketplace

- Home feed and seller posts
- Seller follows
- Buyer and seller notifications
- Direct messages
- Purchase-verified seller reviews
- Custom account photos and shop banners

### Mobile app and checkout

- Existing `the-nest/v1` app routes preserved
- Signed bearer tokens with expiration and revocation
- Compatibility with legacy mobile tokens
- Products, categories, feed, profiles, messages, notifications, orders, earnings, and payouts
- Saved billing and shipping addresses
- Expo push-token storage and push delivery
- Native Stripe PaymentIntent checkout with server-calculated totals, signed quote tokens, idempotency, and signed webhook verification

### Shipping

- Seller ship-from profiles
- Product weight and package dimensions
- WooCommerce-native dimension mirroring
- Shippo rates and labels
- Seller-limited order access
- Label URL and tracking storage
- Buyer shipment notifications

### Site and migration tools

- Legacy `[mynest_*]` and `[the_nest_*]` shortcodes
- Required marketplace, WooCommerce, and policy pages
- Legacy settings, roles, applications, products, follows, reviews, and order metadata migration
- System Health screen and safe role/data repair actions
- HPOS-compatible WooCommerce order access
- Existing data preserved on normal deactivation and uninstall

## 3.0.1 lifecycle fix

Version 3.0.0 could run its install routine from `plugins_loaded` and register a rewritable custom post type before WordPress initialized its rewrite object. On WordPress 7 this could produce `Call to a member function add_rewrite_tag() on null`. Version 3.0.1 separates data installation from rewrite registration, waits until `init`, and flushes rewrite rules only when the rewrite object is available.

## Replace the broken 3.0.0 build

1. Leave WooCommerce and the other WordPress.com-managed plugins in place.
2. Open **Plugins → Add Plugin → Upload Plugin**.
3. Upload the current MyNest Unified Marketplace ZIP.
4. WordPress should identify the existing **MyNest Unified Marketplace** folder and offer **Replace current with uploaded**.
5. Choose the replacement option. Do not install it as a second plugin under a different folder.
6. Confirm **MyNest Unified Marketplace 3.13.38** is active.
7. Open **The Nest → System Health**.
8. Clear the WordPress.com/Page Optimize cache.
9. Test the homepage, WP Admin, product pages, cart, checkout, My Account order view, seller dashboard, and mobile app.

The upgrade process does not delete products, orders, customers, users, media, seller records, ledger rows, payout rows, or existing settings.

## Important production limits

- Live Stripe, Shippo, PayPal, tax, refund, and payout flows must be tested on the actual site before relying on them with real money.
- Automatic payouts are disabled by default and should remain disabled until manual reconciliation has been verified.
- The generated policy pages contain starter text and require legal review before launch.
- Keep the old plugin ZIP files archived until the full website and app test plan passes.

## API namespaces

- `/wp-json/the-nest/v1/`
- `/wp-json/nest-ops/v1/`
- `/wp-json/nest-native/v1/`
- `/wp-json/nest-labels/v1/`
- `/wp-json/nest-shipping/v1/`

See `docs/API.md`, `docs/ARCHITECTURE.md`, and `docs/TESTING.md`.

## Data removal

Normal uninstall preserves marketplace data. Permanent deletion only occurs when this constant is deliberately defined before uninstalling:

```php
define( 'TNM_REMOVE_DATA_ON_UNINSTALL', true );
```




## 3.0.6 header cleanup

Assembler ships with an unlinked placeholder **Learn more** button in its default header pattern. Version 3.0.6 removes only that placeholder during header rendering while leaving the account and cart controls unchanged.

## 3.0.4 seller product creation fix

WordPress treats a POST field named `name` as the reserved post-slug query variable. The seller product form previously used that field, so submitting a product could make WordPress resolve the product name as a page slug and return a 404 before the dashboard handler ran. Version 3.0.4 uses product-specific field names and retains backward-compatible request handling.

## 3.0.3 storefront update

- Preserves the seller product editing and deletion tools from 3.0.2.
- Enables buyer registration from My Account and optional account creation during checkout.
- Keeps guest checkout enabled and unchecked by default.
- Adds Quick Add buttons to WooCommerce shop archives, The Nest Browse page, and the home feed.
- Variable products continue to show their normal options/select-product action.
- Refreshes account, cart, checkout, catalog, marketplace form, and mobile navigation styling.

## 3.13.38 operational consistency

- One authoritative live shipping + handling calculation across quote, order, Stripe, and postage accounting.
- Final tax/discount totals returned to the app for pre-payment review.
- Atomic contact/address save endpoint with checkout-equivalent validation.
- Pre-shipment cancellations separated from 14-day post-delivery returns.
- Signup verification codes hashed at rest.

## 3.0.8 mobile order breakdown fix

WooCommerce hides normal table headers on narrow screens. The custom order breakdown now includes explicit mobile labels on every total row and a guarded two-column responsive layout, so Items, Shipping, Tax, and Total no longer appear as blank colons.


## 3.0.8
- Fixed The Nest admin submenu registration order so Shipping Labels and Operations no longer open as missing pages.
- Added Shippo token and test/live mode controls directly to The Nest → Settings as a reliable fallback.
