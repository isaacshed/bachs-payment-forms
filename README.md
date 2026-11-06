# Bachs Payment Forms

Payment forms for WordPress, paid through [Bachs](https://bachs.io): one-time or recurring payments by card, bank transfer, mobile money and crypto, in different currencies. No WooCommerce needed.

The WordPress.org listing text, setup steps and FAQ are in [`readme.txt`](readme.txt).

## How it works

1. A site admin creates a form (`bachs_form` post type, admins only) and places `[bachs_payment_form id="…"]` on a page. Extra fields come from `[bachs_field …]` shortcodes in the form content.
2. On submit, `Bachs_PF_Checkout` validates every field against the form's own definition and prices the payment on the server (fixed amount, payer-entered amount within min/max, or a listed option, times quantity, within stock). It stores a `pending` row and creates a Bachs checkout session:
   - one-time: ad-hoc `pricing`
   - recurring (USD only): a per-form, per-cadence Bachs product with a `billing_cycle`, priced per checkout via `product_cart[].pricing`
3. The payer pays on the Bachs hosted checkout and returns to a status view authorised by a per-payment secret key.
4. `Bachs_PF_Webhook` (`POST /wp-json/bachs-payment-forms/v1/webhook`) verifies the HMAC signature and marks the payment paid or "needs review" with an atomic `UPDATE … WHERE status IN (…)`, so emails and stock counts happen exactly once. Subscription events link the subscription, and `invoice.paid` records each renewal as its own row.

## Development

Requirements: PHP 7.4+ with `pdo_sqlite` (for the tests).

```bash
php tests/run.php
```

The tests run against a small WordPress stand-in (`tests/bootstrap.php`) with a real in-memory SQLite database, so the payment queries run as SQL.

### Release zip

```bash
bin/build-zip.sh
```

Builds `dist/bachs-payment-forms-<version>.zip` from the committed tree, after checking the plugin header version, `BACHS_PF_VERSION` and the readme's `Stable tag` agree.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
