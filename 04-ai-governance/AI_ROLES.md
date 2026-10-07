# AI Roles and Separation of Duties

## Builder
Implements only the active spec/subphase. May add developer tests. Cannot approve gate or modify locked acceptance/security invariants.

## Reviewer
Receives locked spec + actual diff + evidence, ideally fresh context/model. Reviews architecture, security, correctness, missing scope and unauthorized scope. Does not trust Builder intent statements.

## Gatekeeper
Checks machine evidence, dependency gates, required test matrix, skipped tests/exceptions and reviewer result. Changes status only according to policy.

## Critical two-key areas
Authentication/Authorization; Payments/Billing/Finance/Tax; DB migrations; Backup/Update/Recovery; Encryption/Vault; Extension runtime; Privacy erasure; migration cutover. Builder plus independent Reviewer/Gatekeeper required.
