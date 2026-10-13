# Next two features: plan

Status: proposal. Target: 1.1.0 (feature 1) and 1.2.0 (feature 2).

## Why these two

Aftercare's promise is "know what changed, know what it cost". Two gaps weaken that promise today.

1. **The vitals data reacts too slowly and is often missing.** The Chrome UX Report API returns a rolling 28-day p75. A plugin update that makes the site 40% slower moves that number a little each day, so the 20% regression threshold is usually crossed one to three weeks after the change. By then the change sits far outside the 72-hour window that the incident page and the attribution engine look at. Worse, most small care-plan sites have no Chrome UX Report record at all, and the real-user beacon needs real traffic. For those sites the dashboard stays empty.
2. **The most expensive regressions are not slow pages, they are broken pages.** A fatal error after an update, a white screen or a checkout returning 500 costs far more than a slower LCP. Aftercare records the update that caused it but never notices the breakage.

Both features reuse the ledger, incidents, notifications and attribution pipeline that already exist, so they make the existing product better instead of adding a separate one. Agency Hub and multisite stay on the roadmap but depend on a richer per-site signal, which these two features provide first.

---

## Feature 1: Lab checks with change-triggered re-tests

### What the user gets

- Daily lab measurements from the PageSpeed Insights API (Lighthouse, mobile profile) for the homepage and every tracked URL, using the same Google API key. Works on a site with zero traffic.
- **A re-test 15 minutes after every plugin, theme or core update, theme switch or plugin activation.** If the lab numbers regress against the lab baseline, an incident opens the same day, linked to the exact change that preceded it: "LCP regressed 38% in the lab check 15 minutes after WP Rocket updated 3.15 to 3.16".
- The dashboard shows field data and lab data side by side, labelled clearly, never averaged together.

### Design

**Data source.** New sample source `lab` in `SampleRepository`. Metrics stored: LCP, CLS and TTFB (from the `server-response-time` audit) plus a new lab-only metric `TBT` (Total Blocking Time), the accepted lab proxy for INP. INP has no lab equivalent and is never written from lab data. The baseline is already source-scoped, so lab readings are only ever compared with lab history.

**Noise control.** Lighthouse varies run to run. Each check takes the median of 3 runs per URL (one run for change-triggered checks on more than 3 URLs, to bound the time cost). A lab regression must exceed both the 20% factor and a minimum absolute delta per metric (LCP 300 ms, TBT 100 ms, CLS 0.05, TTFB 150 ms) before an incident opens.

**Lab budgets (decided in milestone 2).** A throttled mobile Lighthouse run is routinely slower than the field budgets, so lab incidents open only on regression against the lab baseline. For lab data the budgets set the dashboard status, not incidents. Lab and field incidents are kept apart by an `incidents.sample_source` column (`field` or `lab`), so a recovery in one never resolves the other.

**Change-triggered checks.** `Ledger\Listeners` already records the triggering events. After recording one of `plugin_update`, `plugin_activate`, `theme_update`, `theme_switch` or `core_update`, it schedules a single event `aftercare_lab_check` 15 minutes later, passing the ledger event ID. Debounce: if a check is already pending, push it back to 15 minutes after the newest change and append the event ID, so a bulk update of 12 plugins produces one check, not 12.

**Execution.** A PageSpeed Insights call takes 10 to 30 seconds. Each URL runs as its own scheduled action (Action Scheduler when present, `wp_schedule_single_event` otherwise) so no single cron request runs for minutes. Cap: 6 URLs per check, 4 change-triggered checks per day.

**Incidents.** `BreachDetector` gains a `run_for_source( 'lab' )` path that evaluates a fresh lab sample immediately instead of waiting for "yesterday". Incidents get a new `trigger_event_ids` column holding the ledger events that preceded the check. In the Pro build `Attribution::score()` gives those events a fixed high score (95), because the timing evidence is direct.

### Files

| File | Change |
|---|---|
| `src/Vitals/LabClient.php` (new) | PageSpeed Insights client: request, median of runs, parse audits, store `lab` samples |
| `src/Vitals/LabScheduler.php` (new) | Debounced scheduling of change-triggered checks; per-URL actions; daily caps |
| `src/Vitals/SampleRepository.php` | Add `SOURCE_LAB`; extend `SOURCE_PRIORITY` and the literal `FIELD()` placeholder runs plus `assert_source_counts()`; field sources stay ahead of lab |
| `src/Vitals/BreachDetector.php` | Source-specific evaluation, absolute minimum deltas for lab |
| `src/Core/Options.php` | `lab_enabled` (default on when an API key exists), `lab_on_change` (default on), `TBT` budget (200 ms); a `LAB_METRICS` list next to `METRICS` |
| `src/Core/Migrations.php` | Schema 1.2.0: `incidents.sample_source` (milestone 2); schema 1.2.1: `incidents.trigger_event_ids` (milestone 3) |
| `src/Core/Cron.php` | Daily pipeline step "lab check" after the Chrome UX Report pull |
| `src/Ledger/Listeners.php` | Call `LabScheduler::queue( $event_id )` after the five trigger event types |
| `src/Incidents/Attribution.php` (Pro) | Boost events listed in `trigger_event_ids` |
| `src/Admin/DashboardPage.php`, `SettingsPage.php`, `IncidentsPage.php` | Field and lab rows, lab toggles, "caught by lab check after: ..." line on incident detail |
| `src/Admin/SiteHealth.php` | Test that the PageSpeed Insights API is enabled for the key |
| `src/Core/CliCommands.php` | `wp aftercare lab [--url=<url>]` |
| `templates/email-incident.php` | Mention the triggering change when present |
| `bin/compat/checks.php` | Mock the API response through `pre_http_request`, assert an incident after a simulated update |

### Free or Pro

Free. It is what makes the free plugin useful on low-traffic sites and the directory guidelines forbid locking it. Pro gains from it through sharper attribution.

### Risks

- The PageSpeed Insights API must be enabled on the key separately from the Chrome UX Report API. The Site Health test and the onboarding notice must say so plainly.
- Lab results come from Google's data centres, not real visitors. Labels must say "lab" everywhere, and budgets for lab are evaluated separately from field budgets.
- Quota is 25,000 requests per day per key; the caps above keep one site under 100.

### Milestones

1. `LabClient` plus `SOURCE_LAB` plus daily step, dashboard rows. Shippable on its own.
2. Lab breach evaluation with absolute deltas.
3. Change-triggered scheduling, `trigger_event_ids`, incident and email copy.
4. Pro attribution boost, CLI, Site Health, compatibility test coverage.

---

## Feature 2: Error and availability watch

### What the user gets

- Every PHP fatal error on the site is captured, grouped by signature and shown in the ledger as "Fatal error in woocommerce/includes/class-wc-cart.php line 412 (37 times in 2 hours)".
- After every change-triggered lab check (feature 1), and once per hour otherwise, a loopback request checks that the homepage and tracked URLs return 200 and do not contain the WordPress critical error page.
- Either signal opens an **error incident**, which goes through the same email, Slack and webhook notifiers and the same "changes from the 72 hours before" view. A fatal error 3 minutes after a plugin update is the strongest attribution signal the plugin can have.

### Design

**Capture.** Hook the `wp_should_handle_php_error` filter (fires inside core's fatal error handler for every fatal, before recovery mode) and return the incoming value untouched. Record `type`, `file` (relative to `ABSPATH`), `line` and a truncated message (200 characters, paths only, no request data or query strings). If `WP_DISABLE_FATAL_ERROR_HANDLER` is set, fall back to a `register_shutdown_function` that reads `error_get_last()`.

**Grouping and rate limiting.** Signature = `md5( file . line . type )`. A new table `aftercare_errors` stores one row per signature with `first_seen`, `last_seen`, `count` and the culprit plugin or theme slug derived from the file path. The write during shutdown is a single `INSERT ... ON DUPLICATE KEY UPDATE`, so a fatal on every request costs one query, not a flood. A ledger entry is written only when a signature is first seen or reappears after 24 hours of silence.

**Availability check.** `wp_remote_get` with `sslverify` following the site setting, 10 second timeout, `Cache-Control: no-cache` plus a cache-busting query argument so page caches do not hide the error. Healthy means status 200 and no `wp-die-message` critical error marker in the body. Two consecutive failures open an incident; one success resolves it. This cannot detect a fully down server (the check runs on that server), and the settings screen says so, pointing to an external uptime monitor for that case.

**Incidents.** Error incidents use the existing table with `metric = 'ERROR'` or `metric = 'HTTP'` and a new `kind` column (`vitals` or `error`), so list, filters, statuses and notifications work unchanged. `breach_p75` holds the occurrence count or status code; `baseline_p75` and `budget_value` stay null for errors and the incident template branches on `kind`. Auto-resolve: no new occurrences of the signature for 24 hours, or availability back to 200.

**Attribution (Pro).** A fatal whose file sits inside a plugin or theme updated in the preceding 72 hours gets score 100 ("high") for that change, ahead of any timing rule.

### Files

| File | Change |
|---|---|
| `src/Errors/Collector.php` (new) | Fatal error hook, signature, culprit slug, shutdown write |
| `src/Errors/Repository.php` (new) | `aftercare_errors` table access, upsert, queries, prune |
| `src/Errors/AvailabilityCheck.php` (new) | Loopback check, consecutive failure state, incident open and resolve |
| `src/Core/Migrations.php` | Schema 1.3.0: `aftercare_errors` table; `incidents.kind`; add the table to `tables()` so uninstall removes it |
| `src/Core/Cron.php` | Hourly hook for availability; error prune in the daily retention step |
| `src/Core/Plugin.php` | Register the collector early (before other plugins load where possible) |
| `src/Ledger/Listeners.php`, `src/Admin/LedgerPage.php` | New event types `php_error` and `site_unavailable` plus filter labels |
| `src/Admin/IncidentsPage.php`, `templates/email-incident.php` | Error variant of detail view and email |
| `src/Admin/ErrorsPage.php` (new) | Grouped error list: signature, culprit, count, first and last seen |
| `src/Incidents/Attribution.php` (Pro) | File-path match rule |
| `src/Core/Privacy.php` | Policy text: error messages are stored locally, truncated, without visitor data |
| `bin/compat/checks.php` | Trigger a fatal from a test must-use plugin; assert one ledger row, one incident, one email |

### Free or Pro

Capture, ledger, incidents and email: free. Slack and webhook delivery and file-path attribution follow the existing Pro split.

### Risks

- Running code inside a fatal shutdown must never throw. Every step is wrapped, the database write is skipped if `$wpdb` is unavailable and the collector has no dependencies beyond `$wpdb`.
- Error messages can contain sensitive values. Truncate, strip anything after the first newline and never store request URLs with query strings.
- Loopback requests are blocked on some hosts. Reuse the Site Health loopback result: if loopbacks fail there, disable the availability check and say why instead of opening false incidents.

### Milestones

1. Collector, repository, ledger entries. Shippable on its own as "errors in the ledger".
2. Error incidents with `kind`, email variant, auto-resolve.
3. Availability check, hooked into feature 1's change-triggered check.
4. Errors page, Pro attribution rule, privacy text, compatibility test coverage.

---

## Order of work

Feature 1 first: it adds the change-triggered scheduling that feature 2's availability check reuses, and it fixes the core vitals promise. Each milestone above is a separate pull request that leaves `main` releasable. Both features ship with readme changelog, README feature list and Romanian translation updates in the same release.
