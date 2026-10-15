# S3/SFTP aktarım sözleşmesi

Bu belge D05.2 backend adapter ayarlarını açıklar. Yönetim ekranı/HTTP bağlantısı D08 kapsamındadır. Gerçek hesap bilgileri kaynak koda veya bu belgeye yazılmaz; uygulamanın güvenli yapılandırma/Vault hattından sağlanmalıdır.

## Ortak davranış

Üretimde varsayılan gerçek aktarımdır. `simulated: true` yalnız test içi bellek deposunu seçer. Boş ayarlar hata verir. PHP cURL uzantısı ve seçilen protokol desteği gerekir; S3 SigV4, SFTP SHA256 host pin desteği ayrıca kontrol edilir. Python/Paramiko yalnız geliştirme test sunucularında kullanılır; uygulama bağımlılığı değildir.

Arşiv ve manifest önce yerelde doğrulanır. Uzakta yedek ID'sine özel kilit alındıktan sonra mevcut paket kontrol edilir. Aynı byte'larla tekrar idempotenttir; farklı veya yarım eski paket ezilmez. Arşiv aktarılıp geri okunarak doğrulanır; manifest son yayınlama işaretidir ve byte'ları da geri okunur. `retrieve` her iki dosyayı özel geçici dizine indirir, ID/size/SHA256 doğrular ve Local adapter üzerinden hedefe yayınlar. Eksik çift false, bozuk çift hata verir. `exists` ve `listBackups` tam çiftin içeriğini doğrular; sahte/yarım paket başarı sayılmaz. `delete` doğruladığı çiftin önce manifestini, sonra arşivini siler; hata başarı sayılmaz.

Doğrulama arşivleri stream ederek diske indirir; büyük yedeklerde ağ ve geçici disk maliyeti vardır. Geçici dosyalar yalnız bu işin rastgele özel dizininden temizlenir. Bu sürüm checksum bütünlüğünü ve sunucu kimliğini doğrular; manifest checksum'u ayrı bir içerik imzası değildir. İstenirse mevcut authenticated AES-GCM yedek şifrelemesi aktarım öncesinde uygulanır.

`timeout_seconds`: tam sayı 1–600, varsayılan 120; bağlantı zaman aşımı en fazla 10 saniye. `max_archive_bytes`: pozitif tam sayı, varsayılan 10 GiB indirme sınırı. Manifest 4 MiB, liste yanıtı 8 MiB ile sınırlıdır. Boyut/zaman sınırı aşımı başarı değildir. S3 tek PutObject kullanır ve yazma üst sınırı 5 × 1024³ byte'tır; multipart bu sözleşmeye dahil değildir.

Kilitler: S3 koşullu `If-None-Match: *` PUT ile `ID.lock` nesnesi, SFTP atomik `mkdir ID.lock` ile dizin kilidi. Aynı uygulamanın bağımsız istemcileri bu kilide uyar. Uzak klasör/prefix bu yedek deposuna ayrılmalı; dış yazıcıların aynı dosyalara kontrolsüz yazmasına izin verilmemelidir. S3-compatible sunucu koşullu PUT semantiğini desteklemelidir. SFTP directory/rename/remove hakları gerekir.

Çökme/bağlantı kaybı eski kilit veya tek arşiv bırakabilir. Kilitlerin süresi otomatik dolmaz; canlı yazıcının kilidi ele geçirilmez. ID yeniden kullanımı açıkça durdurulur; yeni ID ile yeni yedek alınabilir. Operatör canlı iş olmadığını doğrulayıp paketi bağımsız kopyaya aldıktan sonra yalnız ilgili ID'nin kilidini/yarım nesnesini uzlaştırmalıdır. Listeleme bunları tamamlanmış yedek göstermez. SFTP `.upload_*` dosyaları başarısızlıkta temizlenmeye çalışılır; bağlantı yoksa kalabilir ve yayınlanmış sayılmaz. Üretim verisine otomatik toplu temizlik uygulanmaz.

Saklama politikası isteğe bağlıdır. Tüm adaylar listelenip bağımsız geri okuma ile doğrulanmadan silme başlamaz; bozuk geçmişte işlem durur. Tarihsiz geçmiş bilinmeyen kabul edilir ve korunur. Yeni teslim edilen ID aynı tarih/saat kaymasında bile korunur; yerel kaynak temizliği teslim ve varsa saklama işlemi sonrasında yapılır.

## S3BackupStorageAdapter yapılandırması

| Alan | Sözleşme |
|---|---|
| `bucket` | Zorunlu bucket adı; adapter bucket yaratmaz, ACL değiştirmez. |
| `region` | Zorunlu imza bölgesi. |
| `access_key`, `secret_key` | Zorunlu SigV4 kimliği; hata metinlerine alınmaz. |
| `session_token` | Geçici AWS kimlikleri için opsiyonel; yenileme sorumluluğu çağırandadır. |
| `endpoint` | Opsiyonel HTTPS origin. Varsayılan `https://s3.REGION.amazonaws.com`. URL'de kullanıcı/şifre, query, fragment veya alt yol kabul edilmez. Redirect izlenmez. |
| `prefix` | Opsiyonel ayrılmış yol; Unicode/boşluk destekli, `..`/kontrol karakterleri yasak. |
| `ca_bundle` | Opsiyonel özel CA dosyası. Verilmezse cURL güven zinciri kullanılır. TLS peer/hostname kontrolü kapatılamaz. |

Path-style istekler kullanılır; directory bucket/S3 Express, multipart ve otomatik region redirect bu adapter'ın kabul edilen sözleşmesi değildir. S3-compatible uç noktalarda gerçek hesap kabulü ayrıca yapılmalıdır. İzinler ayrılmış prefix üzerinde GetObject, PutObject, DeleteObject ve ListBucket işlemlerini, kilit nesneleri dahil kapsamalıdır. ListObjectsV2 bütün sayfaları takip eder; döngülü/eksik token ve güvenli sınır aşımları açık hata verir. XML dış varlık/DOCTYPE kabul edilmez.

## SftpBackupStorageAdapter yapılandırması

| Alan | Sözleşme |
|---|---|
| `host`, `username` | Zorunlu sunucu DNS/IPv4 adı ve kullanıcı. |
| `port` | Opsiyonel tam sayı; varsayılan 22. |
| `remote_path` | Önceden var olan mutlak ayrılmış dizin. Unicode/boşluk destekli; home expansion, `..`, quote/backslash/kontrol karakterleri yasak. |
| `host_key_sha256` | Zorunlu, güvenilir ayrı kanaldan alınmış sunucu public key SHA256 Base64 izi. OpenSSH `SHA256:` öneki ve padding'siz biçim de kabul edilir. İlk bağlantıda öğrenip otomatik güvenme yoktur. |
| `password` | Parola yöntemi seçilecekse zorunlu, boş olamaz. |
| `private_key` | Parola verilmediyse zorunlu okunabilir özel anahtar dosyası yolu. |
| `public_key` | cURL/SSH backend'i gerektiriyorsa eş public key dosyası. |
| `private_key_passphrase` | Şifreli private key için opsiyonel parola. |

Kimlik yöntemi açık seçilir; agent/keyboard-interactive fallback yapılmaz. Gerçek sunucunun key biçimi ve şifreleme algoritmaları kurulu cURL/SSH backend desteğiyle ayrıca kabul edilmelidir. Sunucu host key rotasyonunda yeni iz güvenilir kanaldan doğrulanıp yapılandırma güncellenir; eski/yeni uyuşmazlığı başarı sayılmaz.

## Yerel gerçek protokol testlerini çalıştırma

Python 3.11+ ortamına `tests/Support/backup-protocol-requirements.txt` kurulur. İzole ortam kullanılıyorsa Python executable'ın mutlak yolu `COLEZA_BACKUP_TEST_PYTHON` değişkeninde verilir; yoksa `python` kullanılır. Ardından `vendor/bin/phpunit tests/Integration/Backup/RemoteBackupTransportTest.php` çalıştırılır. PHPUnit main paketi de bu testleri içerir; bağımlılık eksikse skip ile gizlenmez.

Fixture yalnız 127.0.0.1'de dinler. HTTPS sertifikası, SSH host/client private key'leri her çalışmada geçici üretilir, işlem bitince silinir. S3 uç noktası SigV4 canonical request/HMAC/region/date/payload hash'ini denetler; SFTP sunucusu Paramiko ile gerçek SSH authentication ve SFTP dosya komutlarını işler. İstemci çağrıları taklit edilmez. CI workflow `.github/workflows/backup-transports.yml` aynı protokol sınıfını Linux'ta koşacak şekilde eklenmiştir; yerelde gözlenen sonuç CI'nın çalıştığı iddiası değildir.

Harici AWS/S3-compatible ve bağımsız SFTP hesabında ayrı kabul **PENDING**. Yerel fixture kanıtı bir AWS hesabında, müşteri hostunda veya production ortamında başarı iddiası değildir.
