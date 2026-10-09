# D01 — Ödeme güvenliği uygulama sonucu

Tarih: 09.10.2026. Durum: IMPLEMENTATION_COMPLETE. Bağımsız kabul: henüz yapılmadı.

## Değişen davranış

- Müşterinin manuel/havale bildirimi her zaman PENDING; fatura ve tahsis değişmez. Onay ayrı yetkili iş akışında yapılır. Bildirim üzerinden sağlayıcı ödeme metodu seçilemez; tutar fatura bakiyesini aşamaz.
- Ödeme Application girişlerinde checkout, manuel bildirim, okuma ve listeleme için pozitif oturum kimliği gerekir. Bildirim sahibi ve checkout alıcısı fatura sahibiyle eşleşir. Ayrıntılı RBAC ve diğer modüllerin kimlik sınırları D03 kapsamında açık.
- Checkout sağlayıcıya ve veri tabanına aynı ödeme numarasıyla gider. Başlatma hatası/istisnası kaydı FAILED bırakır; boş token başarı sayılmaz.
- iyzico callback yalnızca metadata içindeki tam checkout token alanını eşleştirir. Alt dize, SQL joker karakteri, ilgisiz metadata/işlem referansı veya mükerrer token ödeme seçemez. Sağlayıcı, doğrulanmış ödeme numarası, tutar ve para birimi eşleşmeden tahsis yapılamaz. Bekleyen kayıt dışındaki durumlar yeniden ödenmez; tamamlanmış bildirim tekrarları ikinci tahsis oluşturmaz.
- Genel webhook yolu istemcinin success/failed beyanını finansal kanıt saymaz. iyzico doğrulama yoluna yönlenir; doğrulama adaptörü bulunmayan sağlayıcılar açık hata döndürür. Diğer sağlayıcı adaptörleri D06 işidir.
- PHPUnit yerel sürümün şemasına bağlandı; eski executionOrder seçeneği kaldırıldı. Test kapsamı daraltılmadı.

## Kanıt

Yeni `PaymentSecurityRegressionTest` toplam 18 vaka içerir. İlk 11 vaka düzeltme öncesi 11 başarısızlık verdi (`D01-before.txt/xml`). Ek genel webhook vakası da düzeltme öncesi başarısızlığı gösterdi (`D01-additional-before.txt`). Başarı, tekrar bildirim, kesin token, eksik/yanlış doğrulanmış numara, eksik oturum, yanlış sahip, farklı sağlayıcı, az/fazla tutar, farklı döviz ve sağlayıcı istisnası sınandı.

Mevcut üç callback testine checkout token fixture'ı eklendi; fatura, durum, tahsis ve tekrar bildirim beklentileri korunuyor. Son tam paket: **918 test, 7.972 assertion; başarısızlık, hata ve PHPUnit eskime uyarısı yok** (`D01-suite.txt/xml`). Bu yerel testler SQLite ve kontrollü sağlayıcı yanıtları kullanıyor; gerçek iyzico/MariaDB kabulü değildir.

## Açık işler ve riskler

F05 uygulama bildirimi açığı giderildi. F06 tutar/para birimi ve kesin kimlik bağlantısı giderildi; token sütunu/indeksi ve atomik benzersizlik D02'de yapılacak. Geçici token araması metadata kayıtlarını tarar; yoğun veri için optimize edilmiş sayılmaz. Yarış koşulları, atomik ödeme/tahsis ve callback event benzersizliği F16 kapsamında hâlâ açıktır. Eski checkout kayıtları token içermiyorsa otomatik mutabakat yapılmaz; üretim verisi varsa güvenilir sağlayıcı kayıtlarıyla migration/reconciliation gerekir.

F23 yalnızca belirtilen ödeme Application girişlerinde kısmen ele alındı. Boolean admin yetkisi, refund yetki sözleşmesi, gerçek route/oturum/RBAC entegrasyonu D03/D08 kapsamındadır. D01 sistemin üretime hazır olduğu veya tüm 33 bulgunun kapandığı anlamına gelmez.

Sonraki aktif aday: D02; MariaDB 10.11 gerçek test ortamı, kanonik şema, token indeksi, transaction/lock ve eşzamanlılık senaryoları.
