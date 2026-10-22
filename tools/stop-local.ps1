$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$phpExecutable = Join-Path $root '.tools\php83\php.exe'
$artisanPath = Join-Path $root 'backend\artisan'
$frontendPath = Join-Path $root 'frontend'
$matched = Get-CimInstance Win32_Process | Where-Object {
    $command = $_.CommandLine
    $apiMatch = $command -and $_.ExecutablePath -eq $phpExecutable -and $command.Contains('artisan serve --host=127.0.0.1 --port=8017')
    $schedulerMatch = $command -and $_.ExecutablePath -eq $phpExecutable -and $command.Contains($artisanPath) -and $command.Contains('schedule:work')
    $webMatch = $command -and $command.Contains($frontendPath) -and (
        $command.Contains('next dev --hostname 127.0.0.1 --port 3017') -or
        $command.Contains('next dev --hostname 0.0.0.0 --port 3017')
    )
    $apiMatch -or $schedulerMatch -or $webMatch
}

if (!$matched) {
    Write-Host 'No matching NEXORA Distribution OS local processes were found. Nothing was stopped.'
    exit 0
}

foreach ($process in $matched) {
    Write-Host "Stopping NEXORA Distribution OS process PID $($process.ProcessId)"
    Stop-Process -Id $process.ProcessId -Force
}

Write-Host 'Only processes whose command line matched this repository and its dedicated ports were targeted.'
