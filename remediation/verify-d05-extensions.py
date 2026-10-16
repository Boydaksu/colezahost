"""Byte-bound evidence for the D05.5 extension lifecycle checkpoint, not whole-product acceptance."""
from pathlib import Path
import hashlib
import json
import subprocess
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parent.parent
R = ROOT / 'remediation'
PREFIX = 'd05-extension-'
CLASSES = {'ExtensionLifecycleTest', 'UpdatePublicationTest', 'UpdateMigrationTest', 'BackupAdmissionTest', 'BackupRecoveryTest',
           'PreUpdateRecoveryTest', 'SignedUpdateAdmissionTest', 'ModuleSchemaSafetyTest',
           'InstallationAcceptanceTest', 'KeyLifecycleTest', 'OperationalModePersistenceTest',
           'PrivacyBindingTest', 'PrivacySigningTest', 'SqlPortabilityCloseoutTest'}


def identity(case):
    name = case.get('name')
    if ' with data set ' in name:
        method, value = name.rsplit(' with data set ', 1)
        name = method + (value if value.startswith('#') else '#' + value[1:-1])
    return case.get('class') + '::' + name


def verify():
    baseline = json.loads((R / (PREFIX + 'baseline.json')).read_text(encoding='utf-8'))
    assert len(baseline['source_sha256']) == 25
    assert len(baseline['migration_sha256']) == 26
    for name, digest in (baseline['source_sha256'] | baseline['migration_sha256']).items():
        assert hashlib.sha256((ROOT / name).read_bytes()).hexdigest() == digest, name
    for name, digest in baseline['migration_sha256'].items():
        original = subprocess.check_output(['git', 'show', baseline['reference_commit'] + ':' + name], cwd=ROOT)
        assert hashlib.sha256(original).hexdigest() == digest, name
    for name, digest in baseline['before_source_sha256'].items():
        original = subprocess.check_output(['git', 'show', baseline['reference_commit'] + ':src/Domain/Module/' + name + '.php'], cwd=ROOT)
        assert hashlib.sha256(original).hexdigest() == digest, name
    stats = {}
    for kind, count in [('main', 2155), ('native', 207)]:
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
        assert len([c for c in cases if c.get('class').endswith('\\ExtensionLifecycleTest')]) == 43
        expected_new = 25 if kind == 'main' else 27
        assert len([c for c in cases if c.get('class').endswith('\\UpdatePublicationTest')]) == expected_new
        assert len([c for c in cases if c.get('class').endswith('\\UpdateMigrationTest')]) == (22 if kind == 'main' else 23)
        if kind == 'native':
            discovery = ET.parse(R / (PREFIX + 'discovery.xml'))
            classes = discovery.findall('.//{*}testClass')
            assert len(classes) == 14 and {c.get('name').rsplit('\\', 1)[-1] for c in classes} == CLASSES
            assert ids == {m.get('id') for m in discovery.findall('.//{*}testMethod')}
        else:
            for name, expected in [('RemoteBackupTransportTest', 35), ('RemoteBackupConfigurationTest', 17), ('SignedUpdateSafetyTest', 23), ('UpdatePackageAdmissionTest', 25)]:
                assert len([c for c in cases if c.get('class').endswith('\\' + name)]) == expected
    assert (R / (PREFIX + 'before.exit')).read_text(encoding='utf-8-sig').strip() == '1'
    before = ET.parse(R / (PREFIX + 'before.xml')).getroot().find('testsuite')
    assert (int(before.get('tests')), int(before.get('failures')), int(before.get('errors'))) == (3, 3, 0)
    checks = json.loads((R / (PREFIX + 'checks.json')).read_text(encoding='utf-8'))
    assert {c['name'] for c in checks} == {'plan', 'protected_manifest', 'diff_whitespace'}
    assert all(c['exit_code'] == 0 for c in checks)
    summary = json.loads((R / (PREFIX + 'summary.json')).read_text(encoding='utf-8'))
    assert summary['main'] == stats['main'] and summary['native'] == stats['native']
    assert summary['d05_complete'] and summary['new_cases_per_database'] == 43
    state = json.loads((R / 'state.json').read_text(encoding='utf-8'))
    assert state['step'] == 'D06.1' and state['step_status'] == 'PENDING'
    assert state['remaining_steps'] == []
    assert state['step_results']['D05.4']['completed_gates'] == 4
    assert state['step_results']['D05.4']['status'] == 'IMPLEMENTATION_COMPLETE_LOCAL_VERIFIED'
    assert state['d05_4_gates']['real_migrations'] == 'IMPLEMENTATION_COMPLETE_LOCAL_VERIFIED'
    assert all(value == 'IMPLEMENTATION_COMPLETE_LOCAL_VERIFIED' for value in state['d05_4_gates'].values())
    assert not state['production_ready'] and state['independent_acceptance'] == 'PENDING'
    assert not state['tests']['full_mariadb_current_source']
    for kind, key in [('main', 'main'), ('native', 'mariadb')]:
        assert state['tests'][key + '_total'] == stats[kind]['tests']
        assert state['tests'][key + '_assertions'] == stats[kind]['assertions']
    for phase in ['D02', 'D03', 'D04', 'D05']:
        assert state['phase_results'][phase]['status'] == 'IMPLEMENTATION_COMPLETE_LOCAL_VERIFIED'
    assert state['step_results']['D05.5']['status'] == 'IMPLEMENTATION_COMPLETE_LOCAL_VERIFIED'
    hashes = R / (PREFIX + 'hashes.json')
    if hashes.exists():
        for name, digest in json.loads(hashes.read_text(encoding='utf-8'))['files'].items():
            assert hashlib.sha256((ROOT / name).read_bytes()).hexdigest() == digest, name
    return stats


if __name__ == '__main__':
    print(json.dumps(verify(), ensure_ascii=False))
