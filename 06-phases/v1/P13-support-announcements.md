# P13 — Support & Announcements

**Release family:** V1
**Status:** PASS
**HARD phase dependencies:** P12

## Objective
Production service desk baseline.

## Entry criteria
- All HARD dependency phase gates are PASS.
- Locked spec/constitution/dependency graph are readable and unchanged unless approved CR exists.
- Required contracts from prior phases are frozen for this phase.
- No unresolved Critical/High blocker from prior phase.

## Subphases
### P13.1 — Departments/agents/status/priority
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P13.2 — Ticket/conversation/replies/internal notes
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P13.3 — Private attachments and file security
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P13.4 — Manual + round-robin assignment
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P13.5 — Basic first-response/resolution SLA scheduler
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P13.6 — Relations to service/domain/invoice/order and contextual commands
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P13.7 — Organization own/all ticket permissions
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P13.8 — Canned responses/basic announcement UI
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P13.9 — Support Golden E2E
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
