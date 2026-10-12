# D02.3b devamı — sağlayıcı ayarları, Vault ve denetim şeması

Tarih: 12.10.2026. Referans commit: 1286284c08ad3226f59eafba80f4339faf26d0a8. D02 IN_PROGRESS; independent_acceptance=PENDING; production_ready=false.

## Düzeltilen davranış

ProviderSettingService ve VaultService her kullanımda CREATE TABLE IF NOT EXISTS veriyor; MariaDB çağıranın transaction'ını örtülü commit ediyordu. Production migrationlar iki tabloyu kurmuyordu. D02-3t-before.txt bu iki transaction kaybını ve iki eksik tabloyu gösteren üç gerçek failure içerir. İlk iki servis düzeltildikten sonra unchanged AuditLogger dependency'si aynı sorunu sürdürdü; D02-3t-audit-before.txt ayrı gerçek failure kanıtıdır.

Migration 18 provider_module_settings ve vault_secrets kurar. Schema-only static girişler Encryptor, sahte şifreleme anahtarı veya sağlayıcı adapter'ı oluşturmaz; mevcut ciphertext'i okumaz/decrypt etmez/yeniden şifrelemez. Provider schema preflight iki tabloyu birlikte doğrular; partial mevcut tablo varsa diğer eksik tabloyu bile oluşturmadan durur. Installed kullanımda Vault/settings/audit DDL çalıştırmaz. Eksik Vault/settings şeması raw PDO dahil açık application transaction içinde reddedilir; partial shape'de sessiz ALTER ve tarih tahmini yoktur.

AuditLogger'ın migration 9 ile SQLite transactional bootstrap uyumluluğu korunur; eksik SQLite audit tablosunun ilk kullanımı hâlâ transactional DDL yapabilir. Native PDO audit okumasındaki tekrarlanan named placeholder HY093 hatası ayrıca D02-3t-audit-read-before.txt ile gösterilip ayrı parametre adlarıyla düzeltildi. Aktör ve temsil edilen kullanıcı eşleşmeleri korunur; iki alanda aynı kullanıcı varsa kayıt çoğalmaz, diğer kullanıcının kaydı dahil edilmez. Bu istisna MySQL caller transaction'ında DDL'ye izin vermez. Kurulu tabloda audit yazımı caller secret/settings değişiklikleriyle birlikte rollback edilebilir. Migration down sırları ve ayarları silmez, explicit backup/retention planı ister.

## Doğrulama

12 ortak senaryo SQLite ve native MariaDB 10.11.18 üzerinde çalışır. Caller rollback ve audited rollback; keyless clean install/replay; byte-identical ciphertext/version/date/settings; Unicode, runtime decrypt ve public masking; installed read/rotate/delete/audit no-DDL; missing dependency ve partial shape guard; failed migration'ın kaydedilmemesi/explicit fixture repair sonrası resume; explicit transaction setup sınırı ve rollback refusal ölçülür. Fixture onarımı bilinmeyen eski updated_at değerini NULL bırakır; production otomatik upgrade olarak uygulanmaz.

| Paket | Test | Doğrulama | Sonuç |
|---|---:|---:|---|
| Ana paket | 1229 | 9489 | Başarılı |
| MariaDB 10.11.18 | 351 | 2645 | Başarılı |
| Toplam | 1580 | 12134 | Failure/error/skip yok |

Final kanıt D02-3t-suite.txt/xml ve D02-3t-mariadb.txt/xml; SHA-256 D02-3t-file-hashes.json. Eski masking/rotation/crypto olumlu beklentileri aynı kalır. Üç test migration sayısı 17→18; Foundation repair testine yeni 18'in devamı eklenir, failure-before-17 count 16 korunur. 18 migration, 92 DDL kaynak. Vault referansı migration 18'den ProviderSettingService üzerinden transitiftir; metin envanteri kabul kanıtı değildir.

## Kalan işler

Orijinal P03.8 ve Security Constitution'ın anahtar, actor/tenant RBAC, secret logging/reveal policy, immutable audit ve güvenli rotation koşulları bu adımla kapanmaz. Yeni API/endpoint veya dış provider işlemi yoktur. Kullanılan sırlar/key yalnız yapay test fixture'larıdır; üretim anahtarı/verisi kullanılmadı.

Standalone çoklu satır save/rotate/delete atomikliği, audit başarısızlığı sonrası caller commit kararı, key rotation/CAS concurrency, namespace boyutu, retention/backup/restore ve HTTP/CLI/Application wiring ayrıca açık. Required-column kontrolü eski engine/type/collation/NULL/default/unique/FK/index/trigger uyumluluğunu kanıtlamaz. Legacy first-use ve diğer domain runtime DDL yolları bütünüyle kaldırılmadı. Sıradaki D02 işi kalan currency/brand ve diğer schema girişleriyle kısıt matrisini incelemek; D03–D09 ve bağımsız üretim kabulü açıktır.
