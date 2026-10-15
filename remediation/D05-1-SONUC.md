# D05.1 — yedek teslimi ve yerel kopya güvenliği

15.10.2026. Başlangıç 2bf8bec43ec271066aa62cc0ecc66ad46aa70b53. Birincil plan P17.5 ve F10; DB scope kabulü F11 ile ilişkili. D05.1 yerel uygulama tamamlandı; D05 açık. Sonraki D05.2; D05.2–D05.5 olmak üzere dört adım kaldı. D02/D03/D04 kapanışları korunur.

## Başlangıç kanıtı

Altı test/altı failure/0 error: store=false'in başarı sayılması; eksik read-back; bozuk read-back; varsayılan S3'ün sessizce simüle etmesi; production SFTP'nin hiçbir aktarım yapmadan başarı dönmesi; DB bağlantısı yokken archive/file işlemlerine kadar ilerlenmesi. Son vaka eski PHP uyarılarını assertion olarak kaydeder ve reddeder; uyarılar gizlenerek başarı üretilmez. Before XML/stdout korunur.

## Teslim sözleşmesi

EnterpriseBackupService store sonucunu zorunlu olarak true ister. Ardından bağımsız, özel ve rastgele bir read-back klasörüne destination retrieve yapar. Backup ID, regular-file/manifest, byte uzunluğu ve SHA-256 kontrol edilir. Destination kendi arşivine uygun farklı bir manifest döndürse bile kaynak arşiv/hash ve manifest byte'larıyla da karşılaştırılır. False/exception/eksik kalem/bozuk veya self-consistent substitute paket başarısızdır; source ZIP+manifest korunur. Retention ancak bu teslim kontrolünden sonra çalışır.

Yerel çalışma paketi non-local destination'da yalnız başarılı bağımsız read-back sonrasında silinir. Local destination'da çalışma paketi korunur. Geçici doğrulama klasöründe yalnız bu işin bilinen dosyaları temizlenir; başka dizine recursive delete yapılmaz. Same working/storage directory senaryosu desteklenir.

LocalBackupStorageAdapter dosyaları özel staging adlarına stream ederek yazar, flush/fsync ve SHA doğrulaması yapar. Archive rename sonrası manifest publish edilir; manifest+archive doğrulanmadan paket görünür başarı sayılmaz. Yayınlama/retrieve/delete/list global yerel lock ile eşzamanlı değişimden korunur. Aynı ID ve aynı byte'larla retry idempotenttir; aynı ID'ye farklı içerik eski paketi ezmez. Kısmi eski hedefler sessizce overwrite edilmez. ID traversal, source symlink ve manifest/hash uyuşmazlığı reddedilir.

Retention tanımadığı veya geçerli kimlik/size/hash/date metadata'sı olmayan geçmişi eski varsayarak silmez. Bilinen en yeni kopya yaş sınırını aşmış olsa bile korunur. Delete=false budamayı durdurur; başarı sayacı ilerletilmez. createBackup şu anda yalnız yeni doğrulanmış manifesti bildiğinden otomatik retention bilinmeyen eski paketleri korur; tüm uzak geçmişin verified keşfi D05.2'de açıktır. Bu yaklaşım depolama kotası optimizasyonunun tamamlandığı iddiası değildir.

## Açıkça başarısız olan eksik yollar

S3/SFTP adapter'ları artık varsayılan olarak simulation seçmez. Gerçek transport store/retrieve/delete/exists/list henüz uygulanmadığı için RuntimeException verir; boş başarı dönmez. Simülasyon yalnız explicit simulated=true test seçeneğidir. Mevcut tests bunu zaten açıkça kullanır; uzak hesap başarı kanıtı sayılmaz.

Enterprise FULL/DATABASE scope bağlantı olmadan reddedilir. Mevcut dump üretimi SQLite dışında gerçek veri üretmediği için MariaDB DB/FULL isteği herhangi bir paket oluşturmadan açık hata verir. FILES scope MariaDB bağlantısıyla kullanılabilir ve DB'nin dahil edildiğini iddia etmez. Bu geçici admission koruması gerçek dump değildir; consistent MariaDB dump D05.3'tedir. PreUpdateBackupService yolu bu adımla tamamlanmış sayılmaz; D05.3/D05.4'te gerçek dump ve zorunlu update backup kabulü gerekir.

Dosya scope'unda eksik/okunamayan/symlink kaynak, desteklenmeyen dizin ve basename çakışması sessizce atlanmaz. FILES boş olamaz. Recursive göreli yol koruyan backup D05.3'te uygulanacaktır. Encryption istenip anahtar yoksa plaintext arşiv üretilmeden reddedilir. ZIP add/finalize/manifest write sonuçları kontrol edilir. Yeni çalışma/storage dizinleri özel modla açılır; bütün platformlarda fiziksel permission/crash/recovery kabulü henüz yapılmış sayılmaz.

## Doğrulama

- Tam ana paket: **1906 test / 12613 assertion**.
- Gerçek MariaDB 10.11, exact discovery ile üç ilgili sınıf: **16 test / 119 assertion**.
- Failure/error/skip sıfır; tam native suite bu tur tekrar çalıştırılmadı.
- 21 yeni publication regresyonu: false/exception/missing/partial/corrupt/substituted read-back, explicit simulation, local checksum/idempotency/collision, ID/path ve partial visibility, scope/encryption admission, bilinmeyen history ve son eski kopyanın korunması.
- Native dört vaka: gerçek MariaDB DB/FULL boş dump reddi, veri korunması, raw caller transaction'ın açık kalması ve FILES scope'un DB'yi dahil göstermemesi. Mevcut installation/SQL sınıfları da geçti.
- Eski retention olumlu fixture'ı artık dummy checksum/yanlış size yerine saklanan gerçek byte'ların SHA-256/size/manifestini kullanır. Mevcut üç-retained/iki-pruned beklentisi korunur. Restore tamper hata sözleşmesi korunur. Üç eski health/recovery fixture sahte {} manifest/rasgele içerik yerine gerçek oluşturulmuş yedeği kullanır; ek Doctor regresyonu bozuk çiftin CRITICAL olduğunu doğrular.
- 26 kanonik migration byte olarak değişmedi; yeni schema yok. Son source/test byte'ları ve ham test kanıtları SHA-256 manifestinde kayıtlıdır.

## Kalan sabit plan

D05.2 gerçek S3/SFTP transport ve verified uzak geçmiş; D05.3 DB/files dump-restore/privacy recovery; D05.4 zorunlu verified pre-update backup/gerçek migration/rollback; D05.5 güvenli extension lifecycle. Harici hesaba yazma veya üretimde restore/deploy yapılmadı. Bağımsız ürün/production kabulü açık.

Ham kanıt D05-1-before/main/mariadb XML/stdout, D05-1-discovery.xml, D05-1-baseline.json ve D05-1-hashes.json. Kontrol `python -X utf8 remediation/verify-d05-publication.py`, yalnız bu adımın kendi kaynak revizyonunda geçerlidir.
