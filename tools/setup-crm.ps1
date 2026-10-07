# One-time setup of the Marketing CRM on this computer.
# Run in PowerShell as Administrator:
#   cd "D:\AI PROJECT\SMS SYSTEM"
#   powershell -ExecutionPolicy Bypass -File tools\setup-crm.ps1
#
# It asks for the MySQL root password (typed into MySQL itself, never stored) and for the
# Admin Head's name / username / email. The CRM's own database password is generated here
# and kept only in .env (which is never committed).

$ErrorActionPreference = 'Stop'
$root   = Split-Path -Parent $PSScriptRoot
$envFile = Join-Path $root '.env'
$php    = 'C:\xampp\php\php.exe'
$httpd  = 'C:\xampp\apache\bin\httpd.exe'
$mysql  = 'C:\Program Files\MySQL\MySQL Server 8.0\bin\mysql.exe'
$utf8   = New-Object System.Text.UTF8Encoding($false)

function Step($n, $text) { Write-Host ""; Write-Host "[$n] $text" -ForegroundColor Cyan }

$isAdmin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $isAdmin) {
    Write-Host 'Please run this in PowerShell opened with "Run as administrator".' -ForegroundColor Yellow
    Write-Host '(Needed so Apache can start with Windows and other office PCs / phones can connect.)'
    exit 1
}
foreach ($p in @($php, $httpd, $mysql, $envFile)) {
    if (-not (Test-Path $p)) { Write-Host "Not found: $p" -ForegroundColor Red; exit 1 }
}

# ---------------------------------------------------------------------------
Step 1 'CRM database password in .env'
$lines = [IO.File]::ReadAllLines($envFile, $utf8)
$current = ($lines | Where-Object { $_ -match '^DB_PASSWORD=' }) -replace '^DB_PASSWORD=', ''
if ([string]::IsNullOrWhiteSpace($current)) {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789'.ToCharArray()
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    $bytes = New-Object byte[] 24
    $rng.GetBytes($bytes)
    $current = -join ($bytes | ForEach-Object { $chars[$_ % $chars.Length] })
    $lines = $lines | ForEach-Object { if ($_ -match '^DB_PASSWORD=') { "DB_PASSWORD=$current" } else { $_ } }
    [IO.File]::WriteAllLines($envFile, $lines, $utf8)
    Write-Host 'A new strong password was generated and saved in .env.'
} else {
    Write-Host 'Using the password already in .env.'
}

# Never show technical error details to users on the office PC
$lines = [IO.File]::ReadAllLines($envFile, $utf8) | ForEach-Object { if ($_ -match '^APP_DEBUG=') { 'APP_DEBUG=false' } else { $_ } }
[IO.File]::WriteAllLines($envFile, $lines, $utf8)

# ---------------------------------------------------------------------------
Step 2 'Create the database and the crm_app user'
# One statement per line, no comments (works both as a normal script and as a MySQL --init-file).
$grants = 'SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES, CREATE VIEW, SHOW VIEW'
$userSql = @(
    'CREATE DATABASE IF NOT EXISTS marketing_crm CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;',
    "CREATE USER IF NOT EXISTS 'crm_app'@'localhost' IDENTIFIED BY '$current';",
    "CREATE USER IF NOT EXISTS 'crm_app'@'127.0.0.1' IDENTIFIED BY '$current';",
    "ALTER USER 'crm_app'@'localhost' IDENTIFIED BY '$current';",
    "ALTER USER 'crm_app'@'127.0.0.1' IDENTIFIED BY '$current';",
    "GRANT $grants ON marketing_crm.* TO 'crm_app'@'localhost';",
    "GRANT $grants ON marketing_crm.* TO 'crm_app'@'127.0.0.1';"
)
$tmp = Join-Path $env:TEMP ('crm_setup_' + [Guid]::NewGuid().ToString('N') + '.sql')
$knows = Read-Host 'Do you know the MySQL ROOT password? (y = yes, n = no / forgotten)'
try {
    if ($knows -match '^[yY]') {
        [IO.File]::WriteAllText($tmp, (($userSql + 'FLUSH PRIVILEGES;') -join "`n") + "`n", $utf8)
        Write-Host 'Type the MySQL ROOT password when asked:'
        cmd /c "`"$mysql`" -u root -p < `"$tmp`""
        if ($LASTEXITCODE -ne 0) { throw 'MySQL did not accept the root password. Run this setup again and answer n to set a new one.' }
    } else {
        # Forgotten root password: start MySQL once with an init file that sets a NEW root password
        # and creates the CRM user. The databases themselves are not changed.
        Write-Host 'Choose a NEW MySQL root password (at least 8 characters). Write it down and keep it safe.' -ForegroundColor Yellow
        do {
            $p1 = [Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR((Read-Host 'New root password' -AsSecureString)))
            $p2 = [Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR((Read-Host 'Type it again' -AsSecureString)))
            if ($p1 -ne $p2) { Write-Host 'The two passwords are different. Try again.' -ForegroundColor Red }
            elseif ($p1.Length -lt 8) { Write-Host 'Use at least 8 characters.' -ForegroundColor Red }
        } while ($p1 -ne $p2 -or $p1.Length -lt 8)
        $rootPw = $p1.Replace("'", "''")
        $initSql = @("ALTER USER 'root'@'localhost' IDENTIFIED BY '$rootPw';") + $userSql + 'FLUSH PRIVILEGES;'
        [IO.File]::WriteAllText($tmp, ($initSql -join "`n") + "`n", $utf8)

        $svc = Get-CimInstance Win32_Service -Filter "Name='MySQL80'"
        if (-not $svc) { throw 'The MySQL80 service was not found.' }
        $defaults = [regex]::Match($svc.PathName, '--defaults-file="?([^"]+)"?').Groups[1].Value
        Write-Host 'Stopping MySQL for a moment...'
        Stop-Service -Name 'MySQL80' -Force
        Start-Sleep -Seconds 3
        $proc = Start-Process -FilePath (Join-Path (Split-Path $mysql) 'mysqld.exe') -ArgumentList @("--defaults-file=`"$defaults`"", "--init-file=`"$tmp`"") -PassThru -WindowStyle Hidden
        $env:MYSQL_PWD = $current
        $ok = $false
        for ($i = 0; $i -lt 60 -and -not $ok; $i++) {
            Start-Sleep -Seconds 2
            & $mysql -u crm_app -h 127.0.0.1 -e 'SELECT 1' 2>$null | Out-Null
            if ($LASTEXITCODE -eq 0) { $ok = $true }
        }
        $env:MYSQL_PWD = $p1
        & (Join-Path (Split-Path $mysql) 'mysqladmin.exe') -u root -h 127.0.0.1 shutdown 2>$null
        Remove-Item Env:MYSQL_PWD -ErrorAction SilentlyContinue
        $proc.WaitForExit(60000) | Out-Null
        if (-not $proc.HasExited) { Stop-Process -Id $proc.Id -Force }
        Start-Service -Name 'MySQL80'
        if (-not $ok) { throw 'MySQL did not start with the setup file. MySQL80 has been restarted unchanged; send a screenshot of this window.' }
        Write-Host 'New MySQL root password set. Keep it safe; the CRM itself does not need it.' -ForegroundColor Green
    }
} finally {
    Remove-Item $tmp -Force -ErrorAction SilentlyContinue
}
Write-Host 'Database and user ready.' -ForegroundColor Green

# ---------------------------------------------------------------------------
Step 3 'Install the CRM tables'
$demo = Read-Host 'Load DEMO data to try the CRM first? (y = demo, Enter = empty office database)'
Push-Location $root
try {
    if ($demo -match '^[yY]') { & $php cli\install.php --seed } else { & $php cli\install.php }
    if ($LASTEXITCODE -ne 0) { Write-Host 'Install reported a problem (see above). If the tables already exist, that is fine.' -ForegroundColor Yellow }

    # -----------------------------------------------------------------------
    if ($demo -match '^[yY]') {
        Step 4 'Demo logins'
        Write-Host 'admin / Admin@2026, coordinator / Coord@2026, jana / Sales@2026 (each must change it at first sign-in).'
    } else {
        Step 4 'Create your Admin Head login'
        & $php cli\create-admin.php
    }
} finally {
    Pop-Location
}

# ---------------------------------------------------------------------------
Step 5 'Start Apache automatically with Windows'
Get-Process httpd -ErrorAction SilentlyContinue | Stop-Process -Force -ErrorAction SilentlyContinue
$svc = Get-Service -Name 'Apache2.4' -ErrorAction SilentlyContinue
if (-not $svc) {
    & $httpd -k install -n 'Apache2.4' | Out-Null
}
Set-Service -Name 'Apache2.4' -StartupType Automatic
Start-Service -Name 'Apache2.4'
Write-Host 'Apache is running and will start by itself after every restart.' -ForegroundColor Green
Set-Service -Name 'MySQL80' -StartupType Automatic -ErrorAction SilentlyContinue

# ---------------------------------------------------------------------------
Step 6 'Allow other office computers and phones on the Wi-Fi'
& (Join-Path $PSScriptRoot 'allow-wifi-access.ps1')

# ---------------------------------------------------------------------------
$ip = (Get-NetIPAddress -AddressFamily IPv4 | Where-Object { $_.IPAddress -notmatch '^(127\.|169\.254\.)' -and $_.PrefixOrigin -ne 'WellKnown' } | Select-Object -First 1).IPAddress
Write-Host ''
Write-Host 'All done. Your links:' -ForegroundColor Green
Write-Host "  This system : http://localhost/marketing_crm"
Write-Host "  Website     : http://$ip/marketing_crm"
Write-Host "  Mobile      : http://$ip/marketing_crm/app/"
Start-Process 'http://localhost/marketing_crm'
