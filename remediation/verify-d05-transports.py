"""Verify D05.2 evidence against the source bytes recorded for this step."""
from pathlib import Path
import hashlib
import json
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parent.parent
R = ROOT / 'remediation'
CLASSES = {'BackupAdmissionTest', 'InstallationAcceptanceTest', 'SqlPortabilityCloseoutTest'}


def identity(case):
    name = case.get('name')
    if ' with data set ' in name:
        method, value = name.rsplit(' with data set ', 1)
        name = method + (value if value.startswith('#') else '#' + value[1:-1])
    return case.get('class') + '::' + name


def verify():
    baseline = json.loads((R / 'D05-2-baseline.json').read_text(encoding='utf-8'))
    for group in ['source_sha256', 'migration_sha256']:
        for name, digest in baseline[group].items():
            assert hashlib.sha256((ROOT / name).read_bytes()).hexdigest() == digest, name
    assert len(baseline['migration_sha256']) == 26
    stats = {}
    for kind in ['main', 'mariadb']:
        tree = ET.parse(R / f'D05-2-{kind}.xml')
        suite = tree.getroot().find('testsuite')
        assert suite is not None
        assert all(int(suite.get(k, '0')) == 0 for k in ['errors', 'failures', 'skipped'])
        assert not any(list(tree.iter(tag)) for tag in ['failure', 'error', 'warning', 'skipped'])
        cases = list(tree.iter('testcase'))
        ids = {identity(case) for case in cases}
        assert len(ids) == len(cases) == int(suite.get('tests'))
        output = (R / f'D05-2-{kind}.txt').read_text(encoding='utf-8-sig')
        assert 'OK (' in output and 'but there were issues' not in output
        stats[kind] = {k: int(suite.get(k)) for k in ['tests', 'assertions']}
        if kind == 'mariadb':
            discovery = ET.parse(R / 'D05-2-discovery.xml')
            classes = discovery.findall('.//{*}testClass')
            assert {c.get('name').rsplit('\\', 1)[-1] for c in classes} == CLASSES and len(classes) == 3
            assert ids == {m.get('id') for m in discovery.findall('.//{*}testMethod')}
        else:
            protocol = [c for c in cases if c.get('class', '').endswith('\\RemoteBackupTransportTest')]
            config = [c for c in cases if c.get('class', '').endswith('\\RemoteBackupConfigurationTest')]
            assert len(protocol) == 35 and len(config) == 17
            stats['protocol'] = {'tests': len(protocol), 'assertions': sum(int(c.get('assertions')) for c in protocol)}
            stats['configuration'] = {'tests': len(config), 'assertions': sum(int(c.get('assertions')) for c in config)}
    before = ET.parse(R / 'D05-2-before.xml').getroot().find('testsuite')
    assert int(before.get('tests')) == int(before.get('failures')) == 2 and int(before.get('errors')) == 0
    checks = json.loads((R / 'D05-2-checks.json').read_text(encoding='utf-8'))
    assert {c['name'] for c in checks} == {'plan', 'protected_manifest', 'diff_whitespace'}
    assert all(c['exit_code'] == 0 for c in checks)
    state = json.loads((R / 'state.json').read_text(encoding='utf-8'))
    assert state['step'] == 'D05.2' and state['next_step'] == 'D05.3'
    assert state['remaining_steps'] == ['D05.3', 'D05.4', 'D05.5']
    assert state['step_results']['D05.2']['status'] == 'IMPLEMENTATION_COMPLETE_LOCAL_VERIFIED'
    assert state['step_results']['D05.2']['external_account_acceptance'] == 'PENDING'
    assert not state['production_ready'] and state['independent_acceptance'] == 'PENDING'
    assert not state['tests']['full_mariadb_current_source']
    for kind in ['main', 'mariadb']:
        assert state['tests'][kind + '_total'] == stats[kind]['tests']
        assert state['tests'][kind + '_assertions'] == stats[kind]['assertions']
    for phase in ['D02', 'D03', 'D04']:
        assert state['phase_results'][phase]['status'] == 'IMPLEMENTATION_COMPLETE_LOCAL_VERIFIED'
        assert phase not in state['remaining_phases']
    hashes = R / 'D05-2-hashes.json'
    if hashes.exists():
        for name, digest in json.loads(hashes.read_text(encoding='utf-8'))['files'].items():
            assert hashlib.sha256((ROOT / name).read_bytes()).hexdigest() == digest, name
    print(json.dumps(stats))
    return stats


if __name__ == '__main__':
    verify()
