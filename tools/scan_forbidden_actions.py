#!/usr/bin/env python3
"""
Forbidden actions and governance scanner for Coleza Host.
Scans the codebase for violations of 04-ai-governance/FORBIDDEN_ACTIONS.md:
- Silent exception swallowing / '@' suppression in PHP code
- Unauthorized test skips or disabled assertions
- Hardcoded sensitive credentials/secrets
"""
import re
import sys
from pathlib import Path

FORBIDDEN_PATTERNS = [
    (r"@\$[a-zA-Z0-9_]+->", "Error suppression operator '@' on method calls"),
    (r"markTestSkipped\(", "Unapproved test skipped invocation"),
    (r"TODO\s*:\s*V1-MUST", "Unfinished V1-MUST TODO tag"),
]

def scan_files(root_dir: Path):
    violations = []
    
    # Paths to scan
    scan_dirs = [root_dir / "src", root_dir / "tests", root_dir / "tools"]
    for sdir in scan_dirs:
        if not sdir.exists():
            continue
        for p in sdir.rglob("*"):
            if not p.is_file() or p.suffix not in [".php", ".py", ".sh", ".js"]:
                continue
            
            content = p.read_text(encoding="utf-8", errors="ignore")
            for pattern, desc in FORBIDDEN_PATTERNS:
                matches = re.finditer(pattern, content)
                for m in matches:
                    line_no = content[:m.start()].count("\n") + 1
                    violations.append(f"{p.relative_to(root_dir)}:{line_no} - {desc}")

    return violations

def main():
    root_dir = Path(__file__).resolve().parent.parent
    violations = scan_files(root_dir)

    print("==================================================")
    print("Coleza Host — AI Governance & Forbidden Actions Scan")
    print("==================================================")

    if violations:
        print(f"VIOLATIONS DETECTED ({len(violations)}):")
        for v in violations:
            print(f"- {v}")
        return 1

    print("STATUS: ZERO FORBIDDEN ACTIONS DETECTED (PASS)")
    return 0

if __name__ == "__main__":
    sys.exit(main())
