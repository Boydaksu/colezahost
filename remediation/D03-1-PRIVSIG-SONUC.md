# D03.1 — yeni gizlilik kayıtlarında bağımsız anahtarlar ve bütün alanların imzalanması

15.10.2026, referans `56ac2c15f65c7f4cbe622bce895f62ecc68b5838`. Orijinal master ZIP içindeki Data Constitution (backup sonrası tombstone uygulama), Security Constitution (immutable audit, secret koruma) ve Golden Scenarios G08 esas alındı. D02 kapalı; D03.1 açık; independent_acceptance=PENDING; production_ready=false. Bu paket yeni kayıtların imzalanmasını ve doğrulanmamış verinin yetkili silme kanıtı olarak kullanılmasını ele alır. Eski kayıt aktarımı ve anahtar yaşam döngüsü tamamlanmış sayılmaz.

## Değişiklik

PrivacyTombstone ve RedactionAuditRecord HMAC v2 kullanır. Amaç ve sürüm canonical JSON içinde imzalanır; associative key sırası normalleştirilir, list sırası ve float türü korunur. Tombstone user ID/email hash/type/reason/checksum/metadata/time; audit user ID/domain/action/count/fields/pre-post checksum/clean flag/time/actor bütünüyle korunur. Veri tabanı otomatik ID’si tombstone restore sırasında değişebildiği için semantik imza kapsamı dışındadır. `hmac:v2:` ve base64url digest 51 byte; mevcut VARCHAR(64) ve 26 migration değişmedi.

Üç amaç için ayrı, kalıcı, korumalı dosya kullanılır: `PRIVACY_TOMBSTONE_KEY_FILE`, `PRIVACY_REDACTION_KEY_FILE`, `PRIVACY_EMAIL_HASH_KEY_FILE`. Runtime dosya/anahtar üretmez ve sabit zayıf default’a düşmez. PrivateKeyFile mevcut CSPRNG32, exclusive-create, flush ve OS permissions kurallarını uygular; bu sınıf ve reviewed process hash’i değiştirilmedi. Kopyalanmış application-key file içeriği veya iki privacy-purpose file aynı anahtarı taşıyorsa reddedilir. İç servis/test injection’ında güçlü raw32/base64 anahtar kullanılabilir; test fixture’ları amaç bazında farklı ve sentetiktir.

Silme/audit kaydı oluşturma anahtarı şema ve yeni kayıt etkisinden önce doğrular. PrivacyErasureService de bağlı signing servislerini silme etkisinden önce kontrol eder. Tombstone metadata’daki imzalı public email-key ID, eski DB restore sonrası yanlış email anahtarıyla sessiz eşleştirme kaybını engeller. Metadata alanındaki çatışan reserved key ID reddedilir. Mevcut kullanıcı kaydı geri döndürülmeden imzası doğrulanır; DB ve out-of-band kayıt çatışması sessizce tercih edilmez.

FileTombstoneStore bütün giriş kayıtlarının imzasını doğrular. Eksik dosya boş envanterdir; mevcut boş/bozuk JSON, yanlış üst format, tekrarlı user ID, geçersiz imza ve çatışan replacement reddedilir. Manifest version/count/checksum ve bütün record MAC’leri yazma öncesi doğrulanır; saldırganın checksum’u yeniden hesaplaması yeterli değildir. Aynı file lock read/merge/write boyunca tutulur; yazma, truncate, flush/fsync kontrol edilir. Bu implementation kilitli yazmadır; crash-atomic temp-file replacement, path/permission hardening ve DB/file ortak recovery transaction’ı garanti edilmez (D05).

getAllTombstones ve iki sync yönü bütün mevcut envanteri yazma/scrub öncesi doğrular. G08 restore tüketicisi bu kontrol üzerinden geçer. Raw tek kayıt getters inspection amaçlı unverified legacy satırı döndürebilir; isTombstoned eski consent marker’ını deny amaçlı varlık olarak korur. Varlık, imza kanıtı değildir. Legacy default-key/unsigned kayıt doğrulanmış sayılmaz ve orijinal satırı otomatik yeniden imzalanmaz. Böyle bir envanter authoritative reconciliation’ı durdurur; production’a açma kabulü değildir.

## Yapılandırma

Sunulan uygulama dizini dışında özel bir dizinde üç farklı dosya bir kez oluşturulur. Var olan dosya üzerine yazılmaz; araç gerçek anahtarı çıktılamaz:

```text
php tools/provision-privacy-key.php tombstone <absolute-private-directory/tombstone.key>
php tools/provision-privacy-key.php redaction <absolute-private-directory/redaction.key>
php tools/provision-privacy-key.php email <absolute-private-directory/email.key>
PRIVACY_TOMBSTONE_KEY_FILE=<absolute-private-directory/tombstone.key>
PRIVACY_REDACTION_KEY_FILE=<absolute-private-directory/redaction.key>
PRIVACY_EMAIL_HASH_KEY_FILE=<absolute-private-directory/email.key>
```

Dosyalar DB backup’ın dışında korunur ve restore/application-key değişimi boyunca saklanır. Bu adımda production anahtarı veya production legacy verisi işlenmedi; GitHub push deployment değildir. Privacy anahtarını değiştirmek henüz desteklenen rotation prosedürü değildir.

## Test kanıtı

Before XML/TXT: iki test/iki failure/error0; önceki imzanın reason/type/metadata ve clean flag/actor/fields değişimini kabul ettiği gösterildi. İlk affected development 45/304; genişletilmiş ortak cases 11/62; gerçek protected-key file tests 2/7 geçti. Eksik anahtarda servis/silme işleminin tablo dahi oluşturmaması daha sonra eklendi ve final main’de doğrulandı. Final öncesi source freeze alınmıştır.

Final ana suite **1690 test / 11670 assertion**, affected MariaDB 10.11 **31 test / 164 assertion**, fail/error/skip0. Native exact iki sınıf: PrivacySigning, PrivacyMigrationInstall; full native yeniden çalıştırılmadı. Shared 11 cases: bütün semantik alan tamper, purpose separation, wrong key, canonical order, legacy default-key forgery rejection, weak/same-purpose key before DDL, float/DB round-trip, wrong email key before user restore changes, existing tampered row rejection, manifest/invalid file immutable rejection, whole-inventory-before-sync, DB/file conflict. Existing installer rollback/reinstall/legacy preservation ve G08/golden/release rehearsal ana testleri korunur. Protected file tests process APP_KEY değişiminden bağımsız doğrulama ve copied-file reuse reddini ölçer; gerçek application-key rotation içinde privacy inventory integration ölçülmüş sayılmaz. File concurrency için yeni paralel stress acceptance eklenmedi.

Security/architecture/dependency scan PASS (1051 PHP dosyası, altı secret signature pattern, üç forbidden-function rule). Bu sınırlı statik kontrol bağımsız security kabulü değildir. `verify-d03-privacy-signatures.py` final XML, exact native discovery, hashes ve 26 migration digest’ini doğrular.

## Kalan kapsam

D03.1 genel iki iş başlığı korunur. Independent privacy signing/legacy integrity başlığının **yeni kayıt imzası** bölümü tamamlandı; kalan somut kapsam: bağımsız provenance ile eski DB/file kayıtlarını orijinal baytları değiştirmeden onaylama, persistent key binding ve eski writer fence, privacy inventory’nin read-only recovery/application-key lifecycle doğrulamasına bağlanması. İkinci başlık comprehensive sink/legacy export/response acceptance; serbest metadata/consumer yolları ve scanForUnredactedPii hata yutma/eksik tablo compliance sonucu burada açıkça incelenecek. D03.2–D03.4 yetki/step-up işleri başlamadı. DB/file interrupted durability ve tam restore D05; bağımsız production kabulü D09’da açık.
