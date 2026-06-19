=== Fundolar ===
Contributors: fundolar
Donate link: https://fundolar.com/
Tags: donations, fundraising, stripe, paypal, paystack
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.3.9
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept donations through a shortcode form by connecting Fundolar Central — includes dashboard insights and a full transaction history.

== Description ==

**Fundolar** adds a donation shortcode and connects to **Fundolar Central** for payment gateways, synced reporting, and payouts.

**What you get**

* **Shortcode** `[fundolar_donate]` — place the donation form on any page or post.
* **Fundolar Central** — connect with a site key under **Fundolar → Settings → Payments**. Active gateways configured in Central (Stripe, PayPal, Mobile Money UG, Paystack, Pesapal, Flutterwave) sync to your donation form.
* **Appearance** — preset amounts, currency, form layouts, and brand colors.
* **Dashboard widget** and **Transactions** list with fee breakdown (gross, 3.5% platform fee, net).
* **Security** — encrypted secret storage, capability checks, and nonces on sensitive actions.

**Platform fee (3.5%)**

Each donation records gross amount, a **3.5% platform fee**, and net to your site. Stripe and PayPal fees are routed via **Stripe Connect** and **PayPal Partner** using built-in platform accounts configured in Central.

**Who this is for**

Churches, nonprofits, creators, and anyone who needs donations on WordPress with multiple payment methods managed from one Central dashboard.

== Installation ==

= Before you start =

* **WordPress** 6.0 or newer and **PHP** 7.4+ (match the headers above; newer versions are recommended).
* **TLS** — your site should use HTTPS for production donations.
* **Fundolar Central** — register at [Fundolar Central](https://app.fundolar.com/owner/register) and configure payment gateways before connecting the plugin.
* **Fundolar Central URL** — your server must be able to resolve and reach the Central base URL (see FAQ if you see cURL / DNS errors).

= Install the plugin =

1. Upload the `fundolar` folder to `wp-content/plugins/`, or upload the ZIP via **Plugins → Add New → Upload Plugin**.
2. Activate **Fundolar** under **Plugins**.
3. Future updates: when a newer version is pushed to [GitHub](https://github.com/biggerbenson/fundolar), WordPress shows **Update now** on the Plugins screen (Dashboard → Updates). No WordPress.org account required.
4. In the admin, open **Fundolar → Settings** (or follow any onboarding links shown).

= Set up payments =

1. In **Fundolar Central admin → Settings → Payments**, enable gateways and enter API keys.
2. Create a WordPress site integration in Central and copy your **site key**.
3. Open **Fundolar → Settings → Payments**, paste the site key, click **Connect Fundolar Central**, then **Sync gateways**.
4. Re-sync after enabling or changing gateways in Central.

= Publish the form =

5. Add `[fundolar_donate]` to a page or post and publish. Run a small test donation.

= Optional: payment logos =

9. For branded logos on the form, add SVG (preferred) or PNG files to `wp-content/plugins/fundolar/resources/images/logos/` using the filenames listed in the FAQ.

= Uninstall =

Deactivating removes scheduled hooks tied to the plugin. Deleting the plugin via WordPress runs `uninstall.php`, which removes plugin options and the custom transactions table — **back up** data first if you need historical exports.

== Frequently Asked Questions ==

= How does Fundolar Central relate to this plugin? =

**Fundolar Central is required for payments.** Configure gateway API keys and enable payment methods in Central admin, then connect your WordPress site with a site key and sync gateways under **Fundolar → Settings → Payments**.

= Do I need a site key to accept donations? =

Yes. Register at Fundolar Central, create a WordPress site integration, and connect the plugin under **Fundolar → Settings → Payments**. Gateway API keys are configured in Central admin and synced to your site.

= Does the plugin collect personal data? =

Donation records in your database may include donor email and name when the form collects them. Card data is handled by your payment processor. See **Privacy** below.

= cURL error 6 / Could not resolve host =

Set **Fundolar Central URL** to a hostname your web server’s DNS can resolve, or set `FUNDOLAR_CENTRAL_URL` in `wp-config.php`. You can also normalize the base URL with the `fundolar_platform_base_url` filter.

= How do I update Fundolar from GitHub? =

The plugin checks `https://github.com/biggerbenson/fundolar` for updates (latest GitHub Release, or the version on the `main` branch). When you publish a newer version, go to **Dashboard → Updates** or **Plugins** and click **Update now**. Override the repository with `FUNDOLAR_GITHUB_REPO` or branch with `FUNDOLAR_GITHUB_BRANCH` in `wp-config.php` if needed.

= Where do I put official payment logos? =

Place SVG (preferred) or PNG files in **`resources/images/logos/`** with these exact filenames:

* `stripe.svg` (or `.png`)
* `paypal.svg`
* `pesapal.svg`
* `flutterwave.svg`
* `paystack.svg`

Recommended display size in the form: **about 56×36 px** (source assets at **2×** resolution, e.g. **112×72 px**, work well for retina). Follow each brand’s guidelines.

= How does the donor receipt work? =

The donor pays the **gross** donation amount. The database stores gross, platform fee, and net for reconciliation.

= Optional: PHP constants for advanced setups =

Rare deployments may define optional `FUNDOLAR_AUTHOR_*` constants in `wp-config.php` for fee-split or diagnostic scenarios. Normal sites rely on **Fundolar Central** and admin settings only.

== External services ==

This plugin connects to third-party services so donations can be processed, verified, and synchronized. Each service below is used only when you enable that gateway or connect Fundolar Central in **Fundolar → Settings**.

= Fundolar Central =

Used for site activation, license validation, gateway synchronization, and reporting donation status to your Fundolar account.

* **When data is sent:** When an administrator connects or syncs the site, and when donations are created or their status changes (if Central is configured).
* **What is sent:** Site URL, plugin version, API credentials you configure, donation metadata (amounts, currency, gateway references, donor name/email when collected by the form, transaction status). No card numbers pass through WordPress; processors handle payment details.
* **Where:** The base URL you set under **Fundolar → Settings → Payments** (default `https://app.fundolar.com`), or `FUNDOLAR_CENTRAL_URL` / the `fundolar_platform_base_url` filter when overridden.
* **Legal:** [Terms of Service](https://fundolar.com/terms-of-service.html), [Privacy Policy](https://fundolar.com/privacy-policy.html).

= Stripe =

Used to create and verify PaymentIntents for card donations, fetch connected-account details for fee routing, and receive signed webhooks at `/wp-json/fundolar/v1/webhooks/stripe`.

* **When data is sent:** When a donor chooses Stripe, after redirect return for status sync, when an administrator tests Stripe configuration, and when Stripe posts webhook events to your site.
* **What is sent:** Donor name and email, donation amount and currency, platform fee split metadata, PaymentIntent IDs, and your Stripe secret key (server-side only, never exposed to the browser). Card data is entered on Stripe-hosted fields, not sent to your WordPress server.
* **Where:** `https://api.stripe.com/`
* **Legal:** [Stripe Services Agreement](https://stripe.com/legal/ssa), [Privacy Policy](https://stripe.com/privacy).

= PayPal =

Used to create checkout orders, capture approved payments server-side, and obtain OAuth tokens for the PayPal REST API.

* **When data is sent:** When a donor pays with PayPal and when the plugin captures an approved order.
* **What is sent:** Donor name and email, donation amount and currency, PayPal order IDs, and your PayPal client credentials (server-side). Payment approval happens in PayPal’s checkout UI.
* **Where:** `https://api-m.paypal.com/` (sandbox hosts may apply in test mode via the `fundolar_paypal_api_base` filter).
* **Legal:** [User Agreement](https://www.paypal.com/us/webapps/mpp/ua/useragreement-full), [Privacy Statement](https://www.paypal.com/us/webapps/mpp/ua/privacy-full).

= Paystack =

Used to initialize hosted checkout, verify transactions after redirect, and list subaccounts for split configuration.

* **When data is sent:** When Paystack is enabled and a donor pays in KES, on return URL verification, and when an administrator syncs subaccount settings.
* **What is sent:** Donor email and name, amount and currency, transaction reference, split/subaccount codes, and your Paystack secret key (server-side).
* **Where:** `https://api.paystack.co/`
* **Legal:** [Terms](https://paystack.com/terms), [Privacy Policy](https://paystack.com/privacy-policy).

= Flutterwave =

Used to start hosted payments, verify transactions after redirect, and list subaccounts for split configuration.

* **When data is sent:** When Flutterwave is enabled and a donor pays, on return URL verification, and when an administrator syncs subaccount settings.
* **What is sent:** Donor name and email, amount and currency, transaction reference (`tx_ref`), Flutterwave transaction ID on return, and your Flutterwave secret key (server-side).
* **Where:** `https://api.flutterwave.com/`
* **Legal:** [Terms & Conditions](https://flutterwave.com/us/terms), [Privacy Policy](https://flutterwave.com/us/privacy-policy).

= Pesapal =

Used to request OAuth tokens, submit checkout orders, and confirm payment status when donors return from Pesapal.

* **When data is sent:** When Pesapal is enabled for a supported mobile-money currency, during checkout initialization, and when the donor returns to your site with Pesapal query parameters.
* **What is sent:** Donor name, email, and phone when required, amount and currency, order reference and tracking IDs, callback/notification URLs, and your Pesapal consumer credentials (server-side).
* **Where:** `https://pay.pesapal.com/v3/api/` (override via `fundolar_pesapal_api_base` when needed).
* **Legal:** [Terms and Conditions](https://www.pesapal.com/terms-and-conditions), [Privacy Policy](https://www.pesapal.com/privacy-policy).

== Screenshots ==

1. Donation form on the front end.
2. Fundolar settings (keys and colors).
3. Dashboard widget and transactions list.

== Privacy ==

This plugin:

* Stores donation **transactions** in a custom database table (amounts, status, gateway metadata, and donor fields your form collects).
* Sends **admin support requests** from the in-dashboard help UI to **info@fundolar.com** when a site administrator submits the form.
* Communicates with **payment processors** and **Fundolar Central** as described under **External services** when those features are configured.

Consult your privacy policy and processor agreements for lawful bases and retention. Use WordPress privacy tools where applicable for donor personal data.

For each third-party API, what is sent, when, and links to terms and privacy policies, see **External services** above.

== Changelog ==

= 1.3.0 =
* WordPress.org–ready: own Stripe/PayPal keys work without Central; optional Central for more gateways.
* Payment mode selector, Connect buttons, manual key fields, 3.5% fee banner, updated How-to guide.

= 1.2.0 =
* Seamless automatic upgrade from the legacy Fundora plugin (settings, transactions, shortcodes, gateway callbacks) with no rename shown to site owners. Existing installs update in place via `fundora/fundora.php` and stay active — nothing is deactivated.
* Past donations sync to Fundolar Central with exact stored amounts after the site connects.
* Dashboard notices: rebrand welcome for former Fundora users; Central onboarding for new installs. Legacy Fundora data is removed after migration.

= 1.1.15 =
* WordPress.org: expanded **External services** documentation (per-gateway data, timing, terms, and privacy links).
* REST donation routes require a valid nonce via `permission_callback`; PayPal capture no longer authorizes from a local order ID alone; cookie-auth bypass only when `X-WP-Nonce` is valid.
* WordPress.org: **Tested up to: 7.0**.

= 1.1.14 =
* Plugin and readme short descriptions use general wording (no gateway brand list in summaries).

= 1.1.13 =
* Shorter plugin **Description** header and readme short summary (major features only).

= 1.1.12 =
* WordPress.org: readme title **Fundolar** matches main plugin header; **Tested up to: 6.9**.

= 1.1.11 =
* **Plugins screen:** **View details** opens the same plugin information modal as WordPress.org plugins (readme sections as tabs). Uses `readme.txt` content; optional `icon-128x128.png` / `icon-256x256.png` in the plugin root for the header icon.

= 1.1.10 =
* **How to use** is now **Fundolar → How to use** (submenu), not a Settings tab. Guide simplified: site key + sync with Fundolar Central to collect donations; optional steps called out.
* Plugin list links: **Settings** and **How to use**.
* Readme installation: removed required Stripe webhook step; Central sync–first flow.

= 1.1.9 =
* Settings screen: **How to use** tab with a short step-by-step guide (superseded by submenu in 1.1.10).

= 1.1.8 =
* Renamed bundled front-end folder from `assets/` to **`resources/`** (CSS, JS, gateway logos) so the **`assets/`** directory stays free for WordPress.org SVN artwork — icons, banners, and `screenshot-*.png`.
* Readme paths updated for custom logos (`resources/images/logos/`).

= 1.1.7 =
* Removed bundled `fundolar-author-platform-config.php`; gateway and platform behavior rely on **Fundolar Central** and WordPress settings (`Fundolar_Author_Credentials` remains only for optional `FUNDOLAR_AUTHOR_*` constants in wp-config).
* Support contact email for admin submissions: **info@fundolar.com**.
* Author URI matches Plugin URI (**https://fundolar.com/**).
* Readme: expanded Description, Installation, FAQ, Privacy, and External services.

= 1.1.6 =
* WordPress.org readiness: display name and readme (contributors, privacy, external services); Author URI and Domain Path; aligned Stable tag with plugin version.
* Removed embedded credentials from optional author config — subsequent release removed the config file entirely in favor of Central.
* Support contact previously documented as support@fundolar.com (superseded by info@ in 1.1.7).

= 1.1.5 =
* No automatic default Central host: configure Fundolar Central URL (or FUNDOLAR_CENTRAL_URL / filter) to avoid DNS errors to an unreachable hostname.
* Clear error and Payments UI when the base URL is not set.

= 1.1.4 =
* Configurable Fundolar Central base URL (Payments settings) when the default hostname does not resolve on your server.
* Optional wp-config constant FUNDOLAR_CENTRAL_URL for site-wide Central URL without using the admin field.
* Clearer short hint on DNS/cURL resolution failures (error 6).
* Platform client: shared HTTP defaults (timeout, TLS verify, User-Agent), path normalization for HMAC, improved activation and API error handling.

= 1.1.3 =
* Added automatic USD-base display conversion when switching currencies.
* Presets now convert from USD-equivalent values.
* Custom amount remains editable and is converted to preserve USD-equivalent value across currency changes.

= 1.1.2 =
* Implemented Pesapal API v3 checkout initialization and redirect flow.
* Added Pesapal callback status verification on return.
* Restricted Pesapal to mobile-money enabled currencies in both frontend and backend.
* Hid/disabled Pesapal option automatically for unsupported currencies.

= 1.1.1 =
* Fixed PayPal logged-in capture nonce flow by refreshing/retrying API nonce.
* Improved nonce bootstrap behavior to avoid stale/cached tokens.
* Added Paystack KES-only enforcement and clearer user messaging.
* Improved Pesapal submit handling so server-side message is shown.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.2.0 =
Automatic legacy plugin upgrade path and Central donation history sync.

= 1.1.15 =
Documentation and REST security hardening for WordPress.org review. No database migration.

= 1.1.14 =
Description copy only. No functional changes.

= 1.1.13 =
Readme and plugin list description text only. No functional changes.

= 1.1.12 =
Documentation-only readme/header alignment for WordPress.org. No code changes required.

= 1.1.11 =
Plugins → Fundolar → **View details** now shows full readme tabs. No database changes.

= 1.1.10 =
**How to use** moved to **Fundolar → How to use** in the admin menu. No data migration.

= 1.1.9 =
Admin-only: **How to use** guide (see 1.1.10 for current location).

= 1.1.8 =
Logo overrides moved from `assets/images/logos/` to **`resources/images/logos/`**. No database changes.

= 1.1.7 =
Removes the optional `fundolar-author-platform-config.php` file; use Fundolar Central and Settings as documented. Support email for admin contact is info@fundolar.com.

= 1.1.6 =
WordPress.org packaging and documentation updates.

= 1.1.5 =
Set Fundolar Central URL under Payments (or wp-config FUNDOLAR_CENTRAL_URL). No implicit default host.

= 1.1.4 =
Central URL is configurable if the default host fails DNS on your server. Optional FUNDOLAR_CENTRAL_URL in wp-config.php.

= 1.1.3 =
Automatic USD-base amount conversion improvements.
