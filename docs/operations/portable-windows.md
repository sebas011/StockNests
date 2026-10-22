# Run StockNest on a Windows PC

Download **[StockNest-Windows-x64.zip](https://github.com/sebas011/StockNests/releases/latest/download/StockNest-Windows-x64.zip)**, extract it, and open **Start StockNest.cmd**. The package includes the application, PHP, PostgreSQL and app-local Visual C++ runtime files. It requires no Docker, Node.js, separate runtime installation or administrator rights.

Use **Windows 10/11 x64**, its built-in **PowerShell 5.1**, and a web browser. The application works offline after the download. This package runs on the local PC; it is not a shared office server.

GitHub's **Code → Download ZIP** and automatic **Source code** archives contain source code. They are different from the ready-to-run **StockNest-Windows-x64.zip** release asset.

## First start

1. Download the Windows release ZIP using the link above.
2. Right-click the ZIP, choose **Extract All**, and extract the entire package to a short **NTFS** folder you own, such as `C:\Users\YourName\StockNest`. Avoid Program Files, FAT/exFAT drives, network drives and folders synchronized by a cloud service. Do not open the launcher from inside the ZIP.
3. Open the extracted folder. It contains **Start StockNest.cmd**, **Stop StockNest.cmd**, **app/** and **runtime/**.
4. Double-click **Start StockNest.cmd**. The first start initializes a fresh local database, applies the database structure and creates the GAA/APPCSE reference records. It may take a few minutes. Services run in the background; the launcher displays the ready address and can then close.
5. Your browser opens the **/setup** page. Create your administrator account with your own name, email and a unique password. No shared default password is supplied. Setup signs you in automatically and opens the dashboard.

Setup creates **data/** beside the launcher. This folder holds your persistent local state, including the database and private configuration. The administrator can then create named user accounts and configure references through the application. See the [administrator guide](../guides/administrator.md) and [end-user guide](../guides/end-user.md).

If the browser does not open automatically, use the local address displayed in the launcher window. First-administrator setup is available only until an account has been created. Later users are created by an administrator; public registration remains closed.

Email delivery is disabled by default for offline use, so password reset links are not emailed unless mail delivery is configured. Keep your administrator password safe.

For approved annual items, Supply requests, outside cash purchases and monthly tracking, follow the [office workflow guide](../office-workflow.md).

## Everyday use

Open **Start StockNest.cmd** from the same extracted folder. Your existing accounts and records load automatically. StockNest continues running in the background after the launcher window closes.

When finished, open **Stop StockNest.cmd** from that folder and wait for it to finish before moving files, backing up or shutting down the PC. Closing the browser alone does not stop StockNest.

The web application and database listen only on the local PC. Each downloaded copy starts with its own records. Two PCs running StockNest do not automatically synchronize, even when they use the same usernames.

## Keep your records safe

Keep **data/** with its StockNest installation. Never delete it to fix a startup problem, replace it with an empty folder, or upload it to GitHub. It contains your organization's private records and secrets.

The launcher restricts the data folder and both private configuration files to the Windows account that runs StockNest, Windows administrators and SYSTEM. These permissions protect against other ordinary Windows accounts; they do not encrypt backups. Multiple named application accounts can still use this installation on the same PC.

To make a consistent backup:

1. Stop StockNest using **Stop StockNest.cmd** and wait for shutdown to finish.
2. Copy the **entire extracted StockNest folder**, including **data/**, to a separate backup location.
3. Date the backup and keep it private. Restart the original installation when copying is complete.

Copying database files while StockNest is running can produce an unusable backup. A full stopped-folder copy preserves the matching application, runtime, database and encryption key together.

To move to another compatible Windows PC, stop the original installation and compress its **entire folder, including data/** into a ZIP. Transfer that private ZIP, then extract it to an NTFS folder owned by the destination Windows account and open **Start StockNest.cmd**. A ZIP does not retain the original PC's Windows account permissions; the launcher applies the new account's private permissions on first start. Keep the ZIP private and delete transfer copies when no longer needed. If a direct folder copy reports access denied because it retained the old PC's account permissions, use this ZIP/extract process from the original account instead. An administrator can recover ownership if the original account is unavailable; do not remove or recreate the private configuration files. If both copies are later used, their records diverge; changes do not merge automatically. Test that you can sign in and see your records before retiring the original copy.

## Update an existing installation

Read the release notes before updating. Portable releases must support the existing PostgreSQL data version; a database major-version change requires a separate migration procedure.

1. Stop the existing installation and make a full-folder backup as described above.
2. Extract the new Windows release into a **different, empty folder**. Keep the original folder intact.
3. Before starting the new copy, copy your existing **data/** folder into the new folder. The new folder must not already contain another installation's data. Preserve the entire folder and its private configuration.
4. Open **Start StockNest.cmd** in the new folder. Startup applies any database migrations. Confirm that you can sign in, see existing records and open the reports you use.
5. Keep the stopped original installation and backup until you have verified the update.

Do not overwrite or delete existing **data/** with files from a release. Do not start the old application against a database already migrated by a newer release. To return to an earlier version, stop the new copy and restore the complete backup into a separate folder; any records entered after that backup will not be present there.

## Troubleshooting

| Problem                                      | What to do                                                                                                                                                                                                     |
| -------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| No Start StockNest.cmd file                  | Extract the complete Windows release asset. GitHub's source-code ZIP is a different download.                                                                                                                  |
| Files open inside a ZIP window               | Choose Extract All, then run the launcher from the extracted folder.                                                                                                                                           |
| Access denied or files cannot be written     | Move the stopped installation to a short folder owned by your Windows user. Avoid Program Files, network and cloud-synchronized folders.                                                                       |
| Windows warns about downloaded files         | Check that the download came from this repository's releases. Use the available file Properties/Unblock option if your Windows policy allows it. Do not disable antivirus or organizational security policies. |
| PowerShell is blocked by organization policy | Ask your PC administrator about the restriction. StockNest does not change the machine's execution policy or bypass organizational controls.                                                                   |
| Startup reports a port conflict              | Stop another copy of StockNest. If a different application owns the port, resolve the conflict before retrying; do not terminate unfamiliar processes.                                                         |
| Browser stays closed                         | Open the local address displayed by the launcher after startup completes.                                                                                                                                      |
| Setup is no longer offered                   | An account already exists. Sign in using the administrator created on the first start.                                                                                                                         |
| Records appear missing after an update       | Stop the new copy. Confirm that the existing data/ folder was copied into the correct installation before its first start. Preserve both copies until you identify the intended database.                      |
| Startup or shutdown fails                    | Read the launcher message and the log location it provides. Keep the data/ folder intact and retain the message when reporting the issue.                                                                      |

## Scope and verification

The bundled server is intended for use on one local PC and has limited request concurrency. For several employees sharing the same live records, use a centrally hosted instance with the operational controls in the [deployment guide](deployment-and-backup.md).

StockNest remains a development implementation with [documented outstanding features](../implementation-status.md). Bundling runtimes does not establish clean-machine certification. See the release notes and [verification record](../verification.md) for the tests actually performed; do not assume that an unlisted Windows configuration has been tested.

For the separate Docker/source-code installation, return to [installation instructions](../../INSTALL.md).
