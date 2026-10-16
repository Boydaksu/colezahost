# D06 — sağlayıcı ve otomasyon sabit planı

16.10.2026. Birincil kaynak orijinal P09/P10/P11/P12/P08; F04/F14/F26/F27 ve önceki fazlarda açık bırakılan provider sınırları. D02–D05 kapanışları korunur. Beş yerel adım vardır; aynı adımı küçük düzeltmelerle tekrar açmak yerine bu çıkış ölçütleriyle kapanır.

| Adım | Sabit kapsam | Çıkış ölçütü |
|---|---|---|
| D06.1 | cPanel gerçek transport ve yanıt/health kabulü | Gerçek HTTPS/CA/host doğrulaması; süre hatası yok; kimlik sırları redirect/plaintext'e gitmez; bounded response/timeouts; belirsiz/HTTP hatalı/bozuk WHM yanıtı başarı veya healthy olmaz; yerel protokol CI |
| D06.2 | Sağlayıcı yaşam döngüsü ve provisioning | Application actor/owner/risk/paid-order bağları; validate/place/reserve/remote/verify/activate; durable retry/idempotency/uncertain reconciliation; dış etki sonrası hata ve duplicate account senaryoları |
| D06.3 | Otomasyon komut bağları ve recovery | Kalıcı approval/delay/version/history önceki kanıtları korunur; güncel bağlam ve pause yeniden kontrolü; gerçek provider command adapters; crash/dispatch recovery ve güvenli tekrar |
| D06.4 | Diğer V1 entegrasyon sözleşmeleri | SMTP gerçek teslim yolu, iyzico güncel auth/response, registrar contact/expiry ve explicit sağlayıcı seçimi; contract suites; erişim gerektiren dış kabul ayrı açık listesi |
| D06.5 | Gerçek Doctor ve faz kabul matrisi | Salt okunur gerçek queue/cron/provider probe ve toplam probe gecikme bütçesi; bilinmeyen durum healthy olmaz; yerel failure/health ve uçtan uca sağlayıcı matrisi; dış sandbox kanıtının yokluğu açık gösterilir |

Her adım önce regresyon → düzeltme → etkilenen testler → tam ana test → gerekiyorsa gerçek MariaDB → byte bağlı kanıt → GitHub kontrolleri sırasıyla teslim edilir. Canonical26 migration ve locked97 korunur. Mevcut memory testleri gerçek protokol/harici hesap kanıtı sayılmaz. Sağlayıcı hesabı/parola aranmaz veya üretime mutasyon yapılmaz. D06 yerel uygulaması ile harici sağlayıcı sandbox kabulü ayrı kaydedilir; gerçek hesap kanıtı olmadan F27 veya production-ready kapanmaz. Bağımsız ürün/host kabulü D09'dadır; HTTP/cron/worker composition D08'dedir.

D06.1 kapsamı dört cPanel sınıfı ve protokol regresyonlarıdır. Paid-order/queue/lifecycle orchestrator davranışı D06.2'ye aittir; tek transport düzeltmesi bütün hosting akışını tamamlandı yapmaz.

D06.1 yerel olarak tamamlandı; kanıt D06-1-SONUC.md. Güncel kalan liste sabit dört adım: D06.2, D06.3, D06.4, D06.5. Sonraki D06.2; dış sağlayıcı kabulü PENDING.
