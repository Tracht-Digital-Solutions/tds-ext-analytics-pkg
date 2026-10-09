# AGENTS.md — tds-ext-analytics-pkg

**Besucher-Statistik**: the dashboard for the public sites' own, consent-gated audience
measurement — visits, sources, tracked clicks, scroll depth, exit pages and form drop-off.
The beacon lives in `tds-shared/analytics`; this repo contributes the PHP `Module` (collector,
reports, retention) to `tds-core-frontend-api` and the `/statistik` page, a widget and a
settings section to the admin product.

Read `tds-frontend-contract-pkg/AGENTS.md` and `tds-core-frontend-api/AGENTS.md` first.

## Commands

```bash
npm install --no-package-lock   # never npm ci
npm run type-check
npm run lint:primitives
npm run test:run                # vitest
npm run build                   # tsup
composer install && composer test   # phpunit; the DB suite needs TDS_TEST_DB_DSN
```

## Hard rules

- Nothing is measured without consent `analytics`; what is collected is what the consent text says.
- Never store an IP address, user agent, query string or form value. New fields go through `Payload`.
- Every number is a `Metrics` pair; reports and the roll-up share it. No report SQL beside it.
- `POST /analytics/collect` answers 204 on every policy drop and DB failure; `forget` never fakes success.
- Times are UTC `gmdate()`, days are Europe/Berlin from `Support/Clock`; never `NOW()` in SQL.
- A failed load is an in-flow alert, never an empty statistic. `apiFetch` only, never a relative `fetch`.
- Every route change updates `php/docs/api.php`. Migrations stay in the `20261009*` band.
- Stay in the `0.1.x` line (`tds-admin-frontend` pins `^0.1.x`). Versions move via the release workflow only.

## Topic files

| File | Read before |
|---|---|
| [docs/agents/architecture.md](docs/agents/architecture.md) | Changing what is collected, the metrics, retention or the site/host map |
| [docs/agents/backend.md](docs/agents/backend.md) | Touching PHP, migrations or `php/docs/api.php` |
| [docs/agents/frontend-conventions.md](docs/agents/frontend-conventions.md) | Touching any island, page or widget markup |
| [docs/agents/testing.md](docs/agents/testing.md) | Writing or changing tests |
| [docs/agents/release.md](docs/agents/release.md) | Releasing or changing CI |

Workspace rules: `../CLAUDE.md`. Cross-repo state: `../MIGRATION-STATUS.md`.
