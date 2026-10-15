"""Verify current D03.1 implementation closeout; independent/product acceptance stays pending."""
from pathlib import Path
import hashlib,json
import xml.etree.ElementTree as ET
ROOT=Path(__file__).resolve().parent.parent
R=ROOT/'remediation';P='D03-1-STAGING-'
EXPECTED_CLASSES={'AutomationSecretTest','WhmcsContactPersistenceTest','VaultUnifiedTest','StagingSecretTest','SqlPortabilityCloseoutTest','SecretStorageTest','SecretEgressTest','SecretAcceptanceTest','QueueSecretTest','ProvisioningSecretTest','ProviderVaultSchemaTest','PrivacySigningTest','PrivacyMigrationInstallTest','PrivacyLegacyTest','PrivacyBindingTest','KeyLifecycleTest','InstallationAcceptanceTest','DomainSecretTest'}
def identity(case):
 name=case.get('name')
 if ' with data set ' in name:
  method,value=name.rsplit(' with data set ',1);name=method+(value if value.startswith('#') else '#'+value[1:-1])
 return case.get('class')+'::'+name

def verify():
 for name in ['source-freeze','hashes']:
  data=json.loads((R/(P+name+'.json')).read_text(encoding='utf-8-sig'))
  assert data['reference_commit']=='051513d74b1a223e125cda8fd4366e93a577f9de'
  for file,digest in data['files'].items(): assert hashlib.sha256((ROOT/file).read_bytes()).hexdigest()==digest,file
 stats={};sets={}
 for name in ['main','native']:
  tree=ET.parse(R/(P+name+'.xml'));suite=tree.getroot().find('testsuite')
  assert suite is not None and all(int(suite.get(a,'0'))==0 for a in ['errors','failures','skipped'])
  cases=list(tree.iter('testcase'));ids={identity(c) for c in cases}
  assert len(cases)==len(ids)==int(suite.get('tests'))
  assert not list(tree.iter('failure')) and not list(tree.iter('error')) and not list(tree.iter('warning'))
  stats[name]={a:int(suite.get(a)) for a in ['tests','assertions']};sets[name]=ids
  if name=='native':
   expected=ET.parse(R/(P+'native-expected.xml'));ns={'p':'https://xml.phpunit.de/testSuite'};classes=expected.findall('.//p:testClass',ns)
   assert len(classes)==18 and {c.get('name').rsplit('\\',1)[-1] for c in classes}==EXPECTED_CLASSES
   assert ids=={m.get('id') for m in expected.findall('.//p:testMethod',ns)}
 parts=json.loads((R/(P+'native-partitions.json')).read_text(encoding='utf-8-sig'))
 assert parts['reference_commit']=='051513d74b1a223e125cda8fd4366e93a577f9de' and set(parts['parts'])=={'A','B','C','D'}
 combined=set();classes=[];assertions=0
 for part in parts['parts'].values():
  raw=ET.parse(R/part['junit']);suite=raw.getroot().find('testsuite');assert suite is not None
  assert all(int(suite.get(a,'0'))==0 for a in ['errors','failures','skipped'])
  cases=list(raw.iter('testcase'));ids={identity(c) for c in cases}
  assert len(ids)==len(cases)==int(suite.get('tests')) and ids==set(part['expected_ids'])
  assert not combined.intersection(ids);combined.update(ids);classes.extend(part['classes']);assertions+=int(suite.get('assertions'))
  assert not list(raw.iter('failure')) and not list(raw.iter('error')) and not list(raw.iter('warning'))
  assert 'OK (' in (R/part['output']).read_text(encoding='utf-8-sig')
 assert combined==sets['native'] and assertions==stats['native']['assertions']
 assert len(classes)==len(set(classes))==18 and set(classes)==EXPECTED_CLASSES
 before_tree=ET.parse(R/(P+'before.xml'));before=before_tree.getroot().find('testsuite')
 assert int(before.get('tests'))==int(before.get('failures'))==2 and int(before.get('errors'))==0
 assert {identity(c) for c in before_tree.iter('testcase')}<=sets['main']
 for label,prefix,count in [('main','Coleza\\Tests\\Unit\\Security\\StagingSecretTest::',20),('native','Coleza\\Tests\\MariaDb\\StagingSecretTest::',25)]: assert sum(i.startswith(prefix) for i in sets[label])==count
 state=json.loads((R/'state.json').read_text(encoding='utf-8-sig'));audit=state['d03_general_secret_audit']
 assert state['step']==state['next_step']=='D03.2' and state['status']=='IN_PROGRESS' and state['phase']=='D03'
 assert not state['production_ready'] and state['independent_acceptance']=='PENDING'
 assert state['phase_results']['D02']['status']=='IMPLEMENTATION_COMPLETE_LOCAL_VERIFIED'
 assert state['step_results']['D03.1']['status']=='IMPLEMENTATION_COMPLETE_LOCAL_VERIFIED'
 assert state['step_results']['D03.1']['independent_acceptance']=='PENDING'
 assert 'D03.1' not in state['remaining_steps'] and state['remaining_steps']==['D03.2','D03.3','D03.4']
 assert audit['status']=='IMPLEMENTATION_COMPLETE_LOCAL_VERIFIED'
 for field in ['queue_storage','staging_storage','privacy_signing_new_records','privacy_legacy_integrity','privacy_key_lifecycle']: assert audit[field]=='IMPLEMENTED_LOCAL_VERIFIED'
 for field in ['remaining','acceptance_remaining','operational_carriers_remaining']: assert audit[field]==[]
 assert not state['tests']['full_mariadb_current_source']
 for name,label in [('main','main'),('native','mariadb')]: assert state['tests'][label+'_total']==stats[name]['tests'] and state['tests'][label+'_assertions']==stats[name]['assertions']
 baseline=json.loads((R/(P+'baseline.json')).read_text(encoding='utf-8-sig'));assert len(baseline['migrations_unchanged'])==26
 for file,digest in baseline['migrations_unchanged'].items(): assert hashlib.sha256((ROOT/file).read_bytes()).hexdigest()==digest,file
 assert len(baseline['original_plan_documents'])==2 and len(baseline['original_plan_archive_sha256'])==64
 assert {Path(n).name for n in baseline['original_plan_documents']}=={'SECURITY_CONSTITUTION.md','DATA_CONSTITUTION.md'}
 scan=(R/(P+'security-scan.txt')).read_text(encoding='utf-8-sig');assert 'STATUS: ALL SECURITY, ARCHITECTURE & DEPENDENCY SCANS PASSED (PASS)' in scan
 matrix=(R/'D03-1-SECRET-INVENTORY.md').read_text(encoding='utf-8-sig');assert '**AÇIK Q1:**' not in matrix and '**AÇIK Q2:**' not in matrix and '**Q2 UYGULANDI:**' in matrix
 assert (R/'D03-1-CLOSURE.md').is_file()
 print('Verified D03.1 implementation closeout:',stats,'; exact 18 native classes; Q1/Q2 complete; next D03.2; independent acceptance pending.')
if __name__=='__main__': verify()
