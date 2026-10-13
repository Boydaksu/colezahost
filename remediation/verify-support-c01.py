"""Verify the Support C01 evidence manifest and disjoint full native suite coverage."""
from pathlib import Path
import hashlib
import json
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parent.parent
PREFIX = ROOT / 'remediation'

def testcase_id(case):
    name = case.get('name')
    if ' with data set ' in name:
        method, dataset = name.rsplit(' with data set ', 1)
        if dataset.startswith('#'):
            name = method + dataset
        elif dataset.startswith('"') and dataset.endswith('"'):
            name = method + '#' + dataset[1:-1]
        else:
            raise AssertionError('Unknown PHPUnit dataset identity: ' + name)
    return case.get('class') + '::' + name

def run_totals(filename):
    document = ET.parse(PREFIX / filename)
    suite = document.getroot().find('testsuite')
    assert suite is not None, filename
    for key in ('errors', 'failures', 'skipped'):
        assert int(suite.get(key, '0')) == 0, (filename, key)
    cases = list(document.iter('testcase'))
    assert len(cases) == int(suite.get('tests')), filename
    identifiers = [testcase_id(case) for case in cases]
    assert len(set(identifiers)) == len(identifiers), filename
    return int(suite.get('tests')), int(suite.get('assertions')), set(identifiers)

def verify():
    manifest = json.loads((PREFIX / 'SUPPORT-C01-file-hashes.json').read_text(encoding='utf-8-sig'))
    for filename, digest in manifest['files'].items():
        assert hashlib.sha256((ROOT / filename).read_bytes()).hexdigest() == digest, filename
    main = run_totals('SUPPORT-C01-main.xml')
    native_a = run_totals('SUPPORT-C01-native-a.xml')
    native_b = run_totals('SUPPORT-C01-native-b.xml')
    namespace = {'p': 'https://xml.phpunit.de/testSuite'}
    expected = {method.get('id') for method in ET.parse(PREFIX / 'SUPPORT-C01-native-expected.xml').findall('.//p:testMethod', namespace)}
    assert not native_a[2] & native_b[2], 'Native partitions overlap'
    assert native_a[2] | native_b[2] == expected, 'Native partitions omit or add tests'
    state = json.loads((PREFIX / 'state.json').read_text(encoding='utf-8-sig'))
    assert state['status'] == 'IN_PROGRESS' and state['production_ready'] is False
    assert state['independent_acceptance'] == 'PENDING'
    assert state['tests']['main_total'] == main[0]
    assert state['tests']['main_assertions'] == main[1]
    assert state['tests']['mariadb_total'] == native_a[0] + native_b[0]
    assert state['tests']['mariadb_assertions'] == native_a[1] + native_b[1]
    assert len(list((ROOT / 'database/migrations').glob('*.php'))) == 25
    print(f'Verified {main[0]} main + {len(expected)} native tests and {len(manifest["files"])} file hashes.')

if __name__ == '__main__':
    verify()
