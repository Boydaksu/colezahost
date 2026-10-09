import pathlib,re,json,collections,zipfile,hashlib,subprocess
root=pathlib.Path(__file__).resolve().parent.parent
out=root/'review'
rows=json.loads((out/'inventory.json').read_text(encoding='utf-8'))
defs={}
for row in rows:
 if not row['path'].endswith('.php'):continue
 t=(root/row['path']).read_text(encoding='utf-8-sig')
 ns=re.search(r'^namespace\s+([^;]+);',t,re.M)
 for cls in re.findall(r'^(?:final\s+|abstract\s+|readonly\s+)*(?:class|interface|enum|trait)\s+(\w+)',t,re.M):
  defs[(ns.group(1)+'\\' if ns else '')+cls]=row['path']
unresolved=[]; portability=[]; domain_edges=[]; suppressions=[]; nullable_auth=[]
for row in rows:
 rel=row['path'];t=(root/rel).read_text(encoding='utf-8-sig',errors='replace')
 if rel.startswith('src/'):
  for i,l in enumerate(t.splitlines(),1):
   m=re.match(r'use\s+(Coleza\\[^;]+);',l)
   if m:
    cls=m.group(1).split(' as ')[0]
    if cls not in defs: unresolved.append({'path':rel,'line':i,'class':cls})
    if rel.startswith('src/Domain/') and cls.startswith('Coleza\\Domain\\'):
     source=rel.split('/')[2];target=cls.split('\\')[2]
     if source!=target:domain_edges.append({'path':rel,'line':i,'source':source,'target':target,'class':cls})
   if re.search(r'ON CONFLICT|INSERT OR IGNORE|INSERT OR REPLACE|strftime\(',l):portability.append({'path':rel,'line':i,'content':l.strip()})
   if re.search(r'@(?:file_get_contents|fopen|fsockopen|simplexml_load_string|unserialize|unlink|mkdir)\(',l):suppressions.append({'path':rel,'line':i,'content':l.strip()})
   if '?int $authUserId = null' in l:nullable_auth.append({'path':rel,'line':i})
evidence={}
for p in range(19):
 d=root/f'evidence/v1/P{p:02}'
 files=list(d.rglob('*'));evidence[d.name]={'files':len([x for x in files if x.is_file()]),'manifest':(d/'manifest.json').exists(),'raw_test_output':(d/'test-results.txt').exists(),'changed_files':(d/'changed-files.txt').exists(),'review_files':[x.name for x in files if 'review' in x.name.lower()],'images':[x.name for x in files if x.suffix.lower() in ['.png','.jpg','.webp']],'gate':(d/'gate-decision.md').exists()}
test_imports=collections.defaultdict(list)
for r in rows:
 if not r['path'].startswith('tests/'):continue
 for cls in r['imports'].split('; '):
  if cls in defs:test_imports[defs[cls]].append(r['path'])
summary={'git_commit':subprocess.check_output(['git','rev-parse','HEAD'],cwd=root,text=True).strip(),'files':len(rows),'source':sum(r['path'].startswith('src/') for r in rows),'test_files':sum(r['path'].startswith('tests/') for r in rows),'unresolved_imports':unresolved,'portable_sql_violations':portability,'domain_edges':domain_edges,'suppression_calls':suppressions,'nullable_auth':nullable_auth,'evidence':evidence,'test_direct_imports':dict(test_imports),'php_files':sum(r['path'].endswith('.php') and r['path'].startswith('src/') for r in rows),'js_files':[r['path'] for r in rows if r['path'].endswith('.js')],'migration_implementations':[r['path'] for r in rows if 'implements MigrationInterface' in (root/r['path']).read_text(encoding='utf-8-sig',errors='replace')]}
z=root/'colezahost.zip'
if z.exists():
 with zipfile.ZipFile(z) as a:
  names=a.namelist();summary['local_zip']={'entries':len(names),'vendor_files':sum('/vendor/' in n or n.startswith('vendor/') for n in names),'manifest':[n for n in names if n.endswith(('manifest.json','manifest.sig','CHECKSUMS.sha256'))],'web_entrypoints':[n for n in names if n.endswith(('index.php','cron.php'))],'sha256':hashlib.sha256(z.read_bytes()).hexdigest()}
(out/'structural-results.json').write_text(json.dumps(summary,ensure_ascii=False,indent=2),encoding='utf-8')
print(json.dumps({k:v for k,v in summary.items() if k not in ['domain_edges','test_direct_imports','suppression_calls']},ensure_ascii=False,indent=2))
