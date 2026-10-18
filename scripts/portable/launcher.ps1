[CmdletBinding()]
param(
    [ValidateSet('Start', 'Stop')]
    [string] $Action = 'Start',
    [switch] $NoBrowser,
    [switch] $NoPause
)

# Windows PowerShell 5.1 is included with supported Windows 10/11 computers.
# This file lives at the root of the extracted portable release, beside app/,
# runtime/, and (after the first start) data/. All changes stay in that folder.
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$releaseRoot = [IO.Path]::GetFullPath($PSScriptRoot)
$appRoot = Join-Path $releaseRoot 'app'
$dataRoot = Join-Path $releaseRoot 'data'
$phpExe = Join-Path $releaseRoot 'runtime\php\php.exe'
$postgresRoot = Join-Path $releaseRoot 'runtime\postgresql'
$pgBin = Join-Path $postgresRoot 'bin'
$pgData = Join-Path $dataRoot 'pgdata'
$logRoot = Join-Path $dataRoot 'logs'
$statePath = Join-Path $dataRoot 'state.json'
$passwordPath = Join-Path $dataRoot '.postgres-password'
$environmentPath = Join-Path $dataRoot '.env'
$launcherLock = $null
$state = $null
$utf8 = New-Object Text.UTF8Encoding($false)

function Protect-PortablePath([string] $Path, [switch] $Directory) {
    # Use SIDs rather than translated account names (which vary by Windows
    # language). Data remains readable by the owning Windows account and the
    # computer's administrators/system, never by all local Windows users.
    $owner = [Security.Principal.WindowsIdentity]::GetCurrent().User
    # Read the existing descriptor so Windows PowerShell 5.1 changes only its
    # DACL. A fresh FileSecurity descriptor can attempt to change auditing too,
    # which requires SeSecurityPrivilege and would break ordinary user accounts.
    $acl = Get-Acl -LiteralPath $Path
    if ($Directory) {
        $inheritance = [Security.AccessControl.InheritanceFlags]::ContainerInherit -bor [Security.AccessControl.InheritanceFlags]::ObjectInherit
    } else {
        $inheritance = [Security.AccessControl.InheritanceFlags]::None
    }
    $acl.SetAccessRuleProtection($true, $false)
    foreach ($rule in @($acl.GetAccessRules($true, $false, [Security.Principal.SecurityIdentifier]))) { $acl.RemoveAccessRuleSpecific($rule) }
    foreach ($sid in @($owner, (New-Object Security.Principal.SecurityIdentifier('S-1-5-18')), (New-Object Security.Principal.SecurityIdentifier('S-1-5-32-544')))) {
        $rule = New-Object Security.AccessControl.FileSystemAccessRule($sid, [Security.AccessControl.FileSystemRights]::FullControl, $inheritance, [Security.AccessControl.PropagationFlags]::None, [Security.AccessControl.AccessControlType]::Allow)
        $acl.SetAccessRule($rule)
    }
    try {
        if ($Directory) { $accessOnly = New-Object Security.AccessControl.DirectorySecurity }
        else { $accessOnly = New-Object Security.AccessControl.FileSecurity }
        $accessOnly.SetSecurityDescriptorSddlForm($acl.GetSecurityDescriptorSddlForm([Security.AccessControl.AccessControlSections]::Access), [Security.AccessControl.AccessControlSections]::Access)
        if ($Directory) { [IO.Directory]::SetAccessControl($Path, $accessOnly) }
        else { [IO.File]::SetAccessControl($Path, $accessOnly) }
        if (-not (Get-Acl -LiteralPath $Path).AreAccessRulesProtected) { throw 'Permission inheritance was not disabled.' }
    } catch {
        throw (New-Object InvalidOperationException('StockNest cannot protect its private data. Extract the stopped-folder ZIP into an NTFS folder owned by your Windows account. See the portable guide for moving to another PC.', $_.Exception))
    }
}

function Write-PlainFile([string] $Path, [string] $Content, [switch] $Private) {
    $temporary = $Path + '.' + [Guid]::NewGuid().ToString('N') + '.tmp'
    [IO.File]::WriteAllText($temporary, $Content, $script:utf8)
    if ($Private) { Protect-PortablePath $temporary }
    Move-Item -LiteralPath $temporary -Destination $Path -Force
    if ($Private) { Protect-PortablePath $Path }
}

function New-RandomSecret([int] $Bytes = 32) {
    $buffer = New-Object byte[] $Bytes
    $generator = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $generator.GetBytes($buffer) } finally { $generator.Dispose() }
    return $buffer
}

# ProcessStartInfo and Start-Process ultimately pass a Windows command line.
# Apply the Windows argv quoting rules, including trailing backslashes, instead
# of interpolating paths or arguments into cmd.exe or another PowerShell call.
function ConvertTo-ProcessArgument([string] $Value) {
    if ($Value.Length -gt 0 -and $Value -notmatch '[\s"]') { return $Value }
    $escaped = [regex]::Replace($Value, '(\\*)"', '$1$1\"')
    $escaped = [regex]::Replace($escaped, '(\\+)$', '$1$1')
    return '"' + $escaped + '"'
}

function Join-ProcessArguments([string[]] $Arguments) {
    return (($Arguments | ForEach-Object { ConvertTo-ProcessArgument $_ }) -join ' ')
}

function Get-PostgresDataDirectory([string] $CommandLine) {
    # pg_ctl rewrites Windows paths with forward slashes. Tokenize the actual
    # command line before reading -D, so spaces, quoted paths, and folder names
    # containing " -D " cannot turn a prefix match into process ownership.
    $arguments = New-Object 'Collections.Generic.List[string]'
    $offset = 0
    while ($offset -lt $CommandLine.Length) {
        while ($offset -lt $CommandLine.Length -and [char]::IsWhiteSpace($CommandLine[$offset])) { $offset++ }
        if ($offset -ge $CommandLine.Length) { break }
        $value = New-Object Text.StringBuilder
        $quoted = $false
        while ($offset -lt $CommandLine.Length) {
            $character = $CommandLine[$offset]
            if (-not $quoted -and [char]::IsWhiteSpace($character)) { break }
            if ($character -eq '\') {
                $slashes = 0
                while ($offset -lt $CommandLine.Length -and $CommandLine[$offset] -eq '\') { $slashes++; $offset++ }
                if ($offset -lt $CommandLine.Length -and $CommandLine[$offset] -eq '"') {
                    [void] $value.Append(('\' * [int] [Math]::Floor($slashes / 2)))
                    if ($slashes % 2 -eq 1) { [void] $value.Append('"') }
                    else { $quoted = -not $quoted }
                    $offset++
                } else { [void] $value.Append(('\' * $slashes)) }
                continue
            }
            if ($character -eq '"') { $quoted = -not $quoted }
            else { [void] $value.Append($character) }
            $offset++
        }
        if ($quoted) { return $null }
        $arguments.Add($value.ToString())
    }
    $directory = $null
    for ($index = 1; $index -lt $arguments.Count; $index++) {
        if ($arguments[$index] -ceq '-D') {
            if ($null -ne $directory -or $index + 1 -ge $arguments.Count) { return $null }
            $index++
            $directory = $arguments[$index]
        }
    }
    if ([string]::IsNullOrWhiteSpace($directory) -or -not [IO.Path]::IsPathRooted($directory)) { return $null }
    try { return [IO.Path]::GetFullPath($directory).TrimEnd('\', '/') }
    catch { return $null }
}

function Invoke-LoggedProcess {
    param(
        [string] $Executable,
        [string[]] $Arguments,
        [string] $Label,
        [int[]] $AllowedExitCodes = @(0),
        [int] $TimeoutSeconds = 180
    )
    $info = New-Object Diagnostics.ProcessStartInfo
    $info.FileName = $Executable
    $info.Arguments = Join-ProcessArguments $Arguments
    $info.WorkingDirectory = $script:appRoot
    $info.UseShellExecute = $false
    $info.CreateNoWindow = $true
    # pg_ctl starts a detached server that inherits its standard handles. A
    # redirected pipe would remain open for the entire lifetime of PostgreSQL,
    # so waiting for ReadToEnd would hang after pg_ctl itself has exited.
    # PostgreSQL already writes its diagnostics to the explicit -l log file.
    $captureOutput = -not [string]::Equals([IO.Path]::GetFileName($Executable), 'pg_ctl.exe', [StringComparison]::OrdinalIgnoreCase)
    $info.RedirectStandardOutput = $captureOutput
    $info.RedirectStandardError = $captureOutput
    $process = New-Object Diagnostics.Process
    $process.StartInfo = $info
    try {
        if (-not $process.Start()) { throw "$Label could not start." }
        if ($captureOutput) {
            $outputTask = $process.StandardOutput.ReadToEndAsync()
            $errorTask = $process.StandardError.ReadToEndAsync()
        }
        if (-not $process.WaitForExit($TimeoutSeconds * 1000)) {
            # This object owns the exact child that was just started.
            $process.Kill()
            $process.WaitForExit()
            throw "$Label took too long. Diagnostic information is in data\logs."
        }
        $output = ''
        $errors = ''
        if ($captureOutput) {
            $output = $outputTask.GetAwaiter().GetResult()
            $errors = $errorTask.GetAwaiter().GetResult()
        } else {
            $errors = 'PostgreSQL diagnostics: data\logs\postgresql.log' + "`r`n"
        }
        $exitCode = $process.ExitCode
        $entry = "`r`n[$([DateTime]::Now.ToString('s'))] $Label (exit $exitCode)`r`n$output$errors"
        [IO.File]::AppendAllText((Join-Path $script:logRoot 'launcher.log'), $entry, $script:utf8)
        if ($AllowedExitCodes -notcontains $exitCode) {
            throw "$Label failed. See data\logs\launcher.log for details."
        }
        return [pscustomobject] @{ ExitCode = $exitCode; Output = $output }
    } finally {
        $process.Dispose()
    }
}

function Save-State {
    Write-PlainFile $script:statePath ($script:state | ConvertTo-Json -Depth 8)
}

function Get-ProcessIdentity([int] $ProcessId) {
    return Get-CimInstance Win32_Process -Filter "ProcessId = $ProcessId" -ErrorAction Stop
}

function Test-OwnedPhpProcess([object] $Record, [string] $Kind) {
    if ($null -eq $Record) { return $false }
    $process = Get-ProcessIdentity ([int] $Record.pid)
    if ($null -eq $process) { return $false }
    if ([string]::IsNullOrWhiteSpace([string] $process.ExecutablePath)) {
        throw "Cannot verify the previous $Kind process. Close StockNest and try again."
    }
    if (-not [string]::Equals([string] $process.ExecutablePath, $script:phpExe, [StringComparison]::OrdinalIgnoreCase)) { return $false }
    if ([string] $process.CommandLine -cne [string] $Record.commandLine) { return $false }
    if ($process.CreationDate.ToUniversalTime().ToString('o') -cne [string] $Record.createdAt) { return $false }
    $marker = Join-Path $script:appRoot 'artisan'
    if ($Kind -eq 'web') { $marker = Join-Path $script:appRoot 'vendor\laravel\framework\src\Illuminate\Foundation\resources\server.php' }
    if ([string] $process.CommandLine -notlike ('*' + [WildcardPattern]::Escape($marker) + '*')) { return $false }
    return $true
}

function Start-PhpProcess([string] $Kind, [string[]] $Arguments, [string] $WorkingDirectory) {
    $record = $script:state.processes.$Kind
    if (Test-OwnedPhpProcess $record $Kind) { return }
    $stamp = [DateTime]::Now.ToString('yyyyMMdd-HHmmss') + '-' + [Guid]::NewGuid().ToString('N').Substring(0, 8)
    $outputPath = Join-Path $script:logRoot "$Kind-$stamp.log"
    $errorPath = Join-Path $script:logRoot "$Kind-$stamp-error.log"
    $process = Start-Process -FilePath $script:phpExe -ArgumentList (Join-ProcessArguments $Arguments) `
        -WorkingDirectory $WorkingDirectory -WindowStyle Hidden -PassThru `
        -RedirectStandardOutput $outputPath -RedirectStandardError $errorPath
    try {
        Start-Sleep -Milliseconds 250
        $identity = Get-ProcessIdentity $process.Id
        if ($null -eq $identity -or $process.HasExited) {
            throw "StockNest's $Kind service could not start. See data\logs."
        }
        $script:state.processes.$Kind = [pscustomobject] @{
            pid = $process.Id
            commandLine = [string] $identity.CommandLine
            createdAt = $identity.CreationDate.ToUniversalTime().ToString('o')
        }
        Save-State
    } finally {
        $process.Dispose()
    }
}

function Stop-OwnedPhpProcess([string] $Kind) {
    $record = $script:state.processes.$Kind
    if (Test-OwnedPhpProcess $record $Kind) {
        # Revalidate immediately before stopping: PIDs can be recycled.
        if (Test-OwnedPhpProcess $record $Kind) {
            Stop-Process -Id ([int] $record.pid) -Force -ErrorAction SilentlyContinue
        }
    }
    $script:state.processes.$Kind = $null
    Save-State
}

function Get-OwnedPostgresProcess {
    $pidPath = Join-Path $script:pgData 'postmaster.pid'
    if (-not (Test-Path -LiteralPath $pidPath -PathType Leaf)) { return $null }
    $lines = [IO.File]::ReadAllLines($pidPath)
    if ($lines.Length -lt 4 -or $lines[0] -notmatch '^\d+$') {
        throw 'The database status file is incomplete. See data\logs; keep the data folder.'
    }
    $process = Get-ProcessIdentity ([int] $lines[0])
    if ($null -eq $process) { return $null }
    $expected = Join-Path $script:pgBin 'postgres.exe'
    $actualDirectory = Get-PostgresDataDirectory ([string] $process.CommandLine)
    if (-not [string]::Equals([string] $process.ExecutablePath, $expected, [StringComparison]::OrdinalIgnoreCase) -or
        -not [string]::Equals($actualDirectory, $script:pgData.TrimEnd('\', '/'), [StringComparison]::OrdinalIgnoreCase)) {
        throw 'A database PID belongs to another process. StockNest will not stop or reuse it.'
    }
    return [pscustomobject] @{ pid = $process.ProcessId; port = [int] $lines[3] }
}

function Test-PortAvailable([int] $Port) {
    if ($Port -lt 1024 -or $Port -gt 65535) { return $false }
    $listener = New-Object Net.Sockets.TcpListener([Net.IPAddress]::Loopback, $Port)
    try {
        $listener.Server.ExclusiveAddressUse = $true
        $listener.Start()
        return $true
    } catch [Net.Sockets.SocketException] {
        return $false
    } finally {
        $listener.Stop()
    }
}

function Get-AvailablePort([int] $Preferred, [int] $Avoid = 0) {
    if ($Preferred -ne $Avoid -and (Test-PortAvailable $Preferred)) { return $Preferred }
    for ($attempt = 0; $attempt -lt 20; $attempt++) {
        $listener = New-Object Net.Sockets.TcpListener([Net.IPAddress]::Loopback, 0)
        try {
            $listener.Start()
            $port = ([Net.IPEndPoint] $listener.LocalEndpoint).Port
        } finally { $listener.Stop() }
        if ($port -ne $Avoid -and $port -ge 1024) { return $port }
    }
    throw 'Could not find a free local port. Close another local server and try again.'
}

function Update-Environment {
    Protect-PortablePath $script:dataRoot -Directory
    $contents = ''
    if (Test-Path -LiteralPath $script:environmentPath -PathType Leaf) {
        Protect-PortablePath $script:environmentPath
        $contents = [IO.File]::ReadAllText($script:environmentPath)
    }
    if ($contents.Length -gt 0 -and -not $contents.EndsWith("`n")) { $contents += "`r`n" }
    $keyMatch = [regex]::Match($contents, '(?m)^APP_KEY=(?:"?)(base64:[A-Za-z0-9+/=]+)(?:"?)\s*$')
    if ($keyMatch.Success) { $key = $keyMatch.Groups[1].Value }
    elseif ([regex]::IsMatch($contents, '(?m)^APP_KEY=\S+')) {
        throw 'The existing data\.env APP_KEY is not a supported key. Keep it and check the installation instructions.'
    } else { $key = 'base64:' + [Convert]::ToBase64String((New-RandomSecret 32)) }
    if (Test-Path -LiteralPath $script:passwordPath -PathType Leaf) {
        Protect-PortablePath $script:passwordPath
        $password = [IO.File]::ReadAllText($script:passwordPath).Trim()
        if ($password -notmatch '^[a-f0-9]{64}$') { throw 'The saved database password is invalid. Keep the data folder and restore it from your backup.' }
    } else {
        if (Test-Path -LiteralPath (Join-Path $script:pgData 'PG_VERSION')) {
            throw 'The database password file is missing. Keep the data folder and restore .postgres-password from your backup.'
        }
        $password = -join ((New-RandomSecret 32) | ForEach-Object { $_.ToString('x2') })
        Write-PlainFile $script:passwordPath ($password + "`r`n") -Private
    }
    $settings = [ordered] @{
        APP_NAME = 'StockNest'; APP_ENV = 'production'; APP_KEY = $key; APP_DEBUG = 'false'
        APP_URL = "http://127.0.0.1:$($script:state.webPort)"; APP_TIMEZONE = 'Asia/Manila'
        APP_LOCALE = 'en'; APP_FALLBACK_LOCALE = 'en'; APP_MAINTENANCE_DRIVER = 'file'
        STOCKNEST_PORTABLE = 'true'; STOCKNEST_CURRENCY = 'PHP'; STOCKNEST_DEMO = 'false'
        STOCKNEST_NOTIFICATION_EMAIL = 'false'; LOG_CHANNEL = 'single'; LOG_LEVEL = 'warning'
        DB_CONNECTION = 'pgsql'; DB_HOST = '127.0.0.1'; DB_PORT = [string] $script:state.dbPort
        DB_DATABASE = 'stocknest'; DB_USERNAME = 'stocknest'; DB_PASSWORD = $password; DB_SSLMODE = 'disable'; DB_URL = ''
        SESSION_DRIVER = 'database'; SESSION_LIFETIME = '120'; SESSION_ENCRYPT = 'true'
        SESSION_DOMAIN = 'null'; SESSION_SECURE_COOKIE = 'false'; QUEUE_CONNECTION = 'database'
        CACHE_STORE = 'database'; FILESYSTEM_DISK = 'local'; MAIL_MAILER = 'log'; BROADCAST_CONNECTION = 'log'
    }
    foreach ($entry in $settings.GetEnumerator()) {
        $pattern = '(?m)^' + [regex]::Escape($entry.Key) + '=[^\r\n]*'
        $line = $entry.Key + '=' + $entry.Value
        if ([regex]::IsMatch($contents, $pattern)) { $contents = [regex]::Replace($contents, $pattern, [Text.RegularExpressions.MatchEvaluator] { param($match) $line }) }
        else { $contents += $line + "`r`n" }
        [Environment]::SetEnvironmentVariable($entry.Key, [string] $entry.Value, 'Process')
    }
    if (-not [regex]::IsMatch($contents, '(?m)^STOCKNEST_ORGANIZATION=')) { $contents += "STOCKNEST_ORGANIZATION=StockNest`r`n" }
    Write-PlainFile $script:environmentPath $contents -Private
    $env:PGPASSWORD = $password
    $env:PGSSLMODE = 'disable'
    $env:STOCKNEST_DATA_DIR = $script:dataRoot
    $env:ASSET_URL = ''
    $env:PATH = (Split-Path -Parent $script:phpExe) + ';' + $script:pgBin + ';' + $env:PATH
    foreach ($pair in @{
        APP_CONFIG_CACHE = 'config.php'; APP_PACKAGES_CACHE = 'packages.php'; APP_SERVICES_CACHE = 'services.php'
        APP_ROUTES_CACHE = 'routes-v7.php'; APP_EVENTS_CACHE = 'events.php'
    }.GetEnumerator()) {
        [Environment]::SetEnvironmentVariable($pair.Key, (Join-Path $script:dataRoot ('cache\' + $pair.Value)), 'Process')
    }
    $env:VIEW_COMPILED_PATH = Join-Path $script:dataRoot 'storage\framework\views'
}

function Write-PhpConfiguration {
    $extensionDirectory = (Join-Path (Split-Path -Parent $script:phpExe) 'ext').Replace('\', '/')
    $temporaryDirectory = (Join-Path $script:dataRoot 'tmp').Replace('\', '/')
    $errorLog = (Join-Path $script:logRoot 'php-error.log').Replace('\', '/')
    $configuration = @"
; Generated locally by StockNest. No machine-wide PHP settings are changed.
[PHP]
extension_dir="$extensionDirectory"
extension=curl
extension=fileinfo
extension=gd
extension=intl
extension=mbstring
extension=openssl
extension=pdo_pgsql
extension=pgsql
extension=sodium
extension=zip
zend_extension=php_opcache.dll
date.timezone=Asia/Manila
memory_limit=256M
max_execution_time=0
display_errors=Off
display_startup_errors=Off
log_errors=On
error_log="$errorLog"
expose_php=Off
variables_order=EGPCS
upload_max_filesize=32M
post_max_size=34M
sys_temp_dir="$temporaryDirectory"
upload_tmp_dir="$temporaryDirectory"
session.save_path="$temporaryDirectory"
opcache.enable=1
opcache.enable_cli=1
opcache.validate_timestamps=1
opcache.revalidate_freq=0
opcache.memory_consumption=128
"@
    $iniPath = Join-Path $script:dataRoot 'php.ini'
    Write-PlainFile $iniPath $configuration
    $env:PHPRC = $iniPath
    # Avoid picking up unrelated PHP extension configuration from the computer.
    $env:PHP_INI_SCAN_DIR = Join-Path $script:dataRoot 'php-conf.d'
}

function Wait-ForWeb {
    $address = "http://127.0.0.1:$($script:state.webPort)/up"
    for ($attempt = 0; $attempt -lt 60; $attempt++) {
        if (-not (Test-OwnedPhpProcess $script:state.processes.web 'web')) { throw 'The web service stopped. See data\logs.' }
        try {
            $response = Invoke-WebRequest -Uri $address -UseBasicParsing -TimeoutSec 2
            if ($response.StatusCode -eq 200) { return }
        } catch { }
        Start-Sleep -Milliseconds 500
    }
    throw 'StockNest did not become ready. See data\logs; use Stop StockNest before trying again.'
}

try {
    if (-not [Environment]::Is64BitOperatingSystem) { throw 'This portable package needs 64-bit Windows 10 or 11.' }
    [IO.Directory]::CreateDirectory($dataRoot) | Out-Null
    Protect-PortablePath $dataRoot -Directory
    [IO.Directory]::CreateDirectory($logRoot) | Out-Null
    for ($attempt = 0; $attempt -lt 180; $attempt++) {
        try {
            $launcherLock = [IO.File]::Open((Join-Path $dataRoot 'launcher.lock'), [IO.FileMode]::OpenOrCreate, [IO.FileAccess]::ReadWrite, [IO.FileShare]::None)
            break
        } catch [IO.IOException] {
            if ($attempt -eq 0) { Write-Host 'StockNest is already starting or stopping. Waiting for it to finish...' }
            Start-Sleep -Milliseconds 500
        }
    }
    if ($null -eq $launcherLock) { throw 'Another StockNest launcher is still working. Wait for that window to finish, then try again.' }
    if (Test-Path -LiteralPath $statePath -PathType Leaf) {
        $state = [IO.File]::ReadAllText($statePath) | ConvertFrom-Json
        if ($state.version -ne 1) { throw 'The saved launcher state uses an unsupported version. Keep the data folder.' }
    } else {
        $state = [pscustomobject] @{
            version = 1; webPort = 8765; dbPort = 54329
            processes = [pscustomobject] @{ web = $null; queue = $null; scheduler = $null }
        }
    }
    if ($Action -eq 'Stop') {
        Write-Host 'Stopping StockNest...'
        Stop-OwnedPhpProcess 'web'
        Stop-OwnedPhpProcess 'scheduler'
        # The worker checks this marker between jobs. Allow its active job to finish.
        if (Test-OwnedPhpProcess $state.processes.queue 'queue') {
            Write-Host 'Finishing background work before closing the database...'
            Update-Environment
            Write-PhpConfiguration
            try { Invoke-LoggedProcess $phpExe @((Join-Path $appRoot 'artisan'), 'queue:restart', '--no-interaction') 'Finish background work' -TimeoutSeconds 15 | Out-Null } catch { }
            for ($attempt = 0; $attempt -lt 190; $attempt++) {
                if (-not (Test-OwnedPhpProcess $state.processes.queue 'queue')) { break }
                Start-Sleep -Milliseconds 500
            }
        }
        Stop-OwnedPhpProcess 'queue'
        if ($null -ne (Get-OwnedPostgresProcess)) {
            Invoke-LoggedProcess (Join-Path $pgBin 'pg_ctl.exe') @('-D', $pgData, '-m', 'fast', '-w', '-t', '60', 'stop') 'Stop local database' -TimeoutSeconds 75 | Out-Null
        }
        Write-Host 'StockNest is stopped. Your inventory and settings remain in the data folder.'
        exit 0
    }

    Write-Host 'Starting StockNest. The first start may take a few minutes...'
    foreach ($required in @($phpExe, (Join-Path $pgBin 'initdb.exe'), (Join-Path $pgBin 'pg_ctl.exe'), (Join-Path $pgBin 'psql.exe'), (Join-Path $pgBin 'createdb.exe'), (Join-Path $appRoot 'artisan'), (Join-Path $appRoot 'vendor\autoload.php'), (Join-Path $appRoot 'public\build\manifest.json'))) {
        if (-not (Test-Path -LiteralPath $required -PathType Leaf)) { throw 'The portable package is incomplete. Extract every file from the Windows portable ZIP, then start it from that folder.' }
    }
    foreach ($relative in @('cache', 'tmp', 'php-conf.d', 'storage\app\private', 'storage\app\public', 'storage\framework\cache\data', 'storage\framework\sessions', 'storage\framework\views', 'storage\logs')) {
        [IO.Directory]::CreateDirectory((Join-Path $dataRoot $relative)) | Out-Null
    }
    $databaseProcess = Get-OwnedPostgresProcess
    if ($null -ne $databaseProcess) { $state.dbPort = $databaseProcess.port }
    else { $state.dbPort = Get-AvailablePort ([int] $state.dbPort) }
    $webAlreadyRunning = Test-OwnedPhpProcess $state.processes.web 'web'
    if (-not $webAlreadyRunning) { $state.webPort = Get-AvailablePort ([int] $state.webPort) ([int] $state.dbPort) }
    Save-State
    Update-Environment
    Write-PhpConfiguration
    Invoke-LoggedProcess $phpExe @('-r', 'foreach (["bcmath","curl","fileinfo","gd","intl","mbstring","openssl","pdo_pgsql","pgsql","sodium","zip"] as $extension) { if (!extension_loaded($extension)) { fwrite(STDERR, "Missing PHP extension: ".$extension.PHP_EOL); exit(1); } } echo "Bundled PHP is ready.".PHP_EOL;') 'Check bundled PHP' | Out-Null

    if (-not (Test-Path -LiteralPath (Join-Path $pgData 'PG_VERSION') -PathType Leaf)) {
        if (Test-Path -LiteralPath $pgData) { throw 'Database setup was interrupted. Keep data\pgdata and inspect data\logs before trying again.' }
        Write-Host 'Preparing your local inventory database...'
        Invoke-LoggedProcess (Join-Path $pgBin 'initdb.exe') @('-D', $pgData, '--username=stocknest', '--auth=scram-sha-256', ('--pwfile=' + $passwordPath), '--encoding=UTF8', '--locale=C') 'Initialize local database' -TimeoutSeconds 300 | Out-Null
    }
    if ($null -eq $databaseProcess) {
        $postgresOptions = '-h 127.0.0.1 -p ' + $state.dbPort
        Invoke-LoggedProcess (Join-Path $pgBin 'pg_ctl.exe') @('-D', $pgData, '-l', (Join-Path $logRoot 'postgresql.log'), '-o', $postgresOptions, '-w', '-t', '60', 'start') 'Start local database' -TimeoutSeconds 75 | Out-Null
        $databaseProcess = Get-OwnedPostgresProcess
        if ($null -eq $databaseProcess) { throw 'The local database did not start. See data\logs.' }
    }
    $connection = @('-h', '127.0.0.1', '-p', [string] $state.dbPort, '-U', 'stocknest', '-w')
    $databaseLocation = Invoke-LoggedProcess (Join-Path $pgBin 'psql.exe') ($connection + @('-d', 'postgres', '-t', '-A', '-c', 'SHOW data_directory;')) 'Verify local database location'
    if (-not [string]::Equals([IO.Path]::GetFullPath($databaseLocation.Output.Trim()), $pgData, [StringComparison]::OrdinalIgnoreCase)) {
        throw 'The database port belongs to another installation. StockNest did not change that database.'
    }
    $databaseExists = Invoke-LoggedProcess (Join-Path $pgBin 'psql.exe') ($connection + @('-d', 'postgres', '-t', '-A', '-c', "SELECT 1 FROM pg_database WHERE datname = 'stocknest';")) 'Check inventory database'
    if ($databaseExists.Output.Trim() -ne '1') {
        Invoke-LoggedProcess (Join-Path $pgBin 'createdb.exe') ($connection + @('--maintenance-db=postgres', 'stocknest')) 'Create inventory database' | Out-Null
    }
    if (-not $webAlreadyRunning) {
        # These are generated caches only; never delete application or database data.
        foreach ($name in @('config.php', 'packages.php', 'services.php', 'routes-v7.php', 'events.php')) {
            $cache = Join-Path $dataRoot ('cache\' + $name)
            if (Test-Path -LiteralPath $cache -PathType Leaf) { Remove-Item -LiteralPath $cache -Force }
        }
        Write-Host 'Checking inventory tables and funding sources...'
        Invoke-LoggedProcess $phpExe @((Join-Path $appRoot 'artisan'), 'migrate', '--force', '--seed', '--no-interaction') 'Update inventory tables' -TimeoutSeconds 300 | Out-Null
    }
    $router = Join-Path $appRoot 'vendor\laravel\framework\src\Illuminate\Foundation\resources\server.php'
    Start-PhpProcess 'web' @('-S', "127.0.0.1:$($state.webPort)", '-t', (Join-Path $appRoot 'public'), $router) (Join-Path $appRoot 'public')
    Start-PhpProcess 'queue' @((Join-Path $appRoot 'artisan'), 'queue:work', '--sleep=3', '--tries=3', '--timeout=90', '--no-interaction') $appRoot
    Start-PhpProcess 'scheduler' @((Join-Path $appRoot 'artisan'), 'schedule:work', '--no-interaction') $appRoot
    Wait-ForWeb
    $url = "http://127.0.0.1:$($state.webPort)"
    Write-Host "StockNest is ready: $url"
    Write-Host 'Use Stop StockNest before copying the data folder or shutting down this installation.'
    if (-not $NoBrowser) { Start-Process -FilePath $url | Out-Null }
    exit 0
} catch {
    Write-Host ''
    Write-Host ('StockNest could not ' + $Action.ToLowerInvariant() + ': ' + $_.Exception.Message) -ForegroundColor Red
    Write-Host 'Your inventory has not been reset. Diagnostic files are in data\logs.'
    if (-not $NoPause -and [Environment]::UserInteractive) {
        try { [void] (Read-Host 'Press Enter to close this window') } catch { }
    }
    exit 1
} finally {
    $env:PGPASSWORD = $null
    if ($null -ne $launcherLock) { $launcherLock.Dispose() }
}
