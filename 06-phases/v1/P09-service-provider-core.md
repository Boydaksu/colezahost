# P09 — Service & Provider Core

**Release family:** V1
**Status:** PASS
**HARD phase dependencies:** P08

## Objective
Provider-independent service operations ready for adapter.

## Entry criteria
- All HARD dependency phase gates are PASS.
- Locked spec/constitution/dependency graph are readable and unchanged unless approved CR exists.
- Required contracts from prior phases are frozen for this phase.
- No unresolved Critical/High blocker from prior phase.

## Subphases
### P09.1 — Service lifecycle/billing/placement relations
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P09.2 — Provider capability contracts and operation DTOs
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P09.3 — Server/Server Pool/location/capacity models
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P09.4 — Placement Engine basic health/capacity/priority
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P09.5 — Capacity reserve→commit/release workflow
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P09.6 — Provisioning operation/error classification contracts
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P09.7 — Provider module settings/Vault mappings
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P09.8 — Service concurrency and optimistic-edit tests
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
