# D02.3b devamı — eski hizmet/domain şemasının arşivli dönüşümü

Tarih: 11.10.2026. Başlangıç commit: d20a1c453a344e6b9a06352889d9e81707cb1518. D02 IN_PROGRESS; independent_acceptance PENDING; production_ready false.

## Değişiklik

LegacyAdoptionUpgrade, önceki ServiceAdoptionService/DomainAdoptionService'in bilinen eski tablolarını uygulamanın şemasına taşır. Services için bilinen dört ek auto_renew/grace/version kolonu da desteklenir. Eski tablolar legacy_adoption_services_v12 ve legacy_adoption_domains_v12 adıyla kalır; DROP/cleanup yapılmaz. Kimlikler ve mevcut adopted-provider satırları korunur. Yeni hizmet numarası SRV-LEGACY-id'dir; eski tabloda numara yoktu, günlük yeni hizmet sayacı tüketilmez. Original AUTO_INCREMENT/sqlite_sequence üst sınırı taşınır; silinmiş yüksek kimlik yeniden kullanılmaz.

19.99→1999 dönüşümü string üzerinden yapılır, float yuvarlama kullanılmaz. Bu geçiş yalnız desteklenen iki ondalık hassasiyet için uygulanır; mevcut currencies kaydı farklı precision veriyorsa veya kayıt yokken para birimi USD/TRY/EUR/GBP dışında ise açık uzlaştırma ister. Domain kaynak tutarı/para birimi adopted_billing metadata'sında kalır; gerçek subscription/fatura bağlantısı D04/D07'de açık.

Müşteri/product/server id, durum, kaynak tarihleri, Türkçe metadata, registrar, nameserver ve bilinen yenileme/mahremiyet bayrakları korunur. Legacy hizmette auto_renew yoksa 0 atanır ve auto_renew_unknown işaretlenir; kendiliğinden yenileme açılmaz. Bilinen auto-renew/grace/version değerleri aynen taşınır. Eski domain tablosunda olmayan DNS/forwarding/lock bilgileri NULL kalır. Sunucu yerleşimi taşımasında eski atanma tarihi uydurulmaz; assigned_at NULL'dır.

Installer kimlik şemasını, ardından iki legacy tabloyu değiştirmeden önce ön kontrol eder. ServiceService/DomainService schema girişleri ve migration 12 aynı upgrader'ı çağırır; önceki migrationlar değişmez. MySQL named lock legacy geçişlerini serialize eder. DDL veri transaction'ından ayrıdır: marker copying olarak kaydedilir, kaynak yeniden adlandırılır, boş canonical şema hazırlanır ve next-id ayarlanır. Satır/placement kopyası ile complete işareti tek transaction'da tutulur. Hata halinde kopya satırlar geri alınır; kaynak arşiv ve copying durumu tekrar çalıştırmaya izin verir. Checksum/count/next-id değişmişse otomatik devam edilmez. Complete geçiş, daha sonra değişen canonical satırları arşivdeki eski değerlerle ezmez. İki tablonun tüm DDL'si atomik/rollback edilebilir ilan edilmez; bakım penceresi ve doğrulanmış yedek gerekir.

Kaynak FK/trigger/custom index/CHECK, bilinmeyen kolon seti, eksik hizmet tarihi, geçersiz tutar/JSON/alan boyutu, desteklenmeyen hassasiyet ve normalize domain çakışması durdurulur. Bunlar sessizce kaldırılmaz veya yeniden fiyatlanmaz. Aynı server/username yerleşimi var ama identity yoksa yeniden ithal etme açık uzlaştırma hatası verir; ikinci hizmet kaydı oluşturulmaz.

## Test kanıtı

İlk beş MariaDB regresyonu 3 error ve 2 failure verdi (D02-3i-before.txt): bilinen eski tablolar dönüştürülemiyordu; olumsuz veride installer önceden core tabloları oluşturuyordu. Ortak 17 senaryo SQLite ve gerçek MariaDB'de canonical uygulamadan okuma, arşivin satır satır korunması, yeni uygulama kayıtları, tekrar sonrası güncel fiyatın korunması, eski identity/reimport, silinmiş id üst sınırı, optional flags ve güvenli durdurmayı doğrular. Marker UPDATE trigger hatası kopya/placement satırlarını rollback eder; trigger kaldırılınca aynı arşivden tek kayıtla tamamlanır. FK, custom index, metadata hatası, normalize isim çakışması, bilinmeyen user state ve TEXT büyümesi için değişiklik öncesi durma sınanır. MariaDB parent PDO native prepares kullanır.

Son kaynak değişikliklerinden sonra bütün paket tekrar çalıştırıldı; aşağıdaki ham dosyalar son koşuya aittir. Üç migration-adedi beklentisi 11→12 güncellendi; önceki koruma beklentileri gevşetilmedi.

| Paket | Test | Assertion | Sonuç |
|---|---:|---:|---|
| Ana paket | 1043 | 8336 | Başarılı |
| MariaDB 10.11.18 | 152 | 970 | Başarılı |
| Toplam | 1195 | 9306 | Hata, başarısızlık, atlanan test yok |

Ham kanıt: D02-3i-suite.txt/xml, D02-3i-mariadb.txt/xml. Dosya özetleri D02-3i-file-hashes.json.

## Kalan işler

Gerçek üretim upgrade/restore, bakım penceresi ve eski worker'ların durdurulması uygulanmadı. Arşivlerin backup/restore ve privacy erasure/retention kapsamı D05/D07'de ayrıca ele alınmalı; orijinal müşteri verisi arşivde de bulunduğundan bu adım privacy kabulü değildir. Genel legacy type/default/constraint varyantları, farklı currency precision, tüm provider kimliği uzlaştırmaları, billing ve otomasyonun bellek depoları açık. Yeni archive/marker ledger migration sırasında ihtiyaç varsa oluşturulur; metin envanteri tüm tabloların boş kurulumda kurulduğunu kanıtlamaz. Envanter 88 CREATE TABLE kaynak dosyasıdır. Üretim verisi dönüştürülmedi, bağımsız PASS veya release sonucu verilmedi.
