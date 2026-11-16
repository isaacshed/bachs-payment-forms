=== Bachs Payment Forms ===
Contributors: bachs, isaacshed, geekydaniel
Tags: payment form, donations, bachs, recurring payments, africa
Requires at least: 6.2
Tested up to: 7.1
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Create payment forms that accept card, bank transfer, mobile money and crypto payments through Bachs, one-time or recurring, in different currencies.

== Description ==

Bachs Payment Forms lets you build payment forms in WordPress and take payments through [Bachs](https://bachs.io/), a payments platform for internet businesses across African markets. No WooCommerce or shop needed: create a form, paste its shortcode into any page, and payers complete their payment on the secure Bachs checkout.

= Features =

* **Fixed, chosen or listed amounts** — charge a set price, let payers enter their own amount (with an optional minimum and maximum) for donations and invoices, or offer a list of amounts to choose from.
* **Quantity and stock** — let payers buy more than one, with a per-payment limit and optional stock that sells out automatically.
* **Recurring payments** — make a form always recurring (weekly, monthly, every 3 months or yearly), or let payers choose between a one-time and a recurring payment. Renewals are recorded automatically.
* **Your own fields** — add text, email, phone, number, date, dropdown, radio and checkbox fields with a simple shortcode. Answers are saved with the payment and included in the emails.
* **Many payment methods and currencies** — cards, bank transfer, mobile money and crypto stablecoins, all handled on the Bachs checkout. Price forms in USD, NGN, GHS, KES, MWK, RWF, TZS, UGX, XAF, XOF or ZMW.
* **Optional currency choice** — let payers pick their currency on the form, at exact prices you set for each currency, or keep one currency per form.
* **Webhook-verified payments** — a payment is only marked paid when Bachs's signed webhook confirms it, and the amount is checked against the form. Payments that don't match are held for your review.
* **Receipts and notifications** — payers get an email receipt; you get an email for every payment.
* **Payments dashboard** — see, filter and search every payment, open the full details, and export to CSV.
* **Pay again** — a payer whose checkout expired or was cancelled can pay again from their status link, without being charged twice.
* **Sandbox mode** — build and test against the Bachs sandbox before going live.

= Requirements =

* WordPress 6.2 or higher
* PHP 7.4 or higher
* An SSL certificate (required for live payments)
* A Bachs account — sign up at [bachs.io](https://bachs.io/)

== Installation ==

= Automatic Installation =

1. Log in to your WordPress Admin Dashboard.
2. Navigate to **Plugins > Add New**.
3. Search for "Bachs Payment Forms".
4. Click **Install Now** and then **Activate**.

= Manual Installation =

1. Download the plugin .zip from the [WordPress.org plugin repository](https://wordpress.org/plugins/bachs-payment-forms/).
2. Upload the `bachs-payment-forms` folder to `/wp-content/plugins/` via FTP or your hosting control panel.
3. Go to **Plugins > Installed Plugins** and click **Activate** under Bachs Payment Forms.

= Setup =

1. Go to **Bachs Forms > Settings** and enter your Bachs API keys (sandbox and/or live).
2. Copy the **Webhook URL** shown there into your Bachs developer portal, subscribe it to the listed events, and paste each signing secret back into the settings.
3. Go to **Bachs Forms > Add New Form**, describe what the payer is paying for, choose the amount and options, and publish.
4. Copy the form's shortcode, for example `[bachs_payment_form id="123"]`, into any page or post.

== Frequently Asked Questions ==

= How do I add fields to a form? =

Name and email are always collected. Add more fields anywhere in the form content with the `[bachs_field]` shortcode, for example:

`[bachs_field type="text" label="Company"]`
`[bachs_field type="select" label="T-shirt size" options="S|M|L" required="yes"]`

Supported types are text, email, tel, number, url, date, textarea, select, radio and checkbox.

= Which currencies are supported? =

Forms can be priced in USD, NGN, GHS, KES, MWK, RWF, TZS, UGX, XAF, XOF or ZMW. With Adaptive Pricing enabled in your Bachs dashboard, payers can also pay in their own local currency. Bachs settles the funds to your balance in USD, or NGN when enabled.

= Can payers choose their currency? =

Yes, two ways, and you can use either:

* **On the Bachs checkout page:** price a form in USD and turn on Adaptive Pricing in your Bachs dashboard. Payers see the amount in their local currency, converted at the live exchange rate, and can switch currency on the checkout page. No setup in WordPress is needed.
* **On your form, at prices you set:** in the form's Payment settings, tick "Let payers choose a currency on this form" and give the price for each extra currency (for example USD 10, NGN 15,000, GHS 150). Payers pick their currency on the form and are charged exactly that price. For a list of amounts, add each currency's price to every option line, for example `Gold | 50 | NGN 75000 | GHS 600`; for a payer-entered amount, you can set a minimum and maximum per currency.

Either way, Bachs settles the funds to your balance in USD, or NGN when enabled.

= Why are recurring payments only available in USD? =

Recurring payments use Bachs subscriptions, which currently bill USD cards only. A form priced in another currency is always one-time, and on a form that lets payers choose their currency, payers who choose a currency other than USD pay once.

= How do payers cancel a recurring payment? =

You can cancel any subscription from your Bachs dashboard, and payers can manage theirs through the Bachs customer portal if you have it enabled. The subscription's status in WordPress updates automatically when Bachs sends the change.

= Do I need to configure webhooks? =

Yes. Payments are only marked paid when Bachs's signed webhook arrives, so a payer closing their browser can never leave a payment unrecorded, and a payment can never be faked from the browser. Add the Webhook URL from the settings to your Bachs developer portal, subscribe to `collection.succeeded`, `collection.underpaid`, `collection.failed`, `checkout.expired`, `customer.subscription.created`, `customer.subscription.updated`, `customer.subscription.deleted` and `invoice.paid`, and paste the signing secret into the settings.

= A payment shows "Awaiting payment" but it was paid in Bachs. Why? =

Payments are confirmed instantly by Bachs's webhook. If the webhook isn't set up, or its signing secret doesn't match, the plugin still confirms the payment with Bachs when the payer returns to your site, and checks recent unpaid payments every hour. You can also open the payment and click **Check status with Bachs**. To fix the cause, open **Bachs Forms > Settings**: the **Webhook status** line shows whether webhooks are arriving and, if one was rejected, why.

= Why is a payment marked "Needs review"? =

The plugin checks every payment against the form before marking it paid. It is held for review, and you get an email, when the payer paid less than the form asked for, Bachs accepted the payment on terms other than the exact amount, the payment was short (underpaid), or a second payment arrived for a submission that was already paid. Check the payment in your Bachs dashboard, then deliver, collect the balance, or refund.

= Can payers upload files? =

Not in this version. Accepting files from anyone on the internet, before they have paid, is a security risk, so file fields are not supported.

= Does it support refunds? =

Refund payments from your Bachs dashboard. The payment record in WordPress keeps the Bachs charge ID so you can find it.

== Screenshots ==

1. A payment form on the front end.
2. Form settings: amount, quantity and stock, recurring options.
3. The payments dashboard.
4. Plugin settings: API keys and webhook URL.

== Changelog ==

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
Initial release.

== External services ==

This plugin connects to the Bachs API to create checkout sessions, check their status, and create recurring products. It is required for the plugin to function.

When a payer submits a form, the plugin sends the following to Bachs (`https://api.bachs.io`, or `https://sandbox-api.bachs.io` in test mode):

* The payer's name and email address
* The amount, currency and, for recurring payments, the billing schedule
* A payment reference and internal payment and form IDs
* The addresses of the pages the payer returns to after paying

For a recurring form, the plugin also creates a Bachs product named after the form. The answers to your own form fields are stored in WordPress only and are not sent to Bachs.

The payer then pays on the Bachs hosted checkout (`https://checkout.bachs.io`). Card and payment details are handled entirely by Bachs; this plugin never stores or processes card numbers or other sensitive payment data. Bachs sends signed webhook notifications back to your site to confirm payments and subscription changes.

For details on how Bachs handles data, see:

* Bachs website: https://bachs.io
* Bachs documentation: https://docs.bachs.io
* Bachs Privacy Policy: https://bachs.io/privacy
* Bachs Buyer Terms: https://bachs.io/buyer-terms
