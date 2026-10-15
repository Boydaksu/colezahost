# Backup/restore işletim sözleşmesi

D05.3 backend sözleşmesi. Gerçek production host kabulü ve HTTP/cron/worker composition ayrı adımlardır. Production üzerinde bu çalışma sırasında restore yapılmadı.

## Yedekleme

`EnterpriseBackupService` DB/FULL için SQLite veya MariaDB InnoDB'yi destekler. Kendi snapshot transaction'ını açar; çağıranın raw PDO veya Connection transaction'ı açıksa değişiklik yapmadan reddeder. MariaDB'de REPEATABLE READ consistent snapshot ve transaction boyunca metadata kilitleri kullanılır; şema değişirse yayınlama durur. DDL/migration işlemlerini backup sırasında durdurmak gerekir. MyISAM, view, trigger, routine/event desteklenmiyorsa sessiz eksik yedek yerine hata verilir. Bu sürüm genel amaçlı mariadb-dump ithalatçısı değildir.

Şema, FK/index, generated sütun tanımları ve gerçek satırlar saklanır; generated sütunlar INSERT listesinden çıkarılır. MariaDB metin/binary değerleri hex SQL literal, SQLite text `CAST(X'...' AS TEXT)` ve BLOB hex literal olarak yazılır. Unicode/NUL/apostrof/satır sonu/SQL yorumuna benzeyen içerik korunur. SQLite indexleri ve AUTOINCREMENT high-water mark ayrıca saklanır. MariaDB session charset/SQL mode/quote/buffering ayarları geri yüklenir; dump'ın çalıştırılacağı SQL mode manifestte kaydedilir. AUTO_INCREMENT sıfır kimliği korunur.

MariaDB export/import oturumunda UTC kullanılır; TIMESTAMP mutlak zamanı ve mikro-saniye hassasiyeti farklı host saat dilimlerinde kaymaz. DATETIME duvar saati metni korunur. Kaynak ve hedefin önceki session time_zone değeri işlem sonunda geri yüklenir. Bunun nedeni MariaDB'nin TIMESTAMP değerlerini session time zone'a göre dönüştürmesidir: [MariaDB TIMESTAMP](https://mariadb.com/docs/server/reference/data-types/date-and-time-data-types/timestamp).

Tek DB değeri 16 MiB, SQL statement 64 MiB sınırındadır; sınır aşımı publish edilmez. Bu sınırlar çok büyük BLOB kayıtları için ayrı exporter gerektirir. Dump ve restore stream eder; toplam SQL bir string/statement listesi olarak belleğe alınmaz.

Dosya kaynakları tek dosya veya klasör olabilir. Sayısal listedeki kökler basename altında yerleştirilir; string anahtarlar açık göreli alias'tır. Örnek: `['config/first.php' => '/source-a/config.php', 'uploads' => '/source/uploads']`. Alt yollar ve boş dizinler korunur; basename çakışmaları ezilmez. Symlink/özel dosya, okunamayan/değişen kaynak ve taşınabilir olmayan yollar hata verir. Çalışma klasörü recursion'dan dışlanır; doğrudan bu klasördeki açıkça seçilmiş dosyalar desteklenir. Backup deposunu uygulama kaynak ağacının dışında tutmak gerekir. DB snapshot'ı ile dosya sisteminin ortak bir transaction'ı yoktur; tam uygulama anının tutarlı olması için dosya yazarları maintenance ile durdurulmalıdır.

## Geri yükleme

Hedef DB önceden sağlanmış, ayrı ve offline bir staging veritabanı olmalıdır. Varsayılan çağrı nonempty DB'yi reddeder. Uygulamanın mevcut çalışan DB bağlantısını kendiliğinden değiştirmez. Sunucu kullanıcı yetkileri yalnız hedef DB ile sınırlandırılmalıdır. Yalnız güvenilir backup paketleri seçilmelidir; SHA256 checksum ayrı bir imza/kimlik doğrulama değildir.

`inspectBackup` de `executeRestore` da paketin ID/byte size/hash'ini ve ZIP içindeki bütün kayıtları doğrular. Eksik/fazla manifest kaydı, yanlış entry hash, traversal/drive/ADS/backslash, symlink/özel dosya, case veya dosya/dizin çakışması reddedilir. Genel extraction işlevi kullanılmaz; özel stage'e stream edilir. En fazla 100.000 entry ve toplam 10 GiB açılmış içerik kabul edilir. Encryption varsa doğrulanmış cipher payload ayrı özel dosyaya açılır.

SQL önce bütün olarak lexical admission'dan stream edilir; sonra ikinci geçişte uygulanır. Quoted semicolon/newline/doubled quote ve yorumlar ayrıştırılır. Unterminated söz dizimi veya üretilen logical dump komutları dışındaki komutlar ilk mutation'dan önce reddedilir. MariaDB DDL transaction ile geri alınmış sayılmaz. Bir hata halinde gerçek uygulanmış statement sayısı döner; DB kısmen doldurulmuş olabilir. Otomatik destructive replay yapılmaz.

DB restore için format-2 driver/table/row inventory zorunludur. Eski inventory'siz paketler, özellikle geçmiş sürümün comment-only MariaDB dump'ları, boş DB'de başarı sayılmaz; doğrulanmış kaynaktan yeniden export gerekir. PreUpdateBackupService'in bu ortak formatı üretmesi ve update/rollback'e zorunlu bağlanması D05.4 kapsamındadır. Eski inventory'siz DB paketleri bu backend'de otomatik migrate edilmez.

Restore sonunda format-2 DB table/row inventory, SQLite integrity/FK veya MariaDB composite FK orphan kontrolü yapılır. Uygulama key binding ve bilinen bütün cipher/ref kayıtları doğrulanır. Kullanıcı içeren pakette backup'tan bağımsız authoritative tombstone inventory zorunludur; kayıp dosya boş geçmiş sayılmaz. Bilerek boş geçmiş varsa operatörün sağladığı mevcut açık inventory gerekir. Privacy key imzaları ve out-of-band kayıtlar doğrulanır, post-backup silmeler tekrar uygulanır. User/core credentials, consent/session/factor/API ve tanımlı legacy/canonical billing/hosting/support alanları için mapping/write/readback hatası başarı sayılmaz. Genel ürün PII/retention/legal-hold envanteri ve bütün domain handler sözleşmeleri D07 kapsamındadır; bilinmeyen schema'lar güvenli biçimde durdurulur.

Mevcut hedef dosyalar önce özel sibling ağaca kopyalanır. Yedek dosyalar orada hazırlanır. DB/key/privacy/health başarılı olmadan çalışan hedef değiştirilmez. Başarıda eski hedef `TARGET.previous_RANDOM` olarak korunur, hazırlanan ağaç rename ile geçer. Bu iki rename ortak DB transaction'ı değildir; DB/folder geçiş aralığı Recovery gate altında tutulmalıdır. Eski ağaç operatör incelemesi tamamlanmadan silinmez.

## Kesinti ve açılış kapısı

Journal hedef klasörün dışında `PARENT/.TARGET.restore-state.json` dosyasıdır. Sibling dosya kilidi aynı target'a, MariaDB advisory lock aynı DB'ye iki restore'u engeller. Preparing/importing/verifying/publishing durumları, fail ve complete kaydı diske flush/fsync edilip atomik dosya geçişiyle yayınlanır. Kaydın içinde sır/SQL satırı bulunmaz.

Uygulama girişleri `OperationalModeManager`'ı aynı journal yolu ile oluşturmalıdır: üçüncü `restoreJournalPath` argümanı. Tamamlanmamış journal varsa DB schema bootstrap/read gerektirmeden Recovery döner, müşteri ve mutasyonlar (super-admin dahil) engellenir; Normal'e geçiş reddedilir. Bu aynı binding HTTP/CLI/cron/worker başlangıcında D08 composition işidir. Başarılı restore DB'de ayrıca kalıcı RECOVERY bırakır; kendiliğinden Normal/customer/cron açmaz. Açılış öncesi yetkili operatör hedef, anahtarlar, authoritative privacy inventory ve sağlık sonuçlarını ayrıca kabul etmelidir.

Kesintide journal/önceki klasör/yedek paketi korunur. Yarım DB'yi mevcut uygulamaya bağlamayın. Yeni boş staging DB ve ayrı hedefle yeniden restore en güvenli yoldur. Aynı hedefte journal'ı kaldırıp devam etmek otomatik API davranışı değildir; eski ve yeni dizinlerin gerçek durumunu, çalışan süreç olmadığını ve DB'nin taze olduğunu operatör uzlaştırmalıdır. Kaynak yedek ve authoritative tombstone store bu işlemden bağımsız tutulur.

`replaceExistingDatabase: true` yalnız mevcut rollback bağlamının açık uyumluluk yoludur; mevcut tabloların destructive DDL ile değişebileceğini ve backup'ta bulunmayan tabloların korunacağını açıkça kabul eder. Default fresh DB kapısı kaldırılmaz. Atomik pre-update snapshot/deploy/rollback orchestration ve bu yolun ürün akışı D05.4'te tamamlanacaktır. Restore başarısı production_ready anlamına gelmez.
