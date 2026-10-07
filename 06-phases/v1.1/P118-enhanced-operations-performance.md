# P118 — Enhanced Operations & Performance

**Release family:** V1.1
**Status:** PLANNED
**HARD phase dependencies:** P117

## Objective
Enhanced-server profile and V1.1 stable release.

## Entry criteria
- All HARD dependency phase gates are PASS.
- Locked spec/constitution/dependency graph are readable and unchanged unless approved CR exists.
- Required contracts from prior phases are frozen for this phase.
- No unresolved Critical/High blocker from prior phase.

## Subphases
### P118.1 — Redis cache/queue/lock adapters
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P118.2 — Continuous worker/Supervisor profile documentation
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P118.3 — Advanced placement/resource pools
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P118.4 — Optional search adapter after proven need
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P118.5 — Contract amendments + document QR verification
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P118.6 — Additional theme/operator UX improvements
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P118.7 — V1.1 full regression and release gate
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
