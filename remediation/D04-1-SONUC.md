# D04.1 — sunucu fiyatı ve parasal bütünlük

15.10.2026. Başlangıç commit: 07aa6a7dd8b5d5d0f41b5e116d79a0e22c176d67. Birincil kaynak orijinal master plan P05/P06/P11, inceleme F07/F15/F30. D04.1 yerel uygulama tamamlandı. D04 açık: kalan D04.2 ve D04.3; sonraki D04.2.

## Sorun ve düzeltme

Değişmemiş başlangıç kaynaklarında beş regresyonun beşi başarısızdı: müşterinin kendi fiyatını vermesi, bağımlılık yokken sıfır tutar, negatif fatura, tutarsız kalem toplamı ve sipariş vergisinin faturada kuruş kaybı. Başlangıç JUnit/stdout korunur.

Müşteri Application sipariş komutu yalnız ürün/dönem/adet/seçenek/eklenti/domain seçimini alır. Kayıtlı aktif ürün/grup, geçerli ve zorunlu seçenekler, uygulanabilir aktif eklenti ve para birimi/dönem fiyatı doğrulanır. Müşteri tutar, vergi, ürün adı ve finansal snapshot gönderemez; fiyat yoksa ücretsiz sipariş oluşmaz. Seçimlerdeki tek seferlik fiyatlar tekrar eden bedele eklenmez, kurulum bedeline alınır. Ürün adı ve fiyat/vergi ayrıntısı sunucudan dondurulur.

Kişisel kullanıcı ve kurum ID alanları ayrıdır: checkout customer override kapsamını kurum, yeni user override kapsamını kişisel kullanıcı için okur. Kurum muafiyeti aynı numaralı kişisel kullanıcıya taşınmaz. Mevcut düşük seviyeli Pricing API'nin customer davranışı korunur; checkout kişisel indirimleri açık user kapsamıyla tanımlanmalıdır. Kurum vergi muafiyeti mevcut customer_id kaydıyla uygulanır; kişisel muafiyet için ID tahmini yapılmaz.

Elle sipariş fiyatı ayrı createManualOrder komutuyla gerçek orders.price_override sistem izni ve gerekçe ister; gerekçe audit edilir. Normal müşteri siparişi ve yetkili personelin normal katalog siparişi aynı sunucu fiyatı kuralını kullanır. Serbest tutarlı fatura invoices.manage ister. Müşteri faturası erişim kontrolü altında kayıtlı siparişten üretilir; order_id ile fiyat/owner/para birimi override birleştirilmez.

Tam sayı adet ve para alanları negatif, kesirli, boolean, taşan ve tutarsız toplamları reddeder. Sınır mevcut signed INT para sütunlarıyla uyumlu 2147483647 minor birimdir. Vergi dahil/hariç ve karma oranlar net/vergi/toplam olarak ayrılır. En büyük kalan dağıtımı satırlardaki toplam kuruşu korur; kalem snapshot'ı ve fatura başlığı uzlaşır.

OrderService hesaplama/header/child yazımını tek transaction/savepoint içinde yürütür. Fatura sipariş nesnesinin dışarıdan verilmiş alanlarına güvenmez; kalıcı siparişi güncel kilitle tekrar okur ve kayıtlı tutarları faturaya taşır. Aynı siparişe paralel istekler order row kilidiyle tek fatura alır. Önceden birden fazla veya bağlamı bozuk fatura bulunan sipariş açık uzlaştırma hatası verir; eski finansal kayıtlar silinmez.

## Doğrulama

- Ana paket: **1847 test / 12389 assertion**.
- Gerçek MariaDB 10.11: **75 test / 630 assertion**, discovery ile bire bir eşleşen altı ilgili sınıf.
- Failure/error/skip sıfır. Yeni ortak 18 vaka SQLite ve MariaDB'de; MariaDB'de ayrıca dört süreçte tek sipariş faturası ve eski repeatable-read snapshot sonrası güncel fiyat/vergi/ürün doğrulanır.
- Parent transaction açık kalır, rollback sipariş ve auditi geri alır; child ve audit yazma hataları kısmi kayıt bırakmaz. Mevcut finans eşzamanlılık, yetki/audit, belge, kurulum ve SQL regresyonları korunur.
- 26 kanonik migration byte olarak değişmedi. Yeni migration yok. Kurulu müşteri akışında DDL yok; test kaydı bunu denetler.
- API olumlu testleri artık gerçek katalog fiyatlarını ve gerçek invoices.manage rolünü kullanır. Ödeme fixture faturası güvenilir domain setup'ıdır; müşteri serbest fatura oluşturması başarı beklentisi olmaktan çıkarıldı. Audit failure testi yetkili aktörle gerçek audit yazma hatasını sınamayı sürdürür.

## Sınırlar ve kalan işler

Bu adım tüm D04 veya production kabulü değildir. D04.2 hizmet/dönem başına tek yenileme ve ödeme replay; D04.3 kalıcı uçtan uca ticaret ve liste/batch sorgu bütçeleridir. Tam native paket bu tur tekrar çalıştırılmadı; yalnız belirtilen altı sınıf çalıştırıldı. D03 kapanışı korunur.

Stok ve satış tarihleri satın alma uygunluğu olarak kontrol edilir; bu değişiklik stok rezervasyonu/tüketimi veya geçmiş siparişler üzerinden toplam müşteri kotası uygulamaz. Ürün başına adetler mevcut istekte birlikte sayılır. Sağlayıcı fulfillment/lifecycle D06'dadır. Vergi konumu mevcut sunucu politikası TR'dir; müşteri body alanından seçilmez. Uluslararası adres/politika ekranı ve HTTP/UI bağlantısı D08, bağımsız ürün kabulü D09'dur. Deployment yapılmadı.

Ham kanıt D04-1-before/main/mariadb XML ve stdout, D04-1-discovery.xml, source/migration SHA-256 D04-1-baseline.json. Kapanış kontrolü `python -X utf8 remediation/verify-d04-pricing.py`, yalnız bu adımın kendi kaynak revizyonunda geçerlidir.
