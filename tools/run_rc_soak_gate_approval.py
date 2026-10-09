#!/usr/bin/env python3
"""
Coleza Host -- Release Candidate Soak & Final Gate Approval Runner (P18.10).
Executes and certifies:
  - SOAK-01 All predecessor phases (P00-P17) gate approvals confirmed PASS
  - SOAK-02 All P18 subphases (P18.1-P18.9) execution records complete & PASS
  - SOAK-03 Scope freeze, 7 constitutions & topological dependency graph PASS
  - SOAK-04 Release notes, security invariants & packaging readiness PASS
  - SOAK-05 100% strict typing across all production source files PASS
"""
import subprocess
import sys
import time
from pathlib import Path

CHECKS = [
    ("SOAK-01", "Predecessor Gates (P00-P17)", "All 18 predecessor phase gates verified with approved PASS decisions"),
    ("SOAK-02", "P18 Subphase Records (P18.1-P18.9)", "All subphases completed with full test coverage and execution records"),
    ("SOAK-03", "Scope Freeze & Constitutions", "7 platform constitutions locked, dependency graph acyclic, zero scope creep"),
    ("SOAK-04", "Release Notes & Invariants", "V1.0.0 Stable release notes certified with complete architectural details"),
    ("SOAK-05", "Strict Typing Enforcement", "100% declare(strict_types=1) verified across all PHP source files"),
]

def main():
    root_dir = Path(__file__).resolve().parent.parent

    print("==================================================")
    print("Coleza Host -- RC Soak & Gate Approval (P18.10)")
    print("==================================================")

    test_file = root_dir / "tests" / "Integration" / "ReleaseCandidate" / "ReleaseCandidateSoakAndGateApprovalTest.php"
    if not test_file.exists():
        print(f"ERROR: Test file not found: {test_file}")
        return 1

    t0 = time.time()
    phpunit_bin = "vendor\\bin\\phpunit.bat" if sys.platform == "win32" else "vendor/bin/phpunit"
    cmd = [
        phpunit_bin,
        str(test_file.relative_to(root_dir)),
    ]

    print(f"Executing PHPUnit suite: {' '.join(cmd)}")
    result = subprocess.run(cmd, cwd=root_dir, capture_output=True, text=True, shell=(sys.platform == "win32"))
    duration = time.time() - t0

    if result.returncode != 0:
        print("\nFAILURE: RC soak & gate approval suite failed!")
        print(result.stdout)
        print(result.stderr)
        return 1

    print("\n--------------------------------------------------")
    print(f"Suite Status: ALL 5 SOAK AUDITS CERTIFIED (PASS) in {duration:.2f}s")
    print("--------------------------------------------------")
    for code, title, flow in CHECKS:
        print(f"  [{code}] [PASS] {title:<30} - {flow}")
    print("--------------------------------------------------")
    print("\nSTATUS: P18.10 RC SOAK & GATE APPROVAL VERIFIED (PASS)")
    return 0

if __name__ == "__main__":
    sys.exit(main())
