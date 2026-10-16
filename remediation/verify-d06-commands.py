"""Current-source local evidence for fixed D06.2 gate3; external and process acceptance remain open."""
from pathlib import Path
import hashlib,json,re,subprocess,xml.etree.ElementTree as ET
ROOT=Path(__file__).resolve().parent.parent
R=ROOT/'remediation'
PREFIX='d06-commands-'
CLASSES={'HostingCommandTest','HostingDispatchTest','ProvisioningProbeSafetyTest','ProviderVaultSchemaTest','ProvisioningSecretTest'}
def identity(case):
    name=case.get('name')
    if ' with data set ' in name:
        method,value=name.rsplit(' with data set ',1)
        name=method+(value if value.startswith('#') else '#'+value[1:-1])
    return case.get('class')+'::'+name

def verify():
    baseline=json.loads((R/(PREFIX+'baseline.json')).read_text(encoding='utf-8'))
    assert len(baseline['source_sha256'])==59 and len(baseline['migration_sha256'])==26
    for name,digest in (baseline['source_sha256']|baseline['migration_sha256']).items():
        assert hashlib.sha256((ROOT/name).read_bytes()).hexdigest()==digest,name
    for name,digest in (baseline['migration_sha256']|baseline['before_source_sha256']).items():
        assert hashlib.sha256(subprocess.check_output(['git','show',baseline['reference_commit']+':'+name],cwd=ROOT)).hexdigest()==digest,name
    stats={}
    for kind,count in [('main',2282),('native',109)]:
        tree=ET.parse(R/(PREFIX+kind+'.xml'));suite=tree.getroot().find('testsuite');cases=list(tree.iter('testcase'));ids={identity(c) for c in cases}
        assert len(ids)==len(cases)==int(suite.get('tests'))==count
        assert all(int(suite.get(k,'0'))==0 for k in ['failures','errors','skipped'])
        assert not any(list(tree.iter(k)) for k in ['failure','error','warning','skipped'])
        assert (R/(PREFIX+kind+'.exit')).read_text(encoding='utf-8-sig').strip()=='0'
        output=(R/(PREFIX+kind+'.txt')).read_text(encoding='utf-8-sig')
        assert 'OK (' in output and 'but there were issues' not in output
        assert not re.search(r'(?m)^(?:PHP )?(?:Warning|Notice|Deprecated):',output)
        assert len([c for c in cases if c.get('class').endswith('\\HostingCommandTest')])==42
        assert len([c for c in cases if c.get('class').endswith('\\HostingDispatchTest')])==23
        if kind=='native':
            discovery=ET.parse(R/(PREFIX+'discovery.xml'))
            assert {c.get('name').rsplit('\\',1)[-1] for c in discovery.findall('.//{*}testClass')}==CLASSES
            assert ids=={m.get('id') for m in discovery.findall('.//{*}testMethod')}
        stats[kind]={k:int(suite.get(k)) for k in ['tests','assertions']}
    before=ET.parse(R/(PREFIX+'before.xml')).getroot().find('testsuite')
    assert tuple(int(before.get(k)) for k in ['tests','failures','errors'])==(3,3,0)
    assert (R/(PREFIX+'before.exit')).read_text(encoding='utf-8-sig').strip()=='1'
    summary=json.loads((R/(PREFIX+'summary.json')).read_text(encoding='utf-8'))
    assert summary['main']==stats['main'] and summary['native']==stats['native']
    assert summary['completed_gates']==3 and summary['total_gates']==4 and not summary['production_ready']
    checks=json.loads((R/(PREFIX+'checks.json')).read_text(encoding='utf-8'))
    assert {c['name'] for c in checks}=={'plan','protected_manifest','diff_whitespace'} and all(c['exit_code']==0 for c in checks)
    state=json.loads((R/'state.json').read_text(encoding='utf-8'))
    assert state['phase']=='D06' and state['step']=='D06.2' and state['step_status']=='IN_PROGRESS'
    assert state['step_results']['D06.2']['completed_gates']==3 and state['step_results']['D06.2']['remaining_gates']==['process_protocol_acceptance']
    assert state['step_results']['D06.1']['status']=='IMPLEMENTATION_COMPLETE_LOCAL_VERIFIED'
    assert not state['production_ready'] and state['independent_acceptance']=='PENDING' and not state['tests']['full_mariadb_current_source']
    for phase in ['D02','D03','D04','D05']:assert state['phase_results'][phase]['status']=='IMPLEMENTATION_COMPLETE_LOCAL_VERIFIED'
    hashes=R/(PREFIX+'hashes.json')
    if hashes.exists():
        for name,digest in json.loads(hashes.read_text(encoding='utf-8'))['files'].items():assert hashlib.sha256((ROOT/name).read_bytes()).hexdigest()==digest,name
    return stats
if __name__=='__main__':print('Verified D06.2 gate3 / 3 of 4:',json.dumps(verify()))
