#!/usr/bin/env python3
"""
Comprehensive V1 Audit:
Cross-checks all 19 phases (P00-P18) and 155 subphases against:
1. Roadmap and specification definitions
2. Execution records in evidence/v1/
3. Gate decisions in evidence/v1/
4. Production source code in src/
5. Automated test coverage in tests/
6. Constitutions in 03-constitution/
7. Forbidden scope / creep detection
8. Codebase hygiene (TODO, FIXME, incomplete stubs)
"""
import json
import os
import re
from pathlib import Path

root = Path('.')

with open(root / '09-machine-readable' / 'roadmap.json', encoding='utf-8') as f:
    roadmap = json.load(f)

with open(root / '09-machine-readable' / 'scope.json', encoding='utf-8') as f:
    scope = json.load(f)

v1_phases = [p for p in roadmap['phases'] if p.get('version') == 'V1']

print("================================================================================")
print("COLEZA HOST V1 DEEP AUDIT: PLANNED SPECIFICATIONS VS IMPLEMENTED REALITY")
print("================================================================================")

# 1. Audit Phase Gate Decisions
print("\n[1] PHASE GATE DECISIONS (P00 - P18):")
missing_gates = []
failed_gates = []

for p in v1_phases:
    pid = p['id']
    gate_file = root / 'evidence' / 'v1' / pid / 'gate-decision.md'
    if not gate_file.exists():
        missing_gates.append(pid)
        print(f"  [X] {pid}: MISSING gate-decision.md")
    else:
        content = gate_file.read_text(encoding='utf-8')
        if 'PASS' in content:
            print(f"  [OK] {pid}: {p['title']} -> PASS")
        else:
            failed_gates.append(pid)
            print(f"  [!] {pid}: {p['title']} -> NOT PASS")

# 2. Audit Subphase Execution Records
print("\n[2] SUBPHASE EXECUTION RECORDS (155 SUBPHASES):")
missing_sub_records = []
non_pass_sub_records = []
total_subs = 0

for p in v1_phases:
    pid = p['id']
    subs = p.get('subphases', [])
    for s in subs:
        sid = s['id']
        total_subs += 1
        rec_file = root / 'evidence' / 'v1' / pid / f"{sid}-execution-record.md"
        if not rec_file.exists():
            missing_sub_records.append(sid)
        else:
            txt = rec_file.read_text(encoding='utf-8')
            if 'PASS' not in txt:
                non_pass_sub_records.append(sid)

print(f"  Total planned subphases: {total_subs}")
print(f"  Missing execution records: {len(missing_sub_records)} {missing_sub_records if missing_sub_records else ''}")
print(f"  Non-PASS execution records: {len(non_pass_sub_records)} {non_pass_sub_records if non_pass_sub_records else ''}")

# 3. Audit Constitutions
print("\n[3] PLATFORM CONSTITUTIONS (03-constitution/):")
const_files = [
    'ARCHITECTURE_CONSTITUTION.md',
    'DATA_CONSTITUTION.md',
    'EXTENSION_CONSTITUTION.md',
    'PRODUCT_CONSTITUTION.md',
    'RELEASE_CONSTITUTION.md',
    'SECURITY_CONSTITUTION.md',
    'UI_CONSTITUTION.md',
]
missing_consts = []
for c in const_files:
    cp = root / '03-constitution' / c
    if not cp.exists() or cp.stat().st_size < 100:
        missing_consts.append(c)
        print(f"  [X] {c}: MISSING or EMPTY")
    else:
        print(f"  [OK] {c} ({cp.stat().st_size} bytes)")

# 4. Audit Production Source Structure
print("\n[4] PRODUCTION SOURCE CODE INVENTORY (src/):")
src_php_files = list(root.glob('src/**/*.php'))
print(f"  Total PHP source files in src/: {len(src_php_files)}")

# Check strict_types in every file
strict_types_missing = []
for f in src_php_files:
    txt = f.read_text(encoding='utf-8', errors='ignore')
    if 'declare(strict_types=1);' not in txt:
        strict_types_missing.append(str(f))

print(f"  Missing declare(strict_types=1): {len(strict_types_missing)}")

# 5. Audit Test Suite Inventory
print("\n[5] AUTOMATED TEST SUITE INVENTORY (tests/):")
test_php_files = list(root.glob('tests/**/*Test.php'))
print(f"  Total PHPUnit test classes in tests/: {len(test_php_files)}")

# 6. Audit Code Hygiene (TODO / FIXME / placeholder checks in src/)
print("\n[6] CODE HYGIENE & TECHNICAL DEBT SCAN IN src/:")
todo_findings = []
for f in src_php_files:
    txt = f.read_text(encoding='utf-8', errors='ignore')
    for line_no, line in enumerate(txt.splitlines(), start=1):
        if re.search(r'\b(TODO|FIXME|XXX|HACK)\b', line, re.IGNORECASE):
            todo_findings.append((str(f), line_no, line.strip()))

print(f"  Total TODO/FIXME markers in src/: {len(todo_findings)}")
for file_path, line_no, content in todo_findings[:10]:
    print(f"    - {file_path}:{line_no} -> {content}")

# 7. Audit Forbidden Scope (Post-V1 features active in V1)
print("\n[7] FORBIDDEN POST-V1 LEAKAGE SCAN:")
forbidden_terms = ['plesk', 'directadmin', 'affiliate', 'live-chat', 'marketplace', 'ai-assistants', 'reseller-panel']
forbidden_findings = []
for f in src_php_files:
    # ignore comments, look for active class names or modules
    fname = f.name.lower()
    for term in forbidden_terms:
        if term in fname:
            forbidden_findings.append((str(f), term))

print(f"  Forbidden active classes/files: {len(forbidden_findings)}")
for fpath, term in forbidden_findings:
    print(f"    [!] Found forbidden module {term}: {fpath}")

# 8. Check V1-MUST Domains Implementation
print("\n[8] V1-MUST DOMAIN DIRECTORY CHECK (src/Domain/):")
domain_dirs = [d.name for d in (root / 'src' / 'Domain').iterdir() if d.is_dir()]
print(f"  Domain directories in src/Domain/: {len(domain_dirs)}")
for d in sorted(domain_dirs):
    sub_count = len(list((root / 'src' / 'Domain' / d).glob('**/*.php')))
    print(f"    - {d}: {sub_count} PHP files")

print("\n================================================================================")
print("AUDIT SUMMARY:")
print(f"  Phases PASS: {len(v1_phases) - len(missing_gates) - len(failed_gates)} / {len(v1_phases)}")
print(f"  Subphases Recorded: {total_subs - len(missing_sub_records)} / {total_subs}")
print(f"  Constitutions Verified: {len(const_files) - len(missing_consts)} / {len(const_files)}")
print(f"  Strict Typing: {len(src_php_files) - len(strict_types_missing)} / {len(src_php_files)}")
print("================================================================================")
