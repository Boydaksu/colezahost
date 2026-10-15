# D05 — backup, restore, update ve extension

Birincil kaynak: orijinal master plan P17.3–P17.9 ve extension sözleşmeleri; review/INCELEME.md F10/F11/F12/F13/F20/F31. D02/D03/D04 kapanışları korunur. Beş sabit yerel uygulama adımı vardır; bağımsız ürün kabulü D09'dadır.

| Adım | Kapsam | Çıkış ölçütü |
|---|---|---|
| D05.1 | Yedek teslimi ve yerel kopya güvenliği | False/exception/eksik veya bozuk read-back başarı olmaz; doğrulanmış ikinci kopyadan önce yerel paket silinmez. Local yayınlama/ID/path/manifest doğrulaması ve retention güvenliği. Desteklenmeyen gerçek transport veya DB dump açık hata verir. |
| D05.2 | Gerçek S3 ve SFTP aktarımı | Gerçek transport, kimlik/TLS/host key doğrulaması; aktarım/read-back/list/delete; yerel protokol sunucularında arıza testleri. Harici hesap/sandbox kabulü ayrı kaydedilir. |
| D05.3 | DB/files dump, restore ve privacy recovery | MariaDB consistent dump; recursive göreli path/binary/Unicode/SQL ayrıştırma; taze hedefe staged restore, kesinti/Recovery mode ve zorunlu tombstone/key doğrulaması. |
| D05.4 | Güncelleme ve rollback | Tek imzalı paket sözleşmesi; zorunlu doğrulanmış pre-update backup; gerçek migration; staged dosya geçişi/hata recovery/rollback. |
| D05.5 | Güvenli extension kurulumu | Quarantine/path/symlink/zip-bomb/uyumluluk/izinler; migration preview/apply, health ve enable; başarısızlık/recovery matrisi. |

D05.1 yerel uygulama/doğrulama tamamlandı (D05-1-SONUC.md). Sonraki D05.2; dört adım açık. Bu ilk adım uzaktaki gerçek bir hesabın veya MariaDB dump/restore'un çalıştığı anlamına gelmez; bunlar D05.2/D05.3'ün açık çıkış ölçütleridir. Desteklenmeyen yollar sahte başarı yerine hata vermelidir. Üretime deploy, production restore veya harici hesaba yazma yapılmaz.

D05.2 teknik birincil referansları: [cURL AWS SigV4](https://curl.se/libcurl/c/CURLOPT_AWS_SIGV4.html), [cURL SSH host key SHA-256](https://curl.se/libcurl/c/CURLOPT_SSH_HOST_PUBLIC_KEY_SHA256.html), [AWS ListObjectsV2](https://docs.aws.amazon.com/AmazonS3/latest/API/API_ListObjectsV2.html). Bu referansların okunması gerçek transport kabulü değildir.
