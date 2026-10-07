# P06 — Manual Commerce Vertical

**Release family:** V1
**Status:** PASS
**HARD phase dependencies:** P05

## Objective
First real vertical slice without external providers.

## Entry criteria
- All HARD dependency phase gates are PASS.
- Locked spec/constitution/dependency graph are readable and unchanged unless approved CR exists.
- Required contracts from prior phases are frozen for this phase.
- No unresolved Critical/High blocker from prior phase.

## Subphases
### P06.1 — Order state machine and admin/client order creation
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P06.2 — Invoice finalization/numbering/line-tax-currency snapshots
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P06.3 — Payment entity + allocations + manual/bank payment
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P06.4 — Partial/split payments and basic refunds
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P06.5 — Credit ledger and adjustments
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P06.6 — Recurring/renewal invoice primitives
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P06.7 — Manual service creation/state model
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P06.8 — Golden Manual Commerce E2E
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
