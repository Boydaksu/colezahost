#!/usr/bin/env python3
"""
Coleza Host -- Minimum Shared-Host Compatibility Matrix Runner (P18.6).
Executes and certifies full compatibility on constrained shared-host profiles:
  - SH-01 Runtime Environment & Extension Compatibility
  - SH-02 Zero-Daemon Queue Execution via DatabaseQueue
  - SH-03 Concurrency Synchronization via DatabaseLock
  - SH-04 Caching via FileCache & DatabaseCache
  - SH-05 Local Storage File Isolation & Traversal Prevention
  - SH-06 Cron Heartbeat and System Doctor Health Verification
"""
import subprocess
import sys
import time
from pathlib import Path

MATRIX = [
    ("SH-01", "Runtime & Extensions", "Standard PHP 8.4 runtime satisfies requirements without custom C-extensions"),
    ("SH-02", "Zero-Daemon Queue", "Background jobs execute reliably via DatabaseQueue without Redis or external daemons"),
    ("SH-03", "Database Locking", "Distributed concurrency control operates via DatabaseLock without Redis/Redlock"),
    ("SH-04", "File & DB Caching", "High performance caching operates via FileCache and DatabaseCache without Memcached"),
    ("SH-05", "Storage Sandboxing", "LocalStorage sandboxes files safely within storage root, blocking directory traversal"),
    ("SH-06", "System Doctor Diagnostic", "SystemDoctor reports HEALTHY status across all diagnostics on shared-host profile"),
]

def main():
    root_dir = Path(__file__).resolve().parent.parent

    print("==================================================")
    print("Coleza Host -- Shared-Host Compatibility (P18.6)")
    print("==================================================")

    test_file = root_dir / "tests" / "Integration" / "SharedHost" / "SharedHostCompatibilityMatrixTest.php"
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
        print("\nFAILURE: Shared-host compatibility matrix test suite failed!")
        print(result.stdout)
        print(result.stderr)
        return 1

    print("\n--------------------------------------------------")
    print(f"Suite Status: ALL 6 SHARED-HOST PROFILES CERTIFIED (PASS) in {duration:.2f}s")
    print("--------------------------------------------------")
    for code, title, flow in MATRIX:
        print(f"  [{code}] [PASS] {title:<25} - {flow}")
    print("--------------------------------------------------")
    print("\nSTATUS: P18.6 MINIMUM SHARED-HOST COMPATIBILITY MATRIX VERIFIED (PASS)")
    return 0

if __name__ == "__main__":
    sys.exit(main())
