For the current Office of the Vice President for Academic Affairs system, follow the [office workflow guide](../office-workflow.md). The institutional planning and approval procedures below are historical reference.

# Administrator guide

This guide describes the intended operating workflow. Use only actions actually present in the delivered release; consult the root README for implemented scope and verification. Configuration grants access and authority, so changes must be attributed, reasoned and auditable.

New accounts created by an administrator must replace their initial password before using StockNest. Share that initial password privately; the replacement must be different. Deactivate accounts through administration when access ends. Historical account records are retained.

For custom roles, review `budget.approve`, `fund.approve`, `requests.department-approve` and `fiscal.reopen` in the [implemented permission controls](../permissions.md). Request approval also needs `requests.approve`, and each stage needs a different reviewer within the current approval cycle. Fiscal reopening uses the explicit reopening action with a recorded reason.

## Before opening live operations

1. Set the organization's display name. StockNest is the application/default name; a legal organization name has not been supplied. Confirm PHP currency and Asia/Manila display timezone.
2. Confirm the two funding source records **GAA** and **APPCSE**. Preserve their identities; do not rename one to stand for another fund or infer acronym expansions. Enter accounting references if supplied.
3. Create departments, warehouses/locations, item categories and units. Confirm quantity precision/whole-unit rules and threshold defaults. Import catalog data through a validation preview.
4. Confirm the actual fiscal calendar, then open the applicable year. Load opening stock only through an approved opening transaction with fund ownership and supporting evidence. Do not enter a second opening balance on top of existing continuity stock.
5. Create named active users, assign minimal roles, department scopes and funding source scopes. Avoid shared administrator accounts. Configure password reset/mail, session policy and required 2FA.
6. Configure approval routes and effective-dated authority, including amount limits, substitutes, unplanned-item exceptions and budget overrides. Have a second authorized person review privileged grants. Published workflow changes apply to new submissions unless an explicit migration is approved.
7. Confirm projection approval does not automatically authorize expenditure. Enter separately approved fund/year allocations, budget enforcement mode and accounting recognition/rounding/costing rules.
8. Verify backups and isolated recovery. Agree retention, who monitors failures, recovery access, and fiscal close/reopen authority before real transactions begin.

## Default roles

The nine initial bundles are Super Administrator, System Administrator, Budget Officer, Department Requester, Department Head, Fund Approver, Procurement Officer, Inventory Custodian and Auditor/Read-Only User. See the [permission matrix](../permissions.md).

A role is not a universal scope. A Fund Approver assigned to GAA does not automatically see APPCSE documents. A department requester normally sees their own department-authorized submissions. System administration does not confer financial approval authority. Self approval is denied by default, including when one person holds several roles; a configured exception needs independently authorized scope, reason and effective dates.

Deactivating a user must prevent further login/actions and address existing sessions, delegated authority and pending assignments. Reassign pending work explicitly; do not rewrite past approver identity.

## Budget configuration

Display original approved plan, current revised plan, authorized allocation, outstanding commitments, actual spend and remaining budget separately. GAA and APPCSE each have independent accounts by fiscal year. Shared item identities do not combine budgets.

Hard limit is the proposed default. If soft warnings or overrides are enabled, specify who can use them and the required reason/independent approval. Overrides never permit untraceable fund transfer, invalid quantity, negative inventory or historical approval overwrite.

PO approval reserves budget as commitment. Explicit expense posting settles its matching amount/quantity allocation and recognizes spending together. An accepted delivery increases stock; it does not prove payment. Investigate any report that appears to add the same PO amount to both outstanding commitment and actual expenditure.

## Monthly control routine

- Check pending approval inbox and overdue documents. Confirm returns/rejections have useful comments and stale workflow revisions are not approved.
- Review low-stock items, outstanding procurement/receipts, reservations and partial fulfillment. Releasing a stale reservation must retain its history.
- Create or verify monthly count sessions for all active locations. Confirm counters, cutoff and fund ownership identification. Require independent review before adjustment.
- Review budget utilization per fund and unusual overrides/reversals. Reconcile materialized balances to ledgers through diagnostics.
- Review access changes, inactive accounts/delegations, failed queues/notifications, backup alerts and audit exceptions.

## Fiscal closing

Review outstanding requests, orders, partial receipts, unpaid commitments/expenditure recognition, unclosed counts, unresolved variances and inventory valuation. Resolve or explicitly authorize carry-forward of each eligible document. Retain closing reports and approval evidence.

Close the fiscal year through its controlled action; ordinary users must no longer backdate transactions into it. New-year inventory must reconcile to closing inventory once. Do not automatically carry unused budget into the new year. Reopening is an exceptional independently authorized action with a reason and audit record.

## Correcting mistakes

Revise unsubmitted drafts normally. After approval/posting, use approved amendment/change requests, linked cancellation, return, reversal or adjustment. Never edit stock balances, settled expense amounts, approved projection versions or audit logs directly. For suspected corruption, stop the affected posting flow, preserve evidence, reconcile, and follow the recovery procedure.

Export/audit access remains scoped and private. Do not distribute reports by changing file storage to public access. Archive records only under the approved retention policy.
