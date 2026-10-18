# StockNest documentation

StockNest is a budget-integrated inventory management application for the configured organization. Its initial funding sources are **GAA** and **APPCSE**. The names are user-confirmed; their acronyms are not expanded here.

These documents distinguish **requirements/design** from **implemented behavior**. A proposed table, screen, workflow, or test in these documents is not evidence that it exists or passes. The root project README and the actual application, migrations, and test results are the authority for current implementation status.

| Document                                                          | Audience and purpose                                                                           |
| ----------------------------------------------------------------- | ---------------------------------------------------------------------------------------------- |
| [Executed verification](verification.md)                          | Developers and reviewers: actual tests and remaining runtime checks                            |
| [Implementation status](implementation-status.md)                 | Business owners: implemented behavior and remaining master-prompt scope                        |
| [Requirements and processes](requirements.md)                     | Business owners: assumptions, invariants, processes, and module dependencies                   |
| [Permissions](permissions.md)                                     | Administrators and engineers: nine default roles, department/fund scopes, separation of duties |
| [Architecture decision](architecture/ADR-001-modular-monolith.md) | Engineers: application boundaries, transaction design, security, folder layout                 |
| [Database design and ERD](database-design.md)                     | Engineers: proposed normalized schema, relationships, constraints, ledgers                     |
| [Roadmap](../.planning/ROADMAP.md)                                | Delivery team: seven implementation phases and completion evidence                             |
| [Acceptance and traceability](acceptance.md)                      | Business owners and QA: required scenarios and risk-focused verification                       |
| [Local setup](operations/local-setup.md)                          | Developers: intended local setup and dependency checks                                         |
| [Deployment and recovery](operations/deployment-and-backup.md)    | Operators: deployment, backup, recovery, and production prerequisites                          |
| [Administration](guides/administrator.md)                         | Authorized administrators: safe configuration and operating controls                           |
| [End-user workflows](guides/end-user.md)                          | Requesters, reviewers, procurement, custodians, counters, and auditors                         |

Defaults are PHP currency and Asia/Manila timezone. Persist transaction timestamps in UTC and display them in the configured timezone. Budget allocation and approved planning totals remain separate concepts.

Do not use sample data as production opening balances, actual expenditure, or evidence of authorization.
