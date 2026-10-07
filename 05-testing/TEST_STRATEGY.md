# Test Strategy

## Layers
Unit → Integration(real DB) → Contract → Architecture → Permission/Security → Migration/Upgrade/Restore → Concurrency/Failure → API → Browser E2E → Accessibility/Visual → Performance → Full Regression.

## Required principles
- Skipped required tests default allowance: 0. Environment exceptions need tracked ID/expiry.
- Coverage is supporting evidence, not sole quality metric.
- Critical finance/auth/pricing/domain/provisioning code gets property/invariant and mutation tests.
- Integration tests use real DB; do not mock every collaborator.
- Verified provider adapters pass shared contract suites and failure fixtures.
- Bugs follow Red→Green regression policy.
- Minimum shared-host profile and enhanced profile both have compatibility suites; V1 critical features must pass minimum profile.

## Evidence
Canonical evidence comes from commands/CI output, not AI prose: command, exit code, test count, fail/skip count, coverage, static analysis, security scan, performance result, changed files and commit.
