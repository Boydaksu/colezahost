# D03.1 — anahtar binding, rotation ve restore key kapısı

Referans `5c904cbc7694ebcea617391a758941d9b2846e4c`. Orijinal master Security Constitution/F08/F09. D02 kapalı; D03.1 IN_PROGRESS; production_ready=false; independent_acceptance=PENDING.

## Tamamlanan yerel backend

KeyLifecycleService explicit maintenance ve bağımsız durable transaction ister; raw/managed caller transaction reddedilir. initialize mevcut key ile bütün bilinen cipher/owner kayıtlarını doğrular, security.application_key_probe encrypted sentinel'ini mevcut system_settings tablosunda saklar. Key materyali DB'ye eklenmez. Matching bind idempotenttir; yanlış key veya legacy/unknown scope/provenance binding'i ve audit'i rollback eder. Canonical unique setting_key, Vault identity ve InnoDB gerekli; implicit DDL/migration yoktur.

Vault set/get/delete/reseal, SMTP mutation, TOTP setup/state ve explicit legacy upgrades probe'u **işlem içindeki FOR UPDATE** ile kontrol eder. Rotator aynı lock'u alır. Böylece kabul edilmiş eski-key yazım rotasyon inventory'sinden önce commit eder; rotation sonrası eski-key write/delete reddedilir. Encryptor seal ile guard arasında aynı snapshot'tır; default SMTP/TOTP key'i çözülüp cache edilir. Nontransactional binding table write admission sağlamaz. Unbound eski runtime veri için explicit maintenance bind gerekir; kayıp probe'lu cipher backup restore doğrulamasını atlatamaz.

rotateToFile yeni korumalı dosyayı DB transaction öncesinde doğrular. Vault (legacy authenticated GCM dahil), SMTP, TOTP ve legacy encrypted server field tek transaction'da dönüşür. Namespace/key/context ve owner refs doğrulanır; refs değişmez. Vault version/date güncellenir. Probe + rotation audit aynı transaction'dır. Late audit failure bütün cipher/version/probe değerlerini rollback eder; staged dosya kalır. Aynı key, yok/güvensiz file ve caller transaction reddedilir. DB ve ortam switch atomik sayılmaz: dosya önceden kalıcıdır, commit sonrası yeni APP_KEY_FILE ve restart/verify gerekir. Eski dosya silinmez. Komutlar key/secret döndürmez; yalnız fingerprint/count metadata vardır.

Installer setupApplicationKey private file oluşturur veya explicit resume kullanır; binding doğrulanır ve SMTP aynı key'i alır. Session yalnız key_id taşır. Finalize file'ı yeniden yükler; kaybolmuş/değişmiş file veya matching olmayan cipher/probe ile lock yazılmaz. HTTP screen/composition bağlama D08'dedir; servis ortamı kendisi değiştirmez. CLI bind/verify/rotate parametreleri yalnız CLI'de kabul edilir, key argument değildir. --maintenance süreç durdurmaz; yazıcıları durdurduğunuzun kabulüdür.

Restore doğru arşiv parolasından bağımsız uygulama key'i ister. Matching probe + deep cipher/ref read-only verification, preserved file copy/success öncesindedir. Missing/wrong key, corrupt ciphertext ve missing probe'lu cipher archive false döner. SQL import key kontrolünden önce yapılır: failed restore hedefinde veri bulunabilir. Sonuç gerçek uygulanmış SQL/file sayılarını taşır; MySQL DDL rollback veya tam recovery kanıtı değildir.

## Yeni bulunan cron açığı

Original CronSetupService cron.token'i plaintext saklıyor, is_encrypted=1 diyordu. Geçerli recovery/key lifecycle bu kaydı encrypted sanamaz. Yeni token hash:v1:SHA256 ve is_encrypted=0'dır; hash tekrar authentication token olarak kullanılamaz. Empty token create/auth reddedilir. Legacy token runtime'da okunmaz; provenance teyitli explicit upgrade/replay desteklenir. Cron hash'i encryption-key değişiminden etkilenmez. Eski davranış yalnız namespace izolasyonuyla original source üzerinden before-cron çıktısında yeniden üretildi. URI'de token sunma ve genel log/export audit'i bu paketle kapanmış sayılmaz.

## Test ve ham kanıt

Original wrong-key writer ve key olmadan finalize: before 2 test/2 failure, error0. Geliştirmede SMTP fixture host eksikliği, yanlış test method/catch türü, legacy cron flag ve yalnız core schema kullanan migration E2E fixture görüldü. Positive migration fixture gerçek 26-migration baseline ile başlatıldı; veri/hold assertion'ları korunur. İlk focused/development/preflight XML/TXT dosyaları saklandı, final yerine sayılmadı.

Son ana paket **1616 test/11361 assertion**. Son affected MariaDB **168 test/1218 assertion**,8 sınıf/exact discovery, fail/error/skip0. Native bütün suite değildir. Son source freeze ve hashes verify-d03-keylife.py ile doğrulanır. 26 migration byte olarak değişmedi. Security/dependency scan PASS; Windows key ACL gerçekten çalıştırıldı. POSIX gerçek Linux kabulü ayrıca gerekir.

Native bir rotator + üç bağımsız writer yarışını ve dört retired-key sürecinin reddini ölçer; admitted write audit sayısı korunur ve final bütün cipher'lar yeni key ile doğrulanır. CLI ayrı süreçlerde bind/verify/rotate ve yeni APP_KEY_FILE ile restart verification yapar. SQLite ve MariaDB late audit rollback, legacy Vault/cipher conversion, stale write/delete/SMTP/TOTP, unknown encrypted setting/owner ref, same/missing key file, raw/managed caller admission ve plaintext/key redaction testlerini paylaşır. Installer explicit resume/lost file/no-key lock negative; restore correct/wrong/missing/corrupt/unbound ve doğru encrypted-archive passphrase ile yanlış app key senaryolarını sınar.

## Kalan kapsam

F08 key lifecycle backend yerel olarak uygulandı; D03.1'de **tek kalan iş grubu genel secret/config/log/export audit'i**. Actor, admin step-up/RBAC/impersonation D03.2–D03.4'te; CLI audit actor0 internal bakım sözleşmesidir. D05 dump'un SQLite literal escaping/MariaDB dump, restore atomicity/staging/path ve whole-module recovery kabulü açıktır; yalnız bu key gate geçerli restore güvenliği sayılmaz. GitHub push deployment değildir.
