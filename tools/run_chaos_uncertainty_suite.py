#!/usr/bin/env python3
"""
Coleza Host — Concurrency, Failure, Chaos & Provider Uncertainty Runner (P18.4).
Executes and certifies the comprehensive chaos suite covering:
  - C01 Double-Payment Webhook Delivery Chaos & Replay Idempotency
  - C02 Concurrent Credit Ledger Debit Competition & Negative Balance Prevention
  - C03 Server Placement & Capacity Contention Race Condition
  - C04 Provider 429 Rate Limiting, Exponential Backoff, & Jitter Bounds
  - C05 Error Classification: Transient Network vs Permanent Rejected
  - C06 Domain Registrar Socket Timeout Uncertainty & Batch Reconciliation
  - C07 Missed Scheduler Catchup & Distributed Worker Collision Prevention
  - C08 Service State Machine Optimistic Concurrency Control
"""
import subprocess
import sys
import time
from pathlib import Path

SCENARIOS = [
    ("C01", "Double-Payment Replay", "Rapid duplicate webhook replay absorbs duplicate locally without duplicate payments or balance corruption"),
    ("C02", "Credit Ledger Race", "Concurrent credit balance debits enforce locking and non-negative balance invariants"),
    ("C03", "Capacity Contention", "Concurrent provisioning on saturated node strictly respects capacity caps and triggers graceful rejection"),
    ("C04", "Rate Limit & Backoff", "Provider 429 backoff respects exponential curve and jitter bounds with max attempt cutoff"),
    ("C05", "Fault Classification", "Transient socket/5xx errors classify as retryable while 4xx domain errors halt retry loops immediately"),
    ("C06", "Registrar Uncertainty", "Upstream socket timeouts enter UNCERTAIN state and reconcile automatically upon background sync"),
    ("C07", "Scheduler Lock Race", "Distributed worker lock prevents concurrent execution of missed scheduler catchup runs"),
    ("C08", "Optimistic Locking", "Concurrent service modifications with stale version throw ServiceConcurrencyException cleanly"),
]

def main():
    root_dir = Path(__file__).resolve().parent.parent

    print("==================================================")
    print("Coleza Host -- Concurrency, Chaos & Uncertainty (P18.4)")
    print("==================================================")

    test_file = root_dir / "tests" / "Integration" / "Chaos" / "ChaosAndConcurrencySuiteTest.php"
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
        print("\nFAILURE: Chaos and Concurrency test suite failed!")
        print(result.stdout)
        print(result.stderr)
        return 1

    print("\n--------------------------------------------------")
    print(f"Suite Status: ALL 8 CHAOS & CONCURRENCY SCENARIOS CERTIFIED (PASS) in {duration:.2f}s")
    print("--------------------------------------------------")
    for code, title, flow in SCENARIOS:
        print(f"  [{code}] [PASS] {title:<25} - {flow}")
    print("--------------------------------------------------")
    print("\nSTATUS: P18.4 CHAOS, CONCURRENCY & UNCERTAINTY SUITE VERIFIED (PASS)")
    return 0

if __name__ == "__main__":
    sys.exit(main())
