# Requirements and business processes

**Status:** Phase 1 design. This document describes required behavior and configurable defaults, not a claim that every module is implemented.

## Business interpretation

An **annual projection** estimates the year's needs. An approved version establishes the historical planning baseline. A later approved amendment creates a new current version without replacing that baseline. An **authorized allocation** is a separate permission to spend; approving a projection does not create allocation unless an explicit configured rule invokes a separately audited allocation action.

An **item request** is a department's request to receive goods. Approval authorizes a maximum quantity and fulfillment purpose. A **procurement requisition** identifies an approved request's unmet need for purchasing. A **purchase order** authorizes an order and reserves budget when approved. A **goods receipt** records actual accepted delivery and increases inventory. An **issuance** records actual release to a recipient and decreases inventory. An **expenditure** records the explicitly approved spending event. These are distinct records with separate permissions and lifecycle states.

Approving a projection, item request, or purchase order never posts inventory. A goods receipt never silently marks an invoice as paid. A count records observations; only an approved adjustment corrects the ledger.

## Confirmed requirements and configurable assumptions

| Decision | Initial rule | Confirmation or configuration needed |
|---|---|---|
| Product name | StockNest | Organization display name is configurable and remains unspecified |
| Funding sources | GAA and APPCSE, separate records | Do not expand acronyms or infer legal accounting rules |
| Currency/timezone | PHP; Asia/Manila | Organization-level formatting and timezone configuration; one base currency initially |
| Fiscal year | Calendar year, explicitly opened/closed | Confirm actual fiscal calendar before opening production year |
| Self approval | Denied by default for every approval stage | Any exception needs an explicitly authorized, scoped, effective-dated policy, reason, and audit entry |
| Review chain | Department Head then Fund Approver for monthly requests | Authority amounts, delegates, quorum, substitute approvers, and exception routes must be configured |
| Projection authorization | Approval establishes plan only | A separate authorized allocation is required by default |
| Commitment timing | Approved purchase order creates budget commitment | Request estimates are informational; they do not reserve budget |
| Actual expenditure | Explicit posted expenditure, normally the recognized paid amount | Confirm organization's recognition policy before production; receipt and PO approval alone never imply spend |
| Budget control | Hard limit by fund/fiscal year by default | Soft warning, authorized override, and exceptional route are configurable and audited |
| Unplanned item | Allowed through explicit exception approval | Reason required; approved projection is never silently expanded |
| Ownership | Each inventory balance belongs to one fund | Issue only stock owned by the request fund; same location may contain both funds |
| Fund transfer | Not supported initially | Add an explicit approved transfer process before allowing reattribution |
| Quantity precision | Four decimal places | Units requiring integers get an additional whole-number validation rule |
| Monetary precision | Four decimal places in storage; two for displayed PHP totals | Agree rounding, taxes, discounts, freight, and invoice allocation rules before procurement rollout |
| Reservations | Reserve approved requests explicitly | Reservations reduce availability, not on-hand; reservation expiry/release rules configurable |
| Physical count | Monthly snapshot by location, item, and fund ownership | Cutoff is recorded; counting instructions reconcile movements during observation |
| Costing | Proposed moving weighted-average per item/fund/location | Confirm accounting valuation method before inventory valuation is released; no retroactive silent revaluation |
| Approval changes | Snapshot workflow and authority rules on submission | New configuration applies to new submissions; existing runs need explicit audited migration |
| Fiscal carry-forward | Carry stock only with reviewed opening/closing reconciliation | Unused budget does not carry forward automatically |
| Two-factor authentication | Available, strongly recommended for elevated roles | Decide mandatory roles and recovery policy before production |
| Retention | No ordinary user deletion of posted/audited records | Define legal retention duration, archival policy, and recovery objectives with business owner |

The initial defaults are visible configuration, not irreversible business policy. Before live use, the organization must confirm its real approval authorities, fiscal calendar, allocation amounts, financial recognition policy, and opening stock ownership.

## Transaction invariants

1. Approved historical versions and decisions are immutable. Correct errors by a linked revision, reversal, or authorized adjustment with a reason.
2. Every monetary posting has one funding source and fiscal year. Every physical stock position has one fund owner, item, location, and optional lot identity.
3. Projection approval creates a baseline only. Request approval changes approved quantities only. PO approval posts a commitment only. Receipt, issuance, return, transfer, and approved adjustment are the only stock-posting sources.
4. Remaining budget equals **authorized allocation + posted allocation adjustments − outstanding commitments − posted actual expenditure**. Current projection totals are displayed alongside this calculation, never substituted for allocation.
5. Outstanding commitment equals **approved PO commitment + approved increases − explicit releases − amount settled into expenditure**. Settlement releases the matching commitment and posts expense in one transaction. A linked expenditure cannot settle more than the matching line's outstanding amount. Any price overrun requires a separately authorized increase/override.
6. Partial payments or recognized expenditures settle the attributed portion of the PO line, not the whole order. For quantity-based allocation, settle original line commitment proportionally to the recognized quantity; for amount-based recognition, settle its explicitly allocated amount. Record the basis, allocation, rounding residue, and remaining amount. Final settlement releases the documented residual. A delivery by itself leaves commitment outstanding unless an explicit configured accounting action posts an expenditure.
7. On-hand equals the signed sum of stock ledger entries. Available equals on-hand minus active reservations. A balance table is a locked projection of the ledger and can be rebuilt.
8. A stock confirmation locks affected balance and reservation rows in deterministic order, checks availability, posts balanced entries where applicable, updates balances, fulfills request quantities, and records audit/idempotency in one database transaction. Default negative balance is prohibited.
9. A request line cannot be issued above its approved quantity less net prior fulfillment; a receipt line cannot exceed approved unreceived PO quantities without an authorized amendment.
10. The same idempotency key and operation payload returns its first completed result. Reusing a key with another payload is rejected. Unique source-posting constraints prevent a second ledger posting even after a retry.
11. Permissions combine action permission, department scope, fund scope, approval authority, document state, and effective dates. Server checks protect every object, export, attachment, and dashboard query.
12. Closed years reject ordinary backdated postings. Reopening or period corrections require explicit authority and an audit reason. Inventory crossing year-end retains ownership and traceable continuity; opening entries never duplicate prior balances.

## Workflows

### Annual planning and allocation

Prepare one projection per fiscal year/fund/department → draft lines and optional monthly breakdown → submit immutable candidate version → review → approve/reject/return. Approval preserves original baseline. Approved amendment produces another version and links its changed lines and reason. Budget Officer separately proposes allocation/adjustment and authorized approver posts it. Imports first validate into a preview and create no approved baseline directly.

### Monthly requisition and fulfillment

Draft request → submit once → snapshot ordered approval steps → Department Head review → fund authority review → exception/override step when unplanned or over limit → approved quantities → pending/partial fulfillment → fulfilled → controlled close. Rejection and return require comments. A returned request is revised and resubmitted with a new workflow run. An approved request changes only through an approved change request, preserving previous authorization and outstanding commitments/fulfillment checks.

Use existing owned stock when available, or create procurement requisition for the unmet portion. Reservations do not deduct stock. Pick/release may prepare issuance but stock changes only on explicit confirmation with recipient and issuer details. Return unused goods through a linked return transaction and reduce net fulfilled quantity according to the configured return policy.

### Procurement and spending

Approved unmet requirement → procurement review → supplier selection → draft PO → approval and atomic budget check/commitment → ordered → partial/full accepted receipt → explicit expenditure recognition and proportional commitment settlement → close when outstanding receipt, liability/commitment, and return conditions are resolved. Cancellation releases only unused commitment through a posted release. Returns to supplier are separate negative stock movements and any financial reversal is separately linked; do not assume a returned item is already refunded.

### Physical inventory and reconciliation

Create monthly location session → record transaction cutoff and immutable balance snapshot → assign counters → print sheet/encode/import → document count time, physical quantity, and variance reason → recount if needed → submit → independent review/approval → reconcile through linked authorized adjustment → close.

For each item/fund/location/lot position, variance is **cutoff physical quantity − cutoff system quantity**. If physical observation occurs after cutoff, record and reconcile every movement between cutoff and observation so the encoded quantity refers to cutoff. Freeze or coordinate movement through the counting zone when this cannot be established reliably. Do not guess ownership when identical stock from two funds is mixed: identify labeled ownership or route unresolved attribution to review.

Reconciliation applies the approved variance to the **current** balance under lock, preserving legitimate subsequent movements. Example: snapshot 50; count 47 → variance −3; later issuance −5 → current 45; approved adjustment −3 → resulting 42. It must not set current balance to 47. If a negative variance would make current stock negative or invalidate active reservations, block posting and require resolution/recount; approval alone does not bypass stock constraints. Every count line can be reconciled once, and a reversal links the original adjustment.

### Year close

Review open orders, commitments, requests, counts, receipts, expenditures, and stock valuation → resolve or explicitly carry eligible documents → reconcile ledgers and balances → approve closing snapshot → close fiscal year. Carry inventory using one controlled continuity/opening method. Opening a new fiscal year does not imply a new spending allocation or automatic budget carry-forward.

## Module dependencies

| Module | Required scope | Dependencies |
|---|---|---|
| A Authentication/access | Users, roles, scopes, sessions, optional 2FA, login activity | Foundation |
| B Funding sources | Configurable GAA/APPCSE, approver assignment, accounting references | A, O |
| C Annual projections | Versioned fund/department plans, imports, approvals, comparisons | A, B, E, O, M, N |
| D Budget/amendments | Separate allocation/plan/commitment/spend, amendments, enforcement | B, C, M, N |
| E Catalog | Shared items, categories, UOM, supplier/location references, import/export | A, O |
| F Monthly requests | Multi-line requisitions, approval chains, approved changes, unplanned routes | A–E, M, N |
| G Procurement | Procurement requisitions, suppliers, PO approval, partial receipts, spend | D, E, F, M, N |
| H Inventory | Ledger, owned balances/lots, receipt/return/transfer/adjustment, reservations | B, E, G, N |
| I Issuance | Approved queue, reserve/pick/confirm, recipient acknowledgment, returns | F, H, N |
| J Physical counts | Cutoff snapshot, sheets, recounts, discrepancy approval, adjustment | H, M, N |
| K Analytics | Role-scoped operational and financial dashboards | D, F–J, N |
| L Reports | Nineteen reports, scoped filters, CSV/XLSX/PDF generation | C–K, N |
| M Approval/notifications | Versioned workflows, ordered decisions, in-app/email, action inbox | A, O; shared infrastructure begins in Phase 2 |
| N Audit | Append-only actor/object/before/after/reason history and archives | A; shared infrastructure begins in Phase 2 |
| O Administration | Organization, fiscal years, scopes, settings, closing, thresholds | A, B; grows with each module |

Shared approval and audit capabilities are built early to avoid retrofitting uncontrolled approvals into later phases. Modules remain in one application and database.
