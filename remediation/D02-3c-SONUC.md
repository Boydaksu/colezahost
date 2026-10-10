# D02.3b devamı — ortak belge sayacı, kurulum ve Doctor

Tarih: 10.10.2026. Başlangıç commit: cc6b95ebed71ebbc2dacc8f5c3ec540a00c25f65. Referans: CR/TCR-D02.3b, F02/F03/F16/F26. Kanıt dosyaları D02-3c adıyla önceki adımın kanıtını değiştirmeden kaydedildi. **D02 ve D02.3b hâlâ devam ediyor.**

## Sonuç

Teklif ve proforma numarası tabloda global UNIQUE iken numara tenant başına üretildiği için ikinci organizasyonun ilk belgesi çakışıyordu. Quote/Proforma artık type/year için ortak sayacı kullanıyor. Görünen numara biçimi, belge kimliği ve organizasyon alanı korunuyor. Migration 5 eski belge numaraları ve tenant sayaçlarından en yüksek değeri taşır; eski belgeleri ve tenant sayaçlarını yeniden yazmaz. Tanınmayan eski numaralarda sessizce numara seçmek yerine geçiş durur. Diğer amaçlarla kullanılan tenant bazlı generator API'si korunur.

WebInstaller, ADMIN aşamasına geçmeden initializeApplicationSchema üzerinden production migrationları uygular. Migration 6 kanonik core, katalog, fiyat, vergi, sipariş, fatura, kredi, hizmet, finans hesap/gider/kârlılık, organizasyon/davet ve modül şemalarını ilgili servislerin kurucularıyla hazırlar. Uygulama initializer'ı MySQL transaction içinde DDL başlamasını reddeder. Domainlerin desteklemediği tablo öneki, kısmi kurulum oluşturulmadan reddedilir; önek desteği tamamlanmış sayılmaz.

Doctor tanılama sırasında CREATE TABLE çalıştırmaz. jobs ve failed_jobs tablolarını, cron run_at/status sözleşmesini ve gerçek installed_modules tablosunu okur. Son cron başarısızsa, zamanı geçersiz/gelecekteyse veya gecikmişse HEALTHY vermez. Eski Doctor ran_at geçmişi migration 6 ile run_at alanına taşınır; eski alan, task sayısı ve çıktı korunur. Başarı bilgisi bulunmayan eski kaydın statüsü unknown olur; başarı uydurulmaz. Sağlayıcı envanter sayımı uzak bağlantı testi olmadığı için provider sonucu WARNING olur; gerçek transport kabulü D06'da açık.

## Test kanıtı

Önceki commitin dört sınıfı git show ile ignored runtime içinde kopyalanıp ayrı bootstrap ile yüklendi. İlk dört yeni test bu kaynaklarda **2 hata ve 2 başarısızlık** verdi: organizasyon numara çakışması, eksik application initializer, Doctor'ın transaction'ı implicit commit etmesi ve cron sözleşmesi uyuşmazlığı (`D02-3c-before.txt`). İlk çalıştırıcının sandbox dosya erişimi ve geçici sunucu ömrü sorunları ürün hatası sayılmadı; kanıt gerçek ServBay PHP ve kalıcı izole MariaDB ile alındı.

| Paket | Test | Assertion | Sonuç |
|---|---:|---:|---|
| Ana paket | 954 | 8.072 | Başarılı |
| Gerçek MariaDB 10.11.18 | 57 | 470 | Başarılı |
| Toplam | 1.011 | 8.542 | Hata, başarısızlık, atlanan test yok |

Yeni MariaDB testleri dört organizasyondan dört ayrı PHP işleminde 64 teklif ve 64 proforma üretip benzersizlik, kalıcılık ve organizasyon bağını doğruluyor. Ayrıca tekrar kurulumda satırların korunması, eski 700 belge/800 tenant sayacından 801 üretimi, öneğin tablo oluşturmadan reddi, eski cron geçmişinin korunması, taze ama başarısız cronun CRITICAL olması ve Doctor'ın uygulama transaction'ını bozmaması sınandı. SQLite sihirbaz testi altı migrationın yönetici adımından önce uygulanmasını doğruladı.

İlk tam pakette eski Doctor şemasını oluşturan iki integration fixture başarısız oldu (`D02-3c-suite-initial.txt`). Fixturelar production initializer ve run_at/status sözleşmesine geçirildi. G06'nın uzak bağlantı yapılmadan bütün raporun HEALTHY olması beklentisi kaldırıldı: provider WARNING, diğer altı bileşen ayrı ayrı HEALTHY doğrulanıyor. Bu yeni ölçüt gerçek sağlayıcı testi değildir. İki mevcut migration-adedi testi 4→6 güncellendi; veri/izin koruma beklentileri korunuyor. Son ham kanıt `D02-3c-suite.txt/xml` ve `D02-3c-mariadb.txt/xml`; kaynak hash'leri `D02-3c-file-hashes.json`.

## Açık kalanlar

[D02 kurulum matrisi](D02-INSTALL-MATRIX.md) tamamlanan ve açık kontrolleri ayrı kaydeder. Dar eski user/organization şemalarının kolon uyumu, kalan domainlerin kurulum/upgrade envanteri ve tam tablo öneki desteği açık. GoldenFinancialDataset üretim şemasının yerine kullanılamaz. Sağlayıcı/cron transport ve geçmiş yazımı D06; diğer iş akışı eşzamanlılığı ve erişim sınırları D03/D04/D07 kabulüne bağlı. Yeni migrationlar üretim veri tabanına uygulanmadı. Bağımsız kabul PENDING ve production_ready false kalır.
