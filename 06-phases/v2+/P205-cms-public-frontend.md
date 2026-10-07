# P205 — CMS / Public Frontend

**Release family:** V2+
**Status:** PLANNED
**HARD phase dependencies:** P204

## Objective
Optional public web presence.

## Entry criteria
- All HARD dependency phase gates are PASS.
- Locked spec/constitution/dependency graph are readable and unchanged unless approved CR exists.
- Required contracts from prior phases are frozen for this phase.
- No unresolved Critical/High blocker from prior phase.

## Subphases
### P205.1 — Public site/content bounded context
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P205.2 — Blog/pages/content localization
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P205.3 — SEO/cache/theme integration
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P205.4 — Commerce/catalog public presentation
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P205.5 — Strict separation from client/admin business logic
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
