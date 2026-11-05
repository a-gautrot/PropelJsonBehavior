# A Powershell script reserved for the CI running Windows, as jobs related to this OS tend to break mid-way through
#requires -Version 7.0
[CmdletBinding()]
param(
    [ValidateSet('Start', 'Stop')]
    [string] $Action = 'Start',

    [ValidatePattern('\A[A-Za-z_][A-Za-z0-9_]*\z')]
    [string] $Database = 'json_behavior',

    [ValidatePattern('\A[0-9]+\.[0-9]+\.[0-9]+\z')]
    [string] $Version = '8.4.13',

    [ValidateRange(1024, 65535)]
    [int] $Port = 3307,

    [string] $ArchivePath
)

$ErrorActionPreference = 'Stop'

function New-Password {
    $bytes = [byte[]]::new(32)
    $random = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    try {
        $random.GetBytes($bytes)
    } finally {
        $random.Dispose()
    }
    return [System.BitConverter]::ToString($bytes).Replace('-', '')
}

function Set-PrivateDirectory {
    param([string] $Path)

    $identity = [System.Security.Principal.WindowsIdentity]::GetCurrent().User
    $acl = [System.Security.AccessControl.DirectorySecurity]::new()
    $acl.SetOwner($identity)
    $acl.SetAccessRuleProtection($true, $false)
    $rule = [System.Security.AccessControl.FileSystemAccessRule]::new(
        $identity, 'FullControl', 'ContainerInherit, ObjectInherit', 'None', 'Allow'
    )
    $acl.AddAccessRule($rule)
    Set-Acl -LiteralPath $Path -AclObject $acl
}

if ($Action -eq 'Stop') {
    if (!$env:MYSQL_CI_ROOT) {
        return
    }
    if (!$env:RUNNER_TEMP) {
        throw 'Unable to validate the MySQL installation directory.'
    }
    $mysqlRoot = [System.IO.Path]::GetFullPath($env:MYSQL_CI_ROOT)
    $relative = [System.IO.Path]::GetRelativePath($env:RUNNER_TEMP, $mysqlRoot).Replace('\', '/')
    if ($relative -cnotmatch '\Amysql-ci-[a-f0-9]{32}/mysql-[0-9]+\.[0-9]+\.[0-9]+-winx64\z') {
        throw 'Refusing to stop MySQL outside its dedicated runner directory.'
    }
    $credentials = Join-Path (Split-Path -Parent $mysqlRoot) 'root.ini'
    $admin = Join-Path $mysqlRoot 'bin/mysqladmin.exe'
    $serverPath = Join-Path $mysqlRoot 'bin/mysqld.exe'
    $running = @(Get-Process -Name mysqld -ErrorAction SilentlyContinue | Where-Object { $_.Path -eq $serverPath })
    foreach ($process in $running) {
        $null = $process.Handle
    }
    & $admin "--defaults-file=$credentials" shutdown
    if ($LASTEXITCODE -ne 0) {
        throw 'MySQL shutdown failed.'
    }
    foreach ($process in $running) {
        if (!$process.WaitForExit(30000)) {
            throw 'MySQL did not finish shutting down within 30 seconds.'
        }
    }
    Remove-Item -LiteralPath $credentials
    return
}

if (!$IsWindows -or !$env:RUNNER_TEMP -or !$env:GITHUB_ENV -or !$env:GITHUB_OUTPUT) {
    throw 'This script requires a GitHub Actions Windows runner.'
}

# Verified against MySQL release key BCA43417C3B485DD128EC6D4B7B3B788A8D3785C.
$checksums = @{
    '8.4.13' = 'E9CD98B1D23F122987B1AFCA26AD942EFEC5891481103D48AE3CDE237FA3EA54'
}
if (!$checksums.ContainsKey($Version)) {
    throw 'This MySQL version has no reviewed checksum.'
}

$listener = [System.Net.Sockets.TcpListener]::new([System.Net.IPAddress]::Loopback, $Port)
try {
    $listener.Start()
} finally {
    $listener.Stop()
}

$directory = Join-Path $env:RUNNER_TEMP ('mysql-ci-' + [guid]::NewGuid().ToString('N'))
[System.IO.Directory]::CreateDirectory($directory) | Out-Null
Set-PrivateDirectory -Path $directory
$archive = Join-Path $directory 'mysql.zip'
if ($ArchivePath) {
    $archive = (Resolve-Path -LiteralPath $ArchivePath).ProviderPath
} else {
    $series = ($Version.Split('.')[0..1]) -join '.'
    $url = "https://cdn.mysql.com/Downloads/MySQL-$series/mysql-$Version-winx64.zip"
    Invoke-WebRequest -Uri $url -OutFile $archive -MaximumRedirection 0 -TimeoutSec 300
}
if ((Get-FileHash -LiteralPath $archive -Algorithm SHA256).Hash -cne $checksums[$Version]) {
    throw 'The MySQL archive checksum does not match the reviewed release.'
}
Expand-Archive -LiteralPath $archive -DestinationPath $directory

$mysqlRoot = Join-Path $directory "mysql-$Version-winx64"
$server = Join-Path $mysqlRoot 'bin/mysqld.exe'
$client = Join-Path $mysqlRoot 'bin/mysql.exe'
$data = Join-Path $directory 'data'
$log = Join-Path $directory 'mysql.log'
$initialization = Join-Path $directory 'init.sql'
$credentials = Join-Path $directory 'root.ini'
$ciCredentials = Join-Path $directory 'ci.ini'
$rootPassword = New-Password
$ciPassword = New-Password
Write-Output "::add-mask::$rootPassword"
Write-Output "::add-mask::$ciPassword"

$serverProcess = $null
try {
    # Offline initialization does not start a listening server.
    & $server --no-defaults --initialize-insecure "--basedir=$mysqlRoot" "--datadir=$data"
    if ($LASTEXITCODE -ne 0) {
        throw 'MySQL initialization failed.'
    }

    $databaseName = '`' + $Database + '`'
    $grantTarget = '`' + $Database.Replace('_', '\_') + '`'
    $sql = @(
        "ALTER USER 'root'@'localhost' IDENTIFIED BY '$rootPassword';"
        "CREATE DATABASE $databaseName;"
        "CREATE USER 'ci'@'127.0.0.1' IDENTIFIED BY '$ciPassword';"
        "CREATE USER 'ci'@'localhost' IDENTIFIED BY '$ciPassword';"
        "GRANT ALL ON $grantTarget.* TO 'ci'@'127.0.0.1';"
        "GRANT ALL ON $grantTarget.* TO 'ci'@'localhost';"
    )
    [System.IO.File]::WriteAllLines($initialization, $sql, [System.Text.UTF8Encoding]::new($false))

    $settings = @('host=127.0.0.1', "port=$Port", 'protocol=TCP', 'connect-timeout=2')
    [System.IO.File]::WriteAllLines(
        $credentials, (@('[client]', 'user=root', "password=$rootPassword") + $settings),
        [System.Text.UTF8Encoding]::new($false)
    )
    [System.IO.File]::WriteAllLines(
        $ciCredentials, (@('[client]', 'user=ci', "password=$ciPassword", "database=$Database") + $settings),
        [System.Text.UTF8Encoding]::new($false)
    )

    $serverProcess = Start-Process -FilePath $server -PassThru -WindowStyle Hidden -ArgumentList @(
        '--no-defaults',
        "--basedir=`"$mysqlRoot`"",
        "--datadir=`"$data`"",
        "--log-error=`"$log`"",
        "--init-file=`"$initialization`"",
        '--bind-address=127.0.0.1',
        "--port=$Port",
        '--mysqlx=OFF'
    )

    $ready = $false
    $deadline = [DateTime]::UtcNow.AddSeconds(60)
    while ([DateTime]::UtcNow -lt $deadline) {
        & $client "--defaults-file=$credentials" --execute='SELECT 1' 2>$null | Out-Null
        if ($LASTEXITCODE -eq 0) {
            $ready = $true
            break
        }
        if ($serverProcess.HasExited) {
            break
        }
        Start-Sleep -Seconds 1
    }
    if (!$ready) {
        throw 'MySQL did not become ready within 60 seconds.'
    }
    & $client "--defaults-file=$ciCredentials" --execute='SELECT 1' 2>$null | Out-Null
    if ($LASTEXITCODE -ne 0) {
        throw 'The CI account cannot access its test database.'
    }

    Remove-Item -LiteralPath $initialization, $ciCredentials
    Add-Content -LiteralPath $env:GITHUB_ENV -Value "MYSQL_CI_ROOT=$mysqlRoot"
    Add-Content -LiteralPath $env:GITHUB_OUTPUT -Value "password=$ciPassword"
} catch {
    if (Test-Path -LiteralPath $log) {
        Get-Content -LiteralPath $log -Tail 30 | ForEach-Object {
            $_.Replace($rootPassword, '[REDACTED]').Replace($ciPassword, '[REDACTED]')
        }
    }
    if ($null -ne $serverProcess -and !$serverProcess.HasExited) {
        Stop-Process -Id $serverProcess.Id -ErrorAction SilentlyContinue
    }
    foreach ($secretFile in @($initialization, $credentials, $ciCredentials)) {
        if (Test-Path -LiteralPath $secretFile) {
            Remove-Item -LiteralPath $secretFile
        }
    }
    throw
}
