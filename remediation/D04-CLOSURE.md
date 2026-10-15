# D04 — yerel uygulama kapanışı

15.10.2026. Durum: IMPLEMENTATION_COMPLETE_LOCAL_VERIFIED. Bağımsız kabul PENDING; production_ready=false.

| Adım | Sonuç | Kanıt |
|---|---|---|
| D04.1 | Sunucu katalog fiyatı, geçerli seçim, parasal/vergi/fatura snapshot bütünlüğü | D04-1-SONUC.md |
| D04.2 | Dönem başına tek fatura ve kalıcı paid-period tüketimi; retry/rollback/concurrency | D04-2-SONUC.md |
| D04.3 | Kanonik kurulumda gerçek manuel Application ticareti, adet bazında tek hizmet ve sabit/parçalı sorgu bütçeleri | D04-3-SONUC.md |

Son kaynakta 1884 ana test/12552 assertion ve ilgili on MariaDB sınıfında 157 test/1451 assertion geçti. Failure/error/skip=0. Exact discovery ve source/migration hash kontrolleri D04-3-summary.json, D04-3-baseline.json ve verify-d04-flow.py ile kayıtlıdır. Tam native suite tekrar çalıştırılmadı; bağımsız kabul yapılmadı.

D04'te kalan yerel uygulama adımı yok. D02/D03 kapanışları korunur; sonraki D05 yedek, geri yükleme, güncelleme ve eklenti fazıdır. Yeni finans kusuru kanıtlanmadıkça D04 aynı işlerle yeniden açılmaz.

F30'un gerçek sağlayıcı/risk/queue/sandbox bölümü D06, HTTP/browser/route/page composition D08, reference-host/shared-host/release/bağımsız kabul D09'da kalır. Katalog stok rezervasyonu ve sağlayıcı fulfillment bu manuel akışın kabulünden tamamlandı sayılmaz. SQL sorgu bütçeleri p95 host performansı iddiası değildir. Üretime deployment yapılmadı.
