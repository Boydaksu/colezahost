"""Verify D03.2 local evidence at its own source revision; no production acceptance claim."""
from pathlib import Path
import hashlib,json,xml.etree.ElementTree as ET
ROOT=Path(__file__).resolve().parent.parent
R=ROOT/'remediation'
CLASSES={'OrganizationAuthorizationTest','IdentityLifecycleTest','ApiSessionPersistenceTest','InstallationAcceptanceTest','LegacyIdentitySchemaTest'}
def identity(case):
    name=case.get('name')
    if ' with data set ' in name:
        method,value=name.rsplit(' with data set ',1)
        name=method+(value if value.startswith('#') else '#'+value[1:-1])
    return case.get('class')+'::'+name

def verify():
    baseline=json.loads((R/'D03-2-baseline.json').read_text(encoding='utf-8-sig'))
    for group in ['source_sha256','migration_sha256']:
        for file,digest in baseline[group].items():
            assert hashlib.sha256((ROOT/file).read_bytes()).hexdigest()==digest,file
    assert len(baseline['migration_sha256'])==26
    stats={}
    for kind in ['main','native']:
        tree=ET.parse(R/f'D03-2-{kind}.xml');suite=tree.getroot().find('testsuite')
        assert suite is not None
        assert all(int(suite.get(k,'0'))==0 for k in ['errors','failures','skipped'])
        cases=list(tree.iter('testcase'));ids={identity(c) for c in cases}
        assert len(ids)==len(cases)==int(suite.get('tests'))
        assert not any(list(tree.iter(tag)) for tag in ['failure','error','warning','skipped'])
        output=(R/f'D03-2-{kind}.txt').read_text(encoding='utf-8-sig')
        assert 'OK (' in output and 'but there were issues' not in output
        stats[kind]={k:int(suite.get(k)) for k in ['tests','assertions']}
        if kind=='native':
            ns={'p':'https://xml.phpunit.de/testSuite'}
            discovery=ET.parse(R/'D03-2-native-discovery.xml')
            classes=discovery.findall('.//p:testClass',ns)
            assert {c.get('name').rsplit('\\',1)[-1] for c in classes}==CLASSES and len(classes)==5
            assert ids=={m.get('id') for m in discovery.findall('.//p:testMethod',ns)}
    before=ET.parse(R/'D03-2-before.xml').getroot().find('testsuite')
    assert int(before.get('tests'))==int(before.get('failures'))==3 and int(before.get('errors'))==0
    state=json.loads((R/'state.json').read_text(encoding='utf-8-sig'))
    assert state['step']==state['next_step']=='D03.3' and state['remaining_steps']==['D03.3','D03.4']
    assert state['step_results']['D03.2']['status']=='IMPLEMENTATION_COMPLETE_LOCAL_VERIFIED'
    assert state['step_results']['D03.1']['status']=='IMPLEMENTATION_COMPLETE_LOCAL_VERIFIED'
    assert not state['production_ready'] and state['independent_acceptance']=='PENDING'
    assert state['d03_1_remaining_buckets']==[]
    assert not state['tests']['full_mariadb_current_source']
    for kind,label in [('main','main'),('native','mariadb')]:
        assert state['tests'][label+'_total']==stats[kind]['tests']
        assert state['tests'][label+'_assertions']==stats[kind]['assertions']
    hashes=R/'D03-2-hashes.json'
    if hashes.exists():
        for file,digest in json.loads(hashes.read_text())['files'].items():
            assert hashlib.sha256((ROOT/file).read_bytes()).hexdigest()==digest,file
    print(json.dumps(stats))
if __name__=='__main__': verify()
