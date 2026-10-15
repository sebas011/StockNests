# Deployment, backup and recovery

**Status:** Operational guidance to validate before production. Development Compose is a practical local environment, not a production-readiness certificate. Final release requires actual acceptance/security/concurrency results and a successful restore drill.

## Production deployment contract

Deploy a versioned application image built from reviewed lockfiles. Run Laravel/PHP web workers, PostgreSQL 18 and Redis with a monitored queue worker and scheduler. Serve through HTTPS with a correct trusted-proxy configuration. Keep PostgreSQL/Redis private; use authenticated mail delivery rather than development Mailpit. Persist database and private uploaded/export files outside ephemeral app containers.

Configure `APP_ENV=production`, `APP_DEBUG=false`, HTTPS `APP_URL`, secure session cookies and approved session lifetime, secrets, least-privilege database credentials, Redis authentication/network restrictions, mail sender/provider, queue retry/timeouts, storage paths, timezone and currency. Exclude `.env`, database backups, access tokens and private attachments from Git and public web roots.

Disable public registration unless explicitly needed and controlled. Provision named users and the first administrator with a unique interactive secret. Enable 2FA according to the organization's policy. Remove or disable demo accounts/data before live use; do not run DemoSeeder in production.

## Release procedure

1. Record the release revision, dependency lockfile hashes, migration list and required configuration changes. Confirm tests, ownership/fund scopes, fiscal rules and real approval authorities.
2. Produce/verify a current database and private-file backup; verify recovery access to the existing `APP_KEY` and other encryption keys. Rehearse any irreversible data migration on a restored copy.
3. Build frontend assets and production PHP dependencies in the image. Run dependency vulnerability/compatibility checks; use the delivered package lockfiles.
4. Drain or pause workers and scheduled financial/stock jobs as needed. Use a maintenance window for schema/data changes that are not backward-compatible. Protect in-flight transactions from mixed release behavior.
5. Run migrations with an explicitly approved environment, then production seeds only when intended and reviewed. Never run `migrate:fresh`, demo seeds, or key regeneration on a deployed database.
6. Refresh Laravel configuration/routes/views as appropriate, restart long-running workers so they load the new code, and verify scheduler singleton/lock behavior. Retain previous image/configuration for rollback.
7. Check health: database and Redis connection; login/authorization; scoped empty or sample-safe reads; pending queue/failed jobs; private file access; ledger/balance reconciliation; no unintended migrations or demo data.
8. Resume jobs and traffic. Monitor error rate, queue age/failures, database lock waits, storage, backup alerts and financial/stock reconciliation diagnostics. Record actual checks/results.

Rollback must account for schema compatibility. Prefer rolling back to a compatible prior image; do not blindly execute migration down methods on posted financial/stock data. If a data restore is required, stop writes, obtain business authorization for the point-in-time loss, restore database/files consistently, reconcile, and document transactions needing re-entry. Preserve original incident evidence.

## Backup scope and ownership

The named operator owns backup monitoring and recovery access; the business owner confirms retention and recovery objectives. Until those are agreed, treat a daily encrypted backup plus more frequent database protection as a proposed starting point, not an accepted recovery guarantee. Set documented RPO (maximum acceptable lost transactions) and RTO (maximum acceptable downtime); verify them through drills.

Back up:

- PostgreSQL schema/data with compatible PostgreSQL 18 tooling. Consider WAL archiving and point-in-time recovery when daily transaction loss is unacceptable.
- Private uploaded attachments, generated documents needed for retention and file metadata. Database/files must correspond to a consistent recovery point.
- `APP_KEY`, relevant encryption keys, required deployment configuration and backup decryption credentials in a separately protected recovery vault.
- Release image/revision and lockfiles to reproduce the restored application.

Encrypt backups, limit read/delete access, retain off-host copies, and alert on missing/failed/undersized backups. Use immutable/retention-protected copies where available. Test integrity and readability; a successful upload message does not prove a usable backup. Retention and audit archival policy require the organization's approval.

## Example local backup

Run from the project folder with Compose services running. Use actual configured database credentials; `stocknest` is an example database/user. Create the host backup directory first. Do not pipe binary PostgreSQL dumps through older PowerShell redirection, which can alter binary output.

```powershell
$stockNestBackupPath = Join-Path $env:USERPROFILE 'StockNestBackups'
New-Item -ItemType Directory -Force -Path $stockNestBackupPath
docker compose exec -T db pg_dump -U stocknest -d stocknest --format=custom --file=/tmp/stocknest.dump
docker compose cp db:/tmp/stocknest.dump "$stockNestBackupPath\stocknest.dump"
docker compose exec -T app tar -czf /tmp/stocknest-private.tar.gz -C /var/www/html storage/app/private
docker compose cp app:/tmp/stocknest-private.tar.gz "$stockNestBackupPath\stocknest-private.tar.gz"
```

Adjust the app working directory to the actual image path. These commands demonstrate transport, not a complete encryption/retention system. Protect local backups and avoid placing them inside a public or synced source directory. For a consistent file/database point, pause writes or use an application-aware snapshot mechanism; files created after the database dump must not be treated as committed database documents at that earlier point.

## Isolated restore drill

Never test recovery by overwriting the live database. Prepare a separate deployment/database/files directory with outbound mail and external integrations disabled, separate workers, and the matching application version/key.

Example using a new database inside a disposable local database container:

```powershell
$stockNestBackupPath = Join-Path $env:USERPROFILE 'StockNestBackups'
docker compose cp "$stockNestBackupPath\stocknest.dump" db:/tmp/stocknest-restore.dump
docker compose exec -T db createdb -U stocknest stocknest_restore
docker compose exec -T db pg_restore -U stocknest --dbname=stocknest_restore --exit-on-error /tmp/stocknest-restore.dump
```

An existing `stocknest_restore` database needs a separately approved reset or another unique isolated database name. This guide intentionally does not overwrite one automatically.

Restore private files into the isolated application's storage with correct permissions. Configure that application to use `stocknest_restore` and the matching original encryption key. Keep notifications/queues disconnected from real recipients until recovery validation completes.

Validate:

1. Expected users, scopes, funds, years, documents, approvals and attachments exist; no demo seed ran.
2. Stock balances equal ledger sums per owned position; active reservations do not exceed balances. Verify sample receipt/issuance/count adjustment provenance.
3. Authorized, committed, actual and available budget reconcile by fund/year; partial settlements and reversals remain linked and balanced.
4. Count snapshots, approved revisions, original baselines, audit entries, closed-period protections and private downloads work.
5. Application checks pass against the restored database; observed backup age and elapsed recovery satisfy agreed RPO/RTO.

Record backup identifier, release, timestamp/timezone, operator, duration, validation evidence and remediation. Repeat after significant schema/storage/encryption changes and at the organization's chosen drill interval.

## Ongoing operation

Monitor failed jobs and outbox backlog, long-running exports/imports, scheduler health, lock timeouts/deadlocks, disk usage and database growth, expiring/failed backups, notification delivery and suspicious authentication. Provide diagnostics that compare materialized stock/budget totals to ledgers without silently repairing differences. Any repair must be explicitly reviewed and audited.

Application accounts cannot edit/delete posted ledgers or audit history. Archival is a controlled retention process preserving references and access restrictions; it is not routine record deletion.
