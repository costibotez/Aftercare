=== Aftercare ===
Contributors: costibotez
Tags: core web vitals, performance, monitoring, activity log, client reports
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Daily Core Web Vitals monitoring, a complete change ledger and performance regression alerts. Catch problems before your visitors do.

== Description ==

**Know what changed. Know what it cost. Prove what you did.**

Aftercare watches your WordPress site after launch. Every day it records your real-world Core Web Vitals; every second it records the changes made to the site. When performance regresses, it connects the two — an incident opens, an email goes out, and the incident page shows every change from the 72 hours before the regression, right next to the trend.

It is built for agencies and freelancers who maintain client sites on monthly care plans — and for any site owner who wants an answer to "why did the site just get slower?" that is based on data instead of guesswork.

= 📈 Core Web Vitals monitor =

* Daily p75 values for **LCP, INP, CLS and TTFB** from the Chrome UX Report — real field data from actual Chrome users, fetched with your own free Google API key
* Optional **real-user monitoring beacon**: ~2 KB, no external libraries or services, loaded for a configurable sample of visits and aggregated to daily p75 on your own site
* Track the homepage plus as many other URLs as you like, with sparkline trends and pass / warn / fail status pills

= 💰 Performance budgets and incidents =

* Editable budgets per metric (defaults: LCP 2.5 s, INP 200 ms, CLS 0.1, TTFB 800 ms)
* A daily p75 over budget — or 20% worse than your 28-day baseline — opens an incident and emails you
* Incidents track status (open, acknowledged, resolved, dismissed) and resolve automatically when the metric recovers
* Every incident shows the raw change timeline from the 72 hours before the breach

= 📒 Change ledger =

* Records plugin updates (old → new version), activations, deactivations, theme updates and switches, core updates, allow-listed settings changes, content publishes and new users
* Human-readable entries with the responsible user: "WP Rocket updated 3.15 to 3.16 by admin"
* Filterable timeline by type and date, CSV export, and the full history kept by default

= 🧰 Fits into your workflow =

* **Dashboard widget** with vitals status and open incidents on the main wp-admin screen, plus a pass / warn / fail dot in the admin bar
* **Site Health integration** — checks that vitals collection is configured, daily checks are running and budgets are not breached
* **WP-CLI commands** — `wp aftercare pull`, `wp aftercare check`, `wp aftercare status` and `wp aftercare run` for scripted setups and real server crons
* **Weekly digest email** — vitals status, changes made and incidents from the past 7 days (opt-out in settings)
* **Guided first run** — a three-step setup pointer until first data arrives, and suggested privacy-policy text for the RUM beacon

= 🔒 Private by design =

* No phoning home, no accounts, no external service. CrUX calls go straight from your server to Google with your key; RUM beacons post to your own site's REST API
* Uninstall removes every table and option unless you choose to keep the data

This plugin is complete in itself. Nothing here is capped, timed or held back: every URL you add is monitored, vitals are kept for thirteen months and the change ledger is kept in full.

A separate add-on plugin, distributed outside this directory, adds ranked cause attribution and white-label monthly client reports with Slack and webhook delivery. It is not required, and this plugin does not check for a licence, prompt for one or change behaviour without it.

Aftercare is built and maintained by [Nomad Developer](https://www.nomad-developer.co.uk/).

= Privacy =

Aftercare does not phone home. CrUX data is fetched directly from Google's API using the key you provide. The optional RUM beacon posts anonymous metric values (no cookies, no personal data, no IPs stored) to your own site's REST API and can be disabled at any time.

== External Services ==

Aftercare connects to the following external services. No connection is made until you configure it.

**Chrome UX Report API (Google)** — used to retrieve real-user Core Web Vitals field data for the URLs you choose to track. Once you enter your own Google API key, the plugin sends your tracked URLs (or your site's origin) together with that API key to `https://chromeuxreport.googleapis.com` once per day per URL. No visitor data, personal data or site content is sent. This service is provided by Google: [Terms of Service](https://developers.google.com/terms), [Privacy Policy](https://policies.google.com/privacy). If no API key is configured, no request is ever made.

== Installation ==

1. Install and activate the plugin.
2. Open **Aftercare → Settings** and paste a Google API key with the Chrome UX Report API enabled (free).
3. Optionally add up to 5 extra tracked URLs and enable real-user monitoring.
4. Vitals appear after the first daily pull — or press "Run daily checks now" on the dashboard.

== Frequently Asked Questions ==

= Where does the vitals data come from? =

From the Chrome UX Report (field data from real Chrome users), fetched daily with your own Google API key. Optionally, a small RUM beacon collects vitals from your own visitors as a second source.

= Does this plugin limit anything? =

No. Tracked URLs are unlimited, vitals are kept for thirteen months and the ledger is kept in full. Both retention windows can be shortened with the `aftercare_vitals_retention_days` and `aftercare_ledger_retention_days` filters if you would rather keep the tables small.

= What happens to my data when I delete the plugin? =

By default all Aftercare tables and options are removed on uninstall. Tick "Keep Aftercare data" in Settings to preserve them.

= Can I run the checks from a server cron instead of WP-Cron? =

Yes. `wp aftercare run` executes the full daily pipeline (CrUX pull, RUM aggregation, breach detection, retention). Aftercare also uses Action Scheduler automatically when another plugin (such as WooCommerce) provides it.

== Screenshots ==

1. Dashboard — vitals cards with budgets, status pills, sparklines, incidents and recent changes
2. Change ledger — filterable timeline of every site change
3. Incident detail — breach versus budget and baseline, with the changes from the 72 hours before (ranked cause attribution shown is a Pro feature)
4. Settings — API key, tracked URLs, performance budgets and notifications

== Changelog ==

= 1.0.3 =
* Removed every limit that depended on a licence check: tracked URLs are unlimited, vitals are kept for thirteen months and the change ledger is kept in full
* Settings fields are never disabled and are always saved. Settings belonging to the separate add-on are shown only when that add-on is installed, instead of being displayed in a locked state
* Removed the licensing code, the upgrade prompts and the reports screen that existed only to advertise the add-on. Features now appear when the code implementing them is present
* Removed the bundled translation files and the load_plugin_textdomain call, since translations come from translate.wordpress.org

= 1.0.2 =
* Vitals queries now bind the table name and the source ranking list as parameters instead of interpolating them into the SQL string
* Readme: a Frequently Asked Questions entry that had been placed inside the Screenshots section now renders where it belongs
* Declared compatibility with WordPress 7.1

= 1.0.1 =
* CrUX samples now record which level they came from — `crux_url` (the tracked URL) or `crux_origin` (the whole site, used when the URL-level record is too thin)
* The 28-day baseline is built from a single source, so a URL-level reading is never compared against an origin-level average
* The baseline comparison stays muted until 7 days of same-source history exist, preventing a false "20% regression" the day CrUX switches levels
* Sparklines plot one point per day from the preferred source instead of blending levels together

= 1.0.0 =
* Vitals monitoring: daily CrUX p75 pull (LCP, INP, CLS, TTFB) with your own Google API key, optional ~2 KB real-user monitoring beacon
* Performance budgets with breach detection against budget and 28-day baseline; incidents with status workflow and email alerts
* Change ledger: plugin/theme/core updates, activations, theme switches, settings changes, publishes and new users
* Dashboard widget, admin bar status indicator, Site Health tests, first-run setup guide
* WP-CLI commands: pull, check, status, run
* Weekly digest email (opt-out)
* Privacy-policy content for the RUM beacon
* Pro: cause attribution, white-label client reports, Slack/webhooks, extended retention

== Upgrade Notice ==

= 1.0.3 =
Tracked URL and history limits are gone. Existing data and settings are untouched, and retention now keeps more than before rather than less.

= 1.0.2 =
Hardening and documentation fixes only. No changes to stored data or settings.

= 1.0.0 =
Initial release.
