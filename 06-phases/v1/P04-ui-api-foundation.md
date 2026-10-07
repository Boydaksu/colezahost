# P04 — UI & API Foundation

**Release family:** V1
**Status:** PASS
**HARD phase dependencies:** P03

## Objective
Usable admin/client shells and API surface with no duplicated business logic.

## Entry criteria
- All HARD dependency phase gates are PASS.
- Locked spec/constitution/dependency graph are readable and unchanged unless approved CR exists.
- Required contracts from prior phases are frozen for this phase.
- No unresolved Critical/High blocker from prior phase.

## Subphases
### P04.1 — Design tokens + core component library
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P04.2 — Admin shell/sidebar/density/theme/Action Center skeleton
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P04.3 — Client shell/navigation
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P04.4 — DataTable/drawers/modals/workspace patterns
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P04.5 — Keyboard/Ctrl+K/global search foundation
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P04.6 — Accessibility test harness
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P04.7 — REST /api/v1 auth/scopes/errors/pagination/rate-limit/idempotency
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P04.8 — OpenAPI generation + API logs
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
