"""Byte-bound evidence for the D06.2 durable dispatch and activation checkpoint, not whole-product acceptance."""
from pathlib import Path
import hashlib
import json
import re
import subprocess
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parent.parent
R = ROOT / 'remediation'
PREFIX = 'd06-dispatch-'
CLASSES = {'HostingDispatchTest', 'ProvisioningProbeSafetyTest', 'ProviderVaultSchemaTest', 'ProvisioningSecretTest'}


def identity(case):
    name = case.get('name')
    if ' with data set ' in name:
        method, value = name.rsplit(' with data set ', 1)
        name = method + (value if value.startswith('#') else '#' + value[1:-1])
    return case.get('class') + '::' + name


def verify():
    baseline = json.loads((R / (PREFIX + 'baseline.json')).read_text(encoding='utf-8'))
    assert len(baseline['source_sha256']) == 45
    assert len(baseline['migration_sha256']) == 26
    for name, digest in (baseline['source_sha256'] | baseline['migration_sha256']).items():
        assert hashlib.sha256((ROOT / name).read_bytes()).hexdigest() == digest, name
    for name, digest in baseline['migration_sha256'].items():
        original = subprocess.check_output(['git', 'show', baseline['reference_commit'] + ':' + name], cwd=ROOT)
        assert hashlib.sha256(original).hexdigest() == digest, name
    for name, digest in baseline['before_source_sha256'].items():
        original = subprocess.check_output(['git', 'show', baseline['reference_commit'] + ':' + name], cwd=ROOT)
        assert hashlib.sha256(original).hexdigest() == digest, name
    stats = {}
    for kind, count in [('main', 2237), ('native', 64)]:
        tree = ET.parse(R / (PREFIX + kind + '.xml'))
        suite = tree.getroot().find('testsuite')
        assert all(int(suite.get(k, '0')) == 0 for k in ['errors', 'failures', 'skipped'])
        assert not any(list(tree.iter(tag)) for tag in ['failure', 'error', 'warning', 'skipped'])
        cases = list(tree.iter('testcase'))
        ids = {identity(c) for c in cases}
        assert len(ids) == len(cases) == int(suite.get('tests')) == count
        output = (R / (PREFIX + kind + '.txt')).read_text(encoding='utf-8-sig')
        assert 'OK (' in output and 'but there were issues' not in output
        assert not re.search(r'(?m)^(?:PHP )?(?:Warning|Notice|Deprecated):', output)
        assert (R / (PREFIX + kind + '.exit')).read_text(encoding='utf-8-sig').strip() == '0'
        stats[kind] = {k: int(suite.get(k)) for k in ['tests', 'assertions']}
        assert len([c for c in cases if c.get('class').endswith('\\HostingDispatchTest')]) == 20
        if kind == 'native':
            discovery = ET.parse(R / (PREFIX + 'discovery.xml'))
            classes = discovery.findall('.//{*}testClass')
            assert len(classes) == 4 and {c.get('name').rsplit('\\', 1)[-1] for c in classes} == CLASSES
            assert ids == {m.get('id') for m in discovery.findall('.//{*}testMethod')}
        else:
            assert len([c for c in cases if c.get('class').endswith('\\CpanelTransportTest')]) == 62
            for name, expected in [('RemoteBackupTransportTest', 35), ('RemoteBackupConfigurationTest', 17), ('SignedUpdateSafetyTest', 23), ('UpdatePackageAdmissionTest', 25)]:
                assert len([c for c in cases if c.get('class').endswith('\\' + name)]) == expected
    assert (R / (PREFIX + 'before.exit')).read_text(encoding='utf-8-sig').strip() == '1'
    before = ET.parse(R / (PREFIX + 'before.xml')).getroot().find('testsuite')
    assert (int(before.get('tests')), int(before.get('failures')), int(before.get('errors'))) == (12, 12, 0)
    checks = json.loads((R / (PREFIX + 'checks.json')).read_text(encoding='utf-8'))
    assert {c['name'] for c in checks} == {'plan', 'protected_manifest', 'diff_whitespace'}
    assert all(c['exit_code'] == 0 for c in checks)
    summary = json.loads((R / (PREFIX + 'summary.json')).read_text(encoding='utf-8'))
    assert summary['main'] == stats['main'] and summary['native'] == stats['native']
    assert summary['completed_gates'] == 2 and summary['new_dispatch_cases'] == 20 and not summary['production_ready']
    state = json.loads((R / 'state.json').read_text(encoding='utf-8'))
    assert state['phase'] == 'D06' and state['step'] == 'D06.2' and state['step_status'] == 'IN_PROGRESS'
    assert state['remaining_steps'] == ['D06.2', 'D06.3', 'D06.4', 'D06.5']
    assert state['step_results']['D06.2']['completed_gates'] == 2
    assert state['step_results']['D06.2']['total_gates'] == 4
    assert state['step_results']['D06.2']['status'] == 'IN_PROGRESS'
    assert state['step_results']['D06.1']['status'] == 'IMPLEMENTATION_COMPLETE_LOCAL_VERIFIED'
    assert state['phase_results']['D06']['completed_steps'] == ['D06.1']
    assert not state['production_ready'] and state['independent_acceptance'] == 'PENDING'
    assert not state['tests']['full_mariadb_current_source']
    for kind, key in [('main', 'main'), ('native', 'mariadb')]:
        assert state['tests'][key + '_total'] == stats[kind]['tests']
        assert state['tests'][key + '_assertions'] == stats[kind]['assertions']
    for phase in ['D02', 'D03', 'D04', 'D05']:
        assert state['phase_results'][phase]['status'] == 'IMPLEMENTATION_COMPLETE_LOCAL_VERIFIED'
    hashes = R / (PREFIX + 'hashes.json')
    if hashes.exists():
        for name, digest in json.loads(hashes.read_text(encoding='utf-8'))['files'].items():
            assert hashlib.sha256((ROOT / name).read_bytes()).hexdigest() == digest, name
    return stats


if __name__ == '__main__':
    print(json.dumps(verify(), ensure_ascii=False))
