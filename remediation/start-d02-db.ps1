$ErrorActionPreference = 'Stop'
$taskRoot = Split-Path -Parent $PSScriptRoot
$taskRuntime = Join-Path $PSScriptRoot 'runtime'
$taskData = Join-Path $taskRuntime 'mariadb-d02'
$taskBin = 'C:\ServBay\packages\mariadb\10.11\bin'
New-Item -ItemType Directory -Path $taskRuntime -Force | Out-Null
if (!(Test-Path -LiteralPath (Join-Path $taskData 'mysql'))) {
    & (Join-Path $taskBin 'mariadb-install-db.exe') "--datadir=$taskData" '--port=33079' '--password=colezahost-local-test-only' '--silent'
    if ($LASTEXITCODE -ne 0) { throw 'Test database initialization failed.' }
}
$taskArguments = @('--no-defaults', ('--datadir="' + $taskData + '"'), '--port=33079', '--bind-address=127.0.0.1', '--skip-name-resolve', '--innodb-flush-log-at-trx-commit=1', '--character-set-server=utf8mb4', '--collation-server=utf8mb4_unicode_ci', '--console')
$taskProcess = Start-Process -FilePath (Join-Path $taskBin 'mariadbd.exe') -ArgumentList $taskArguments -WorkingDirectory $taskRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $taskRuntime 'server-out.txt') -RedirectStandardError (Join-Path $taskRuntime 'server-error.txt')
$taskProcess.Id | Set-Content -LiteralPath (Join-Path $taskRuntime 'server.pid')
Write-Output "Isolated MariaDB started; PID $($taskProcess.Id); port 33079."
