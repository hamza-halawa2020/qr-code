$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$pidFile = Join-Path $projectRoot 'storage\app\private\dev-processes.json'
if (-not (Test-Path -LiteralPath $pidFile)) { Write-Output 'No managed development processes.'; return }
foreach ($record in @(Get-Content -LiteralPath $pidFile -Raw | ConvertFrom-Json)) {
    if ($record.root -ne $projectRoot) { throw 'Process record belongs to another project; refused.' }
    $process = Get-Process -Id $record.pid -ErrorAction SilentlyContinue
    if ($process -and $process.StartTime.ToUniversalTime().Ticks -eq $record.startedTicks) {
        Stop-Process -Id $process.Id -ErrorAction Stop
        Write-Output "$($record.name): stopped"
    }
}
Remove-Item -LiteralPath $pidFile
