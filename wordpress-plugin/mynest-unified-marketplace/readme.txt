=== MyNest Unified Marketplace ===
Contributors: mynest
Tags: woocommerce, marketplace, multivendor, seller, mobile-api
Requires at least: 6.5
Requires PHP: 8.0
Requires Plugins: woocommerce
Stable tag: 3.13.37
License: GPLv2 or later

One complete custom marketplace plugin for the MyNest website and The Nest mobile app.

== Description ==

Version 3.0.1 is a clean single-plugin rebuild. It replaces the older MyNest, The Nest, NorthCraft, seller portal, fee, payout, order breakdown, mobile bridge, operations, tracking, native checkout, and shipping plugins.

Features include seller applications and approval, seller products and orders, item-level platform fees, earnings ledger, payout requests, social feed, follows, messages, notifications, verified reviews, mobile REST APIs, saved addresses, native Stripe checkout, shipping profiles, Shippo labels, legacy shortcode compatibility, data migration, and system-health tools.

The plugin adds seller roles without replacing administrator roles. Automatic payouts are disabled during installation and every upgrade.

== Installation ==

1. Keep WooCommerce and the currently active standard WordPress.com/WooCommerce plugins active.
2. Leave all old custom MyNest, The Nest, and NorthCraft plugins inactive.
3. Upload version 3.0.8.
4. Choose "Replace current with uploaded" when WordPress detects the existing MyNest Unified Marketplace plugin.
5. Confirm version 3.0.8 is active.
6. Open The Nest > System Health.
7. Clear cache and complete the included test plan.

== Changelog ==

= 3.13.37 =
* Added built-in nest-trust/v1 buyer-protection disputes and server-side refund/dispute locking.
* Authorized accepted custom-order private products for the assigned buyer in native checkout, with idempotent quote acceptance.
* Standardized seller hold defaults to 7 days and kept dispute-held earnings out of payout release.
* Hardened manual payouts: ACH/reference required before paid, failed/returned retries reset safely to requested.
* Unified admin operations APIs for seller applications, refunds, payouts, disputes, shipping and order exceptions.


= 3.0.8 =
* Fixes blank labels in the buyer Order breakdown on mobile.
* Adds explicit responsive labels for Items, Shipping, Tax, fees, and Total.
* Keeps order amounts aligned in a readable two-column mobile layout.

= 3.0.6 =
* Removed the Assembler theme's unlinked placeholder "Learn more" button from the site header.
* Preserved the account and cart controls.

= 3.0.4 =
* Fixes seller product creation returning Page Not Found by replacing WordPress-reserved POST field names with product-specific field names.
* Preserves backward-compatible POST handling for existing forms and keeps seller product editing intact.

= 3.0.3 =
* Adds native seller product Edit and Delete controls to the seller dashboard Products tab.
* Edit loads a product's current values into the Add-product form (edit mode) and updates it via the existing seller-owner-checked update path; Delete trashes a product after confirmation.
* Reuses the existing tnm_action form-POST pattern and the same product CRUD used by the REST layer; no REST endpoints or authentication were changed.

= 3.0.1 =
* Fixes the WordPress 7 fatal error caused by registering rewritable post types during plugins_loaded before the rewrite object exists.
* Defers custom post type registration and the one-time rewrite flush until init.
* Makes post type registration idempotent and guards rewrite flushing when WordPress has not initialized the rewrite object.
* Rebuilt the marketplace as one modular custom plugin.
* Preserves existing plugin folder, shortcodes, route namespaces, options, user metadata, product metadata, order metadata, and custom tables.
* Prevents administrators and shop managers from being treated as sellers.
* Uses additive seller roles and never calls set_role().
* Corrects WooCommerce order-item callback arguments and supports WooCommerce order override classes.
* Uses WooCommerce CRUD order objects for HPOS compatibility.
* Adds server-signed native-checkout quotes and verifies Stripe amount and currency before payment completion.
* Adds signed Stripe webhook verification and checkout idempotency.
* Unifies signed and legacy mobile authentication across marketplace, operations, labels, and shipping-profile APIs.
* Adds seller-owned media validation and migrated product ownership support.
* Adds normalized shipping profiles, WooCommerce dimension mirroring, Shippo response validation, tracking, and notifications.
* Keeps automatic payouts disabled after installation or upgrade.


3.0.3 storefront update: buyer accounts, guest checkout retained, polished UI, and Quick Add catalog buttons.

= 3.0.8 =
* Fixed Shippo admin-page routing and added Shippo configuration to the main marketplace settings page.
