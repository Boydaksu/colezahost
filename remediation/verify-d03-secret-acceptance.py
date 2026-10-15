"""Verify the bounded ACCEPT evidence, without claiming D03.1 or production closure."""
from pathlib import Path
import hashlib
import json
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parent.parent
R = ROOT / 'remediation'
P = 'D03-1-ACCEPT-'
EXPECTED_CLASSES = {
    'SecretAcceptanceTest', 'FinancialConcurrencyTest', 'SqlPortabilityCloseoutTest',
    'PrivacyBindingTest', 'ProvisioningSecretTest', 'SecretEgressTest',
}

def identity(case):
    name = case.get('name')
    if ' with data set ' in name:
        method, value = name.rsplit(' with data set ', 1)
        name = method + (value if value.startswith('#') else '#' + value[1:-1])
    return case.get('class') + '::' + name

def verify():
    for name in ['source-freeze', 'hashes']:
        data = json.loads((R / (P + name + '.json')).read_text(encoding='utf-8-sig'))
        assert data['reference_commit'] == '552e8dad1e8160866cf2ff2ce07ceb670072a2f1'
        for file, digest in data['files'].items():
            assert hashlib.sha256((ROOT / file).read_bytes()).hexdigest() == digest, file
    stats = {}
    sets = {}
    for name in ['main', 'native']:
        tree = ET.parse(R / (P + name + '.xml'))
        suite = tree.getroot().find('testsuite')
        assert suite is not None and all(int(suite.get(a, '0')) == 0 for a in ['errors', 'failures', 'skipped'])
        assert not list(tree.iter('warning')) and not list(tree.iter('error')) and not list(tree.iter('failure'))
        cases = list(tree.iter('testcase'))
        ids = {identity(c) for c in cases}
        assert len(cases) == len(ids) == int(suite.get('tests'))
        stats[name] = {a: int(suite.get(a)) for a in ['tests', 'assertions']}
        sets[name] = ids
        if name == 'native':
            expected = ET.parse(R / (P + 'native-expected.xml'))
            ns = {'p': 'https://xml.phpunit.de/testSuite'}
            classes = expected.findall('.//p:testClass', ns)
            assert {c.get('name').rsplit('\\', 1)[-1] for c in classes} == EXPECTED_CLASSES
            assert len(classes) == len(EXPECTED_CLASSES)
            assert ids == {m.get('id') for m in expected.findall('.//p:testMethod', ns)}
    before_tree = ET.parse(R / (P + 'before.xml'))
    before = before_tree.getroot().find('testsuite')
    assert int(before.get('tests')) == int(before.get('failures')) == 5 and int(before.get('errors')) == 0
    assert {identity(c) for c in before_tree.iter('testcase')} <= sets['main']
    for label, prefix in [('main', 'Coleza\\Tests\\Unit\\Security\\SecretAcceptanceTest::'), ('native', 'Coleza\\Tests\\MariaDb\\SecretAcceptanceTest::')]:
        assert sum(x.startswith(prefix) for x in sets[label]) == 16
    state = json.loads((R / 'state.json').read_text(encoding='utf-8-sig'))
    assert state['status'] == 'IN_PROGRESS' and state['step'] == 'D03.1'
    assert not state['production_ready'] and state['independent_acceptance'] == 'PENDING'
    assert state['phase_results']['D02']['status'] == 'IMPLEMENTATION_COMPLETE_LOCAL_VERIFIED'
    assert not state['tests']['full_mariadb_current_source']
    for name, label in [('main', 'main'), ('native', 'mariadb')]:
        assert state['tests'][label + '_total'] == stats[name]['tests']
        assert state['tests'][label + '_assertions'] == stats[name]['assertions']
    audit = state['d03_general_secret_audit']
    assert audit['remaining'] == ['COMPREHENSIVE_SINK_LEGACY_EXPORT_AND_RESPONSE_ACCEPTANCE']
    assert audit['acceptance_remaining'] == ['Q1_QUEUE_PAYLOAD_PROTECTION', 'Q2_MIGRATION_STAGING_PAYLOAD_PROTECTION']
    assert audit['operational_carriers_remaining'] == ['jobs/failed_jobs payload', 'migration staging raw/canonical JSON']
    for field in ['privacy_signing_new_records', 'privacy_legacy_integrity', 'privacy_key_lifecycle']:
        assert audit[field] == 'IMPLEMENTED_LOCAL_VERIFIED'
    baseline = json.loads((R / (P + 'baseline.json')).read_text(encoding='utf-8-sig'))
    assert len(baseline['migrations_unchanged']) == 26
    for file, digest in baseline['migrations_unchanged'].items():
        assert hashlib.sha256((ROOT / file).read_bytes()).hexdigest() == digest, file
    assert 'STATUS: ALL SECURITY, ARCHITECTURE & DEPENDENCY SCANS PASSED (PASS)' in (R / (P + 'security-scan.txt')).read_text(encoding='utf-8-sig')
    print('Verified bounded secret acceptance:', stats, '; 16 shared cases; six exact native classes; Q1/Q2 remain open.')

if __name__ == '__main__':
    verify()
