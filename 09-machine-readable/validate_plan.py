#!/usr/bin/env python3
from pathlib import Path
import json, sys
base=Path(__file__).resolve().parent
road=json.loads((base/'roadmap.json').read_text(encoding='utf-8'))
ids=[p['id'] for p in road['phases']]
errors=[]
if len(ids)!=len(set(ids)): errors.append('Duplicate phase IDs')
known=set(ids)
for p in road['phases']:
    for d in p.get('hard_dependencies',[]):
        if d not in known: errors.append(f"{p['id']}: unknown phase dependency {d}")
    subids=[s['id'] for s in p.get('subphases',[])]
    if len(subids)!=len(set(subids)): errors.append(f"{p['id']}: duplicate subphase id")
sc=json.loads((base/'scope.json').read_text(encoding='utf-8'))
for k in ('V1-MUST','V1-FOUNDATION','V1.1','V2+'):
    if k not in sc['scope']: errors.append(f'Missing scope category: {k}')
dom=json.loads((base/'domains.json').read_text(encoding='utf-8'))
for name,dep in dom['domains'].items():
    for d in dep.get('hard',[])+dep.get('soft',[]):
        # external conceptual anchors governance/brand/etc are allowed if present or governance anchor
        if d not in dom['domains'] and d not in {'governance','brand','logging','backup','documents','contracts','fraud','notifications','finance','analytics','system_health','queue','module_core'}:
            errors.append(f'{name}: unknown domain dependency {d}')
if errors:
    print('PLAN VALIDATION FAILED')
    print('\n'.join('- '+e for e in errors))
    sys.exit(1)
print(f"PLAN VALIDATION PASS: {len(ids)} phases, {sum(len(p['subphases']) for p in road['phases'])} subphases")
