=== Fundolar ===
Contributors: biggerbenson, dchamp-legacy, fundolar
Donate link: https://fundolar.com/
Tags: donations, fundraising, stripe, paypal, paystack
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.1.14
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept donations through a customizable shortcode form, sync payment setup with Fundolar Central, and review results with dashboard insights and a full transaction history.

== Description ==

**Fundolar** adds a donation shortcode, connects your site to **Fundolar Central** for licensing and gateway synchronization, and keeps donation records with reporting for reconciliation.

**What you get**

* **Shortcode** `[fundolar_donate]` — place the donation form on any page or post (classic editor, blocks that render shortcodes, or patterns that include the shortcode).
* **Payment methods** — enable the gateways your Central account supports; turn off anything you do not need.
* **Fundolar Central** — connect your site using the license/API flow in **Fundolar → Settings**. Central supplies the integration layer so gateway configuration and platform behavior stay consistent with your account (see **External services**).
* **Appearance** — preset and custom amounts, currency selector, configurable colors (deep purple and gold defaults), optional logo images under `resources/images/logos/`.
* **WordPress dashboard widget** — recent completed donation totals and trend-style summaries for administrators.
* **Transactions** — filterable list for successful, failed, pending, and refunded records; metadata stored in a dedicated table on your site.
* **Security-minded settings** — API material stored with WordPress salts where encryption applies; capability checks and nonces on sensitive actions.

**Platform fee (3.5%)**

Each donation is recorded with:

* **Receipt amount** — the gross amount the donor authorizes (e.g. $5.00).
* **Platform fee** — 3.5% of that amount, for transparent reporting.
* **Net to site** — the remainder attributed to the site in reporting.

Splitting settlements across bank accounts depends on your payment processor (Paystack splits, Flutterwave subaccounts, Stripe Connect, etc.). Configure payout behavior in each provider’s dashboard as required.

**Who this is for**

Churches, nonprofits, creators, and anyone who needs a professional donation experience without building checkout from scratch. **Requires** a working connection to **Fundolar Central** (correct base URL and DNS from your host) for the full activation and sync flow described in **Installation**.

== Installation ==

= Before you start =

* **WordPress** 6.0 or newer and **PHP** 7.4+ (match the headers above; newer versions are recommended).
* **TLS** — your site should use HTTPS for production donations.
* **Fundolar Central URL** — your server must be able to resolve and reach the Central base URL you configure (see FAQ if you see cURL / DNS errors).

= Install the plugin =

1. Upload the `fundolar` folder to `wp-content/plugins/`, or upload the ZIP via **Plugins → Add New → Upload Plugin**.
2. Activate **Fundolar** under **Plugins**.
3. In the admin, open **Fundolar → Settings** (or follow any onboarding links shown).

= Connect Fundolar Central =

4. Complete **license / site activation** with Fundolar Central as documented in your Fundolar account materials. This links WordPress to your Central organization so gateway and platform data can sync.
5. Set **Fundolar Central URL** under **Fundolar → Settings → Payments** if your deployment uses a non-default host (or define `FUNDOLAR_CENTRAL_URL` in `wp-config.php` above the “stop editing” line — the admin field wins when both are set).

= Connect and collect =

6. Under **Fundolar → Settings → Payments**, paste your **site key**, connect to Fundolar Central, then **sync**. Your site stays in sync with Central; you can start collecting donations once sync succeeds.
7. Optional: adjust currency and preset amounts on the **General** tab, colors on **Layout**, and notifications under **Advanced**. See **Fundolar → How to use** for a short checklist.

= Publish the form =

8. Add `[fundolar_donate]` to a page or post and publish. Preview as a visitor and run a small test donation if your processor offers test mode.

= Optional: payment logos =

9. For branded logos on the form, add SVG (preferred) or PNG files to `wp-content/plugins/fundolar/resources/images/logos/` using the filenames listed in the FAQ.

= Uninstall =

Deactivating removes scheduled hooks tied to the plugin. Deleting the plugin via WordPress runs `uninstall.php`, which removes plugin options and the custom transactions table — **back up** data first if you need historical exports.

== Frequently Asked Questions ==

= How does Fundolar Central relate to this plugin? =

The plugin communicates with **Fundolar Central** for activation, synchronization, and platform features. You configure the Central base URL under **Fundolar → Settings → Payments**. Gateway and operational details are coordinated through that connection so you do not rely on a separate “author config” file shipped inside the plugin.

= Does the plugin collect personal data? =

Donation records in your database may include donor email and name when the form collects them. Card data is handled by your payment processor. See **Privacy** below.

= cURL error 6 / Could not resolve host =

Set **Fundolar Central URL** to a hostname your web server’s DNS can resolve, or set `FUNDOLAR_CENTRAL_URL` in `wp-config.php`. You can also normalize the base URL with the `fundolar_platform_base_url` filter.

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

**External services summary**

* **Payment gateways** — Stripe, PayPal, Paystack, Flutterwave, Pesapal (only those you enable): processing charges, redirects, and verification.
* **Fundolar Central** — configured base URL (for example `https://app.fundolar.com` when applicable): activation, sync, and related API traffic needed for the integration model described in this readme.

== Changelog ==

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
