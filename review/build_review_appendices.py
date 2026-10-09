import pathlib,re,json,csv,collections,html,subprocess,datetime,shutil
root=pathlib.Path(__file__).resolve().parent.parent; out=root/'review'
inventory=json.loads((out/'inventory.json').read_text(encoding='utf-8'))
struct=json.loads((out/'structural-results.json').read_text(encoding='utf-8'))
source=out/'report-source.md'
if not source.exists():source.write_text((out/'INCELEME.md').read_text(encoding='utf-8'),encoding='utf-8')
report=source.read_text(encoding='utf-8').replace('.github/workflows/verify.yml','.github/workflows/verify-integrity.yml')
issues=[];file_findings=collections.defaultdict(list);references=[]
for section in re.split(r'(?=^### F\d+)',report,flags=re.M)[1:]:
 fid=re.match(r'### (F\d+)',section).group(1)
 section=section.split('\n## ')[0]
 for rel,needle in re.findall(r'\[\[([^|]+)\|(.*?)\]\]',section):file_findings[rel].append(fid)
def resolve(m):
 rel,needle=m.group(1),m.group(2);p=root/rel
 if not p.exists():issues.append('MISSING '+rel);return '`'+rel+'`'
 lines=p.read_text(encoding='utf-8-sig').splitlines(); matches=[i+1 for i,l in enumerate(lines) if needle in l]
 line=matches[0] if matches else None
 if not matches:issues.append('NO_LINE '+rel+' | '+needle)
 references.append({'path':rel,'line':line,'needle':needle})
 return '['+p.name+(':'+str(line) if line else '')+']('+p.as_posix()+(':'+str(line) if line else '')+')'
report=re.sub(r'\[\[([^|]+)\|(.*?)\]\]',resolve,report)
(out/'INCELEME.md').write_text(report,encoding='utf-8')
(out/'reference-validation.json').write_text(json.dumps({'issues':issues,'references':references},ensure_ascii=False,indent=2),encoding='utf-8')
print('References',len(references),'issues',issues)
def link(rel,label=None): return '['+(label or rel)+']('+ (root/rel).as_posix()+')'
phase_notes={
'P00':('Kısmi / onay kanıtı yetersiz','F28 F29','Plan ve kontrol dosyaları korunuyor; gate/CI gerçek uygulama kabulünü zorlamıyor.'),
'P01':('Kısmi / üretim engelli','F01 F02 F03 F22','Framework bileşenleri mevcut; executable uygulama, canonical migrations ve katman enforcement eksik.'),
'P02':('Kısmi / gerçek DB doğrulaması açık','F16 F26','Operasyon primitives mevcut; atomiklik, kalıcı scheduler/cron ve doğru health bağlantıları doğrulanmalı.'),
'P03':('Kısmi / güvenlik engelli','F03 F08 F09 F23 F31','Güvenlik bileşenleri mevcut; kurulum şema çatışması, sır saklama ve guard entegrasyonu engelli.'),
'P04':('İskelet / ürün yüzeyi eksik','F01 F23 F24','HTML/API bileşenleri var; gerçek route, ekran, assets/JS ve middleware zinciri yok.'),
'P05':('Bileşen var / kabul açık','F07 F30','Katalog/fiyat/vergi hesaplayıcıları korunabilir; authoritative commerce entegrasyonu ve mutation kanıtı eksik.'),
'P06':('Kısmi / finans engelli','F02 F05 F07 F16','Ticaret modeli ve testler var; settlement yetkisi, MariaDB, para invariants ve atomiklik eksik.'),
'P07':('Kısmi / belge kabulü açık','F02 F25','Finans ve belge modelleri var; gerçek UI/DB, Unicode ve çok sayfalı PDF kabulü eksik.'),
'P08':('Kısmi / entegrasyon engelli','F06 F23 F27','Transport/controller bileşenleri var; callback doğrulaması, gerçek SMTP ve auth/sandbox kanıtı eksik.'),
'P09':('Kısmi / secret ve atomiklik açık','F09 F16','Service/provider/placement sözleşmeleri var; credential encryption ve kapasite yarışları kapanmalı.'),
'P10':('Gerçek cPanel yolu engelli','F04 F30','Mock hosting workflow var; native transport hata veriyor, gerçek uçtan uca provider kanıtı yok.'),
'P11':('Kısmi / kalıcılık ve replay engelli','F14 F15 F22','Automation/renewal modeli var; pause/approve/delay/history kalıcı değil; renewal replay hatalı.'),
'P12':('Kısmi / provider kabulü açık','F02 F27','NameSilo ve domain modelleri var; provider kararı, expiry/contact, sandbox ve MariaDB doğrulanmalı.'),
'P13':('Kısmi / UI ve komut etkisi açık','F01 F21','Ticket/SLA/attachment modelleri var; gerçek ekranlar, Application komutları ve izin bağları eksik.'),
'P14':('Kısmi / privacy engelli','F18 F19 F20 F30','Risk/abuse modelleri var; commerce chain, tam export, erasure ve restore scrub güvenliği eksik.'),
'P15':('Canonical veride engelli','F17','Analytics fixture şeması gerçek commerce şemasıyla uyuşmuyor; hatalar sıfır veri olarak gizleniyor.'),
'P16':('Kısmi / cutover kanıtı eksik','F32','Migration çekirdeği mevcut; raporsuz cutover, hold entegrasyonu ve gerçek kaynak completeness doğrulanmalı.'),
'P17':('Kurulum/recovery engelli','F01 F03 F10 F11 F12 F13 F20 F26','Web installer, backup, update ve health kabulünde tekrar üretilmiş ciddi hatalar var.'),
'P18':('Stable kabulü RED','F28 F29 F30 F33','Release testleri ve beyanları var; gerçek ürün/sandbox/DB/browser/independent Stable kanıtı yetersiz.')}
overrides={
'P00.1':('Kontrol dosyaları var','Git/manifest/CI kurulmuş; CI koruması yalnız plan/manifest kontrolü.'),
'P00.2':('Metinler korunmuş','7 anayasa özgün içerikle aynı. Kod uyumu dosya varlığıyla kanıtlanmıyor.'),
'P00.3':('Kapsam kayıtları korunmuş','Scope/roadmap/domain kayıtları özgün planla aynı; ürün tamamlanmasına delil değildir.'),
'P00.4':('Eksik gate','Faz kanıt paketleri eksik; gerçek CI PHP/test/security/DB çalıştırmıyor.'),
'P00.5':('Protokol var / enforcement yetersiz','AI rollerinin dokümanı var; independent reviewer kimliği/kanıtı doğrulanamadı.'),
'P01.1':('Kısmi','Runtime bootstrap mevcut; HTTP/CLI uygulama girişi yok.'),
'P01.2':('Kısmi','Container var; Core provider domainlere bağımlı ve Vault key fallback sabit.'),
'P01.3':('Kısmi','HTTP/router/pipeline var; gerçek uygulama routes/middleware bileşimi yok.'),
'P01.4':('Engelli','Connection/migrator primitive var; production migration yok, şemalar çatışıyor.'),
'P01.6':('Kısmi','Command/Query/Event sözleşmeleri var; tüm business girişleri bunları kullanmıyor.'),
'P01.7':('Yetersiz kontrol','Regex architecture testleri kritik cross-domain/DI sınırlarını kapsamıyor.'),
'P02.4':('Kısmi','DB unique lock primitive var; kritik domain mutasyonlarına kapsamlı bağlanmamış.'),
'P02.5':('Atomiklik açık','Pop read/update row lock veya conditional claim yok; priority sütunu ve gerçek jitter yok.'),
'P02.6':('Kısmi','Scheduler primitive mevcut; gerçek bin/cron entry ve Doctor zaman alanı uyuşmuyor.'),
'P02.7':('İskelet var','Skeleton P02 için anlamlı; P17 production kabulünün yerine kullanılamaz.'),
'P03.1':('Kısmi','Auth/session/token bileşenleri var; secure cookie/session rotation/login rate-limit production chain yok.'),
'P03.2':('Kısmi / secret açık','TOTP/recovery/trusted device var; TOTP plaintext ve mandatory admin policy login zincirine bağlı değil.'),
'P03.4':('Engelli','Fresh installer şemasında RBAC sorgusu hata veriyor; granular guard tüm komutlara bağlı değil.'),
'P03.5':('Kısmi','Impersonation guard/audit var; gerçek session/operation yüzeylerine bağ doğrulanamadı.'),
'P03.6':('Kısmi','Dictionary parity testi geçiyor; shell kullanıcı metinleri hardcoded ve CI parity koşmuyor.'),
'P03.8':('Engelli','Vault encryption var; bilinen fallback key ve alternatif plaintext credential yolları mevcut.'),
'P03.10':('Kısmi','Manifest/lifecycle var; quarantine/path/permission/migration/health install zinciri eksik.'),
'P04.1':('Bileşen var','Tokens ve renderer var; deploy edilmiş asset yolu ve tam bileşen deneyimi eksik.'),
'P04.2':('İskelet','Admin shell HTML’i mevcut; operational screens/theme-density davranışı yok.'),
'P04.3':('İskelet','Client shell HTML’i mevcut; gerçek self-service pages/default theme akışı yok.'),
'P04.4':('Kısmi','DataTable/drawer/modal HTML’i mevcut; progressive JS/focus davranışı yok.'),
'P04.5':('Kısmi','Search/palette HTML/modeli var; Ctrl+K event ve gerçek veri/yetki entegrasyonu yok.'),
'P04.6':('Sınırlı harness','Regex/renk hesabı var; browser accessibility/keyboard/focus/visual yok.'),
'P04.7':('Kısmi','ApiKey/rate-limit/errors helper var; gerçek /api/v1 route-auth-scope-idempotency chain yok.'),
'P04.8':('Kısmi','OpenAPI/log helper var; gerçek route sözleşmesiyle otomatik doğrulama yok.'),
'P05.8':('Eksik kabul kanıtı','Golden hesaplama testleri var; mutation runner/skor/rapor yok.'),
'P06.1':('Engelli','Order modeli var; müşteri unit_price_minor override ile authoritative fiyatı bypass ediyor.'),
'P06.2':('Engelli','Invoice snapshot modeli var; negatif/client totals, MariaDB numbering ve finalize invariants açık.'),
'P06.3':('Engelli','Müşteri manual yöntemiyle completed ödeme ve paid invoice üretebiliyor.'),
'P06.4':('Atomiklik açık','Partial/split/refund bileşenleri var; race/reconciliation ve transaction garantileri eksik.'),
'P06.5':('Atomiklik açık','Credit ledger var; debit check ve write tek atomic koruma altında değil.'),
'P06.8':('Bileşen E2E','Doğrudan hizmet çağrıları test ediliyor; gerçek HTTP/UI/MariaDB kullanıcı akışı değil.'),
'P07.4':('Engelli','PDF tek sayfa ve uzun metin kesiliyor; Türkçe Unicode font desteği yok.'),
'P07.5':('Kısmi','Numbering/version/snapshot/private storage var; bazı DDL SQLite’a özgü, concurrent version kabulü açık.'),
'P08.1':('Kısmi / false success','Gerçek SMTP transport var; default NotificationEngine ve installer test memory transport.'),
'P08.4':('Kısmi','Outbound signer/retry/delivery var; gerçek HTTP/security/queue bağları doğrulanmadı.'),
'P08.5':('Auth uyumu açık','iyzico native HTTP var; raw JSON SHA1 auth güncel HMAC-SHA256 sözleşmesiyle farklı.'),
'P08.6':('Engelli','Callback paid amount/currency kontrolü eksik; event unique/atomiklik ve refund uncertainty açık.'),
'P08.7':('Kısmi / güvenlik engelli','4 commerce controller/Application var; fiyat/settlement açıkları ve route binding eksik.'),
'P09.5':('Atomiklik açık','Reserve→commit/release var; capacity check ve increment transactional/conditional değil.'),
'P09.7':('Kısmi / plaintext açık','Vault mapping var; ServerService auth_secret_encrypted alanına plaintext yazıyor.'),
'P09.8':('Sınırlı concurrency kanıtı','Optimistic-edit testleri var; gerçek MariaDB paralel capacity/payment proof yok.'),
'P10.1':('Engelli','Native cURL transport yanıt sonrası undefined constant hatası veriyor.'),
'P10.2':('Engelli','Adapter operasyonları var; gerçek transport kullanıldığında R20 hatası tüm yolları etkiliyor.'),
'P10.3':('Gerçek provider kabulü açık','Change package adapter/test var; gerçek transport/sandbox doğrulaması yok.'),
'P10.4':('Mock workflow var','6 adımlı workflow mevcut; executable queue/Application satış zinciri eksik.'),
'P10.5':('Kısmi','Retry/classification/reconciliation var; gerçek side-effect sonrası transport error ve race kabulü açık.'),
'P10.6':('Mock failure kanıtı','Duplicate prevention testleri mock fixtures; gerçek provider/parallel failure kanıtı yok.'),
'P10.7':('Eksik E2E','Golden test doğrudan completed ödeme yazıyor; risk/iyzico callback/DBQueue/SMTP yolu yok.'),
'P11.2':('Engelli','Approval/delay/version/run history bellekte; request/process restart ile kaybolur.'),
'P11.3':('Mimari sapma','Automation adapterları ortak Application komutları yerine domain ServiceService çağırıyor.'),
'P11.4':('Kısmi','Renewal invoice primitives var; canonical MariaDB ve period transaction kabulü açık.'),
'P11.5':('Engelli','Overdue DB state var; provider lifecycle/Application bağları ve paid-invoice replay sorunu mevcut.'),
'P11.6':('Engelli','Pause All yeni instance/process’te active’e dönüyor.'),
'P11.7':('Engelli','Catch-up bileşeni var; aynı paid invoice hizmeti iki dönem yeniliyor.'),
'P12.4':('Provider kabulü açık','NameSilo seçilmiş; kullanıcının mevcut sağlayıcısını kilitleme onayı ve real sandbox evidence yok.'),
'P12.5':('Kısmi / doğruluk açık','Register/renew/etc var; expiry bugün+years ve registration contact iletimi eksik.'),
'P12.6':('Kısmi','Operation idempotency/reconciliation var; gerçek timeout/sandbox/parallel kanıtı açık.'),
'P12.8':('Fixture E2E','Golden domain senaryosu mevcut; production provider/ödeme/UI akış kanıtı değil.'),
'P13.3':('Korunabilir bileşen','Private storage/extension/MIME/size/path denetimleri ve testleri mevcut; gerçek HTTP permission bağları açık.'),
'P13.6':('Engelli','Builtin contextual commands hiçbir işlem yapmadan success döndürüyor; gerçek Application handler yok.'),
'P13.7':('Bileşen var / entry bağları açık','TicketPermissionService var; production UI/API enforcement gösterilemiyor.'),
'P13.8':('Kısmi','Canned response/announcement hizmetleri var; kullanıcı ekranları yok.'),
'P14.1':('Bileşen var / entegrasyon açık','Rule-based risk mevcut; gerçek order/payment pipeline’a zorunlu bağ kanıtı yok.'),
'P14.5':('Engelli','Step-up/export bileşeni var; canonical invoices/orders/services export’u eksik kalıyor.'),
'P14.6':('Engelli','Erasure success sonrası eski parola ile login mümkün; blockers yanlış şemayla fail open.'),
'P14.8':('Engelli','Tombstone store var; restore reconciliation optional/fail-open, canonical schema scrub eksik.'),
'P14.9':('Engelli','Handler registry var; bazı handlerlar farklı tabloya bakıyor, audit import’u yanlış.'),
'P15.1':('Bileşen var','Metric registry/semantics var; gerçek read-model kaynağı uyumsuz.'),
'P15.2':('Engelli','MRR snapshots hosting_services varsayıyor; commerce services canonical bağ yok.'),
'P15.3':('Engelli','Gerçek invoice mevcutken metrics zero döndürüyor; currency filtering de eksik.'),
'P15.4':('Engelli','Aggregation/rebuild var; canonical source columns ve audit import’u yanlış.'),
'P15.5':('Kısmi / gerçek veri engelli','5 dashboard DTO var; gerçek dashboard ekranı ve doğru commerce veri bağları yok.'),
'P15.6':('Kısmi / semantics açık','FX/timezone/freshness bileşenleri var; financial query currency parametresini filtrelemiyor.'),
'P15.7':('Fixture reconciliation','Golden dataset ayrı test şeması kuruyor; gerçek commerce ledger entegrasyonu kanıtlanmıyor.'),
'P15.8':('Bileşen güvenlik testi','Permission/scope testleri var; production Application/API yüzeyine bağ açık.'),
'P16.3':('Kısmi','Adoption/identity sözleşmeleri var; gerçek provider identity verifier kanıtı açık.'),
'P16.4':('Korunabilir bileşen','WHMCS read-only/version/capability scanner var; gerçek kaynak sürüm matrisi kanıtı açık.'),
'P16.9':('Kısmi','Hold/suppression servisleri var; gerçek login/mail/automation/provisioning girişlerine zorunlu bağ yok.'),
'P16.10':('Engelli','Finansal rapor veya bazı zorunlu reviewer/checkpoint bileşenleri yokken cutover PASS olabilir.'),
'P17.1':('Engelli','Web installer service var; /install sayfası yok, RBAC schema clash ve memory email false success.'),
'P17.2':('Kısmi','Fresh migration mode modelleri var; gerçek canonical fresh-install ürün/cutover E2E yok.'),
'P17.3':('Engelli','Signature/checksum var; updater migrations çalıştırmadan sayaç artırıyor.'),
'P17.4':('Engelli','Verified backup helper var; backup false ile atlanabiliyor, MariaDB dump yok.'),
'P17.5':('Engelli','Remote adapterlar stub; local cleanup yedeği yok ediyor, MariaDB dump ve recursive files eksik.'),
'P17.6':('Engelli','Restore class var; apostrof verisi syntax error, canonical DR ve privacy unseal garantisi yok.'),
'P17.7':('Kısmi','Mode state/guard var; gerçek route/job/mutation entrypoints’e uygulanmıyor.'),
'P17.8':('Engelli','System Doctor gerçek failed_jobs yerine background_jobs bakıp sağlıklı bildiriyor.'),
'P17.9':('Engelli','Rollback/tombstone bileşenleri var; gerçek updater failure/mandatory clean reconciliation bağları yok.'),
'P18.1':('Kabul yetersiz','PASS evrakı ve dosya varlığı audit’i gerçek blocker/implementation kabulünü doğrulamıyor.'),
'P18.2':('Eksik ürün E2E','G01–G08 testleri var; G02 risk/payment callback/queue yolunu atlıyor, provider/email mock.'),
'P18.3':('Eksik analiz','Regex scans mevcut; undefined constant, fixed key ve plaintext sırları yakalamıyor; gerçek static analysis yok.'),
'P18.4':('Yetersiz race kanıtı','Chaos fixtures var; MariaDB çok süreçli race ve gerçek transport uncertainty kanıtı yok.'),
'P18.5':('Engelli','Küçük SQLite benchmark var; canonical invoice listesinde 100 satıra 101 query.'),
'P18.6':('Gerçek matris yok','Shared-host başlıklı SQLite primitive testleri var; gerçek minimum host/MariaDB kurulum matrisi yok.'),
'P18.7':('Engelli','Fixture rehearsals var; fresh schema, backup, update migration ve restore probes başarısız.'),
'P18.8':('Eksik kabul','Dictionary parity/HTML regex var; hardcoded metin, missing assets/JS, browser/visual test yok.'),
'P18.9':('Eksik ürün paketi','Sentetik packaging testleri var; gerçek executable release ZIP ve ortak updater layout yok.'),
'P18.10':('Stable RED','Dosyalarda PASS aramak independent review/süreli soak değildir; açık P0/P1 blockerlar mevcut.')}
phase_rows=[]
original=root/'review-original-plan/hosting-platform-master-plan'
for p in sorted((original/'06-phases/v1').glob('*.md')):
 phase=p.name[:3];title=p.read_text(encoding='utf-8').splitlines()[0]
 for sid,name in re.findall(r'^### (P\d+\.\d+) — (.+)$',p.read_text(encoding='utf-8'),re.M):
  evrel=f'evidence/v1/{phase}/{sid}-execution-record.md';ev=root/evrel;text=ev.read_text(encoding='utf-8') if ev.exists() else ''
  refs=sorted(set(re.findall(r'(?:src|tests|tools|\.github)/[\w./+\\-]+\.(?:php|py|css|yml)',text)))
  for cls in re.findall(r'Coleza\\(?:\w+\\)+\w+',text):
   rel='src/'+cls.removeprefix('Coleza\\').replace('\\','/')+'.php'
   if (root/rel).is_file():refs.append(rel)
  refs=sorted(set(r for r in refs if (root/r).is_file()))
  state=re.search(r'\*\*Status:\*\*\s*(\w+)',text)
  status,note=overrides.get(sid,('Bileşen/not mevcut; release kabulü açık',phase_notes[phase][2]))
  phase_rows.append({'alt_faz':sid,'ozgun_gereksinim':name,'degerlendirme':status,'not':note,'ilgili_bulgular':phase_notes[phase][1],'kaynak_test_referanslari':'; '.join(refs),'execution_record':evrel if ev.exists() else 'YOK','AI_beyani':state.group(1) if state else 'Metin mevcut; biçim farklı','release_kabulu':'Onaylanmadı'})
with (out/'ALT_FAZ_MATRISI.csv').open('w',encoding='utf-8-sig',newline='') as f:
 w=csv.DictWriter(f,fieldnames=phase_rows[0].keys());w.writeheader();w.writerows(phase_rows)
md=['# V1 — 155 özgün alt fazın kabul karşılaştırması','', 'Kaynak: kullanıcının orijinal plan ZIP’i. Bileşen varlığı ve AI beyanı üretim kabulü değildir. Kaynak/test referansları execution record’dan çıkarıldı, mevcut dosya yolları doğrulandı; bunlar tek başına işin doğru yapıldığının kanıtı değildir. Özel değerlendirme bulunmayan satırlar fazın genel bulgularını taşır; her alt fazın her davranışının tek tek çalıştırıldığı iddia edilmez. Bütün satırlarda production release kabulü açık veya engellidir. Ana rapordaki F numaraları dosya/satır ve tekrar üretim kanıtını verir.','']
for phase in phase_notes:
 md += [f'## {phase}', '',f'**Faz durumu:** {phase_notes[phase][0]}. **Bulgular:** {phase_notes[phase][1]}.','', '| Alt faz / özgün gereksinim | İnceleme | Eksik / doğrulanacak | Mevcut kayıt / dosyalar |','|---|---|---|---|']
 for r in phase_rows:
  if not r['alt_faz'].startswith(phase+'.'):continue
  refs=r['kaynak_test_referanslari'].split('; ') if r['kaynak_test_referanslari'] else []
  reftext=link(r['execution_record'],'AI execution record') if r['execution_record']!='YOK' else 'Kayıt yok'
  if refs:reftext+='<br>'+ '<br>'.join(link(x) for x in refs)
  md.append('| '+r['alt_faz']+' — '+r['ozgun_gereksinim']+' | '+r['degerlendirme']+' | '+r['not']+' | '+reftext+' |')
 md.append('')
(out/'ALT_FAZ_MATRISI.md').write_text('\n'.join(md),encoding='utf-8')
testrefs=struct['test_direct_imports']; detailed=set(file_findings)
file_rows=[]
for r in inventory:
 rel=r['path'];t=(root/rel).read_text(encoding='utf-8-sig',errors='replace');notes=[];findings=list(dict.fromkeys(file_findings.get(rel,[])))
 if rel.startswith('src/'):
  typ=re.search(r'^(?:final\s+|abstract\s+|readonly\s+)*(class|interface|enum|trait)\s+(\w+)',t,re.M)
  if typ:role={'class':'Sınıf','interface':'Sözleşme','enum':'Enum / state değerleri','trait':'Ortak davranış'}[typ.group(1)]+': '+typ.group(2)
  else:role='UI design tokens / kaynak asset'
  level='Odaklı kod incelemesi / ana rapor bağlantısı' if rel in detailed else 'Dosya içeriği otomatik statik tarandı; özel davranış kabulü verilmedi'
  if any(x['path']==rel for x in struct['portable_sql_violations']):findings+=['F02'];notes+=['SQLite SQL sözdizimi; hedef MariaDB uyumu açık.']
  if any(x['path']==rel for x in struct['unresolved_imports']):findings+=['F20'];notes+=['Mevcut olmayan Domain\\Audit\\AuditLogger import’u.']
  if rel.startswith('src/Ui/'):notes+=['Gerçek route/asset/browser deneyimi için F01/F24 bağlamı geçerlidir.']
  methods=r['methods'].split('; ') if r['methods'] else []
  operative=[x for x in methods if not re.match(r'^(get|is|has|to|jsonSerialize|__construct)',x)]
  if operative:notes+=['İşlevler: '+', '.join(operative)+'.']
  if not findings:notes+=['Bu dosya için ana raporda ayrı bug atanmadı; hatasız veya production-ready onayı değildir.']
 elif rel.startswith('tests/'):
  role='Test kodu: '+pathlib.Path(rel).stem;level='Test içeriği/metot/bağımlılık taraması; 900-test suite çalıştırmasına dahil'
  if rel in detailed:level+='; odaklı kabul incelemesi'
  testnames=re.findall(r'function\s+(test\w+)',t);notes+=['Test yöntemleri: '+', '.join(testnames)+'.']
  if 'sqlite::memory:' in t:notes+=['Bellek içi SQLite fixture; MariaDB/shared-host doğrulaması değildir.']
  if re.search(r'Mock|MemoryTransport|mockHttpClient',t):notes+=['Mock/memory collaborator; gerçek sağlayıcı kanıtı değildir.']
  notes+=['Test geçtiği, orijinal kabul akışının tamamının kapsandığı anlamına gelmez.']
 elif rel.startswith('evidence/'):
  role='AI uygulama/gate beyanı';level='Belge içeriği ve kanıt paketi envanteri karşılaştırıldı';findings+=['F28'];notes+=['Beyan özgün kabulün yerine kullanılamaz. Ayrı reviewer/changed-files eksik; P07+ canonical manifest/raw test output da eksik.']
 elif rel.startswith('tools/'):
  role='Kontrol / audit runner';level='İçerik, kontroller ve beyan sınırları tarandı'
  if rel in detailed:level='Odaklı kontrol mantığı incelemesi'
  notes+=['Kapsamı regex/dosya varlığı veya çağırdığı test suite ile sınırlıdır; script adı certification değildir.']
  if 'audit' in pathlib.Path(rel).stem or 'check_gate' in rel or 'verify_constitutions' in rel:findings+=['F28']
 elif (original/rel).exists():
  role='Özgün master-plan/kabul kaynağı';level='Orijinal ZIP ile normalize içerik karşılaştırması'
  a=(original/rel).read_text(encoding='utf-8-sig').splitlines();b=t.splitlines();notes+=['Özgün içerikle aynı.' if a==b else 'Özgün içerikten farklı; original-plan-diff.txt içinde ayrı fark mevcut.']
 else:
  role='Yapılandırma / dağıtım / proje meta dosyası';level='Dosya içeriği statik tarandı';notes+=['Ana rapor ve mevcut runtime/package sözleşmesiyle birlikte değerlendirilir.']
 file_rows.append({'dosya':rel,'rol':role,'inceleme_seviyesi':level,'satir':r['lines'],'sha256':r['sha256'],'ilgili_bulgular':' '.join(dict.fromkeys(findings)),'dogrudan_test_importlari':'; '.join(testrefs.get(rel,[])),'yontemler':r['methods'],'risk_sinyalleri':r['signals'],'degerlendirme':' '.join(notes)})
with (out/'DOSYA_DOSYA.csv').open('w',encoding='utf-8-sig',newline='') as f:
 w=csv.DictWriter(f,fieldnames=file_rows[0].keys());w.writeheader();w.writerows(file_rows)
md=['# Coleza Host — 1.152 dosyanın ayrı inceleme kaydı','','Ana karar ve kanıtlar: '+link('review/INCELEME.md','Ana rapor')+'. CSV eki tüm yöntem, hash, doğrudan test referansı ve risk sinyallerini içerir. Üçüncü taraf vendor, Git metadata, PHPUnit cache ve inceleme sırasında oluşturulan dosyalar bu 1.152 satıra dahil değildir. Yerel ZIP ana raporda ayrıca ele alındı.','', '**İnceleme düzeyi açıkça belirtilmiştir:** odaklı inceleme yapılan dosya ile yalnız otomatik statik taranan dosya aynı kabul düzeyinde değildir. Ayrı bulgu yokluğu hatasızlık onayı değildir. Doğrudan test import’u coverage ölçümü değildir; testler dolaylı da kullanabilir.','']
groups=collections.defaultdict(list)
for r in file_rows:
 rel=r['dosya'];parts=rel.split('/');group='/'.join(parts[:3]) if rel.startswith('src/Domain/') else ('/'.join(parts[:2]) if len(parts)>1 else 'Kök dosyalar');groups[group].append(r)
for group,rs in sorted(groups.items()):
 md += ['## '+group, '', '| Dosya | Rol / inceleme seviyesi | Bulgular / değerlendirme |', '|---|---|---|']
 for r in rs:md.append('| '+link(r['dosya'])+' | '+r['rol']+'<br>'+r['inceleme_seviyesi']+'<br>'+str(r['satir'])+' satır | '+(r['ilgili_bulgular'] or 'Ayrı bulgu atanmadı')+'<br>'+r['degerlendirme'].replace('|',' / ')+' |')
 md.append('')
(out/'DOSYA_DOSYA.md').write_text('\n'.join(md),encoding='utf-8')
print('file rows',len(file_rows),'subphase rows',len(phase_rows),'detailed report paths',len(detailed))
