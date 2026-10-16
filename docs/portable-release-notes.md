Download **StockNest-Windows-x64.zip** below. Extract the entire folder and open **Start StockNest.cmd**. Create your administrator account in the browser on first start. Use **Stop StockNest.cmd** when finished.

The package includes PHP, PostgreSQL, compiled screens, production application dependencies and app-local Windows runtime libraries. It needs no Docker or separate runtime/database installation. It runs offline on a Windows 10/11 x64 PC using the built-in PowerShell and a browser.

Accounts, records and private configuration are created in `data/` on your PC. No demo data or default administrator password is bundled. Stop the application before making a full-folder backup or moving it. Separate PC copies do not synchronize records.

The release workflow checks launch/stop/restart, persistence, first administrator setup, catalog records and exports. The current application remains the documented development implementation; see [remaining scope](https://github.com/sebas011/StockNests/blob/main/docs/implementation-status.md) before production use.

**Source code** archives are for developers; choose **StockNest-Windows-x64.zip** for the ready-to-run application.
