"""Verify the automation secret-storage package, not whole-product acceptance."""
from pathlib import Path
import hashlib
import json
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parent.parent
R = ROOT / 'remediation'
P = 'D03-1-AUTOSTORE-'


def identity(case):
    name = case.get('name')
    if ' with data set ' in name:
        method, value = name.rsplit(' with data set ', 1)
        name = method + (value if value.startswith('#') else '#' + value[1:-1])
    return case.get('class') + '::' + name


def verify():
    for name in ['source-freeze', 'hashes']:
        data = json.loads((R / (P + name + '.json')).read_text(encoding='utf-8-sig'))
        for file, digest in data['files'].items():
            assert hashlib.sha256((ROOT / file).read_bytes()).hexdigest() == digest, file
    stats = {}
    for name in ['main', 'native']:
        tree = ET.parse(R / (P + name + '.xml'))
        suite = tree.getroot().find('testsuite')
        assert suite is not None and all(int(suite.get(a, '0')) == 0 for a in ['errors', 'failures', 'skipped'])
        cases = list(tree.iter('testcase'))
        ids = {identity(c) for c in cases}
        assert len(cases) == len(ids) == int(suite.get('tests'))
        stats[name] = {a: int(suite.get(a)) for a in ['tests', 'assertions']}
        if name == 'native':
            expected = ET.parse(R / (P + 'native-expected.xml'))
            ns = {'p': 'https://xml.phpunit.de/testSuite'}
            assert len(expected.findall('.//p:testClass', ns)) == 9
            assert ids == {m.get('id') for m in expected.findall('.//p:testMethod', ns)}
    before = ET.parse(R / (P + 'before.xml')).getroot().find('testsuite')
    assert int(before.get('tests')) == int(before.get('failures')) == 1 and int(before.get('errors')) == 0
    state = json.loads((R / 'state.json').read_text(encoding='utf-8-sig'))
    assert state['status'] == 'IN_PROGRESS' and state['step'] == 'D03.1'
    assert not state['production_ready'] and state['independent_acceptance'] == 'PENDING'
    assert not state['tests']['full_mariadb_current_source']
    for name, label in [('main', 'main'), ('native', 'mariadb')]:
        assert state['tests'][label + '_total'] == stats[name]['tests']
        assert state['tests'][label + '_assertions'] == stats[name]['assertions']
    assert state['d03_general_secret_audit']['automation_storage'] == 'IMPLEMENTED_LOCAL_VERIFIED'
    assert len(state['d03_general_secret_audit']['remaining']) == 3
    baseline = json.loads((R / (P + 'baseline.json')).read_text(encoding='utf-8-sig'))
    assert len(baseline['migrations_unchanged']) == 26
    for file, digest in baseline['migrations_unchanged'].items():
        assert hashlib.sha256((ROOT / file).read_bytes()).hexdigest() == digest, file
    assert 'STATUS: ALL SECURITY, ARCHITECTURE & DEPENDENCY SCANS PASSED (PASS)' in (R / (P + 'security-scan.txt')).read_text(encoding='utf-8-sig')
    print('Verified automation storage:', stats, '; nine exact native classes; D03.1 remains open.')


if __name__ == '__main__':
    verify()
