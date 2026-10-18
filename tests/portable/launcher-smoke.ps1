[CmdletBinding()]
param([string] $ReleasePath)

# With no argument this checks the launcher without starting application services.
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

function Assert-PrivatePath([string] $Path) {
    $acl = Get-Acl -LiteralPath $Path
    Assert-True $acl.AreAccessRulesProtected 'Private data still inherits permissions from its parent folder.'
    $allowed = @([Security.Principal.WindowsIdentity]::GetCurrent().User.Value, 'S-1-5-18', 'S-1-5-32-544')
    $rules = @($acl.GetAccessRules($true, $true, [Security.Principal.SecurityIdentifier]))
    Assert-True ($rules.Count -gt 0) 'The private path has an empty access list.'
    foreach ($rule in $rules) {
        Assert-True ($allowed -contains $rule.IdentityReference.Value) 'Another Windows account can access portable private data.'
        Assert-True (-not $rule.IsInherited) 'Private data has an inherited permission rule.'
    }
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

# Model a stale CIM row after queue:restart without relying on the race timing.
# The OS liveness check uses real exited/live PIDs, while the CIM row is fixed.
$originalIdentity = (Get-Item Function:\Get-ProcessIdentity).ScriptBlock
$exitedProcess = $null
$appRoot = Join-Path $projectRoot '.tools\identity fixture\app'
$phpExe = Join-Path $projectRoot '.tools\identity fixture\runtime\php\php.exe'
try {
    function Get-ProcessIdentity([int] $ProcessId) { return $script:identityFixture }
    $exitShell = Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe'
    $exitedProcess = Start-Process -FilePath $exitShell -ArgumentList '-NoLogo -NoProfile -NonInteractive -Command "exit 0"' -WindowStyle Hidden -PassThru
    Assert-True ($exitedProcess.WaitForExit(10000)) 'The process exit fixture did not finish.'
    $identityFixture = [pscustomobject] @{ ExecutablePath = ''; CommandLine = ''; CreationDate = [DateTime]::UtcNow }
    $record = [pscustomobject] @{ pid = $exitedProcess.Id; commandLine = ''; createdAt = '' }
    Assert-True (-not (Test-OwnedPhpProcess $record 'queue')) 'An exited worker with a stale blank CIM identity prevented shutdown.'

    $record.pid = $PID
    $refused = $false
    try { Test-OwnedPhpProcess $record 'queue' | Out-Null } catch {
        $refused = $_.Exception.Message -like 'Cannot verify the previous queue process.*'
    }
    Assert-True $refused 'A live process with an unverifiable identity was accepted.'

    $identityFixture.ExecutablePath = $phpExe
    $identityFixture.CommandLine = '"' + $phpExe + '" "' + (Join-Path $appRoot 'artisan') + '" queue:work'
    $record.commandLine = $identityFixture.CommandLine
    $record.createdAt = $identityFixture.CreationDate.ToUniversalTime().ToString('o')
    Assert-True (Test-OwnedPhpProcess $record 'queue') 'An exact recorded PHP identity was rejected.'
    $identityFixture.ExecutablePath = $phpExe + '.other'
    Assert-True (-not (Test-OwnedPhpProcess $record 'queue')) 'An executable path mismatch was accepted.'
    $identityFixture.ExecutablePath = $phpExe
    $record.commandLine += ' --different'
    Assert-True (-not (Test-OwnedPhpProcess $record 'queue')) 'A command line mismatch was accepted.'
    $record.commandLine = $identityFixture.CommandLine
    $record.createdAt = $identityFixture.CreationDate.AddSeconds(-1).ToUniversalTime().ToString('o')
    Assert-True (-not (Test-OwnedPhpProcess $record 'queue')) 'A reused PID with a different creation time was accepted.'
    $record.createdAt = $identityFixture.CreationDate.ToUniversalTime().ToString('o')
    $identityFixture.CommandLine = '"' + $phpExe + '" -r "sleep(30);"'
    $record.commandLine = $identityFixture.CommandLine
    Assert-True (-not (Test-OwnedPhpProcess $record 'queue')) 'An unrelated PHP command without the application marker was accepted.'
    function Get-ProcessIdentity([int] $ProcessId) { throw 'Process inspection fixture denied access.' }
    $inspectionFailed = $false
    try { Test-OwnedPhpProcess $record 'queue' | Out-Null } catch {
        $inspectionFailed = $_.Exception.Message -eq 'Process inspection fixture denied access.'
    }
    Assert-True $inspectionFailed 'An inspection failure was treated as an exited process.'
} finally {
    Set-Item Function:\Get-ProcessIdentity $originalIdentity
    if ($null -ne $exitedProcess) { $exitedProcess.Dispose() }
}

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
    Assert-PrivatePath $dataRoot
    Assert-PrivatePath $environmentPath
    Assert-PrivatePath $passwordPath
    # Simulate a pre-hardening file with an explicit broad access grant. A later
    # launch must repair BOTH copies of the database credential, not just new files.
    foreach ($path in @($environmentPath, $passwordPath)) {
        $weakAcl = Get-Acl -LiteralPath $path
        $weakAcl.AddAccessRule((New-Object Security.AccessControl.FileSystemAccessRule((New-Object Security.Principal.SecurityIdentifier('S-1-1-0')), 'Read', 'Allow')))
        # Change only the DACL on Windows PowerShell 5.1. Passing Get-Acl's
        # complete descriptor to Set-Acl can require SeSecurityPrivilege even
        # though this regression fixture is only adding an ordinary read grant.
        $weakAccessOnly = New-Object Security.AccessControl.FileSecurity
        $weakAccessOnly.SetSecurityDescriptorSddlForm($weakAcl.GetSecurityDescriptorSddlForm([Security.AccessControl.AccessControlSections]::Access), [Security.AccessControl.AccessControlSections]::Access)
        [IO.File]::SetAccessControl($path, $weakAccessOnly)
    }
    Update-Environment
    Assert-PrivatePath $environmentPath
    Assert-PrivatePath $passwordPath
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
Write-Host 'Launcher checks passed: syntax, Windows paths, exited-process races, live identity refusals, occupied ports, private credential ACLs and persistent settings.'

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
