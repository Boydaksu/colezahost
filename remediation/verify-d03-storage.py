"""Verify encrypted storage evidence; affected native suite only, D03.1 remains open."""
from pathlib import Path
import json
import hashlib
import xml.etree.ElementTree as ET
R = Path(__file__).resolve().parent
ROOT = R.parent


def case_id(case):
    name = case.get('name')
    if ' with data set ' in name:
        method, value = name.rsplit(' with data set ', 1)
        name = method + (value if value.startswith('#') else '#' + value[1:-1])
    return case.get('class') + '::' + name


def result(name):
    tree = ET.parse(R / ('D03-1-STORAGE-' + name + '.xml'))
    suite = tree.getroot().find('testsuite')
    assert suite is not None
    for attr in ('errors', 'failures', 'skipped'):
        assert int(suite.get(attr, '0')) == 0
    cases = list(tree.iter('testcase')); ids = {case_id(case) for case in cases}
    assert len(cases) == len(ids) == int(suite.get('tests'))
    output = (R / ('D03-1-STORAGE-' + name + '.txt')).read_text(encoding='utf-8-sig')
    assert 'OK (' in output and 'Warnings:' not in output
    return int(suite.get('tests')), int(suite.get('assertions')), ids


def verify():
    for name in ['D03-1-STORAGE-source-freeze.json', 'D03-1-STORAGE-hashes.json']:
        manifest = json.loads((R / name).read_text(encoding='utf-8-sig'))
        for file, digest in manifest['files'].items():
            assert hashlib.sha256((ROOT / file).read_bytes()).hexdigest() == digest, file
    main = result('main'); native = result('native')
    ns = {'p': 'https://xml.phpunit.de/testSuite'}
    discovery = ET.parse(R / 'D03-1-STORAGE-native-expected.xml')
    assert len(discovery.findall('.//p:testClass', ns)) == 6
    assert native[2] == {method.get('id') for method in discovery.findall('.//p:testMethod', ns)}
    state = json.loads((R / 'state.json').read_text(encoding='utf-8-sig'))
    assert state['phase'] == 'D03' and state['step'] == 'D03.1' and state['status'] == 'IN_PROGRESS'
    assert state['production_ready'] is False and state['independent_acceptance'] == 'PENDING'
    assert state['tests']['full_mariadb_current_source'] is False
    assert state['tests']['main_total'] == main[0] and state['tests']['mariadb_total'] == native[0]
    baseline = json.loads((R / 'D03-1-STORAGE-baseline.json').read_text(encoding='utf-8-sig'))
    for file, digest in baseline['migrations_unchanged'].items():
        assert hashlib.sha256((ROOT / file).read_bytes()).hexdigest() == digest, file
    print(f'Verified full main{main[0]} + affected native{native[0]}; six-class discovery exact; D03.1 remains open.')


if __name__ == '__main__':
    verify()
