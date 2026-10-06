# Allow phones / laptops on the SAME Wi-Fi to open the CRM served by XAMPP Apache.
#
# Run ONCE in PowerShell **as Administrator**:
#   powershell -ExecutionPolicy Bypass -File "D:\AI PROJECT\SMS SYSTEM\tools\allow-wifi-access.ps1"
#
# Safety: the rule only applies to Private networks (your home/office Wi-Fi) and only
# accepts connections from the local subnet - not from public Wi-Fi or the internet.
# Remove later with:  Remove-NetFirewallRule -DisplayName "Marketing CRM (Apache, LAN only)"

$ErrorActionPreference = 'Stop'
$name = 'Marketing CRM (Apache, LAN only)'

$isAdmin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole(
    [Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $isAdmin) {
    Write-Host 'Please run this script as Administrator (right-click PowerShell > Run as administrator).' -ForegroundColor Red
    exit 1
}

if (Get-NetFirewallRule -DisplayName $name -ErrorAction SilentlyContinue) {
    Write-Host "Rule '$name' already exists - nothing to do." -ForegroundColor Yellow
} else {
    New-NetFirewallRule -DisplayName $name `
        -Direction Inbound -Action Allow -Protocol TCP -LocalPort 80 `
        -Program 'C:\xampp\apache\bin\httpd.exe' `
        -Profile Private -RemoteAddress LocalSubnet | Out-Null
    Write-Host "Created firewall rule '$name'." -ForegroundColor Green
}

$profile = Get-NetConnectionProfile | Where-Object { $_.IPv4Connectivity -ne 'Disconnected' } | Select-Object -First 1
if ($profile -and $profile.NetworkCategory -ne 'Private') {
    Write-Host "Your network '$($profile.InterfaceAlias)' is '$($profile.NetworkCategory)'. Set it to Private in Windows Settings > Network for this rule to apply." -ForegroundColor Yellow
}

$ips = Get-NetIPAddress -AddressFamily IPv4 |
    Where-Object { $_.IPAddress -notlike '127.*' -and $_.IPAddress -notlike '169.254.*' -and $_.PrefixOrigin -ne 'WellKnown' } |
    Select-Object -ExpandProperty IPAddress
Write-Host ''
Write-Host 'Open on a phone / laptop connected to the same Wi-Fi:' -ForegroundColor Cyan
foreach ($ip in $ips) { Write-Host "   http://$ip/marketing_crm/" }
Write-Host ''
Write-Host 'Tip: reserve this PC''s IP in your Wi-Fi router (DHCP reservation) so the address never changes.'
