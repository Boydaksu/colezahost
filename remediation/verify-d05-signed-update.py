"""Verify this D05.4 signed package/mandatory backup checkpoint against its recorded source bytes."""
from pathlib import Path
import hashlib
import json
import subprocess
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parent.parent
R = ROOT / 'remediation'
PREFIX = 'D05-4-signed-'
CLASSES = {
    'BackupAdmissionTest', 'BackupRecoveryTest', 'PreUpdateRecoveryTest',
    'SignedUpdateAdmissionTest', 'ModuleSchemaSafetyTest', 'InstallationAcceptanceTest', 'KeyLifecycleTest', 'OperationalModePersistenceTest',
    'PrivacyBindingTest', 'PrivacySigningTest', 'SqlPortabilityCloseoutTest',
}


def identity(case):
    name = case.get('name')
    if ' with data set ' in name:
        method, value = name.rsplit(' with data set ', 1)
        name = method + (value if value.startswith('#') else '#' + value[1:-1])
    return case.get('class') + '::' + name


def verify():
    baseline = json.loads((R / (PREFIX + 'baseline.json')).read_text(encoding='utf-8'))
    for group in ['source_sha256', 'migration_sha256']:
        for name, digest in baseline[group].items():
            assert hashlib.sha256((ROOT / name).read_bytes()).hexdigest() == digest, name
    for name,digest in baseline['before_source_sha256'].items():
        assert hashlib.sha256(subprocess.check_output(['git','show',baseline['reference_commit']+':'+name],cwd=ROOT)).hexdigest() == digest, name
    assert len(baseline['before_source_sha256']) == 4
    assert len(baseline['source_sha256']) == 15
    assert len(baseline['migration_sha256']) == 26
    stats = {}
    for kind, count in [('main', 2065), ('native', 114)]:
        tree = ET.parse(R / (PREFIX + kind + '.xml'))
        suite = tree.getroot().find('testsuite')
        assert all(int(suite.get(k, '0')) == 0 for k in ['errors', 'failures', 'skipped'])
        assert not any(list(tree.iter(tag)) for tag in ['failure', 'error', 'warning', 'skipped'])
        cases = list(tree.iter('testcase'))
        ids = {identity(c) for c in cases}
        assert len(ids) == len(cases) == int(suite.get('tests')) == count
        output = (R / (PREFIX + kind + '.txt')).read_text(encoding='utf-8-sig')
        assert 'OK (' in output and 'but there were issues' not in output
        assert (R / (PREFIX + kind + '.exit')).read_text(encoding='utf-8-sig').strip() == '0'
        stats[kind] = {k: int(suite.get(k)) for k in ['tests', 'assertions']}
        if kind == 'native':
            discovery = ET.parse(R / (PREFIX + 'discovery.xml'))
            classes = discovery.findall('.//{*}testClass')
            assert len(classes) == 11 and {c.get('name').rsplit('\\', 1)[-1] for c in classes} == CLASSES
            assert ids == {m.get('id') for m in discovery.findall('.//{*}testMethod')}
        else:
            for name, expected in [('SignedUpdateSafetyTest', 23), ('UpdatePackageAdmissionTest', 25), ('UpdateVersionTest', 15), ('RemoteBackupTransportTest', 35), ('RemoteBackupConfigurationTest', 17)]:
                assert len([c for c in cases if c.get('class').endswith('\\' + name)]) == expected
    before = ET.parse(R / (PREFIX + 'before.xml')).getroot().find('testsuite')
    assert int(before.get('tests')) == 23 and int(before.get('failures')) == 20 and int(before.get('errors')) == 1
    checks = json.loads((R / (PREFIX + 'checks.json')).read_text(encoding='utf-8'))
    assert {c['name'] for c in checks} == {'plan', 'protected_manifest', 'diff_whitespace'}
    assert all(c['exit_code'] == 0 for c in checks)
    state = json.loads((R / 'state.json').read_text(encoding='utf-8'))
    assert state['step'] == 'D05.4' and state['step_status'] == 'IN_PROGRESS'
    assert state['remaining_steps'] == ['D05.4', 'D05.5']
    assert state['d05_4_gates']['pre_update_backup'] == 'IMPLEMENTATION_COMPLETE_LOCAL_VERIFIED'
    assert all(state['d05_4_gates'][k] == 'PENDING' for k in ['real_migrations', 'staged_publication_and_rollback'])
    assert state['d05_4_gates']['signed_package_and_backup_gate'] == 'IMPLEMENTATION_COMPLETE_LOCAL_VERIFIED'
    assert not state['production_ready'] and state['independent_acceptance'] == 'PENDING'
    assert not state['tests']['full_mariadb_current_source']
    for kind, key in [('main', 'main'), ('native', 'mariadb')]:
        assert state['tests'][key + '_total'] == stats[kind]['tests']
        assert state['tests'][key + '_assertions'] == stats[kind]['assertions']
    for phase in ['D02', 'D03', 'D04']:
        assert state['phase_results'][phase]['status'] == 'IMPLEMENTATION_COMPLETE_LOCAL_VERIFIED'
    summary = json.loads((R / (PREFIX + 'summary.json')).read_text(encoding='utf-8'))
    assert summary['main'] == stats['main'] and summary['native'] == stats['native']
    assert summary['d05_4_complete'] is False and summary['completed_gates'] == 2
    hashes = R / (PREFIX + 'hashes.json')
    if hashes.exists():
        for name, digest in json.loads(hashes.read_text(encoding='utf-8'))['files'].items():
            assert hashlib.sha256((ROOT / name).read_bytes()).hexdigest() == digest, name
    return stats


if __name__ == '__main__':
    print(json.dumps(verify(), ensure_ascii=False))
