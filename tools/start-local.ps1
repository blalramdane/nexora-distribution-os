$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$backend = Join-Path $root 'backend'
$frontend = Join-Path $root 'frontend'
$php = Join-Path $root '.tools\php83\php.exe'
$ini = Join-Path $root '.tools\php83\php.ini'
$npm = (Get-Command npm.cmd -ErrorAction Stop).Source
$localPreviewDb = Join-Path $backend 'database\nexora-local-preview.sqlite'

if (!(Test-Path (Join-Path $backend 'vendor\autoload.php'))) {
    throw 'Backend dependencies are missing. Run composer install in backend first.'
}
if (!(Test-Path (Join-Path $frontend 'node_modules\next\package.json'))) {
    throw 'Frontend dependencies are missing. Run npm ci in frontend first.'
}
if (!(Test-Path $php) -or !(Test-Path $ini)) {
    throw 'Project-scoped PHP 8.3 was not found under .tools\php83. Install PHP 8.3+ or restore the local runtime.'
}

# Pin every fresh launcher invocation to one project-local SQLite database.
# Previously, the API inherited DB_DATABASE from whichever PowerShell session
# launched it, so a new terminal could silently switch databases and invalidate
# the browser's Sanctum token. Never recreate it from %TEMP% or fall back to .env.
if (!(Test-Path $localPreviewDb)) {
    throw "The persistent NEXORA local preview database is missing. Restore its verified backup before starting; refusing to create an empty database or switch to another copy: $localPreviewDb"
}

$env:PHPRC = $ini
$env:PHP_INI_SCAN_DIR = ''
$env:APP_ENV = 'local'
$env:DB_CONNECTION = 'sqlite'
$env:DB_DATABASE = $localPreviewDb
$env:DB_URL = ''
$env:CACHE_STORE = 'file'
$env:CACHE_DRIVER = 'file'
$env:SESSION_DRIVER = 'file'
$env:QUEUE_CONNECTION = 'sync'
$env:NEXT_PUBLIC_API_URL = 'http://127.0.0.1:8017/api/v1'
$env:API_INTERNAL_URL = 'http://127.0.0.1:8017'

function Test-NexoraEndpoint([string] $Url) {
    try {
        $response = Invoke-WebRequest -Uri $Url -TimeoutSec 2 -UseBasicParsing
        return [int]$response.StatusCode -eq 200
    } catch {
        return $false
    }
}

function Start-IsolatedService([int] $Port, [string] $Url, [string] $Name, [string] $Executable, [string[]] $Arguments, [string] $WorkingDirectory, [string] $LogPrefix) {
    $listener = Get-NetTCPConnection -State Listen -LocalPort $Port -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($listener) {
        if (Test-NexoraEndpoint $Url) {
            Write-Host "$Name is already responding at $Url; leaving its process untouched." -ForegroundColor Green
            return
        }
        throw "Port $Port is occupied by PID $($listener.OwningProcess), but it did not pass the $Name health check. No process was stopped."
    }

    $stdout = Join-Path $WorkingDirectory "$LogPrefix.out.log"
    $stderr = Join-Path $WorkingDirectory "$LogPrefix.err.log"
    $process = Start-Process -FilePath $Executable -ArgumentList $Arguments -WorkingDirectory $WorkingDirectory -WindowStyle Hidden -PassThru -RedirectStandardOutput $stdout -RedirectStandardError $stderr
    Write-Host "$Name launch requested (PID $($process.Id)); log: $stdout"
}

function Start-NexoraScheduler() {
    $artisanPath = Join-Path $backend 'artisan'
    $running = Get-CimInstance Win32_Process | Where-Object {
        $_.ExecutablePath -eq $php -and $_.CommandLine -and
        $_.CommandLine.Contains($artisanPath) -and $_.CommandLine.Contains('schedule:work')
    } | Select-Object -First 1
    if ($running) {
        Write-Host "NEXORA scheduled worker is already running (PID $($running.ProcessId)); leaving it untouched." -ForegroundColor Green
        return
    }

    $stdout = Join-Path $backend 'local-scheduler.out.log'
    $stderr = Join-Path $backend 'local-scheduler.err.log'
    $process = Start-Process -FilePath $php -ArgumentList @(
        '-c', ('"{0}"' -f $ini), ('"{0}"' -f $artisanPath), 'schedule:work'
    ) -WorkingDirectory $backend -WindowStyle Hidden -PassThru -RedirectStandardOutput $stdout -RedirectStandardError $stderr
    Write-Host "NEXORA scheduled worker launch requested (PID $($process.Id)); log: $stdout"
}

Start-IsolatedService -Port 8017 -Url 'http://127.0.0.1:8017/api/v1/health' -Name 'NEXORA API' -Executable $php -Arguments @('-c', ('"{0}"' -f $ini), 'artisan', 'serve', '--host=127.0.0.1', '--port=8017') -WorkingDirectory $backend -LogPrefix 'local-serve'
Start-IsolatedService -Port 3017 -Url 'http://127.0.0.1:3017' -Name 'NEXORA Frontend' -Executable $npm -Arguments @('run', 'dev', '--', '--hostname', '127.0.0.1', '--port', '3017') -WorkingDirectory $frontend -LogPrefix 'local-serve'
Start-NexoraScheduler

Start-Sleep -Seconds 2
Write-Host "Frontend: http://127.0.0.1:3017" -ForegroundColor Cyan
Write-Host "API health: http://127.0.0.1:8017/api/v1/health" -ForegroundColor Cyan
Write-Host "Persistent local database: $localPreviewDb" -ForegroundColor Cyan
if (!(Test-NexoraEndpoint 'http://127.0.0.1:8017/api/v1/health')) {
    Write-Warning "API has not passed its health check yet. Inspect $(Join-Path $backend 'local-serve.err.log') and local-serve.out.log."
}
if (!(Test-NexoraEndpoint 'http://127.0.0.1:3017')) {
    Write-Warning 'Frontend has not returned HTTP 200 yet. Inspect frontend/local-serve.*.log.'
}
