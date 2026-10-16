# D06.2 — dört sabit kontrol

16.10.2026. Orijinal P09/P10, D06-PLAN.md; actor/risk/paid-order/lifecycle ve güvenli remote-effect sözleşmesi. D06.1 kapalı kalır. Bu liste yeni kontroller eklenerek büyütülmez; bulunan ilgili kusur ait kontrol içinde çözülür.

| Kontrol | Sabit kabul ölçütü | Durum |
|---|---|---|
| Kalıcı dispatch ve kapasite | Tek ortak bağlantıda claim/reservation/placement/audit; caller transaction reddi; remote öncesi kalıcı processing; belirsiz sonuçta kapasiteyi ve claim'i tutma, ikinci create reddi | IMPLEMENTED_LOCAL_VERIFIED_CHECKPOINT |
| Doğrulanmış atomik aktivasyon | Tam remote kimlik/aktiflik ve güncel service/server binding; activate/placement/op/audit atomik; generated credential yalnız encrypted payload; explicit matching reconciliation | IMPLEMENTED_LOCAL_VERIFIED_CHECKPOINT |
| Application yetki/ödeme/risk ve lifecycle | Güncel yetkili actor, sahibiyle eşleşen paid order/invoice/risk kararı; create/suspend/unsuspend/terminate komutlarının ortak kalıcı akışı | PENDING |
| Kesinti/eşzamanlılık/gerçek protokol kabulü | Ayrı süreçler, paralel claim, worker ölümünden explicit recovery; gerçek HTTPS sağlayıcı failure ve paid-order workflow kabulü; CI | PENDING |

Bu teslim ilk iki kontrol içindir. Domain workflow güvenilir host çağrısıdır; authenticated HTTP/worker composition veya tamamlanmış Application risk kapısı gibi sunulmaz. Yeni testteki reentrant ikinci çağrı gerçek paralel süreç testi değildir; son kontrol açıktır. Yeni snapshot kaydı olmayan legacy operation'ların önceki reconciliation sözleşmesi korunur; otomatik absence/replay izni verilmez. Bilinmeyen uzak sonuçta allocation ve processing/hold kaydı korunur; worker'ların durdurulduğu açık bakım/uzlaştırma gerekir. Canlı cPanel/harici hesap kabulü ve production_ready açık kalır.

Son durum: **2/4 kontrol yerel doğrulandı; D06.2 IN_PROGRESS.** Sonraki Application admission/lifecycle kontrolüdür. Kanıt D06-2-DISPATCH-SONUC.md.
