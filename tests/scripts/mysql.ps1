#requires -Version 7.0
[CmdletBinding()]
param(
    [Parameter(Mandatory)]
    [string] $ArchivePath,

    [ValidateRange(1024, 65535)]
    [int] $Port = 33307,

    [switch] $RunPhpTests
)

$ErrorActionPreference = 'Stop'
$project = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$setup = Join-Path $project '.github/scripts/mysql.ps1'
$archive = (Resolve-Path -LiteralPath $ArchivePath).ProviderPath
$work = Join-Path ([System.IO.Path]::GetTempPath()) ('mysql-check-' + [guid]::NewGuid().ToString('N'))
[System.IO.Directory]::CreateDirectory($work) | Out-Null
$identity = [System.Security.Principal.WindowsIdentity]::GetCurrent().User
$acl = [System.Security.AccessControl.DirectorySecurity]::new()
$acl.SetOwner($identity)
$acl.SetAccessRuleProtection($true, $false)
$acl.AddAccessRule([System.Security.AccessControl.FileSystemAccessRule]::new(
    $identity, 'FullControl', 'ContainerInherit, ObjectInherit', 'None', 'Allow'
))
Set-Acl -LiteralPath $work -AclObject $acl

$variables = @('RUNNER_TEMP', 'GITHUB_ENV', 'GITHUB_OUTPUT', 'MYSQL_CI_ROOT', 'TEST_DSN', 'TEST_USER', 'TEST_PASSWORD')
$previous = @{}
foreach ($variable in $variables) {
    $previous[$variable] = [Environment]::GetEnvironmentVariable($variable)
}
$environmentFile = Join-Path $work 'github.env'
$outputFile = Join-Path $work 'github.output'
$ciConfig = Join-Path $work 'client.ini'
$corrupted = Join-Path $work 'corrupted.zip'
$database = 'mysql_ci_test'
$mysqlRoot = $null
$completed = $false

try {
    $env:RUNNER_TEMP = $work
    $env:GITHUB_ENV = $environmentFile
    $env:GITHUB_OUTPUT = $outputFile
    $env:MYSQL_CI_ROOT = $null

    [System.IO.File]::WriteAllText($corrupted, 'not a MySQL archive')
    $rejected = $false
    try {
        $null = & $setup -ArchivePath $corrupted -Port $Port
    } catch {
        $rejected = $_.Exception.Message -eq 'The MySQL archive checksum does not match the reviewed release.'
    }
    if (!$rejected) {
        throw 'The corrupted archive was not rejected before extraction.'
    }
    $rejected = $false
    try {
        $null = & $setup -Version '8.4.999' -ArchivePath $archive -Port $Port
    } catch {
        $rejected = $_.Exception.Message -eq 'This MySQL version has no reviewed checksum.'
    }
    if (!$rejected) {
        throw 'An unreviewed MySQL version was accepted.'
    }
    $rejected = $false
    try {
        $null = & $setup -Database "invalid`n" -ArchivePath $archive -Port $Port
    } catch [System.Management.Automation.ParameterBindingException] {
        $rejected = $true
    }
    if (!$rejected) {
        throw 'The invalid database name was accepted.'
    }
    $env:MYSQL_CI_ROOT = [System.Environment]::GetFolderPath('Windows')
    $rejected = $false
    try {
        $null = & $setup -Action Stop
    } catch {
        $rejected = $_.Exception.Message -eq 'Refusing to stop MySQL outside its dedicated runner directory.'
    }
    if (!$rejected) {
        throw 'The shutdown directory was not validated.'
    }
    $env:MYSQL_CI_ROOT = $null

    # Capture masking commands locally; never print the generated passwords.
    $messages = & $setup -Database $database -ArchivePath $archive -Port $Port
    if ($env:GITHUB_ACTIONS -eq 'true') {
        $messages | Where-Object { $_ -is [string] -and $_.StartsWith('::add-mask::') } | Write-Output
    }
    $mysqlRoot = (Get-Content -LiteralPath $environmentFile | Where-Object { $_.StartsWith('MYSQL_CI_ROOT=') }).Substring(14)
    $env:MYSQL_CI_ROOT = $mysqlRoot
    $password = (Get-Content -LiteralPath $outputFile | Where-Object { $_.StartsWith('password=') }).Substring(9)
    if ($password -cnotmatch '\A[A-F0-9]{64}\z') {
        throw 'The CI password was not generated securely.'
    }
    $directory = Split-Path -Parent $mysqlRoot
    $rootConfig = Join-Path $directory 'root.ini'
    $privateAcl = Get-Acl -LiteralPath $directory
    if (!$privateAcl.AreAccessRulesProtected -or $privateAcl.Access.Count -ne 1 -or
        $privateAcl.Access[0].IdentityReference.Translate([System.Security.Principal.SecurityIdentifier]).Value -ne $identity.Value) {
        throw 'The credential directory is accessible beyond the runner account.'
    }
    if ((Test-Path -LiteralPath (Join-Path $directory 'init.sql')) -or
        (Test-Path -LiteralPath (Join-Path $directory 'ci.ini'))) {
        throw 'Temporary initialization credentials were not removed.'
    }
    [System.IO.File]::WriteAllLines($ciConfig, @(
        '[client]', 'user=ci', "password=$password", "port=$Port", 'host=127.0.0.1', 'protocol=TCP'
    ), [System.Text.UTF8Encoding]::new($false))
    $client = Join-Path $mysqlRoot 'bin/mysql.exe'
    & $client "--defaults-file=$ciConfig" "--database=$database" --execute='CREATE TABLE checked (id INT); DROP TABLE checked;' 2>$null
    if ($LASTEXITCODE -ne 0) {
        throw 'The CI account cannot run the database tests.'
    }

    foreach ($user in @('root', 'ci')) {
        & $client --no-defaults --protocol=TCP --host=127.0.0.1 "--port=$Port" "--user=$user" --connect-timeout=2 --execute='SELECT 1' 2>$null | Out-Null
        if ($LASTEXITCODE -eq 0) {
            throw 'A database account still accepts an empty password.'
        }
    }
    & $client "--defaults-file=$rootConfig" --execute='CREATE DATABASE mysqlXciXtest;' 2>$null
    if ($LASTEXITCODE -ne 0) {
        throw 'Unable to prepare the privilege check.'
    }
    & $client "--defaults-file=$ciConfig" --execute='CREATE TABLE mysqlXciXtest.forbidden (id INT);' 2>$null | Out-Null
    if ($LASTEXITCODE -eq 0) {
        throw 'The CI grants match databases outside the exact test database.'
    }
    & $client "--defaults-file=$ciConfig" --execute='SELECT User FROM mysql.user;' 2>$null | Out-Null
    if ($LASTEXITCODE -eq 0) {
        throw 'The CI account has access to MySQL administration tables.'
    }

    if ($RunPhpTests) {
        $env:TEST_DSN = "mysql:host=127.0.0.1;port=$Port;dbname=$database"
        $env:TEST_USER = 'ci'
        $env:TEST_PASSWORD = $password
        Push-Location $project
        try {
            & php tests/run.php
            if ($LASTEXITCODE -ne 0) {
                throw 'The MySQL PHP test suite failed.'
            }
        } finally {
            Pop-Location
        }
    }

    $null = & $setup -Action Stop
    $env:MYSQL_CI_ROOT = $null
    if (Test-Path -LiteralPath $rootConfig) {
        throw 'The root credentials were not removed after shutdown.'
    }
    $completed = $true
} finally {
    try {
        if ($env:MYSQL_CI_ROOT) {
            $null = & $setup -Action Stop
        }
    } finally {
        foreach ($file in @($environmentFile, $outputFile, $ciConfig, $corrupted)) {
            if (Test-Path -LiteralPath $file) {
                Remove-Item -LiteralPath $file
            }
        }
        foreach ($variable in $variables) {
            [Environment]::SetEnvironmentVariable($variable, $previous[$variable])
        }
        if ($completed) {
            $resolvedWork = [System.IO.Path]::GetFullPath($work)
            $relativeWork = [System.IO.Path]::GetRelativePath([System.IO.Path]::GetTempPath(), $resolvedWork)
            if ($relativeWork -cnotmatch '\Amysql-check-[a-f0-9]{32}\z') {
                throw 'Refusing to clean a directory outside the test workspace.'
            }
            for ($attempt = 0; $attempt -lt 10; $attempt++) {
                try {
                    Remove-Item -LiteralPath $resolvedWork -Recurse -Force
                    break
                } catch {
                    if ($attempt -eq 9) {
                        throw
                    }
                    Start-Sleep -Milliseconds 500
                }
            }
        }
    }
}

'MySQL archive, authentication, grants, private files and shutdown: OK'

