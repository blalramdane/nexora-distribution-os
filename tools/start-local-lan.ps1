$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$backend = Join-Path $root 'backend'
$frontend = Join-Path $root 'frontend'
$php = Join-Path $root '.tools\php83\php.exe'
$ini = Join-Path $root '.tools\php83\php.ini'
$db = Join-Path $backend 'database\nexora-local-preview.sqlite'
$node = (Get-Command node.exe -ErrorAction Stop).Source
$port = 3017

if (!(Test-Path $db)) { throw "The local preview database is missing; refusing to create or switch databases: $db" }
if (!(Test-Path (Join-Path $backend 'vendor\autoload.php'))) { throw 'Backend dependencies are missing.' }
if (!(Test-Path (Join-Path $frontend 'node_modules\next\package.json'))) { throw 'Frontend dependencies are missing.' }
if (!(Test-Path $php) -or !(Test-Path $ini)) { throw 'Project-scoped PHP 8.3 was not found.' }

$wifi = Get-NetIPConfiguration | Where-Object {
    $_.IPv4DefaultGateway -and $_.NetAdapter.Status -eq 'Up' -and $_.IPv4Address
} | Select-Object -First 1
$lanAddress = $wifi.IPv4Address | Where-Object {
    $_.IPAddress -notlike '127.*' -and $_.IPAddress -notlike '169.254.*'
} | Select-Object -First 1 -ExpandProperty IPAddress
if (!$lanAddress) { throw 'No active Wi-Fi/Ethernet IPv4 address with a gateway was found. Connect to the same network as the test phone, then retry.' }

# Keep the API bound to loopback. The browser calls the same-origin Next.js
# /api/* rewrite, which proxies to the local API and avoids exposing port 8017.
$env:PHPRC = $ini
$env:PHP_INI_SCAN_DIR = ''
$env:APP_ENV = 'local'
$env:DB_CONNECTION = 'sqlite'
$env:DB_DATABASE = $db
$env:DB_URL = ''
$env:CACHE_STORE = 'file'
$env:CACHE_DRIVER = 'file'
$env:SESSION_DRIVER = 'file'
$env:QUEUE_CONNECTION = 'sync'
$env:NEXT_PUBLIC_API_URL = '/api/v1'
$env:API_INTERNAL_URL = 'http://127.0.0.1:8017'
$env:NEXORA_DIST_DIR = '.next-local-ui-review-20261022'

try {
    $apiHealth = Invoke-WebRequest -Uri 'http://127.0.0.1:8017/api/v1/health' -TimeoutSec 4 -UseBasicParsing
    if ([int]$apiHealth.StatusCode -ne 200) { throw 'The local API health check did not return HTTP 200.' }
} catch {
    throw "The API is not responding at 127.0.0.1:8017. Start the existing local API first. Details: $($_.Exception.Message)"
}

$listener = Get-NetTCPConnection -State Listen -LocalPort $port -ErrorAction SilentlyContinue | Select-Object -First 1
if ($listener) {
    throw "Port $port is already occupied by PID $($listener.OwningProcess). No process was stopped. Stop only the existing NEXORA frontend, then retry this script."
}

$ruleName = 'NEXORA Distribution OS LAN Preview TCP 3017'
if (!(Get-NetFirewallRule -DisplayName $ruleName -ErrorAction SilentlyContinue)) {
    try {
        New-NetFirewallRule -DisplayName $ruleName -Description 'Temporary NEXORA local preview; allow the frontend from the local subnet only. API port 8017 remains loopback-only.' -Direction Inbound -Action Allow -Protocol TCP -LocalPort $port -RemoteAddress LocalSubnet -Profile Any | Out-Null
        Write-Host 'Added a firewall rule for TCP 3017 restricted to the local subnet.' -ForegroundColor Green
    } catch {
        Write-Warning 'Windows Firewall did not allow an automatic local-subnet rule (administrator permission may be required). The app will start, but other devices may be blocked by the firewall.'
    }
}

$stdout = Join-Path $frontend 'local-lan-serve.out.log'
$stderr = Join-Path $frontend 'local-lan-serve.err.log'
$process = Start-Process -FilePath $node -ArgumentList @('node_modules/next/dist/bin/next', 'dev', '--hostname', '0.0.0.0', '--port', "$port") -WorkingDirectory $frontend -WindowStyle Hidden -PassThru -RedirectStandardOutput $stdout -RedirectStandardError $stderr
Write-Host "NEXORA LAN frontend launched (PID $($process.Id)); logs: $stdout and $stderr"

$ready = $false
for ($attempt = 0; $attempt -lt 25; $attempt++) {
    Start-Sleep -Milliseconds 800
    try {
        $response = Invoke-WebRequest -Uri "http://127.0.0.1:$port/login" -TimeoutSec 3 -UseBasicParsing
        if ([int]$response.StatusCode -eq 200) { $ready = $true; break }
    } catch { }
}
if (!$ready) { throw "The frontend did not become healthy. Inspect $stderr and $stdout" }

$proxyHealth = Invoke-WebRequest -Uri "http://127.0.0.1:$port/api/v1/health" -TimeoutSec 5 -UseBasicParsing
if ([int]$proxyHealth.StatusCode -ne 200) { throw 'The same-origin API proxy did not return HTTP 200; inspect the frontend log.' }

Write-Host ''
Write-Host 'LOCAL NEXORA FIELD DEMO' -ForegroundColor Cyan
Write-Host "Field portal: http://${lanAddress}:$port/field" -ForegroundColor Green
Write-Host "Login:        http://${lanAddress}:$port/login" -ForegroundColor Green
Write-Host 'API:          same-origin /api/* proxy to 127.0.0.1:8017 (not exposed directly)' -ForegroundColor DarkGray
Write-Host 'Database:     backend/database/nexora-local-preview.sqlite' -ForegroundColor DarkGray
Write-Host 'Network note: test phone must be connected to this same Wi-Fi/hotspot; do not port-forward this preview to the Internet.' -ForegroundColor Yellow
