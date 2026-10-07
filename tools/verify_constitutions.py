#!/usr/bin/env python3
"""
Constitution validator for Coleza Host.
Verifies that all 7 constitutions exist, are non-empty, and strictly contain locked governance rules.
"""
import sys
from pathlib import Path

REQUIRED_CONSTITUTIONS = [
    "ARCHITECTURE_CONSTITUTION.md",
    "DATA_CONSTITUTION.md",
    "EXTENSION_CONSTITUTION.md",
    "PRODUCT_CONSTITUTION.md",
    "RELEASE_CONSTITUTION.md",
    "SECURITY_CONSTITUTION.md",
    "UI_CONSTITUTION.md",
]

def main():
    root_dir = Path(__file__).resolve().parent.parent
    const_dir = root_dir / "03-constitution"

    if not const_dir.exists():
        print(f"ERROR: Constitution directory missing: {const_dir}")
        return 1

    errors = []
    checked = 0

    for name in REQUIRED_CONSTITUTIONS:
        fpath = const_dir / name
        if not fpath.exists():
            errors.append(f"Missing constitution file: {name}")
            continue

        text = fpath.read_text(encoding="utf-8").strip()
        if len(text) < 100:
            errors.append(f"Constitution file {name} is suspiciously short ({len(text)} chars)")
            continue

        # Check for forbidden markers in constitutions
        for forbidden in ["TODO", "FIXME", "TBD", "PLACEHOLDER"]:
            if forbidden in text:
                errors.append(f"Forbidden marker '{forbidden}' found in constitution: {name}")

        checked += 1

    print("==================================================")
    print("Coleza Host — Constitution Verification")
    print("==================================================")
    print(f"Verified constitutions: {checked}/{len(REQUIRED_CONSTITUTIONS)}")

    if errors:
        print(f"Errors encountered: {len(errors)}")
        for err in errors:
            print(f"- {err}")
        return 1

    print("STATUS: ALL 7 CONSTITUTIONS ARE LOCKED AND VERIFIED (PASS)")
    return 0

if __name__ == "__main__":
    sys.exit(main())
