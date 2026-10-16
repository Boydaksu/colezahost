# D05 — backup, restore, update ve extension

Birincil kaynak: orijinal master plan P17.3–P17.9 ve extension sözleşmeleri; review/INCELEME.md F10/F11/F12/F13/F20/F31. D02/D03/D04 kapanışları korunur. Beş sabit yerel uygulama adımı vardır; bağımsız ürün kabulü D09'dadır.

| Adım | Kapsam | Çıkış ölçütü |
|---|---|---|
| D05.1 | Yedek teslimi ve yerel kopya güvenliği | False/exception/eksik veya bozuk read-back başarı olmaz; doğrulanmış ikinci kopyadan önce yerel paket silinmez. Local yayınlama/ID/path/manifest doğrulaması ve retention güvenliği. Desteklenmeyen gerçek transport veya DB dump açık hata verir. |
| D05.2 | Gerçek S3 ve SFTP aktarımı | Gerçek transport, kimlik/TLS/host key doğrulaması; aktarım/read-back/list/delete; yerel protokol sunucularında arıza testleri. Harici hesap/sandbox kabulü ayrı kaydedilir. |
| D05.3 | DB/files dump, restore ve privacy recovery | MariaDB consistent dump; recursive göreli path/binary/Unicode/SQL ayrıştırma; taze hedefe staged restore, kesinti/Recovery mode ve zorunlu tombstone/key doğrulaması. |
| D05.4 | Güncelleme ve rollback | Tek imzalı paket sözleşmesi; zorunlu doğrulanmış pre-update backup; gerçek migration; staged dosya geçişi/hata recovery/rollback. |
| D05.5 | Güvenli extension kurulumu | Quarantine/path/symlink/zip-bomb/uyumluluk/izinler; migration preview/apply, health ve enable; başarısızlık/recovery matrisi. |

D05.1–D05.3 yerel uygulama/doğrulaması tamamlandı. Şu an D05.4; D05.4/D05.5 olmak üzere iki adım açık. D05.4 sabit dört kontrolünün ilk üçü tamamlandı: gerçek pre-update backup, ortak imzalı paket/zorunlu bağlı yedek ve gerçek signed-order migration. Kalıcı hash/ledger, process-death ve partial-DDL recovery reddi doğrulandı. Yalnız staged dosya geçişi/version/health/gerçek rollback kontrolü açıktır. Son kanıt D05-4-MIGRATIONS-SONUC.md; önceki yedek ve signed kabul raporları tarihsel olarak korunur. D05.4 kapanmadı, production_ready=false. Bütün ürün girişlerinin journal bağlaması D08, bütün ürün privacy mapping D07, harici hesap/host ve bağımsız kabul D09 kapsamındadır.

D05.2 teknik birincil referansları: [cURL AWS SigV4](https://curl.se/libcurl/c/CURLOPT_AWS_SIGV4.html), [cURL SSH host key SHA-256](https://curl.se/libcurl/c/CURLOPT_SSH_HOST_PUBLIC_KEY_SHA256.html), [AWS ListObjectsV2](https://docs.aws.amazon.com/AmazonS3/latest/API/API_ListObjectsV2.html). Uygulama/yerel protokol kanıtı D05-2-SONUC.md; harici hesap kabulü ayrı ve açıktır.

16.10.2026 D05.4 ilerlemesi: **3/4 kontrol tamam; tek açık kontrol staged yayınlama ve rollback**. Yeni migration/SQLite/MariaDB otomatik GitHub kabul işi eklenmiştir. Extension lifecycle D05.5 ayrıca açıktır. Kapsamı büyüten yeni faz eklenmedi.
