# D03.1 Q1 — şifreli kuyruk ve başarısız iş saklaması

15.10.2026; başlangıç commit’i `2600f4c5d31aed2746fff6eb493846c0d5c51e3c`. Orijinal master Security/Data Constitution ve mevcut Q1 çıkış ölçütleri esas alındı. D02 kapalı; Q1 yerel uygulama ve kabulü tamamlandı. D03.1, Q2 migration staging için açık. independent_acceptance=PENDING; production_ready=false. GitHub gönderimi deployment değildir.

## Değişiklik

İlk iki regresyon, jobs.payload ve failed_jobs.payload alanlarının etiketli/etiketsiz sentetik sırları serialize(job) içinde açık tuttuğunu gösterdi (2 test/2 failure/error0, before XML/TXT). Kuyruk artık job bytes’ın tamamını fiziksel Vault’ta saklar; owner tablosunda yalnız vault:v1 referansı bulunur. Referans table/ID/queue/payload sütununa, Vault ciphertext’i namespace/key kapsamına bağlıdır. ID/queue değişimi, başka job/DLQ referansı, yanlış anahtar, eksik veya bozuk ciphertext kabul edilmez. Girdiyi maskeleyip işi bozmak yerine binary/NUL ve float dahil exact serialized bytes korunur.

Foundation DatabaseQueue yalnız QueuePayloadStoreInterface sözleşmesini bilir; Domain/Vault/QueuePayloadStore fiziksel kasayı uygular. CoreServiceProvider persistent Encryptor ile uygulamayı enjekte eder. Doğrudan kuyruk oluşturan runtime tüketicisi bu storage’ı vermelidir; plaintext/default-key fallback yoktur. ensureTables ve size/failedSize gibi metadata yolları key istemez; historical migration sözleşmesi ve 26 canonical migration byte olarak korunur. Encrypted runtime için önce prepareStorage ile dependency/schema hazırlığı yapılabilir; MySQL caller transaction içinde DDL yapılmaz, mevcut şema doğrulanır. Eksik key owner/Vault/audit tabloları yaratılmadan reddedilir.

Push ID tahsisi, Vault write ve audit aynı transaction/savepoint’tedir. Pop binding→owner→Vault lock sırasını izler; authenticated payload okunmadan lease verilmez. Reservation token ve queue kimliği mevcut sahibin ack/release/fail yetkisini sınırlar; stale worker mevcut owner veya Vault verisini silemez. Retry aynı payload referansını ve backoff davranışını korur. DLQ son handle sonrası değişmiş job state’ini ayrı table/ID scope ile saklar; yeni failed owner/cipher, eski owner/cipher silimi ve bütün audit’ler aynı aggregate’dedir. Audit hatasında placeholder/orphan veya kısmi taşıma commit edilmez. Corrupt/non-job payload sessizce silinmez; işlem reddedilip queue row korunur.

Structural validation allowed_classes=false ile object wakeup/unserialize hook’larını çalıştırmaz ve kayıtlı JobInterface sınıfını arar. Gerçek pop authenticated job’ı normal şekilde hydrate eder; normal job davranışı korunur. Maintenance ve read-only lifecycle verification job hook’larını çalıştırmaz. Bu, uygulamanın güvenilen job kodunu sandbox’a almak veya her job’ın uzak sistem yan etkisini tam bir kez yapmasını sağlamak değildir.

## Eski kayıt bakımı

Canonical owner/Vault/security-audit şemaları kurulu ve doğru APP_KEY veya APP_KEY_FILE yapılandırılmış olmalıdır. Üretici ve worker’lar durdurulmuş, doğrulanmış yedek alınmış ve legacy job kaynağı güvenilir olarak incelenmiş olmalıdır. Explicit CLI:

```text
php tools/queue-secret-maintenance.php --maintenance --confirmed-trusted-jobs
```

CLI yalnız CLI SAPI’de çalışır; eksik flag, yanlış key, caller transaction, eksik/uygunsuz şema, malformed serialized job veya Vault owner çakışması reddedilir. Runtime eski plaintext’i otomatik kabul etmez. Bütün batch doğrulanıp tek transaction’da taşınır; idempotent tekrar sıfır kayıt verir. Raw serialized bytes korunur; eski failed_jobs.exception mesaj/trace’i genel “Legacy queue failure details withheld.” metniyle değiştirilir. Kaynak backup/log dosyaları otomatik silinmiş sayılmaz; forensic retention ve fiziksel erişim politikası ayrıca yönetilmelidir. Bu CLI yalnız izole sentetik MariaDB testinde çalıştırıldı; production verisi taşınmadı.

## Anahtar ve kurtarma

KeyLifecycle requiresVerification, preflight ve deep owner inventory iki kuyruk tablosunu kapsar. Bütün physical Vault ciphers mevcut rotation aggregate’inde değişir; owner ref’ler sabit kalır. Eski cached key ile push/pop/ack/fail yeni binding’den sonra yazamaz. Native eski-parent-snapshot testi current locking binding okumasını doğrular. Lifecycle verify audit/data veya job hook çalıştırmaz. Eski plaintext/spliced owner, rotation’ın herhangi bir ciphertext/probe/version/audit yazmasından önce reddedilir.

Gerçek SQLite backup/restore kabulü doğru key ile restored job’ın okunmasını; yanlış/eksik key, bozuk cipher, queue owner değişimi veya legacy plaintext ile files copy/success olmamasını ölçer. SQL importu atomik rollback olmuş sayılmaz. Bütün MariaDB dump/parser/path ve interrupted file durability kabulü D05 kapsamında kalır.

## Test kanıtı

Final tam ana suite **1753 test / 11977 assertion**; etkilenen MariaDB 10.11 suite **82 test / 683 assertion**, failure/error/skip0. Exact altı native sınıf: QueueSecret, KeyLifecycle, FinancialConcurrency, InstallationAcceptance, SecretAcceptance, SqlPortabilityCloseout. Full native suite bu kaynakta yeniden koşulmadı; historical D02 full sonucu kendi commitinde korunur.

16 ortak queue case SQLite ve MariaDB’de; native üç ek case MyISAM owner, eski parent snapshot ve CLI flag/idempotence/process restart. Ortak case’ler exact restart/DLQ, stale lease, owner replay/reroute, wrong/missing key, raw/managed parent rollback, late DLQ delete-audit failure, push/read audit failure, no-hook trusted legacy conversion, malformed batch, conflicting Vault owner, retry/mutated DLQ state ve corrupt payload admission’ı kapsar. KeyLifecycle iki yeni ortak case pending+failed payload rotation/restart/read-only verify ve stale writer/pre-effect inventory rejection’ı ölçer. FinancialConcurrency dört worker’ın 32 farklı job lease almasını yeniden test eder. Restore provider’ına queue-corrupt/queue-legacy eklendi; pozitif restore aynı sır değerini geri okur.

İlk geniş development koşusu Foundation→Domain mimari bağımlılığını yakaladı. Doğrudan bağımlılık kaldırılarak storage interface/composition injection uygulandı; test veya mimari kural gevşetilmedi. Eski kaynakla devam eden native koşu durduruldu ve final kanıt sayılmadı. Final kaynak yeniden normalize edilip SHA256 ile sabitlendi. Security/architecture/dependency taraması PASS: 1073 PHP file, altı secret signature, üç forbidden-function rule. Bu statik tarama bağımsız güvenlik kabulü değildir. verify-d03-queue-secrets.py source/evidence hash, exact testcase ID kümeleri, state sınırları ve 26 unchanged migration’ı doğrular.

## Kalan iş

D03.1’de yalnız Q2 — migration_staging_records.raw_payload_json/canonical_payload_json — açık. Owner/source bağlı encrypted storage, pristine raw koruma, explicit legacy bakım, validation/quarantine diagnostic temizliği, transaction ve lifecycle/native kabulü gerekir. Q2 ardından mevcut dosya envanterinin kapanış kontrolü yapılır. Önceden biten key/privacy/diğer carrier işleri yeniden açılmaz. D03.2–D03.4, D05, D06, D08 ve D09 kendi yetki, recovery, provider, composition ve bağımsız kabul kapsamlarında kalır.
