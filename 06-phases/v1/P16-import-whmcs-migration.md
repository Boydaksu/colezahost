# P16 — Import & WHMCS Migration

**Release family:** V1
**Status:** PASS
**HARD phase dependencies:** P15

## Objective
Safe production transition from WHMCS with zero silent data loss.

## Entry criteria
- All HARD dependency phase gates are PASS.
- Locked spec/constitution/dependency graph are readable and unchanged unless approved CR exists.
- Required contracts from prior phases are frozen for this phase.
- No unresolved Critical/High blocker from prior phase.

## Subphases
### P16.1 — Generic source/staging/canonical DTO/mapping/validation engine
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P16.2 — CSV import + locale-aware parsing
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P16.3 — Adopt existing service/domain/provider identity workflows
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P16.4 — WHMCS source scan/version/capability/read-only connector
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P16.5 — Migrate clients/orgs/products/services/domains
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P16.6 — Migrate invoices/payments/credits with financial reconciliation
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P16.7 — Tickets/attachments/custom fields and unsupported-data accounting
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P16.8 — Dry-run/conflict/quarantine/checkpoint/resume/idempotency
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P16.9 — Migration Hold/automation+notification suppression
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P16.10 — Cutover checklist/final reconciliation/seal + Golden Migration E2E
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
