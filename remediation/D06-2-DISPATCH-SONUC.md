# D06.2 — kalıcı dispatch ve atomik aktivasyon

16.10.2026. D06-2-KABUL.md içindeki ilk iki sabit kontrol **IMPLEMENTED_LOCAL_VERIFIED_CHECKPOINT**. **D06.2 IN_PROGRESS, 2/4**; Application actor/owner/paid-order/risk/lifecycle ve ayrı süreç/paralel/gerçek HTTPS workflow kabulü kalan iki kontroldür. D06.1 ve D02–D05 kapanışları korunur. production_ready=false, bağımsız/harici sağlayıcı kabulü PENDING.

## Düzeltilen hata

Özgün workflow işlem cevabı veya verification başarısız olunca allocation ve placement'ı boşaltıyordu. Hesap uzakta açılmış olabilir; tekrar çağrı yeni create gönderebiliyordu. Processing de active-operation sorgusuna dahil değildi. Başarı kontrolü yalnız kullanıcı adıydı; yanlış domain/plan/suspended/invalid IP kabul ediliyordu. Aktivasyonun service/placement/op/audit adımları tek transaction değildi; audit hatası sonrası active hizmet kalabiliyordu. Yeni password services.password_encrypted alanına düz metin yazılıyordu. İlk 12 negatif SQLite testi eski 079fdc9 kaynakta **12 failure, 0 error**, exit1 verdi. Before XML/stdout/exit ve üç özgün kaynak Git blob hash'i saklıdır.

## Son davranış

Workflow aynı Connection nesnesindeki service/server/capacity/operation servislerini ister; raw/managed caller transaction etkiden önce reddedilir. Trusted host domain API'sidir; authenticated Application kapısı tamamlandı sayılmaz. Service kimliği, sürümü ve önceki create kaydı yeniden kontrol edilir. Bir önceki queued/processing/retrying/reconciliation/failed/completed create kaydı pending hizmette yeni create'e izin vermez; active hizmete tekrar çağrı uzaktan create yapmaz. Kesilmiş processing otomatik timeout/replay ile yeniden sahiplenilmez.

Provider, preferred server/pool/location ve dedicated-IP bağları doğrulanır. Desteklenmeyen provider'a otomatik verified=true verilmez. cPanel package alias gerçek remote paket adına salt okunur çözümlenir. Tek kısa transaction içinde service/server kilidi → reserve → allocation commit → reserved placement → Vault'ta encrypted payload → processing claim ve dispatch audit yazılır. Başarısız hazırlıkta hiçbir create gönderilmez ve bütün yerel hazırlık geri alınır. Uzak istek bu transaction dışındadır. Commit edilmiş allocation TTL süpürücüsü tarafından boşaltılmaz; belirsiz uzak hesabın kaynakları korunur.

Payload kullanıcı/alan adı/paket/kaynaklar, yeni password, service ve server fingerprint'leri ve reservation token içerir; mevcut encrypted ProvisioningPayloadStore içinde saklanır. Parola service alanına yazılmaz veya eski password_encrypted değeri düz parola gibi gönderilmez; mevcut credential varsa explicit review gerekir. Kaynak password/key/credential bakım veya kullanıcıya güvenli teslim arayüzünün tamamı bu kontrol değildir.

Remote mutation/probe hatası veya kimlik çelişkisi processing'i atomik audit ile reconciliation_required'a alır; next_attempt_at temizlenir. Allocation ve reserved placement tutulur. İşlem kaydı/audit yazılamazsa önceki durable processing kaydı kalır; success üretilmez. Operatör, uzakta hesap yokluğu doğrulanmadan rezervasyonu serbest bırakmamalı veya yeni create kaydı üretmemelidir. Otomatik absence kararı veya keyfi force retry API'si eklenmedi.

Remote verify `getVerifiedAccountSummary` kullanır: tek kullanıcı, eşleşen domain ve canonical plan, geçerli IP, suspended0 gerekir. Son yerel transaction, güncel service/server fingerprint'ini, aynı committed allocation ve reserved placement'ı kilitli SELECT ile tekrar doğrular. MariaDB repeatable-read snapshot'ından eski satır success kanıtı yapılmaz. Placement→placed, optimistic service→active, operation→completed ve audit birlikte commit olur. Son audit failure bütün yerel aktivasyonu geri alır; capacity hâlâ committed tutulur. Yeni credential yalnız encrypted payload'dadır.

Explicit UncertainResponseReconciliationService yeni _hosting_dispatch işaretli kayıtta aynı binding/capacity/placement kontrollerini ister. Eşleşen remote postcondition ile yerel durumu atomik tamamlar; başka create göndermez. Kimlik değiştiyse bilinmeyen sonuç olarak tutar. Eski işaretsiz kayıtların önceki temkinli reconciliation sözleşmesi korunur; orijinal uzak komutun gerçekten uygulandığı ayrıca ispatlandı denmez. Bu checkpoint explicit bakımda worker'lar durdurulduktan sonraki çağrı içindir; live-worker arbitration, worker ölümü ve gerçek paralel kabul son sabit kontrolün konusudur.

## Kanıt

- Final tam ana paket **2237 test / 13649 assertion**, exit0; failure/error/skip/warning yok. 20 yeni SQLite dispatch vakası, önceki cPanel62 gerçek HTTPS ve S3/SFTP52 protocol/config vakaları dahildir.
- Final izole gerçek MariaDB10.11 **64 test / 504 assertion**, dört affected sınıf; exit0, hata/atlama yok. Yeni20 native vaka dahildir. Discovery ve çalışan vaka ID'leri eşit; bütün native suite değildir.
- Yeni testler: doğru aktive etme/tekrar çağrı; domain/plan/suspended/IP/type çelişkileri; kayıp remote yanıtı; processing hold; reentrant ikinci worker çağrısı; raw caller transaction; encrypted-only password; geç audit rollback; service/server binding drift; TTL sweeper; explicit matching reconciliation; mixed Connection reddi. Reentrant callback ayrı paralel süreç veya gerçek HTTP failure kabulü gibi sayılmadı.
- Eski pozitif golden fixture'lara domain/suspended gibi zorunlu gerçek hesap alanları eklendi. Remote/verification/conflict durumunda release bekleyen eski testler artık committed allocation/reserved placement/held operation doğrular. Başarısız create/probe success'e çevrilmedi; testler silinmedi veya skip yapılmadı.
- Final testten önce45 kaynak/test/workflow input'u sabitlendi. Canonical26 migration byte olarak aynı; yeni şema eklenmedi. Plan39/258, locked97 ve whitespace kontrolü geçti. Provider CI her iki OS'te golden/probe testlerini de koşar, native grubuna HostingDispatchTest eklenir. Önceki backup/update CI işleri korunur.

Kalan sabit iki kontrol: **Application actor/owner/paid invoice/risk + create/suspend/unsuspend/terminate komut bağları** ve **ayrı süreç/parallel/crash/gerçek HTTPS hosting workflow kabulü**. D06.2 kapanmadı. D06.3 otomasyon, D06.4 diğer entegrasyonlar, D06.5 Doctor ayrıca plandaki yerlerinde durur. Üretim deployment/remote mutation yapılmadı; yerel fixtures harici cPanel hesabının kabulü değildir.

Kanıt dosyaları d06-dispatch-baseline/before/main/native/discovery/checks/summary/hashes; denetim `python -X utf8 remediation/verify-d06-dispatch.py`, bu checkpoint'in byte sürümü içindir.
