# CDF Donation Form with PayPal and Stripe

A secure, lightweight WordPress donation plugin for one-time and monthly recurring donations via **Stripe Checkout** and the **PayPal REST API**.

- **Version:** 2.2.0
- **Requires:** WordPress 5.8+, PHP 7.4+
- **License:** GPLv2 or later

---

## Features

| Area | Details |
|---|---|
| Payments | Stripe Checkout (cards, wallets, Link) and PayPal REST (orders + billing subscriptions) |
| Frequencies | One-time and monthly, with a switch inside a single form |
| Verification | Amounts and donor identity are read from the gateway API — never from URL parameters |
| Webhooks | Stripe and PayPal endpoints with signature verification for renewals, refunds and failures |
| Admin | One page, six tabs: Overview, Form, Style, Emails, Settings, Tools |
| Reporting | Revenue, gateway split, monthly trend, top donors, searchable list, details modal, CSV export |
| Data control | Delete a single donation or a bulk selection with all of its meta |
| Emails | Editable templates, placeholders, custom sender, test email |
| Styling | Color pickers for normal/hover/active states + border radius (CSS variables) |
| Security | Nonces on every submission and save (opt-out toggle for cached sites), honeypot, rate limiting, server-side validation, sanitized settings |
| Amount modes | Preset buttons fill the amount field, or custom-amount mode leaves it empty with a placeholder while a typed value overrides the preset |
| Performance | Assets only load where used, cached analytics and generated CSS |

## Shortcodes

```
[donation_form]                                 Full form with frequency switch
[donation_form type="one_time"]                 One-time only
[donation_form type="monthly"]                  Monthly only
[donation_form amounts="500,250,100,50"]        Custom preset buttons
[donation_receipt]                              Receipt for the success page
```

## Installation

1. Copy the plugin folder to `/wp-content/plugins/`.
2. Activate it — the `/donation-success/` and `/donation-canceled/` pages are created automatically.
3. Open **Donations → Dashboard → Settings** and enter your Stripe and PayPal credentials.
4. Add `[donation_form]` to a page.

## How a donation flows

1. The donor submits the form. The nonce, honeypot, rate limit and every field are validated server-side.
2. A pending record is stored server-side and referenced by an unguessable key — the return URL carries no amount, name or email.
3. The donor is redirected to Stripe Checkout or PayPal.
4. On return (or via webhook, whichever happens first) the plugin queries the gateway API, confirms the payment, and logs a `donation` post with a unique serial number.
5. Admin and donor emails are sent, and the analytics cache is cleared.

## File structure

```
cdf-donation-form-with-paypal-and-stripe.php  Plugin header, constants, global config, helpers
includes/
  class-donation-admin.php        Single tabbed admin page, Settings API, export, tests
  class-donation-cpt.php          `donation` post type, admin columns, details metabox
  class-donation-emails.php       Admin + donor HTML emails, templates, test email
  class-donation-shortcodes.php   [donation_form] and [donation_receipt]
  class-payment-processor.php     Submission handling, gateway calls, verification, serials
  class-webhooks.php              Stripe/PayPal webhook endpoints
assets/
  css/frontend-style.css          Front-end styles (prefixed, responsive, RTL)
  css/admin-style.css             Admin dashboard styles
  js/frontend-script.js           Amount sync, gateway selection, submit lock
  js/admin-script.js              Tabs, color pickers, donation modal, copy buttons
uninstall.php                     Optional full data removal
```

## Re-branding

Plugin identity lives in one place — the constants and `$GLOBALS['cdf_plugin_config']` array at the top of the main plugin file:

```php
define( 'CDF_PLUGIN_NAME', 'CDF Donation Form with PayPal and Stripe' );
define( 'CDF_PLUGIN_SLUG', 'cdf-donation-form-with-paypal-and-stripe' );
define( 'CDF_PLUGIN_VERSION', '2.2.0' );
define( 'CDF_PLUGIN_PREFIX', 'cdf_' );
define( 'CDF_PLUGIN_CURRENCY', 'USD' );
```

Change the name, slug, version, currency or default amounts there and the admin UI, emails and asset versions follow.

## Hooks

**Actions**

- `cdf_donation_logged( $post_id, $data )` — after a donation is stored
- `cdf_donation_status_changed( $post_id, $status )` — webhook status update
- `cdf_before_delete_donation( $post_id )` — before a donation is permanently deleted

**Filters**

- `cdf_donation_data` — donation array before it is saved
- `cdf_stripe_checkout_body` — Stripe Checkout Session parameters
- `cdf_paypal_checkout_payload` — PayPal order/subscription payload

## Webhooks

Add these URLs in the gateway dashboards and paste the signing secret/webhook ID under **Settings**:

- Stripe: `https://example.com/wp-json/cdf/v1/stripe`
- PayPal: `https://example.com/wp-json/cdf/v1/paypal`

## Changelog

See [readme.txt](readme.txt) for the full changelog.
