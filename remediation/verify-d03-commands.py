"""Verify D03.4 local evidence at its own source revision; no production acceptance claim."""
from pathlib import Path
import hashlib,json,xml.etree.ElementTree as ET
ROOT=Path(__file__).resolve().parent.parent
R=ROOT/'remediation'
CLASSES={'CommandAuthorizationTest','AuthenticationBoundaryTest','OrganizationAuthorizationTest','IdentityLifecycleTest','InstallationAcceptanceTest','LegacyIdentitySchemaTest','ApiSessionPersistenceTest','SqlPortabilityCloseoutTest','SupportPersistenceTest','FinancialConcurrencyTest','DocumentPersistenceTest'}
def identity(case):
    name=case.get('name')
    if ' with data set ' in name:
        method,value=name.rsplit(' with data set ',1)
        name=method+(value if value.startswith('#') else '#'+value[1:-1])
    return case.get('class')+'::'+name

def verify():
    baseline=json.loads((R/'D03-4-baseline.json').read_text(encoding='utf-8-sig'))
    for group in ['source_sha256','migration_sha256']:
        for file,digest in baseline[group].items():
            assert hashlib.sha256((ROOT/file).read_bytes()).hexdigest()==digest,file
    assert len(baseline['migration_sha256'])==26
    stats={}
    for kind in ['main','native']:
        tree=ET.parse(R/f'D03-4-{kind}.xml');suite=tree.getroot().find('testsuite')
        assert suite is not None
        assert all(int(suite.get(k,'0'))==0 for k in ['errors','failures','skipped'])
        cases=list(tree.iter('testcase'));ids={identity(c) for c in cases}
        assert len(ids)==len(cases)==int(suite.get('tests'))
        assert not any(list(tree.iter(tag)) for tag in ['failure','error','warning','skipped'])
        output=(R/f'D03-4-{kind}.txt').read_text(encoding='utf-8-sig')
        assert 'OK (' in output and 'but there were issues' not in output
        stats[kind]={k:int(suite.get(k)) for k in ['tests','assertions']}
        if kind=='native':
            ns={'p':'https://xml.phpunit.de/testSuite'}
            discovery=ET.parse(R/'D03-4-discovery.xml')
            classes=discovery.findall('.//p:testClass',ns)
            assert {c.get('name').rsplit('\\',1)[-1] for c in classes}==CLASSES and len(classes)==11
            assert ids=={m.get('id') for m in discovery.findall('.//p:testMethod',ns)}
    before=ET.parse(R/'D03-4-before.xml').getroot().find('testsuite')
    assert int(before.get('tests'))==int(before.get('failures'))==5 and int(before.get('errors'))==0
    state=json.loads((R/'state.json').read_text(encoding='utf-8-sig'))
    assert state['step']=='D03.4' and state['next_step']=='D04' and state['remaining_steps']==[]
    assert state['step_results']['D03.4']['status']=='IMPLEMENTATION_COMPLETE_LOCAL_VERIFIED'
    assert state['step_results']['D03.1']['status']=='IMPLEMENTATION_COMPLETE_LOCAL_VERIFIED'
    assert not state['production_ready'] and state['independent_acceptance']=='PENDING'
    assert state['d03_1_remaining_buckets']==[]
    assert not state['tests']['full_mariadb_current_source']
    for kind,label in [('main','main'),('native','mariadb')]:
        assert state['tests'][label+'_total']==stats[kind]['tests']
        assert state['tests'][label+'_assertions']==stats[kind]['assertions']
    assert state['phase_results']['D03']['status']=='IMPLEMENTATION_COMPLETE_LOCAL_VERIFIED'
    assert all(state['step_results'][f'D03.{i}']['status']=='IMPLEMENTATION_COMPLETE_LOCAL_VERIFIED' for i in range(1,5))
    assert 'D03' not in state['remaining_phases']
    parts=json.loads((R/'D03-4-native-partitions.json').read_text());combined=set();assertions=0
    for part in parts['parts'].values():
        tree=ET.parse(R/part['junit']);suite=tree.getroot().find('testsuite');cases=list(tree.iter('testcase'));actual={identity(c)for c in cases}
        assert all(int(suite.get(k,'0'))==0 for k in ['errors','failures','skipped'])
        assert actual==set(part['expected_ids']) and len(actual)==len(cases)==int(suite.get('tests'))
        assert not combined.intersection(actual);combined.update(actual);assertions+=int(suite.get('assertions'))
        assert 'OK (' in (R/part['output']).read_text(encoding='utf-8-sig')
    expected={m.get('id')for m in ET.parse(R/'D03-4-discovery.xml').findall('.//{*}testMethod')}
    assert combined==expected and assertions==stats['native']['assertions']
    hashes=R/'D03-4-hashes.json'
    if hashes.exists():
        for file,digest in json.loads(hashes.read_text())['files'].items():
            assert hashlib.sha256((ROOT/file).read_bytes()).hexdigest()==digest,file
    print(json.dumps(stats))
if __name__=='__main__': verify()
