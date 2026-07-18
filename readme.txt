=== Aftercare — Core Web Vitals Monitor, Change Ledger & Client Reports ===
Contributors: costibotez
Tags: core web vitals, performance, monitoring, activity log, client reports
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Performance accountability for agencies. Watch Core Web Vitals, record every site change, catch regressions before the client does.

== Description ==

Aftercare is built for agencies and freelancers who maintain client WordPress sites on monthly care plans. It answers the two questions every retainer client eventually asks: *"what did you actually do this month?"* and *"why did the site get slower?"*

**Vitals Monitor** — pulls daily p75 Core Web Vitals (LCP, INP, CLS, TTFB) from the Chrome UX Report using your own Google API key, optionally enriched by a lightweight (~2 KB) real-user monitoring beacon on a sampled fraction of visits. Set performance budgets per metric; a breach of budget or a 20% regression against the 28-day baseline opens an incident and emails you.

**Change Ledger** — every plugin update, activation and deactivation, theme update and switch, core update, allow-listed settings change, content publish and new user is recorded with a human-readable summary and the responsible user. When something regresses, you can see exactly what changed in the 72 hours before.

**Incidents** — regressions become trackable incidents with status (open, acknowledged, resolved, dismissed), the breach value, the budget and the baseline — plus the raw change timeline side by side.

= Aftercare Pro =

* **Cause attribution** — ranks every change from the 72 hours before a regression with confidence badges, so you fix the right thing first
* **White-label client reports** — monthly drafts with vitals versus last month, work performed, incidents caught; your logo, colours and a personal note; print/PDF and email delivery
* **Slack and webhook notifications**
* **Unlimited tracked URLs**, per-URL budgets, 13-month history, unlimited ledger retention and CSV export

= Privacy =

Aftercare does not phone home. CrUX data is fetched directly from Google's API using the key you provide. The optional RUM beacon posts anonymous metric values (no cookies, no IPs stored) to your own site's REST API.

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

== Changelog ==

= 1.0.0 =
* Initial release: vitals monitoring (CrUX + RUM), performance budgets, breach detection, incidents, change ledger, email alerts, Pro attribution/reports/notifications.
