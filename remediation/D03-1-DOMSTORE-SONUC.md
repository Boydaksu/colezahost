# D03.1 — domain EPP ve registrar işlem verilerinin Vault’ta saklanması

Referans `65a5601d4048314cc633764611050ba1bb9fc935`. Orijinal master Security Constitution’ın sır saklama/Vault ve sırları diagnostic logdan çıkarma sınırları esas alındı. D02 kapalı; D03.1 IN_PROGRESS; independent_acceptance=PENDING; production_ready=false. Matrisin bilinen provisioning, automation ve domain/EPP carrier yolları bu paketle yerel olarak uygulandı. Bütün serbest metadata alanlarının veya bütün ürünün production kabulü değildir.

## Değişiklik

DomainSecretStore `domains.epp_code` ve `domain_registrar_operations.payload_json/result_json` içeriğini ortak `domain` namespace Vault’ta AES-GCM ile şifreli saklar. Owner alanında yalnız `vault:v1:` ref bulunur. EPP key’i domain ID+column kapsamına; operasyon key’i operation ID/domain ID/type/idempotency key/column kapsamına SHA256 ile bağlanır. Başka domain/operation/column referansı runtime ve deep verify sırasında reddedilir. Bu physical entity binding’dir; kullanıcı/organizasyon authorization policy D03.2’de ayrıca ele alınır.

Operational JSON tüm hâliyle şifrelenir, secret alan adı tahmini yapılmaz. JSON_THROW_ON_ERROR/PRESERVE_ZERO_FRACTION kullanılır. getEppCode()/operation payload/result API’leri transfer ve execution için original veriyi döndürür; operational redaction yoktur. EPP ref 73 byte olduğu için mevcut VARCHAR(255) alanında kalır; Vault uzun kodu tutabilir. EPP retrieval/provider DTO/admin response yetki ve output acceptance ayrı açıktır.

EPP setter/update, Vault/write/access audit, domain owner update ve setter timeline tek transaction/savepoint içinde çalışır. Metadata-only update stored ref/cipher’ı korur; EPP açıkça null verilirse Vault secret silinir ve owner null olur. Registrar operation create önce owner ID’yi aynı transaction içinde alır, JSON refs bağlar ve transaction bitmeden reload eder. Sonuç/status/retry/reconciliation metadata değişimleri Vault/result ve security audit ile birlikte atomiktir. Geç timeline/owner failure tüm local değişimi geri alır. Raw/managed caller transaction sahipliği korunur.

Mutable owner ve Vault ciphertext aynı read transaction ve owner lock ile okunur; bound key probe owner lock’tan önce alınır. Domain list/getter ve operation ID/idempotency/uncertain list yolları bu kurala bağlıdır. Bound database’de eski key writer reddedilir. Registrar operation için installed columns, Vault/audit dependency ve InnoDB preflight; first-use DDL caller transaction içinde yoktur. ensureTable standalone module bootstrap için kullanılabilir. DomainService.ensureTables yönteminin kurulum davranışı korundu; 26 baseline migration dosyası byte olarak değişmedi; production install sırası değiştirilmedi.

Domain timeline write ve legacy read view payload/description redacted; eski DB audit bytes yeniden yazılmaz. Operation create/failure/uncertain/reconciled error diagnostics known payload/result context ile scrub edilir, legacy getter safe view verir. Arbitrary unlabeled exception text için DLP veya tüm HTTP sink kabulü sayılmaz.

KeyLifecycle bind/verify/rotation inventory EPP ve iki operation field’ı kapsar; owner refs, Vault presence/authenticated ciphertext ve operation JSON shape doğrulanır. Mevcut master-key rotation üç cipher’ı da yeniden sarar. Restore requiresVerification gate EPP/operation secret record presence’ını da görür. Tam dump/file recovery D05’te açık kalır.

## Eski veri geçişi

Runtime plaintext fallback kaldırıldı. Güvenilir eski veri provenance’ı teyit edilip writers durdurulduktan sonra, kurulu canonical owner/Vault/audit storage ve doğru APP_KEY/APP_KEY_FILE ile:

```text
php tools/domain-secret-maintenance.php --maintenance --confirmed-legacy-data
```

Bakım explicit onay ve kendi durable transaction’ını ister; caller transaction içinde çalışmaz, şema oluşturmaz. Bütün eski EPP/JSON/ref kayıtları önce doğrulanır. Malformed JSON, wrong reference/key ve existing conflicting Vault owner geçişi reddeder. Secret + owner ref + audit aynı transaction içindedir. İkinci geçiş `upgraded_fields=0` döndürür; existing secret verification access audit oluşturabilir. Geçiş sonrası application-key verify ve yeniden başlatma kontrolü uygulanmalıdır. Gerçek production verisinde CLI çalıştırılmadı; GitHub push deployment değildir.

## Test kanıtı

Before XML/TXT 1 test/1 failure/error0: EPP ile input/result sırlarının owner tablolarında plaintext olması gösterildi. İlk development domain suite 53/446; genişletilmiş ortak secret tests 13/37; ilk affected native domain 15/93 geçti. Son native’e ayrı connection/old-snapshot case eklendi. Ara koşular final yerine sayılmadı. Ana suite aynı değişmemiş implementation/Unit source ile başarılı olduktan sonra final kanıta taşındı; son ek native-only test final native’de çalıştı. Source freeze/hashes ve 26 migration digest’i doğrulanır.

Son ana suite **1677 test / 11595 assertion**. Son affected MariaDB 10.11 **69 test / 539 assertion**, fail/error/skip0. Beş exact native sınıf: DomainSecret, KeyLifecycle, SqlPortabilityCloseout, LegacyAdoptionUpgrade, AdoptionPersistence. Full native suite bu kaynakta yeniden koşulmadı; historical full D02 sonucu kendi commitinde kalır.

13 ortak domain secret case (data sets dahil): EPP/input/result plaintext-free owner rows ve restart/float exact round-trip; uzun EPP storage, metadata-only ref/cipher/version preservation ve explicit null delete; EPP/operation owner splice rejection; late timeline/operation-owner failure rollback; raw/managed parent rollback; wrong key read/write etkisizliği; runtime legacy rejection, provenance/malformed batch atomicity/idempotent upgrade; existing Vault owner conflict rejection; cached successful callback tek kez çalışması; timeline safe write/legacy view ve immutable historical bytes; missing configured key before EPP/operation effects.

Üç ek MariaDB case: dört concurrent duplicate creator için tek owner ve orphan secret olmaması; dört writer/readers ile mutable owner/cipher coherence ve 12 EPP version; ayrı connection commit’i öncesinden başlayan parent snapshot’ta current EPP/result read ve caller transaction preservation. KeyLifecycle ortak ek testinde gerçek protected replacement file ile EPP/input/result rotation/restart, retired-key writer fence ve spliced operation owner deep-verify rejection ölçülür. Mevcut transfer/cache/timeout/reconciliation/golden/chaos domain tests ana suite’te korunur.

Security/architecture/dependency scan PASS: 1044 PHP dosyası, altı signature pattern ve üç forbidden-function rule. DLP veya bağımsız production security acceptance değildir. `verify-d03-domain-storage.py` final XML, exact five-class native discovery, source/hash metadata ve migration bytes’i doğrular. Tarihsel package verifiers kendi commitlerinde geçerlidir.

## Kalan kapsam

Matrisin inventoried operational carrier işi yerel olarak tamamlandı. D03.1’in **iki** genel alt işi kaldı: independent privacy signing keys/legacy integrity; comprehensive sink/legacy export/response acceptance. Bu son audit diğer serbest metadata, queue/staging/consumer ve bilinmeyen secret yollarını da denetler. Actor/step-up/RBAC D03.2–D03.4; gerçek registrar failed-retry claim/remote-effect lifecycle ve provider command binding D06; HTTP composition D08; full recovery D05 ve independent acceptance D09’da açık. Bu paket failed-retry CAS/remote command exactly-once garantisi eklemez. Yeni D02 alt fazı açılmadı.
