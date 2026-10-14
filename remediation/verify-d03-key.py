"""Verify runtime key-guard evidence; does not certify complete D03.1 or full native suite."""
from pathlib import Path
import hashlib
import json
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parent.parent
R = ROOT / 'remediation'
manifest = json.loads((R / 'D03-1-KEY-hashes.json').read_text(encoding='utf-8-sig'))
for file, digest in manifest['files'].items():
    assert hashlib.sha256((ROOT / file).read_bytes()).hexdigest() == digest, file
for name, expected in [('main', manifest['main']), ('native', manifest['native_affected_only'])]:
    tree = ET.parse(R / ('D03-1-KEY-' + name + '.xml'))
    suite = tree.getroot().find('testsuite')
    assert suite is not None
    assert int(suite.get('tests')) == len(list(tree.iter('testcase'))) == expected['tests']
    assert int(suite.get('assertions')) == expected['assertions']
    for attribute in ['errors', 'failures', 'skipped']:
        assert int(suite.get(attribute, '0')) == 0
    assert 'OK (' in (R / ('D03-1-KEY-' + name + '.txt')).read_text(encoding='utf-8-sig')
    if name == 'native':
        assert {c.get('class').split('\\')[-1] for c in tree.iter('testcase')} == {'ProviderVaultSchemaTest', 'ProviderBrandSetupTest'}
state = json.loads((R / 'state.json').read_text(encoding='utf-8-sig'))
assert state['phase'] == 'D03' and state['step'] == 'D03.1' and state['status'] == 'IN_PROGRESS'
assert state['production_ready'] is False and state['independent_acceptance'] == 'PENDING'
assert state['tests']['full_mariadb_current_source'] is False
assert state['findings']['F09'].startswith('OPEN_')
print('Verified full main1543 + affected native22 tests; D03.1 secret migration/rotation remains open.')
