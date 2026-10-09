#!/usr/bin/env python3
"""
Coleza Host -- Release Package, Checksum & Signature Suite Runner (P18.9).
Executes and certifies:
  - PKG-01 Deterministic SHA256 per-file checksum manifest generation
  - PKG-02 Ed25519 cryptographic detached signature verification
  - PKG-03 Package tamper detection & rejection (payload & manifest tampering)
  - PKG-04 Distribution ZIP archive bundling & integrity
  - PKG-05 Release notes completeness & version invariant alignment
"""
import subprocess
import sys
import time
from pathlib import Path

CHECKS = [
    ("PKG-01", "Checksum Manifest", "Deterministic SHA256 calculation across all distribution payload files"),
    ("PKG-02", "Cryptographic Signature", "Ed25519 detached manifest signing and public-key verification"),
    ("PKG-03", "Tamper Rejection", "Immediate detection and rejection of altered files or tampered manifests"),
    ("PKG-04", "Distribution Archive", "Canonical ZIP bundling with payload and metadata artifacts at root"),
    ("PKG-05", "Release Notes Completeness", "Comprehensive V1.0.0 Stable release notes with security and invariant details"),
]

def main():
    root_dir = Path(__file__).resolve().parent.parent

    print("==================================================")
    print("Coleza Host -- Release Packaging Suite (P18.9)")
    print("==================================================")

    test_file = root_dir / "tests" / "Integration" / "ReleasePackage" / "ReleasePackagingAndSignatureSuiteTest.php"
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
        print("\nFAILURE: Release packaging suite failed!")
        print(result.stdout)
        print(result.stderr)
        return 1

    print("\n--------------------------------------------------")
    print(f"Suite Status: ALL 5 PACKAGE CHECKS CERTIFIED (PASS) in {duration:.2f}s")
    print("--------------------------------------------------")
    for code, title, flow in CHECKS:
        print(f"  [{code}] [PASS] {title:<28} - {flow}")
    print("--------------------------------------------------")
    print("\nSTATUS: P18.9 RELEASE PACKAGING & SIGNATURE VERIFIED (PASS)")
    return 0

if __name__ == "__main__":
    sys.exit(main())
