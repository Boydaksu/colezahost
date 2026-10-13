"""Verify local API/session evidence; native scope is seven affected classes, not the full suite."""
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
            raise AssertionError(dataset)
    return case.get('class') + '::' + name

def result(filename):
    tree = ET.parse(PREFIX / filename)
    suite = tree.getroot().find('testsuite')
    assert suite is not None, filename
    for key in ('failures', 'errors', 'skipped'):
        assert int(suite.get(key, '0')) == 0, (filename, key)
    cases = list(tree.iter('testcase'))
    identifiers = [testcase_id(case) for case in cases]
    assert len(cases) == int(suite.get('tests')) == len(set(identifiers)), filename
    assert not any(tree.iter('warning')) and not any(tree.iter('risky')), filename
    return int(suite.get('tests')), int(suite.get('assertions')), set(identifiers)

def verify():
    manifest = json.loads((PREFIX / 'API-SESSION-C01-file-hashes.json').read_text(encoding='utf-8-sig'))
    for filename, digest in manifest['files'].items():
        assert hashlib.sha256((ROOT / filename).read_bytes()).hexdigest() == digest, filename
    main = result('API-SESSION-C01-main.xml')
    native = result('API-SESSION-C01-native-acceptance.xml')
    ns = {'p': 'https://xml.phpunit.de/testSuite'}
    discovery = ET.parse(PREFIX / 'API-SESSION-C01-native-acceptance-expected.xml')
    expected = {method.get('id') for method in discovery.findall('.//p:testMethod', ns)}
    assert native[2] == expected, 'Native affected scope differs from discovery'
    assert len(discovery.findall('.//p:testClass', ns)) == 7
    state = json.loads((PREFIX / 'state.json').read_text(encoding='utf-8-sig'))
    assert state['status'] == 'IN_PROGRESS' and state['production_ready'] is False
    assert state['independent_acceptance'] == 'PENDING'
    assert state['tests']['main_total'] == main[0]
    assert state['tests']['main_assertions'] == main[1]
    assert state['tests']['mariadb_total'] == native[0]
    assert state['tests']['mariadb_assertions'] == native[1]
    assert state['tests']['full_mariadb_current_source'] is False
    assert len(list((ROOT / 'database/migrations').glob('*.php'))) == 25
    inventory = json.loads((PREFIX / 'D02-SCHEMA-INVENTORY.json').read_text(encoding='utf-8-sig'))
    assert inventory['file_count'] == len(inventory['files']) == 91
    print(f'Verified {main[0]} full main + {native[0]} affected native tests; {len(manifest["files"])} hashes. Full native rerun not claimed.')

if __name__ == '__main__':
    verify()
