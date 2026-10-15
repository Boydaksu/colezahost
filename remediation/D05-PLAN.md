# D05 — backup, restore, update ve extension

Birincil kaynak: orijinal master plan P17.3–P17.9 ve extension sözleşmeleri; review/INCELEME.md F10/F11/F12/F13/F20/F31. D02/D03/D04 kapanışları korunur. Beş sabit yerel uygulama adımı vardır; bağımsız ürün kabulü D09'dadır.

| Adım | Kapsam | Çıkış ölçütü |
|---|---|---|
| D05.1 | Yedek teslimi ve yerel kopya güvenliği | False/exception/eksik veya bozuk read-back başarı olmaz; doğrulanmış ikinci kopyadan önce yerel paket silinmez. Local yayınlama/ID/path/manifest doğrulaması ve retention güvenliği. Desteklenmeyen gerçek transport veya DB dump açık hata verir. |
| D05.2 | Gerçek S3 ve SFTP aktarımı | Gerçek transport, kimlik/TLS/host key doğrulaması; aktarım/read-back/list/delete; yerel protokol sunucularında arıza testleri. Harici hesap/sandbox kabulü ayrı kaydedilir. |
| D05.3 | DB/files dump, restore ve privacy recovery | MariaDB consistent dump; recursive göreli path/binary/Unicode/SQL ayrıştırma; taze hedefe staged restore, kesinti/Recovery mode ve zorunlu tombstone/key doğrulaması. |
| D05.4 | Güncelleme ve rollback | Tek imzalı paket sözleşmesi; zorunlu doğrulanmış pre-update backup; gerçek migration; staged dosya geçişi/hata recovery/rollback. |
| D05.5 | Güvenli extension kurulumu | Quarantine/path/symlink/zip-bomb/uyumluluk/izinler; migration preview/apply, health ve enable; başarısızlık/recovery matrisi. |

D05.1–D05.3 yerel uygulama/doğrulaması tamamlandı (D05-1/2/3-SONUC.md). D05.3 sabit kontrol listesi D05-3-KABUL.md; desteklenen formatta gerçek MariaDB/SQLite export, recursive files, fresh offline staged restore, interruption journal, key/privacy/health kapıları yerelde doğrulandı. Sonraki D05.4; D05.4/D05.5 olmak üzere iki adım açık. PreUpdateBackupService'in ortak zorunlu verified backup hattına bağlanması ve atomik update/rollback D05.4'tedir. Journal'ın bütün ürün girişlerine bağlanması D08, bütün ürün privacy mapping D07, harici hesap/host ve bağımsız kabul D09 kapsamındadır. Üretime deploy/restore veya harici hesaba yazma yapılmadı.

D05.2 teknik birincil referansları: [cURL AWS SigV4](https://curl.se/libcurl/c/CURLOPT_AWS_SIGV4.html), [cURL SSH host key SHA-256](https://curl.se/libcurl/c/CURLOPT_SSH_HOST_PUBLIC_KEY_SHA256.html), [AWS ListObjectsV2](https://docs.aws.amazon.com/AmazonS3/latest/API/API_ListObjectsV2.html). Uygulama/yerel protokol kanıtı D05-2-SONUC.md; harici hesap kabulü ayrı ve açıktır.
