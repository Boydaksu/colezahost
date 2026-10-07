#!/usr/bin/env python3
"""
Comprehensive suite runner for Coleza Host.
Executes all verification tools:
- Plan validation
- Manifest integrity
- Constitutions completeness
- Scope & dependencies graph
- Gate policy compliance
- Forbidden actions scan
"""
import subprocess
import sys
from pathlib import Path

def run_cmd(name, cmd):
    print(f"\n--- Running: {name} ---")
    res = subprocess.run(cmd, shell=True, text=True, capture_output=True)
    if res.stdout:
        print(res.stdout.strip())
    if res.stderr:
        print(res.stderr.strip())
    if res.returncode != 0:
        print(f"FAILED: {name} exited with code {res.returncode}")
        return False
    return True

def main():
    root = Path(__file__).resolve().parent.parent
    checks = [
        ("PHPUnit Test Suite", "vendor\\bin\\phpunit"),
        ("Plan Validation", f"python {root / '09-machine-readable' / 'validate_plan.py'}"),
        ("Manifest Integrity", f"python {root / 'tools' / 'verify_manifest.py'}"),
        ("Constitutions Verification", f"python {root / 'tools' / 'verify_constitutions.py'}"),
        ("Scope & Dependency Graph", f"python {root / 'tools' / 'verify_scope_dependencies.py'}"),
        ("Gate Policy Check", f"python {root / 'tools' / 'check_gate.py'}"),
        ("Forbidden Actions Scan", f"python {root / 'tools' / 'scan_forbidden_actions.py'}"),
    ]

    failed = 0
    for name, cmd in checks:
        if not run_cmd(name, cmd):
            failed += 1

    print("\n==================================================")
    if failed == 0:
        print(f"ALL SUITES PASSED CLEANLY ({len(checks)}/{len(checks)}).")
        return 0
    else:
        print(f"FAILURES DETECTED: {failed}/{len(checks)} failed.")
        return 1

if __name__ == "__main__":
    sys.exit(main())
