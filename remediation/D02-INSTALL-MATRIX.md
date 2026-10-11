# D02 kurulum/geçiş kabul matrisi

Güncelleme: 11.10.2026. Kanıt: D02-3a, D02-3b, D02-3c, D02-3d, D02-3e, D02-3f, D02-3g, D02-3h, D02-3i. Bu matris ürünün bağımsız kabulü değildir. D02 IN_PROGRESS.

| Senaryo | Durum | Kanıt / sınır |
|---|---|---|
| Gerçek MariaDB 10.11, temiz veri tabanı, production iki migrationlar | Yerel test geçti | DatabaseFoundationTest; InstallationAcceptanceTest; on iki migration |
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
| Bilinen Auth/WHMCS ve eski installer user/organization kolonları | Yerel test geçti | LegacyIdentitySchemaTest (SQLite + MariaDB); veri koruma, NOT NULL ad, pasif giriş reddi, yarım geçişten devam |
| Bilinmeyen kimlik şeması ve erişim durumu | Güvenli durdurma testi geçti | Preflight CREATE/ALTER öncesinde hata verir; parola veya erişim durumu tahmin edilmez |
| Eski kolon boyutu, NULL/default, unique/FK/index ve diğer kimlik varyantları | Açık | Bu geçiş kolonları ekler; mevcut tipleri/kısıtları yıkıp yeniden oluşturmaz. Desteklenen kolon düzeni, bütün eski şemaların eşdeğerliği değildir |
| BrandSetup ve eski NOT NULL organization owner | Yerel test geçti | Açık aktif/yetkili sahip, kalıcı bağ, müşteri/diğer marka korunması, rollback ve dört paralel kurulum |
| Legacy provider identity uzlaştırması | Açık id'lerle yerel test geçti; otomatik tahmin yok | bindExistingProvider başka sahibi devralmaz; eski belirsiz bağ operatörün açık seçimi olmadan kurulmaz |
| Destek, API, oturum/kimlik güvenliği, sözleşme ve belge arşivi tabloları | Yerel kurulum/veri koruma testleri geçti | Migration 9; 24 tablo, eski ticket kolonları, API scope/sıralı sayaç, global sözleşme numarası; snapshot blob depolama taklidi |
| Gizlilik ve migration geçmişinin 13 tablosu | Yerel kurulum/veri koruma testleri geçti | Migration 10; eski dar/signed tombstone, imzasız kaydın doğrulanmaması, checkpoint/retention korunması, transaction rollback |
| Domain/hizmet adoption uygulama şeması ve atomiklik | Yerel test geçti | Migration 11; uygulamadan okuma, yerleşim/timeline/identity rollback; dört paralel hizmet ve dört paralel domain aktarımı |
| Bilinen eski services/domains adoption tabloları | Arşivli dönüşüm ve tekrar testi geçti | LegacyAdoptionUpgrade/migration 12; id/tutar/tarih/binding ve silinmiş id sayaç sınırı; rollback/retry; sonraki canonical değişiklikler korunur |
| Belirsiz legacy shape, source FK/trigger/custom index/CHECK ve veri çelişkisi | Ön kontrol/durdurma; genel dönüşüm açık | Bilinmeyen bağımlılık kaldırılmaz; iki ondalık dışı para birimi ve eksik hizmet tarihi uzlaştırma ister |
| Legacy arşivlerin backup/restore ve privacy/retention kapsamı | D05/D07 açık | Orijinal tablolar silinmez; arşivlerin silme/anonimleştirme ve gerçek restore kabulü ayrıca gerekli |
| Diğer otomasyon ve operasyonel domainler; genel runtime DDL sınırları | Açık | Kurulum şeması bütün iş akışlarının ve kısıtların kabulü değildir |
| GoldenFinancialDataset'in üretim şemasıyla eşleşmesi | Açık | Test fixture kanonik kurulum şeması sayılmaz; F17/F30 |
| Gerçek sağlayıcı bağlantısı ve gerçek cron çalıştırıcısının geçmiş yazımı | D06 kabulü açık | Doctor provider envanterini uzaktan doğrulanmış HEALTHY diye sunmaz |
| Üretim veri tabanında upgrade/rollback | Yapılmadı | Yedek, bakım penceresi ve eski worker'ların durdurulması gerekir; yerel test üretim onayı değildir |

Sıradaki D02 işi: D02-SCHEMA-INVENTORY.json içindeki kalan domain şema kurucularını gerçek kurulum/upgrade akışına bağlamak ve kısıt uyumunu sınamak. Envanter 88 CREATE TABLE kaynak dosyasını listeler; metinde migration referansı olması tek başına çalıştırma ya da kabul kanıtı değildir. D02 çıkış ölçütleri tamamlanmadan D03'e geçilmez.
