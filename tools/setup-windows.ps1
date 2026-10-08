# NEXORA Distribution OS - Windows local setup
$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent $PSScriptRoot
$Backend = Join-Path $Root "backend"
$Frontend = Join-Path $Root "frontend"
$Tools = Join-Path $Root ".tools"
$PhpDir = Join-Path $Tools "php83"
$PhpZip = Join-Path $Tools "php83.zip"
$PhpVersion = "8.3.33"
$PhpUrl = "https://windows.php.net/downloads/releases/php-$PhpVersion-nts-Win32-vs16-x64.zip"
function Write-Step($Message) { Write-Host ("==> " + $Message) -ForegroundColor Cyan }
$systemPhp = $null
try { $systemPhp = (& php -r "echo PHP_VERSION;" 2>$null).Trim() } catch {}
$useLocalPhp = $true
if ($systemPhp) { try { $useLocalPhp = ([version]$systemPhp -lt [version]"8.3.0") } catch {} }
if ($useLocalPhp) {
    New-Item -ItemType Directory -Force -Path $Tools | Out-Null
    if (-not (Test-Path (Join-Path $PhpDir "php.exe"))) {
        Write-Step "Downloading project-local PHP $PhpVersion"
        Invoke-WebRequest -Uri $PhpUrl -OutFile $PhpZip
        New-Item -ItemType Directory -Force -Path $PhpDir | Out-Null
        Expand-Archive -Path $PhpZip -DestinationPath $PhpDir -Force
        Remove-Item $PhpZip -Force
    }
    $env:PATH = "$PhpDir;$env:PATH"
}
$phpVersion = (& php -r "echo PHP_VERSION;").Trim()
if ([version]$phpVersion -lt [version]"8.3.0") { throw "NEXORA requires PHP 8.3+. Detected $phpVersion." }
Write-Host "Using PHP $phpVersion"
if (-not (Test-Path (Join-Path $PhpDir "php.ini"))) {
    if (Test-Path (Join-Path $PhpDir "php.ini-development")) { Copy-Item (Join-Path $PhpDir "php.ini-development") (Join-Path $PhpDir "php.ini") }
}
if (Test-Path (Join-Path $PhpDir "php.ini")) {
    $ini = Get-Content (Join-Path $PhpDir "php.ini") -Raw
    foreach ($ext in @("curl","fileinfo","mbstring","openssl","pdo_mysql","bcmath","intl","zip")) { $ini = $ini -replace "(?m)^;extension=$ext\s*$", "extension=$ext" }
    Set-Content (Join-Path $PhpDir "php.ini") $ini -Encoding UTF8
}
if (-not (Get-Command composer -ErrorAction SilentlyContinue)) { throw "Composer is not installed." }
$envFile = Join-Path $Backend ".env"
if (-not (Test-Path $envFile)) { Copy-Item (Join-Path $Backend ".env.example") $envFile }
$mysql = "C:\xampp\mysql\bin\mysql.exe"
if (Test-Path $mysql) {
    & $mysql -u root -e "CREATE DATABASE IF NOT EXISTS nexora_distribution CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
} else { Write-Host "XAMPP MySQL not found. Start MySQL and create nexora_distribution." -ForegroundColor Yellow }
Push-Location $Backend
try {
    composer install
    if (-not (Select-String -Path ".env" -Pattern "^APP_KEY=base64:" -Quiet)) { php artisan key:generate }
    php artisan migrate --seed
} finally { Pop-Location }
Push-Location $Frontend
try { npm install } finally { Pop-Location }
Write-Host "NEXORA local setup completed." -ForegroundColor Green
