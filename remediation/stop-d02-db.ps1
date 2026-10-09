$ErrorActionPreference = 'Stop'
$taskRuntime = Join-Path $PSScriptRoot 'runtime'
$taskPidFile = Join-Path $taskRuntime 'server.pid'
if (!(Test-Path -LiteralPath $taskPidFile)) { return }
$taskPid = [int](Get-Content -LiteralPath $taskPidFile)
$taskProcess = Get-CimInstance Win32_Process -Filter "ProcessId = $taskPid"
$taskData = Join-Path $taskRuntime 'mariadb-d02'
if (!$taskProcess) { Write-Output 'Test server is already stopped.'; return }
if ($taskProcess.ExecutablePath -ne 'C:\ServBay\packages\mariadb\10.11\bin\mariadbd.exe' -or
    !$taskProcess.CommandLine.Contains($taskData) -or !$taskProcess.CommandLine.Contains('--port=33079')) {
    throw 'PID does not belong to the isolated D02 server; refusing to stop it.'
}
$taskClientFile = Join-Path $taskRuntime 'shutdown-client.ini'
@"
[client]
user=root
password=colezahost-local-test-only
host=127.0.0.1
port=33079
"@ | Set-Content -LiteralPath $taskClientFile
& 'C:\ServBay\packages\mariadb\10.11\bin\mariadb-admin.exe' "--defaults-extra-file=$taskClientFile" shutdown
if ($LASTEXITCODE -ne 0) { throw 'Test server shutdown failed.' }
Write-Output 'Isolated D02 MariaDB server stopped cleanly.'
