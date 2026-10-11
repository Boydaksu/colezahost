# D02.3b devamı — gizlilik ve migration geçmişi

Tarih: 11.10.2026. Başlangıç commit: d6bc404e49d8750795a735c84df299758a0c13a9. D02 IN_PROGRESS; bağımsız kabul PENDING; production_ready false.

## Değişiklik

Migration 10 gizlilik için sekiz ve staging/checkpoint/hold/suppression/adopted-provider kimlikleri için beş tabloyu gerçek kurulum akışına bağlar. Önceki migrationlar değiştirilmedi. Kurulum sırasında dış sağlayıcı, dosya deposu, silme işlemi veya gizlilik anahtarı üretimi çalıştırılmaz. Retention servisinin mevcut varsayılan seed davranışı kullanılır; operatörün değiştirdiği süreler üzerine yazılmaz. Bu sürelerin hukuki uygunluğu kabul edilmiş sayılmaz.

PrivacyConsentService ve PrivacyTombstoneService aynı tablo için farklı DDL kullanıyordu. Ortak PrivacyTombstoneSchema dar eski tabloya hash/checksum/metadata/signature kolonlarını additive ekler. Eski id, user_id, silme kararı, nullable reason ve tarih korunur. Eski signed tablo değiştirilmez; NOT NULL kısıtları kaldırılmaz. Consent'in eski API'si hash/imza olmadan silme kaydı yazmaya devam eder. Boş imza doğrulamadan geçmez; geçiş e-posta veya HMAC uydurmaz. İmzasız kaydın user_id üzerinden silinmiş hesap olarak tanınması sürer; e-posta hash koruması için eski eksik bilgi tamamlanmış sayılmaz.

PdoSchema.useInstalledSchemaInTransaction, seçilen gizlilik ve migration repository'lerinde aktif MySQL transaction sırasında gerekli kolonları salt SELECT ile doğrular. Şema mevcutsa DDL/seed yapılmadan işleme devam edilir; eksikse LogicException verilir ve caller transaction'ı commit edilmez. Bu güvence diğer bütün domain servislerine genellenmiş sayılmaz. Yeni tombstone e-posta indeksi introspection ile oluşturulur; diğer gizlilik servislerinin eski indeks hata yakalama davranışları ve tüm constraint varyantları ayrıca açık.

## Test kanıtı

İlk 16 MariaDB regresyonu 15 error, 1 failure, 1 warning verdi: 13 eksik tablo, iki servis sırası hatası, eksik legacy imza kolonu. D02-3g-before.txt tarihsel hata kanıtıdır. Ortak 18 senaryo SQLite ve gerçek MariaDB'de çalışır; iki ek MariaDB testi rollback ve transaction içinde eksik şemanın güvenli reddini doğrular. Native prepares açıktır. Türkçe içerik, signed imzanın korunması, eski dar kayıt, original signed NOT NULL şema, checkpoint devam konumu ve özelleştirilmiş retention satırlarının birebir korunması sınanır. Üç migration-adedi beklentisi 9→10 güncellendi.

| Paket | Test | Assertion | Sonuç |
|---|---:|---:|---|
| Ana paket | 1016 | 8217 | Başarılı |
| MariaDB 10.11.18 | 123 | 769 | Başarılı |
| Toplam | 1139 | 8986 | Hata, başarısızlık, atlanan test yok |

Ham kanıt: D02-3g-suite.txt/xml, D02-3g-mariadb.txt/xml. Dosya özetleri D02-3g-file-hashes.json.

## Kalan işler

Anahtar/salt varsayılanları ve imzasız geçmiş kayıtların operatör uzlaştırması D03/D07'de açık. Gerçek out-of-band depolama, restore güvenliği ve erasure'ın gerçek domain tablolarına bağlanması D05/D07'de açık. Bu adım kullanıcı silme/yasal uyum kabulü değildir. Otomasyonun bellek depoları, domain/service adoption şemaları, diğer operasyonel domainler, kolon/unique/FK/index varyantları ve tablo öneki açık. Şema envanteri 88 CREATE TABLE kaynak dosyası içerir; migration metni referansı gerçek çalıştırma/kabul kanıtı değildir. Üretim veri tabanına migration uygulanmadı.
