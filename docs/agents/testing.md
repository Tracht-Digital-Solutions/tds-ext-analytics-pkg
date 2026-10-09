# Testing

```bash
npm run test:run   # vitest; islands opt into jsdom per file via a @vitest-environment docblock
composer test      # phpunit
```

CI (`_build.yml`) runs both suites, plus type-check, `lint:primitives` and build, on
every run.

## Test environment against the current tds-shared

tds-shared is a **peer** dependency, so a fresh standalone install may resolve an old
version while every product build composes the current one. Keep these in mind:

- `apiFetch` first consults the host runtime config (`/tds-runtime.json`), so
  `fetch.mock.calls[0]` is that probe. Call **`primeRuntimeConfig(null)`** in
  `beforeEach`; panel products never ship that file, so "absent" matches production.
- `apiFetch` is **async**. `await waitFor(() => expect(fetch).toHaveBeenCalled())`
  before reading `mock.calls`.
- A multipart upload carries an **empty** `headers` object. Assert "no content-type
  header", never "headers is undefined".
- Error-path tests answer with a **populated** body and a non-OK status. Against an
  empty error body the ok-check is unobservable.
- At least one assertion per suite pins the **absolute API host** (see
  [frontend-conventions.md](frontend-conventions.md#api-calls-apifetch-never-a-relative-fetch)).

## Suites

| Suite | Covers |
|---|---|
| `src/index.test.ts` + `tests/packaging.test.ts` | Manifest as a product build sees it; specifiers resolve, are exported and ship |
| `islands/Dashboard.test.tsx` | Absolute API host, KPIs, in-flow error, site filter, form funnel, widget "–" on failure |
| `php/tests/SupportTest.php` | Payload allow-list (no e-mail in UTM, no query in paths), channels, UA families, hosts, Berlin days, GeoIp without a file |
| `php/tests/AnalyticsModuleTest.php` | Gating of every report, 400/403 on the beacon, bot and GPC drops, forget never claims success without a DB |
| `php/tests/AnalyticsApiDocsTest.php` | Documented = mounted routes; only the two browser routes are public |
| `php/tests/AnalyticsDatabaseTest.php` | **Needs `TDS_TEST_DB_DSN`.** Schema from the real migrations (Phinx). No IP/UA in any row, foreign session ids dropped, every report, roll-up continuity and idempotence, forget, rate limit |

```bash
TDS_TEST_DB_DSN="mysql:host=127.0.0.1;dbname=tds_ext_test;charset=utf8mb4" TDS_TEST_DB_USER=root composer test
```
