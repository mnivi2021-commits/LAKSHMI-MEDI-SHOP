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

# ---------------------------------------------------------------------------
Step 2 'Create the database and the crm_app user (enter the MySQL ROOT password when asked)'
$sql = [IO.File]::ReadAllText((Join-Path $root 'database\setup_user.sql'), $utf8).Replace('CHANGE_ME_STRONG_PASSWORD', $current)
$sql += "`nALTER USER 'crm_app'@'localhost' IDENTIFIED BY '$current';`nALTER USER 'crm_app'@'127.0.0.1' IDENTIFIED BY '$current';`nFLUSH PRIVILEGES;`n"
$tmp = Join-Path $env:TEMP ('crm_setup_' + [Guid]::NewGuid().ToString('N') + '.sql')
[IO.File]::WriteAllText($tmp, $sql, $utf8)
try {
    cmd /c "`"$mysql`" -u root -p < `"$tmp`""
    if ($LASTEXITCODE -ne 0) { throw 'MySQL did not accept the root password, or the script failed. Run this setup again.' }
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
