# ColezaHost düzeltme planı

Referans: `review/INCELEME.md` bulguları F01–F33 ve orijinal master plan. Bu çalışma kapsamı yeniden tanımlamaz; hataları giderir ve eksik V1 davranışlarını tamamlar. Eski PASS kayıtları bağımsız kabul kanıtı sayılmaz. Kullanıcının 09.10.2026 düzeltme talebi bu uygulama ve test çalışmasını yetkilendirir.

| Faz | Kapsam ve bulgular | Çıkış ölçütü |
|---|---|---|
| D01 | Ödeme bildirimi ve iyzico onayı: F05, F06; ödeme erişim sınırı F23 (kısmi) | Müşteri bildirimi ödeme kapatmaz; kimlik, token, sağlayıcı, tutar, para birimi eşleşmesi; negatif regresyonlar ve mevcut testler |
| D02 | MariaDB, kurulum ve kalıcı veri sözleşmesi: F02, F03, F16; F06 token indeksi | Gerçek MariaDB 10.11 kurulum/migration ve paralel işlem testleri; atomik sıra, bakiye, kapasite, kuyruk ve webhook |
| D03 | Kimlik, yetki, sır yönetimi: F08, F09, F23 kalan | Zorunlu güvenli anahtar; şifreli sırlar; ayrıntılı RBAC, oturum, organizasyon ve çapraz müşteri testleri |
| D04 | Sipariş/fatura, yenileme ve finans performansı: F07, F15, F30 | Sunucu fiyatı, geçerli toplamlar, dönem başına tek yenileme; gerçek akış ve sorgu bütçesi |
| D05 | Yedek, geri yükleme, güncelleme ve eklenti: F10, F11, F12, F13, F20, F31 | Gerçek uzak aktarım; MariaDB geri yükleme; kesinti/geri dönüş/tombstone; migration uygulanması; zararlı paket reddi |
| D06 | Sağlayıcı ve otomasyon: F04, F14, F26, F27 | Gerçek transport, kalıcı onay/duraklatma/geçmiş; doğru Doctor; sağlayıcı sandbox ve hata senaryoları |
| D07 | Raporlama, gizlilik, destek ve geçiş: F17, F18, F19, F21, F32 | Gerçek şemadan doğru çıktı; silinen hesap erişemez; gerçek destek eylemleri; zorunlu cutover kontrolleri |
| D08 | Çalışan uygulama ve arayüz: F01, F22, F24, F25 | Front controller ve komutlar; Application kullanım sınırı; gerçek ekranlar; TR/EN, tarayıcı erişilebilirliği, tam Unicode PDF |
| D09 | Sürekli doğrulama ve sürüm kabulü: F28, F29, F33 | MariaDB+tarayıcı CI; statik analiz/coverage; gerçek paket kurulumu, soak ve bağımsız inceleme kanıtı |

Her faz: hedef dosyaları belirle → açığı gösteren test → düzeltme → ilgili regresyonlar → tüm mevcut testler → ham kanıt ve kalan işler. Test beklentisi sırf başarısızlığı gizlemek için değiştirilmez. Gerçek sağlayıcı ve üretim doğrulaması yerel taklitle kapanmış sayılmaz. Gerekli dış erişim/hesap bilgileri ilgili fazda kaydedilir.

Aktif faz D02. D01 uygulaması tamamlandı; bağımsız kabul açık. D02 üç adımda yürütülür: D02.1 kanonik kurulum/RBAC şeması, migration ve atomik sıra numaraları; D02.2 ödeme, bakiye, token ve kuyruk eşzamanlılığı; D02.3 kapasite, kalan SQL ve geçiş kabulü. Her adım kendi kanıtını üretir; D02 tüm çıkış ölçütleri karşılanmadan tamamlandı sayılmaz. Nihai kabul, tüm bulgular ve orijinal alt faz ölçütleri kapandıktan sonra bağımsız incelemeyle yapılır.

## CR-D01 / TCR-D01

Sorun: istemci ödeme metodu seçimiyle fatura kapatabiliyor; callback tutar/para birimi ve kesin token bağı göz ardı ediliyor. Etki alanı: PaymentApplicationService, PaymentService, PaymentWebhookHandler ve ödeme testleri. Şema ve orijinal kapsam değişikliği yok. Test değişikliği: negatif ödeme senaryoları ekle; eski callback testlerine gerçek checkout token bağını ekle; PHPUnit yapılandırma eskimelerini kaldır. Eski inceleme kanıtlarını tarihsel olarak koru.

## CR-D02.1 / TCR-D02.1

Kurulum ve RBAC aynı şema üzerinden çalışmalı; global roller için NULL içeren bir primary key kullanılmamalı. Mevcut roller ve izinler migration ile korunmalı. SQLite'e özel sıra upsertleri, sürücüye uygun atomik numara üretimiyle değiştirilir. Hedef dosyalar: Connection, Migrator, RBAC şeması/servisi, installer database/admin servisleri, sekiz sıra üretici servis ve bunların migration/entegrasyon testleri. Test ortamı MariaDB 10.11.18; izole localhost sunucusu ve yalnız test verisi. MariaDB DDL transaction geri dönüşü desteklemediğinden migration başarısı gerçek uygulama sonrasında kaydedilir; hata başarı olarak işaretlenmez. Bu adımın tamamlanması F16'nın tamamını kapatmaz.

## CR-D02.2 / TCR-D02.2

Hedefler: PaymentService, InvoiceService, CreditService, PaymentWebhookHandler, Connection, DatabaseQueue/QueueJob; token/webhook/lease ve finans kilidi migration'ları; SQLite hata enjeksiyonu ve gerçek MariaDB paralel işlem testleri. Ödeme/tahsis/fatura/credit/refund bir transaction içinde tutulur; dış sağlayıcı isteği için kalıcı iade rezervasyonu ayrı commit edilir. Token SHA-256 özeti UNIQUE indeksle eşleştirilir. Önceki mükerrer token regresyonu, ikinci token atamasının reddedilmesi ve ilk kaydın bozulmaması ölçütüne yükseltilir. Eski mükerrer/çelişkili kayıtlar migration tarafından sessizce seçilmez veya silinmez; mutabakat gerektiren hata verir. Queue lease sahibi değiştikten sonra eski çalışan delete/retry/DLQ yapamaz.
