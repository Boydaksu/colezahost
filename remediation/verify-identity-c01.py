"""Verify full main/native evidence, exact disjoint discovery coverage and file hashes."""
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
    ids = [testcase_id(case) for case in cases]
    assert len(cases) == int(suite.get('tests')) == len(set(ids)), filename
    assert not list(tree.iter('warning')) and not list(tree.iter('risky')), filename
    output = (PREFIX / filename.replace('.xml', '.txt')).read_text(encoding='utf-8-sig')
    assert 'OK (' in output and 'FAILURES!' not in output and 'Warnings:' not in output, filename
    return int(suite.get('tests')), int(suite.get('assertions')), set(ids)

def verify():
    manifest = json.loads((PREFIX / 'IDENTITY-C01-file-hashes.json').read_text(encoding='utf-8-sig'))
    for filename, digest in manifest['files'].items():
        assert hashlib.sha256((ROOT / filename).read_bytes()).hexdigest() == digest, filename
    main = result('IDENTITY-C01-main.xml')
    a = result('IDENTITY-C01-native-a.xml')
    b = result('IDENTITY-C01-native-b.xml')
    ns = {'p': 'https://xml.phpunit.de/testSuite'}
    discovery = ET.parse(PREFIX / 'IDENTITY-C01-native-expected.xml')
    expected = {method.get('id') for method in discovery.findall('.//p:testMethod', ns)}
    assert not a[2] & b[2], 'Native partitions overlap'
    assert a[2] | b[2] == expected, 'Full native discovery differs from executions'
    assert len(discovery.findall('.//p:testClass', ns)) == 38
    state = json.loads((PREFIX / 'state.json').read_text(encoding='utf-8-sig'))
    assert state['status'] == 'IN_PROGRESS' and state['production_ready'] is False
    assert state['independent_acceptance'] == 'PENDING'
    assert state['tests']['main_total'] == main[0] and state['tests']['main_assertions'] == main[1]
    assert state['tests']['mariadb_total'] == a[0] + b[0] and state['tests']['mariadb_assertions'] == a[1] + b[1]
    assert state['tests']['full_mariadb_current_source'] is True
    assert len(list((ROOT / 'database/migrations').glob('*.php'))) == 26
    inventory = json.loads((PREFIX / 'D02-SCHEMA-INVENTORY.json').read_text(encoding='utf-8-sig'))
    assert inventory['file_count'] == len(inventory['files']) == 93
    print(f'Verified {main[0]} full main + {len(expected)} full native tests; {len(manifest["files"])} hashes.')

if __name__ == '__main__':
    verify()
