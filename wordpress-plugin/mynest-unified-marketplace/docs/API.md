# MyNest Unified Marketplace API

All production traffic must use HTTPS. Authenticated calls normally use:

```http
Authorization: Bearer TOKEN
```

Legacy app sessions may temporarily use:

```http
X-Nest-Mobile-Token: TOKEN
```

Every non-public route has a WordPress REST `permission_callback`. Seller routes validate both authentication and seller ownership.

## Main marketplace — `the-nest/v1`

### Public

- `GET /config`
- `GET /categories`
- `GET /products`
- `GET /products/{id}`
- `GET /feed`
- `GET /sellers/{id}`
- `GET /sellers/{id}/products`
- `GET /sellers/{id}/posts`
- `GET /sellers/{id}/reviews`

### Account

- `POST /auth/register`
- `POST /auth/login`
- `POST /auth/logout`
- `GET /auth/me`
- `PUT|PATCH /auth/me`
- `POST /media`

### Social

- `POST /posts`
- `POST /sellers/{id}/follow`
- `DELETE /sellers/{id}/follow`
- `POST /sellers/{id}/reviews`
- `GET /notifications`
- `POST /notifications/read`
- `GET /messages`
- `POST /messages`
- `GET /messages/{user_id}`

### Seller

- `POST /seller/application`
- `GET /seller/dashboard`
- `GET /seller/profile`
- `PUT|PATCH /seller/profile`
- `GET /seller/products`
- `POST /seller/products`
- `PUT|PATCH /seller/products/{id}`
- `DELETE /seller/products/{id}`
- `GET /seller/orders`
- `PUT|PATCH /seller/orders/{id}`
- `GET /seller/earnings`
- `GET /seller/payouts`
- `POST /seller/payouts`

## Operations — `nest-ops/v1`

- `GET /health`
- `POST /device-token`
- `GET /addresses`
- `POST /addresses`
- `GET /address/suggest?input=...`
- `POST /shipping/rates`
- `POST /shipping/label`
- `PUT|PATCH /orders/{id}/mark-shipped`
- `POST /account/photo`

## Native checkout — `nest-native/v1`

- `GET /health`
- `POST /checkout/quote`
- `POST /checkout/create-intent`
- `POST /checkout/complete`
- `POST /stripe-webhook`

`quote` calculates product prices and fallback shipping on the server and returns a short-lived signed `quote_token`.

`create-intent` accepts the signed quote and an optional `checkout_token` or `request_id`. Reusing the checkout token returns the existing pending order and PaymentIntent rather than creating a duplicate.

`complete` retrieves the PaymentIntent from Stripe and verifies status, currency, and received amount before marking the WooCommerce order paid.

The webhook requires the configured signing secret and a valid `Stripe-Signature` header.

## Shipping labels — `nest-labels/v1`

- `GET /health`
- `POST /seller/orders/{id}/rates`
- `POST /seller/orders/{id}/label`
- `GET /seller/orders/{id}/label`

Seller calls are limited to that seller's items. An administrator may pass `seller_id` for a multi-seller order.

## Shipping profiles — `nest-shipping/v1`

- `GET /health`
- `GET /seller/profile`
- `POST /seller/profile`
- `GET /seller/products/{id}/shipping`
- `POST /seller/products/{id}/shipping`

Product weight is stored in ounces and dimensions in inches for Shippo compatibility. Values are mirrored into WooCommerce's configured weight and dimension units.


### Atomic contact + address save

`POST /the-nest/v1/me/contact-address` validates account email/phone and a complete shipping address before writing either. Supply `{ contact: { email, phone }, address, address_id? }`.

`POST /nest-native/v1/checkout/create-intent` returns final `tax_total`, `discount_total`, `shipping_total`, and `amount`; clients must review any changed final total before presenting payment.
