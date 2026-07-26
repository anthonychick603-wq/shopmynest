# Architecture

## Single engine

The plugin bootloader loads one ordered module set after WooCommerce. It refuses to start a second marketplace engine when a separate legacy backend has already declared the same core functions or classes.

## Modules

- `class-mnu-install.php` — roles, tables, pages, defaults, scheduled maintenance
- `class-tnm-auth.php` — signed bearer tokens and revocation
- `class-tnm-applications.php` — seller applications and approval
- `class-tnm-marketplace.php` — products, ownership, order snapshots, breakdowns, tracking
- `class-tnm-ledger.php` — seller earnings, holds, releases, voids, refunds
- `class-tnm-payouts.php` — requests, reservations, manual/PayPal processing
- `class-tnm-social.php` — feed, follows, notifications, messages, verified reviews
- `class-tnm-rest.php` — primary mobile REST API
- `class-tnm-shortcodes.php` and `class-mnu-compat.php` — seller web UI and legacy pages
- `class-mnu-native-checkout.php` — server-priced Stripe PaymentIntent checkout
- `class-mnu-ops.php` — addresses, push notifications, account photo, shared shipping operations
- `class-mnu-shipping-labels.php` — Shippo order rates and labels
- `class-mnu-shipping-profiles.php` — seller origin and package data
- `class-tnm-admin.php` and `class-mnu-system.php` — settings, launch checks, migration, health

## Data compatibility

The plugin preserves and reads the established data contract:

- Settings option: `tnm_settings`
- Seller roles: `tnm_seller`, `mynest_seller`
- Product seller metadata: `_tnm_seller_id`, `_mynest_seller_id`
- Order item fee and seller snapshots: `_tnm_*` plus selected legacy `_mynest_*` keys
- Shipping profile: `_thenest_shipping_profile`
- Product package metadata: `_thenest_weight_oz`, `_thenest_length_in`, `_thenest_width_in`, `_thenest_height_in`
- Tables: `{prefix}tnm_ledger`, `tnm_payouts`, `tnm_follows`, `tnm_notifications`, `tnm_messages`, `tnm_reviews`

Migrated products are found by both WordPress author and seller metadata. Ownership checks use the stored seller ID rather than assuming the product author is always correct.

## Administrator safety

Seller approval uses `add_role()` and never replaces a user's existing roles. The plugin contains no `set_role()` calls. Administrators and shop managers may use management tools but are explicitly excluded from seller ownership, seller redirects, seller fees, and public seller classification.

## Order storage

Orders are accessed through WooCommerce order and order-item CRUD objects. The plugin does not query the WordPress posts table for orders, allowing WooCommerce High-Performance Order Storage to remain enabled.

## Money safety

Order items receive a fee percentage, platform fee, seller ID, store name, and seller-net snapshot. Ledger capture is idempotent by order, item, and entry type. Native checkout totals are calculated on the server. Automatic payouts are forced off on activation and upgrade.
