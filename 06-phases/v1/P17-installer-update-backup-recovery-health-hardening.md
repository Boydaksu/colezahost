# P17 — Installer, Update, Backup, Recovery & Health Hardening

**Release family:** V1
**Status:** PLANNED
**HARD phase dependencies:** P16

## Objective
System can be installed, updated, moved and recovered safely.

## Entry criteria
- All HARD dependency phase gates are PASS.
- Locked spec/constitution/dependency graph are readable and unchanged unless approved CR exists.
- Required contracts from prior phases are frozen for this phase.
- No unresolved Critical/High blocker from prior phase.

## Subphases
### P17.1 — Web installer requirements/DB/admin/locale/brand/email/cron
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P17.2 — Fresh-install migration mode
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P17.3 — Signed/checksummed staged update + module compatibility
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P17.4 — Mandatory verified pre-update backup
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P17.5 — Backup full/DB/files; Local/SFTP/S3; encryption/manifest/retention
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P17.6 — Restore wizard + disaster recovery on fresh hosting
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P17.7 — Normal/Safe/Recovery/read-only/full-maintenance modes
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P17.8 — System Health/Doctor for cron/queue/storage/DB/providers/modules/backup
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P17.9 — Rollback paths and privacy tombstone application after restore
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.

## Required implementation discipline
- Each subphase has its own checklist/evidence; later subphase does not excuse prior failure.
- UI/API/CLI/automation paths reuse Application Layer.
- No speculative V1.1/V2 feature implementation.
- Any schema change has migration + upgrade test.
- Any critical bug fix starts with a failing regression test when reproducible.

## Required tests
- Unit + integration for new behavior.
- Architecture/dependency checks.
- Permission/security tests for new reachable operations.
- Applicable concurrency/idempotency/failure tests.
- Shared-host baseline compatibility for infrastructure touched.
- Full affected-area regression; phase-specific tests from `05-testing/TEST_MATRIX.md`.

## Evidence package
- manifest/spec/commit; changed files; commands + exit codes.
- Test/static/architecture/security results; coverage.
- Applicable performance/concurrency/migration/UI screenshots.
- Reviewer report and Gatekeeper decision.

## Explicitly forbidden
- Next phase implementation before PASS.
- Acceptance/test weakening, skips without exception, fake evidence.
- Scope expansion beyond this phase/release family.
- Direct cross-domain repository/DB bypass.

## Exit / Gate
Outcome must be demonstrably achieved, every subphase accepted, required evidence complete, independent review PASS, and no unapproved debt/blocker remains.
