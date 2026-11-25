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

Use **test-mode** keys only. The dashboard must have the legacy Shopify/offsite
integration enabled for `checkout.razorpay.com/integration/shopify` to accept the
form; without it, the simulator still works.

## What it shows

1. **Start a purchase** builds the signed `x_*` form with the library's own
   `PurchaseRequest` and prints every field. The order (reference, amount,
   currency) is kept in a temp file, keyed by reference — same as a real
   merchant would keep it server-side.
2. **Pay on Razorpay** posts that form to hosted checkout exactly as a browser
   would. After paying, hosted posts the signed callback back to
   `callback.php`, which runs `isSuccessful()`.
3. **Simulate the callback** runs the same code against crafted payloads:

| Scenario | What it proves |
|---|---|
| `completed` | A signed success callback is not enough — with no such payment at Razorpay, the API check rejects it. Pay for real to see a pass. |
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
