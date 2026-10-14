"""Check complete regression evidence, disjoint native discovery and source/file hashes."""
from pathlib import Path
import hashlib
import json
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parent.parent
R = ROOT / 'remediation'


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
    tree = ET.parse(R / filename)
    suite = tree.getroot().find('testsuite')
    assert suite is not None, filename
    for key in ('failures', 'errors', 'skipped'):
        assert int(suite.get(key, '0')) == 0, (filename, key)
    cases = list(tree.iter('testcase'))
    ids = [testcase_id(case) for case in cases]
    assert len(cases) == int(suite.get('tests')) == len(set(ids)), filename
    assert not list(tree.iter('warning')) and not list(tree.iter('risky')), filename
    output = (R / filename.replace('.xml', '.txt')).read_text(encoding='utf-8-sig')
    assert 'OK (' in output and 'FAILURES!' not in output and 'Warnings:' not in output, filename
    return int(suite.get('tests')), int(suite.get('assertions')), set(ids)


def verify():
    for file in ['D02-CLOSEOUT-source-freeze.json', 'D02-CLOSEOUT-file-hashes.json']:
        manifest = json.loads((R / file).read_text(encoding='utf-8-sig'))
        for filename, digest in manifest['files'].items():
            assert hashlib.sha256((ROOT / filename).read_bytes()).hexdigest() == digest, filename
    main = result('D02-CLOSEOUT-main.xml')
    partitions = [result('D02-CLOSEOUT-native-' + letter + '.xml') for letter in 'abc']
    ns = {'p': 'https://xml.phpunit.de/testSuite'}
    discovery = ET.parse(R / 'D02-CLOSEOUT-native-expected.xml')
    expected = {method.get('id') for method in discovery.findall('.//p:testMethod', ns)}
    executed = set()
    for partition in partitions:
        assert not executed & partition[2], 'Native partitions overlap'
        executed |= partition[2]
    assert executed == expected, 'Native execution differs from full discovery'
    assert len(discovery.findall('.//p:testClass', ns)) == 40
    assert len(list((ROOT / 'database/migrations').glob('*.php'))) == 26
    state = json.loads((R / 'state.json').read_text(encoding='utf-8-sig'))
    assert state['phase'] == 'D03' and state['status'] == 'IN_PROGRESS'
    assert state['phase_results']['D02']['status'] == 'IMPLEMENTATION_COMPLETE_LOCAL_VERIFIED'
    assert state['independent_acceptance'] == 'PENDING' and state['production_ready'] is False
    assert state['tests']['main_total'] == main[0] and state['tests']['main_assertions'] == main[1]
    assert state['tests']['mariadb_total'] == sum(p[0] for p in partitions)
    assert state['tests']['mariadb_assertions'] == sum(p[1] for p in partitions)
    print(f'Verified {main[0]} main + {len(expected)} native tests; four D02 local gates closed; independent product acceptance pending.')


if __name__ == '__main__':
    verify()
