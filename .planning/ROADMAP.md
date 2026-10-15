# StockNest implementation roadmap

**Planning date:** 2026-10-15 (Asia/Manila). This is a delivery plan, not a statement that all phases are complete. Current implementation, runtime limitations and executed test evidence belong in the root README and delivery handoff. Each phase is accepted only when its integrated behavior and meaningful tests exist; a navigation entry or empty controller is not completion.

## Phase 1 — Requirements and architecture

Deliver business process/assumption analysis, nine-role permission/scoping matrix, modules/dependencies, architecture ADR, proposed normalized schema/ERDs, folder layout, roadmap, acceptance traceability and operating guide drafts. Record GAA/APPCSE as confirmed funds and PHP/Asia-Manila as configurable defaults.

Completion evidence: document review for contradictions; agree that projection/request/PO/receipt/issuance/expenditure/count are distinct; record outstanding approval/accounting decisions. Documents under `docs/` constitute the proposed design baseline and can evolve through reviewed decisions.

## Phase 2 — Project foundation

Create Laravel 13/PHP 8.4+ with official React 19/Inertia 3 starter where compatible; Tailwind 4/shadcn/ui layout; PostgreSQL 18 local configuration; Redis queue/cache; auth/session/2FA support; Spatie permissions and nine role seeds; fund/department scopes; private attachments; shared approval engine, idempotency and audit infrastructure. Add fiscal years, GAA/APPCSE master seeds, controlled admin provisioning, Docker development configuration, and installation commands.

Completion evidence: clean environment can install/boot/migrate/seed; login/logout/reset and inactive-user rejection verified; access denial by permission/fund/department verified; navigation is responsive/accessibly labeled; secrets are not committed; baseline backend/frontend checks run and limitations recorded. A logged-in dashboard can initially display honest empty states.

## Phase 3 — Core budgeting

Implement catalog/categories/UOM, funding sources, fiscal years, draft/imported projections, versioned submission/approval, independent allocation authorization, budget calculation, amendments, original/current comparison and scoped budget views. Add hard/soft/override controls and the unplanned-item exception definition. Seed realistic demo planning data separately from production seeds.

Completion evidence: required scenarios 1 and 4; projection approval never invents stock/spend/allocation; immutable original remains accessible; other-fund/department reads denied; version/amount calculations and invalid state transitions verified. Migration and seed behavior are idempotent where designed.

## Phase 4 — Monthly operations

Implement multi-line monthly requests, ordered approvals/returns/partial authorization, approved changes, procurement requisitions, suppliers, PO versioning/approval and atomic commitments, partial goods receipts, explicit expense settlement, stock ledger/balances/lots, reservations, partial issuance, returns and same-fund transfers. Add cancellation/reversal and private supporting documents. Drive all transitions through actual transactional actions.

Completion evidence: scenarios 2, 3, 5, 6, 8 and 9; approval-only operations leave stock/spend unchanged; partial settlement never double counts committed/actual amounts; cancellation releases only unused obligation; same-fund ownership enforced; retries and competing issuances verified against real PostgreSQL connections.

## Phase 5 — Monthly physical inventory

Implement location/ownership-scoped count sessions and assignments, snapshot cutoff, printable sheets, draft encoding/import previews, recount observations, reviewer comments, discrepancy approval, linked adjustment reconciliation, session closure and monthly due/overdue queries. Preserve transactions after cutoff; prevent duplicate reconciliation and unauthorized retrospective changes.

Completion evidence: scenario 7 plus post-cutoff movement example (50 → count 47 → issue 5 → adjust −3 → 42), positive variance, zero-system discovered stock, recount history, concurrent posting, and insufficient-current-stock blocking verified. Counter cannot approve own discrepancy.

## Phase 6 — Reporting and administration

Implement role-scoped database dashboards and all nineteen report types with appropriate CSV/XLSX/PDF exports; Recharts analytics, generation metadata and private download authorization; in-app notifications/preferences and optional email; approval center; complete immutable audit history, settings/configuration and fiscal closing/reopening with controlled stock continuity. Clarify demo environment labeling.

Completion evidence: scenario 10; report totals reconcile to ledger and authoritative version selections; report filters never expand user scope; queued export honors revoked access; overdue count/low-stock notifications reflect real data; closing rejects backdated activity and unused budget does not automatically carry.

## Phase 7 — Testing and deployment

Complete integrated Pest, Vitest and Playwright suites, authorization/attachment/export tests, PostgreSQL concurrency tests, upload/import limits, production configuration, queue/scheduler monitoring, secure setup, database/private-file backups and tested recovery. Finalize admin/end-user docs to actual screens and perform business-owner acceptance with production-like data.

Completion evidence: clean deployment from lockfiles, all required scenarios executed, no unresolved critical integrity/authorization defects, dependency compatibility confirmed, representative load checks, successful backup restore/reconciliation drill, permission review and agreed recovery objectives. Document exact test commands, results, environment and any outstanding limitation. This gate is required before production readiness can be claimed.

## Cross-phase quality rules

- Never replace missing domain logic with mock dashboards, generated numbers, or inert action buttons.
- Every phase includes its own schema, backend policy/validation/action, usable page, audit, and meaningful tests. Shared infrastructure begins early and evolves deliberately.
- Maintain `docs/acceptance.md` traceability; update user guides as behavior becomes implemented.
- Do not silently relax separation of duties, fund ownership, historical immutability, period locks or negative-stock constraints to make a demo pass.
- Package compatibility, executable runtime availability and third-party PDF/import support must be reported honestly; requested versions in a file do not prove an installed working application.
