param([int]$Port = 8000, [string]$PhpPath = '')

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$privateDir = Join-Path $projectRoot 'storage\app\private'
$pidFile = Join-Path $privateDir 'dev-processes.json'
if (-not $PhpPath) {
    $candidate = Join-Path $env:USERPROFILE '.config\herd\bin\php84\php.exe'
    if (Test-Path -LiteralPath $candidate) { $PhpPath = $candidate }
    else {
        $command = Get-Command php.exe -ErrorAction SilentlyContinue
        if (-not $command) { throw 'PHP not found. Supply -PhpPath pointing to php.exe.' }
        $PhpPath = $command.Source
    }
}
$PhpPath = (Resolve-Path -LiteralPath $PhpPath).Path
New-Item -ItemType Directory -Path $privateDir -Force | Out-Null
$records = @()
if (Test-Path -LiteralPath $pidFile) {
    foreach ($record in @(Get-Content -LiteralPath $pidFile -Raw | ConvertFrom-Json)) {
        $process = Get-Process -Id $record.pid -ErrorAction SilentlyContinue
        if ($process -and $process.StartTime.ToUniversalTime().Ticks -eq $record.startedTicks) { $records += $record }
    }
}
$definitions = @(
    @{ name='server'; args=@('-d','auto_prepend_file=','-S',"127.0.0.1:$Port",'-t','public','scripts/local-router.php') },
    @{ name='worker'; args=@('-d','auto_prepend_file=','artisan','queue:work','--sleep=3','--tries=5','--timeout=120','--no-ansi') },
    @{ name='scheduler'; args=@('-d','auto_prepend_file=','artisan','schedule:work','--no-ansi') }
)
foreach ($definition in $definitions) {
    if ($records | Where-Object { $_.name -eq $definition.name }) { Write-Output "$($definition.name): already running"; continue }
    if ($definition.name -eq 'server' -and (Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue)) {
        Write-Output "Port $Port already has a server; it is not managed or stopped by this script."
        continue
    }
    $process = Start-Process -FilePath $PhpPath -ArgumentList $definition.args -WorkingDirectory $projectRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $privateDir "$($definition.name).out.log") -RedirectStandardError (Join-Path $privateDir "$($definition.name).err.log")
    $records += [pscustomobject]@{ name=$definition.name; pid=$process.Id; startedTicks=$process.StartTime.ToUniversalTime().Ticks; root=$projectRoot }
    [IO.File]::WriteAllText($pidFile, (ConvertTo-Json -InputObject @($records) -Depth 5))
    Write-Output "$($definition.name): started"
}
Write-Output "Customers: http://127.0.0.1:$Port | Admin: http://127.0.0.1:$Port/admin"
Write-Output 'Local development only. Restart these processes after changing Firebase configuration.'
