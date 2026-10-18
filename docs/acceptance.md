# Acceptance scenarios and requirement traceability

**Status:** Acceptance specification. Scenarios are planned verification requirements until matched with executed tests and results. A row in this file is not a passing test.

## Required end-to-end scenarios

| ID                      | Setup and action                                                                                              | Expected evidence                                                                                                                                                                                            | Phase |
| ----------------------- | ------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ----- |
| S01 Annual planning     | Same fiscal year, different GAA and APPCSE item/quantity/price lists; independent authorized users approve    | Two separately scoped approved baselines; correct exact totals; original version history; no inventory, spend or implicit allocation posting                                                                 | 3     |
| S02 Monthly request     | Request a projected item; submit and inspect before/after an authorized approval                              | Pending until required decisions complete; no requester's self approval; approved quantities recorded; stock and actual expenditure unchanged                                                                | 4     |
| S03 Unplanned purchase  | Request catalog item absent from relevant approved plan                                                       | Explicit unplanned indicator and reason; exception workflow required; ordinary approval cannot skip exception; baseline remains unchanged                                                                    | 4     |
| S04 Budget amendment    | Change 100 projected units to 70 or revise price through amendment                                            | Original approved 100-unit version remains accessible; approved new version shows 70; revision author/approval/reason; no retroactive edit of actual transactions                                            | 3     |
| S05 Partial receipt     | Approve PO for 100; receive 40 accepted units                                                                 | PO approval creates commitment only; accepted receipt posts exactly +40 owned units; 60 outstanding; receipt retry creates no duplicate stock; no automatic expenditure                                      | 4     |
| S06 Issuance            | Approved request for 20; prepare pick, reserve and confirm 12                                                 | Reservation/pick changes no on-hand; confirm creates −12 ledger movement and partial fulfillment; recipient/issuer recorded; eight remain; duplicate confirmation is harmless                                | 4     |
| S07 Count discrepancy   | Snapshot 50, count 47, submit/review/approve and reconcile                                                    | Variance −3; no stock effect before approved adjustment; one −3 ledger posting; immutable count/review/adjustment link; counter cannot approve same discrepancy                                              | 5     |
| S08 Fund separation     | Same item held/planned in GAA and APPCSE; procure with GAA                                                    | Only GAA commitment/spend/balance changes; APPCSE budget stays unchanged; GAA request cannot issue APPCSE-owned stock even at same location                                                                  | 4     |
| S09 Concurrent issuance | Two authorized users concurrently issue last unit, plus retried same issuance                                 | Exactly one competing issue succeeds; other returns clear conflict/insufficient-stock result; balance never negative; one posting per source; use separate PostgreSQL connections                            | 4, 7  |
| S10 Year end            | Build full-year baseline/revision/allocation/commitment/expenditure/movement data and generate annual reports | Totals reconcile by fund and period; original/current plans separate; outstanding commitment excludes settled amounts; scoped downloads; closing prevents unauthorized backdating and automatic budget carry | 6, 7  |

## Additional integrity acceptance

| ID                               | Verification                                                                                                                                                                                                                            |
| -------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| I01 Partial financial settlement | Authorized 100,000; PO commitment 20,000 → available 80,000. Post 5,000 linked expenditure with 5,000 settlement → commitment 15,000, spend 5,000, available still 80,000. Cancel unused 15,000 → available 95,000. No double counting. |
| I02 Price variance               | Approved PO commitment cannot be silently exceeded by invoice/expense; authorized increase or override required; explicit amount allocation/rounding retained.                                                                          |
| I03 Concurrent budget            | Two PO approvals compete for same remaining allocation; lock account; enforce configured hard limit atomically; override only with valid scoped authority/reason.                                                                       |
| I04 Post-cutoff count movement   | Snapshot 50, accepted cutoff count 47, later issue 5 → current 45; reconcile −3 → 42. Do not set to 47.                                                                                                                                 |
| I05 Count conflict               | Snapshot negative variance exceeds current available/current stock after later movement or conflicts with reservations; reconciliation blocks for controlled resolution; no forced negative stock.                                      |
| I06 Reversal                     | Reverse receipt/issuance/expense only through authorized linked event; preserve original; validate remaining returnable/settleable quantities and fiscal period; duplicate reversal rejected.                                           |
| I07 Reservation                  | Two reservations compete; only available quantity can reserve; expired/canceled/released remainder becomes available once; issue consumes reservation and on-hand together.                                                             |
| I08 Historical changes           | Item/UOM/supplier/workflow edits do not alter previously approved snapshots or decision identities. Approved request/order change creates new version and required reapproval.                                                          |
| I09 Replay                       | Same idempotency key/payload returns first result; different payload with same key is rejected; unique source posting prevents duplicates after key expiry.                                                                             |
| I10 Upload/import                | Invalid type/oversize/malicious filename rejected; files private; CSV/XLSX preview reports invalid rows without partial hidden postings; spreadsheet formula injection neutralized in exports.                                          |
| I11 Scope denial                 | Direct object URL, lookup, dashboard, attachment, export and queued report deny other department/fund. Revoking access blocks later downloads and generation.                                                                           |
| I12 Workflow race                | Duplicate decisions, stale revision decisions, out-of-order steps, inactive approver and expired delegation fail; returned revision starts a new run.                                                                                   |
| I13 Audit                        | Successful transition includes actor/reason/revision; rejected operations do not partially post; sensitive secrets absent; ordinary roles cannot alter/remove audit rows.                                                               |
| I14 Year continuity              | Closing/opening inventory reconciles to prior close exactly once; no accidental duplicate opening stock; unused allocation requires explicit carry-forward authorization.                                                               |
| I15 Recovery                     | Restore PostgreSQL + private files into isolated environment; compare movement/budget balances, sample documents, attachments and approvals; retain recovery proof and actual elapsed recovery time.                                    |
| I16 Accessibility                | Keyboard-only request/review/confirmation flow, visible focus, meaningful error summaries, readable status labels beyond color, accessible charts/tables, light/dark contrast and printable reports.                                    |

## Mandatory business-rule mapping

| Original rule                               | Planned enforcement                                      | Acceptance        |
| ------------------------------------------- | -------------------------------------------------------- | ----------------- |
| 1 Approved projection baseline              | Version state + independent ordered approval             | S01               |
| 2 Controlled amendments                     | Amendment action, new approved version                   | S04               |
| 3 Historical original                       | Immutable baseline pointer/version                       | S04, I08          |
| 4 Requests require approval                 | Submit/run/decide actions                                | S02               |
| 5 No skipped levels                         | Frozen ordered run steps                                 | S02, I12          |
| 6 Separation of duties                      | Actor/authority policy                                   | S02, S07, I11     |
| 7 Request approval no stock deduction       | Stock posting only in confirm issuance                   | S02, S06          |
| 8 PO approval no receipt                    | Commitment-only PO action                                | S05               |
| 9 Stock increases only authorized movement  | Positive ledger posting boundary                         | S05, I06          |
| 10 Stock decreases only authorized movement | Negative ledger posting boundary                         | S06, I06          |
| 11 Count approval before adjustment         | Approved adjustment and unique count posting             | S07, I04, I05     |
| 12 Fund on money                            | Required account/fund FK and scopes                      | S08               |
| 13 Valid numbers/no duplicates              | SQL checks, exact decimal, idempotency/source uniqueness | S09, I09          |
| 14 Configured budget control                | Locked account check + scoped override                   | I01–I03           |
| 15 Unplanned exception                      | Plan-link check and exception step                       | S03               |
| 16 Immutable history                        | Version/reversal architecture, audit                     | S04, I06, I08     |
| 17 Backend authorization                    | Policies, scoped queries and jobs                        | I11, I12          |
| 18 Closed year backdating                   | Fiscal-period guard under transaction                    | S10, I14          |
| 19 Transactions/concurrency                 | Row locks, unique posting, atomic actions                | S09, I03–I05, I09 |
| 20 Auditable significant changes            | Append-only audit in command transaction                 | I13               |

## Required report catalog

All reports apply viewer scope before user filters. Filters may narrow access, never expand it. Export metadata includes as-of/generation timestamp, timezone, fiscal/date scope, fund/department as relevant, document references and generating user. Artifacts stay private and expire under retention policy. CSV/XLSX text cells neutralize formula execution. PDF layout must be visually inspected at realistic row counts.

| #   | Report                          | Authoritative sources and useful filters                                                                |
| --- | ------------------------------- | ------------------------------------------------------------------------------------------------------- |
| 1   | Annual projected expense        | Approved projection version; year, fund, department, item/category                                      |
| 2   | Approved annual budget          | Authorized account ledger/allocation documents; year, fund, department limit if defined                 |
| 3   | Original vs revised projection  | Original/current approved versions and amendment links; year/fund/department                            |
| 4   | Budget utilization              | Authorized, outstanding committed and actual ledger buckets; year/fund/month                            |
| 5   | Monthly item requests           | Request revisions, approval and fulfillment; month/fund/department/status/requester                     |
| 6   | Pending approval                | Active runs/steps and effective authority; type/fund/department/age                                     |
| 7   | Procurement and purchase orders | PO revisions, request allocations, receipts and commitments; supplier/status/fund/date                  |
| 8   | Actual spending                 | Posted expenditures and linked reversals; recognition date/fund/supplier/category                       |
| 9   | Current stock                   | Position balances, item, fund ownership and lot; warehouse/location/category/as-of                      |
| 10  | Inventory movement ledger       | Posted signed entries and source documents; date/item/fund/location/type                                |
| 11  | Stock issuance                  | Confirmed issuances/net linked returns; department/recipient/item/fund/date                             |
| 12  | Monthly physical inventory      | Count sessions, snapshots and observations; month/location/fund/status                                  |
| 13  | Inventory discrepancy           | Approved/observed variance and linked adjustment state; month/location/item/reason                      |
| 14  | Low stock                       | Available/on-hand versus configured thresholds; item/category/location/fund                             |
| 15  | Supplier purchase history       | Approved orders, accepted receipts, spend and returns; supplier/date/item/fund                          |
| 16  | Item consumption                | Confirmed issue less accepted returns under declared consumption definition; item/department/month/fund |
| 17  | Fund-specific expenditure       | Posted actual expense by account; fund/year/date/category/department allocations                        |
| 18  | Annual comparative              | Consistent versions/allocations/spend/movements across selected years/funds                             |
| 19  | Audit trail                     | Scoped actor/action/target/change history; user/type/date/reference; redact secrets                     |

## Test layers and evidence

- Pest unit tests cover exact calculations and state/authority decisions. Feature tests exercise authenticated routes, validation, transactional actions, reports and denial cases using database state assertions.
- PostgreSQL integration tests exercise unique/check constraints and separate-connection simultaneous posting; SQLite cannot certify PostgreSQL locks or constraint behavior.
- Vitest checks meaningful reusable interactions, validation/error display, status/timeline behavior, and precision formatting. Avoid tests that merely duplicate implementation statements.
- Playwright verifies complete user journeys with real database-backed workflows and distinct users for separation of duties; assertions include backend financial/stock consequences, not only success toasts.
- Production readiness also requires restore drill, queued-job failure behavior, print/export inspection, scoped access review, and representative performance checks.

For each executed run record commit/revision, command, environment/runtime/database, count/results, relevant logs and any omitted scenario. Never infer a passed integration suite from successful source generation or type checking.
