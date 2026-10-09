# P15 — Analytics & Business Intelligence Baseline

**Release family:** V1
**Status:** PASS
**HARD phase dependencies:** P14

## Objective
Trusted dashboards without making analytics source-of-truth.

## Entry criteria
- All HARD dependency phase gates are PASS.
- Locked spec/constitution/dependency graph are readable and unchanged unless approved CR exists.
- Required contracts from prior phases are frozen for this phase.
- No unresolved Critical/High blocker from prior phase.

## Subphases
### P15.1 — Metric Registry + Semantic Layer definitions
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P15.2 — MRR/ARR/new/churned MRR and subscription snapshots
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P15.3 — Invoice vs cash vs outstanding/refund/contribution metrics
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P15.4 — Daily/monthly read models/aggregations + rebuild
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P15.5 — Owner/Finance/Sales/Operations/Support dashboards
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P15.6 — Currency/timezone/data freshness semantics
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P15.7 — Golden Financial Dataset + ledger reconciliation
- Implement only this subphase scope.
- Add/update required tests and evidence before marking subphase complete.
### P15.8 — Analytics permission/data-scope tests
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
