=== Fundolar ===
Contributors: fundolar
Donate link: https://fundolar.com/
Tags: donations, fundraising, stripe, paypal, paystack
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept donations through a shortcode form with payment gateways managed in Fundolar Central.

== Description ==

**Fundolar** adds a donation shortcode to WordPress and connects to **Fundolar Central** for payment processing. Configure Stripe, PayPal, Paystack, Flutterwave, Pesapal, Mobile Money (UG), and more in your Fundolar dashboard, then sync them to your site.

**What you get**

* **Shortcode** `[fundolar_donate]` — place the donation form on any page or post.
* **Fundolar Central** — connect with your site key to sync payment gateways from a hosted dashboard.
* **Appearance** — preset amounts, currency, form layouts, and brand colors.
* **Dashboard widget** and **Transactions** list with fee breakdown (gross, 3.5% platform fee, net).
* **Security** — encrypted secret storage, capability checks, and nonces on sensitive actions.

**Platform fee (3.5%)**

Each donation records gross amount, a **3.5% platform fee**, and net to your site for reporting.

**Who this is for**

Churches, nonprofits, creators, and anyone who needs donations on WordPress with Stripe, PayPal, and regional payment methods — without managing API keys inside WordPress.

== Installation ==

= Before you start =

* **WordPress** 6.0 or newer and **PHP** 7.4+ (match the headers above; newer versions are recommended).
* **TLS** — your site should use HTTPS for production donations.
* **Fundolar account** — register at [Fundolar Central](https://app.fundolar.com/owner/register) and configure the payment methods you want to offer.

= Install the plugin =

1. Upload the `fundolar` folder to `wp-content/plugins/`, or upload the ZIP via **Plugins → Add New → Upload Plugin**.
2. Activate **Fundolar** under **Plugins**.
3. Future updates: when a newer build is published at [fundolar.com/plugin](https://fundolar.com/plugin/fundolar.zip), WordPress shows **Update now** on the Plugins screen (Dashboard → Updates).
4. In the admin, open **Fundolar → Settings** (or follow any onboarding links shown).

= Set up payments =

1. Open **Fundolar → Settings → Payments**.
2. Paste your **site key** from Fundolar Central.
3. Click **Connect**, then **Sync gateways**.
4. Enable payment methods in your Fundolar dashboard if none appear after syncing.

= Publish the form =

5. Add `[fundolar_donate]` to a page or post and publish. Run a small test donation.

= Optional: payment logos =

6. For branded logos on the form, add SVG (preferred) or PNG files to `wp-content/plugins/fundolar/resources/images/logos/` using the filenames listed in the FAQ.

= Uninstall =

Deactivating removes scheduled hooks tied to the plugin. Deleting the plugin via WordPress runs `uninstall.php`, which removes plugin options and the custom transactions table — **back up** data first if you need historical exports.

== Frequently Asked Questions ==

= Do I need a Fundolar account? =

Yes. Create a free account at [Fundolar Central](https://app.fundolar.com/owner/register), configure payment gateways there, and connect your WordPress site with the site key under **Fundolar → Settings → Payments**.

= Does the plugin collect personal data? =

Donation records in your database may include donor email and name when the form collects them. Card data is handled by your payment processor. See **Privacy** below.

= cURL error 6 / Could not resolve host =

Your server cannot reach Fundolar Central. Confirm outbound HTTPS is allowed and that DNS resolves `app.fundolar.com`. Contact your host if the problem persists.

= Where do I get payment logos? =

Add SVG or PNG files under `wp-content/plugins/fundolar/resources/images/logos/` with these filenames:

* `stripe.svg`, `paypal.svg`, `paystack.svg`, `flutterwave.svg`, `pesapal.svg`, `mobile-money-ug.svg`, `payoneer.svg`

== Screenshots ==

1. Donation form on the front end.
2. Fundolar Central connection in Settings → Payments.
3. Dashboard widget and transactions list.

== Privacy ==

This plugin:

* Stores donation **transactions** in a custom database table (amounts, status, gateway metadata, and donor fields your form collects).
* Sends **admin support requests** from the in-dashboard help UI to **info@fundolar.com** when a site administrator submits the form.
* Communicates with **payment processors** and **Fundolar Central** as described under **External services** when those features are configured.

Consult your privacy policy and processor agreements for lawful bases and retention. Use WordPress privacy tools where applicable for donor personal data.

For each third-party API, what is sent, when, and links to terms and privacy policies, see **External services** above.

== Changelog ==

= 1.4.0 =
* Fundolar.com distribution: payments are managed exclusively through Fundolar Central (own API key fields removed from Settings).
* Updated admin UI, how-to guide, form messages, and notices for a Central-only setup.

= 1.3.20 =
* WordPress.org review response: explicitly list all external service domains in readme.
* Settings save uses an allowlisted, sanitized field set (no raw $_POST pass-through).
* Donation REST route detection no longer reads $_GET['rest_route'].
* WordPress.org package excludes plugins_api updater code and does not mutate active_plugins.

= 1.3.19 =
* Add Payoneer Checkout card payments beside Stripe for donors (embedded card fields + hosted fallback).

= 1.3.18 =
* WordPress.org review: document PayPal api-m and MarzPay (wallet.wearemarz.com) with terms/privacy links.
* Remove custom plugins_api update hooks on WordPress.org builds; stop auto-activating/deactivating other plugins.
* Replace WP_PLUGIN_DIR hardcoding with plugin_dir_path-based paths.
* Add nonce checks for gateway return URLs and OAuth returns; tighten MarzPay webhook verification via API re-check.
* Sanitize admin settings POST input and REST route detection inputs.

== External services ==

This plugin connects to third-party services when accepting donations or syncing with Fundolar Central.

= Fundolar Central =

* **What:** Site registration, gateway sync, donation reporting, and owner dashboard.
* **Where:** `https://app.fundolar.com` (or your configured Central host).
* **When data is sent:** When you connect or sync under Settings → Payments, when donations are recorded, and during background sync tasks.
* **Terms:** https://fundolar.com/terms
* **Privacy:** https://fundolar.com/privacy

= Stripe =

Used to create and verify PaymentIntents for card donations and receive signed webhooks at `/wp-json/fundolar/v1/webhooks/stripe`.

* **When data is sent:** When a donor chooses Stripe and during payment status updates.
* **Terms:** https://stripe.com/legal
* **Privacy:** https://stripe.com/privacy

= PayPal =

* **Where:** `https://api-m.paypal.com` (live) or sandbox equivalent.
* **When data is sent:** When a donor pays with PayPal.
* **Terms:** https://www.paypal.com/us/webapps/mpp/ua/useragreement-full
* **Privacy:** https://www.paypal.com/us/webapps/mpp/ua/privacy-full

= Paystack =

* **When data is sent:** When a donor pays with Paystack.
* **Terms:** https://paystack.com/terms
* **Privacy:** https://paystack.com/privacy

= Flutterwave =

* **When data is sent:** When a donor pays with Flutterwave.
* **Terms:** https://flutterwave.com/us/terms
* **Privacy:** https://flutterwave.com/us/privacy-policy

= Pesapal =

* **When data is sent:** When a donor pays with Pesapal.
* **Terms:** https://www.pesapal.com/support/knowledge-base/terms-and-conditions
* **Privacy:** https://www.pesapal.com/support/knowledge-base/privacy-policy

= Payoneer Checkout =

* **When data is sent:** When a donor pays with Payoneer card fields.
* **Terms:** https://www.payoneer.com/legal/terms-of-service/
* **Privacy:** https://www.payoneer.com/legal/privacy-policy/

= MarzPay (Mobile Money UG) =

* **Where:** `https://wallet.wearemarz.com`
* **When data is sent:** When a donor pays with Mobile Money (UG), during collection requests, status polling, and webhook callbacks.
* **Terms:** Contact MarzPay for current terms.
* **Privacy:** Contact MarzPay for current privacy policy.
