# Aftercare

Performance accountability for agencies and freelancers maintaining WordPress sites on monthly care plans. Aftercare watches every client site after handover, records every change, detects Core Web Vitals regressions, attributes them to their probable cause (Pro) and turns the month into a white-label client report (Pro).

## Modules

| Module | What it does | Free | Pro |
|---|---|---|---|
| **Vitals Monitor** | Daily CrUX p75 pull (your own Google API key) + optional ~2 KB RUM beacon, budgets, breach detection | 5 URLs, 30-day history | Unlimited URLs, per-URL budgets, 13 months |
| **Change Ledger** | Plugin/theme/core updates, activations, publishes, settings, users — human-readable, with actor | 90 days | Unlimited + CSV export |
| **Incidents** | Budget breach or 20% baseline regression opens an incident; email alert; auto-resolve on recovery | ✅ | + Slack & webhooks |
| **Attribution** | Ranks changes from the 72 h before a regression with confidence badges | — | ✅ |
| **Client Reports** | Monthly white-label draft: vitals vs last month, work performed, incidents; print/PDF + email | — | ✅ |

## Structure

```
aftercare.php          Bootstrap, PSR-4 autoloader, Freemius loader hook
uninstall.php          Clean removal (respects "keep data" setting)
src/
  Core/                Container, activation, migrations (4 custom tables), cron, options
  Vitals/              CrUX client, RUM REST endpoint + aggregation, breach detector
  Ledger/              Hook listeners, event storage/queries
  Incidents/           Incident storage, rule-based attribution engine
  Reports/             Monthly report builder + storage (Pro)
  Admin/               Menu + Dashboard/Ledger/Incidents/Reports/Settings screens
  Notifications/       Email (free), Slack + generic webhook (Pro)
  Licensing/           Pro gating (`aftercare_is_pro` filter; Freemius when present)
assets/                Admin CSS/JS (dependency-free SVG sparklines), RUM beacon
templates/             Incident email, white-label report
languages/             POT + Romanian translation
```

## Development notes

- WordPress 6.4+, PHP 8.1+. No build step, no framework.
- Pro gating: without the Freemius SDK the plugin runs free; `add_filter( 'aftercare_is_pro', '__return_true' )` unlocks Pro for development and service installs. Drop the Freemius SDK into `vendor/freemius/` for production licensing.
- PDF export: reports are print-friendly HTML by default; hook `aftercare_pdf_engine` to plug in dompdf.
- Cron: uses Action Scheduler when available (e.g. WooCommerce present), WP-Cron otherwise, with an admin health warning when WP-Cron looks unreliable.
- QA shortcut: **Dashboard → Run daily checks now** executes the full daily pipeline (CrUX pull → RUM aggregation → breach detection → retention) on demand. Forcing a breach by lowering a budget then running it opens an incident and sends the alert email.
