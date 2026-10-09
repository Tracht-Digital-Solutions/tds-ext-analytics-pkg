# tds-ext-analytics-pkg

**Besucher-Statistik** for the TDS public sites (Landingpage, Blog, Tools, Shop, Login):
where visitors come from, what they click, how far they scroll, on which page they leave and
at which form field they give up — self-hosted, without third parties, and only for visitors
who agreed to the consent category „Statistik“.

## Parts

| Part | Where |
|---|---|
| Beacon (`startAnalytics`, `forgetAnalytics`) | `@tracht-digital-solutions/tds-shared/analytics` (≥ 0.50.0) |
| Collector, reports, retention | `php/src/AnalyticsModule.php`, composed into `tds-core-frontend-api` |
| Dashboard `/statistik`, widget, settings | `src/index.ts` → `tds-admin-frontend` |

## What is stored

Per visit: a random visitor id (30 days in the browser), entry/exit page, page count, engaged
time, referrer host, UTM tags, country (from a local DB-IP Lite file), device class, browser
and OS family, language. Per event: path, CTA name, outbound host, scroll milestone, section
id, form and field **name**. No IP address, no user agent, no form content.

Raw rows are kept 90 days (configurable), then folded into anonymous day totals.

## Marking up a site

```html
<a href="/kontakt" data-track="hero-primary">Projekt anfragen</a>
<form data-track-form="contact">…</form>
<section id="preise">…</section>
```

```astro
<script>
  import { startAnalytics } from "@tracht-digital-solutions/tds-shared/analytics";
  startAnalytics({ site: "landing", lang: document.documentElement.lang || "de" });
</script>
```

The site's `ConsentBanner` must list `"analytics"` in `categories`, and the privacy policy
must describe the measurement.

## Develop

```bash
npm install --no-package-lock && npm run type-check && npm run test:run
composer install && composer test
```

Release with the manual `release.yml` button (bumps `package.json` and `composer.json`
together, tags, publishes).
