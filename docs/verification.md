# Verification record — October 16, 2026

## Environment

Windows, PHP 8.4.13, PostgreSQL 18.6, locked Composer/npm dependencies. Authoritative PostgreSQL tests use a separate `stocknest_test` database. Demonstration browser fixtures use a separate local `stocknest` database with explicit demo labeling.

## Executed checks

| Check | Evidence |
|---|---|
| PostgreSQL migrations and production reference seeds | All nine migrations ran successfully. GAA/APPCSE references and nine permission roles seeded without creating users. |
| PostgreSQL backend suite, final integrated run | 75 tests passed, 460 assertions, no failures/errors/skips. Includes both independent-process stock races, reference setup, count-only access, audit triggers and workflow form context. |
| TypeScript | `npm run types:check` passed. |
| Production frontend assets | `npm run build` passed. |
| Frontend interaction tests | 13 tests passed, including explicit action context, decimal values, validation/retry keys and multi-line form behavior. |
| PHP formatting | Laravel Pint completed and formatted source. |
| Installation initializer | 3 Node tests passed: fresh credentials, preservation of existing keys and correct insertion of missing settings. |
| Browser checks | 2 Chromium tests passed: login/public signup control and a real request through department/fund approval, with unchanged stock CSV before/after. Four independent authenticated browser contexts were used. |

The two concurrency cases release independent PHP processes at the same barrier: competing requests for the last ten units yield one issuance; simultaneous retries of the same command yield one ledger effect. SQLite results are not used to claim these properties.

The first PostgreSQL setup exposed a stale permission cache when DatabaseSeeder suppresses model events. Role setup now explicitly clears that cache after creating permissions. A regression test also confirms reseeding preserves administrator-customized grants.

The local harness initially hit startup/response-time limits during Windows file copying. Final tests ran after that copy, with explicit worker-readiness waits. Browser execution also exposed dropped non-visible workflow values; forms now send only explicitly declared record/decision context, with unrelated model defaults excluded. Regression tests cover this contract.

## Not yet certified

Docker image/container startup, production deployment, restore drill, complete nineteen-report and full-master-prompt acceptance, load testing and organizational UAT. See [implementation status](implementation-status.md) for the remaining required feature scope.

No external deployment, push, publication or messages were performed. Demo passwords, application keys, native database files, local logs, browser traces and development tools are excluded from source archives.
