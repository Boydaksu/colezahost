# D04.2 — yenileme faturası ve ödeme tekrarları

15.10.2026; başlangıç f01d9e1e97cc7eafecf3d36b36a0a0d461084a1d. Birincil kaynak orijinal master plan P11.4/P11.5/P11.7, ticaret P06 ve bulgu F15. Yerel uygulama tamamlandı. D04'te yalnız D04.3 gerçek ticaret akışı ve sorgu bütçesi kaldı. D02/D03 kapanışları korunur; bağımsız ürün ve production kabulü açık.

## Kanıtlanan açıklar

Başlangıç kaynağında beş regresyonun beşi başarısızdı: paid invoice tekrarında ikinci uzatma; başka müşteriye ait faturayla uzatma; yenileme olmayan sıradan bir faturayla uzatma; dışarıdan verilen yenileme bedeli; renewal_records yazma hatası sonrası sahipsiz fatura. İlk düzeltme sonrasında ayrıca yönetici tarih değişikliğiyle eski ödemenin yeniden tüketilmesi ve tüketim kaydı hatası iki başarısız regresyonla gösterildi. Her iki before XML/stdout çifti korunur.

## Uygulama

RenewalInvoiceService kalıcı hizmeti güncel kilitle okur. Fiyat hizmetin saklanan recurring_amount_minor değeridir; gönderilen tutar kullanılmaz. Sahip/kurum/para birimi/dönem uyuşmazlığı reddedilir. Aktif veya askıya alınmış, tekrar eden ve doğru dönemli hizmet gerekir; tek seferlik/iptal/sonlandırılmış/bekleyen hizmetten otomatik yenileme üretilmez. Auto-renew kapalıysa olağan otomatik yol reddedilir; mevcut sunucu RenewalPolicy(autoRenewOnly=false) açık istisnası korunur. Scheduler doğrudan değerlendirmede de lead window ve tekrar eden dönem kuralını uygular.

Fatura, kalemler, sıra sayacı ve renewal_records aynı transaction/savepoint içinde yazılır. Aynı hizmet/dönem çağrıları kalıcı hizmet satırı altında seri hale gelir; var olan fatura geri döner. Eksik/çakışan eski kayıtlar sessizce onarılmaz veya yeni faturayla değiştirilmez; uzlaştırma hatası verir. Vergi dahil fiyat net/vergi/toplam olarak ayrılır; kurum muafiyeti kişisel kullanıcı ID'sinden tahmin edilmez. Vergi konumu hizmetin sunucu tarafından saklanan billing_country_code metadata'sı, yoksa mevcut TR politikasıdır.

Paid lifecycle artık fatura kalemindeki herhangi bir service_id'yi uzatma yetkisi saymaz. Gerçek renewal_records → fatura → hizmet owner/organization/currency/cycle/period bağını ve kalem dönem snapshot'ını doğrular. Kısmi veya unpaid fatura uzatmaz. Dönem ilerleme tarihi kayıtlı period_end'dir; mevcut tarihe körlemesine bir ay eklenmez. İptal/sonlandırılmış/uyuşmayan dönemler açıkça reddedilir.

İşlenen invoice/service/period bilgisi invoice_items.metadata_json içindeki renewal_settlement kaydıyla kalıcıdır. Bu kayıt, hizmet tarihi/status/version değişikliğiyle aynı transaction içindedir. Tekrar çağrı boş eylem döner; yönetici vade tarihini geriye alsa da eski ödeme tekrar tüketilmez. Daha ileri tarihteki eski kayıt yalnız already_advanced receipt'iyle tekrar işlemden korunur; tarihi geri çekmez. Para/vergi/fiyat snapshot'ları değişmez. Receipt hatası hizmet güncellemesini de geri alır; tekrar deneme güvenlidir. Yeni sütun veya migration eklenmedi.

Askıdan çıkarma politika iznine uyar; kapalıysa dönem ilerler, askı nedeni/status korunur. Yerel işlem başarısızlığı yutulmaz. Bildirim yalnız bu komutun sahip olduğu commit sonrasında gönderilir; caller transaction hâlâ açıksa mail gönderilmez. Kalıcı bildirim kuyruğu/uzak sağlayıcı unsuspend orkestrasyonu bu adımdan kabul almış sayılmaz (D06).

InvoiceService transaction içindeki kalem hydration'ını da current locking read yapar. Böylece eski repeatable-read snapshot yeni fatura kalemlerini veya tüketim kaydını gizlemez. Renewal storage ve bağlı invoice tables için InnoDB/kurulu şema kontrolü yapılır; komut içinde DDL yoktur. Yerel komut tüm transaction'a sahipse deadlock en fazla iki ek denemeyle yeniden yürütülür; dış caller'a ait transaction hataları üst çağrıya bırakılır.

ServiceRenewActionHandler ödeme/fatura bağlamındaki otomatik yenilemeyi aynı hizmete bağlı paid-period komutuna yönlendirir. Eksik invoice ID ve başka hizmetin faturası reddedilir; replay tekrar uzatmaz. Ödeme bağlamı olmayan açık manuel automation extension komutu korunur; tüm automation actor/provider binding kabulü D06/D08 kapsamındadır.

## Doğrulama

- Tam ana paket: **1865 test / 12456 assertion**.
- Gerçek MariaDB 10.11, tam discovery eşleşmeli dokuz ilgili sınıf: **132 test / 1216 assertion**.
- Failure/error/skip sıfır. Ana paket architecture/security kontrollerini içerir. Tüm native paket bu tur tekrar çalıştırılmadı.
- Ortak 18 regresyon SQLite ve MariaDB'de; native ilave altı vaka: dört süreçte tek fatura, dört süreçte tek uzatma, eşzamanlı generation replay+paid settlement, eski fiyat snapshot'ı, eski boş snapshot sonrası peer'de oluşturulup ödenmiş yeni fatura ve MyISAM reddi.
- Raw/managed caller rollback hem hizmeti hem receipt metadata'sını geri alır. Renewal record/receipt/service update hatalarında yerel kısmi kayıt kalmaz. Kısmi ödeme, eski dönem replay, ownership/currency/state değişikliği, manuel tarih değişikliği ve auto-reactivation politikası sınanır.
- Ay sonu ve artık yıl: 31 Ocak→28/29 Şubat, 29 Şubat yıllık→28 Şubat; bozuk tarih/cycle reddedilir.
- 26 kanonik migration byte olarak değişmedi. Kaynak/test byte'ları son testten önce SHA-256 ile sabitlendi.
- Eski renewal fixture'ları artık gerçek services satırları kurar. Eski olumlu reactivation testi sıradan manuel faturayı değil kalıcı yenileme kaydını kullanır; sıradan faturanın reddi ayrı regresyonla korunur. Negatif ve concurrency beklentileri zayıflatılmadı.

## Kalan iş

D04.3: katalog fiyatı → sipariş → fatura → gerçek ödeme allocation → hizmet → yenileme akışının kalıcı kabulü ve liste/batch sorgu bütçeleri. Tüm HTTP/CLI/event wiring ve ekran kabulü D08; dış sağlayıcı etkileri D06; bağımsız kabul D09. Üretime deployment yapılmadı.

Ham kanıt: D04-2-before, D04-2-receipt-before, D04-2-main, D04-2-mariadb XML/stdout; exact test listesi D04-2-discovery.xml; SHA-256 D04-2-baseline.json ve D04-2-hashes.json. Kapanış doğrulaması `python -X utf8 remediation/verify-d04-renewal.py`, yalnız bu adımın kendi kaynak revizyonunda geçerlidir.
