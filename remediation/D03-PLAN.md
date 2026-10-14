# D03 — kimlik, yetki ve sır yönetimi

Kaynak: ilk remediation/PLAN.md D03 ve review/INCELEME.md F08, F09, F23. D02-CLOSEOUT yerel kapanışından sonra uygulanır. Bu dört paket iş gruplarıdır; her biri birden fazla düzeltme ve negatif senaryo içerir. Bağımsız ürün kabulü D09'da ayrıca yapılır.

| Sıra | Sabit kapsam | Çıkış ölçütü |
|---|---|---|
| D03.1 | Güvenli anahtar ve sırların kalıcı depolanması | Sabit/eksik anahtar yazımı reddedilir; provider/registrar ve kimlik sırları uygun şifreli storage hattını kullanır; eski verinin güvenli geçişi, restart ve log/export sızıntısı test edilir. |
| D03.2 | Organizasyon ve üyelik yetkisi | Davet tüketimi, üyelik/rol değişimi ve organizasyon seçimi güncel actor/üyelik ile doğrulanır; child hata ve raw/managed caller rollback bütünlüğü; paralel tüketim ve çapraz müşteri negatifleri. |
| D03.3 | Giriş ve güçlü kimlik kanıtı | Login/reset/verification mevcut kalıcı primitive'leri kullanır; admin 2FA politikası, güvenilir cihaz ve step-up gerçek actor/challenge bağını korur; session issuance/revocation ve cookie sınırları test edilir. |
| D03.4 | Ayrıntılı RBAC, impersonation ve audit | Finans/destek/yönetim komutlarının backend yetki sınırları; impersonation başlat/bitir ve audit birlikte tutarlı; yetkisiz ve kapsam dışı talepler yan etkiden önce durur. |

D02'de düzeltilen token/2FA/session kalıcılığı tekrar yazılmaz; D03 bunların yetki ve gerçek kullanım sınırlarını tamamlar. Her paket önce gerçek açığı gösteren regresyon, sonra düzeltme ve ilgili/tam test kanıtıyla ilerler. Yeni bulunan kusurlar ilgili dört paketin içinde kaydedilir; ayrı ve sürekli büyüyen D02 alt paketlerine dönüştürülmez. HTTP ekranları ve bütün composition root kurulumu D08 ile birlikte doğrulanır; D03'ün backend reddetme kuralları ekranlara bırakılmaz.

14.10.2026: D03.1 runtime sabit anahtar kaldırma ve explicit key doğrulama uygulandı; D03-1-KEY-SONUC.md kanıtıdır. D03.1 henüz kapanmadı. Aynı paket içinde kalan işler: installer anahtar provisioning/recovery/rotation bağı; SMTP, sunucu ve TOTP sırlarının şifreli depolanması ve eski verinin güvenli geçişi; log/export redaction. D03.2–D03.4 başlamadı. D02 yerel kapanışı kendi Git commitinde korunur.
