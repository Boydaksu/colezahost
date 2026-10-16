"""Reproduce the original post-request cPanel Error using the pinned Git blob and loopback TLS only.
Run with the protocol fixture Python environment; --php selects a PHP8.4 binary.
Exit0 proves reproduction of the bug, not a successful provider operation.
"""
import argparse,hashlib,json,os,subprocess,sys,tempfile,time
from pathlib import Path
ROOT=Path(__file__).resolve().parent.parent
parser=argparse.ArgumentParser();parser.add_argument('--php',default='php');args=parser.parse_args()
baseline=json.loads((ROOT/'remediation/d06-cpanel-baseline.json').read_text(encoding='utf-8'))
original=subprocess.check_output(['git','show',baseline['reference_commit']+':src/Domain/Providers/Cpanel/CpanelCurlTransport.php'],cwd=ROOT)
assert hashlib.sha256(original).hexdigest()==baseline['before_source_sha256']['CpanelCurlTransport']
with tempfile.TemporaryDirectory(prefix='coleza_cpanel_original_') as temporary:
 root=Path(temporary);(root/'original.php').write_bytes(original)
 (root/'probe.php').write_text(r"""<?php
 require $argv[1];require $argv[2];
 try{(new Coleza\Domain\Providers\Cpanel\CpanelCurlTransport(false))->request('POST',$argv[3],['Authorization'=>'whm root:fixture-only-token'],bodyParams:['username'=>'fixture']);echo json_encode(['unexpected_success'=>true]);exit(1);}
 catch(Throwable $e){echo json_encode(['error_class'=>get_class($e),'message'=>$e->getMessage()]);exit(str_contains($e->getMessage(),'startTime')?0:2);}
 """,encoding='utf-8',newline='\n')
 with (root/'stdout').open('w') as out,(root/'stderr').open('w') as err:
  server=subprocess.Popen([sys.executable,'-u',str(ROOT/'tests/Support/cpanel_protocol_server.py'),str(root)],stdin=subprocess.PIPE,stdout=out,stderr=err)
  try:
   for _ in range(200):
    if (root/'ready.json').exists():break
    if server.poll() is not None:raise RuntimeError('Loopback fixture failed to start')
    time.sleep(.05)
   ready=json.loads((root/'ready.json').read_text());run=subprocess.run([args.php,str(root/'probe.php'),str(ROOT/'vendor/autoload.php'),str(root/'original.php'),'https://127.0.0.1:'+str(ready['port'])+'/json-api/createacct'],capture_output=True,text=True,timeout=10)
   result=json.loads(run.stdout);result.update(probe_exit=run.returncode,recorded_requests=len((root/'requests.jsonl').read_text().splitlines()),reference_commit=baseline['reference_commit'],fixture_only_tls_bypass_for_original_bug=True,original_source_sha256=hashlib.sha256(original).hexdigest())
   assert result['probe_exit']==0 and result['recorded_requests']==1 and result['error_class']=='Error',result
   print(json.dumps(result,indent=2))
  finally:
   server.stdin.close()
   try:server.wait(timeout=10)
   except subprocess.TimeoutExpired:server.terminate();server.wait(timeout=10);raise
