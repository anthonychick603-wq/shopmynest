# Version 3.0.0 Upgrade and Test Plan

## Before uploading

The active list should remain:

- Akismet
- Jetpack
- MyNest Unified Marketplace 2.0.0
- Page Optimize
- Stripe Tax
- WooCommerce
- WooCommerce Analytics
- WooCommerce Stripe Gateway

Do not reactivate any old custom MyNest, The Nest, or NorthCraft plugin.

## Replace version 2.0.0

1. Upload the version 3.0.0 ZIP from **Plugins → Add Plugin → Upload Plugin**.
2. Choose **Replace current with uploaded**.
3. Confirm the plugin screen shows **MyNest Unified Marketplace 3.0.0**.
4. Open **The Nest → System Health**.
5. Confirm WooCommerce, marketplace engine, tables, pages, and seller roles are good.
6. Confirm automatic payouts are disabled.
7. Clear the WordPress.com/Page Optimize cache.

## Administrator test

- `/wp-admin/` opens normally.
- Plugins, Users, Appearance, Settings, Products, WooCommerce, and The Nest remain visible.
- The administrator account has not gained a seller role or been redirected to the seller portal.
- The system-health role repair only adds marketplace roles/capabilities and does not replace administrator roles.

## Public website test

- The homepage renders instead of showing `[mynest_home_feed]`.
- The home feed is one column.
- Category names display decoded characters rather than `&amp;`.
- Approved sellers do not see Become a Seller.
- Product, shop, cart, checkout, My Account, and policy pages open.
- Cart items can be added and removed.

## Order-view regression test

Open an existing order under **My Account → Orders → View**. Confirm there is no `TNM_Marketplace::render_item_breakdown()` TypeError and the page renders the item and order breakdown normally.

## Seller test

1. Use a non-administrator test account.
2. Submit and approve a seller application.
3. Confirm the seller role is added without altering any administrator.
4. Create, edit, and delete a test product.
5. Upload a product image owned by the seller.
6. Confirm the seller cannot edit another seller's product.
7. Confirm migrated products assigned through seller metadata appear in the dashboard even when their old WordPress author differs.
8. Place one single-seller and one multi-seller test order.
9. Confirm each seller sees only their own items, gross amount, fee, net amount, tracking, and status controls.

## Financial test

- Confirm the configured percentage is snapshotted on each order item.
- Confirm payment creates one ledger earning per seller item without duplicates.
- Confirm earnings remain pending for the configured holding period.
- Confirm release changes eligible earnings to available.
- Confirm cancellation/failed orders void unpaid earnings.
- Test full and partial refunds and reconcile the resulting ledger adjustments.
- Test a manual payout request and completion.
- Keep PayPal sandbox and automatic payouts disabled until reconciliation is complete.

## Native checkout test

- Use Stripe test mode.
- Request a quote and confirm the returned total is server-calculated.
- Create an intent with the signed quote token.
- Repeat the same checkout token and confirm no duplicate order is created.
- Complete payment and confirm amount/currency verification passes.
- Configure the webhook signing secret and send a Stripe test event.
- Confirm failed and canceled PaymentIntents update the order correctly.

## Shipping test

- Save a seller ship-from profile.
- Save product weight and dimensions and verify WooCommerce fields are updated.
- Use Shippo test credentials to request rates.
- Buy a test label and verify label URL, tracking, order note, seller status, and buyer notification.
- On multi-seller orders, confirm each seller's label uses only that seller's items.

## Mobile app test

- Login and load `/auth/me`.
- Load products, categories, feed, seller pages, notifications, and messages.
- Test seller products, orders, earnings, payouts, shipping profile, account photo, and saved addresses.
- Confirm both signed Authorization bearer tokens and existing legacy sessions work during migration.

## Rollback

Keep the previous version 2.0.1 ZIP and all legacy source ZIPs archived. If a critical issue appears, replace version 3.0.0 with the previous **MyNest Unified Marketplace** ZIP using the same WordPress replacement workflow. Do not activate multiple marketplace plugins together.
