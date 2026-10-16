[CmdletBinding()]
param([string] $ReleasePath)

# With no argument this checks the launcher without starting any services.
# Pass a freshly extracted, disposable portable release for the lifecycle test.
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$projectRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..'))
$source = Join-Path $projectRoot 'scripts\portable\launcher.ps1'
$tokens = $null
$parseErrors = $null
$ast = [Management.Automation.Language.Parser]::ParseFile($source, [ref] $tokens, [ref] $parseErrors)
if ($parseErrors.Count -gt 0) { throw 'The launcher contains a PowerShell syntax error.' }
$functions = $ast.FindAll({ param($node) $node -is [Management.Automation.Language.FunctionDefinitionAst] }, $true)
foreach ($function in $functions) { . ([scriptblock]::Create($function.Extent.Text)) }

function Assert-True([bool] $Condition, [string] $Message) {
    if (-not $Condition) { throw $Message }
}

$quoteCases = @(
    @('', '""'),
    @('simple-value', 'simple-value'),
    @('C:\StockNest files\app', '"C:\StockNest files\app"'),
    @('C:\StockNest files\', '"C:\StockNest files\\"'),
    @('a "quoted" value', '"a \"quoted\" value"'),
    @('C:\StockNest\', 'C:\StockNest\'),
    @('literal-$(not-a-command)', 'literal-$(not-a-command)')
)
foreach ($case in $quoteCases) {
    Assert-True ((ConvertTo-ProcessArgument $case[0]) -ceq $case[1]) 'Windows argument quoting failed.'
}
$expectedData = 'C:\StockNest portable test\data\pgdata'
foreach ($command in @(
    '"C:/StockNest portable test/runtime/postgresql/bin/postgres.exe" -D "C:/StockNest portable test/data/pgdata" -h 127.0.0.1 -p 54329',
    '"C:\StockNest portable test\runtime\postgresql\bin\postgres.exe" -D "C:\StockNest portable test\data\pgdata" -h 127.0.0.1'
)) {
    Assert-True ([string]::Equals((Get-PostgresDataDirectory $command), $expectedData, [StringComparison]::OrdinalIgnoreCase)) 'PostgreSQL data path normalization failed.'
}
Assert-True ((Get-PostgresDataDirectory 'C:/runtime/postgres.exe -D C:/StockNest/data/pgdata -h 127.0.0.1') -ceq 'C:\StockNest\data\pgdata') 'An unquoted PostgreSQL directory was parsed incorrectly.'
$nearbyData = Get-PostgresDataDirectory '"C:/runtime/postgres.exe" -D "C:/StockNest portable test/data/pgdata-backup" -h 127.0.0.1'
Assert-True (-not [string]::Equals($nearbyData, $expectedData, [StringComparison]::OrdinalIgnoreCase)) 'A nearby database folder was accepted as the same directory.'
Assert-True ($null -eq (Get-PostgresDataDirectory '"C:/StockNest -D fake/runtime/postgres.exe" -h 127.0.0.1')) 'A -D substring inside a quoted folder was accepted.'
Assert-True ($null -eq (Get-PostgresDataDirectory 'postgres.exe -D "C:/first" -D "C:/second"')) 'An ambiguous database directory was accepted.'
Assert-True ($null -eq (Get-PostgresDataDirectory 'postgres.exe -D data/pgdata')) 'A relative database directory was accepted.'
$available = Get-AvailablePort 8765
Assert-True (Test-PortAvailable $available) 'Selected port is not available on loopback.'
$listener = New-Object Net.Sockets.TcpListener([Net.IPAddress]::Loopback, $available)
try {
    $listener.Start()
    $replacement = Get-AvailablePort $available
    Assert-True ($replacement -ne $available) 'An occupied port was selected.'
} finally { $listener.Stop() }

$temporaryRoot = Join-Path $projectRoot ('.tools\portable launcher test ' + [Guid]::NewGuid().ToString('N'))
[IO.Directory]::CreateDirectory($temporaryRoot) | Out-Null
$utf8 = New-Object Text.UTF8Encoding($false)
$dataRoot = $temporaryRoot
$pgData = Join-Path $dataRoot 'pgdata'
$environmentPath = Join-Path $dataRoot '.env'
$passwordPath = Join-Path $dataRoot '.postgres-password'
$phpExe = Join-Path $dataRoot 'runtime\php\php.exe'
$pgBin = Join-Path $dataRoot 'runtime\postgresql\bin'
$state = [pscustomobject] @{ webPort = 8765; dbPort = 54329 }
$environmentBefore = @{}
foreach ($item in Get-ChildItem Env:) { $environmentBefore[$item.Name] = $item.Value }
try {
    [IO.File]::WriteAllText($environmentPath, "STOCKNEST_ORGANIZATION=Test office`r`n", $utf8)
    Update-Environment
    $firstEnvironment = [IO.File]::ReadAllText($environmentPath)
    $firstPassword = [IO.File]::ReadAllText($passwordPath)
    Update-Environment
    Assert-True ([IO.File]::ReadAllText($environmentPath) -ceq $firstEnvironment) 'Restart changed the environment or application key.'
    Assert-True ([IO.File]::ReadAllText($passwordPath) -ceq $firstPassword) 'Restart changed the database password.'
    Assert-True ($firstEnvironment.Contains('STOCKNEST_ORGANIZATION=Test office')) 'Organization settings were overwritten.'
    Assert-True ($firstEnvironment.Contains('APP_DEBUG=false')) 'Portable mode exposed debugging.'
    Assert-True ($firstEnvironment.Contains('DB_HOST=127.0.0.1')) 'Database is not restricted to loopback.'
    $state.webPort = 8766
    Update-Environment
    $updatedEnvironment = [IO.File]::ReadAllText($environmentPath)
    Assert-True ($updatedEnvironment.Contains('APP_URL=http://127.0.0.1:8766')) 'Changed web port was not persisted.'
    $firstKey = [regex]::Match($firstEnvironment, '(?m)^APP_KEY=([^\r\n]+)').Groups[1].Value
    $updatedKey = [regex]::Match($updatedEnvironment, '(?m)^APP_KEY=([^\r\n]+)').Groups[1].Value
    Assert-True ($firstKey -ceq $updatedKey) 'Changing ports replaced the encryption key.'
} finally {
    foreach ($item in @(Get-ChildItem Env:)) {
        if (-not $environmentBefore.ContainsKey($item.Name)) { [Environment]::SetEnvironmentVariable($item.Name, $null, 'Process') }
    }
    foreach ($entry in $environmentBefore.GetEnumerator()) { [Environment]::SetEnvironmentVariable($entry.Key, $entry.Value, 'Process') }
    $allowedRoot = [IO.Path]::GetFullPath((Join-Path $projectRoot '.tools')) + [IO.Path]::DirectorySeparatorChar
    $resolvedTemporary = [IO.Path]::GetFullPath($temporaryRoot)
    if (-not $resolvedTemporary.StartsWith($allowedRoot, [StringComparison]::OrdinalIgnoreCase)) { throw 'Temporary cleanup path is outside the test workspace.' }
    Remove-Item -LiteralPath $resolvedTemporary -Recurse -Force
}
Write-Host 'Launcher checks passed: syntax, Windows paths, occupied ports, persistent secrets and settings.'

if ([string]::IsNullOrWhiteSpace($ReleasePath)) { exit 0 }
$release = [IO.Path]::GetFullPath($ReleasePath)
$launcher = Join-Path $release 'launcher.ps1'
$releaseData = Join-Path $release 'data'
if (-not (Test-Path -LiteralPath $launcher -PathType Leaf)) { throw 'ReleasePath must point to an extracted portable release.' }
if (Test-Path -LiteralPath $releaseData) { throw 'Lifecycle smoke testing requires a disposable release with no existing data folder.' }
$powershellExe = Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe'
$sentinel = $null
try {
    & $powershellExe -NoLogo -NoProfile -NonInteractive -ExecutionPolicy Bypass -File $launcher -Action Start -NoPause -NoBrowser
    Assert-True ($LASTEXITCODE -eq 0) 'First launch failed.'
    $firstState = [IO.File]::ReadAllText((Join-Path $releaseData 'state.json')) | ConvertFrom-Json
    $savedEnvironment = [IO.File]::ReadAllText((Join-Path $releaseData '.env'))
    $savedPassword = [IO.File]::ReadAllText((Join-Path $releaseData '.postgres-password'))
    $health = Invoke-WebRequest -Uri "http://127.0.0.1:$($firstState.webPort)/up" -UseBasicParsing
    Assert-True ($health.StatusCode -eq 200) 'Application health request failed.'
    $setup = Invoke-WebRequest -Uri "http://127.0.0.1:$($firstState.webPort)/setup" -UseBasicParsing
    Assert-True ($setup.StatusCode -eq 200) 'First administrator setup page failed.'
    $postgresId = [int]([IO.File]::ReadAllLines((Join-Path $releaseData 'pgdata/postmaster.pid'))[0])
    foreach ($processId in @([int]$firstState.processes.web.pid, $postgresId)) {
        $runtimeModules = @((Get-Process -Id $processId).Modules | Where-Object { $_.ModuleName -match '^(vcruntime140|msvcp140)' })
        Assert-True ($runtimeModules.Count -gt 0) 'Expected C++ runtime modules were not loaded.'
        foreach ($module in $runtimeModules) {
            Assert-True ($module.FileName.StartsWith((Join-Path $release 'runtime') + '\', [StringComparison]::OrdinalIgnoreCase)) 'A service used a machine-installed C++ runtime instead of the bundled library.'
        }
    }

    & $powershellExe -NoLogo -NoProfile -NonInteractive -ExecutionPolicy Bypass -File $launcher -Action Start -NoPause -NoBrowser
    Assert-True ($LASTEXITCODE -eq 0) 'Repeated launch failed.'
    $secondState = [IO.File]::ReadAllText((Join-Path $releaseData 'state.json')) | ConvertFrom-Json
    foreach ($kind in @('web', 'queue', 'scheduler')) {
        Assert-True ($firstState.processes.$kind.pid -eq $secondState.processes.$kind.pid) "Repeated launch duplicated the $kind service."
    }
    $sentinelExe = Join-Path $release 'runtime\php\php.exe'
    $sentinel = Start-Process -FilePath $sentinelExe -ArgumentList '-n -r "sleep(180);"' -WindowStyle Hidden -PassThru
    & $powershellExe -NoLogo -NoProfile -NonInteractive -ExecutionPolicy Bypass -File $launcher -Action Stop -NoPause
    Assert-True ($LASTEXITCODE -eq 0) 'Stop failed.'
    Assert-True (-not $sentinel.HasExited) 'Stop affected an unrelated PHP process.'
    & $powershellExe -NoLogo -NoProfile -NonInteractive -ExecutionPolicy Bypass -File $launcher -Action Start -NoPause -NoBrowser
    Assert-True ($LASTEXITCODE -eq 0) 'Restart after a clean stop failed.'
    Assert-True ([IO.File]::ReadAllText((Join-Path $releaseData '.env')) -ceq $savedEnvironment) 'Restart changed saved application settings.'
    Assert-True ([IO.File]::ReadAllText((Join-Path $releaseData '.postgres-password')) -ceq $savedPassword) 'Restart changed the database password.'
    Write-Host 'Portable lifecycle passed: first setup, repeat start, clean stop, restart, persistence and unrelated-process protection.'
} finally {
    & $powershellExe -NoLogo -NoProfile -NonInteractive -ExecutionPolicy Bypass -File $launcher -Action Stop -NoPause
    if ($null -ne $sentinel) {
        if (-not $sentinel.HasExited) { $sentinel.Kill(); $sentinel.WaitForExit() }
        $sentinel.Dispose()
    }
}
