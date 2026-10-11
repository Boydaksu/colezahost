# D02.3b devamı — destek, API, kimlik ve belge kurulumu

Tarih: 11.10.2026. Başlangıç commit: df085ff0042f4d90865c0ea1fea8ba72cd2fa8c6. D02 IN_PROGRESS; bağımsız kabul PENDING; üretime hazır sonucu verilmedi.

Migration 9, destek/API/oturum/kimlik güvenliği/sözleşme/arşiv için 24 tabloyu gerçek kurulum akışına bağlar. Attachment/Context ve belge şema girişleri depolama veya sağlayıcı nesnesi oluşturmaz. Sözleşme ve snapshot SQLite'e özel DDL yerine sürücüye uygun kimlik kolonu kullanır; bu girişler MySQL uygulama transaction'ında DDL'yi reddeder. Mevcut migrationlar değiştirilmedi.

Sözleşme numarası global UNIQUE kolona uygun ortak sayaçtan gelir. Saklanan eski sözleşme numaraları ve eski tenant sayaçlarının üst sınırı korunur; eski belgeler yeniden numaralandırılmaz. Tanınmayan legacy numara açık uzlaştırma hatası verir. TicketService eksik invoice_id/order_id kolonlarını introspection ile tamamlar; gerçek SQL hatalarını yutmaz.

## Test kanıtı

İlk 25 MariaDB regresyonu 24 eksik tablo ve bir SQLite DDL hatası verdi (D02-3f-before.txt). Ortak 30 senaryo SQLite ve gerçek MariaDB'de kurulum, farklı organizasyonlar, Türkçe içerik, tekrar kurulumda ticket/message/API kaydı korunması, eski ticket kolonları, API scope reddi, sıralı rate counter, ithal sözleşme üst sınırı ve snapshot payload/checksum kalıcılığını doğrular. MariaDB native prepares kullanır. Üç migration sayısı testi 8→9 güncellendi; veri koruma ölçütleri gevşetilmedi.

| Paket | Test | Assertion | Sonuç |
|---|---:|---:|---|
| Ana paket | 998 | 8185 | Başarılı |
| MariaDB 10.11.18 | 103 | 689 | Başarılı |
| Toplam | 1101 | 8874 | Hata, başarısızlık, atlanan test yok |

Ham kanıt: D02-3f-suite.txt/xml, D02-3f-mariadb.txt/xml. Dosya özetleri: D02-3f-file-hashes.json.

## Kalan işler

Snapshot testinde yalnız blob deposu taklittir; veri tabanı gerçektir. PDF renderer, gerçek filesystem ve arşiv sürümleme yarışları bu testle kapanmaz. API rate-limit testi sıralıdır; eşzamanlı güvenlik kabulü değildir. Kimlik tablolarının kurulması 2FA sırlarının şifrelenmesi veya tüm yetki sınırlarının tamamlanması anlamına gelmez; D03 açık. Diğer servislerin runtime DDL kullanımı, kalan otomasyon/privacy/adoption şemaları, eski kısıt/kolon uyumu ve tablo öneki açık. Envanterde migration metni referansı kabul kanıtı sayılmaz. Üretim veri tabanına migration uygulanmadı.
