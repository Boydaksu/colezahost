# D05.4 — güncelleme öncesi gerçek yedek

D05.4 devam ediyor; dört sabit kontrolden ilkini tamamlayan ara teslimdir. Sonraki iş imzalı paketin dondurulması ve atlanamayan, doğru veritabanı/uygulama ağacına bağlı yedek kapısıdır. Gerçek migration ve staged dosya geçişi/rollback ardından gelir. Kapsam D05-4-KABUL.md içinde sabittir.

PreUpdateBackupService'in ikinci, eksik dump uygulaması kaldırıldı. Ortak EnterpriseBackupService, LogicalDatabaseDump ve BackupFileTree üzerinden format-2 consistent database snapshot ve istenen recursive dosyalar üretilir. Tam pre_update manifest'i hedef sürüm bilgisiyle teslimden önce oluşturulur; yayınlanmış manifest sonradan değiştirilmez. DB bağlantısı zorunludur. Sürüm değeri yol oluşturmaz; çakışmaya karşı ortak rastgele paket kimliği ve mevcut paketi koruyan storage sözleşmesi kullanılır. Eksik kaynak, basename/alias çakışması, unsupported engine/object veya caller transaction açık hatadır; eksik yedek başarı sayılmaz.

Paket bağımsız klasöre tekrar alınır; kimlik/boyut/ZIP hash kontrolünden sonra tam entry üyeliği ve her iç dosyanın hash/boyutu BackupArchive ile doğrulanır. Format-2 DB envanteri, MariaDB UTC metadata'sı ve dump'ın tam lexical parse/table-row toplamları kontrol edilir. Eksik envanterli eski/comment-only paketler recovery kanıtı olamaz. Bu kontrol genel SQL semantik doğrulayıcısı veya her yedekte otomatik deneme restore değildir; gerçek restore bu hizmetin ürettiği paketlerle regresyon testlerinde yapılır.

## Kanıt

Düzeltme öncesi 9 regresyonun 7'si başarısızdı: DB olmadan dosya-only pre-update başarı, eksik kaynağın atlanması, basename üzerine yazma, aynı saniye paket çakışması, restore formatı/recursive kayıp, iç checksum'un yok sayılması ve legacy comment-only kabulü. Version/path negatif kontrolleri de kayıtlıdır. Nihai ana pakette bunlara ek olarak geçerli format-2 envanterinin comment-only dump'a taşınması reddedilir.

- Tam ana paket: **2002 test / 12864 assertion**, exit 0, failure/error/skip yok. Yeni 10 pre-update safety vakası ve mevcut 35 gerçek S3/SFTP protokol testi dahildir.
- İzole MariaDB 10.11: **99 test / 804 assertion**, exit 0; discovery ile aynı exact 9 class/99 case. Bütün native paket sayılmaz.
- Yeni 3 native vaka: gerçek pre-update paketi → taze DB/files restore; Türkçe/emoji/apostrof/backslash/binary/decimal/generated column ve +03:00 kaynaktan UTC timestamp round-trip; MyISAM reddi; caller transaction'ın açık kalması ve rollback sonrası boş DB. Mevcut backup/recovery/installation/key/privacy/mode/SQL regresyonları da geçti.
- SQLite gerçek restore: recursive dosyalar/boş dizinler, text/binary değerler ve explicit index korunur.
- 26 canonical migration byte olarak değişmedi. Kaynak/testler son çalışmalardan önce donduruldu. Ana testler için yalnız yerel dev protocol fixture, native için yalnız 33079 portundaki izole test DB kullanıldı.

## Açık sınırlar

Updater'ın requireBackup=false yolu, manifest'i doğrulamadan sonra yeniden okuması, yalnız sayım yapan migration döngüsü ve aktif ağaca doğrudan dosya kopyalaması bu teslimde düzeltilmedi. D05.4 kapanmış veya güncelleme güvenli kullanıma hazır değildir. Bu paket otomatik uygulama ağacı seçmez: extraFilePaths açıkça seçilir; boş liste tam uygulama yedeği anlamına gelmez. Yedek alınırken dosya/DDL yazarları durdurulmalıdır. Privacy/key restore kapıları ve D07/D08/D09 sınırları korunur; deployment/üretim restore/harici hesaba yazma yapılmadı.

Kanıt denetimi: `python -X utf8 remediation/verify-d05-preupdate-backup.py`. Bu denetim bu teslimin kaydedilmiş kaynak byte'ları içindir. Ham before/main/native XML/stdout/exit dosyaları, discovery, baseline, summary, plan/locked-manifest/whitespace kontrolleri ve son byte hash'leri D05-4-prebackup-* dosyalarında bulunur. İlk sandbox test girişimi Windows PHP tarafından mevcut vendor/autoload.php dosyasının okunamaz görülmesiyle çalışamadı; aynı testler normal dosya erişimiyle yeniden başlatıldı ve yalnız gerçek tamamlanan koşular kanıta alındı.
