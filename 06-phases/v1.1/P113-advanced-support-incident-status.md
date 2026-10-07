# P113 — Advanced Support, Incident & Status

**Release family:** V1.1
**Status:** PLANNED
**HARD phase dependencies:** P112

## Objective
Mature service desk and incident communication.

## Entry criteria
- All HARD dependency phase gates are PASS.
- Locked spec/constitution/dependency graph are readable and unchanged unless approved CR exists.
- Required contracts from prior phases are frozen for this phase.
- No unresolved Critical/High blocker from prior phase.

## Subphases
### P113.1 — Advanced SLA calendars/pause/escalation
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P113.2 — Least-busy and optional skill-based assignment
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P113.3 — Merge/split/collision detection
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P113.4 — Incident/parent-ticket bulk communication
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P113.5 — Client-facing Status Page integration
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P113.6 — Email piping/IMAP/provider inbound improvements
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
