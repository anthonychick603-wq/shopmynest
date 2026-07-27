# shopmynest — WordPress / WooCommerce codebase

Server-side source for [shopmynest.com](https://shopmynest.com): custom plugins that turn a WooCommerce store into the ShopMyNest marketplace, plus the marketplace child theme.

The Expo/React Native mobile app lives in a separate repo (`shopmynestv1`, `anthony.mobile`, or `shopmynest-mobile-v2`).

## Layout

```
wordpress-plugin/
  mynest-unified-marketplace/       Full marketplace: sellers, fees, payouts, orders, social, mobile APIs, checkout, shipping
  mynest-mobile-app-bridge/         REST endpoints consumed by the mobile app (community blog, /auth/me/permissions)
  shopmynest-branding/              Site-wide logo, favicons, login screen, admin bar, WooCommerce email header
  shopmynest-legal-pages/           /terms, /privacy, /refunds, /shipping — seeded on activation
  mynest-trust-suite/               Disputes, favorites, offers, boosts, seller badges, Pro Seller tier
wordpress-theme/
  mynest-marketplace-child/         Etsy + Vinted-inspired child theme (parent: Assembler)
```

Alongside each source directory is a built `.zip` matching the version currently active on the production server.

## Live versions (as of 2026-07-26)

| Plugin / Theme                | Repo version | Server version | Notes |
| ----------------------------- | ------------ | -------------- | ----- |
| mynest-unified-marketplace    | 3.7.1        | 3.7.1          | In sync |
| mynest-mobile-app-bridge      | 1.2.1        | 1.1.0          | **Server needs update** — v1.2.1 zip is ready to upload |
| shopmynest-branding           | 1.0.0        | 1.0.0          | In sync |
| shopmynest-legal-pages        | 1.0.0        | 1.0.0          | In sync |
| mynest-marketplace-child      | 1.0.3        | not tracked    | Confirm theme is uploaded |
| mynest-trust-suite            | 1.1.0        | 1.1.0          | In sync |

## Deploy

For WordPress.com-hosted sites:

- Upload the built `.zip` via wp-admin → Plugins → Add New → Upload.
- Or use the WP.com dashboard's plugin manager.

For self-hosted or SSH-enabled sites:

```
cd wp-content/plugins/
rm -rf mynest-unified-marketplace
unzip /path/to/mynest-unified-marketplace-v3.7.1.zip
```

Deactivate & reactivate the plugin in wp-admin after upgrading to trigger any DB migrations.
