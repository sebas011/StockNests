# MASTER DEVELOPMENT PROMPT

## Budget-Integrated Inventory Management System

### 1. Your Role

Act as a team of experienced professionals consisting of:

- Senior Software Architect
- Full-Stack Software Engineer
- Database Architect
- UI/UX Designer
- Inventory Management Specialist
- Budget and Financial Systems Analyst
- Quality Assurance Engineer
- Application Security Engineer

Your task is to design and build a complete, production-ready, web-based **Budget-Integrated Inventory Management System**.

The system must integrate annual projected expenses, multiple funding sources, budget monitoring, item requests, approval workflows, procurement, stock management, monthly physical inventory, reporting, and audit trails.

Do not build a generic inventory CRUD application. Build a cohesive system based on the actual business processes described below.

Prioritize correctness, security, maintainability, usability, and data integrity over unnecessary complexity.

---

### 2. Business Background

Our organization manages inventory and expenses through an annual projection and monthly request process.

**Current business process:**

1. At the beginning of each fiscal year, we prepare and submit projected expenses for the entire year.
2. We currently have exactly two funding sources.
3. Each funding source has its own list of projected items, quantities, and estimated costs.
4. Annual projections are submitted for review and approval.
5. Once approved, projected items become the baseline for the organization's annual budget planning.
6. However, projected items are estimates, not fixed purchasing requirements. Actual needs, quantities, prices, and item selections may change throughout the year.
7. During each month, departments or authorized users submit item requests based on actual requirements.
8. Requests are not automatically approved. They must pass through the appropriate review and approval workflow.
9. Approved requests may be fulfilled using existing inventory or may require purchasing additional stock.
10. Inventory personnel also conduct monthly manual physical inventory counts.
11. Physical inventory results must be compared against system records.
12. Any discrepancy must be documented, reviewed, and approved before inventory records are adjusted.
13. The organization must monitor projected expenses, approved requests, actual spending, item availability, and inventory movements throughout the year.

The system must support this complete workflow, including changes to initially approved projections.

**Important distinction:** An approved annual projection is a planning baseline, not proof that funds were spent, goods were ordered, or inventory was received.

Likewise, approving an item request must not automatically decrease stock. Inventory should change only when a valid receiving, issuance, return, transfer, or authorized adjustment transaction occurs.

---

### 3. Required Technology Stack

Build the application using:

**Backend**

- PHP 8.4 or later, compatible with Laravel 13
- Laravel 13
- Laravel MVC architecture
- Eloquent ORM
- Laravel Form Requests for validation
- Laravel Policies and authorization
- Laravel Events, Notifications, and Queues

**Frontend**

- React 19
- TypeScript
- Inertia.js 3
- Tailwind CSS 4
- shadcn/ui
- Lucide icons
- Recharts for analytics
- Accessible, reusable React components

**Database**

- PostgreSQL 18
- Properly normalized relational schema
- Foreign keys, constraints, and indexes
- Database transactions for critical operations
- Appropriate decimal data types for quantities and monetary amounts

**Additional tools**

- Spatie Laravel Permission for role-based access
- Laravel Excel for spreadsheet import/export
- A maintained PDF generation library
- Redis for caching and background queues where required
- Pest for backend tests
- Vitest for frontend unit tests
- Playwright for end-to-end tests
- Docker Compose for local development
- Git for version control

Use the official Laravel React starter kit where suitable.

Prefer a modular Laravel monolith rather than separate microservices.

Use stable, compatible package releases, avoiding unnecessary dependencies.

The initial deployment must be practical for a small to medium-sized organization while remaining extensible.

---

### 4. Functional Modules

Build the following integrated modules.

#### MODULE A — Authentication and User Management

Implement secure authentication and role-based access control.

Suggested roles:

1. Super Administrator
2. System Administrator
3. Budget Officer
4. Department Requester
5. Department Head
6. Fund Approver
7. Procurement Officer
8. Inventory Custodian
9. Auditor / Read-Only User

Roles and permissions must be configurable.

Features:

- Secure login and logout
- Password reset
- Account activation/deactivation
- User profiles
- Department assignments
- Role and permission management
- Configurable approval authority
- Login activity logs
- Session management
- Optional two-factor authentication
- Separation of duties

Users must only access information and perform actions authorized by their roles.

Prevent users from approving their own requests unless an authorized administrator explicitly configures an exception.

Do not rely solely on hiding frontend buttons for authorization. Enforce permissions on the server.

#### MODULE B — Funding Source Management

The system initially has two funding sources.

Do not hardcode funding sources throughout the application. Store them in a dedicated database table so authorized administrators can configure them.

Each funding source must maintain independent:

- Annual projected items
- Approved estimated budget amounts
- Budget amendments
- Monthly requests
- Approved commitments
- Actual expenditures
- Remaining authorized budget
- Budget utilization reports

Required features:

- Create and edit funding source profiles
- Activate/deactivate funding sources
- Assign authorized approvers
- Configure spending controls
- Track fund-specific transactions
- Filter reports by funding source
- Maintain separate fund accounting references

An item may appear under both funding sources, but its planned quantities, budgets, requests, and expenses must remain separately traceable.

Fund transfers must not occur silently. If supported, require an explicitly approved, audited fund transfer process.

#### MODULE C — Fiscal Year and Annual Projection Management

Create a complete annual projected expense management module.

At the beginning of each fiscal year, authorized users should be able to prepare projected expenses separately for each funding source.

Each projected line item should contain:

- Fiscal year
- Funding source
- Department
- Item code
- Item name
- Category
- Description/specifications
- Unit of measurement
- Estimated annual quantity
- Estimated unit price
- Estimated total cost
- Justification
- Priority
- Optional planned month or monthly quantity breakdown
- Remarks

Automatically calculate:

Estimated Total = Estimated Quantity × Estimated Unit Price

The system must provide:

- Projection creation
- Draft saving
- Editing before submission
- Submission for approval
- Approval or rejection
- Return for revision
- Reviewer comments
- Approval timestamps
- Version history
- Bulk entry
- Spreadsheet import/export
- Projection duplication for the next fiscal year
- Comparisons with previous years

**Projection workflow:**

Draft → Submitted → Under Review → Approved / Rejected / Returned for Revision

Approved projections become the baseline annual plan.

However, changes must remain possible through a controlled amendment process.

#### MODULE D — Annual Budget Monitoring and Amendments

Create a budget monitoring module that distinguishes the following:

1. Original approved projection
2. Current revised projection
3. Authorized budget allocation
4. Approved spending commitments
5. Actual expenditures
6. Remaining available budget

Do not automatically assume the total projected cost is the same as the legally or administratively authorized spending limit. Support a configurable relationship between projection approval and budget authorization.

Support:

- Revised item quantities
- Revised estimated costs
- Addition of unplanned items
- Removal or cancellation of projected items
- Budget adjustments
- Fund-specific budget monitoring
- Changes in supplier prices
- Budget amendment requests
- Amendment approval/rejection
- Complete amendment history
- Budget utilization charts
- Overspending warnings
- Configurable budget enforcement

For example, if an item was projected at 100 units but only 70 are actually needed, the system should preserve the original 100-unit projection while displaying the revised requirement and actual transactions.

Similarly, users must be able to request items that were not included in the original projection, subject to appropriate approvals.

Never overwrite approved historical projection records without retaining the original version.

Support optional hard budget limits, soft warnings, and authorized overrides.

Avoid double-counting commitments and actual spending when purchase orders transition into paid or received transactions.

#### MODULE E — Item Master Data and Catalog

Create a centralized item catalog.

Each item should include:

- Unique item code/SKU
- Item name
- Category
- Description
- Specifications
- Unit of measurement
- Item type
- Minimum stock level
- Reorder point
- Optional maximum stock
- Standard/estimated unit cost
- Preferred supplier
- Storage location
- Active/inactive status
- Optional image
- Optional barcode or QR code
- Optional expiration date and batch tracking

Provide:

- Search
- Filters
- Sorting
- Pagination
- Category management
- Unit-of-measure management
- Duplicate prevention
- Import/export
- Item history

Use one shared item master catalog while preserving fund-specific budget and transaction tracking.

#### MODULE F — Monthly Item Request Management

Build a monthly requisition system.

Authorized users must be able to submit requests for items.

Each request should include:

- Unique request number
- Request date
- Requested delivery or fulfillment date
- Requesting department
- Requesting user
- Funding source
- Requested item(s)
- Requested quantity
- Unit of measurement
- Estimated price
- Purpose/justification
- Priority
- Optional attachments
- Remarks

Support multiple items per request.

**Request workflow:**

Draft → Submitted → Pending Review → Approved / Rejected / Returned for Revision

After approval, requests may progress to:

Pending Fulfillment → Partially Fulfilled → Fulfilled → Closed

Canceled must be a controlled status with cancellation reasons and appropriate permissions.

Requirements:

- Requests must never be automatically approved by default.
- Approvers must be able to approve, reject, or return requests.
- Support configurable multilevel approvals.
- Store approver identities and timestamps.
- Allow approval comments.
- Support request modifications before approval.
- Support controlled change requests after approval.
- Allow partially approved quantities when authorized.
- Track requested versus approved versus fulfilled quantities.
- Show stock availability.
- Show budget availability.
- Show whether requested items were included in annual projections.
- Flag unplanned and over-budget items.
- Prevent unauthorized users from skipping approval stages.
- Prevent repeated submissions caused by double-clicks or retries.

Request approval must not automatically purchase items, create inventory, or deduct stock.

#### MODULE G — Procurement and Purchasing

Implement a purchasing workflow for approved requests requiring additional stock.

Functions:

- Procurement requisitions
- Supplier selection
- Purchase order creation
- Purchase order approval
- Estimated purchase costs
- Actual purchase prices
- Purchase order tracking
- Partial order deliveries
- Purchase cancellations
- Supplier records
- Purchase documents and attachments
- Actual expense tracking

Suggested workflow:

Approved Request → Procurement Review → Purchase Order → Order Approval → Ordered → Received / Partially Received → Closed

The system should maintain a clear relationship between:

- Original projection
- Approved monthly request
- Purchase order
- Goods receipt
- Actual expenditure

Purchase orders and receipts must be separate records.

A purchase order alone does not represent stock received.

Support cancellation, returns, partial deliveries, and the release or reversal of unused commitments.

#### MODULE H — Inventory Receiving and Stock Management

Track actual physical inventory.

Implement:

- Stock receipts
- Stock issuances
- Stock returns
- Stock transfers
- Stock adjustments
- Stock reservations
- Stock history
- Stock balances
- Storage locations
- Reorder alerts
- Low-stock warnings
- Optional expiration monitoring
- Optional batch or lot tracking

Every stock movement must record:

- Transaction reference
- Item
- Quantity
- Movement type
- Source/destination
- Funding source or ownership attribution where applicable
- Responsible user
- Date/time
- Remarks
- Supporting document reference

Use an auditable stock movement ledger.

Never directly manipulate stock balances without generating an authorized stock movement transaction.

Prevent negative inventory balances by default.

Use atomic database transactions, appropriate locking, and idempotency protections to prevent concurrent requests from causing inconsistent balances.

If physical stock funded by different sources is stored in one location, maintain proper fund attribution while preventing double-counting of physical stock.

#### MODULE I — Inventory Issuance and Fulfillment

Approved requests for available stock should be fulfilled through an issuance process.

Features:

- Approved request queue
- Available inventory lookup
- Reservation of stock
- Pick and release workflow
- Partial issuance
- Recipient identification
- Date of issuance
- Issued quantity
- Issuing staff
- Receiving staff
- Optional acknowledgment or signature
- Issuance receipt generation
- Return of unused items

Stock must decrease only when the issuance transaction is confirmed.

Maintain a complete link to the original approved request.

#### MODULE J — Monthly Manual Physical Inventory

This is a critical module.

Our organization performs manual physical inventory every month.

Build a physical inventory counting and reconciliation system.

Functions:

- Create monthly inventory count sessions
- Define counting month and date
- Select warehouse/location
- Assign counting personnel
- Generate printable count sheets
- Support manual quantity encoding
- Support spreadsheet uploads
- Save incomplete counts
- Support recounting
- Review discrepancies
- Submit counts for approval
- Finalize count sessions
- Generate reconciliation reports

Each count entry should capture:

- Item code
- Item name
- Storage location
- System quantity at the count cutoff
- Physical counted quantity
- Quantity variance
- Variance reason
- Counter identity
- Count date
- Reviewer comments

Automatically calculate:

Variance = Physical Quantity − System Quantity

Support positive and negative variances.

The system must distinguish a count from an adjustment.

A physical count must not automatically change stock quantities.

Suggested workflow:

Count Created → Counting → Submitted → Under Review → Approved → Reconciled → Closed

Any correction to system stock balances must require an authorized adjustment.

Include controls for transactions occurring while a count is in progress, such as snapshot-based quantities, cutoff times, and reconciliation of post-cutoff movements.

Maintain historical count results and adjustment records.

#### MODULE K — Dashboard and Analytics

Build role-specific dashboards.

The main dashboard should provide:

- Current fiscal year
- Total projected annual expenses
- Original and revised projections
- Authorized annual budget
- Committed expenses
- Actual spending
- Remaining budget
- Total inventory quantity/value
- Low-stock items
- Pending requests
- Pending approvals
- Open purchase orders
- Monthly requests
- Recent transactions
- Inventory discrepancies
- Overdue physical counts

Provide charts showing:

- Projected versus actual expenses
- Monthly spending trends
- Budget utilization by funding source
- Request status distribution
- Inventory movement trends
- Most-requested items
- Low-stock categories

Dashboard data must reflect actual database transactions.

Do not use hardcoded demo values outside a clearly identified demonstration or development environment.

#### MODULE L — Reports and Document Generation

Provide comprehensive reports.

Required reports:

1. Annual projected expense report
2. Approved annual budget report
3. Original versus revised projection report
4. Budget utilization report
5. Monthly item request report
6. Pending approval report
7. Procurement and purchase order report
8. Actual spending report
9. Current stock report
10. Inventory movement ledger
11. Stock issuance report
12. Monthly physical inventory report
13. Inventory discrepancy report
14. Low-stock report
15. Supplier purchase history
16. Item consumption report
17. Fund-specific expenditure report
18. Annual comparative report
19. Audit trail report

Each report should support relevant filters, such as:

- Fiscal year
- Month/date range
- Funding source
- Department
- Item/category
- Status
- Requester
- Supplier
- Storage location

Provide PDF, Excel, and CSV exports where appropriate.

Reports should include generation dates, document references, and responsible users where relevant.

Reports must respect user permissions and fund visibility restrictions.

#### MODULE M — Notifications and Approval Center

Implement a centralized approval and notification center.

Notify users when:

- A projection is submitted
- A projection is approved/rejected
- A budget amendment requires approval
- A monthly request is submitted
- A request is returned for revision
- A request is approved/rejected
- A purchase order requires action
- Goods have been received
- Approved inventory is ready for release
- Stock is running low
- A physical inventory count is due
- A discrepancy requires reconciliation approval

Support in-app notifications and optional email notifications.

Provide:

- Pending approval inbox
- Notification history
- Unread notification indicators
- Links to relevant records
- Configurable notification preferences

All approval decisions must remain auditable.

#### MODULE N — Audit Trails and Activity Logs

Record all significant user and financial activities.

Track:

- Login events
- Record creation
- Record modifications
- Projection submissions and decisions
- Budget amendments
- Request approvals/rejections
- Purchase order changes
- Inventory receipts
- Inventory issuances
- Stock adjustments
- Physical count approvals
- Permission changes
- System configuration changes

Where appropriate, store previous and updated values.

Audit records must include:

- User
- Action
- Timestamp
- Affected record
- Relevant before/after values
- Reason or remarks

Protect audit records from unauthorized alteration or deletion.

Implement audit retention and archival controls.

#### MODULE O — Administration and System Configuration

Provide administrative configuration for:

- Organization information
- Funding sources
- Departments
- Warehouses/storage locations
- Fiscal years
- Item categories
- Units of measurement
- Approval workflows
- Budget rules
- Notification settings
- Stock thresholds
- Report settings
- User permissions

Default currency to Philippine Peso (PHP), but keep currency formatting configurable.

Default timezone to Asia/Manila, configurable by administrators.

Support fiscal-year closing and opening, including controlled carry-forward of opening inventory balances. Do not automatically carry forward unused budget without an explicit authorized rule.

---

### 5. Database Architecture

Design a properly normalized PostgreSQL database.

At minimum, evaluate the need for these entities:

**Organization and access**

- users
- roles
- permissions
- departments
- funding_sources
- approval_workflows
- approval_steps
- approval_decisions
- activity_logs

**Budgeting**

- fiscal_years
- annual_projections
- projection_items
- projection_versions
- projection_amendments
- budget_allocations
- budget_adjustments
- financial_commitments
- expenditure_records

**Items and procurement**

- items
- item_categories
- units_of_measure
- suppliers
- item_requests
- item_request_lines
- purchase_orders
- purchase_order_lines
- goods_receipts
- goods_receipt_lines

**Inventory**

- warehouses
- stock_locations
- stock_lots
- stock_movements
- stock_reservations
- stock_issuances
- stock_issuance_lines
- inventory_counts
- inventory_count_lines
- inventory_adjustments
- stock_transfers

**System**

- notifications
- attachments
- system_settings
- audit_logs

These are suggested entities, not mandatory final table names.

Refine the schema to avoid duplication, enforce referential integrity, and support all business rules.

Generate:

- Entity relationship diagram
- Database migration files
- Foreign keys and constraints
- Indexes
- Model relationships
- Seed data

Use immutable financial and inventory ledgers where appropriate.

Never store values as floating-point numbers when precision is essential.

Maintain reliable traceability between planning, requests, approval, procurement, receiving, issuance, and actual expenditures.

---

### 6. UI/UX Requirements

Build a professional, modern enterprise application.

The interface should feel like polished financial and inventory management software rather than a basic school project.

Use:

- Clean dashboard layout
- Collapsible sidebar
- Top navigation
- Breadcrumbs
- Professional typography
- Consistent spacing
- Light and dark modes
- Responsive layouts
- Accessible color contrast
- Consistent status indicators
- Reusable data tables
- Searchable dropdowns
- Modal dialogs
- Form validation
- Loading skeletons
- Empty states
- Error states
- Success confirmations
- Toast notifications
- Pagination
- Date range filtering

Use shadcn/ui and Tailwind CSS consistently.

For complex workflows, provide clear step-based interfaces and status timelines.

Approval screens should present:

- Request details
- Requested items
- Budget availability
- Stock availability
- Relevant annual projection
- Previous approvals
- Supporting attachments
- Approve/reject/return actions
- Required comments where applicable

Prevent confusing or accidental financial and inventory changes.

All financial and inventory reports must be legible on screen and when printed.

---

### 7. Business Rules and Validation

The following rules are mandatory:

1. Annual projections must be approved before becoming the official planning baseline.
2. Approved projections may be amended through an authorized workflow.
3. Initial projections must remain historically accessible.
4. Monthly requests must require approval.
5. Requests cannot bypass configured approval levels.
6. The requester and approver must follow separation-of-duties rules.
7. Request approval must not automatically deduct inventory.
8. Purchase order approval must not automatically add inventory.
9. Stock increases only through valid receiving, return, transfer-in, or adjustment transactions.
10. Stock decreases only through valid issuance, transfer-out, return-to-supplier, or adjustment transactions.
11. Inventory count variances require reconciliation approval before adjustment.
12. Every monetary transaction must have a valid fund attribution.
13. Negative quantities, invalid costs, and duplicate transaction processing must be prevented.
14. Over-budget behavior must follow configured budget controls.
15. Unplanned item requests are permitted only through the configured exception/approval process.
16. Historical approvals and financial records must not be silently overwritten.
17. Authorization must be enforced on the backend.
18. Fiscal year closing must prevent unauthorized backdated changes.
19. Critical operations must use database transactions and concurrency controls.
20. All significant changes must generate auditable records.

---

### 8. Security Requirements

Implement:

- Server-side request validation
- Password hashing
- CSRF protection
- Secure session handling
- Role and permission checks
- Object-level authorization
- Rate limiting
- File upload validation
- Protection against SQL injection and XSS
- Secure error handling
- Environment-based secrets
- Database backup and recovery procedures
- Authorization tests
- Secure audit logging

Use Laravel security features and established best practices.

Do not expose confidential data through API responses, exports, or unauthorized views.

---

### 9. Development Approach

Do not attempt to write thousands of unrelated files without a clear implementation plan.

Follow this sequence.

**Phase 1 — Requirements and Architecture**

First produce:

- Business process analysis
- Assumptions requiring confirmation
- User roles and permissions matrix
- Complete module list
- Main workflows
- System architecture
- Database ERD
- Project folder structure
- Implementation roadmap

Identify ambiguous requirements, especially the differences between item requisition, inventory issuance, and procurement requests.

Use sensible, configurable defaults where requirements are not yet finalized.

**Phase 2 — Project Foundation**

Create:

- Laravel project
- React/Inertia integration
- PostgreSQL configuration
- Authentication
- Role-based access
- Main dashboard layout
- Migrations and seeders

**Phase 3 — Core Budgeting**

Implement:

- Funding sources
- Fiscal years
- Item catalog
- Annual projections
- Projection approvals
- Budget monitoring
- Projection amendments

**Phase 4 — Monthly Operations**

Implement:

- Monthly requests
- Approval workflows
- Purchase orders
- Goods receipts
- Inventory movements
- Stock issuance

**Phase 5 — Physical Inventory**

Implement:

- Monthly counting sessions
- Count sheets
- Manual counts
- Discrepancy handling
- Approval-based reconciliation

**Phase 6 — Reporting and Administration**

Implement:

- Dashboard analytics
- Reports
- Export functionality
- Notifications
- Audit trails
- Administrative configuration

**Phase 7 — Testing and Deployment**

Implement:

- Backend tests
- Frontend tests
- End-to-end workflow tests
- Security tests
- Concurrent inventory operation tests
- Deployment configuration
- Setup documentation
- Backup documentation

Each phase must produce functional, integrated, tested code.

Do not leave key business logic as placeholders.

---

### 10. Required Test Scenarios

At minimum, verify the following scenarios:

**Scenario 1 — Annual planning**

A user submits different projected item lists for Funding Source A and Funding Source B.

An authorized officer approves each projection.

Both approved plans appear separately in annual budget reports.

**Scenario 2 — Monthly request**

A department requests an item included in its annual projection.

The request remains pending until an authorized approver acts.

Approval alone must not change physical stock or actual expenditure.

**Scenario 3 — Unplanned purchase**

A user requests an item that was not included in the approved annual plan.

The system identifies it as unplanned and routes it through the configured exception workflow.

**Scenario 4 — Budget amendment**

An initially projected quantity or estimated price changes.

The system retains the original approved projection and records the newly approved revision.

**Scenario 5 — Stock receiving**

An approved purchase order is partially delivered.

Only received quantities are added to available physical inventory.

Outstanding quantities remain visible.

**Scenario 6 — Stock issuance**

An approved item request is fulfilled from available stock.

Stock is deducted only when the issuance is confirmed.

**Scenario 7 — Physical count discrepancy**

The system shows 50 units of an item.

The assigned counter physically counts 47 units.

The system records a variance of -3.

Inventory remains at its recorded balance until an authorized reconciliation adjustment is approved.

**Scenario 8 — Fund separation**

The same item exists in both funding sources.

A purchase from Fund A must not automatically consume Fund B's budget.

**Scenario 9 — Concurrent transactions**

Two users attempt to issue the last available stock simultaneously.

The system prevents the stock balance from becoming negative or processing the same issuance twice.

**Scenario 10 — Year-end reporting**

An administrator generates annual reports comparing original projections, revised projections, authorized allocations, actual expenses, and inventory movements.

The reports accurately reflect the entire fiscal year.

---

### 11. Deliverables

Provide the following:

1. Complete working source code
2. Maintainable project architecture
3. Database schema and migrations
4. ER diagram
5. Working authentication and permissions
6. Functional frontend pages
7. Backend business logic
8. Approval workflows
9. Financial and inventory calculations
10. Report and export functionality
11. Automated test suite
12. Sample seed data for two funding sources
13. Example fiscal-year projections
14. Sample monthly requests and approval transactions
15. Local installation instructions
16. Docker development configuration
17. Deployment guidance
18. Administrator documentation
19. End-user instructions

Use realistic sample data to demonstrate the workflow, but separate demo data from production data.

Maintain a README explaining how to install, configure, test, and operate the system.

---

### 12. Final Development Instructions

Treat this project as a real internal business application that employees will depend on daily.

Do not prioritize visual appearance over transactional correctness.

Ensure that budgets, approvals, purchasing, inventory, and physical counting operate consistently.

Avoid unnecessary complexity and overengineering.

Build components that are reusable and understandable.

Do not silently introduce unapproved business rules.

When important business requirements are unclear, document the assumption and propose the most practical solution rather than inventing a rigid policy.

When generating code, provide complete, working implementations with actual database integration.

Do not use mock-only workflows or nonfunctional UI buttons as final implementations.

Explain what is implemented, what has been tested, and what remains incomplete.

**Start by analyzing the requirements and presenting the proposed architecture, database schema, module dependencies, user permissions, and development roadmap. Then begin implementing the application in logical, testable phases.**

The ultimate goal is a reliable, professional, and scalable Budget-Integrated Inventory Management System that replaces manual spreadsheets and fragmented recordkeeping with a centralized, auditable workflow.
