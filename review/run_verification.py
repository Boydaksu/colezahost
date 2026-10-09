import pathlib,subprocess,json,datetime,hashlib,shutil,os
root=pathlib.Path(__file__).resolve().parent.parent
jobs=[('plan',['python','09-machine-readable/validate_plan.py']),('manifest',['python','tools/verify_manifest.py']),('constitutions',['python','tools/verify_constitutions.py']),('scope',['python','tools/verify_scope_dependencies.py']),('gate',['python','tools/check_gate.py']),('forbidden',['python','tools/scan_forbidden_actions.py']),('security',['python','tools/scan_security_and_secrets.py']),('composer_validate',['composer','validate','--no-check-publish']),('composer_audit',['composer','audit','--format=json']),('behavior_probes',['php','review/behavior_probes.php'])]
results=[]
for label,cmd in jobs:
 started=datetime.datetime.now(datetime.timezone.utc).isoformat()
 resolved=[shutil.which(cmd[0]) or cmd[0]]+cmd[1:]
 r=subprocess.run(subprocess.list2cmdline(resolved) if os.name=='nt' else resolved,cwd=root,capture_output=True,text=True,encoding='utf-8',errors='replace',shell=os.name=='nt')
 (root/'review'/f'{label}-output.txt').write_text(r.stdout+'\n'+r.stderr,encoding='utf-8')
 results.append({'check':label,'command':cmd,'started_utc':started,'exit_code':r.returncode,'stdout':r.stdout,'stderr':r.stderr})
 print(label,'exit',r.returncode,r.stdout.strip().splitlines()[-1] if r.stdout.strip() else r.stderr[:100])
(root/'review/verification-results.json').write_text(json.dumps(results,ensure_ascii=False,indent=2),encoding='utf-8')
original=root/'review-original-plan/hosting-platform-master-plan'
print('original files',len([p for p in original.rglob('*') if p.is_file()]))
print('original zip sha256',hashlib.sha256(pathlib.Path('C:/Users/alici/Downloads/hosting-platform-master-plan.zip').read_bytes()).hexdigest())
