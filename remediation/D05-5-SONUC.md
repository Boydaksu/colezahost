# D05.5 — güvenli eklenti kurulumu

16.10.2026. **IMPLEMENTATION_COMPLETE_LOCAL_VERIFIED**. D05-5-KABUL.md sabit kapsamı tamamlandı; D05'in beş yerel adımı kapalı. Sonraki faz D06. production_ready=false; independent_acceptance=PENDING.

## Kurulum davranışı

ExtensionPackage mevcut updater'ın imza ve dondurulmuş snapshot doğrulamasını kullanır. Özel quarantine, tam üye/checksum eşleşmesi, duplicate JSON/path/case kontrolü, link ve traversal reddi, 5000 üye/64 MiB toplam/100 sıkıştırma oranı sınırları uygulanır. PHP syntax kontrolü dosyayı çalıştırmaz. İç extension.json katı alanlarla aynı imzalı sürüm/minimum Core/dependency sözleşmesine bağlıdır; azami Core ve API1 uyumu kaybolmaz. Wildcard ağ izni, belirsiz/null izin, reserved path ve desteklenmeyen alan reddedilir.

preview() yalnız okur; gerçek JSON migration'dan üretilecek SQL'i gösterir. install() aynı paketi tekrar dondurur; kullanıcı onayı exact digest ve izin listesine bağlanır. Stale onay hiçbir DDL yapmaz. Aynı DB/hedef RecoveryLease ve global guard başka update/restore/extension ile yarışmayı durdurur. Doğru PDO/Core bağlamında gerçek tüm DB ve mevcut module-tree yedeği alınır; bağımsız yeniden okuma ve tree drift kontrolünden sonra Recovery/journal kalıcılaştırılır. Backup, kaynak, public ve module konumları ayrıdır; caller transaction kabul edilmez.

Migration yalnız sıralı deklaratif create_table JSON'dur. İsimler modül ID'sinden türetilen ext_HASH_ namespace'ine derlenir; raw SQL/PHP veya Core tablo ismi verilemez. Tip/kolon/işlem/dosya sınırları vardır. Gerçek DDL ve hash ledger kullanılır; SQLite migration+receipt transaction, MariaDB gerçek implicit-DDL davranışıyla test edilir. Başarısız DDL tamamlandı sayılmaz ve otomatik tekrar çalışmaz. Canonical 26 migration byte olarak korunur.

İmzalı dosyalar private staged dizine yazılır, hash ve fsync kontrolünden sonra tek modül dizini olarak yayınlanır. Önce disabled modül ve audit birlikte kaydedilir. SQL/FK/key/privacy/file bütünlüğü ile Core/dependency yeniden doğrulanır; yalnız host tarafından sağlanan read-only adapter health true dönerse enable+audit aynı transaction içinde yapılır. Eksik callback/contract kapıyı açmaz; callback tek başına gerçek health yerine geçmez. Paket PHP'si require/autoload edilmez. Legacy discovery paketleri yeniden yorumlamaz ve legacy enable paket health kapısını atlayamaz.

ExtensionContext PDO/container/raw SQL vermez: kendi deklaratif tabloları, private dosyaları, extension.ID ayarları, aynı PDO'ya bağlı module:ID Vault ve namespaced route kullanılır. Her çağrıda güncel enabled/Core/dependency/guard/file/ledger/grant doğrulanır; yakalanmış context veya route disable sonrası çalışmaz. Satır/dosya/ayar boyutu ve pagination sınırlıdır. Ağ yalnız exact onaylı HTTPS origin (açık port dahil), GET ve ayrı origin/path argümanlarıyla host transport'una gider. Varsayılan transport yoksa reddedilir. TLS/DNS/no-redirect uygulaması host adapter sözleşmesidir; testteki spy gerçek ağ/SSRF host kabulü değildir. Aynı süreçte güvenilir PHP adapter'ı OS sandbox'ı olarak sunulmaz.

## Hata ve geri dönüş

Kalıcı journal DB kimliği/hedef/yedek ID/kaynak sürüm/paket ve backup hash'lerini bağlar. Kısmi migration, audit, health ve süreç ölümü success sayılmaz. Açık offline recover() ortak RestoreWizard exact SQL/files replacement ve key/privacy gate kullanır; yeni tablolar/dosyalar temizlenir, önceki DB/module ağacı geri gelir. Corrupt yedekte silme başlamaz. Tamamlanmış recovery tekrar import yapmaz. Bu global DB rollback olduğundan diğer writer'lar bakımda durmalıdır; normal ürün writer composition D08'e aittir. Başarı Normal moda otomatik geçirmez. Disable uninstall değildir ve veriyi korur; fresh install duplicate/upgrade/orphan yolu yerine geçmez.

## Test kanıtı

- Önceki 9abd1ae revizyonundan iki özgün Module sınıfı ayrı PHP süreçte preload edildi: azami Core kaybı, audit hatasında kalan modül ve legacy packaged-enable bypass için **3 test / 3 failure / 0 error**, exit1. Before XML/stdout/exit ve özgün blob hash'leri saklıdır.
- Final tam ana paket **2155 test / 13497 assertion**, exit0; failure/error/skip yok. 43 yeni SQLite extension vakası, bütün önceki updater/rollback ve 52 gerçek yerel S3/SFTP protocol/config vakası dahildir.
- Limit sonrası ilk native denemede önceki izole sunucunun kapandığı görüldü; deneme durduruldu, kendi sunucusu yeniden başlatıldı ve aynı source ile bütün 207 vaka baştan koşuldu. Ortam denemesinin kısmi çıktısı d05-extension-native-environment.txt/json içinde ayrı saklıdır; başarılı test sayısına dahil değildir.
- Final gerçek MariaDB10.11 **207 test / 1608 assertion**, 14 affected sınıf, exit0; failure/error/skip yok. 43 yeni extension vakası dahildir. Discovery ve çalıştırılan vaka ID'leri birebir eşleşir. Bütün native suite yeniden çalıştırılmış sayılmaz.
- Ayrı PHP süreçlerinde DDL, publication ve enable sınırlarında exit76 ile kesinti; yeni süreçle gerçek rollback; iki gerçek installer süreciyle lease yarışı; geç audit PDO hatası sonrası partial-DDL recovery; bozuk backup; readonly preview; imzalı ZIP; path/symlink/bomb; grant/file/ledger tamper ve scoped Vault/route/network testleri vardır.
- Final testten önce 25 source/test/workflow dosyası sabitlendi. Plan39/258, locked97 ve whitespace kontrolü geçti. GitHub işi module/extension SQLite ve 14 affected MariaDB sınıfını da çalıştırır; mevcut üç transport işi korunur.

Kapanış güvenli installer ve scoped v1 API içindir. Desteklenmeyen SQL/PHP migration reddedilir; keyfi yüklenmiş PHP runtime/upgrade/uninstall desteği iddia edilmez. Gerçek sağlayıcı/transport adapter'ları D06, ürün retention/erasure D07, authenticated HTTP/CLI/cron/worker ve Normal aktivasyon D08, bağımsız host/browser/harici hesap kabulü D09'dadır. Üretim deployment/restore yapılmadı.

Kanıt: d05-extension-baseline/before/main/native/discovery/checks/summary/hashes; denetim `python -X utf8 remediation/verify-d05-extensions.py`. Önceki D05 raporları tarihsel kaynak revizyonlarına bağlıdır; yeni kodla onların byte verifier'ını çalıştırmak önceki teslimin kanıtını güncellemez.
