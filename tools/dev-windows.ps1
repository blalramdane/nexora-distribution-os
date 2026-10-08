# NEXORA Distribution OS - Windows local development
$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent $PSScriptRoot
$Backend = Join-Path $Root "backend"
$Frontend = Join-Path $Root "frontend"
$PhpDir = Join-Path $Root ".tools\php83"
if (Test-Path (Join-Path $PhpDir "php.exe")) {
    $env:PATH = "$PhpDir;$env:PATH"
    $env:PHPRC = Join-Path $PhpDir "php.ini"
    $env:PHP_INI_SCAN_DIR = ""
}
$phpVersion = (& php -r "echo PHP_VERSION;" 2>$null).Trim()
if (-not $phpVersion -or [version]$phpVersion -lt [version]"8.3.0") { throw "Run .\tools\setup-windows.ps1 first." }
$ip = (Get-NetIPAddress -AddressFamily IPv4 -PrefixOrigin Dhcp | Where-Object { $_.IPAddress -notlike "169.254.*" -and $_.IPAddress -ne "127.0.0.1" } | Select-Object -First 1 -ExpandProperty IPAddress)
if (-not $ip) { $ip = "127.0.0.1" }
$frontendEnv = Join-Path $Frontend ".env.local"
$apiUrl = "NEXT_PUBLIC_API_URL=http://${ip}:8000/api/v1"
if (Test-Path $frontendEnv) {
    $content = Get-Content $frontendEnv -Raw
    if ($content -match "(?m)^NEXT_PUBLIC_API_URL=") { $content = [regex]::Replace($content, "(?m)^NEXT_PUBLIC_API_URL=.*$", $apiUrl) }
    else { $content = $content.TrimEnd() + [Environment]::NewLine + $apiUrl + [Environment]::NewLine }
    Set-Content $frontendEnv $content -Encoding UTF8
} else { Set-Content $frontendEnv ($apiUrl + [Environment]::NewLine) -Encoding UTF8 }
Write-Host "NEXORA backend:  http://${ip}:8000" -ForegroundColor Green
Write-Host "NEXORA frontend: http://${ip}:3000" -ForegroundColor Green
Write-Host "Phone must be on the same Wi-Fi as this PC." -ForegroundColor Yellow
Start-Process powershell -WorkingDirectory $Backend -ArgumentList "-NoExit","-Command","php artisan serve --host=0.0.0.0 --port=8000"
Start-Process powershell -WorkingDirectory $Frontend -ArgumentList "-NoExit","-Command","npm run dev -- --hostname 0.0.0.0 --port 3000"
