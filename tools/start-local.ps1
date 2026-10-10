$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$backend = Join-Path $root 'backend'
$frontend = Join-Path $root 'frontend'
$php = Join-Path $root '.tools\php83\php.exe'
$ini = Join-Path $root '.tools\php83\php.ini'
$npm = (Get-Command npm.cmd -ErrorAction Stop).Source

if (!(Test-Path (Join-Path $backend 'vendor\autoload.php'))) {
    throw 'Backend dependencies are missing. Run composer install in backend first.'
}
if (!(Test-Path (Join-Path $frontend 'node_modules\next\package.json'))) {
    throw 'Frontend dependencies are missing. Run npm ci in frontend first.'
}
if (!(Test-Path $php) -or !(Test-Path $ini)) {
    throw 'Project-scoped PHP 8.3 was not found under .tools\php83. Install PHP 8.3+ or restore the local runtime.'
}

$env:PHPRC = $ini
$env:PHP_INI_SCAN_DIR = ''

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

Start-IsolatedService -Port 8017 -Url 'http://127.0.0.1:8017/api/v1/health' -Name 'NEXORA API' -Executable $php -Arguments @('-c', $ini, 'artisan', 'serve', '--host=127.0.0.1', '--port=8017') -WorkingDirectory $backend -LogPrefix 'local-serve'
Start-IsolatedService -Port 3017 -Url 'http://127.0.0.1:3017' -Name 'NEXORA Frontend' -Executable $npm -Arguments @('run', 'dev', '--', '--hostname', '127.0.0.1', '--port', '3017') -WorkingDirectory $frontend -LogPrefix 'local-serve'

Start-Sleep -Seconds 2
Write-Host "Frontend: http://127.0.0.1:3017" -ForegroundColor Cyan
Write-Host "API health: http://127.0.0.1:8017/api/v1/health" -ForegroundColor Cyan
if (!(Test-NexoraEndpoint 'http://127.0.0.1:8017/api/v1/health')) {
    Write-Warning 'API has not passed its health check yet. Inspect backend/storage/logs/local-serve.*.log.'
}
if (!(Test-NexoraEndpoint 'http://127.0.0.1:3017')) {
    Write-Warning 'Frontend has not returned HTTP 200 yet. Inspect frontend/local-serve.*.log.'
}
