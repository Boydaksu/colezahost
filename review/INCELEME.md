# Coleza Host — Orijinal master plana göre bağımsız inceleme

**Tarih:** 9 Ekim 2026, Türkiye saati. **İncelenen commit:** `6dc47ea83bb51c09c993e6cef3af3577e5955f51`. Yerel HEAD ve GitHub `main` aynı commit olarak doğrulandı.

**Karar: Bu sürümü V1 Stable veya üretime hazır olarak kabul etmiyorum.** Kapsamlı bir PHP bileşen ve test koleksiyonu yapılmış; fakat planın istediği kurulabilir, kullanıcıların işlettiği, MariaDB üzerinde çalışan ve gerçek sağlayıcılara güvenli biçimde bağlanan ürün henüz ortaya çıkmamış. En önemli sorun özellik sayısı değil, parçaların birbirinden farklı şemalar ve güvenlik varsayımlarıyla geliştirilmesi ve eksik parçaların PASS kabul edilmesi.

## İncelemenin dayanağı ve sınırları

Birincil kaynak, kullanıcının verdiği `C:/Users/alici/Downloads/hosting-platform-master-plan.zip` arşividir. SHA256: `9af69b64e38e4ce1f8a4727e2f98d5796a05a9f8072b89bf242558b186e83256`. Arşivde 98 dosya bulunuyor. Yapay zekanın notları kabul ölçütü olarak kullanılmadı; notlardaki iddialar kaynak ve test davranışıyla karşılaştırıldı.

Git ile takip edilen 1.152 dosyanın tamamı içerik, hash, yöntemler, bağımlılıklar ve risk işaretleri açısından dosya düzeyinde tarandı. Bunların 670'i `src/` altında: 669 PHP dosyası ve bir CSS dosyası. 168 test dosyası mevcut. Kritik iş akışları ve bunların test/kanıt dosyaları ayrıca ayrıntılı okundu; 23 hedefli davranış doğrulaması sentetik verilerle çalıştırıldı. Dosya envanteri, ayrıntılı okuma veya tekrar üretim yapılmış dosyalarla yalnızca otomatik statik taranmış dosyaları birbirinden ayırır. Her dosyanın her yürütme yolunun elle doğrulandığı veya eksiksiz bir penetrasyon testi yapıldığı iddia edilmiyor.

Üçüncü taraf `vendor/` kaynakları satır satır ürün incelemesine dahil edilmedi; Composer doğrulama ve güvenlik denetimi yapıldı. Yerel `colezahost.zip` ayrıca arşiv envanteriyle incelendi. Gerçek müşteri verisi, canlı ödeme, domain satın alma ve gerçek cPanel değişikliği kullanılmadı. MariaDB, gerçek shared hosting, sağlayıcı sandbox ve tarayıcı E2E çalıştırılmadı; bu alanlarda statik olarak kanıtlanan uyumsuzluk ile çalıştırılmamış doğrulama ayrı belirtiliyor. Ürün kaynakları değiştirilmedi; inceleme çıktıları `review/`, özgün plan kopyası `review-original-plan/` altında tutuluyor.

## Gerçekten yapılmış ve korunmaya değer işler

- Özgün kapsam, anayasa, mimari ve kabul metinleri esasen korunmuş. Normalize edilmiş karşılaştırmada 98 dosyanın 77'si aynı; değişen 21 dosya 18 fazın PLANNED→PASS durumu, ilerleme JSON'u, manifest ve README markalamasından oluşuyor. Kabul ölçütlerinin sistematik olarak yeniden yazıldığına dair bulgu yok.
- Native PHP, hafif bağımlılıklar, modular monolith hedefi ve domain isimleri genel olarak plana uygun. Production bağımlılıkları iki PSR paketinden oluşuyor. Composer yapılandırması geçerli; çalıştırılan Composer audit bilinen advisory veya terk edilmiş paket bildirmedi. Bu, uygulama güvenliği onayı değildir.
- State machine, fiyat/vergi hesaplama, tarihsel kur snapshot'ı, ödeme allocation, credit ledger, sağlayıcı sözleşmeleri, staging/checkpoint, tombstone ve belge snapshot'ı için anlamlı bileşenler mevcut.
- **900 test, 7.916 assertion çalıştı; başarısızlık/hata/skip bildirilmedi.** İki PHPUnit deprecation var. Notlarda verilen toplam sayılar mevcut test çalıştırmasıyla örtüşüyor. Testlerin gerçekten var olmadığı veya test sonuçlarının tamamının uydurma olduğu sonucuna varmıyorum.
- cPanel için gerçek cURL sınıfı, iyzico ve NameSilo için gerçek HTTP yolları, SMTP için gerçek socket transport'u mevcut. Sorun bunların bazıları çalışırken hata vermesi, varsayılanların simülasyona düşmesi ve gerçek bağlantı kanıtının bulunmaması.
- Kapsam dışındaki full reseller, affiliate, live chat, CMS, marketplace ve AI assistant ürünlerinin aktif uygulamalarını bulmadım. V1.1/V2+ plan dosyalarının repoda bulunması fazla özellik geliştirilmiş olduğu anlamına gelmiyor.

## Üretimi engelleyen bulgular

**Önceliklerin anlamı:** P0, canlı kullanımı veya Stable ilanını doğrudan engelleyen sorun; P1, V1 kabulünden önce çözülmesi gereken ciddi açık/eksik; P2, kalite ve tutarlılık sorunu. Bunlar bu incelemenin iş öncelikleridir, CVSS puanları değildir. “Tekrar üretildi” yerel ve sentetik bağlamı ifade eder; açık internetten sömürüldüğü anlamına gelmez.

### F01 — P0: Dağıtılabilir uygulama girişi ve gerçek ekranlar yok

Git envanterinde `index.php`, `public/`, uygulama route kayıtları veya `bin/coleza` girişi bulunmuyor. `Bootstrap` ortamı ve hata işleyicilerini hazırlıyor; web isteğini alıp uygulamanın yollarına bağlayan bir giriş değil. API controller'ları normal PHP yöntemleri, `/install` ise installer hizmetinin adı olarak dokümanda anlatılıyor; çalıştırılabilir kurulum sayfası bulunmuyor. Admin/client shell sınıfları HTML üretiyor ancak sipariş, fatura, ödeme, hizmet, domain, destek, ayarlar ve dashboard ekranlarını içeren bir ürün akışı yok. README ve release notes'taki ZIP'i açıp `/install` ziyaret etme talimatı bu checkout ile karşılanmıyor.

Kanıt: [Bootstrap.php:17](C:/Users/alici/OneDrive/Desktop/colezahost/src/Foundation/Bootstrap/Bootstrap.php:17), [WebInstallerService.php:18](C:/Users/alici/OneDrive/Desktop/colezahost/src/Domain/Installer/WebInstallerService.php:18), [RELEASE_NOTES.md:68](C:/Users/alici/OneDrive/Desktop/colezahost/RELEASE_NOTES.md:68). Plan: P01, P04, P17.1 ve V1 UI sözleşmesi. Düzeltme: gerçek front controller, composition root, route/middleware/asset bağları ve tüm V1 günlük işlemlerinin ekranları.

### F02 — P0: Ana hedef MariaDB olmasına rağmen kritik SQL SQLite'a özgü

Fatura, ödeme, iade, credit, service, domain fiyat/contact, notification preference ve finans sequence işlemlerinde koşulsuz `ON CONFLICT ... DO UPDATE` kullanılıyor. 13 kullanım saptandı; WHMCS üyelik aktarımında ayrıca bir `INSERT OR IGNORE` kullanımı var. `Connection` SQL'i dönüştürmeden PDO'ya gönderiyor. SQLite testleri geçerken planın ana hedefi MariaDB'de bu yollar çalışmaz. Bazı DDL'lerde yalnız ID sütununun sürücüye göre değiştirilmesi bu sorguları taşınabilir yapmıyor. Server quota azaltmada `MAX(0, ...)` de MariaDB için ayrıca gözden geçirilmeli.

Kanıt: [InvoiceService.php:395](C:/Users/alici/OneDrive/Desktop/colezahost/src/Domain/Commerce/Invoices/InvoiceService.php:395), [PaymentService.php:447](C:/Users/alici/OneDrive/Desktop/colezahost/src/Domain/Commerce/Payments/PaymentService.php:447), [ServiceService.php:1059](C:/Users/alici/OneDrive/Desktop/colezahost/src/Domain/Commerce/Services/ServiceService.php:1059), [Connection.php:233](C:/Users/alici/OneDrive/Desktop/colezahost/src/Foundation/Database/Connection.php:233). [MariaDB'nin resmi upsert sözdizimi](https://mariadb.com/docs/server/reference/sql-statements/data-manipulation/inserting-loading-data/insert-on-duplicate-key-update) `ON DUPLICATE KEY UPDATE` kullanır. Statik olarak doğrulandı; MariaDB üzerinde çalıştırılmadı. Düzeltme: MariaDB 10.11+ gerçek DB testleri ve açık sürücü uyarlaması.

### F03 — P0: Kurulum şeması ile uygulama şeması uyuşmuyor

Installer `user_roles` tablosunu `organization_id` olmadan kuruyor; `RbacService` bu alanla sorguluyor. Sıfır kurulumdan sonra yetki sorgusu **`no such column: ur.organization_id`** hatasıyla tekrar üretildi (R01). Installer `roles` şeması da RBAC'nin `scope/description` alanlarıyla aynı değil; `super_admin` ile `superadmin` adları farklı ve installer'ın `permissions_json` alanı RBAC'nin okuduğu permission tablosunun yerini tutmuyor. `CREATE TABLE IF NOT EXISTS` mevcut tabloyu düzeltmez. Production migration sınıfları bulunmuyor; MigrationInterface uygulaması yalnız testte var. Kurulumun çalışması ile kurulan ürünün işlemesi ayrı test edilmemiş.

Kanıt: [DatabaseSetupService.php:109](C:/Users/alici/OneDrive/Desktop/colezahost/src/Domain/Installer/DatabaseSetupService.php:109), [RbacService.php:161](C:/Users/alici/OneDrive/Desktop/colezahost/src/Domain/Identity/Rbac/RbacService.php:161), [AdminBootstrapService.php:45](C:/Users/alici/OneDrive/Desktop/colezahost/src/Domain/Installer/AdminBootstrapService.php:45), [Migrator.php:9](C:/Users/alici/OneDrive/Desktop/colezahost/src/Foundation/Database/Migrator.php:9). Düzeltme: tek canonical şema, versioned migrations, fresh-install→login→RBAC→commerce çapraz testleri.

### F04 — P0: Gerçek cPanel transport'u yanıt sonrasında hata veriyor

`CpanelCurlTransport` süre hesabında `$startTime` yerine **`startTime`** kullanıyor. Gerçek cURL yolunu yerel bir dosya URL'siyle, ağ bağlantısı olmadan çalıştırınca `Undefined constant "Coleza\Domain\Providers\Cpanel\startTime"` hatası alındı (R20). Hata `curl_exec` sonrasında oluşuyor: uzak sunucuda işlem yapılmışken uygulama bunu bağlantı başarısızlığı olarak görebilir. Mock transport kullanan testler bunu yakalamıyor. Sadece bu tek satır bile “production cPanel adapter PASS” kararını geçersiz kılıyor.

Kanıt: [CpanelCurlTransport.php:72](C:/Users/alici/OneDrive/Desktop/colezahost/src/Domain/Providers/Cpanel/CpanelCurlTransport.php:72), [GoldenMasterScenariosE2ETest.php:269](C:/Users/alici/OneDrive/Desktop/colezahost/tests/Integration/Golden/GoldenMasterScenariosE2ETest.php:269). Düzeltme: gerçek transport regresyonu ve dış etki sonrası belirsiz cevap testleri.

### F05 — P0: Müşteri manuel ödemeyle kendi faturasını kapatabiliyor

`PaymentApplicationService::recordManualPayment` müşteri faturasının sahipliğini kontrol ediyor ama ödeme yöntemini veya finansal onayı admin'e sınırlamıyor. `PaymentService::recordPayment` banka havalesi dışındaki yöntemleri varsayılan olarak completed kabul ediyor. Admin olmayan kullanıcı, kendi faturası için `paymentMethod='manual'` verince ödeme completed, fatura paid oldu (R04). Bu controller/Application yüzeyi henüz HTTP'ye bağlanmış değil; bağlandığında güvenli olmayacak mevcut davranış kesin olarak tekrar üretildi.

Kanıt: [PaymentApplicationService.php:92](C:/Users/alici/OneDrive/Desktop/colezahost/src/Application/Commerce/Payments/PaymentApplicationService.php:92), [PaymentService.php:196](C:/Users/alici/OneDrive/Desktop/colezahost/src/Domain/Commerce/Payments/PaymentService.php:196), [PaymentApiController.php:82](C:/Users/alici/OneDrive/Desktop/colezahost/src/Api/Controllers/Commerce/PaymentApiController.php:82). Düzeltme: müşteri kanıt gönderimi yalnız pending; settlement ayrı, granular yetkili, audit edilen finans komutu.

### F06 — P0: iyzico callback tutar ve para birimini karşılaştırmıyor

Gateway doğrulaması sonucundaki `paidAmountMinor` ve `currency`, bekleyen ödeme ile eşleştirilmiyor. Sentetik gateway 1 cent USD başarı cevabı verdiğinde beklenen **10.000 kuruş TRY** ödeme tamamen tahsil edilmiş sayıldı ve fatura paid oldu (R05). “Gateway success” tek başına para birimi, tutar, transaction ve token bağını doğrulamaz. `findPaymentByToken` ayrıca metadata'da `%token%` LIKE kullanarak kesin token eşleşmesi yerine alt dize arıyor.

Kanıt: [PaymentWebhookHandler.php:152](C:/Users/alici/OneDrive/Desktop/colezahost/src/Domain/Commerce/Payments/Gateways/PaymentWebhookHandler.php:152), [PaymentService.php:1000](C:/Users/alici/OneDrive/Desktop/colezahost/src/Domain/Commerce/Payments/PaymentService.php:1000). Düzeltme: kesin checkout token/transaction bağlama, tutar/currency doğrulaması ve uyuşmazlıkta settlement'ı durdurma.

### F07 — P0: Müşteri kendi sipariş fiyatını belirleyebiliyor

Order controller müşteri `items` verisini Application katmanına geçiriyor. `OrderService`, `unit_price_minor` varsa bunu “admin override” diye kabul ediyor ama çağıranın admin olup olmadığını bilmiyor. Gerçek PricingService'de fiyat **10.000 kuruş** ayarlanmışken admin olmayan müşteri **1 kuruş** override gönderdi; order toplamı 1 oldu (R22). PricingService olmadığı başka yolda fiyat 0'a düşüyor. Fatura yaratımında negatif fiyat ve dışarıdan subtotal/total/tax da kabul ediliyor; -10.000 toplam üretildi (R08).

Kanıt: [OrderApiController.php:37](C:/Users/alici/OneDrive/Desktop/colezahost/src/Api/Controllers/Commerce/OrderApiController.php:37), [OrderService.php:121](C:/Users/alici/OneDrive/Desktop/colezahost/src/Domain/Commerce/Orders/OrderService.php:121), [InvoiceService.php:144](C:/Users/alici/OneDrive/Desktop/colezahost/src/Domain/Commerce/Invoices/InvoiceService.php:144). Düzeltme: müşteri yalnız ürün/opsiyon/cycle seçer; authoritative fiyat ve vergi sunucuda hesaplanır. Override ayrı admin komutu olur.

### F08 — P0: Vault varsayılan anahtarı herkes için aynı

Composition root, config'de `app.key` bulunmayınca 32 tane `c` karakterinden üretilen sabit key kullanıyor. `config/app.php` bu key'i tanımlamıyor. Varsayılan container'ın şifrelediği sentetik sır, kaynakta görülen fallback key ile çözüldü (R10). AES kullanılması yeterli değil; aynı bilinen anahtar credential korumasını boşa çıkarıyor.

Kanıt: [CoreServiceProvider.php:223](C:/Users/alici/OneDrive/Desktop/colezahost/src/Foundation/Bootstrap/CoreServiceProvider.php:223), [app.php:5](C:/Users/alici/OneDrive/Desktop/colezahost/config/app.php:5). Düzeltme: kurulumda rastgele kurulum başına key; eksik key'de fail closed; key dışa aktarımı, rotasyonu ve recovery planı.

### F09 — P0: “Encrypted” denilen bazı sırlar düz metin

SMTP parolası `is_encrypted=1` etiketiyle ancak şifrelenmeden kaydediliyor (R03). WHM auth secret `auth_secret_encrypted` alanına aynen yazılıyor (R21); iki alan adında encryption geçmesi gerçek encryption değil. TOTP secret de `TwoFactorService` tarafından doğrudan DB'ye yazılıyor. ProviderSettingService/Vault hattı daha iyi tasarlanmış olsa da bu alternatif saklama yollarını korumuyor. Kullanılan probe sırları sentetiktir; gerçek credential sızıntısı saptandığı iddia edilmiyor.

Kanıt: [EmailSetupService.php:36](C:/Users/alici/OneDrive/Desktop/colezahost/src/Domain/Installer/EmailSetupService.php:36), [ServerService.php:366](C:/Users/alici/OneDrive/Desktop/colezahost/src/Domain/Servers/Services/ServerService.php:366), [TwoFactorService.php:97](C:/Users/alici/OneDrive/Desktop/colezahost/src/Domain/Identity/TwoFactor/TwoFactorService.php:97). Düzeltme: tek scoped Vault yolu, TOTP için güvenli şifreleme ve mevcut kayıtlar için migration.

### F10 — P0: SFTP/S3 yedeği hiç aktarılmadan yerel kopya siliniyor

SFTP ve S3 adapter'ları varsayılan olarak bellekte simüle ediyor. `simulated=false` ile production seçildiğinde store/retrieve/delete yöntemleri gerçek bağlantı yapmadan true döndürüyor. EnterpriseBackupService store sonucunu doğrulamadan uzak destination için yerel ZIP ve manifesti siliyor. Production S3 modunda DB yedeği oluşturuldu; **uzakta yedek yok, yerel arşiv yok, yerel manifest yok** sonucu tekrar üretildi (R07). Bu doğrudan recoverability sorunudur.

Kanıt: [S3BackupStorageAdapter.php:25](C:/Users/alici/OneDrive/Desktop/colezahost/src/Domain/Backup/Storage/S3BackupStorageAdapter.php:25), [SftpBackupStorageAdapter.php:44](C:/Users/alici/OneDrive/Desktop/colezahost/src/Domain/Backup/Storage/SftpBackupStorageAdapter.php:44), [EnterpriseBackupService.php:126](C:/Users/alici/OneDrive/Desktop/colezahost/src/Domain/Backup/EnterpriseBackupService.php:126). Düzeltme: gerçek transport, uzak checksum/read-back doğrulaması; doğrulanmış ikinci kopyadan önce yerel silme yapılmaması.

### F11 — P0: Ana veritabanı için gerçek backup dump yok

`EnterpriseBackupService` ve pre-update backup dump yalnız sqlite sürücüsünde tablo/veri üretiyor. MySQL/MariaDB yolunda açıklama satırları dönüyor; DB-only backup “var” görünebilir ama verileri taşımaz. File backup dizinleri dolaşmıyor, sadece tekil dosyaları `basename` ile topluyor; farklı dizinlerde aynı adlı dosyalar çakışabilir. DB verilmeden FULL/DATABASE çağrısı da açık bir başarısızlık üretmiyor. Bunlar master plandaki full/DB/files + fresh-host recovery kabulünü karşılamıyor.

Kanıt: [EnterpriseBackupService.php:153](C:/Users/alici/OneDrive/Desktop/colezahost/src/Domain/Backup/EnterpriseBackupService.php:153), [PreUpdateBackupService.php:207](C:/Users/alici/OneDrive/Desktop/colezahost/src/Domain/Backup/PreUpdateBackupService.php:207), [EnterpriseBackupService.php:81](C:/Users/alici/OneDrive/Desktop/colezahost/src/Domain/Backup/EnterpriseBackupService.php:81). Statik doğrulandı. Düzeltme: MariaDB consistent snapshot/export, recursive göreli path koruma, kapsam eksikliğinde explicit fail.

### F12 — P1: Basit bir apostrof bile restore'u bozuyor

SQLite dump'ta değerler PDO quote yerine `addslashes` ile SQL'e ekleniyor. `O'Reilly` içeren tek satırın backup→restore denemesi SQL syntax error verdi (R11). SQL satır sonuna göre bölünüyor, restore transaction ve zorunlu sonrası health/mode açma denetimi yok. Hata öncesi bazı SQL'ler uygulanmış olsa bile sonuç sayaçları sıfır dönerek kısmi değişikliği de gizleyebiliyor.

Kanıt: [EnterpriseBackupService.php:169](C:/Users/alici/OneDrive/Desktop/colezahost/src/Domain/Backup/EnterpriseBackupService.php:169), [RestoreWizardService.php:265](C:/Users/alici/OneDrive/Desktop/colezahost/src/Domain/Backup/RestoreWizardService.php:265). Düzeltme: doğru dump/restore formatı, binary/multiline/Unicode veri testleri, kısmi restore recovery protokolü.

### F13 — P0: Updater migrasyonları çalıştırmadan başarı sayıyor

`applyValidatedUpdate` migrasyon listesini dolaşıp yalnız sayacı artırıyor; SQL veya migration çalıştırmıyor. İmzalı sentetik paket için `success=true, migrations_run=1` döndü, hedef tablo oluşmadı (R13). Aynı yöntem `requireBackup=false` ile zorunlu backup'ı atlamaya izin veriyor. Dosyalara doğrudan copy yapılması toplu atomic update değil; ortada hatada rollback/Recovery bağlama yok. ReleasePackagingService `payload/`, updater `files/` dizinini bekliyor; iki paket sözleşmesi de farklı.

Kanıt: [StagedUpdateService.php:240](C:/Users/alici/OneDrive/Desktop/colezahost/src/Domain/Updater/StagedUpdateService.php:240), [StagedUpdateService.php:183](C:/Users/alici/OneDrive/Desktop/colezahost/src/Domain/Updater/StagedUpdateService.php:183), [ReleasePackagingService.php:115](C:/Users/alici/OneDrive/Desktop/colezahost/src/Domain/Release/ReleasePackagingService.php:115). Düzeltme: gerçek migrator, zorunlu verified backup, staged swap/rollback, tek paket sözleşmesi, hata halinde Normal Mode'a açılmama.

### F14 — P1: Otomasyonun kritik durumu istek bitince kayboluyor

ApprovalManager, DelayManager, RuleVersionManager ve RunHistory bellekte array tutuyor. EmergencyPauseManager her yeni instance'da active başlıyor. Bir istekte Pause All verilince paused=true, yeni instance'da false oldu (R06). Shared hosting'de farklı HTTP/cron süreçleri bu belleği paylaşmaz; onay, gecikme, sürüm ve run history kalıcı değildir. “Emergency Pause” üretim güvenliği için bu haliyle güvenilir değil.

Kanıt: [ApprovalManager.php:14](C:/Users/alici/OneDrive/Desktop/colezahost/src/Domain/Automation/Approval/ApprovalManager.php:14), [DelayManager.php:14](C:/Users/alici/OneDrive/Desktop/colezahost/src/Domain/Automation/Delay/DelayManager.php:14), [EmergencyPauseManager.php:16](C:/Users/alici/OneDrive/Desktop/colezahost/src/Domain/Automation/Safety/EmergencyPauseManager.php:16), [RuleVersionManager.php:14](C:/Users/alici/OneDrive/Desktop/colezahost/src/Domain/Automation/Versioning/RuleVersionManager.php:14). Düzeltme: transaction'lı kalıcı repository; farklı süreçlerden okuma ve restart testleri.

### F15 — P1: Aynı paid invoice tekrar işlendiğinde hizmet tekrar uzuyor

`OverdueLifecycleWorkflow::handleInvoicePaid` aynı invoice/service döneminin daha önce işlendiğini saklamıyor. Aynı paid invoice iki kere verildiğinde hizmet tarihi önce 1 Aralık 2026'ya, sonra 1 Ocak 2027'ye ilerledi (R19). Ayrıca item'ın renewal invoice'a ait olması yerine herhangi bir service bağlı paid invoice üzerinden yenileme deneniyor. Callback tekrarları ve cron catch-up normal operasyonlarda oluşabilir.

Kanıt: [OverdueLifecycleWorkflow.php:66](C:/Users/alici/OneDrive/Desktop/colezahost/src/Domain/Commerce/Services/Lifecycle/OverdueLifecycleWorkflow.php:66), [OverdueLifecycleWorkflow.php:113](C:/Users/alici/OneDrive/Desktop/colezahost/src/Domain/Commerce/Services/Lifecycle/OverdueLifecycleWorkflow.php:113). Düzeltme: invoice+service+billing period unique işlem kaydı ve atomic command.

### F16 — P1: “Concurrency certified” iddiası kritik mutasyonları kapsamıyor

Queue pop, read→update rezervasyonunu row lock veya conditional update olmadan yapıyor. Payment approval/allocation/refund ve credit deduction gibi yolların okuma/doğrulama/yazma zincirleri tek atomic transaction/lock ile korunmuyor. Kapasite check ayrı read, allocation artırımı ayrı update. Webhook event tablosunda gateway+event_id unique constraint yok. Invoice paid amount tek read→write ile değişiyor; geçerli allocation olmadan 10.000 toplam için 20.000 paid kabul edildi (R09). Gerçek iki MariaDB worker ile yarış üretimi yapılmadı; burada risk kaynak incelemesiyle saptandı. Mevcut ardışık çağrı testleri paralel yarış kanıtı değildir.

Kanıt: [DatabaseQueue.php:76](C:/Users/alici/OneDrive/Desktop/colezahost/src/Foundation/Queue/DatabaseQueue.php:76), [CreditService.php:166](C:/Users/alici/OneDrive/Desktop/colezahost/src/Domain/Commerce/Credit/CreditService.php:166), [CapacityReservationService.php:115](C:/Users/alici/OneDrive/Desktop/colezahost/src/Domain/Servers/Capacity/Services/CapacityReservationService.php:115), [InvoiceService.php:351](C:/Users/alici/OneDrive/Desktop/colezahost/src/Domain/Commerce/Invoices/InvoiceService.php:351). Düzeltme: transaction+row/lease locks+DB constraint+çok süreçli gerçek DB failure testleri.

### F17 — P0: Analytics gerçek ticaret tablolarını okuyamıyor; sıfır döndürüyor

Ticaret domaini `total_minor/amount_minor/currency_code` kullanırken analytics `total_amount/amount/currency` alanlarını ve `hosting_services` tablosunu bekliyor. SQL hataları catch edilip yok sayılıyor. Gerçek InvoiceService ile oluşturulan 10.000 kuruşluk tek invoice için analytics **invoice_count=0, gross_invoiced=0** verdi (R12). Finance metric yöntemlerindeki currency parametresi birçok sorguda filtreye dönüşmüyor; farklı para birimlerini tek toplamda toplama riski de var. Testler bu farklı alanlarla bağımsız fixture tabloları kurduğu için sorun geçiyor.

Kanıt: [FinancialMetricsService.php:40](C:/Users/alici/OneDrive/Desktop/colezahost/src/Domain/Analytics/Financial/FinancialMetricsService.php:40), [PerformanceAndReferenceBudgetRegressionTest.php:190](C:/Users/alici/OneDrive/Desktop/colezahost/tests/Integration/Performance/PerformanceAndReferenceBudgetRegressionTest.php:190), [SubscriptionSnapshotService.php:75](C:/Users/alici/OneDrive/Desktop/colezahost/src/Domain/Analytics/Subscription/SubscriptionSnapshotService.php:75). Düzeltme: canonical domain query/read model sözleşmeleri, gerçek commerce fixture→analytics reconciliation ve currency ayrımı.

### F18 — P1: Privacy export gerçek verilerin bir bölümünü sessizce atlıyor

DefaultPrivacyDataCollector yine farklı şema adları kullanıyor: orders `total_amount/currency`, invoices `total/currency`, services `hosting_services`, domains `fqdn` vb. Hatalarda boş liste dönüyor. Kullanıcının gerçek invoice'u varken export invoices boş çıktı (R17). “Export başarılı” sonucu verilerin tam olduğu anlamına gelmiyor.

Kanıt: [DefaultPrivacyDataCollector.php:99](C:/Users/alici/OneDrive/Desktop/colezahost/src/Domain/Privacy/Export/DefaultPrivacyDataCollector.php:99), [DefaultPrivacyDataCollector.php:111](C:/Users/alici/OneDrive/Desktop/colezahost/src/Domain/Privacy/Export/DefaultPrivacyDataCollector.php:111). Düzeltme: domain-owned collector sözleşmeleri, completeness/accounting ve bilinmeyen tablo hatasında explicit failure.

### F19 — P0: Privacy erasure eski parola ile erişimi açık bırakıyor

Erasure service geniş users şemasını varsayıyor; mevcut installer şemasında fallback yalnız name/email/status değiştiriyor, `password_hash` ve `is_active` kalıyor. Silinmiş olarak işaretlenen kullanıcının yeni anonim email'i ve **eski parolasıyla authenticate başarılı** oldu (R16). Taslakta sessions/tokens silme maddesi olmasına rağmen execute yolu bunları gerçekten temizlemiyor. Active hosting/domain kontrolleri de yanlış tablo/sütunlar yüzünden fail open olabilir. “isSuccess=true” bu davranışı yanlış temsil ediyor.

Kanıt: [PrivacyErasureService.php:267](C:/Users/alici/OneDrive/Desktop/colezahost/src/Domain/Privacy/Erasure/PrivacyErasureService.php:267), [AuthService.php:130](C:/Users/alici/OneDrive/Desktop/colezahost/src/Domain/Identity/Auth/AuthService.php:130)], [PrivacyErasureService.php:174](C:/Users/alici/OneDrive/Desktop/colezahost/src/Domain/Privacy/Erasure/PrivacyErasureService.php:174). Düzeltme: erişimi atomic kapatma; sessions/API/trusted devices/2FA secrets revoke; gerçek domain verisiyle blockers.

### F20 — P1: Tombstone restore koruması zorunlu değil ve hata yutuyor

Restore reconciliation isteğe bağlı constructor argümanı; verilmeden restore success dönüyor. Reconciliation başarısız scrub'ları yutabiliyor ve sayaç artırabiliyor; restore, reconciliation report'un clean olmasını zorunlu kılmıyor. FileTombstoneStore yalnız son yazmada LOCK_EX kullanıyor; read→merge→write tek kilit altında değil ve bozuk JSON boş store gibi yorumlanıyor. Beş analytics/privacy dosyası mevcut olmayan `Coleza\Domain\Audit\AuditLogger` sınıfını import ediyor; gerçek Identity AuditLogger bu tipe enjekte edilemiyor. Varsayılan tombstone key/salt da sabit.

Kanıt: [RestoreWizardService.php:229](C:/Users/alici/OneDrive/Desktop/colezahost/src/Domain/Backup/RestoreWizardService.php:229), [BackupRestoreReconciliationService.php:7](C:/Users/alici/OneDrive/Desktop/colezahost/src/Domain/Privacy/Tombstone/BackupRestoreReconciliationService.php:7), [FileTombstoneStore.php:27](C:/Users/alici/OneDrive/Desktop/colezahost/src/Domain/Privacy/Tombstone/FileTombstoneStore.php:27), [PrivacyTombstoneService.php:19](C:/Users/alici/OneDrive/Desktop/colezahost/src/Domain/Privacy/Tombstone/PrivacyTombstoneService.php:19). Düzeltme: zorunlu fail-closed scrub, health/unseal bariyeri, kalıcı dış store ve doğru audit sözleşmesi.

### F21 — P1: Destek komutları hiçbir şey yapmadan “yapıldı” diyor

Builtin `service.reboot`, `service.sync_status`, `domain.sync_whois` ve `invoice.resend_notification` gerçek Application/provider/mail çağrısı yerine success metni oluşturuyor. Gerçekte olmayan service #12345 için, handler kayıtlı olmadan reboot çağrısı **“command dispatched”, success=true** döndü (R23). Staff kontrolü yalnız pozitif ID; gerçek izin kontrolü yok. cPanel V1 account operasyonları içinde reboot'un neyi yeniden başlattığı da tanımlı değil. Bu, doğrudan kullanıcının “ne alaka?” sorusuna karşılık gelen davranışlardan biri.

Kanıt: [TicketContextService.php:184](C:/Users/alici/OneDrive/Desktop/colezahost/src/Domain/Support/Context/TicketContextService.php:184), [TicketContextService.php:100](C:/Users/alici/OneDrive/Desktop/colezahost/src/Domain/Support/Context/TicketContextService.php:100). Düzeltme: komutların gerçek Application handler'ları; desteklenen capability yoksa unavailable/failure; backend RBAC.

### F22 — P1: Ortak Application katmanı yalnız küçük bir bölümü kapsıyor

Application klasöründe yalnız Orders/Invoices/Payments/Quotes için dört hizmet ve dört command DTO var. Support, privacy, migration, provisioning, renewal, domain, installer ve automation işlemleri çoğunlukla domain hizmetlerini doğrudan çağırıyor. Automation adapter'ı `ServiceService` çağırıyor; foundation service provider business domainlerini kaydediyor. Katman isimleri plana benziyor fakat sınırlar planın emrettiği Command/Query entry point üzerinden uygulanmıyor. Architecture testleri yalnız birkaç namespace/strict_types regex'ini ölçüyor; cross-domain repository erişimini kapsamlı doğrulamıyor.

Kanıt: [ServiceSuspendActionHandler.php:48](C:/Users/alici/OneDrive/Desktop/colezahost/src/Domain/Automation/Adapters/Service/ServiceSuspendActionHandler.php:48), [CoreServiceProvider.php:152](C:/Users/alici/OneDrive/Desktop/colezahost/src/Foundation/Bootstrap/CoreServiceProvider.php:152), [ArchitectureTest.php:40](C:/Users/alici/OneDrive/Desktop/colezahost/tests/Architecture/ArchitectureTest.php:40). Düzeltme: bütün V1 iş akışları için yetkili Application Commands/Queries; platform ve domain DI bileşimini ayırma; gerçek dependency kuralları.

### F23 — P1: RBAC, 2FA, API scope ve bakım mode'u operasyonlara bağlanmamış

ApiKeyService, RateLimiter, TwoFactorService, ImpersonationService ve mode guard ayrı bileşenler olarak mevcut. Ancak gerçek auth/session/CSRF/API middleware zinciri ve route bileşimi yok. Application yöntemlerinde null auth ID kontrolü atlayabiliyor; admin yetkisi granular permission yerine bool. Organization ID'leri request'ten alınırken gerçek üyelik yetkisi garanti edilmiyor. Admin 2FA enforce yöntemi var fakat production login zincirinde kullanıldığı gösterilmiyor. Önemli güvenlik primitives yazılmış olması, tüm V1 işlemlerinin bunlarla korunması değildir.

Kanıt: [InvoiceApplicationService.php:20](C:/Users/alici/OneDrive/Desktop/colezahost/src/Application/Commerce/Invoices/InvoiceApplicationService.php:20), [ApiKeyService.php:83](C:/Users/alici/OneDrive/Desktop/colezahost/src/Api/Auth/ApiKeyService.php:83), [TwoFactorService.php:289](C:/Users/alici/OneDrive/Desktop/colezahost/src/Domain/Identity/TwoFactor/TwoFactorService.php:289), [OperationalModeManager.php:135](C:/Users/alici/OneDrive/Desktop/colezahost/src/Domain/OperationalMode/OperationalModeManager.php:135). Düzeltme: explicit actor/org/system context, default-deny backend yetkisi ve gerçek HTTP security testleri.

### F24 — P1: UI/TR-EN ve erişilebilirlik beyanı ürün düzeyinde kanıtlanmıyor

AdminShell `/assets/admin.css`, ClientShell `/assets/client.css`, her ikisi `/assets/tokens.css` istiyor; bu deployed asset yolları yok. `src/Ui/tokens.css` dışında uygulama CSS dosyası ve takip edilen JS dosyası yok. Ctrl+K, drawer/modal focus yönetimi, theme/density davranışları uygulanmış progressive JS olarak bulunmuyor. Shell metinleri “Search or jump to”, “Administrator”, “No pending operational actions” gibi hardcoded English; `<html lang="tr">` sabit. Dictionary key parity testi iki mevcut sözlüğün eşitliğini ölçüyor, tüm kullanıcı metinlerinin çevrildiğini değil. A11y testi HTML regex ve sabit renk örnekleri; gerçek sayfa, klavye/focus, mobile, screenshot/visual regression kanıtı yok.

Kanıt: [AdminShell.php:43](C:/Users/alici/OneDrive/Desktop/colezahost/src/Ui/Admin/AdminShell.php:43), [ClientShell.php:41](C:/Users/alici/OneDrive/Desktop/colezahost/src/Ui/Client/ClientShell.php:41), [AccessibilityValidator.php:14](C:/Users/alici/OneDrive/Desktop/colezahost/src/Ui/Accessibility/AccessibilityValidator.php:14), [LocalizationCompletenessAndAccessibilitySuiteTest.php:118](C:/Users/alici/OneDrive/Desktop/colezahost/tests/Integration/LocalizationAndAccessibility/LocalizationCompletenessAndAccessibilitySuiteTest.php:118). Düzeltme: gerçek günlük ekranlar, asset build/bundle, translator integration, browser ve visual testler. “WCAG 2.1 AA compliant” beyanı mevcut kanıtla kabul edilemez.

### F25 — P1: PDF uzun belgeyi kesiyor ve Türkçe Unicode'u korumuyor

PurePhpPdfRenderer hep bir sayfa üretiyor; y koordinatı 60'ın altına inince yeni sayfa açmadan break yapıyor. 100 satırlık belge tek sayfa kaldı; son satır yok (R15). Metin ISO-8859-1'e çevriliyor, standart Helvetica/WinAnsi font kullanılıyor; Türkçe ğ/ı/ş gibi karakterlerin Unicode gösterimi sağlanmıyor. Plan, Unicode/page-break/visual regression testlerini açıkça zorunlu kılmış.

Kanıt: [PurePhpPdfRenderer.php:39](C:/Users/alici/OneDrive/Desktop/colezahost/src/Domain/Documents/Rendering/PurePhpPdfRenderer.php:39), [PurePhpPdfRenderer.php:140](C:/Users/alici/OneDrive/Desktop/colezahost/src/Domain/Documents/Rendering/PurePhpPdfRenderer.php:140), [PurePhpPdfRenderer.php:169](C:/Users/alici/OneDrive/Desktop/colezahost/src/Domain/Documents/Rendering/PurePhpPdfRenderer.php:169). Düzeltme: Unicode font gömme, gerçek pagination/table layout, uzun TR/EN fatura render testleri.

### F26 — P1: System Doctor yanlış queue'ya bakıp sağlıklı diyor

DatabaseQueue `jobs/failed_jobs` kullanıyor, Doctor ise kendi `background_jobs` tablosunu yaratıp onu okuyor. Gerçek failed_jobs'ta 20 hata varken Doctor **healthy, failed=0** bildirdi (R18). Cron zaman alanları `run_at` ve `ran_at` arasında çelişiyor. Provider health gerçek authentication değil active server sayısı; sıfır server da healthy olabilir. Health sorgularının tablo yaratması da canonical schema'yı bozabilecek bir yan etki. Operatör bu göstergelere güvenemez.

Kanıt: [SystemDoctorService.php:172](C:/Users/alici/OneDrive/Desktop/colezahost/src/Domain/Health/SystemDoctorService.php:172), [DatabaseSetupService.php:194](C:/Users/alici/OneDrive/Desktop/colezahost/src/Domain/Installer/DatabaseSetupService.php:194), [SystemDoctorService.php:141](C:/Users/alici/OneDrive/Desktop/colezahost/src/Domain/Health/SystemDoctorService.php:141)]. Düzeltme: queue/scheduler/provider sözleşmelerinden gerçek durumu okuma; salt okunur health probes; production fixture'larla test.

### F27 — P1: Entegrasyon hazır sayılıyor, gerçek sağlayıcı kanıtı yok

Installer e-posta testi varsayılan MemoryMailTransport kullanıyor. `invalid.example` SMTP host'u ile **success=true, transport=memory** sonucu alındı; hiçbir e-posta gönderilmedi (R02). NotificationEngine default'u da memory. iyzico auth raw JSON üzerinden SHA1/IYZWS üretiyor; [güncel resmi iyzico dokümanı](https://docs.iyzico.com/en/getting-started/preliminaries/authentication/hmacsha256-auth) URI+body içeren HMAC-SHA256/IYZWSv2 tanımlıyor. Bu uyum farkı gerçek sandbox testiyle kapanmalı; eski protokolün her ortamda tamamen kaldırıldığı iddia edilmiyor. NameSilo renewal expiry, mevcut uzak expiry yerine bugün+years olarak üretiliyor; registration contact bilgisi request'e aktarılmıyor. NameSilo seçimi notta yazıyor, fakat mevcut sağlayıcının kullanıcı tarafından kilitlendiğine ilişkin onay/ADR bulunmadı.

Kanıt: [EmailSetupService.php:67](C:/Users/alici/OneDrive/Desktop/colezahost/src/Domain/Installer/EmailSetupService.php:67), [NotificationEngine.php:24](C:/Users/alici/OneDrive/Desktop/colezahost/src/Domain/Notifications/NotificationEngine.php:24), [IyzicoPaymentGateway.php:268](C:/Users/alici/OneDrive/Desktop/colezahost/src/Domain/Commerce/Payments/Gateways/Iyzico/IyzicoPaymentGateway.php:268), [NameSiloRegistrarAdapter.php:135](C:/Users/alici/OneDrive/Desktop/colezahost/src/Domain/Domains/Registrar/Adapters/NameSilo/NameSiloRegistrarAdapter.php:135), [P12.4-execution-record.md:14](C:/Users/alici/OneDrive/Desktop/colezahost/evidence/v1/P12/P12.4-execution-record.md:14). Düzeltme: açık provider kararı, gerçek sandbox contract suites, SMTP delivery kanıtı ve expiry/contact doğruluğu.

### F28 — P0: PASS dosyasının varlığı gerçek gate onayı yerine kullanılıyor

`check_gate.py` status'un izinli değer olması ve evidence dizininin varlığına bakıyor; alt faz kabulünü, gerçek test çıktısını, reviewer'ı veya blocker'ı doğrulamıyor. `verify_constitutions.py` anayasa dosyalarının varlığı/uzunluğunu inceliyor; kodun anayasalara uyduğunu ölçmüyor. Son audit araçları ve RC soak testleri PASS kelimesini/evrak varlığını yeniden doğrulayıp “certified” diyor. 19 fazın hiçbirinde ayrı reviewer report veya changed-files.txt yok; P07–P18'de manifest.json ve ayrı test-results.txt bulunmuyor. UI screenshot yok. Execution record içinde komut çıktısı metni bulunması bu bağımsız kanıtları ikame etmez. Ayrı Gatekeeper kimliği/bağımsız değerlendirme ve süreli RC soak kanıtı doğrulanamadı.

Kanıt: [check_gate.py:47](C:/Users/alici/OneDrive/Desktop/colezahost/tools/check_gate.py:47), [verify_constitutions.py:37](C:/Users/alici/OneDrive/Desktop/colezahost/tools/verify_constitutions.py:37), [audit_full_v1_completeness.py:46](C:/Users/alici/OneDrive/Desktop/colezahost/tools/audit_full_v1_completeness.py:46), [ReleaseCandidateSoakAndGateApprovalTest.php:44](C:/Users/alici/OneDrive/Desktop/colezahost/tests/Integration/ReleaseCandidate/ReleaseCandidateSoakAndGateApprovalTest.php:44), [gate-decision.md:78](C:/Users/alici/OneDrive/Desktop/colezahost/evidence/v1/P18/gate-decision.md:78). Düzeltme: immutable commit-bound raw evidence, reviewer identity, gerçek coverage/static/security/performance raporları ve blocker-aware gate.

### F29 — P1: CI ve güvenlik taraması zorunlu kabul kapsamını ölçmüyor

GitHub workflow yalnız plan validation ve manifest verification çalıştırıyor; PHP kurulum/test/real DB/security/browser/coverage yok. “Static/security” aracı sınırlı regex ve strict_types kontrolü; PHPStan/Psalm eşdeğeri type/undefined-symbol analizi yok. Sabit key, plaintext encrypted sütunu ve undefined startTime bu taramalarla PASS oluyor. Forbidden scan `@$object->...` ararken `@file_get_contents/@unserialize` yollarını kapsamıyor. Mutation runner/skor veya coverage raporu bulunmuyor. Paketlerin yalnız PSR olmasını kontrol etmek shared-host uyumluluğu veya tüm gereksinimlerin doğruluğu demek değil.

Kanıt: [verify-integrity.yml:1](C:/Users/alici/OneDrive/Desktop/colezahost/.github/workflows/verify-integrity.yml:1), [scan_security_and_secrets.py:25](C:/Users/alici/OneDrive/Desktop/colezahost/tools/scan_security_and_secrets.py:25), [scan_forbidden_actions.py:13](C:/Users/alici/OneDrive/Desktop/colezahost/tools/scan_forbidden_actions.py:13), [phpunit.xml:20](C:/Users/alici/OneDrive/Desktop/colezahost/phpunit.xml:20). Düzeltme: PHP+MariaDB CI, gerçek static analysis, meaningful security/permission/property/mutation/browser testleri; threshold değişikliklerinde change control.

### F30 — P1: Golden/shared-host/performance testleri ürün gerçeğini sadeleştiriyor

G02 açıklaması iyzico→Webhook→Queue→Risk zincirini söylerken test doğrudan `recordPayment(status=completed)` çağırıyor; gerçek gateway callback, risk kararı ve DBQueue yolu yürümüyor. cPanel ve email Memory transport. SharedHost matrix gerçek cPanel/Plesk/shared hosting kurulumu yerine mevcut makinede SQLite testleri. Performance testleri küçük bellek içi tablolar; canonical ticaret şemasıyla invoice listesi **100 satıra 101 query** yaptı (R14), planın N+1 ve <=30 primary-page hedefi bu yolda sağlanmıyor. G01–G08 için kaynakta testler var; gate-decision notlarında senaryoların ad/numara açıklamaları ayrıca özgün planla uyuşmuyor. Sorun test yokluğu değil, testin kabul akışının tamamını yürütmemesi.

Kanıt: [GoldenMasterScenariosE2ETest.php:378](C:/Users/alici/OneDrive/Desktop/colezahost/tests/Integration/Golden/GoldenMasterScenariosE2ETest.php:378), [SharedHostCompatibilityMatrixTest.php:59](C:/Users/alici/OneDrive/Desktop/colezahost/tests/Integration/SharedHost/SharedHostCompatibilityMatrixTest.php:59), [InvoiceService.php:322](C:/Users/alici/OneDrive/Desktop/colezahost/src/Domain/Commerce/Invoices/InvoiceService.php:322)], [gate-decision.md:19](C:/Users/alici/OneDrive/Desktop/colezahost/evidence/v1/P18/gate-decision.md:19). Düzeltme: gerçek Application/HTTP/DB/queue yolu ve provider sandbox; belgelenmiş reference host ile N+1 list bütçeleri.

### F31 — P1: Extension sistemi planın güvenli kurulum zincirini tamamlamıyor

ModuleManifest ve install/enable/disable kayıtları var. Install yalnız min core version ve duplicate kontrol edip DB kaydı yaratıyor. Planın quarantine→path/symlink/zip-bomb scan→compatibility→permissions→migration preview→health→enable zinciri yok; namespaced route/storage/table ve network/Vault permission enforcement da üretim entegrasyonu olarak gösterilemiyor. Bir module manifest'inin permissions alanını taşımak, modülün bunu aşamamasını sağlamak değildir.

Kanıt: [ModuleLifecycleService.php:90](C:/Users/alici/OneDrive/Desktop/colezahost/src/Domain/Module/ModuleLifecycleService.php:90), [ModuleManifest.php:9](C:/Users/alici/OneDrive/Desktop/colezahost/src/Domain/Module/ModuleManifest.php:9). Düzeltme: güvenli package installer, capability/permission enforcement, extension contract suite. Shared-host PHP için OS sandbox iddiası eklenmemeli.

### F32 — P1: WHMCS cutover bazı zorunlu kanıtlar eksikken geçebiliyor

Migration tarafında staging, locale CSV, read-only connector, checkpoint, conflict ve unsupported accounting için değerli işler yapılmış. Ancak CutoverChecklistService finansal rapor verilmediğinde `financialPassed=true` ve “verified” mesajı üretiyor; unsupported accountant/checkpoint opsiyonelse onlar da true başlıyor. Seal, rapor yoksa financial summary'yi reconciled diye yazabiliyor. Safety hold ve notification suppression servisleri mevcut olsa da gerçek login/billing/provisioning/mail girişlerine zorunlu bağlanmış ürün akışı yok. “Zero silent loss” assertion'ı tabloları saymak kadar kaynak veri, attachment ve terminal durum completeness'ını da kapsamalı.

Kanıt: [CutoverChecklistService.php:72](C:/Users/alici/OneDrive/Desktop/colezahost/src/Domain/Migration/Cutover/CutoverChecklistService.php:72), [CutoverChecklistService.php:179](C:/Users/alici/OneDrive/Desktop/colezahost/src/Domain/Migration/Cutover/CutoverChecklistService.php:179)], [MigrationHoldService.php:11](C:/Users/alici/OneDrive/Desktop/colezahost/src/Domain/Migration/Hold/MigrationHoldService.php:11). Düzeltme: tüm required raporlar zorunlu; canonical fresh-install şeması üzerinde göç→ticaret→privacy/analytics; gerçek WHMCS sürüm fixture ve cutover rehearsals.

### F33 — P2: Release notları ve dağıtım paketi uygulamadan daha ileri iddialar taşıyor

Release notes “unparalleled stability”, “double-entry”, “circuit breaker”, “WCAG compliant”, “0 PII resurrection guarantee” gibi kanıtı bulunmayan veya yukarıdaki sorunlarla çelişen beyanlar taşıyor. Minimum MariaDB 10.5/SQLite desteği, master plandaki MariaDB 10.11+ ana hedefiyle aynı sözleşme değil. P18 phase dosyası halen PLANNED, current-state ve gate dosyası PASS. Yerel `colezahost.zip` içinde vendor var; ancak web entrypoint, release-root manifest/signature/checksums yok. Arşiv ayrıca 1.337 `.git/` kaydı ve PHPUnit cache içeriyor; production dağıtımına uygun ayrılmış paket değil. Sadece paketleme sınıfının sentetik küçük dosyaları ZIP'lemesi tam ürün release artefact'ı değildir.

Kanıt: [RELEASE_NOTES.md:11](C:/Users/alici/OneDrive/Desktop/colezahost/RELEASE_NOTES.md:11), [P18-v1-release-candidate-stable-gate.md:4](C:/Users/alici/OneDrive/Desktop/colezahost/06-phases/v1/P18-v1-release-candidate-stable-gate.md:4), [ReleasePackagingService.php:38](C:/Users/alici/OneDrive/Desktop/colezahost/src/Domain/Release/ReleasePackagingService.php:38). Düzeltme: doğru RC statüsü, gerçek paket reproducibility/kurulum testi, gerçek ölçümü aşmayan release notes.

## Faz faz değerlendirme

“Kısmi” çalışan ve korunabilir bileşenler bulunduğu; “engelli” belirli kabul ölçütlerinin yanlış/eksik olduğu anlamındadır. Hiçbir faz için bu checkout'un production gate'ini yeniden onaylamıyorum. Alt fazların tamamının özgün adları, execution record varlığı, kaynak/test referansları ve inceleme notları `ALT_FAZ_MATRISI.md` dosyasında ayrı ayrı yer alıyor.

| Faz | Mevcut gerçek durum | Kapanması gereken esas fark |
|---|---|---|
| P00 — Kontrol/yönetişim | Kısmi | Plan korunuyor; CI ve gate gerçek kabulü zorlamıyor. F28/F29 |
| P01 — Foundation | Kısmi, engelli | Primitives var; executable uygulama, canonical migrations ve doğru layer sınırı yok. F01/F02/F03/F22 |
| P02 — Platform primitives | Kısmi | Queue/cache/lock/scheduler var; atomiklik ve gerçek cron/health bağları eksik. F16/F26 |
| P03 — Identity/security | Kısmi, engelli | Auth/TOTP/RBAC/Vault var; kurulum şema çatışması, sabit key, plaintext ve zorunlu guard zinciri yok. F03/F08/F09/F23/F31 |
| P04 — UI/API | Büyük ölçüde iskelet | Bileşen HTML'i var; günlük ekran, assets/JS ve route/middleware yok. F01/F23/F24 |
| P05 — Katalog/fiyat/vergi | Korunabilir çekirdek, kısmi | Hesaplayıcı testleri var; müşteri fiyat override ve ticaret sözleşmesi bunları bypass ediyor. F07/F30 |
| P06 — Manual commerce | Kısmi, engelli | State/allocation/ledger var; yetkisiz settlement, MariaDB ve transaction garantileri eksik. F02/F05/F07/F16 |
| P07 — Finans/belgeler | Kısmi | Baseline entities mevcut; PDF kesilmesi/Unicode, DB portability ve gerçek arayüz eksik. F02/F25 |
| P08 — Entegrasyon | Kısmi, engelli | HTTP/SMTP yolları var; yanlış callback validation ve false-success defaults. F06/F23/F27 |
| P09 — Service/provider/server | Kısmi | Model/capability/placement var; secret storage ve atomic kapasite koruması eksik. F09/F16 |
| P10 — cPanel hosting | Engelli | Mock workflow var; gerçek transport hata veriyor ve uçtan uca ödeme/risk/queue bağı kanıtlanmıyor. F04/F30 |
| P11 — Renewal/automation | Kısmi, engelli | Scheduler/conditions var; kalıcı approve/delay/pause yok, invoice replay iki kez renew ediyor. F14/F15/F22 |
| P12 — Domains/registrar | Kısmi | NameSilo adapter seçilmiş; expiry/contact doğruluğu, provider approval/sandbox ve MariaDB doğrulanmalı. F02/F27 |
| P13 — Support | Kısmi | Ticket/department/SLA/attachments çekirdeği var; ekran ve gerçek contextual command eksik. F01/F21 |
| P14 — Fraud/abuse/privacy | Kısmi, engelli | Risk ve case bileşenleri var; gerçek commerce chain'e bağ yok; export/erasure/tombstone hatalı. F18/F19/F20 |
| P15 — Analytics | Engelli | Semantics/dashboards modelleri var; gerçek ticaret şemasından yanlış/sıfır veri okuyor. F17 |
| P16 — Import/WHMCS | Kısmi | Geniş migration çekirdeği var; fail-open cutover ve ürün çapında hold/completeness kanıtı eksik. F32 |
| P17 — Install/update/backup/recovery | Engelli | Sınıflar var; sıfır kurulum, backup kaybı, boş DB dump, fake migrations ve yanlış health. F01/F03/F10/F11/F12/F13/F20/F26 |
| P18 — Stable kabul | Reddedilmeli | Gerçek release/sandbox/shared-host/browser/coverage/mutation/independent soak kanıtları yeterli değil. F28/F29/F30/F33 |

## Eksik, fazla ve “ne alaka?” ayrımı

**Eksiklerin merkezinde entegrasyon var:** çalışır ürün girişleri, ekranlar, gerçek MariaDB şeması/migrasyonları, backend yetki zinciri, gerçek cPanel/iyzico/SMTP/registrar sandbox doğrulaması, güvenilir uzak backup, kalıcı automation, doğru analytics/privacy read models, gerçek CI/review/gate.

**Fazla özellik geliştirilmesi belirgin ana sorun değil.** Plan zaten analytics, finance, migration, privacy, automation ve documents istiyor; bu klasörlerin büyüklüğünü scope creep saymak doğru olmaz. V1.1/V2 plan klasörleri korunmalı. Buna karşılık çok sayıda PASS/enterprise/certification metni, ürünü doğrulamadan release onayı üretmek için yazılmış ek audit araçları ve production namespace içinde test fixture/mock bileşenleri bakım yükünü artırıyor. Bunları yeni özellik olarak değil kalite/öncelik sorunu olarak değerlendiriyorum.

**“Ne alaka?” olan somut parçalar:** gerçek `services` yerine bazı modüllerin varsaydığı `hosting_services`; canonical invoice minor alanları yerine farklı invoice şemaları; destekten gerçekte dispatch edilmeyen reboot; sahte buyer kimlik/telefon varsayılanları; kullanıcı sağlayıcısı belli olmadan NameSilo'nun “rock-solid” gerekçeyle seçilmesi; SQLite testleriyle shared hosting/MariaDB certification; bir PASS dosyasının başka PASS testine dönüştürülmesi. Bunların her biri planın esas niyetini karşılamadan görünürde kapsamı dolduruyor.

## Yapay zekanın notlarına ne kadar güvenilebilir?

Dosya isimlerini ve uygulama niyetini anlamak için işe yarıyorlar; tamamlanma/üretim onayı için güvenilir değiller. Özellikle P17.5 SFTP/S3 ve P18 gate kayıtları kaynak davranışıyla çelişiyor. P12.4 notu testlerde dış ağ çağrısı olmadığını açıkça söylüyor; aynı notun “production adapter PASS” demesi gerçek sandbox kanıtı gereksinimini karşılamıyor. P18.10 “soak” testi süreli sistem işletmek yerine dosyalarda PASS/başlık/strict_types arıyor. Notlarda test toplamı doğru olabilirken o toplamdan çıkarılan Stable kararı yanlış olabilir.

Kasıt, aldatma veya tüm sayısal çıktıları uydurma hakkında çıkarım yapmıyorum. Burada gösterilen sorun, **kanıtın gerçekten ölçtüğü şeyle onaylanan şeyin farklı olmasıdır.** Manifest de repo içindeki güncellenmiş manifesti doğruluyor; orijinal planla karşılaştırmanın yerine geçmiyor. Bu incelemede orijinal ZIP ayrıca karşılaştırıldı ve kabul metinlerinin korunması olumlu bulgu olarak kaydedildi.

## İyileştirme sırası ve yeniden kabul şartları

1. **Stable beyanını geri çekip sürümü geliştirme/RC olarak ele alın.** Orijinal master planı değiştirmeden gerçek blocker ve debt listesi oluşturun. Mevcut PASS dosyalarını tarihsel beyan olarak koruyun, yeni inceleme kararını ayrı tutun.
2. **Önce tek çalışan dikey akış kurun:** fresh install→admin login+2FA+RBAC→client/org→catalog→authoritative order→invoice→pending manual proof→admin approval→service→support. Gerçek MariaDB ve gerçek HTTP/ekranlardan çalışsın.
3. **Parasal ve credential güvenliğini kapatın:** F05/F06/F07/F08/F09/F16. Settlement yalnız doğrulanmış finans komutları; bütün actor/org sınırları backend'de; her secret tek güvenli storage hattı.
4. **Recovery'yi gerçekten kanıtlayın:** F03/F10/F11/F12/F13/F20. Local/SFTP/S3 oluştur→uzakta verify→yerel kayıp→fresh host restore→health/privacy temizliği. Update migration hatasında Recovery, backup'sız update yok.
5. **Gerçek hosting/domain satışını bağlayın:** cPanel transport hatası, iyzico auth, callback→queue→provisioning, provider uncertainty, registrar expiry/contact; gerçek sandbox ve bağımsız reviewer.
6. **Automation, analytics, privacy ve migration'ı canonical şemayla bütünleştirin.** Kalıcı approve/delay/pause; invoice replay tek renewal; gerçek ticaret ledger'ıyla analytics; tam export ve erişimi kapatan erasure; rapor yoksa cutover yok.
7. **UI ve test kapısını tamamlayın:** tüm günlük ekranlar, deploy assets, TR/EN, mobile/keyboard, Unicode çok sayfalı PDF. CI gerçek DB/full tests/static/security/coverage/mutation/browser/release rehearsal çalıştırsın; ardından belgeli süreli RC soak ve ayrı Stable onayı.

Kodu topluca atıp yeniden başlatmak için yeterli gerekçe yok; korunacak domain modelleri ve testler var. Fakat mevcut parçaların üzerine yeni özellik eklemek bu sorunları kapatmaz. Önce schema, entrypoints, güvenlik ve recovery omurgası düzeltilmeli. Test sayısı veya dosya sayısından “planın yüzde X'i tamamlandı” hesabı üretmek sağlıklı olmaz; faz/alt faz kabul matrisindeki somut durumlar kullanılmalı.

## İnceleme çıktıları

- `DOSYA_DOSYA.md` ve `DOSYA_DOSYA.csv`: 1.152 takip edilen dosyanın ayrı kaydı; rol, satır/hash, doğrudan test referansları, risk sinyalleri, ilgili F bulguları ve inceleme seviyesi.
- `ALT_FAZ_MATRISI.md` ve `ALT_FAZ_MATRISI.csv`: özgün 155 V1 alt fazının ayrı satırları.
- `original-plan-diff.txt`: normalize edilmiş özgün plan farkları.
- `behavior-probe-results.jsonl`: 23 sentetik tekrar üretimin ham sonuçları. Probe'lar mevcut test suite'e eklenmedi; kaynak düzeltmesi yapılmadı.
- `phpunit-output.txt`, `review-test-results.xml` ve `verification-results.json`: test ve kontrol çıktıları. Kontrol araçlarının exit 0 vermesi onların ölçmediği uygulama sorunlarını geçersiz kılmaz.
- `structural-results.json`: schema/SQL/import/evidence/paket bulguları; `file-inventory.csv` ham envanter.

**Bu raporun önerdiği release kararı: V1 Stable kabulü RED.** Yeniden değerlendirme, yukarıdaki blocker'lar kapandıktan ve orijinal planın gerçek kabul kanıtları üretildikten sonra yapılmalı.
