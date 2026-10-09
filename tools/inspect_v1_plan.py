import json
from pathlib import Path

root = Path('.')
with open(root / '09-machine-readable' / 'roadmap.json', encoding='utf-8') as f:
    roadmap = json.load(f)

with open(root / '09-machine-readable' / 'scope.json', encoding='utf-8') as f:
    scope = json.load(f)

v1_phases = [p for p in roadmap['phases'] if p.get('version') == 'V1']
print(f"Total V1 phases: {len(v1_phases)}")
total_subs = 0
for p in v1_phases:
    subs = p.get('subphases', [])
    total_subs += len(subs)
    p_title = p['title'].encode('ascii', 'replace').decode('ascii')
    print(f"\nPhase {p['id']}: {p_title} ({len(subs)} subphases)")
    for s in subs:
        s_title = s['title'].encode('ascii', 'replace').decode('ascii')
        print(f"  - {s['id']}: {s_title}")

print(f"\nTOTAL V1 SUBPHASES: {total_subs}")

print("\n--- SCOPE.JSON V1-MUST & V1-FOUNDATION ---")
v1_must = scope.get('scope', {}).get('V1-MUST', [])
print(f"V1-MUST domains ({len(v1_must)}):")
for item in v1_must:
    print(f"  * {item}")

v1_foundation = scope.get('scope', {}).get('V1-FOUNDATION', [])
print(f"\nV1-FOUNDATION domains ({len(v1_foundation)}):")
for item in v1_foundation:
    print(f"  * {item}")

post_v1 = scope.get('scope', {}).get('V1.1', []) + scope.get('scope', {}).get('V2+', [])
print(f"\nPOST-V1 (Forbidden in V1) domains ({len(post_v1)}):")
for item in post_v1:
    print(f"  * {item}")
