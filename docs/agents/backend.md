# Backend

The PHP half is a `Module` (`php/src/AnalyticsModule.php`, extends `AbstractModule`) that the
composed `tds-core-frontend-api` mounts in one process with every other extension.

## Migrations (Phinx)

This module owns the **`20261009*`** band and the `analytics_` table and class prefix.

All composed extensions share **one** `phinxlog`, so:

- The migration **class name** and the **numeric version prefix** must be unique across
  every extension. Put the module id in the class name.
- The **file name must map to the class** (`<version>_<snake_case_class>.php`). A
  mismatch throws `Could not find class …` during the scan and aborts **every**
  extension's migrations, not just this one.
- Migrations must survive MySQL 8 (production): no nullable primary-key columns, and
  unsigned ids where the other side is unsigned.

## API reference (`php/docs/api.php`)

The module implements the contract's optional `ApiDocSource`. `php/docs/api.php` returns
one entry per route (summary, params, responses, required permission). The admin
frontend's API reference joins it onto the introspected Slim routes by
`"<METHOD> <pattern>"`.

- **`pattern` must be the Slim pattern verbatim**, inline regex included. A prettified
  path silently produces an orphan doc and an undocumented route.
- **`php/tests/AnalyticsApiDocsTest.php` asserts both directions:** documented and registered
  routes are the same set, every path placeholder is described, and a named permission
  exists in `permissions()`. Adding or renaming a route without touching `docs/api.php`
  fails there.

## Contract dependency

The published `tds-frontend-contract` is consumed VCS-only on the Composer side
(`type: vcs`) and from GitHub Packages on the npm side. Never use a `path` repository;
Composer fails on a missing path repo in CI.

## Public routes

`POST /analytics/collect` and `POST /analytics/forget` are unauthenticated browser calls.
They must never be listed in a `SiteKeyProtected` prefix. `collect` answers 204 for every
policy drop and for a database failure (a beacon must not produce console errors on a
public site); `forget` answers 503 on failure, because the visitor waits for the answer and
must not be told their data is gone when it is not.

## Time

Timestamps are UTC via `gmdate()`; `day` columns are the Europe/Berlin calendar day computed
in PHP (`Support/Clock`). Never `NOW()` / `CURDATE()` in SQL.
