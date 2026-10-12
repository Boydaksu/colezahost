# D02 kurulum/geçiş kabul matrisi

Güncelleme: 12.10.2026. Kanıt: D02-3a, D02-3b, D02-3c, D02-3d, D02-3e, D02-3f, D02-3g, D02-3h, D02-3i, D02-3j, D02-3k, D02-3l, D02-3m, D02-3n, D02-3o, D02-3p, D02-3q, D02-3r, D02-3s, D02-3t, D02-3u, D02-3v, D02-3w, D02-3x, D02-3y. Bu matris ürünün bağımsız kabulü değildir. D02 IN_PROGRESS.

| Senaryo | Durum | Kanıt / sınır |
|---|---|---|
| Gerçek MariaDB 10.11, temiz veri tabanı, production yirmi iki migration | Yerel test geçti | DatabaseFoundationTest; InstallationAcceptanceTest; yirmi iki migration |
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
| Otomasyon onay/gecikme/geçmiş/acil durdurma kayıtları | Yerel kalıcılık ve paralellik testleri geçti | Migration 13; SQLite/MariaDB; yeni bağlantıdan okuma; tek onay kararı/dispatch claim; paused işler bekler; D02-3j |
| Kalıcı kural sürümleri ve özgün onay/gecikme provenance | Yerel kurulum, restore ve paralel yayın testleri geçti | Migration 14; tam trigger/condition/action snapshot; v1 işi v2 sonrası v1 ile çalışır; dörder süreçte numara; D02-3k |
| Onay ve ertelemenin birleşik akışı | Yerel atomiklik, replay ve paralellik testleri geçti | D02-3l; karar/child/history tek transaction; onay sonrası bekleme; aynı actor tekrarında tek child, farklı actor kararı değiştiremez |
| Eski onayın bekleme politikası | Bilinen frozen sürümden doğrulama ve belirsizliği durdurma testleri geçti | Yeni JSON delay alanı yoksa özgün kayıtlı sürüm/hash kontrol edilir; bilinmeyen politika sıfır kabul edilmez |
| Otomasyon tamamlanma kanıtının iş girdileriyle eşleşmesi | Yerel bütünlük testleri geçti | D02-3n; hash/version/branch/context/action sonuçları; eksik veya çelişkili eski sonuç otomatik doğrulanmaz |
| Onaydan doğan delay'in özgün onayla bütünlüğü | Yerel claim/replay, reverse link ve paralellik testleri geçti | D02-3n; değiştirilmiş eylem/context/policy/zaman aralığı durur; silinmiş origin alanı reverse parent lookup ile aşamaz |
| Yarım kalan otomasyon işleri | Salt okunur inceleme testi geçti; güvenli dış recovery açık | Eksik/başarısız/çelişkili outcome gösterilir, otomatik retry yok; provider reconciliation ve lease/idempotency ayrı D06 kabulü |
| Kalıcı global yıkıcı işlem kontenjanı | Yerel kalıcılık, hata ve dört süreçli yarış testleri geçti | Migration 15; 5 girişim/3600 saniye, işlemden önce committed permit; başarısız/belirsiz sonuç da sayılır; D02-3m |
| Otomasyon actor RBAC ve tüm HTTP/CLI/cron bağları | D03/D06/D08 açık | Kalıcı engine/version interface container binding yerel doğrulandı; bütün ürün girişleri veya dış komut güvenliği kabul edilmiş sayılmaz |
| Otomasyon eylem deneme günlüğü | Yerel SQLite/MariaDB testleri geçti; sağlayıcı kanıtı ve genel kurtarma açık | D02-3p; migration 16, kayıt-before-handler, write fault sonrası duruş, pending/input bağı, dört süreçte tek start |
| Otomasyon başarı geçmişi ve eylem kayıt bağı | Yerel SQLite/MariaDB testleri geçti; dış sağlayıcı kabulü açık | D02-3q; exact pending/ordered action/input/version/time/dispatch bağı; missing/unknown/foreign/extra deneme işi gizleyemez; salt okunur, no retry/backfill |
| Hizmet otomasyonu yerel/sağlayıcı sınırı | Yerel SQLite/MariaDB koruma testleri geçti; gerçek sağlayıcı komutu açık | D02-3r; placement/legacy remote identity için local-only başarı reddi; strict id/version; current-read/CAS; dört worker tek geçiş |
| Foundation cache/locks/idempotency/heartbeat ve migration metadata runtime DDL | Yerel SQLite/MariaDB şema ve transaction testleri geçti | D02-3s; migration 17, installed schema no-DDL, raw/managed caller rollback, eksik/yarım şemada duruş; tam constraint/engine matrisi açık |
| Sağlayıcı ayarları/Vault ve denetim bağımlılığının runtime DDL yolu | Yerel kurulum, veri koruma ve caller rollback testleri geçti | D02-3t; keyless migration 18, ciphertext/version/date korunması, installed audit no-DDL; anahtar/RBAC/secret audit güvenlik kabulü D03 açık |
| Marka/para birimi/kur geçmişi şeması ve runtime DDL | Yerel kurulum, kayıt koruma ve caller rollback testleri geçti | D02-3u; migration 19, installed no-DDL, FX schema check before fallback/provider; single-active/default yarışları ve gerçek FX kabulü açık |
| Tek aktif marka ve default currency cooperating writer bütünlüğü | Yerel rollback, current-read ve dört worker testleri geçti | D02-3v; ortak row mutex, savepoint/raw caller, installer/domain ortak policy, failed default insert eski varsayılanı korur; bypass SQL/DB unique constraint/diğer writer matrisi açık |
| Abuse şikâyet/mesaj şeması ve runtime DDL | Yerel kurulum, kayıt koruma, indeks tamamlama ve raw caller rollback testleri geçti | D02-3w; migration 21, iki tablo, installed no-DDL; yarım şemada duruş ve explicit fixture repair sonrası devam; tam constraint/engine/RBAC/retention matrisi açık |
| Fraud değerlendirme/sinyal/manuel karar/liste/velocity şemaları | Yerel kurulum, replay, indeks tamamlama, raw/managed caller rollback testleri geçti | D02-3x; migration22, altı tablo/sekiz indeks; toplu partial preflight; immutable karar workflow/concurrency/RBAC ve tam constraints ayrıca açık |
| Fraud çoklu yazım ve cooperating manual review yarışları | Yerel failure/savepoint/current-read ve dört worker testleri geçti | D02-3y; evaluation+signals/terminal status+snapshot atomik; parent-before-review locks; direct SQL/DB unique constraint, actor/RBAC/audit/metadata checksum policy ayrıca açık |
| Diğer otomasyon ve operasyonel domainler; genel runtime DDL sınırları | Açık | Kurulum şeması bütün iş akışlarının ve kısıtların kabulü değildir |
| GoldenFinancialDataset'in üretim şemasıyla eşleşmesi | Açık | Test fixture kanonik kurulum şeması sayılmaz; F17/F30 |
| Provisioning cPanel readback ve kalıcı yerel uzlaştırma | Yerel transport/SQL testleri geçti; gerçek sunucu kabulü açık | D02-3o; UNKNOWN hesabın yokluğu değildir, kimlik/etkinlik doğrulanır; service/placement/operation/audit atomik; dört süreçte tek completion |
| Gerçek sağlayıcı bağlantısı ve gerçek cron çalıştırıcısının geçmiş yazımı | D06 kabulü açık | Doctor provider envanterini uzaktan doğrulanmış HEALTHY diye sunmaz |
| Üretim veri tabanında upgrade/rollback | Yapılmadı | Yedek, bakım penceresi ve eski worker'ların durdurulması gerekir; yerel test üretim onayı değildir |

Sıradaki D02 işi: D02-SCHEMA-INVENTORY.json içindeki kalan domain şema kurucularını gerçek kurulum/upgrade akışına bağlamak ve kısıt uyumunu sınamak. Envanter 93 CREATE TABLE kaynak dosyasını listeler; metinde migration referansı olması tek başına çalıştırma ya da kabul kanıtı değildir. D02 çıkış ölçütleri tamamlanmadan D03'e geçilmez.
