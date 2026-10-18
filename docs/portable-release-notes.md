Download **StockNest-Windows-x64.zip** below. Extract the entire folder and open **Start StockNest.cmd**. Create your administrator account in the browser on first start. Use **Stop StockNest.cmd** when finished.

The package includes PHP, PostgreSQL, compiled screens, production application dependencies and app-local Windows runtime libraries. It needs no Docker or separate runtime/database installation. It runs offline on a Windows 10/11 x64 PC using the built-in PowerShell and a browser.

Accounts, records and private configuration are created in `data/` on your PC. No demo data or default administrator password is bundled. Stop the application before making a full-folder backup or moving it. Separate PC copies do not synchronize records.

This update strengthens approval separation, reservation limits and release, account access, report limits and private Windows file permissions. New accounts created by an administrator must replace their initial password before using the system. Report CSV downloads stream larger datasets; oversized PDF/Excel exports explain how to narrow filters or switch to CSV. Table sorting now applies across the full filtered list, and charts load when needed.

**Updating an existing copy:** stop StockNest and back up the entire folder. Extract this release into a different, empty folder, then copy your existing `data/` folder into it before starting. Startup applies the upgrade migrations. Keep the original stopped copy and backup until you confirm your records. Never replace your existing data with a release folder. See the [update guide](https://github.com/sebas011/StockNests/blob/main/docs/operations/portable-windows.md#update-an-existing-installation).

Administrators using custom roles should review the new `budget.approve`, `fund.approve`, `requests.department-approve` and `fiscal.reopen` permissions. Existing customized grants are preserved. Standard eligible role bundles receive the new permissions through the upgrade migration.

The release workflow checks launch/stop/restart, persistence, first administrator setup, catalog records and exports. The current application remains the documented development implementation; see [remaining scope](https://github.com/sebas011/StockNests/blob/main/docs/implementation-status.md) before production use.

**Source code** archives are for developers; choose **StockNest-Windows-x64.zip** for the ready-to-run application.
