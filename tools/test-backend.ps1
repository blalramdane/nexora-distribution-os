param(
    [Parameter(ValueFromRemainingArguments = $true)]
    [string[]] $TestArguments
)

$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$backend = Join-Path $root 'backend'
$php = Join-Path $root '.tools\php83\php.exe'
$ini = Join-Path $root '.tools\php83\php.ini'
$previewDb = Join-Path $backend 'database\nexora-local-preview.sqlite'

if (!(Test-Path $php) -or !(Test-Path $ini)) {
    throw 'The repository-local PHP 8.3 runtime is missing.'
}

$envNames = @('APP_ENV', 'DB_CONNECTION', 'DB_DATABASE', 'DB_URL', 'CACHE_STORE', 'CACHE_DRIVER', 'SESSION_DRIVER', 'QUEUE_CONNECTION')
$priorEnvironment = @{}
foreach ($name in $envNames) {
    $priorEnvironment[$name] = [Environment]::GetEnvironmentVariable($name, 'Process')
}

$beforeHash = if (Test-Path $previewDb) { (Get-FileHash -LiteralPath $previewDb -Algorithm SHA256).Hash } else { $null }
$testExitCode = 1

try {
    # Apply these in the parent shell of Artisan/PHPUnit; do not rely on inherited
    # terminal variables or on PHPUnit's XML environment precedence alone.
    $env:APP_ENV = 'testing'
    $env:DB_CONNECTION = 'sqlite'
    $env:DB_DATABASE = ':memory:'
    $env:DB_URL = ''
    $env:CACHE_STORE = 'array'
    $env:CACHE_DRIVER = 'array'
    $env:SESSION_DRIVER = 'array'
    $env:QUEUE_CONNECTION = 'sync'

    Push-Location $backend
    try {
        & $php -c $ini artisan test @TestArguments
        $testExitCode = $LASTEXITCODE
    }
    finally {
        Pop-Location
    }
}
finally {
    foreach ($name in $envNames) {
        [Environment]::SetEnvironmentVariable($name, $priorEnvironment[$name], 'Process')
    }
}

$afterHash = if (Test-Path $previewDb) { (Get-FileHash -LiteralPath $previewDb -Algorithm SHA256).Hash } else { $null }
if ($beforeHash -ne $afterHash) {
    throw "SAFETY FAILURE: PHPUnit changed the local preview database. Before=$beforeHash After=$afterHash. Stop and restore the verified backup before continuing."
}

Write-Host "Preview database isolation: PASS (SHA-256 unchanged: $afterHash)" -ForegroundColor Green
exit $testExitCode
