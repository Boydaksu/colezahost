# D02 kapanış sınırı ve faz sahipliği

Referans: ilk `PLAN.md` D02 satırı ile `review/INCELEME.md` F02, F03 ve F16. Başlangıç commit: `577438873305ca987f58e954dace44c2227917de`. D02-CLOSEOUT yerel kapanış kanıtıdır; bütün V1 ürününün bağımsız kabulü değildir.

## Dört sabit çıkış ölçütü

| Ölçüt | Somut doğrulama |
|---|---|
| 1. Kurulum ve kalıcı şema | MariaDB 10.11.18 üzerinde 26 sürümlü migration; temiz kurulum → yönetici giriş → RBAC → müşteri faturası → ödeme → tekrar kurulum; kayıtların korunması. DatabaseFoundationTest, InstallationAcceptanceTest, LegacyIdentitySchemaTest, ModuleSchemaInstallTest ve D02CloseoutTest. |
| 2. İlk incelemedeki SQL uyumu | Sekiz finans/hizmet sıra üreticisi; alan adı fiyat ve kişi upsertleri; bildirim tercihleri; WHMCS üyelik aktarımı; kapasite azaltımı. DatabaseFoundationTest, SqlPortabilityCloseoutTest, CapacityAndNotificationTest, WhmcsContactPersistenceTest. |
| 3. İlk kritik eşzamanlılık ve geri alma | Gerçek dört süreç ile sıra, ödeme/tahsis, fazla ödeme, kredi, iade, webhook, queue lease ve kapasite; sonradan bulunan fatura başlık/kalem/sıra hatalarının birlikte geri alınması. FinancialConcurrencyTest, CapacityAndNotificationTest, InstallationAcceptanceTest ve D02CloseoutTest. |
| 4. Tam yerel regresyon | Ana paketin tamamı; native a/b ve yalnız yeni SQL sınıfını içeren c çalışmasının ayrık birleşimi tam MariaDB keşfiyle birebir eşleşmeli; hata, başarısızlık, atlanan test ve uyarı bulunmamalı. Kaynaklar koşu sonrası hash ile doğrulanır. |

Sonuç ve sayılar `D02-CLOSEOUT-SONUC.md`, `D02-CLOSEOUT-summary.json` ve `verify-d02-closeout.py` ile kaydedilir. Ölçütler karşılanmadan state kapanışa geçirilmez.

## Kapsamın sınırı

Kanonik 26 migration ve ayrıca üretim sınıflarının açık module setup yöntemleri doğrulanır. Bu, 93 DDL kaynak dosyasının her tarihsel/bozuk şema varyantının uyumlu olduğu anlamına gelmez. Alan adı katalog tabloları 26 baseline migration içinde değildir; SqlPortabilityCloseoutTest açık DomainCatalogService.ensureTables kurulumunu kullanır. Gerçek katalog/registrar başlangıç bağı D06, uygulama composition root ve modüllerin kullanılabilir kurulum deneyimi D08 kapsamındadır. Bu ayrım F03'ün bütün ürün genelinde kapandığı iddiası değildir.

Katılan Connection/caller işlemleri ve InnoDB kanıtlanır. Aynı veritabanına kuralları atlayan başka yazılımlar, transaction içinde ham PDO DDL veya uyumsuz trigger/index tasarımları genel bir atomiklik sertifikası kapsamında değildir. Bilinen riskler sessizce başarılı kabul edilmez; sonraki fazda kendi gerçek iş akışıyla doğrulanır.

## D02 dışında açık kalan işlerin sahipleri

| Faz | Açık iş |
|---|---|
| D03 | Organizasyon davet/üyelik/değişim, impersonation ve audit bütünlüğü; backend actor/RBAC ve çapraz müşteri sınırları; gerçek login/step-up/session issuance; zorunlu güvenli anahtar ve sırların şifrelenmesi. |
| D04 | Sunucu fiyatı ve tutar doğrulaması; sipariş/fatura iş kuralları; dönem başına tek yenileme; finans sorgu bütçesi. |
| D05 | Gerçek backup/restore/update/plugin iş akışları; üretim upgrade ve recovery matrisi; bakımda eski/uyumsuz kurulumların güvenli geçişi. |
| D06 | Gerçek sağlayıcı transport/sandbox, registrar/katalog başlangıcı, otomasyonun gerçek komut/cron/recovery bağları. |
| D07 | Raporların tarihsel ve müşteri kapsamı; gizlilik; gerçek destek komutları, attachment recovery ve cutover. |
| D08 | Front controller/composition root, HTTP/CLI girişleri, gerçek ekranlar, TR/EN, erişilebilirlik ve Unicode PDF. |
| D09 | MariaDB/tarayıcı CI, statik analiz/coverage, paket kurulumu/soak ve bağımsız sürüm kabulü. |

Önceki paket notları tarihsel kapsam ve kalan işleri korur. Bunlardaki eski “D02 IN_PROGRESS” ifadeleri önceki commitlerin durumudur; güncel faz `state.json` ile bu kapanış kaydından okunur. Üretim hazır ve bağımsız kabul durumu ayrıca açık kalır.
