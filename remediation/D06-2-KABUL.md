# D06.2 — dört sabit kontrol

16.10.2026. Orijinal P09/P10, D06-PLAN.md; actor/risk/paid-order/lifecycle ve güvenli remote-effect sözleşmesi. D06.1 kapalı kalır. Bu liste yeni kontroller eklenerek büyütülmez; bulunan ilgili kusur ait kontrol içinde çözülür.

| Kontrol | Sabit kabul ölçütü | Durum |
|---|---|---|
| Kalıcı dispatch ve kapasite | Tek ortak bağlantıda claim/reservation/placement/audit; caller transaction reddi; remote öncesi kalıcı processing; belirsiz sonuçta kapasiteyi ve claim'i tutma, ikinci create reddi | IMPLEMENTATION_COMPLETE_LOCAL_VERIFIED |
| Doğrulanmış atomik aktivasyon | Tam remote kimlik/aktiflik ve güncel service/server binding; activate/placement/op/audit atomik; generated credential yalnız encrypted payload; explicit matching reconciliation | IMPLEMENTATION_COMPLETE_LOCAL_VERIFIED |
| Application yetki/ödeme/risk ve lifecycle | Güncel yetkili actor, sahibiyle eşleşen paid order/invoice/risk kararı; create/suspend/unsuspend/terminate komutlarının ortak kalıcı akışı | IMPLEMENTATION_COMPLETE_LOCAL_VERIFIED |
| Kesinti/eşzamanlılık/gerçek protokol kabulü | Ayrı süreçler, paralel claim, worker ölümünden explicit recovery; gerçek HTTPS sağlayıcı failure ve paid-order workflow kabulü; CI | IMPLEMENTATION_COMPLETE_LOCAL_VERIFIED |

Dört sabit kontrol yerel olarak tamamlandı; **D06.2 IMPLEMENTATION_COMPLETE_LOCAL_VERIFIED, 4/4**. Kapanış D06-2-CLOSURE.md; son süreç/gerçek TLS kabulü D06-2-ACCEPTANCE-SONUC.md. Önceki iki teslim tarihsel kanıtlarıdır. Canlı worker/recovery mutex'i, dört ayrı süreçli claim/çakışan lifecycle, OS worker kill ve fresh-process read-only recovery gerçek loopback HTTPS ile SQLite ve MariaDB'de doğrulandı. Unknown remote outcome otomatik replay/release izni değildir. HTTP/CLI/cron/worker composition D08; harici hesap/bağımsız host kabulü D09; production_ready=false.

**Sonraki D06.3 otomasyon komut bağları/recovery.** Yeni kabul kontrolü eklenmedi.
