=== CDF Donation Form with PayPal and Stripe ===
Contributors: razuahammad
Donate link: https://github.com/razuahammad55/cdf-donation-form-with-paypal-and-stripe
Tags: donation, paypal, stripe, fundraising, non-profit, recurring donations
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 2.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A secure WordPress donation plugin supporting Stripe Checkout and PayPal REST API for one-time and monthly recurring donations.

== Description ==

CDF Donation Form with PayPal and Stripe is a lightweight, high-converting fundraising plugin for non-profits, charities, religious organizations and creators.

Accept secure payments with **Stripe Checkout** (cards, wallets, Link) and the **PayPal REST API** — no complex setup required.

### Key Features

- **One admin page, six tabs** — Overview (analytics + donation list), Form, Style, Emails, Settings and Tools. No scattered submenus.
- **Dual payment gateways** — Stripe Checkout and PayPal REST API, each with separate test and live credentials.
- **One-time and monthly** — a single form can offer both with a built-in frequency switch.
- **Verified payments** — donation amounts, names and emails are read from the gateway API, never from the browser. Webhooks keep renewals and refunds in sync.
- **Donation dashboard** — revenue totals, gateway breakdown, monthly trend, top donors, searchable donation table with a details modal and CSV export.
- **Branded HTML emails** — editable subjects, headings and intro text with placeholders, plus a test-email button.
- **Style controls** — color pickers for normal/hover/active states and a global border radius.
- **Developer friendly** — namespaced functions, filters and actions (`cdf_donation_logged`, `cdf_donation_data`, `cdf_stripe_checkout_body`, `cdf_paypal_checkout_payload`).

== External Services ==

This plugin connects to third-party payment providers to process donations.

1. **Stripe API** (`api.stripe.com`)
   - **Used for:** creating Checkout Sessions and Prices, verifying payments, handling webhooks.
   - **Data sent:** donation amount, currency, donor email and name, site return URLs, plugin metadata.
   - [Stripe Terms](https://stripe.com/legal/end-users) | [Stripe Privacy Policy](https://stripe.com/privacy)

2. **PayPal REST API** (`api-m.paypal.com`, `api-m.sandbox.paypal.com`)
   - **Used for:** creating orders and billing subscriptions, verifying payments and webhook signatures.
   - **Data sent:** donation amount, currency, donor name, email and return URLs.
   - [PayPal Legal](https://www.paypal.com/us/legalhub/home)

No data is sent anywhere unless you configure gateway credentials and a donor submits the form.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install the ZIP through **Plugins → Add New**.
2. Activate the plugin. The pages `/donation-success/` and `/donation-canceled/` are created automatically.
3. Go to **Donations → Dashboard → Settings** and add your Stripe and PayPal credentials for the current mode.
4. Place `[donation_form]` on any page, post or widget.
5. Optional: add the webhook URLs shown in **Settings** to your Stripe and PayPal dashboards.

== Frequently Asked Questions ==

= Do I need HTTPS? =
Yes. Both gateways require SSL for live payments. The plugin warns you on the Tools tab if HTTPS is missing.

= What shortcodes are available? =
- `[donation_form]` – form with a one-time / monthly switch.
- `[donation_form type="one_time"]` – one-time only.
- `[donation_form type="monthly"]` – monthly only.
- `[donation_form amounts="500,250,100,50"]` – override preset amounts.
- `[donation_receipt]` – receipt summary for the success page.

= Can I use both gateways at once? =
Yes. A gateway only appears on the form when it is enabled and has keys for the current mode.

= How are PayPal monthly donations handled? =
PayPal plans have a fixed price. Create a plan priced at 1.00 of your store currency and paste its `P-...` Plan ID under Settings — the donation amount is sent as the plan quantity.

= Why do I see "Test / Sandbox" badges in the donation list? =
Each donation records the mode it was made in, so test data can never be confused with live revenue.

= Where are the old submenu pages? =
Everything now lives on one page: **Donations → Dashboard**, with tabs for Overview, Form, Style, Emails, Settings and Tools.

== Screenshots ==

1. Donation dashboard with revenue analytics, gateway breakdown and the searchable donation table.
2. Donation details modal.
3. Front-end form with frequency switch, preset amounts and gateway selection.
4. Style tab with color pickers for normal, hover and active states.
5. Email settings with editable templates and placeholders.
6. Gateway settings with test/live credentials and webhook endpoints.

== Changelog ==

= 2.1.0 =
- Security: PayPal donations are now verified through the API — donor name, email and amount can no longer be forged through the return URL.
- Security: removed the nonce bypass option; nonces are always verified. Honeypot, rate limiting and server-side validation added.
- Security: atomic serial numbers via a single SQL increment, duplicate-submit lock, and sanitizers on every settings field.
- Added webhook endpoints (`/wp-json/cdf/v1/stripe`, `/wp-json/cdf/v1/paypal`) with signature verification for renewals, refunds and failed payments.
- New single-page admin UI with tabs: Overview, Form, Style, Emails, Settings, Tools.
- Donation list with search, filters, details modal and CSV export; analytics moved to the top of the same page.
- Editable email templates, configurable sender, test email and connection test.
- Configurable currency, preset amounts, min/max, button text and thank-you message; gateways hidden when not configured.
- Performance: assets load only where used, cached analytics and generated CSS, CSS variables, media queries and RTL support.
- Added `uninstall.php`, text domain loading, file/version constants and a global config array for easy re-branding.

= 2.0.0 =
- 100% Plugin Check compliance and zero static analysis warnings.
- Independent dual-credential storage for Sandbox and Live API keys.
- Refined activation redirect and streamlined admin panels.

= 1.7.0 =
- Resolved static analysis input sanitization warnings.
- Split admin and frontend CSS into separate assets.

= 1.6.0 =
- Added one-time activation redirect.
- Added caching environment nonce bypass toggle (removed in 2.1.0 for security).

= 1.5.0 =
- Added `Requires PHP: 7.4` header.
- Documented third-party services in readme.txt.

= 1.4.0 =
- Fixed text domain issues.

= 1.3.0 =
- Updated plugin title for guideline compliance.
- Added tabbed color controls with hex support.

= 1.2.0 =
- Initial public release with Stripe Checkout and PayPal REST API.
