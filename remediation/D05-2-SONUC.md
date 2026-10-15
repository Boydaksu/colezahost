# D05.2 — gerçek S3 ve SFTP aktarımı

15.10.2026. Başlangıç cbb4dd91569ae62aab214c19368966f67c368ea7. Birincil master plan P17.5, inceleme F10. D05.2 yerel uygulama/doğrulaması tamamlandı. D02/D03/D04 kapanışları ve 26 kanonik migration korunur. Sabit planın sonraki adımı D05.3; üç adım kaldı. F11 consistent MariaDB dump bu adımın tamamlandığı anlamına gelmez.

## Başlangıç ve sonuç

İki başlangıç regresyonu iki failure/0 error verdi: gerçek S3 ve SFTP adapter'ları konfigürasyonla dahi aktarım yerine unimplemented exception dönüyordu. İki protokolün upload, yeni istemciyle exists/list/retrieve/byte karşılaştırma/delete ve eksik paket davranışını kapsayan aynı yaşam döngüsü testleri şimdi geçer. Before XML/stdout korunur.

Üretim adapter'ları gerçek PHP cURL kullanır. S3 path-style HTTPS isteklerini açık region/service ile SigV4 imzalar; içerik SHA256 ve opsiyonel session token destekler. TLS peer/hostname kontrolü zorunludur, redirect izlenmez. SFTP gerçek SSH üzerinden zorunlu SHA256 host public key pin ile, açık password veya private key yöntemi kullanır; agent fallback yoktur. Boş/uygunsuz yapılandırma gerçek bağlantı kurulmadan reddedilir. Secret/server response body'leri hata metnine taşınmaz. Production SDK/shell veya Python bağımlılığı eklenmedi.

RemoteBackupRepository iki protokolde paket bütünlüğünü ve yayınlama sırasını birleştirir. Archive source hash/size/ID doğrulanır; uzakta per-ID mutation lock alınır. S3 conditional PUT, SFTP atomik mkdir kullanır. Aynı byte retry idempotent, farklı/yarım eski ID çatışması açık hatadır. Archive upload/read-back hash kontrolünden sonra manifest yayınlanıp byte karşılaştırması yapılır. Service ayrıca bağımsız özel dizine tam paketi tekrar okuyarak kaynak hash ve manifest byte'larını karşılaştırır. SFTP geçici upload adı ve final rename kullanır. Manifest son yayınlama işaretidir.

Retrieve önce rastgele özel dizine stream ederek indirir; eksik çift false, bozuk çift hata verir. Tam doğrulanmadan hedef dosyaları yayımlanmaz; Local adapter ile mevcut hedef collision koruması kullanılır. Exists/list tam paketleri doğrular, geçici/lock/yarım dosyaları tamamlanmış saymaz. S3 ListObjectsV2 tüm sayfaları takip eder, tekrar token/boyut sınırı/dış XML varlıkları reddeder. Delete doğrulanmış çiftte önce manifesti sonra archive'ı siler; başarısızlık bildirilir. Geri okuma, listeleme ve history doğrulaması ağ/disk maliyetlidir; bellekte tüm archive tutulmaz.

Automatic retention artık geçmişteki paketleri bağımsız okuyup doğrulamadan silme başlatmaz. Eksik tarihli geçmiş bilinmeyen olarak korunur; bozuk geçmiş silmeyi durdurur. Yeni teslim edilen ID timestamp eşitliği veya gelecekte görünen eski kayıt karşısında açık protected set ile korunur. Non-local kaynak temizliği teslim ve varsa retention sonrasındadır; başarısızlıkta kaynak kalır.

D05.1'deki temporary koruma testinin üç yeni yedeği süresiz tutma beklentisi yeni verified-history özelliği nedeniyle güncellendi: her create sonucunda yalnız en son doğrulanmış ID'nin saklandığını kontrol eder. Eski bilinmeyen geçmiş ve son eski kopyayı koruyan test değişmedi. Gelecek tarihli eski paket, gerçek iki protokolde bozuk geçmiş ve verified tarihli geçmiş pruning regresyonları ayrıca eklendi. Başarısızlığı gizlemek için bilinmeyen geçmiş koruması kaldırılmadı.

## Gerçek yerel protokol doğrulaması

- Tam ana paket: **1959 test / 12765 assertion**; failure/error/skip sıfır.
- Ana paketin içinde **35 gerçek protokol vakası / 127 assertion** ve **17 uygunsuz yapılandırma vakası** vardır.
- Gerçek izole MariaDB 10.11, exact discovery ile üç ilgili sınıf: **16 test / 119 assertion**. Tüm native suite tekrar çalıştırılmadı.
- Gerçek HTTPS sunucusu SigV4 canonical request, HMAC, region/service, tarih ve payload SHA256 denetler. Üretilmiş CA ile TLS uygulanır. Gerçek Paramiko SSH/SFTP sunucusu password/public-key doğrulaması ve dosya komutlarını işler. İstemci cURL çağrıları mock değildir. Sunucular yalnız loopback dinler; anahtar/sertifikalar geçicidir.
- Bağımsız istemcilerde upload/list/readback/delete/missing; exact binary/Unicode byte koruma; Unicode/boşluk içeren remote path; S3 iki nesnelik pagination; aynı ID retry/collision; iki ayrı PHP writer; mevcut lock reddi; yanlış parola/SigV4 secret; yanlış host pin; güvenilmeyen CA/yanlış hostname; reddedilen archive/manifest yazımı; bozuk/kesik download; indirme boyut limiti; yarım paket görünmezliği; delete reddi; verified history retention ve bozuk history'de sıfır silme geçer.
- Başarısız store/readback senaryolarında ZIP+manifest source çifti ve önceki good paket korunur; retention çalışmış gibi başarı üretilmez.
- Yeni workflow Linux'ta protokol sınıfını zorunlu koşacak şekilde eklendi. Bu rapor yerel sonuçtur; GitHub CI sonucu gözlenmeden CI geçti denmez. Ana pakette fixture bağımlılığı eksikse skip yapılmaz.

## Sınırlar ve sonraki adım

Harici AWS/S3-compatible ve bağımsız SFTP hesabında kabul PENDING; müşteri hesabına veya production ortama yazılmadı. Mevcut SSH key algoritması, S3-compatible conditional PUT davranışı ve gerçek sunucu izinleri dış kabulde ayrıca denenir. UI/HTTP configuration composition D08 kapsamındadır. Ürün/production bağımsız kabulü açık kalır.

S3 single PutObject yazma sınırı 5 × 1024³ byte; multipart ve directory bucket bu sözleşmede yoktur. Varsayılan archive download limiti 10 GiB, manifest 4 MiB, liste yanıtı 8 MiB ve timeout 120 saniyedir; limitler açık hata verir. SHA256 manifest checksum'u ayrı bir içerik imzası değildir; authenticated AES-GCM şifreleme üstteki mevcut seçenektir.

Crash/network kaybı eski lock/yarım archive bırakabilir. Kilitler otomatik eskimiş sayılmaz, ID sessizce tekrar kullanılmaz. SFTP upload orphan'ı yayınlanmış sayılmaz. Operatör uzlaştırma adımları ve ayrılmış remote namespace gereği `D05-2-TRANSPORT-KULLANIM.md` dosyasındadır; toplu otomatik silme eklenmedi.

D05.3 consistent MariaDB dump, recursive göreli dosyalar, staged restore/privacy/key recovery; D05.4 mandatory verified pre-update backup/gerçek migration/rollback; D05.5 güvenli extension lifecycle açık. Önceki MariaDB DB/FULL admission reddi, gerçek dump tamamlanıncaya kadar korunur.

## Tekrarlanabilir kanıt

PHP çalıştırıcısı: `C:/ServBay/packages/php/current/php.exe -c C:/ServBay/etc/php/current/php.ini`. Python fixture yolu test sırasında proje içindeki izole `remediation/runtime/backup-python/Scripts/python.exe` ortamına `COLEZA_BACKUP_TEST_PYTHON` ile verildi; requirements dev-only olarak sürüm sabitlidir.

- Başlangıç: PHPUnit `tests/Integration/Backup/RemoteBackupTransportTest.php --filter testRealLifecycle --log-junit remediation/D05-2-before.xml`; 2 assertion failure.
- Final main: PHPUnit `--colors=never --cache-directory .phpunit.cache/d05-2-main --log-junit remediation/D05-2-main.xml`; OK ve JUnit sıfır failure/error/skip.
- Native discovery/run: PHPUnit `-c phpunit-mariadb.xml --filter 'BackupAdmissionTest|InstallationAcceptanceTest|SqlPortabilityCloseoutTest'`; discovery XML ID'leri sonuç XML ID'leriyle birebir karşılaştırılır. İzole port 33079; mevcut normal sunucuya dokunulmaz.
- Kalıcı kontrol: `python -X utf8 remediation/verify-d05-transports.py`; yalnız kayıtlı D05.2 source revizyonunda geçerlidir.
- Ham before/main/mariadb XML/stdout, discovery, source/migration SHA256 baseline, summary ve final dosya hash manifesti saklanır. Son testler boyunca source/test byte'ları değişmemiştir.

Birincil teknik kaynaklar: [cURL SigV4](https://curl.se/libcurl/c/CURLOPT_AWS_SIGV4.html), [cURL SSH SHA256 pin](https://curl.se/libcurl/c/CURLOPT_SSH_HOST_PUBLIC_KEY_SHA256.html), [cURL SFTP commands](https://curl.se/libcurl/c/CURLOPT_QUOTE.html), [AWS PutObject](https://docs.aws.amazon.com/AmazonS3/latest/API/API_PutObject.html), [AWS ListObjectsV2](https://docs.aws.amazon.com/AmazonS3/latest/API/API_ListObjectsV2.html).
