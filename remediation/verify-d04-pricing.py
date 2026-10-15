"""D04.1 evidence verifier, valid against its recorded source revision."""
from pathlib import Path
import hashlib,json,xml.etree.ElementTree as ET
ROOT=Path(__file__).resolve().parent.parent
R=ROOT/'remediation'
CLASSES={'CommerceIntegrityTest','CommandAuthorizationTest','FinancialConcurrencyTest','SqlPortabilityCloseoutTest','InstallationAcceptanceTest','DocumentPersistenceTest'}

def identity(case):
    name=case.get('name')
    if ' with data set ' in name:
        method,value=name.rsplit(' with data set ',1)
        name=method+(value if value.startswith('#') else '#'+value[1:-1])
    return case.get('class')+'::'+name

def verify():
    baseline=json.loads((R/'D04-1-baseline.json').read_text(encoding='utf-8'))
    for group in ['source_sha256','migration_sha256']:
        for file,digest in baseline[group].items():
            assert hashlib.sha256((ROOT/file).read_bytes()).hexdigest()==digest,file
    assert len(baseline['migration_sha256'])==26
    stats={}
    for kind in ['main','mariadb']:
        tree=ET.parse(R/f'D04-1-{kind}.xml');suite=tree.getroot().find('testsuite')
        assert suite is not None
        assert all(int(suite.get(k,'0'))==0 for k in ['errors','failures','skipped'])
        cases=list(tree.iter('testcase'));ids={identity(c) for c in cases}
        assert len(ids)==len(cases)==int(suite.get('tests'))
        assert not any(list(tree.iter(tag)) for tag in ['failure','error','warning','skipped'])
        output=(R/f'D04-1-{kind}.txt').read_text(encoding='utf-8-sig')
        assert 'OK (' in output and 'but there were issues' not in output
        stats[kind]={k:int(suite.get(k)) for k in ['tests','assertions']}
        if kind=='mariadb':
            discovery=ET.parse(R/'D04-1-discovery.xml')
            classes=discovery.findall('.//{*}testClass')
            assert {c.get('name').rsplit('\\',1)[-1] for c in classes}==CLASSES and len(classes)==6
            assert ids=={m.get('id') for m in discovery.findall('.//{*}testMethod')}
    before=ET.parse(R/'D04-1-before.xml').getroot().find('testsuite')
    assert int(before.get('tests'))==int(before.get('failures'))==5 and int(before.get('errors'))==0
    state=json.loads((R/'state.json').read_text(encoding='utf-8'))
    assert state['step']=='D04.1' and state['next_step']=='D04.2' and state['remaining_steps']==['D04.2','D04.3']
    assert state['step_results']['D04.1']['status']=='IMPLEMENTATION_COMPLETE_LOCAL_VERIFIED'
    assert not state['production_ready'] and state['independent_acceptance']=='PENDING'
    assert not state['tests']['full_mariadb_current_source']
    for kind in ['main','mariadb']:
        assert state['tests'][kind+'_total']==stats[kind]['tests']
        assert state['tests'][kind+'_assertions']==stats[kind]['assertions']
    assert state['phase_results']['D03']['status']=='IMPLEMENTATION_COMPLETE_LOCAL_VERIFIED'
    assert all(state['step_results'][f'D03.{i}']['status']=='IMPLEMENTATION_COMPLETE_LOCAL_VERIFIED' for i in range(1,5))
    assert 'D03' not in state['remaining_phases'] and 'D04' in state['remaining_phases']
    hashes=R/'D04-1-hashes.json'
    if hashes.exists():
        for file,digest in json.loads(hashes.read_text(encoding='utf-8'))['files'].items():
            assert hashlib.sha256((ROOT/file).read_bytes()).hexdigest()==digest,file
    print(json.dumps(stats))
if __name__=='__main__': verify()
