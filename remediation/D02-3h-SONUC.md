# D02.3b devamı — uygulama şemasına bağlı atomik aktarım

Tarih: 11.10.2026. Başlangıç commit: 4d3368deef0c5a8227b0bf1200dfd15cf31dce71. D02 IN_PROGRESS; bağımsız kabul PENDING; production_ready false.

## Değişiklik

ServiceAdoptionService ve DomainAdoptionService kendi alternatif services/domains tablolarını oluşturmuyor; uygulamanın ServiceService/DomainService şema ve kayıt metotlarını kullanıyor. Hizmet tutarı recurring_amount_minor/currency_code alanına, sunucu bağı service_placements'a yazılıyor. Domain, domain/registrar_id alanlarıyla uygulamadan okunabiliyor; kaynak tarihleri/nameserver/mahremiyet bilgisi korunuyor. Domain kaynak yenileme tutarı ve para birimi adopted_billing metadata'sında korunuyor; subscription/fatura akışı bu adımla tamamlanmış sayılmaz. is_adopted ve sağlayıcı işlem bastırma bayrakları metadata'da, suppress_provisioning ise adopted-provider kimliğinde kalıyor.

Migration 11 domain/contact/timeline ve singleton migration_adoption_lock tablolarını kuruyor. Hizmet/yerleşim/identity veya domain/timeline/tarihler/identity aynı Connection transaction'ında yazılıyor. Trigger kaynaklı identity INSERT hatası hiçbir orphan kayıt bırakmıyor. Ortak kilit satırı eşzamanlı aktarımı serialize ediyor; lock sonrasında güncel identity okunuyor. Tek dış kimlik ancak kaynak sistem/kaynak id ve mevcut kaydın müşteri kimliği aynıysa tekrar kullanılabiliyor. Bu global kilit aktarım güvenliği içindir; yüksek hacimli performans kabulü değildir.

Hizmette eksik/geçersiz registration veya next-due tarihi açık başarısızlık verir; tarih uydurulmaz. Ürün veya sunucu eşlemesi yoksa ilk ürün/sunucuya varsayılan bağ kaldırıldı. Gerçek hedef varlığı, sahiplik/operational-mode ve HTTP/CLI yetki sınırlarının tamamı ayrıca D03/D07 kabulüne tabidir. Remote verifier isteği yalnız mevcut read-only API üzerinden ve transaction dışında çalışır; gerçek sağlayıcı bağlantısı yapılmadı.

ServiceService/DomainService, aktif MySQL transaction'ında kurulu kolonları SELECT ile doğrular ve DDL yapmaz. ServiceService'in dört eski ALTER hatası yutulmaz; eksik kolonlar introspection ile eklenir. Eski dar adoption tabloları canonical alanlara sahip değilse veri değiştirilmeden uzlaştırma hatası verir. Bu adım eski tutar/tarih/domain adını otomatik dönüştürmez veya kısıtları kaldırmaz. ConflictDetector canonical domain ve aktif service placement sorgularını kullanır.

## Test kanıtı

İlk dört MariaDB regresyonu iki SQL şema hatası ve iki orphan-kayıt başarısızlığı verdi (D02-3h-before.txt). Ortak on senaryo SQLite ve gerçek MariaDB'de application read model, 19.99→1999 tutar, tarih/nameserver/billing metadata, tekrar kurulum, tekrar aktarım, trigger rollback, eksik tarih, ürün/sunucu eşlemesi, farklı müşteri/kaynak sistem ve eski dar tabloyu koruma ölçütlerini sınar. İki ek MariaDB testi dörder PHP sürecinde tek hizmet/placement/identity ve tek domain/timeline/identity oluştuğunu doğrular; parent PDO native prepares kullanır.

Eski pozitif fixture'lara açık kaynak tarihleri, üç fixture'a açık sunucu eşlemesi eklenir. Eski domain_name/registrar ve is_adopted kolon beklentileri uygulamanın domain/registrar_id ve adopted metadata'sına taşınır; davranış beklentileri korunur. Üç migration-adedi beklentisi 10→11 güncellenir. Önceki migration dosyaları değiştirilmedi.

| Paket | Test | Assertion | Sonuç |
|---|---:|---:|---|
| Ana paket | 1026 | 8262 | Başarılı |
| MariaDB 10.11.18 | 135 | 863 | Başarılı |
| Toplam | 1161 | 9125 | Hata, başarısızlık, atlanan test yok |

Ham kanıt: D02-3h-suite.txt/xml, D02-3h-mariadb.txt/xml. Dosya özetleri D02-3h-file-hashes.json.

## Kalan işler

Eski dar services/domains tablosunun veri koruyan canonical upgrade'i, tüm constraint/index varyantları ve tablo öneki açık. Domain yenileme tutarının gerçek billing/subscription modeline bağlanması, tüm dış kimlik varyantları ve provider doğrulaması açık. Otomasyonun bellek depoları ve diğer operasyonel domainler ayrıca kalıcı hale getirilmeli. Envanter 87 CREATE TABLE kaynak dosyası içeriyor; referanslar kabul kanıtı değildir. Üretim veri tabanına migration uygulanmadı; eski şema veya gerçek müşteri aktarımı yapılmadı.
