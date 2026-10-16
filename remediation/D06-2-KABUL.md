# D06.2 — dört sabit kontrol

16.10.2026. Orijinal P09/P10, D06-PLAN.md; actor/risk/paid-order/lifecycle ve güvenli remote-effect sözleşmesi. D06.1 kapalı kalır. Bu liste yeni kontroller eklenerek büyütülmez; bulunan ilgili kusur ait kontrol içinde çözülür.

| Kontrol | Sabit kabul ölçütü | Durum |
|---|---|---|
| Kalıcı dispatch ve kapasite | Tek ortak bağlantıda claim/reservation/placement/audit; caller transaction reddi; remote öncesi kalıcı processing; belirsiz sonuçta kapasiteyi ve claim'i tutma, ikinci create reddi | IMPLEMENTED_LOCAL_VERIFIED_CHECKPOINT |
| Doğrulanmış atomik aktivasyon | Tam remote kimlik/aktiflik ve güncel service/server binding; activate/placement/op/audit atomik; generated credential yalnız encrypted payload; explicit matching reconciliation | IMPLEMENTED_LOCAL_VERIFIED_CHECKPOINT |
| Application yetki/ödeme/risk ve lifecycle | Güncel yetkili actor, sahibiyle eşleşen paid order/invoice/risk kararı; create/suspend/unsuspend/terminate komutlarının ortak kalıcı akışı | IMPLEMENTED_LOCAL_VERIFIED_CHECKPOINT |
| Kesinti/eşzamanlılık/gerçek protokol kabulü | Ayrı süreçler, paralel claim, worker ölümünden explicit recovery; gerçek HTTPS sağlayıcı failure ve paid-order workflow kabulü; CI | PENDING |

Bu teslim üçüncü sabit kontrolü tamamlar. Application authenticated host actor kimliğiyle güncel sistem izni/owner/paid invoice/risk ve ürün hedefini denetler; dört lifecycle komutu kalıcı claim/hold/atomik completion kullanır. Domain'in önceki işaretsiz internal API'leri trusted host sözleşmesi olarak korunur. HTTP/CLI/cron/worker composition D08'de; harici hesap ve bağımsız host kabulü D09'da açık kalır. Recovery sırasında ticari/hedef receipt değişmişse sessiz rebind yapılmaz. Canlı worker arbitration/ayrı süreç/parallel/crash ve gerçek HTTPS bütün workflow kabulü dördüncü kontroldür. production_ready=false.

Son durum: **3/4 kontrol yerel doğrulandı; D06.2 IN_PROGRESS.** Yalnız dördüncü sabit kontrol kaldı. Kanıt D06-2-COMMANDS-SONUC.md; önceki iki kontrolün tarihsel kanıtı D06-2-DISPATCH-SONUC.md.
