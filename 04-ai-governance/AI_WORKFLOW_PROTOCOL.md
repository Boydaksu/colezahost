# AI Workflow Protocol

## Before any code
1. Read constitutions, current phase, machine roadmap/scope/dependencies.
2. Verify only one subphase is ACTIVE/READY.
3. Verify every HARD dependency gate is PASS.
4. Produce a short execution record: intended files/contracts/tests; explicitly list non-scope.
5. If dependency/spec conflict exists, stop implementation and create CR/DBR; do not guess.

## During implementation
1. Make smallest coherent change for active subphase.
2. Preserve Application Layer and domain boundaries.
3. Add tests together with code; use real DB in integration suites.
4. Never change locked acceptance/security invariant tests.
5. Record failures rather than hiding them; no catch-and-ignore patches.
6. Keep migration/backward compatibility and TR/EN user-facing strings synchronized.

## Before review
1. Run subphase-required test set and impacted regression.
2. Scan skips/TODO/FIXME/HACK/temporary bypasses.
3. Generate evidence from actual command outputs.
4. Compare changed files to planned scope and flag unexpected changes.
5. Builder status becomes `IMPLEMENTATION_COMPLETE`, not PASS.

## Review/Gate
Reviewer evaluates locked spec vs diff/evidence. Gatekeeper checks dependencies, evidence, thresholds, test-count regression and reviewer decision. Only Gatekeeper can mark PASS. Next subphase may become READY only after PASS.
