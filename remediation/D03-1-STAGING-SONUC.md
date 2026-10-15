# D03.1 Q2 — şifreli migration staging ve yerel uygulama kapanışı

15.10.2026; başlangıç commit’i `051513d74b1a223e125cda8fd4366e93a577f9de`. Birincil kaynak kullanıcının orijinal hosting-platform-master-plan.zip dosyasıdır. İçindeki Security/Data Constitution dosyaları extracted referansla byte olarak karşılaştırıldı; archive/document SHA256 değerleri baseline JSON’da saklanır. D02 kapalı; Q2 ve D03.1 yerel uygulaması tamamlandı. Sonraki adım D03.2. independent_acceptance=PENDING; production_ready=false.

## Bulgular ve uygulama

Before kanıtı 2 test/2 failure/error0: raw_payload_json ve canonical_payload_json açık sırları tutuyordu; validation/quarantine alanları kaynak parolasını tekrar yazabiliyordu. Eski json_encode ayrıca 1.0 değerini 1’e düşürüyordu. Yeni StagingSecretStore raw/canonical JSON’un tamamını fiziksel Vault’a yazar; owner satırında yalnız referans vardır. Scope ID/batch/source system/source entity type/source ID/column; canonical için ayrıca canonical entity type içerir. Missing/wrong key, foreign reference ve owner/type değişimi reddedilir. Yeni JSON float/NUL dahil exact array round-trip sağlar; legacy bakım orijinal JSON byte’larını yeniden encode etmeden korur.

Save source tuple unique index’ini doğrular, canonical dışı unexpected unique kimlikleri ve MyISAM owner’ı reddeder. NOP upsert ve current locking read tek source owner rezerve eder. İlk raw kaynak korunur; tekrar kaydetme raw’ı değiştirmez. Canonical data/type değişimi veya explicit clear eski Vault entry’yi aynı transaction’da değiştirir/siler. Metadata-only save cipher/ref/version’ı korur. Source-system/ID/batch sahibi immutable’dır. Parent raw/managed transaction, savepoint, owner/Vault/audit failures ve duplicate writers birlikte testlidir. Kayıp owner, malformed JSON veya eksik cipher sessizce boş diziye dönüştürülmez.

Validation/quarantine mesajları bilinen raw/canonical secret bağlamıyla kaydetmeden ve legacy okuma görünümünde temizlenir. Geçmiş okuma asıl satırı değiştirmez; explicit maintenance unsafe diagnostic kopyasını temizler. Key lifecycle read-only verify bu kopyalar için de legacy scrub gerektiğini bildirir. Varsayılan StagingRecord JSON/debug görünümü payload gövdelerini tamamen gizler. Operasyonel getters/toArray tam kaynak ve canonical veriyi korur; gerçek mapping/remediation girdisi maskeleme ile bozulmaz. HTTP/actor yetkisi ve retention kendi fazlarında ayrıca doğrulanır.

Orijinal Security Constitution ham kart verisinin saklanmasını/aktarılmasını yasaklar. Raw/canonical girişte ve legacy/restore decode’da PAN/card_number/cardnum/CVV/CVC gibi bilinen alanlar ve Luhn geçerli açık PAN değerleri reddedilir. Kayıt sessizce kırpılmaz veya encrypted biçimde kabul edilmez. Tokenize payment method, brand ve last4 desteklenir. Genel amaçlı obfuscation/DLP veya bütün ödeme sağlayıcıları için kart uyum sertifikası değildir; source adapter ve gerçek command/HTTP kabulü D06/D08 sınırını korur.

## Bakım ve composition

Canonical staging/Vault/security audit şeması kurulu, APP_KEY veya APP_KEY_FILE doğru ve writer’lar durmuşken, güvenilir legacy JSON kaynağı açıkça onaylanarak:

```text
php tools/staging-secret-maintenance.php --maintenance --confirmed-legacy-json
```

CLI HTTP SAPI’yi, eksik flags, parent transaction, schema/key/owner conflict ve malformed/forbidden card data içeren batch’i reddeder. Preflight sonrası bütün batch tek transaction’da taşınır; idempotent tekrar sıfır kayıt verir. Runtime plaintext fallback yoktur. Backup/log dosyaları otomatik yeniden yazılmış/silinmiş sayılmaz. CLI yalnız sentetik izole test DB’sinde çalıştırıldı; production bakımı/deployment yapılmadı.

CoreServiceProvider kalıcı Encryptor ile repository ve interface’i bağlar. ensureTable ve accounting metadata raporu key-free kalır; historical migration 10’a crypto bootstrap eklenmez, 26 canonical migration byte olarak değişmez. Encrypted prepareStorage missing key’i etkiden önce reddeder; MySQL caller transaction içinde dependency DDL yapılmaz. Kurulum testi migration öncesi aynı gerçek protected key file’ı bağlar ve bütün runtime tüketicilerini onunla yapılandırır; tamamlandıktan sonra yeni rastgele key üretmez.

## Anahtar ve recovery

KeyLifecycle requiresVerification/preflight/deep inventory staging raw ve canonical owner’larını kapsar. Fiziksel Vault rotation mevcut aggregate’e katılır; owner referansları sabit kalır. Cached eski key ile find/list/save/status writer current binding’den sonra çalışamaz. Native eski-parent snapshot testleri current owner/cipher tutarlılığını ve stale key reddini ölçer. Read-only inventory audit veya veri yazmaz.

Gerçek SQLite backup/restore pozitif case’i restored raw kaynağı doğru key ile geri okur; source owner değişimi, legacy raw JSON veya secret echo diagnostic case’leri files copy/success’ten önce durur. SQL importunun rollback olduğu iddia edilmez. Whole MariaDB dump/parser/path ve interrupted cross-resource recovery D05’te kalır. Privacy signing/legacy/key-binding/reapply gates önceki uygulamalarıyla korunur.

## Uyum düzeltmeleri ve kanıt

Geniş migration koşusu, muhafazakâr redactor’ın “unmigrated client [99]” gibi diagnostic ID annotation’ından sonraki yararlı açıklamayı sildiğini gösterdi. Embedded JSON balanced fragment sınırında işlenir; normal annotation/suffix korunur, malformed fragment fail-closed ve escaped secret alanları maskeli kalır. Numeric JSON PAN tanısı ayrıca kapatıldı; sıradan küçük kayıt ID’leri korunur. Önceki kaynakla başlayan ara koşular final sayılmadı; son kaynak yeniden sabitlendi.

Final tam ana suite **1782 test / 12122 assertion**; D03.1 kapanışına katılan exact 18 MariaDB sınıfı **247 test / 1736 assertion**, failure/error/skip0. Native kapsam: staging/contact; Vault/provider schema; provisioning/automation/domain/queue; SMTP/TOTP; key lifecycle; privacy signing/legacy/binding/install; egress/API/history ve canonical SQL/install. 18 native sınıf dört yalıtılmış PHP/MariaDB grubunda çalıştırıldı (A/B/C/D); raw XML/TXT çıktıları ve native-partitions.json korunur. Birleştirme her grubun testcase ID kümesini discovery ile karşılaştırır; kesişimler boş, birleşim exact 247 case’tir. Birleştirilmiş XML tek süreç çıktısı gibi sunulmaz. Bütün native suite yeniden koşulmadı; historical full D02 sonucu kendi commitinde korunur.

20 ortak staging case SQLite/MariaDB’de; native 5 ek case MyISAM, dört concurrent duplicate writer, old-snapshot current owner+cipher, stale binding ve CLI admission/idempotence/restart. KeyLifecycle 4 yeni shared case (round-trip/stale writers ve üç inventory rejection varyantı); restore 3 ek negative varyant ve pozitif restored raw assertion; shared redactor annotation/escaped JSON/numeric PAN testleri. CSV/WHMCS validation/zero-silent-loss/quarantine/remediation/cutover ve fresh installer tam ana suite içinde yeniden doğrulanır.

25 source/test/tool dosyası finalden önce SHA256 ile sabitlendi. Security/architecture/dependency taraması PASS: 1077 PHP file, altı signature pattern, üç forbidden-function rule; bağımsız kabul değildir. verify-d03-staging-secrets.py kaynak/kanıt hash, exact native testcase ID kümeleri, 26 unchanged migration, orijinal plan kaynak özetleri, current matrix ve D03.2’ye geçiş state’ini doğrular.

D03.1'in yerel kapanış ölçütleri ve sonraki fazların sınırları D03-1-CLOSURE.md’dedir. Açık Q1/Q2 saklama işi kalmadı; bağımsız/product acceptance verilmedi.
