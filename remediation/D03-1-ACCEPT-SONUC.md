# D03.1 — günlük, hata, geçmiş ve eski dışa aktarım kabulü

15.10.2026; başlangıç referansı `552e8dad1e8160866cf2ff2ce07ceb670072a2f1`. Orijinal master Security/Data Constitution esas alındı. D02 kapalı; D03.1 açık; independent_acceptance=PENDING; production_ready=false. Bu paket önceki anahtar/Vault/privacy signing işini tekrar yapmaz. Önce açık bırakılmış consumer ve çıkış yollarını inceler. Güncel dosya matrisi D03-1-SECRET-INVENTORY.md'dir; eski default HMAC key satırları güncel açık iş olarak gösterilmez.

## Somut bulgular ve değişiklik

İlk beş regresyon mevcut kaynakta beş failure/error0 verdi: doğrudan sorgu günlüğü raw SQL literal ve bindings tutuyordu; notification history bilinen reset/provider secret değerini hem metadata hem error'a yazabiliyordu; olmayan bütün privacy tabloları atlanıp uygun sonucu dönüyordu; ProviderException context/message bilinen sırrı yayıyordu; reset URL dinamik path token'ı API geçmişinde saklanıyordu. Before XML/TXT bu sentetik bulguları içerir.

Connection artık yalnız tanısal SQL şekli ve maskeli binding değerlerini bellekte tutar. SqlDiagnostics string/numeric literal ve yorumları çıkarır. SQLite / MySQL NO_BACKSLASH_ESCAPES ile varsayılan MySQL'in farklı yorumlayacağı tırnak durumunda iki yorum karşılaştırılır; farklıysa sorgu tanısı tamamen atlanır. Development kontrolü bu son uç durumdaki sızıntıyı false→true hidden olarak doğruladı; ortak regresyona eklendi. PDO'nun yürüttüğü query/bindings değişmez, süre ve sorgu sayısı korunur. Ham sorgu değerlerini getQueryLog ile görmek artık mümkün değildir.

SecretRedactor::diagnostic bilinen hassas alan değerlerinin kardeş mesaj/meta alanlarındaki tekrarlarını da temizler. Object/JsonSerializable hook çalıştırmaz, derinlik sınırı korunur. Logger context, privacy export, NotificationLog, ProviderException, health meta ve provisioning classification bu sınırı kullanır. Provider classification category/retry kararı orijinal sonuçtan hesaplanır; operasyonel ProviderOperationResult verisi değiştirilmez.

NotificationCenterService yeni delivery history'yi kaydetmeden temizler. Legacy NotificationLog okuması maskeli görünüm üretir, veritabanındaki tarihsel satırı yeniden yazmaz. Gerçek email içeriği ve alıcıya gönderilecek reset URL korunur; bu materyali maskelemek kullanıcının sıfırlama işlemini bozardı. Memory ve PDO yollarında aynı diagnostic DTO kullanılır.

QueueWorker ham Throwable/message taşımadan job ID/attempt ve exception sınıfını loglar. Failed queue exception alanında yalnız sınıf tutulur; trace, dosya yolu ve mesaj kopyalanmaz. Provisioning throwable classification da trace eklemez. Sağlık kontrolündeki beklenmeyen exception'lar genel mesaj ve sınıfla raporlanır. Webhook ölçümü başarısızsa healthy yerine unhealthy olur; gerçekten boş ama okunabilen kuyruk sağlıklı kalır. Sağlık anahtarı önceki dört karakter prefix'i yerine tamamen gizlidir; ilgili eski test beklentisi bu nedenle güncellendi.

API hata cevapları 5xx'te genel mesaj döndürür; 4xx hatalarda etiketli/bilinen bağlam temizlenir. Error code sabit biçimle sınırlıdır. Başarılı yetkili auth/token cevapları değişmez. API route tanısında reset/verification/token işaretinden sonraki segment, iki kat URL encoding dahil maskelenir; query/userinfo/fragment eskisi gibi çıkarılır. Her özel uygulama route'u otomatik tanınmış sayılmaz.

Privacy scan beş legacy tabloyla sınırlı olduğunu açıkça bildirir. Gerekli kolonları da SELECT eder; eksik tablo/kolon veya query hatası unverified_tables ve is_complete=false/is_compliant=false sonucudur. Geçerli boş envanter, kalıntılı kayıt ve eksik kolon ayrı test edilir. Kalan hassas değerin kendisi rapora konmaz. Bu beş tablo sonucu bütün ürünün kişisel veri silme uygunluk sertifikası değildir.

Privacy export yeni/custom/default collector verisindeki hassas alanları ve bilinen kardeş tekrarları temizler; checksum temiz veri üzerinden hesaplanır. Unsafe legacy paket getExport tarafından reddedilir ve eski satır/checksum değişmez; yeni güvenli paketin okunması da testlidir. Eski fiziksel dosya/DB logları bu değişiklikle otomatik silinmiş veya yeniden şifrelenmiş sayılmaz. Tarihsel forensics/retention erişimi ayrı politika ve D05/D08 kabulündedir.

## Kanıt ve test kapsamı

Final ana paket **1733 test / 11884 assertion**. Etkilenen MariaDB 10.11 paketi **54 test / 385 assertion**, failure/error/skip0. Altı exact native sınıf: SecretAcceptance, FinancialConcurrency, SqlPortabilityCloseout, PrivacyBinding, ProvisioningSecret, SecretEgress. Bütün native suite bu kaynakta yeniden koşulmadı; D02 historical full native kanıtı kendi commitinde korunur.

16 ortak yeni kabul testi SQLite ve gerçek MariaDB'de çalışır. Bunlar ilk beş failure'ın son kaynakta geçmesini, bağlam tekrarlarını, legacy history byte korumasını, temiz/kirli/eksik kolonlu taramayı, auth response ve alıcı reset linkinin exact korunmasını, retry kararını, dead-letter exception sınıfını, SQL kaçış/yorum/literal uç durumlarını, eski export reddi/yeni export checksum'ını, health error'ın false success olmamasını ve object hook çalıştırmamasını kapsar. Ana suite'teki ilk development failure yalnız sağlık anahtar prefix'i beklentisiydi; test tam maskeleme sözleşmesine güncellendi. Ara koşular final kanıt yerine sayılmaz.

26 source/test dosyası final koşudan önce normalize edilip SHA256 ile sabitlendi. 26 canonical baseline migration byte olarak değişmedi. Exact native discovery ile çalıştırılan testcase ID kümeleri birebir karşılaştırılır. Security/architecture/dependency taraması PASS (1066 PHP dosyası, altı signature pattern, üç forbidden-function rule); bu sınırlı statik tarama bağımsız güvenlik kabulü değildir. verify-d03-secret-acceptance.py bu kaynak, kanıt ve state sınırlarını doğrular.

## D03.1'de kalan iki somut saklama işi

**Q1 — jobs.payload ve failed_jobs.payload:** DatabaseQueue halen serialize(job) metnini açık saklar. Gerekli job girdisini maskelemek çözüm değildir. Owner/queue/column bağlamına bağlı şifreli/Vault storage, explicit confirmed legacy maintenance, exact execution round-trip, reservation/DLQ atomicity, caller rollback, current writer fencing ve key rotation/restore inventory gerekir.

**Q2 — migration_staging_records raw_payload_json ve canonical_payload_json:** DatabaseStagingRepository pristine/canonical JSON'u açık saklar. Owner/source bağlamına bağlı şifreli storage, canonical güncellenirken raw kaynağın korunması, explicit legacy maintenance, validation/quarantine diagnostics temizliği, transaction/savepoint ve lifecycle/recovery kabulü gerekir.

Bu ikisi comprehensive acceptance sırasında doğrulanan ek dosya yollarıdır; önceden tamamlanmış provisioning/automation/domain carrier uygulaması yeniden açılmamıştır. Q1/Q2 uygulamasından sonra envanter ve yeni owner kapsamı birlikte son kontrolden geçmeden D03.1 kapatılamaz. D03.2–D03.4 yetki/step-up, D05 full recovery/path/durability, D06 gerçek provider lifecycle, D08 HTTP composition ve D09 bağımsız kabul kendi kapsamlarında açık kalır. Deployment veya production verisi üzerinde bakım yapılmadı.
