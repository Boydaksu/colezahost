# P11 — Renewal & Automation Hardening

**Release family:** V1
**Status:** PASS
**HARD phase dependencies:** P10

## Objective
Lifecycle beyond first sale is production-ready.

## Entry criteria
- All HARD dependency phase gates are PASS.
- Locked spec/constitution/dependency graph are readable and unchanged unless approved CR exists.
- Required contracts from prior phases are frozen for this phase.
- No unresolved Critical/High blocker from prior phase.

## Subphases
### P11.1 — Automation engine trigger/conditions/IF-ELSE/actions
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P11.2 — Approval/delay/observe/dry-run/version/run history
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P11.3 — Domain command adapters for automation (no direct repository)
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P11.4 — Service renewal scheduler and invoice generation
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P11.5 — Overdue/grace policy and suspend/unsuspend workflow
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P11.6 — Emergency Pause All/Destructive actions
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P11.7 — Renewal Golden E2E and missed-scheduler catchup
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
