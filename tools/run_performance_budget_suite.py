#!/usr/bin/env python3
"""
Coleza Host -- Performance, N+1 & Reference-Budget Runner (P18.5).
Executes and certifies the comprehensive performance and reference budget suite:
  - B01 Simple API Read Latency Budget (<= 200 ms)
  - B02 Client Dashboard Aggregation Latency Budget (<= 400 ms)
  - B03 Admin Dashboard Aggregation Latency Budget (<= 500 ms)
  - B04 Primary Page Query Budget (<= 30 queries)
  - B05 N+1 Query Explosion Prevention & Batch Hydration (O(1) constant queries)
  - B06 Large Dataset Chunking & Peak Memory Bounds (< 5 MB delta)
  - B07 Queue Worker Runtime & Memory Budgets Enforcement
"""
import subprocess
import sys
import time
from pathlib import Path

BUDGETS = [
    ("B01", "Simple API Read", "Catalog and resource read requests execute within 200 ms reference target"),
    ("B02", "Client Dashboard", "Aggregated client portal view executes within 400 ms reference target"),
    ("B03", "Admin Dashboard", "Executive and operations dashboard metrics aggregate within 500 ms target"),
    ("B04", "Primary Page Queries", "Typical primary user page executes <= 30 database queries total"),
    ("B05", "N+1 Query Prevention", "List operations batch-hydrate relationships in O(1) constant queries (0 N+1 growth)"),
    ("B06", "Dataset Chunking", "Large exports process sequentially in chunks keeping memory growth < 5 MB"),
    ("B07", "Worker Resource Budgets", "Queue worker supervisors strictly respect runtime and memory ceilings"),
]

def main():
    root_dir = Path(__file__).resolve().parent.parent

    print("==================================================")
    print("Coleza Host -- Performance & Reference Budget (P18.5)")
    print("==================================================")

    test_file = root_dir / "tests" / "Integration" / "Performance" / "PerformanceAndReferenceBudgetRegressionTest.php"
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
        print("\nFAILURE: Performance and budget regression test suite failed!")
        print(result.stdout)
        print(result.stderr)
        return 1

    print("\n--------------------------------------------------")
    print(f"Suite Status: ALL 7 PERFORMANCE BUDGETS CERTIFIED (PASS) in {duration:.2f}s")
    print("--------------------------------------------------")
    for code, title, flow in BUDGETS:
        print(f"  [{code}] [PASS] {title:<25} - {flow}")
    print("--------------------------------------------------")
    print("\nSTATUS: P18.5 PERFORMANCE & REFERENCE BUDGET SUITE VERIFIED (PASS)")
    return 0

if __name__ == "__main__":
    sys.exit(main())
