# P02 — Platform Primitives

**Release family:** V1
**Status:** PASS
**HARD phase dependencies:** P01

## Objective
Shared-host compatible operational primitives.

## Entry criteria
- All HARD dependency phase gates are PASS.
- Locked spec/constitution/dependency graph are readable and unchanged unless approved CR exists.
- Required contracts from prior phases are frozen for this phase.
- No unresolved Critical/High blocker from prior phase.

## Subphases
### P02.1 — Structured logging + correlation/request/operation IDs
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P02.2 — Private storage abstraction
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P02.3 — Cache contract + DB/file baseline
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P02.4 — Lock/idempotency primitives
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P02.5 — Database queue + priority/retry/DLQ
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P02.6 — Scheduler + overlap/missed-run/heartbeat
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P02.7 — Health contracts + installer/backup skeletons
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P02.8 — Runtime/memory budget and graceful worker stop
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
