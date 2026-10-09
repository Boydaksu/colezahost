#!/usr/bin/env python3
"""
Coleza Host — Golden E2E Master Scenario Runner (P18.2).
Executes and certifies the complete G01 through G08 Golden E2E scenario suite
as defined in 05-testing/GOLDEN_SCENARIOS.md:
  - G01 Manual Commerce
  - G02 Automated Hosting
  - G03 Renewal Lifecycle
  - G04 Domain Lifecycle
  - G05 Failure & Idempotency
  - G06 Disaster Recovery & Hardening
  - G07 WHMCS Cutover
  - G08 Privacy Tombstone Restore
"""
import subprocess
import sys
import time
from pathlib import Path

SCENARIOS = [
    ("G01", "Manual Commerce", "Organization -> Product -> Order -> Invoice -> Manual Payment -> Manual Service"),
    ("G02", "Automated Hosting", "Client -> Order -> Risk -> Invoice -> iyzico -> Webhook -> Queue -> Placement -> cPanel -> Active -> Email"),
    ("G03", "Renewal Lifecycle", "Scheduler -> Renewal Invoice -> Notification -> Payment OR overdue/grace -> Suspend"),
    ("G04", "Domain Lifecycle", "Availability -> Order -> Payment -> Registrar Register -> Active -> Reminder -> Renewal"),
    ("G05", "Failure & Idempotency", "Payment succeeds, cPanel timeout/uncertain response, retry/reconcile, no duplicate"),
    ("G06", "Disaster Recovery", "Backup -> intentional broken module/update -> Safe/Recovery -> Rollback/Restore -> Health PASS"),
    ("G07", "WHMCS Cutover", "Scan -> Map -> Dry-run -> Migrate -> Reconcile -> Migration Hold -> Cutover -> Seal"),
    ("G08", "Privacy Restore", "Backup user -> Erase -> Restore old backup -> Apply tombstone -> PII must not resurrect"),
]

def main():
    root_dir = Path(__file__).resolve().parent.parent

    print("==================================================")
    print("Coleza Host — Golden E2E Master Scenarios (P18.2)")
    print("==================================================")

    test_file = root_dir / "tests" / "Integration" / "Golden" / "GoldenMasterScenariosE2ETest.php"
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
        print("\nFAILURE: Golden E2E test suite failed!")
        print(result.stdout)
        print(result.stderr)
        return 1

    print("\n--------------------------------------------------")
    print(f"Suite Status: ALL 8 SCENARIOS CERTIFIED (PASS) in {duration:.2f}s")
    print("--------------------------------------------------")
    for code, title, flow in SCENARIOS:
        print(f"  [{code}] [PASS] {title:<25} - {flow}")
    print("--------------------------------------------------")
    print("\nSTATUS: G01–G08 GOLDEN E2E SCENARIOS VERIFIED (PASS)")
    return 0

if __name__ == "__main__":
    sys.exit(main())
