import pathlib,csv,json,re,zipfile,hashlib,datetime,shutil,subprocess
root=pathlib.Path(__file__).resolve().parent.parent;out=root/'review'
files=list(csv.DictReader((out/'DOSYA_DOSYA.csv').open(encoding='utf-8-sig')))
phases=list(csv.DictReader((out/'ALT_FAZ_MATRISI.csv').open(encoding='utf-8-sig')))
refs=json.loads((out/'reference-validation.json').read_text(encoding='utf-8'))
probes=[json.loads(x) for x in (out/'behavior-probe-results.jsonl').read_text(encoding='utf-8-sig').splitlines() if x.strip()]
assert len(files)==1152 and len({r['dosya'] for r in files})==1152
assert len(phases)==155 and len({r['alt_faz'] for r in phases})==155
assert len(probes)==23 and not refs['issues']
assert 'organization_id' in probes[0]['message']
assert probes[1]['result']['reported_success'] and probes[1]['result']['actual_transport']=='memory'
assert probes[3]['result']['invoice_status']=='paid'
assert probes[4]['result']['paid_minor']==10000
assert not probes[5]['result']['next_request_paused']
assert not probes[6]['result']['remote_exists'] and not probes[6]['result']['local_archive_exists']
assert probes[7]['result']['total_minor']<0
assert probes[8]['result']['paid_minor']>probes[8]['result']['total_minor']
assert probes[9]['result']['decrypted_with_public_fallback']=='synthetic-secret'
assert not probes[10]['result']['success']
assert probes[11]['result']['reported_invoice_count']==0
assert probes[12]['result']['update_result']['migrations_run']==1 and not probes[12]['result']['migration_table_exists']
assert probes[13]['result']['queries']==101
assert not probes[14]['result']['contains_last_line']
assert probes[15]['result']['old_password_still_authenticates']
assert probes[16]['result']['exported_invoices']==0
assert probes[17]['result']['reported_health']['status']=='healthy'
assert probes[18]['result']['after_first']!=probes[18]['result']['after_replay_same_invoice']
assert 'startTime' in probes[19]['message']
assert probes[20]['result']['auth_secret_encrypted']=='synthetic-secret'
assert probes[21]['result']['accepted_order_total_minor']==1
assert probes[22]['result']['result']['success'] and not probes[22]['result']['real_provider_called']
assert subprocess.check_output(['git','diff','--name-only'],cwd=root,text=True).strip()==''
for r in files:
 assert hashlib.sha256((root/r['dosya']).read_bytes()).hexdigest()==r['sha256'],r['dosya']
for r in refs['references']:
 assert r['needle'] in (root/r['path']).read_text(encoding='utf-8-sig').splitlines()[r['line']-1]
assert not re.search(r'\[\[.*\|',(out/'INCELEME.md').read_text(encoding='utf-8'))
shutil.copyfile(root/'review-test-results.xml',out/'review-test-results.xml')
verification=json.loads((out/'verification-results.json').read_text(encoding='utf-8'))
for r in verification:
 if r['check']=='behavior_probes':
  r['original_started_utc']=r.pop('started_utc')
  r['superseding_output_recorded_utc']=datetime.datetime.fromtimestamp((out/'behavior-probe-results.jsonl').stat().st_mtime,datetime.timezone.utc).isoformat()
(out/'verification-results.json').write_text(json.dumps(verification,ensure_ascii=False,indent=2),encoding='utf-8')
summary={'tracked_file_records':1152,'original_v1_subphases':155,'report_findings':len(re.findall(r'^### F\d+', (out/'INCELEME.md').read_text(encoding='utf-8'),re.M)),'source_line_references_verified':len(refs['references']),'targeted_behavior_observations_verified':23,'tracked_product_changes':0,'phpunit_tests':900,'phpunit_assertions':7916,'phpunit_deprecations':2,'scope_limit':'File-level automated static coverage for every tracked file; focused source review and isolated reproduction for critical flows; no live provider/MariaDB/browser test.'}
(out/'review-quality-check.json').write_text(json.dumps(summary,ensure_ascii=False,indent=2),encoding='utf-8')
include=['INCELEME.md','DOSYA_DOSYA.md','DOSYA_DOSYA.csv','ALT_FAZ_MATRISI.md','ALT_FAZ_MATRISI.csv','original-plan-diff.txt','behavior-probe-results.jsonl','behavior_probes.php','phpunit-output.txt','review-test-results.xml','verification-results.json','verification-results-initial.json','reference-validation.json','structural-results.json','review-quality-check.json','composer_audit-output.txt','composer_validate-output.txt']
zip_path=out/'ColezaHost-Inceleme-2026-10-09.zip'
with zipfile.ZipFile(zip_path,'w',zipfile.ZIP_DEFLATED) as z:
 for name in include:z.write(out/name,name)
(out/'INCELEME-PAKETI.sha256').write_text(hashlib.sha256(zip_path.read_bytes()).hexdigest()+'  '+zip_path.name+'\n',encoding='utf-8')
print(json.dumps(summary,ensure_ascii=False));print('Review package:',zip_path,'bytes:',zip_path.stat().st_size)
