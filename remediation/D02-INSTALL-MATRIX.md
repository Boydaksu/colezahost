# D02 kurulum/geçiş kabul matrisi

Güncelleme: 10.10.2026. Kanıt: D02-3a, D02-3b, D02-3c. Bu matris ürünün bağımsız kabulü değildir. D02 IN_PROGRESS.

| Senaryo | Durum | Kanıt / sınır |
|---|---|---|
| Gerçek MariaDB 10.11, temiz veri tabanı, production migrationlar | Yerel test geçti | DatabaseFoundationTest; InstallationAcceptanceTest; altı migration |
| SQLite kurulum sihirbazı, migrationlardan sonra ADMIN adımı | Yerel test geçti | WebInstallerTest; gerçek DatabaseConfig bağlantısı |
| Migration tekrarında eski RBAC rollerinin, izinlerin ve tarihlerinin korunması | Yerel test geçti | DatabaseFoundationTest; IdentitySchemaMigrationRegressionTest |
| Belge/kalem/duyuru kayıtlarının tekrar migrationda korunması | Yerel test geçti | DocumentPersistenceTest; D02-3b |
| Yarım teklif şemasının tamamlanması, item yazımı başarısızlığında parent rollback | Yerel test geçti | DocumentPersistenceTest; D02-3b |
| Eski tenant sayacı ve belge numarası üst sınırının ortak sayaca taşınması | Yerel test geçti | InstallationAcceptanceTest; belgeler yeniden numaralandırılmıyor |
| Dört organizasyondan paralel belge oluşturma | Yerel test geçti | 64 teklif + 64 proforma; benzersiz ve kalıcı kayıtlar |
| Doctor'ın boş veri tabanında tablo/transaction değiştirmemesi | Yerel test geçti | InstallationAcceptanceTest; SELECT hatası sağlık uyarısı olur |
| Gerçek jobs/failed_jobs ve installer cron şemasından tanılama | Yerel test geçti | InstallationAcceptanceTest; cron failure/freshness; SharedHostCompatibilityMatrixTest |
| Eski Doctor ran_at geçmişinin korunması | Yerel test geçti | Migration 6; bilinmeyen eski başarı durumu unknown kalır |
| Katalog, fiyat, vergi, sipariş, fatura, kredi, hizmet, finans, organizasyon ve modül şemaları | Temel kurulum testleri geçti; iş akışları kısmi | Migration 6 servislerin kendi şemasını kullanıyor; tüm domain akışları kabul edilmiş sayılmaz |
| Tablo öneki | Destek açık | Wizard/application initializer kısmi ve yanlış kurulum üretmek yerine öneki yazmadan reddediyor; düşük seviye core initializer desteği bütün domain desteği sayılmaz |
| Dar WHMCS/eskiden oluşturulmuş user ve organization tablolarında eksik kolonlar | Açık | CREATE IF NOT EXISTS kolon uyumunu sağlamaz; ayrı eski tablo matrisi ve veri koruyan upgrade gerekir |
| Diğer destek, API, otomasyon, privacy, migration/adoption ve belge domainleri | Açık | Temel şema listesi bütün platformun kalıcılık listesi değildir |
| GoldenFinancialDataset'in üretim şemasıyla eşleşmesi | Açık | Test fixture kanonik kurulum şeması sayılmaz; F17/F30 |
| Gerçek sağlayıcı bağlantısı ve gerçek cron çalıştırıcısının geçmiş yazımı | D06 kabulü açık | Doctor provider envanterini uzaktan doğrulanmış HEALTHY diye sunmaz |
| Üretim veri tabanında upgrade/rollback | Yapılmadı | Yedek, bakım penceresi ve eski worker'ların durdurulması gerekir; yerel test üretim onayı değildir |

Sıradaki D02 işi: dar eski user/organization şemalarının veri koruyan geçişi ve kalan domain şema envanteri. D02 çıkış ölçütleri tamamlanmadan D03'e geçilmez.
