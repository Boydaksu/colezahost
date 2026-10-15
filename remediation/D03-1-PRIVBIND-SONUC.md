# D03.1 — kalıcı privacy key binding, writer fence ve restore/lifecycle doğrulaması

15.10.2026, referans `4ec72349a58dbfa53d54cd6811b5f42dd6630f10`. Orijinal master Security/Data Constitution ve G08 hedefleri esas alındı. D02 kapalı; D03.1 açık; independent_acceptance=PENDING; production_ready=false. Privacy signing, legacy evidence ve bu binding/verifier paketi yerel olarak uygulandı. D03.1’in kalan tek genel işi comprehensive sink/legacy export/response acceptance’tır. Bu paket bütün ürünün production kabulü değildir.

## Değişiklik ve kapsam

PrivacyKeyBinding her purpose için yalnız public key ID ve purpose-separated HMAC probe tutar. Ham anahtar DB’ye yazılmaz. `privacy_key_bindings` purpose PK, tam kolonlar, InnoDB ve başka unique identity olmaması kontrol edilir. NOP upsert mevcut probe’ı değiştirmez; güncel locking read yanlış/önbellekte kalmış key’i reddeder. Upsert’in önce yapılması absent-purpose gap lock yarışını azaltır. Purpose sırası sabittir; bütün bağlı purpose ID’leri kontrol edilerek aynı key’in tombstone/redaction/email rollerinde kullanılması reddedilir. InnoDB deadlock/SQL hatası normal rollback yoluyla dışarı çıkar; bütün paralel caller’ların mutlaka başarılı olacağı veya retry kuyruğu garantisi verilmez.

İlk binding, mevcut ilgili kayıtların HMAC v2 veya independently approved legacy evidence ile doğrulanmasından sonra commit edebilir. Tombstone/redaction writer, DB↔out-of-band sync ve bakım onayı mevcut DB purpose binding’ini kullanır. Binding + yeni owner kayıtları + binding security audit aynı transaction/savepoint’e alınır. Actor’a ait opsiyonel audit yazımı da owner transaction’ındadır. Privacy sınıflarındaki mevcut olmayan Domain\Audit logger tipi canonical Identity\Audit tipine düzeltildi. Legacy consent API’sinin unsigned deny marker’ı güçlü imza veya key binding kanıtı sayılmaz; böyle bir marker doğrulanmamış kalır ve authoritative verification’ı durdurur.

Gizlilik kaydını yazan DB owner tabloları da InnoDB/full schema açısından doğrulanır. Wrong key preflight erasure etkilerinden önce yapılır; asıl guard transaction içinde güncel probe’ı yeniden kilitler. Raw/managed parent transaction commit edilmez. File/proof yazımı DB rollback ile geri alınmış sayılmaz; cross-resource crash recovery D05 kapsamındadır. Anahtarlar servis/tek verification envanteri içinde tekrar çözülmez; DB binding lock yine her writer transaction’ında güncel okunur. Verifier her yeni verify çağrısında protected key dosyalarını yeniden kontrol eder.

PrivacyIntegrityVerifier salt okunurdur: schema/binding/audit/file yazmaz. Mevcut purpose probe’larını, DB/file tombstone envanterini, redaction audit MAC’lerini ve bütün configured legacy proofs’ı kontrol eder. Orijinal kaydı kayıp proof sessizce yeterli kabul edilmez; orijinal envanterin kurtarılması istenir. Eski unbound kurulumda kayıt MAC/evidence anahtarla doğrulanabilir; sonuç bound_purposes’ı açıkça bildirir. İlk writer veya explicit bind, doğrulanmış envanter üzerinde binding oluşturur. Read-only verify tek başına bakım bind’i yerine geçmez.

Canonical installer yeni binding/audit bağımlılığını historical migration runner başlamadan hazırlar. Böylece migration içindeki schema helper’larına yeni MySQL implicit-commit DDL yüklenmez; 26 baseline migration ve sırası byte olarak değişmez. Runtime writer da caller transaction başlamadan eksik bağımlılığı hazırlayabilir; eksik schema bir caller transaction içinde hazırlanmaz, hata verir. Eksik/uyumsuz mevcut schema explicit upgrade gerektirir.

Application KeyLifecycle initialize/verify/rotate mevcut privacy envanterini de doğrulamadan tamamlanmaz. APP_KEY değişimi privacy anahtarlarını, imzalı kayıtları ve binding probe’larını değiştirmez. Gizlilik anahtarları kalıcı ve bağımsızdır; bu paket privacy-purpose key rotation veya otomatik re-seal prosedürü eklemez. Yeni bir privacy dosyasını mevcut dosya yerine koymak rotation sayılmaz ve binding’i geçmez.

RestoreWizard SQL importundan sonra, preserved file copy ve success öncesinde aynı bağımsız privacy kontrolünü hedef DB üzerinde çalıştırır. Privacy-only restore için APP_KEY zorunlu kılınmaz. Inject edilmiş verifier/reconciler gerçek restore hedefine yeniden bağlanır. Doğrulanmış tombstone varsa opsiyonel reconciler verilmemesi başarıdan önce scrub kontrolünü atlatmaz. G08 artık kısa süreli PII resurrection sonrası success beklemez; success’ten önce scrub edilmiş durumu ve tekrar reconciliation’ın idempotence’ını ölçer. SQL import rollback-atomic değildir; gate başarısız olduğunda import edilmiş statements geri alınmış gösterilmez. Reconciliation sonrası tüm dosya/DB kesinti atomikliği D05’te açıktır.

Geçerli imzalı privacy JSON’un SQLite yedek/restore sırasında addslashes ile bozulduğu development testte görüldü. SQLite dumper artık aynı PDO driver’ın quote() fonksiyonunu kullanır. Pozitif restore testi JSON/float metadata, apostrophe, backslash, double quote ve inline semicolon içeren reason için orijinal privacy satırının exact korunmasını ölçer. Bu düzeltme MariaDB dump desteği veya line-based SQL parser’ın tüm edge case kabulü değildir.

## Bakım ve yapılandırma

Mevcut üç protected file (`PRIVACY_TOMBSTONE_KEY_FILE`, `PRIVACY_REDACTION_KEY_FILE`, `PRIVACY_EMAIL_HASH_KEY_FILE`) ve gerekiyorsa `PRIVACY_LEGACY_EVIDENCE_FILE` korunur. Default recovery/lifecycle için out-of-band tombstone yolu `PRIVACY_TOMBSTONE_STORE_FILE` ile verilebilir; configured kayıp dosya verification’ı durdurur. Dependency injection ile verilmiş store/evidence de aynı kontrole katılır. DB backup’tan eski tombstone’ların geri uygulanabilmesi için orijinal dosya ve proof envanteri DB dışında saklanmalıdır.

Canonical schema hazırlığı ve legacy approval tamamlandıktan sonra writers durmuşken:

```text
php tools/privacy-key-maintenance.php bind --maintenance
php tools/privacy-key-maintenance.php verify
```

bind mevcut probe’ı değiştirmez; üç purpose’ın verified kurulumda bağlanmasını idempotent tamamlar. verify DDL/audit/data yazmaz, yalnız counts/key IDs/bound purposes döndürür. Eksik protected key üretmez. CLI HTTP kullanımını reddeder ve key/record içeriklerini yazdırmaz. Production verisi üzerinde bu CLI çalıştırılmadı; GitHub push deployment değildir.

## Test kanıtı

Before XML/TXT 2 test/2 failure/error0: yanlış güçlü key eski user/audit kaydını okuyamasa da başka user için yeni kayıt yazabiliyordu. Development broad integration 56/429; acceptance 16/101; son affected pre-freeze 38/218 ve ilk native binding 14/104 geçti. Ara koşular final yerine sayılmaz. Kaynak final koşulardan önce freeze edildi.

Final ana suite **1717 test / 11831 assertion**; affected MariaDB 10.11 **82 test / 607 assertion**, fail/error/skip0. Exact altı native sınıf: PrivacyBinding, PrivacySigning, PrivacyLegacy, PrivacyMigrationInstall, KeyLifecycle, SqlPortabilityCloseout. Full native bu kaynakta yeniden koşulmadı; historical full D02 sonucu kendi commitinde tutulur.

12 ortak binding case: yanlış tombstone/redaction/email key; cross-purpose key reuse; SELECT/PRAGMA-only verification ve no-key-material output; empty DB no DDL; tampered probe; first binding’in önceden var olan kaydı doğrulaması; owner failure ile probe+audit rollback; late canonical security audit failure ile owner rollback; raw/managed parent rollback; cached erasure pre-effect rejection; explicit bind admission/idempotence/parent rejection. İki native ek case: ayrı connection signed probe değişimi ve eski parent snapshot’ta current locking fence; MyISAM owner’da binding/data yazmadan ret. Probe değişimi testte simüle edilir, privacy-key rotation prosedürü uygulanmış sayılmaz.

Shared KeyLifecycle ek case gerçek protected replacement file ile APP_KEY rotate/restart verification, privacy row/probe byte preservation, bağımsız privacy writer’ın çalışmaya devam etmesi ve bozuk privacy audit’in verify/yeni rotation’ı ciphertext/probe/version/audit değişmeden durdurmasını ölçer. İki yeni backup case yanlış privacy key’de dosya copy/success olmaması ve import edilen DB’nin görünür kalması; başka source DB’ye bağlı reconciler’ın doğru target’ı scrub etmesi, source kullanıcılarının değişmemesi ve quoted privacy JSON exact round-trip’tir. Eski install/reinstall/legacy preservation, key lifecycle ve G08/release rehearsal testleri korunur.

Security/architecture/dependency scan PASS: 1062 PHP file, altı signature pattern, üç forbidden-function rule. Bağımsız security veya bütün secret/egress kabulü değildir. `verify-d03-privacy-binding.py` final XML, exact six-class discovery, source/hash metadata, remaining-scope state ve 26 migration digest’ini doğrular.

## Kalan iş

D03.1’de yalnız comprehensive sink/legacy export/response acceptance kaldı: serbest metadata, diğer consumer/queue/staging yolları, privacy scan’in hata yutma/compliance davranışı ve tüm dışa çıkışların kabulü bu başlıkta gözden geçirilecek. D03.2–D03.4 authorization/step-up, D05 whole recovery/parser/path/file durability, D06 gerçek provider lifecycle, D08 HTTP composition ve D09 bağımsız kabul açıktır. Bu paket aynı kapsamı tekrar açmaz; privacy signing/legacy/binding implementation yerel olarak tamamlandı.
