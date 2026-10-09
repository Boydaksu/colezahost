param([ValidatePattern('^D02-[0-9]+[a-z]?$')][string]$EvidencePrefix = 'D02-3b')
$ErrorActionPreference = 'Stop'
Set-Location -LiteralPath (Split-Path -Parent $PSScriptRoot)
$env:COLEZA_TEST_MARIADB_DSN = 'mysql:host=127.0.0.1;port=33079;charset=utf8mb4'
$env:COLEZA_TEST_MARIADB_USER = 'root'
$env:COLEZA_TEST_MARIADB_PASSWORD = 'colezahost-local-test-only'
& php vendor/phpunit/phpunit/phpunit -c phpunit-mariadb.xml --log-junit "remediation/$EvidencePrefix-mariadb.xml" *> "remediation/$EvidencePrefix-mariadb.txt"
if ($LASTEXITCODE -ne 0) { Get-Content "remediation/$EvidencePrefix-mariadb.txt"; throw 'MariaDB tests failed.' }
& php vendor/phpunit/phpunit/phpunit --log-junit "remediation/$EvidencePrefix-suite.xml" *> "remediation/$EvidencePrefix-suite.txt"
if ($LASTEXITCODE -ne 0) { Get-Content "remediation/$EvidencePrefix-suite.txt"; throw 'Main test suite failed.' }
Get-Content "remediation/$EvidencePrefix-mariadb.txt" -Tail 5
Get-Content "remediation/$EvidencePrefix-suite.txt" -Tail 5
