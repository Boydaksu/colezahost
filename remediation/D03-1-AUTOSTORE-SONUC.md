# D03.1 — otomasyon işlem verilerinin Vault’ta saklanması

Referans `9895f730a612e5c7a13613314ac4d7423832e8a4`. Orijinal master Security Constitution’ın secrets/Vault ve immutable audit sınırlarıyla, onay/erteleme/recovery davranışının birlikte korunması hedeflendi. D02 kapalı; D03.1 IN_PROGRESS; independent_acceptance=PENDING; production_ready=false. Bu paket provisioning sonrası automation carrier kapsamını tamamlar; domain registrar/EPP saklama ayrıca açık.

## Son davranış

AutomationSecretStore, `automation_pending` (approval/delay), `automation_run_history` ve `automation_rule_versions` JSON’unun tamamını ortak `automation` namespace Vault’ta AES-GCM şifreli saklar. Owner tablosundaki payload yalnız `vault:v1:` referansıdır. Beklenen key tablo+ID/kind veya rule_id/version kapsamına SHA256 ile bağlanır; başka row/kind/version referansı reddedilir. Payload içindeki ID/status ve history/rule-version identity de owner metadata’sıyla doğrulanır. JSON nested/unlabelled sırlar dahil bütün hâliyle saklanır; operational redaction yapılmaz. AutomationCodec scalar/array validation ve deterministik JSON fingerprint davranışını korur; sensitive input trace parameter annotation eklendi.

Pending add/mutate, history save ve rule version insert ile Vault/write/access audit aynı Connection transaction/savepoint’e bağlıdır. Geç owner insert/update veya audit failure secret değişimini de geri alır; yeni rule version nesneye ancak transaction başarıyla bittikten sonra uygulanır. Runtime legacy plaintext fallback yoktur.

Pending find/list, owner ve değişebilir Vault payload’ını aynı transaction ve owner lock kapsamında okur; önce bound application key probe alınır. Böylece bir çalışanın PENDING owner ile başka çalışanın APPROVED secret snapshot’ını karıştırması önlenir. Approval→delay coordinator ve delay reverse-parent lookup aynı encryptor’ı bütün alt bağımlılıklara taşır. Factory kurulu owner/Vault/security-audit şemalarını (InnoDB dahil) ve geçerli persistent key’i handler çağrısından önce doğrular. CoreServiceProvider container’ın Encryptor bağımlılığını factory’ye iletir. Varsayılan yol APP_KEY/APP_KEY_FILE’dır; test key’i yalnız explicit test dependency olarak verilir.

Recovery inspector decrypt sırasında audit veya veri yazmaz. Pending/history/version okumaları aynı read transaction içindedir; caller transaction/savepoint sahipliği korunur. Consistency veritabanının transaction isolation sözleşmesine bağlıdır (yerel MariaDB default repeatable read ve SQLite test edildi). Read-only path key binding ve owner reference/cipher validation’ı atlamaz. Inspector’ın diagnostic/admin response alanlarının kapsamlı secret-output kabulü ayrı kalan işe dahildir.

KeyLifecycleService bind/verify/rotation inventory üç automation owner tablosunu da kapsar, reference + authenticated plaintext JSON identity doğrular; mevcut Vault rotation bütün ciphertext’leri yeniler. Restore key gate automation records varsa deep verification ister. Bu, tam backup/dump/file recovery kabulü değildir.

## Kurulum ve eski veri geçişi

26 baseline migration dosyası byte olarak değişmedi. AutomationSchema/RuleVersion schema installation mevcut migration sırasını korur; Vault yaratımı otomasyon migration’ına eklenmedi. Full baseline zaten ortak Vault/audit tablosunu kurar. Standalone test fixture’ları ortak Vault/audit’i ayrıca kurar. Runtime getter veya factory DDL çalıştırmaz; eksik/partial/nontransactional şemada işlem reddedilir.

Eski düz JSON verisi bulunan kurulumda, writers durdurulup kayıtların güvenilir eski uygulama verisi olduğu doğrulandıktan sonra, doğru APP_KEY/APP_KEY_FILE ve kurulu owner/Vault/audit şemasıyla:

```text
php tools/automation-secret-maintenance.php --maintenance --confirmed-legacy-json
```

Bakım kendi transaction’ını açar; caller transaction içinde veya provenance onayı olmadan çalışmaz. Bütün eski JSON ve identity önceden doğrulanır; malformed JSON, yanlış ref/key, owner identity mismatch ve conflicting Vault owner reddedilir. Vault + owner refs + audit tek transaction içindedir. İkinci geçiş `upgraded_records=0` döndürür; doğrulanan mevcut secret erişimleri audit oluşturabilir. Plaintext kayıt otomatik olarak runtime’da taşınmaz. CLI yalnız count basar, hata stderr generic’tir. Bu cihazdaki/production’daki gerçek uygulama verisinde CLI çalıştırılmadı; GitHub push deployment değildir. Bakım sonrası application-key verify ve yeniden başlatma doğrulaması gerekir.

## Geliştirmede yakalananlar

Before XML/TXT yeni pending plaintext regresyonunun 1 test/1 failure/error0 sonucunu içerir. İlk named-argument denemesi mevcut API’nin yeni parametresi henüz bulunmadığı için fixture error verdi; positional explicit dependency ile gerçek plaintext failure gösterildi. Son testler bu eski API varsayımına dayanmaz.

Erken ana preflight, Vault schema creation’ını automation migration’ına bağlayan tasarımın SQLite transactional migration sırasını bozduğunu gösterdi (284 error/6 failure). Bu tasarım kaldırıldı; production migration dosyaları değiştirilmedi, standalone fixture schema setup ayrıldı. Existing corrupt/stale/duplicate receipt testleri AutomationStorageFixture üzerinden bilerek şifreli içeriği değiştirir; runtime plaintext fallback eklenmez ve semantic rejection assertion’ları korunur. Otomatik fixture düzenlemesinin iki unrelated table-variable insert’e dokunduğu geliştirmede fark edildi ve o değişiklikler geri alındı.

MariaDB hazırlık koşuları approval handoff dört-worker testlerinde owner/Vault okuma yarışını yakaladı (2 error ve sonra 1 error). Worker hata protokolü sabit sınıf/sqlstate/code ve maskeli reason döndürecek şekilde düzeltildi; iki test artık beklenmedik worker error olmadığını açıkça doğrular. Pending find/list atomic read düzeltmesinden sonra bu iki test 2/32 geçti. Eski/ara kaynak koşuları final yerine sayılmadı; runtime altında tutulur. Source freeze son düzeltmelerden sonra tekrar alındı.

## Son kanıt

Ana suite **1663 test / 11542 assertion**; affected MariaDB 10.11 **182 test / 1517 assertion**; fail/error/skip0. Native dokuz exact sınıf: ApprovalDelay, AutomationActionJournal, AutomationLifecycleBoundary, AutomationPersistence, AutomationRecoveryIntegrity, AutomationSecret, AutomationVersion, BlastRadius, KeyLifecycle. Full native suite yeniden koşulmadı; son historical full native D02 kanıtı kendi commitinde korunur.

11 yeni ortak secret-storage case (data sets dahil): pending nested/unlabelled/action secret at-rest ve restart/float round-trip; history/rule snapshot at-rest+exact restart; read-only recovery hiçbir row değiştirmeme; yanlış key read/mutation etkisizliği; owner splice reddi; geç pending insert/update rollback; raw/managed outer rollback; explicit legacy admission/conflict/malformed JSON/ikinci geçiş; history/version geç owner failure ve nesne version değişmeme; configured key olmadan factory öncesi etkisizlik. KeyLifecycle’e eklenen ortak test gerçek protected replacement key dosyasıyla üç automation carrier’ın rotation/restart’ını, eski writer reddini ve owner splice’ın deep verify’ı durdurmasını doğrular. Mevcut native dört-worker approval/delay claim ve rule-version publishing, old-snapshot committed-state, duplicate/forged/stale recovery ve blast-radius testleri korunur.

Final XML/TXT, exact native discovery, source-freeze/hashes ve 26 migration digest’i `verify-d03-automation-storage.py` ile doğrulanır. Security/architecture/dependency scan PASS: 1040 PHP dosyası, altı signature pattern ve üç forbidden-function rule. Bu DLP veya tüm ürün production acceptance kanıtı değildir. Tarihsel verifiers kendi Git commitlerinde geçerlidir.

## Kalan sabit kapsam

D03.1 genel denetiminin üç alt işi devam eder: operational carriers içinde **domain registrar operation JSON ve domain EPP storage**; independent privacy signing keys/legacy integrity; comprehensive sink/legacy export/response acceptance. Provisioning ve automation carrier yerel onarımları tamamlandı. Actor/step-up/RBAC D03.2–D03.4, bütün HTTP composition D08, tam restore D05 ve bağımsız kabul D09’da kalır. Yeni D02 alt fazı açılmadı.
