=== MyNest Mobile App Bridge ===
Contributors: mynest
Tags: woocommerce, marketplace, mobile app, android
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.2.1
License: GPLv2 or later

Mobile API additions and compatibility support for The Nest Android app.

== Description ==

This companion plugin works with WooCommerce and MyNest Unified Marketplace. It provides buyer order history, product reporting, moderated community posts for the app home feed, seller application status, buyer profile photo uploads, reliable mobile bearer-token authentication through reverse proxies, and a Stripe Tax Sandbox checkout fallback.

The Stripe Tax fallback runs only while Stripe Tax is set to Sandbox/Test mode. It never bypasses Stripe Tax live-mode failures.

== Changelog ==

= 1.2.1 =
* Added GET /auth/me/permissions so the mobile app can gate admin-only surfaces (Blog moderation, etc.) on the current user's capabilities without depending on the main marketplace plugin's /auth/me payload.
* Added mynest_mobile_auth_permissions filter for extending the permissions payload.

= 1.2.0 =
* Added community posts: any logged in customer can submit a photo and caption to the app home feed.
* Community posts are held in a pending state and never appear on the public feed until an administrator approves them.
* Added a Community Posts moderation screen with Pending, Approved, and Rejected views and one-click Approve and Reject actions.
* Added a pending-count badge on the Community Posts menu item, a dashboard notice, and an email to administrators and shop managers on new submissions.
* Added REST routes for submitting posts, reading the approved feed, and moderating posts from the mobile app.

= 1.1.0 =
* Added X-MyNest-Token authentication fallback for hosts that strip Authorization headers.
* Added explicit REST request authentication before protected route permission checks.
* Fixed Alerts, messages, orders, and other protected app routes returning Authentication is required after a successful login.
* Added Stripe Tax Sandbox fallback so a failed test tax request does not block WooCommerce block checkout.
* Added a mobile health endpoint.

= 1.0.1 =
* Declared WooCommerce HPOS and Cart/Checkout block compatibility.

= 1.0.0 =
* Initial release.
