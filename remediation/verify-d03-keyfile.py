"""Verify file-backed key evidence, exact native selection and unchanged migrations."""
from pathlib import Path
import hashlib
import json
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parent.parent
R = ROOT / 'remediation'
PREFIX = 'D03-1-KEYFILE-'


def case_id(case):
    name = case.get('name')
    if ' with data set ' in name:
        method, value = name.rsplit(' with data set ', 1)
        name = method + (value if value.startswith('#') else '#' + value[1:-1])
    return case.get('class') + '::' + name


def result(name):
    tree = ET.parse(R / (PREFIX + name + '.xml'))
    suite = tree.getroot().find('testsuite')
    assert suite is not None
    for attr in ('errors', 'failures', 'skipped'):
        assert int(suite.get(attr, '0')) == 0
    cases = list(tree.iter('testcase'))
    ids = {case_id(case) for case in cases}
    assert len(cases) == len(ids) == int(suite.get('tests'))
    output = (R / (PREFIX + name + '.txt')).read_text(encoding='utf-8-sig')
    assert 'OK (' in output and 'Warnings:' not in output
    return {'tests': int(suite.get('tests')), 'assertions': int(suite.get('assertions'))}, ids


def verify():
    for name in ('source-freeze', 'hashes'):
        data = json.loads((R / (PREFIX + name + '.json')).read_text(encoding='utf-8-sig'))
        for file, digest in data['files'].items():
            assert hashlib.sha256((ROOT / file).read_bytes()).hexdigest() == digest, file
    main, _ = result('main')
    native, ids = result('native')
    discovery = ET.parse(R / (PREFIX + 'native-expected.xml'))
    ns = {'p': 'https://xml.phpunit.de/testSuite'}
    assert len(discovery.findall('.//p:testClass', ns)) == 7
    assert ids == {m.get('id') for m in discovery.findall('.//p:testMethod', ns)}
    before = ET.parse(R / (PREFIX + 'before.xml')).getroot().find('testsuite')
    assert before is not None and int(before.get('tests')) == int(before.get('failures')) == 1
    assert int(before.get('errors')) == 0
    state = json.loads((R / 'state.json').read_text(encoding='utf-8-sig'))
    assert state['status'] == 'IN_PROGRESS' and state['step'] == 'D03.1'
    assert state['production_ready'] is False and state['independent_acceptance'] == 'PENDING'
    assert state['tests']['full_mariadb_current_source'] is False
    assert (state['tests']['main_total'], state['tests']['main_assertions']) == (main['tests'], main['assertions'])
    assert (state['tests']['mariadb_total'], state['tests']['mariadb_assertions']) == (native['tests'], native['assertions'])
    baseline = json.loads((R / (PREFIX + 'baseline.json')).read_text(encoding='utf-8-sig'))
    assert baseline['reference_commit'] == 'e1c37cdbccc70eec4bbedcd8344d79ae5a7e2738'
    assert len(baseline['migrations_unchanged']) == 26
    for file, digest in baseline['migrations_unchanged'].items():
        assert hashlib.sha256((ROOT / file).read_bytes()).hexdigest() == digest, file
    policy = json.loads((ROOT / 'tools/reviewed-processes.json').read_text(encoding='utf-8'))
    assert set(policy['files']) == {'src/Domain/Vault/PrivateKeyFile.php'}
    assert policy['files']['src/Domain/Vault/PrivateKeyFile.php']['sha256'] == hashlib.sha256((ROOT / 'src/Domain/Vault/PrivateKeyFile.php').read_bytes()).hexdigest()
    assert 'PASS: approved call and six fail-closed' in (R / (PREFIX + 'process-policy.txt')).read_text(encoding='utf-8-sig')
    assert 'STATUS: ALL SECURITY, ARCHITECTURE & DEPENDENCY SCANS PASSED (PASS)' in (R / (PREFIX + 'security-scan.txt')).read_text(encoding='utf-8-sig')
    print(f'Verified main {main["tests"]}/{main["assertions"]}, affected native {native["tests"]}/{native["assertions"]}; exact seven classes, unchanged 26 migrations; D03.1 open.')


if __name__ == '__main__':
    verify()
