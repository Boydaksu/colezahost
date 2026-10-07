# Release Gate Policy

Stable V1 requires:
- all V1-MUST phase/domain gates PASS;
- full regression; zero unexplained required skips;
- security/secret/dependency scans;
- minimum shared-host profile pass;
- performance/concurrency/failure/chaos budgets;
- installer fresh install + WHMCS fresh-migration mode;
- update from supported previous RC/version;
- backup→destroy→restore verification;
- Recovery/Safe Mode exercise;
- Golden E2E G01–G08;
- module compatibility checks;
- migration financial/service/domain reconciliation;
- release notes, checksums/signature, rollback/runbook validation.

RC and Stable are separate approvals.
