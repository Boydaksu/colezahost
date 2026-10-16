"""Verify the local evidence for the SFTP runtime/CI correction at its recorded source revision."""
from pathlib import Path
import hashlib
import json
import xml.etree.ElementTree as ET

ROOT=Path(__file__).resolve().parent.parent
R=ROOT/'remediation'
baseline=json.loads((R/'CI-SFTP-baseline.json').read_text(encoding='utf-8'))
assert len(baseline['source_sha256'])==4 and len(baseline['migration_sha256'])==26
for name,digest in (baseline['source_sha256']|baseline['migration_sha256']).items():
    assert hashlib.sha256((ROOT/name).read_bytes()).hexdigest()==digest,name
for kind,count in [('main',2065),('protocols',52)]:
    assert (R/f'CI-SFTP-{kind}.exit').read_text(encoding='utf-8-sig').strip()=='0'
    tree=ET.parse(R/f'CI-SFTP-{kind}.xml');suite=tree.getroot().find('testsuite')
    assert int(suite.get('tests'))==count==len(list(tree.iter('testcase')))
    assert all(int(suite.get(k,'0'))==0 for k in ['errors','failures','skipped'])
    assert not any(list(tree.iter(tag)) for tag in ['error','failure','warning','skipped'])
    assert 'OK (' in (R/f'CI-SFTP-{kind}.txt').read_text(encoding='utf-8-sig')
probe=json.loads((R/'CI-SFTP-windows-runtime.txt').read_text(encoding='utf-8-sig'))
assert probe['sha256_host_pin'] and probe['admitted'] and probe['network_requests']==0
before=json.loads((R/'CI-SFTP-before-linux.txt').read_text(encoding='utf-8-sig'))
assert before['sha256_host_pin_setopt_code']==48 and before['network_requests']==0
assert all(c['exit_code']==0 for c in json.loads((R/'CI-SFTP-checks.json').read_text(encoding='utf-8')))
state=json.loads((R/'state.json').read_text(encoding='utf-8'))
assert state['d05_4_gates']['real_migrations']=='PENDING'
assert state['step_results']['D05.4']['completed_gates']==2 and not state['production_ready']
assert not state['ci_sftp_fix']['native_database_suite_rerun']
hashes=R/'CI-SFTP-hashes.json'
if hashes.exists():
    for name,digest in json.loads(hashes.read_text(encoding='utf-8'))['files'].items():
        assert hashlib.sha256((ROOT/name).read_bytes()).hexdigest()==digest,name
print('SFTP runtime/CI local evidence verified: 2065 main / 52 protocol-config tests; 26 unchanged migrations')
