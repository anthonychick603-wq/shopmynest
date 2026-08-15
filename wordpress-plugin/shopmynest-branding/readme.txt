=== ShopMyNest Branding ===
Contributors: shopmynest
Tags: branding, logo, favicon, login, woocommerce
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

Applies the ShopMyNest logo and brand identity across WordPress + WooCommerce.

== Description ==

Drops in the ShopMyNest nest-and-bird logo across your site with zero configuration:

* Favicons (16/32/48/96/180/192/512) and Apple touch icon.
* Custom wp-admin login screen with ShopMyNest logo and warm brand palette.
* Admin bar icon replacement.
* WooCommerce transactional email header + email color defaults.
* `[shopmynest_logo size="300"]` shortcode.
* Fallback site icon via the `get_site_icon_url` filter.

== Installation ==

1. Upload the `shopmynest-branding` folder to `/wp-content/plugins/` **or** upload the zip in **Plugins → Add New → Upload Plugin**.
2. Activate through the **Plugins** menu.
3. Visit **Settings → ShopMyNest** to preview.

== Changelog ==

= 1.4.0 =
* Palette: switched to **Modern Marketplace** — brand indigo primary (#3A3D8A), coral accent (#E27055), warm ivory surface (#F8F5F0), clean white card (#FFFFFF), ink text (#1B1A21), ivory border (#E4DED4). Legacy `secondary` alias points to coral. Shadow tint recalibrated to indigo.

= 1.3.0 =
* Palette: switched from teal + cream to **Studio Clay** — deep forest primary (#3C4B33), clay accent (#B0553A), parchment surface (#F5EFE4), warm paper cards (#FFFBF3), bark ink (#26221C), sand borders (#DFD3BE). Legacy `secondary` alias now points to the clay accent. Shadow tint recalibrated to forest.

= 1.2.2 =
* Category grid: removed emoji icons from the six Shop category tiles; labels now render as centered text only. Adjusted tile height/typography accordingly.

= 1.2.1 =
* Palette: accent and secondary tokens updated to #E2856E to match the mobile app's brand color.

= 1.0.0 =
* Initial release.
