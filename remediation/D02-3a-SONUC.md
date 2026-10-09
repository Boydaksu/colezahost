# D02.3a — Atomik kapasite ve bildirim SQL'i

Tarih: 09.10.2026. Uygulama tamamlandı; bağımsız kabul bekliyor. D02 açık, sonraki adım D02.3b.

## Değişen davranış

- ServerService kota artırma/azaltma, kullanım ve limit güncellemeleri transaction içinde sunucu kilidi alıyor. Aktif durum ve hesap/disk/bant genişliği başlığı yeniden kontrol edilmeden kapasite tüketilemiyor. MariaDB azaltma sorgusu GREATEST, SQLite MAX kullanıyor.
- Sayaç ile rezervasyon oluşturma/bırakma aynı transaction'da. Kayıt veya sayaç hatası ikisini birlikte geri alıyor. Aynı rezervasyonun tekrar bırakılması başka rezervasyonun kotasını azaltmıyor. Doğrudan sayaç azaltma ve kullanım senkronizasyonu da aktif rezervasyon tabanının altına inemiyor. Yapılandırılmış limitler mevcut tahsislerin altına düşürülemiyor.
- Hizmet ve sipariş kalemi için ayrı UNIQUE aktif kapsam anahtarları kullanılıyor. Aynı kaynaklarla tekrar istek mevcut token'ı döndürüyor; farklı kaynak veya hizmet bağlama talebi reddediliyor. Sipariş kalemi anahtarı hizmete commit sonrasında da korunuyor. Bırakılınca anahtarlar serbest kalıyor.
- Rezervasyon miktarları pozitif hesap/nonnegative kaynak ve pozitif TTL gerektiriyor. Bağımsız commit çağrısında süresi dolmuş rezervasyon bırakılıp işlem tamamlandıktan sonra hata döndürülüyor; bu bırakma exception nedeniyle kendi transaction'ında geri alınmıyor. Dış bir transaction varsa onun genel rollback sözleşmesi geçerli. Süre taraması kilit altında durumu tekrar kontrol ediyor; bu arada committed olmuş rezervasyonu bırakmıyor.
- Rezervasyon kilit sırası sunucu → rezervasyon. Kapsam oluşturma sırasında deadlock/unique yarışında yalnız kendi transaction'ını yöneten DB-only reserve çağrısı sınırlı tekrar yapabiliyor; dış transaction'ı yeniden başlatmıyor. Sunucu/statü ve süre taraması indeksleri eklendi.
- NotificationCenterService üç şemayı sürücüye uygun AUTO_INCREMENT/AUTOINCREMENT ile ayrı ifadelerde oluşturuyor. Tercih upsert'i MariaDB ON DUPLICATE KEY UPDATE ve SQLite ON CONFLICT dallarına ayrıldı; placeholder tekrarları kaldırıldı. MariaDB transaction içinde DDL çağrısı reddediliyor.

## Migration

`database/migrations/2026_10_09_000003_capacity_notifications.php`: server/reservation/notification şemalarını oluşturur; eski aktif hizmet ve sipariş kalemi kapsamlarını ayrı sütunlara taşır ve benzersiz indeksler kurar. Mükerrer aktif kapsam veya geçersiz eski miktarları sessizce silmez; mutabakat gerektiren hata verir. Tahsis/sayaç verilerini otomatik yeniden yazmaz. SQLite geçişi transaction içinde; MariaDB implicit DDL commit davranışı nedeniyle bakım penceresi, durdurulmuş eski worker'lar ve doğrulanmış yedek gerekir. Üretim verisine migration uygulanmadı.

## Test kanıtı

İlk 6 regresyonda düzeltme öncesi **5 başarısızlık, 1 başarı** kaydedildi (`D02-3a-before.txt/xml`): başarısız INSERT sonrası kota, başarısız bırakma sonrası sayaç, doğrudan limit aşımı, negatif miktar ve hizmet tekrarındaki çift tahsis. Eski negatif TTL testleri pozitif TTL ile oluşturulmuş kaydın expiry zamanını geçmişe taşıyan fixture'a çevrildi; hata ve kota iadesi beklentileri korundu.

| Paket | Test | Assertion | Sonuç |
|---|---:|---:|---|
| Ana paket | 951 | 8.052 | Başarılı |
| Gerçek MariaDB 10.11.18 | 34 | 305 | Başarılı |
| Toplam | 985 | 8.357 | Hata, başarısızlık, atlanan test yok |

Yeni MariaDB testleri dört ayrı PHP işlemini bariyerden aynı anda başlatıyor: tek hesaplık yere bir rezervasyon; 1.000 MB disk/bant genişliğine dört 600 MB isteğinden yalnız biri; hizmet tekrarlarında tek token; aynı rezervasyonun dört kez bırakılmasında diğer rezervasyonun korunması; commit/expiry yarışında terminal durum ile sayacın uyumu. Ayrıca native prepared query ile bildirim tercihi upsert'i ve inbox read/mark akışı, eski mükerrer kapsamda veri korunması ve hizmet commit sonrasında tekrar koruması doğrulandı. Önceki finans, kuyruk ve numara testleri de çalıştı.

SQLite hata testleri rezervasyon/sayaç başarısızlıklarını, telemetry tabanını, hizmetin yeniden bağlanamamasını ve sipariş kalemi tekrarını kapsıyor. Ham son kanıt: `D02-3a-suite.txt/xml`, `D02-3a-mariadb.txt/xml`, `D02-3a-after.txt/xml`; dosya hash'leri `D02-3a-file-hashes.json`. `git diff --check` temiz. İzole test sunucusu düzgün kapatıldı; mevcut ServBay verilerine dokunulmadı.

## Açık kalan işler

D02 ve F02/F03/F16 bütünü kapanmadı. Bu adım hesap/disk/bant genişliği rezervasyonunu kapsıyor; RAM/CPU/dedicated-IP kaynaklarının gerçek sağlayıcı iş akışına bağlanması ayrıca incelenecek. Gerçek uzak kullanım ile yerel tahsis mutabakatı, provisioning yan etkilerinin idempotency'si ve yetki sözleşmesi ilgili D03/D06/D08 kabulüne bağlı.

**D02.3b:** Quote/Proforma/Announcement gibi diğer PDO şemaları, WHMCS INSERT OR IGNORE yolu, diğer domain SQL/DDL'leri ve kapsamlı fresh install/upgrade matrisi. Doctor şema/işlev hataları F26/D06 ile de eşleştirilecek. D02.3b tamamlanmadan D03'e veya D02 için PASS sonucuna geçilmeyecek. Tüm ürünün üretime hazır olduğu sonucu çıkarılmaz.
