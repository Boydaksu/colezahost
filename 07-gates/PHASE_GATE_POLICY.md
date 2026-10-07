# Phase Gate Policy

Statuses: PLANNED → READY → IN_PROGRESS → REVIEW → PASS or REJECTED/BLOCKED. Only Gatekeeper changes REVIEW→PASS.

PASS requires: all subphase acceptance PASS, HARD dependencies PASS, no unauthorized scope change, required test matrix PASS, skipped-test policy satisfied, static/security/architecture checks PASS, documentation/machine metadata current, evidence complete, independent review PASS, no unapproved TODO/debt.

Any required item FAIL => phase FAIL/BLOCKED; next phase implementation prohibited.
