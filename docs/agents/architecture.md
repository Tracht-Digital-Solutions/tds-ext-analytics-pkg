# Architecture

## Data flow

```
public site layout ── startAnalytics() (tds-shared/analytics)
   │  only after consent "analytics"; ids deleted on withdrawal
   ▼  text/plain beacon, no preflight
POST /analytics/collect ── Payload (allow-list) → Sites (Origin = claimed site)
   │  bot / Sec-GPC / site off / rate limit → 204, nothing stored
   ▼
Collector → analytics_session (one row per visit) + analytics_event
   │  IP: GeoIp country + salted rate hash, then discarded. UA: coarse families, then discarded.
   ▼
Maintenance (≤ once an hour, in-process — no cron on the host)
   raw days older than retention_days → analytics_daily (anonymous totals), raw rows deleted
   ▼
GET /analytics/{overview,pages,scroll,sources,clicks,forms,summary}  (analytics:read)
   Metrics: days ≤ MAX(analytics_daily.day) from totals, later days from raw
```

## Shape

| Path | Role |
|---|---|
| `src/index.ts` | Manifest: `/statistik`, widget `analytics-visits`, settings section, `analytics:read` |
| `pages/Index.astro` → `islands/Dashboard.tsx` | Four tabs: Übersicht, Seiten & Absprung, Herkunft & Klicks, Formulare |
| `islands/charts.tsx` | Hand-drawn SVG (area, sparkline, bar list); theme tokens only |
| `islands/lib.ts` | `loadReport` (via `apiFetch`), Berlin dates, number formatting |
| `php/src/AnalyticsModule.php` | Routes, settings, maintenance trigger |
| `php/src/Domain/Metrics.php` | **The single definition of every number** — reports and roll-up both use it |
| `php/src/Support/*` | Payload validation, channel, user-agent families, GeoIp, Berlin clock |

## Privacy rules (do not loosen without a new consent text)

- Nothing is measured before consent; the beacon checks `consentGranted("analytics")`.
  A change to what is collected means a new `CONSENT_VERSION` in tds-shared.
- Never store an IP address, a user agent, a query string, a form value or a label text.
  New fields go through `Payload`'s allow-list and its clipping.
- The visitor id expires after 30 days, fixed from creation. `POST /analytics/forget` erases
  its raw rows; day totals hold no id and stay.
- Retention defaults to 90 days (setting `retention_days`, 7–400).
- Country data comes from the local DB-IP Lite file (CC BY 4.0) — the attribution is shown
  under "Länder". The address never leaves the server.

## Metrics are defined once

`Metrics::DEFS` maps a `metric.dim` pair to SQL. The roll-up stores exactly those pairs per
day and site, and `Metrics::counts()` sums totals and raw rows across the boundary, so a
report over 120 days reads continuously. Add a number by adding a pair; never write a report
query beside it. `AnalyticsDatabaseTest::testTheRollUpKeepsEveryReportContinuous` asserts
every report is identical before and after a roll-up.

Visitors in rolled-up periods are the sum of daily distinct visitors (a day total cannot be
de-duplicated across days); the dashboard says so.

## Sites and hosts

`Support/Sites::HOSTS` maps production hosts to site ids (`landing`, `blog`, `tools`,
`auth`, `shop`). The setting `extra_hosts` (`host=site` per line) admits a local or staging
host. A new public site needs an entry in `Sites::ALL`, `HOSTS`, `islands/lib.ts` `SITES`
and its layout's `startAnalytics({ site })`.
