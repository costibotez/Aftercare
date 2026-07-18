=== Aftercare — Core Web Vitals Monitor, Change Ledger & Client Reports ===
Contributors: costibotez
Tags: core web vitals, performance, monitoring, activity log, client reports
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.0.0
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
* Homepage plus up to 5 tracked URLs, sparkline trends, pass / warn / fail status pills

= 💰 Performance budgets and incidents =

* Editable budgets per metric (defaults: LCP 2.5 s, INP 200 ms, CLS 0.1, TTFB 800 ms)
* A daily p75 over budget — or 20% worse than your 28-day baseline — opens an incident and emails you
* Incidents track status (open, acknowledged, resolved, dismissed) and resolve automatically when the metric recovers
* Every incident shows the raw change timeline from the 72 hours before the breach

= 📒 Change ledger =

* Records plugin updates (old → new version), activations, deactivations, theme updates and switches, core updates, allow-listed settings changes, content publishes and new users
* Human-readable entries with the responsible user: "WP Rocket updated 3.15 to 3.16 by admin"
* Filterable timeline by type and date, 90 days of history

= 🧰 Fits into your workflow =

* **Dashboard widget** with vitals status and open incidents on the main wp-admin screen, plus a pass / warn / fail dot in the admin bar
* **Site Health integration** — checks that vitals collection is configured, daily checks are running and budgets are not breached
* **WP-CLI commands** — `wp aftercare pull`, `wp aftercare check`, `wp aftercare status` and `wp aftercare run` for scripted setups and real server crons
* **Weekly digest email** — vitals status, changes made and incidents from the past 7 days (opt-out in settings)
* **Guided first run** — a three-step setup pointer until first data arrives, and suggested privacy-policy text for the RUM beacon

= 🔒 Private by design =

* No phoning home, no accounts, no external service. CrUX calls go straight from your server to Google with your key; RUM beacons post to your own site's REST API
* Uninstall removes every table and option unless you choose to keep the data

= Aftercare Pro =

The free plugin tells you *that* something regressed and *what changed*. Aftercare Pro tells you **which change probably did it**:

* **Cause attribution** — ranks every change from the 72 hours before a regression with high / medium / low confidence badges, so you fix the right thing first
* **White-label client reports** — monthly drafts with vitals versus last month, work performed, incidents caught and resolved; your logo, colours and a personal note; print/PDF and email delivery
* **Slack and webhook notifications**
* **Unlimited tracked URLs**, per-URL budgets, 13-month history, unlimited ledger retention and CSV export

= Privacy =

Aftercare does not phone home. CrUX data is fetched directly from Google's API using the key you provide. The optional RUM beacon posts anonymous metric values (no cookies, no personal data, no IPs stored) to your own site's REST API and can be disabled at any time.

== Installation ==

1. Install and activate the plugin.
2. Open **Aftercare → Settings** and paste a Google API key with the Chrome UX Report API enabled (free).
3. Optionally add up to 5 extra tracked URLs and enable real-user monitoring.
4. Vitals appear after the first daily pull — or press "Run daily checks now" on the dashboard.

== Frequently Asked Questions ==

= Where does the vitals data come from? =

From the Chrome UX Report (field data from real Chrome users), fetched daily with your own Google API key. Optionally, a small RUM beacon collects vitals from your own visitors as a second source.

= Does the free version limit anything? =

Free monitors the homepage plus 5 URLs, keeps 30 days of vitals and 90 days of ledger history, and includes budgets, incidents and email alerts. Pro adds cause attribution, client reports, Slack/webhooks, unlimited URLs and longer retention.

= What happens to my data when I delete the plugin? =

By default all Aftercare tables and options are removed on uninstall. Tick "Keep Aftercare data" in Settings to preserve them.

== Screenshots ==

1. Dashboard with vitals cards, sparklines and status pills
2. Change ledger timeline
3. Incident detail with the 72-hour change window
4. Monthly client report (Pro)

= Can I run the checks from a server cron instead of WP-Cron? =

Yes. `wp aftercare run` executes the full daily pipeline (CrUX pull, RUM aggregation, breach detection, retention). Aftercare also uses Action Scheduler automatically when another plugin (such as WooCommerce) provides it.

== Changelog ==

= 1.0.0 =
* Vitals monitoring: daily CrUX p75 pull (LCP, INP, CLS, TTFB) with your own Google API key, optional ~2 KB real-user monitoring beacon
* Performance budgets with breach detection against budget and 28-day baseline; incidents with status workflow and email alerts
* Change ledger: plugin/theme/core updates, activations, theme switches, settings changes, publishes and new users
* Dashboard widget, admin bar status indicator, Site Health tests, first-run setup guide
* WP-CLI commands: pull, check, status, run
* Weekly digest email (opt-out)
* Privacy-policy content for the RUM beacon
* Pro: cause attribution, white-label client reports, Slack/webhooks, extended retention
