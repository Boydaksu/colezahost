import pathlib, json, re, hashlib, csv, difflib
root=pathlib.Path(__file__).resolve().parent.parent
out=root/'review'; out.mkdir(exist_ok=True)
tracked=__import__('subprocess').check_output(['git','ls-files'],cwd=root,text=True).splitlines()
rows=[]
for rel in tracked:
 p=root/rel
 if not p.is_file(): continue
 raw=p.read_bytes(); txt=raw.decode('utf-8-sig',errors='replace'); lines=txt.splitlines()
 imports=re.findall(r'^use ([^;]+);',txt,re.M)
 methods=re.findall(r'(?:public|protected|private)\s+(?:static\s+)?function\s+(\w+)',txt)
 flags=[]
 patterns={'DDL':'CREATE TABLE','SQLite syntax':'AUTOINCREMENT|INSERT OR IGNORE|INSERT OR REPLACE|sqlite_master|PRAGMA|strftime\\(','Simulation':'simulated|simulate|Mock|MemoryMail|InMemory','Remote transport':'curl_exec|stream_socket_client|ssh2_|file_get_contents\\(.*https','Mutable finance':'UPDATE .*?(?:invoices|payments|credit_ledger)','Runtime exception':'throw new .*?(?:RuntimeException|LogicException)','TODO':'TODO|FIXME|HACK|TEMP','Domain SQL':'SELECT |INSERT INTO |UPDATE |DELETE FROM '}
 for name,pat in patterns.items():
  hits=[str(i+1) for i,l in enumerate(lines) if re.search(pat,l,re.I)]
  if hits: flags.append(name+':'+','.join(hits[:18]))
 rows.append({'path':rel,'bytes':len(raw),'lines':len(lines),'sha256':hashlib.sha256(raw).hexdigest(),'methods':'; '.join(methods),'imports':'; '.join(imports),'signals':'; '.join(flags),'review_scope':'Dosya içeriği statik tarandı; ayrıntılı bulgular için ana rapora bakınız.'})
with (out/'file-inventory.csv').open('w',encoding='utf-8-sig',newline='') as f:
 w=csv.DictWriter(f,fieldnames=rows[0].keys());w.writeheader();w.writerows(rows)
(out/'inventory.json').write_text(json.dumps(rows,ensure_ascii=False,indent=2),encoding='utf-8')
original=root/'review-original-plan/hosting-platform-master-plan'
changes=[]
for p in sorted(original.rglob('*')):
 if not p.is_file():continue
 rel=p.relative_to(original).as_posix(); cur=root/rel
 a=p.read_text(encoding='utf-8-sig').splitlines(); b=cur.read_text(encoding='utf-8-sig').splitlines() if cur.exists() else []
 if a!=b:changes.append('\n'.join(difflib.unified_diff(a,b,fromfile='original/'+rel,tofile='current/'+rel)))
(out/'original-plan-diff.txt').write_text('\n\n'.join(changes),encoding='utf-8')
phase=[]
for p in sorted((original/'06-phases/v1').glob('*.md')):
 txt=p.read_text(encoding='utf-8');phase += [p.name]+[l for l in txt.splitlines() if l.startswith('### ') or l.startswith('**HARD')]
(out/'original-subphases.txt').write_text('\n'.join(phase),encoding='utf-8')
print('Tracked files',len(rows),'source',sum(r['path'].startswith('src/') for r in rows),'tests',sum(r['path'].startswith('tests/') for r in rows))
print('Semantic plan diffs',len(changes))
print('\n'.join(r['path']+' '+r['signals'] for r in rows if r['path'].startswith('src/') and ('SQLite syntax' in r['signals'] or 'Simulation' in r['signals'])))
