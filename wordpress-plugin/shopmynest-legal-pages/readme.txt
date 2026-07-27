=== ShopMyNest Legal Pages ===
Contributors: mynest
Tags: legal, terms, privacy, marketplace
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later

Seeds Terms, Privacy, Refund, and Shipping pages for ShopMyNest, with a single settings screen for the legal-entity name, business address, contact email, and effective date.

== Description ==

Activating the plugin creates four Pages if they don't already exist:

* /terms      Terms of Service
* /privacy    Privacy Policy
* /refunds    Return & Refund Policy
* /shipping   Shipping Policy

Existing pages with the same slug are adopted, never overwritten. Deleting/trashing a page and reactivating the plugin will recreate it from the bundled content.

A Settings screen at Settings > ShopMyNest Legal lets you change the legal entity, business address, contact email, and effective date. Values are substituted at render time on the seeded pages only, so future edits from the block editor stay in place and simple values (address, email) update everywhere with one save.

These pages are drafts. They are not legal advice; you should have a lawyer review them before publishing.

== Changelog ==

= 1.0.0 =
* Initial release. Seeds four legal Pages and adds a Settings screen for the four common placeholder values.
