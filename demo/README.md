# Demo checkout

A two-page harness that drives the real `PurchaseRequest` and
`CompletePurchaseResponse` against Razorpay test mode, so you can watch each
callback check accept or reject a payload.

It is not a shop and not part of the library. Nothing here is installed by
Composer.

## Run it

Docker:

```bash
RAZORPAY_KEY_ID=rzp_test_xxx RAZORPAY_KEY_SECRET=xxx \
  docker compose -f demo/docker-compose.yml up --build
```

Or with PHP 8.1+ and Composer already on the machine:

```bash
composer install
RAZORPAY_KEY_ID=rzp_test_xxx RAZORPAY_KEY_SECRET=xxx \
  php -S localhost:8080 -t demo
```

Open <http://localhost:8080>.

Use **test-mode** keys only. They are read from the environment and never
written to disk.

## Why there is no Pay button

Hosted checkout only accepts callbacks to a Shopify storefront. From
`razorpay/hosted`, `app/Services/Integration/Shopify.php`:

```php
const CALLBACK_PATH = '/services/ping/notify_integration/razorpay_cards_upi_netbanking_wallets_/6183780442';

// isValidCallbackUrl(): https, a *.myshopify.com host, and exactly CALLBACK_PATH
```

`x_url_callback` is validated against that before the payment notification is
sent, so a merchant's own return URL is rejected with *Invalid Shopify callback
URL*. The endpoint is usable only by Shopify stores, which do not need this
driver. That is a property of the endpoint, not something the library can
influence, and it is the main reason the driver cannot complete a real payment.

The simulator below runs the same `isSuccessful()` over the same payload shape,
which is what the security fix is about.

## What it shows

1. **Start a purchase** builds the signed `x_*` form with the library's own
   `PurchaseRequest` and prints every field. The order (reference, amount,
   currency) is kept in a temp file, keyed by reference — same as a real
   merchant would keep it server-side.
2. **Simulate the callback** runs `isSuccessful()` against crafted payloads:

| Scenario | What it proves |
|---|---|
| `completed` | A signed success callback is not enough — no such payment exists at Razorpay, so the API check rejects it. |
| `failed`, `pending` | These used to return `true`. They are rejected on `x_result`. |
| `replay` | A signed success from a different, cheaper order cannot complete this one. |
| `resplit` | The signature still verifies (same bytes, fields re-split) and the payload is still rejected. |
| `bad-signature` | Tampered signature is rejected. |

The result page shows each check in the order `isSuccessful()` runs them, so a
rejection tells you exactly which one fired.

## Notes

- `x_amount` is posted in major units (`10.00`), which is what hosted checkout
  echoes back; the paise figure comes from the payment entity.
- `x_gateway_reference` is `"<order_id>|<payment_id>"`.
- Only `id`, `status`, `amount`, `currency`, `order_id` and `method` from the
  payment are displayed — no customer data.
