# Authorization and separation of duties

**Status:** Proposed default policy. The nine roles are configurable permission bundles, not sufficient authorization by themselves.

An action is allowed only if the account is active, the server-side permission is present, document state permits it, department and fund scopes cover the record, applicable approval authority is effective and within its amount/quantity limit, and separation-of-duties rules pass. Deny by default. Shared catalog data may be organization-wide; transaction details remain scoped.

## Default role matrix

Legend: **M** manage; **P** prepare/submit; **A** decide only an assigned approval step; **X** execute a posted transaction; **R** read/export within assigned scope; **—** denied. All entries remain subject to scope and authority. Preparation and approval by the same person are denied by default even when one person holds several roles.

| Capability | Super Admin | System Admin | Budget Officer | Dept Requester | Dept Head | Fund Approver | Procurement Officer | Inventory Custodian | Auditor |
|---|---|---|---|---|---|---|---|---|---|
| Organization, system configuration | M | M | R | — | R | R | R | R | R |
| Users, account status, role assignment | M | M¹ | — | — | — | — | — | — | R |
| Permission definitions / privileged grants | M¹ | — | — | — | — | — | — | — | R |
| Department/fund access assignment | M¹ | M¹ | R | — | R | R | R | R | R |
| Fund / fiscal-year administration | M | M | P | — | R | R | R | R | R |
| Fiscal close/reopen execution | X² | X² | P | — | R | A² | R | R | R |
| Item, UOM, category, supplier catalog | M | M | R | R | R | R | M | M | R |
| Projection preparation / amendments | R | R | P | P³ | P³ | R | R | R | R |
| Projection / amendment approval | — | — | — | — | A³ | A | — | — | R |
| Allocation / budget adjustment proposal | R | R | P | — | R | R | — | — | R |
| Allocation / adjustment authorization | — | — | — | — | — | A | — | — | R |
| Monthly request preparation | R | R | R | P³ | P³ | R | R | R | R |
| Request approval / partial authorization | — | — | — | — | A³ | A | — | — | R |
| Unplanned / over-budget exception | — | — | P | P³ | A³ | A² | P | — | R |
| Procurement requisition / PO preparation | R | R | R | — | R | R | P | R | R |
| PO approval / commitment authority | — | — | R | — | — | A | — | — | R |
| Expenditure preparation | R | R | P | — | — | R | P⁴ | R | R |
| Expenditure approval / posting | — | — | X⁴ | — | — | A⁴ | — | — | R |
| Receipt / reservation / issuance / return | R | R | R | R³ | R³ | R | R | X | R |
| Same-fund location transfer | R | R | R | — | — | R | — | P/X⁵ | R |
| Physical count creation / encoding | R | R | R | — | — | R | — | P⁵ | R |
| Count discrepancy / adjustment approval | — | — | R | — | — | A⁵ | — | — | R |
| Approved adjustment execution | — | — | R | — | — | R | — | X⁵ | R |
| Reports, dashboards, attachments | R | R | R | R³ | R³ | R | R | R | R |
| Audit access / approved archive jobs | M⁶ | R | R | — | R³ | R | R | R | R |

1. Administrative access is not financial approval authority. Privilege/scoping changes must be audited, and an administrator cannot give themselves an exception by ordinary configuration. Require a distinct authorized administrator for self-approval exceptions and privileged elevation; record reason, effective dates, scope, and review. Built-in super administration is not an implicit transaction-policy bypass.
2. Fiscal reopening and budget overrides require explicit extra permissions and assigned authority, not merely the role. Require a reason and independent authorization. Never bypass ledger integrity or permit negative stock via an override.
3. Department scope: requester sees own records by default; Department Head sees assigned departments. Cross-department access is an explicit grant. A requester must also possess the requested fund scope.
4. Initial role allocation is an implementation choice requiring organization confirmation. Posting may be performed by the Budget Officer after independent Fund Approver approval. The preparer may not approve their own expenditure; a procurement officer may prepare supporting financial information but cannot mark payment by ordinary procurement permission.
5. Counting and inventory execution are assigned duties. A counter cannot approve the same discrepancy. An issuer cannot approve their own adjustment. A transfer has explicit source/destination scopes and confirmation authority. A user may execute an already independently authorized adjustment only if separately allowed by its workflow.
6. Audit events are append-only and cannot be edited through administrative CRUD. Archive jobs move validated retention-eligible partitions to protected storage under approved retention policy; they never provide ad hoc erasure of individual transactions.

## Scope model

- `user_department_scopes`: department plus capability scope, effective dates, granting actor/reason. Requester ownership is enforced in addition to the department assignment.
- `user_funding_source_scopes`: funding source plus allowed visibility/operations and effective dates.
- `approval_authorities`: user or role, document type, department, fund, fiscal year/period, maximum amount, sequence/assignment rules, delegation and effective dates. Multiple role membership never merges amount authority without an explicit rule.
- `approval_runs`: frozen workflow version, document revision and amount basis, submitter, and required steps. Decisions refer to exact run/step; stale revisions invalidate pending decisions and require reapproval.
- Public document IDs or unguessable UUIDs do not replace authorization. Every query constrains visible records; every command loads and authorizes the record. Exports and queued jobs re-check requester scope at execution and download.

## Suggested permission vocabulary

Use stable action identifiers such as `requests.view`, `requests.create`, `requests.submit`, `requests.approve`, `requests.cancel`, `requests.change-approved`, `projections.approve`, `budget.allocate`, `budget.override`, `procurement.orders.approve`, `expenditures.post`, `inventory.receive`, `inventory.issue`, `inventory.reserve`, `inventory.transfer`, `counts.encode`, `counts.review`, `counts.reconcile`, `adjustments.approve`, `reports.export`, `audit.view`, and `fiscal-years.reopen`.

Policies combine these Spatie permissions with object-specific scope and workflow conditions. Do not make policy decisions in React, infer approval from role name strings, expose hidden records through search/dropdown results, or give administrators a blanket `before()` approval return.

## Authorization verification requirements

Test each allowed operation and a corresponding denied case: inactive account; missing permission; another department; other fund; same requester/approver; unassigned approval step; stale workflow revision; exceeded authority; closed fiscal year; direct URL; attachment/export download; queued report after access revocation. Verify permission changes invalidate cached grants and sessions where required.
