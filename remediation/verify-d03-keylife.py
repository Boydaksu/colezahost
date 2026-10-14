"""Verify key lifecycle source/evidence, exact native discovery and unchanged migrations."""
from pathlib import Path
import hashlib
import json
import xml.etree.ElementTree as ET
ROOT = Path(__file__).resolve().parent.parent
R = ROOT / 'remediation'
P = 'D03-1-KEYLIFE-'


def identity(case):
    name = case.get('name')
    if ' with data set ' in name:
        method, value = name.rsplit(' with data set ', 1)
        name = method + (value if value.startswith('#') else '#' + value[1:-1])
    return case.get('class') + '::' + name


def result(name):
    tree = ET.parse(R / (P + name + '.xml'))
    suite = tree.getroot().find('testsuite')
    assert suite is not None
    assert all(int(suite.get(a, '0')) == 0 for a in ['failures', 'errors', 'skipped'])
    cases = list(tree.iter('testcase')); ids = {identity(c) for c in cases}
    assert len(cases) == len(ids) == int(suite.get('tests'))
    assert 'OK (' in (R / (P + name + '.txt')).read_text(encoding='utf-8-sig')
    return {'tests': int(suite.get('tests')), 'assertions': int(suite.get('assertions'))}, ids


def verify():
    for name in ['source-freeze', 'hashes']:
        data = json.loads((R / (P + name + '.json')).read_text(encoding='utf-8-sig'))
        for file, digest in data['files'].items():
            assert hashlib.sha256((ROOT / file).read_bytes()).hexdigest() == digest, file
    main, _ = result('main'); native, ids = result('native')
    tree = ET.parse(R / (P + 'native-expected.xml'))
    ns = {'p': 'https://xml.phpunit.de/testSuite'}
    assert len(tree.findall('.//p:testClass', ns)) == 8
    assert ids == {m.get('id') for m in tree.findall('.//p:testMethod', ns)}
    before = ET.parse(R / (P + 'before.xml')).getroot().find('testsuite')
    assert before is not None and int(before.get('tests')) == int(before.get('failures')) == 2
    assert int(before.get('errors')) == 0
    state = json.loads((R / 'state.json').read_text(encoding='utf-8-sig'))
    assert state['step'] == 'D03.1' and state['status'] == 'IN_PROGRESS'
    assert state['production_ready'] is False and state['independent_acceptance'] == 'PENDING'
    assert state['tests']['full_mariadb_current_source'] is False
    assert (state['tests']['main_total'], state['tests']['main_assertions']) == (main['tests'], main['assertions'])
    assert (state['tests']['mariadb_total'], state['tests']['mariadb_assertions']) == (native['tests'], native['assertions'])
    assert state['d03_key_lifecycle_status'] == 'IMPLEMENTED_LOCAL_VERIFIED'
    assert state['d03_1_remaining_buckets'] == ['GENERAL_SECRET_CONFIG_LOG_EXPORT_AUDIT']
    baseline = json.loads((R / (P + 'baseline.json')).read_text(encoding='utf-8-sig'))
    assert len(baseline['migrations_unchanged']) == 26
    for file, digest in baseline['migrations_unchanged'].items():
        assert hashlib.sha256((ROOT / file).read_bytes()).hexdigest() == digest, file
    scan = (R / (P + 'security-scan.txt')).read_text(encoding='utf-8-sig')
    assert 'STATUS: ALL SECURITY, ARCHITECTURE & DEPENDENCY SCANS PASSED (PASS)' in scan
    print(f'Verified main {main["tests"]}/{main["assertions"]} + affected native {native["tests"]}/{native["assertions"]}; exact eight classes, unchanged 26 migrations; key lifecycle locally implemented, D03.1 general audit open.')


if __name__ == '__main__':
    verify()
