# D04.3 — kalıcı manuel ticaret akışı ve sorgu bütçeleri

15.10.2026. Başlangıç 3cf5165e0e1b02cd75a5a011a98e48d27add0ba1. Birincil kaynak orijinal master plan P05, P06.8, P11.4/P11.7 ve F30. Yerel uygulama tamamlandı; D04'ün üç adımı da kapandı. Bağımsız ürün/production kabulü açık. Sonraki D05.

## Kanıtlanan kusurlar

Başlangıçta altı test/altı failure/0 error: 12 sipariş için 13 sorgu, 12 ödeme için 25 sorgu; ödenmemiş siparişten hizmet açılması; tekrar işlemde ikinci hizmet; üç adetlik siparişin tek hizmete düşmesi; invoice payment listesinde allocated ödeme varken doğrudan pending ödemenin kaybolması. Ayrı regresyonda zaten faturalanmış 100 hizmetin taraması 103 sorguyla 6 sorguluk bütçeyi aşar. Her iki before XML/stdout korunur.

## Gerçek manuel Application akışı

Yeni ManualCommerceApplicationService mevcut müşteri Application komutlarını tamamlar: kayıtlı katalog/aktif ürün ve fiyat → müşteri siparişi → erişim kontrollü donmuş fatura → müşterinin pending banka ödeme bildirimi → güncel payments.approve iznine sahip personelin onayı → gerçek allocation ve invoice/order state → services.manage yetkisiyle adet başına hizmet → yerel manuel aktivasyon → kalıcı scheduler yenileme faturası → yeni pending ödeme/personel onayı → tek dönem tüketim kaydı. Ödemeyi completed olarak doğrudan enjekte eden bir golden başarı varsayımı bu yeni akışta yoktur.

19 ortak regresyon SQLite ve MariaDB'de çalışır; ayrıca bir native vaka tüm 26 kanonik migrationı uyguladıktan sonra aynı Application yolunu yürütür. Katalog fiyatı sonradan değişse de eski invoice ve hizmetin recurring bedeli korunur. İlk setup bedeli yenilemeye eklenmez; vergi/net/toplam ve adetler uzlaşır. İki hizmetli siparişte birinin yenilemesini ödemek diğer hizmetin dönemini tüketmez.

Onay/fulfillment/aktivasyon gerçek sistem izinleri ister; müşteri kendi ödemesini onaylayamaz, hizmet açamaz veya etkinleştiremez. Kurumsal manual payment organization_id bağını faturadan alır. Liste görünürlüğü her yeni komutta current actor/membership ile doğrulanır; aynı komut içinde aynı owner/org kararı tekrar kullanılır. Kişisel own-row hızlı yolu yalnız CommandAuthorizer.run etkin aktörü doğruladıktan sonra işler; organization üyeliğini atlamaz. Caller is_admin flag'i yetki vermez.

Manual application entry CoreServiceProvider'a bağlandı; HTTP/UI bağlamı ve route wiring D08'de kalır. Remote provider placement veya kimlik/credential/server/IP kanıtı bulunan hizmet yerel manuel aktivasyon/yenileme onayı yoluna sokulmaz; provider workflow gerekir. Bu çalışmanın sağlayıcı bağlantısı veya cPanel sandbox kabulü olduğu iddia edilmez.

## Hizmet üretimi ve bütünlük

createServicesFromOrder dışarıdan verilmiş Order nesnesinin alanlarını kullanmak yerine güncel kalıcı sipariş/kalemleri kilitle yeniden okur. Active/completed sipariş ve owner/org/currency/tutarı eşleşen tek fully paid invoice gerekir. Sırf order status'u active yapmak ödeme kanıtı değildir. Dönüştürme kalıcı order kilidiyle seri hale gelir; her order item quantity biriminden bir hizmet çıkar. Birim kimliği mevcut metadata içinde commerce_order_unit olarak saklanır. Tekrar çağrı aynı hizmet ID'lerini döndürür; yarım/çakışan legacy set sessizce çoğaltılmaz, uzlaştırma hatası verir.

Header/kalem/owner okumaları transaction içinde current locking read yapar. Tüm birimler ve service sequence tek transaction/savepoint'tir. İkinci birimin yazma hatası ilk birimi de geri alır. Raw/managed caller rollback hizmetleri geri alır. Yeni personel onayı financial state/allocation, renewal due date/receipt ve security audit'i aynı aggregate içinde tutar; audit hatası hepsini geri alır. Yeni schema veya migration yoktur.

Shared-host bellek sınırı için bir siparişin hizmet birimi üst sınırı 1000'dir. Aynı sınır müşteri checkout'unda ödeme/sipariş oluşmadan uygulanır; ürün satırlarına bölmek sınırı aşamaz. Eski/manuel aşırı büyük kayıtlar sessizce eksik teslim edilmez.

## Okuma optimizasyonu

Order ve invoice listeleri child kalemleri toplu yükler. Payment listeleri allocation ve refund toplamlarını toplu yükler. Invoice payment listesi hem doğrudan pending hem allocation üzerinden bağlı ödemeleri birlikte döndürür; tekrarları çoğaltmaz. Empty relation ve monetary snapshots korunur. Parent transaction varsa header ve child current read birlikte kullanılır.

Liste yükleme 500 ID'lik parçalara bölünür. 501 kayıt/Unicode açıklama/tam toplam testi iki parçanın birleşiminde kayıp olmadığını doğrular. ServiceService'in mevcut placement/cancellation toplu yüklemesi korunur. Scheduler 100 eski dönem için tek tek lookup yerine 200 dönemlik parçalarla existingPeriods okur; eksik kayıtlar yine D04.2'nin kilitli/atomik üretim komutuna gider. Prefetch bir para/yetki kararı değildir.

Doğrulanan bütçeler (üst sınırlar):

| Yol | Kayıt | En çok sorgu |
|---|---:|---:|
| Domain sipariş listesi | 100 | 2 |
| Domain fatura listesi | 100 | 2 |
| Domain ödeme listesi, allocations/refunds dahil | 100 | 3 |
| Kişisel Application sipariş/fatura/ödeme listesi, her biri | 100 | 30 |
| Scheduler, zaten faturalanmış dönemler | 100 | 6 |
| İki parçalı fatura listesi | 501 | 3 |
| İki parçalı ödeme listesi | 501 | 5 |

Bu ölçümler SQL sorgu bütçesidir; gerçek reference-host p95 veya tüm HTTP ekranlarının süre/pagination kabulü değildir. Yeni fatura/hizmet yazma işlerinin gerekli kalıcı mutasyonları kayıt sayısıyla artar; yazma batch'i sabit sorguluymuş gibi sunulmaz.

## Son doğrulama

- Tam ana paket: **1884 test / 12552 assertion**.
- Gerçek MariaDB 10.11, exact discovery eşleşmeli on ilgili sınıf: **157 test / 1451 assertion**.
- Failure/error/skip sıfır. Ana paket mevcut architecture/security kontrollerini içerir. Tam native paket bu tur tekrar çalıştırılmadı.
- Native ek altı vaka: kanonik kurulumdan Application journey; dört süreçte üç hizmetin tek kez üretimi; dört personel onayında tek allocation; eski unpaid snapshot sonrası peer ödeme ve caller rollback; invoice owner değişikliğinde ret; MyISAM hizmet tablosunda etki öncesi ret.
- 26 migration byte olarak aynı; source/test SHA-256 son çalışmadan önce sabitlendi. Stage/commit manifesti aynı byte'ları doğrular.
- Eski ServiceLifecycle olumlu testi artık gerçek fatura+pending ödeme+onayla paid order kurar. Eski iki provisioning/golden fixture'daki boş OrderService ödeme bağımlılığı gerçek OrderService ile tamamlandı; onların fake gateway/provider kapsamı gerçek sağlayıcı kabulüne çevrilmedi. Mevcut beklentiler zayıflatılmadı.

## Kapanış sınırı

D04.1 sunucu fiyatı, D04.2 yenileme/ödeme tekrarları ve D04.3 yerel manuel Application akışı/sorgu bütçeleri tamamlandı. F30'un provider/queue/risk zinciri ve sandbox kısmı D06; HTTP/browser/route/page wiring D08; gerçek host, shared-host/release ve bağımsız kabul D09'da açık. D05 yedek/restore/update/extension sonraki fazdır. Deployment yapılmadı.

Ham kanıt: D04-3-before/batch-before/main/mariadb XML/stdout; D04-3-discovery.xml; D04-3-baseline.json/hashes.json. Kontrol: `python -X utf8 remediation/verify-d04-flow.py`, yalnız kendi kaynak revizyonunda geçerlidir.
