#!/usr/bin/env python3
"""
Coleza Host -- Release Rehearsal Lifecycle Suite Runner (P18.7).
Executes and certifies full lifecycle rehearsals:
  - RH-01 Fresh Installation Lifecycle & Lock Enforcement
  - RH-02 Cryptographically Signed Upgrade & Staged Update
  - RH-03 Disaster Recovery Backup, Restore & Privacy Tombstone Reconciliation
  - RH-04 WHMCS Cutover Rehearsal, Financial Reconciliation & Cryptographic Audit Seal
"""
import subprocess
import sys
import time
from pathlib import Path

REHEARSALS = [
    ("RH-01", "Fresh Install & Lockout", "End-to-end installation wizard execution with immutable lockfile lockout"),
    ("RH-02", "Signed Staged Upgrade", "Ed25519 cryptographic package validation, checksum enforcement, atomic deploy & tamper rejection"),
    ("RH-03", "DR Backup & Restore", "Full archive creation, disaster recovery restore, GDPR tombstone reconciliation & 0 PII resurrection"),
    ("RH-04", "WHMCS Cutover & Seal", "Full migration orchestrator, zero-diff financial ledger reconciliation & cryptographic audit seal"),
]

def main():
    root_dir = Path(__file__).resolve().parent.parent

    print("==================================================")
    print("Coleza Host -- Release Rehearsal Suite (P18.7)")
    print("==================================================")

    test_file = root_dir / "tests" / "Integration" / "ReleaseRehearsal" / "ReleaseRehearsalLifecycleSuiteTest.php"
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
        print("\nFAILURE: Release rehearsal lifecycle suite failed!")
        print(result.stdout)
        print(result.stderr)
        return 1

    print("\n--------------------------------------------------")
    print(f"Suite Status: ALL 4 RELEASE REHEARSALS CERTIFIED (PASS) in {duration:.2f}s")
    print("--------------------------------------------------")
    for code, title, flow in REHEARSALS:
        print(f"  [{code}] [PASS] {title:<25} - {flow}")
    print("--------------------------------------------------")
    print("\nSTATUS: P18.7 RELEASE REHEARSAL LIFECYCLE SUITE VERIFIED (PASS)")
    return 0

if __name__ == "__main__":
    sys.exit(main())
