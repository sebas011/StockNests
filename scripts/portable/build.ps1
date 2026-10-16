[CmdletBinding()]
param(
    [string]$SourceRoot = (Join-Path $PSScriptRoot '../..'),
    [string]$OutputDirectory,
    [string]$PhpArchive,
    [string]$PostgresArchive,
    [string]$VcRuntimeDirectory,
    [string]$ComposerPath,
    [switch]$SkipAssets
)

$ErrorActionPreference = 'Stop'
$SourceRoot = [IO.Path]::GetFullPath($SourceRoot)
if (-not $OutputDirectory) { $OutputDirectory = Join-Path $SourceRoot 'dist' }
$OutputDirectory = [IO.Path]::GetFullPath($OutputDirectory)
$manifest = Get-Content -LiteralPath (Join-Path $PSScriptRoot 'runtimes.json') -Raw | ConvertFrom-Json
$buildParent = Join-Path $SourceRoot ('.tools/portable-build-' + [Guid]::NewGuid().ToString('N'))
$packageRoot = Join-Path $buildParent 'StockNest'
$downloadRoot = Join-Path $SourceRoot '.tools/portable-downloads'
foreach ($directory in @($packageRoot, $downloadRoot, $OutputDirectory)) { New-Item -ItemType Directory -Force -Path $directory | Out-Null }

function Get-VerifiedArchive($Specification, [string]$ExistingPath, [string]$Name) {
    if ($ExistingPath) { $path = [IO.Path]::GetFullPath($ExistingPath) }
    else {
        $path = Join-Path $downloadRoot $Name
        if (-not (Test-Path -LiteralPath $path)) {
            Write-Host "Downloading official $Name runtime..."
            $previousProgress = $ProgressPreference
            try { $ProgressPreference = 'SilentlyContinue'; Invoke-WebRequest -UseBasicParsing -Uri $Specification.url -OutFile $path }
            finally { $ProgressPreference = $previousProgress }
        }
    }
    if ((Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash -ne $Specification.sha256) { throw "Runtime checksum mismatch: $Name" }
    return $path
}

function Copy-Tree([string]$From, [string]$To, [string[]]$Extra = @()) {
    New-Item -ItemType Directory -Force -Path $To | Out-Null
    & robocopy $From $To /E /NFL /NDL /NJH /NJS /NP /R:1 /W:1 @Extra | Out-Null
    if ($LASTEXITCODE -ge 8) { throw "Copy failed: $From" }
}

Add-Type -AssemblyName System.IO.Compression.FileSystem
$phpZip = Get-VerifiedArchive $manifest.php $PhpArchive 'php-windows-x64.zip'
$postgresZip = Get-VerifiedArchive $manifest.postgresql $PostgresArchive 'postgresql-windows-x64.zip'
$phpRoot = Join-Path $packageRoot 'runtime/php'
[IO.Compression.ZipFile]::ExtractToDirectory($phpZip, $phpRoot)
$postgresRoot = Join-Path $packageRoot 'runtime/postgresql'
$archive = [IO.Compression.ZipFile]::OpenRead($postgresZip)
try {
    foreach ($entry in $archive.Entries) {
        $isLicense = $entry.FullName -match '^pgsql/(?:doc/)?(server_license|commandlinetools_3rd_party_licenses)\.txt$'
        if ($entry.FullName -notmatch '^pgsql/(bin|lib|share)/' -and -not $isLicense) { continue }
        if ($entry.FullName.EndsWith('/')) { continue }
        $relative = $entry.FullName.Substring(6)
        if ($isLicense) { $relative = 'doc/' + [IO.Path]::GetFileName($relative) }
        $destination = [IO.Path]::GetFullPath((Join-Path $postgresRoot $relative))
        if (-not $destination.StartsWith([IO.Path]::GetFullPath($postgresRoot) + [IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase)) { throw 'Archive path escaped the PostgreSQL runtime directory' }
        New-Item -ItemType Directory -Force -Path (Split-Path $destination -Parent) | Out-Null
        [IO.Compression.ZipFileExtensions]::ExtractToFile($entry, $destination, $true)
    }
} finally { $archive.Dispose() }
foreach ($notice in @('server_license.txt', 'commandlinetools_3rd_party_licenses.txt')) {
    if (-not (Test-Path -LiteralPath (Join-Path $postgresRoot "doc/$notice"))) { throw "PostgreSQL redistribution notice missing: $notice" }
}

if (-not $VcRuntimeDirectory) {
    $candidates = @()
    foreach ($root in @('C:/Program Files/Microsoft Visual Studio', 'C:/Program Files (x86)/Microsoft Visual Studio')) {
        if (Test-Path -LiteralPath $root) {
            $candidates += Get-ChildItem -Path "$root/*/*/VC/Redist/MSVC/*/x64/Microsoft.VC*.CRT" -Directory -ErrorAction SilentlyContinue
        }
    }
    $VcRuntimeDirectory = $candidates | Sort-Object FullName -Descending | Select-Object -First 1 -ExpandProperty FullName
}
if (-not $VcRuntimeDirectory) { throw 'Provide the licensed Visual C++ x64 redistributable CRT directory with -VcRuntimeDirectory. End users must not need a runtime installer.' }
foreach ($requiredFile in @('vcruntime140.dll', 'vcruntime140_1.dll', 'msvcp140.dll')) {
    if (-not (Test-Path -LiteralPath (Join-Path $VcRuntimeDirectory $requiredFile))) { throw "Redistributable file missing: $requiredFile" }
}
$vcFiles = @(Get-ChildItem -LiteralPath $VcRuntimeDirectory -File | Where-Object { $_.Name -match '^(vcruntime140.*|msvcp140.*|concrt140|vccorlib140|vcomp140)\.dll$' })
foreach ($target in @($phpRoot, (Join-Path $postgresRoot 'bin'))) {
    foreach ($file in $vcFiles) { Copy-Item -LiteralPath $file.FullName -Destination (Join-Path $target $file.Name) }
}

$php = Join-Path $phpRoot 'php.exe'
$builderIni = Join-Path $buildParent 'builder.ini'
$extensionDirectory = (Join-Path $phpRoot 'ext').Replace('\', '/')
@"
extension_dir="$extensionDirectory"
extension=openssl
extension=curl
extension=mbstring
extension=fileinfo
extension=pdo_pgsql
extension=pgsql
extension=pdo_sqlite
extension=sqlite3
extension=gd
extension=zip
extension=intl
extension=sodium
memory_limit=512M
date.timezone=Asia/Manila
"@ | Set-Content -LiteralPath $builderIni -Encoding ASCII
$env:PHPRC = $builderIni
& $php -c $builderIni -r 'if (!extension_loaded("bcmath") || !extension_loaded("pdo_pgsql")) { exit(1); } echo PHP_VERSION, PHP_EOL;'
if ($LASTEXITCODE -ne 0) { throw 'Bundled PHP extension verification failed' }

if (-not $SkipAssets) {
    Push-Location $SourceRoot
    try {
        & $php -c $builderIni artisan wayfinder:generate --with-form
        if ($LASTEXITCODE -ne 0) { throw 'Route helper generation failed' }
        $env:STOCKNEST_SKIP_WAYFINDER = 'true'
        & npm run build
        if ($LASTEXITCODE -ne 0) { throw 'Frontend asset build failed' }
    } finally { Pop-Location }
}
if (-not (Test-Path -LiteralPath (Join-Path $SourceRoot 'public/build/manifest.json'))) { throw 'Build frontend assets before packaging' }

$applicationRoot = Join-Path $packageRoot 'app'
foreach ($folder in @('app', 'config', 'database', 'public', 'resources', 'routes')) {
    $copyOptions = @('/XJ', '/XF', '*.sqlite', '*.sqlite3', 'hot')
    if ($folder -eq 'public') { $copyOptions += @('/XD', (Join-Path $SourceRoot 'public/storage')) }
    Copy-Tree (Join-Path $SourceRoot $folder) (Join-Path $applicationRoot $folder) $copyOptions
}
New-Item -ItemType Directory -Force -Path (Join-Path $applicationRoot 'bootstrap/cache') | Out-Null
foreach ($directory in @('storage/framework/views', 'storage/framework/sessions', 'storage/framework/cache/data', 'storage/logs')) { New-Item -ItemType Directory -Force -Path (Join-Path $applicationRoot $directory) | Out-Null }
Get-ChildItem -LiteralPath (Join-Path $SourceRoot 'bootstrap') -File | ForEach-Object { Copy-Item -LiteralPath $_.FullName -Destination (Join-Path $applicationRoot 'bootstrap') }
foreach ($file in @('artisan', 'composer.json', 'composer.lock', '.env.example')) { Copy-Item -LiteralPath (Join-Path $SourceRoot $file) -Destination (Join-Path $applicationRoot $file) }
Copy-Tree (Join-Path $SourceRoot 'vendor') (Join-Path $applicationRoot 'vendor')
if (-not $ComposerPath) {
    if (Test-Path -LiteralPath (Join-Path $SourceRoot '.tools/composer.phar')) { $ComposerPath = Join-Path $SourceRoot '.tools/composer.phar' }
    else { throw 'Provide Composer PHAR with -ComposerPath for the build machine' }
}
Push-Location $applicationRoot
try {
    & $php -c $builderIni $ComposerPath install --no-dev --no-interaction --prefer-dist --no-progress --no-scripts
    if ($LASTEXITCODE -ne 0) { throw 'Production dependency preparation failed' }
    & $php -c $builderIni artisan package:discover --ansi
    if ($LASTEXITCODE -ne 0) { throw 'Production package discovery failed' }
} finally { Pop-Location }
# Package discovery validates the build, but its machine-specific caches must not ship.
Get-ChildItem -LiteralPath (Join-Path $applicationRoot 'bootstrap/cache') -File -Filter '*.php' | ForEach-Object { Remove-Item -LiteralPath $_.FullName }
foreach ($file in @('launcher.ps1', 'Start StockNest.cmd', 'Stop StockNest.cmd')) { Copy-Item -LiteralPath (Join-Path $PSScriptRoot $file) -Destination (Join-Path $packageRoot $file) }
@'
# StockNest for Windows

Extract the entire ZIP, open **Start StockNest.cmd**, and create your administrator account in the browser. Open **Stop StockNest.cmd** when finished.

No Docker or separate software installation is required. Your records stay in **data/**. Keep this folder when backing up, moving or updating StockNest.

Read the [full Windows instructions](docs/operations/portable-windows.md) for backups, moving to another PC and updates.
'@ | Set-Content -LiteralPath (Join-Path $packageRoot 'READ ME FIRST.md') -Encoding UTF8
Copy-Item -LiteralPath (Join-Path $SourceRoot 'INSTALL.md') -Destination (Join-Path $packageRoot 'INSTALL.md')
Copy-Tree (Join-Path $SourceRoot 'docs') (Join-Path $packageRoot 'docs')
@'
STOCKNEST FOR WINDOWS

1. Extract the entire ZIP to a folder you can write to.
2. Open Start StockNest.cmd. Your browser opens StockNest.
3. On the first start, create your own administrator account.
4. When finished, open Stop StockNest.cmd and wait for it to finish.

No Docker, Node.js, PHP or database installation is required.
Use Windows 10/11 x64 and its built-in PowerShell/browser.
After download, the application runs offline on this PC.

Your private accounts, inventory, settings and encryption keys are in data/.
Never delete that folder or upload it to GitHub. Stop StockNest before copying
the entire folder to make a backup or move to another compatible Windows PC.
Different PCs do not automatically share or synchronize records.

Full instructions: docs/operations/portable-windows.md
Project: https://github.com/sebas011/StockNests
'@ | Set-Content -LiteralPath (Join-Path $packageRoot 'READ ME FIRST.txt') -Encoding UTF8
$runtimeRecord = [ordered]@{
    php = $manifest.php; postgresql = $manifest.postgresql
    visualCpp = @($vcFiles | ForEach-Object { @{ name = $_.Name; version = $_.VersionInfo.FileVersion; sha256 = (Get-FileHash -LiteralPath $_.FullName).Hash } })
    builtFrom = (& git -C $SourceRoot rev-parse HEAD)
}
$runtimeRecord | ConvertTo-Json -Depth 8 | Set-Content -LiteralPath (Join-Path $packageRoot 'runtime-manifest.json') -Encoding UTF8
@'
StockNest bundles PHP (PHP License), PostgreSQL and EDB-supplied libraries (see runtime/postgresql/doc), production Composer dependencies (individual licenses remain under app/vendor), and app-local Microsoft Visual C++ Redistributable DLLs from the build machine's licensed Visual Studio redist directory. Microsoft runtime files remain subject to Microsoft Software License Terms. Their signed copyright/version metadata is retained.

Upstream sources:
https://www.php.net/downloads.php
https://www.postgresql.org/about/licence/
https://www.enterprisedb.com/download-postgresql-binaries
https://learn.microsoft.com/cpp/windows/redistributing-visual-cpp-files

The target computer does not run any redistributable installer. Libraries are private to this folder.
'@ | Set-Content -LiteralPath (Join-Path $packageRoot 'THIRD-PARTY-NOTICES.txt') -Encoding UTF8
$unsafe = @(Get-ChildItem -LiteralPath $packageRoot -File -Recurse -Force | Where-Object { $_.Name -eq '.env' -or $_.Name -match '\.(dump|sqlite|sqlite3)$' -or $_.FullName -match '[\\/]data[\\/]pgdata[\\/]' })
if ($unsafe.Count) { throw 'Private runtime data found in release package' }
$zipPath = Join-Path $OutputDirectory 'StockNest-Windows-x64.zip'
if (Test-Path -LiteralPath $zipPath) { Remove-Item -LiteralPath $zipPath }
[IO.Compression.ZipFile]::CreateFromDirectory($packageRoot, $zipPath, [IO.Compression.CompressionLevel]::Optimal, $true)
(Get-FileHash -LiteralPath $zipPath -Algorithm SHA256).Hash.ToLowerInvariant() + '  StockNest-Windows-x64.zip' | Set-Content -LiteralPath ($zipPath + '.sha256') -Encoding ASCII
Write-Host "Portable package created: $zipPath"
Write-Output $packageRoot
