# P18 — V1 Release Candidate & Stable Gate

**Release family:** V1
**Status:** PLANNED
**HARD phase dependencies:** P17

## Objective
Signed, reproducible, evidence-backed V1 stable release.

## Entry criteria
- All HARD dependency phase gates are PASS.
- Locked spec/constitution/dependency graph are readable and unchanged unless approved CR exists.
- Required contracts from prior phases are frozen for this phase.
- No unresolved Critical/High blocker from prior phase.

## Subphases
### P18.1 — Freeze V1 feature scope and run full dependency/gate audit
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P18.2 — Run G01–G08 Golden E2E
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P18.3 — Full static/architecture/security/secret/dependency scans
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P18.4 — Concurrency/failure/chaos/provider uncertainty suite
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P18.5 — Performance/N+1/reference-budget regression
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P18.6 — Minimum shared-host compatibility matrix
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P18.7 — Fresh install + upgrade + backup/restore + WHMCS cutover rehearsal
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P18.8 — TR/EN completeness + accessibility/visual regression
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P18.9 — Release package vendor/assets/checksum/signature/release notes
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P18.10 — RC soak/issue closure → independent Stable approval
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
