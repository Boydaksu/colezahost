# D05.4 — gerçek migration kontrolü

16.10.2026. Orijinal master plan P17.3/P17.4/P17.9 ve D05-4-KABUL.md üçüncü kontrolü yerel olarak tamamlandı. Dört sabit kontrolün **üçü tamam, biri açık**. D05.4 IN_PROGRESS; production_ready=false, bağımsız kabul PENDING. Önceki iki kontrol yeniden açılmadı.

İmzalı manifestin açık migration sırası gerçek Migrator üzerinde uygulanır; dizin sıralaması sırayı değiştirmez. Yalnız private frozen payload içindeki imzalı/hash'li PHP yüklenir. Sözdizimi tüm liste için etkiden önce kontrol edilir; MigrationInterface gerçek require sonrası zorunludur. Listede olmayan PHP yayınlanabilir fakat çalıştırılmaz. Aynı basename/case variant çakışmaları, bağlı kanıtı olmayan mevcut canonical ledger adı, değiştirilmiş hash ve silinmiş ledger reddedilir. Asıl 26 migration byte olarak aynıdır; yeni kurulum migration'ı veya örtülü şema bootstrap eklenmedi.

Tamamlanma kaydı iki parçalıdır: kurulu migrations ledger ve uygulama ağacı dışındaki SHA256/DB kimliği bağlı receipt. SQL sonrası ledger, sonra receipt tamamlanır. Önce importing niyeti diske fsync ile yazılır; SQL/ledger/receipt arasındaki belirsiz kesinti otomatik tekrarlanmaz. Başarılı ve aynı hash'li tekrar migrations_run=0 verir; sayaç yalnız gerçekten biten yeni işi gösterir. SQLite up+ledger transaction içindedir; top-level PHP de önce journal'a bağlanır. İmzalı PHP güvenilir yayıncı kodudur, bu mekanizma PHP sandbox değildir.

Hedef için exclusive dosya kilidi, DB için ortak kilit vardır. MariaDB canonical Migrator ile aynı named lock'u kullanır; nested canonical çağrının lock sayısı dengelenir. SQLite fiziksel DB yanında ortak dosya kilidi/receipt alanı vardır; DB ve receipt'ler değiştirilecek uygulama ağacının dışında olmalıdır. Memory SQLite yalnız test/ephemeral kullanımdır; kalıcı süreç testi gerçek SQLite dosyası kullanır. MariaDB receipt alanı hedef dışındaki backup storage altındadır. Başka hedef/backup store seçerek yarım DDL korumasını aşmayı önleyen kalıcı DB guard system_settings içindedir; ekstra şema gerekmez.

Zorunlu doğru DB/target yedeği ve yeniden okuma, target drift ve modül kontrolleri DDL'den önce tamamlanır. Recovery modu kalıcı yazılır, dış journal importing/publishing/complete durumlarını taşır. Modül uyumluluğu migration sonrası tekrar okunur. Hata dosyaların yayınlanmasını durdurur; failed journal/DB guard yeniden girişte files-only update'i de engeller. Journal bağlamını alan OperationalModeManager müşteri/yazma işlemini superadmin dahil durdurur. Sistem başarıda bile kendiliğinden Normal moda dönmez.

MariaDB DDL örtülü commit yapabildiği için hata sonrası tablo kalabilir: test gerçekten CREATE TABLE ardından hata verir, tablonun kaldığını ve ledger'ın başarılı saymadığını doğrular. SQLite aynı up işlemini geri alır. Her iki sonuç açık ve ayrı test edilir; MariaDB geri alınmış gibi gösterilmez. Sonraki migration hatasında daha önce biten migration ledger/receipt'i korunur ve gerçek tamamlanan sayı journal'da kalır.

## Doğrulama

- Önceki source üzerinde ilk 11 yeni vaka: 5 error + 5 failure, yalnız bir vaka başarılı. Blanket migration reddi gerçek uygulamanın yerini tutmuyordu. Bu before kanıtı sonradan eklenen 11/12 ek vakayı içermez.
- Son tam ana paket: **2087 test / 13066 assertion**, exit 0; failure/error/skip yok. Yeni 22 SQLite vakası, önceki imzalı paket ve gerçek 52 S3/SFTP protokol/config vakası dahildir.
- Son gerçek izole MariaDB 10.11: **137 test / 1032 assertion**, 12 sınıf; discovery ile sonuç kimlikleri birebir aynı, exit 0; failure/error/skip yok. 22 ortak senaryo ve canonical 26 üzerine 27. signed migration/yedekte eski 26 ledger testi vardır. Bütün MariaDB suite tekrar çalıştırılmış sayılmaz.
- Gerçek ayrı PHP sürecinde exit(73) ile kesinti, yeni süreçle yeniden deneme reddi, iki süreçle hedef kilidi, raw/managed caller transaction korunması, syntax/wrong-type/top-level hata, hash/ledger uyuşmazlığı, case/basename collision, kaynak değişimi, modül sonrası uyumsuzluk ve farklı hedef/store üzerinden guard bypass testleri geçti.
- 14 source/test dosyası son tam testlerden önce sabitlendi; 26 canonical migration önceki commit ile SHA256 olarak eşit. Plan 39 faz/258 alt faz, 97 locked dosya ve whitespace kontrolleri geçti.
- Yeni `.github/workflows/update-migrations.yml` SQLite updater/Migrator ile gerçek MariaDB affected paketini her push/PR'da çalıştırır. MariaDB 10.11 yalnız CI servisi ve test parolasıyla kullanılır; healthcheck resmi [MariaDB belgesindeki](https://mariadb.com/docs/server/server-management/automated-mariadb-deployment-and-administration/docker-and-mariadb/using-healthcheck-sh) `--connect --innodb_initialized` kontrolüdür. Önceki üç transport CI işi korunur. Canlı GitHub sonucu ayrıca incelenir; yerel sonucu Linux sonucu gibi sunmayız.

## Kalan tek D05.4 kontrolü

Aktif ağaca dosya kopyalama henüz staged tree swap değildir. File copy/disk hatası ve kesinti bütün DB+dosyaları otomatik geri almaz. Kalıcı kurulu sürüm/health doğrulaması, eski ağacın korunması, backup'ta olmayan yeni tabloları da temizleyen gerçek rollback ve rollback hata görünürlüğü dördüncü kontrolün konusudur. Bu adımda journal/kilit migration güvenliği için eklenmiştir; dördüncü kontrol tamamlanmış sayılmaz. Başarısız kayıtlar elle silinip otomatik tekrar denenmemelidir; doğrulanmış recovery sonraki kontrolde bağlanır.

Bakımda diğer dosya/DB yazarları durmalıdır; bütün ürün HTTP/CLI/cron/worker fencing D08, privacy kapsamı D07, harici host/hesap ve bağımsız kabul D09'dadır. D05.5 extension lifecycle ayrıca açıktır. Üretim deployment/restore veya harici hesaba yazma yapılmadı.

Kanıt: d05-migrations-before/main/native XML/stdout/exit, discovery, baseline, checks, summary ve hashes. Denetim: `python -X utf8 remediation/verify-d05-migrations.py`; yalnız bu teslimin kaynak byte'ları için geçerlidir.
