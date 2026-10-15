# D04 — sipariş/fatura, yenileme ve finans performansı

Birincil kaynak: orijinal ZIP P05, P06 ve P11; remediation/PLAN.md F07/F15/F30. D03 yerel kapanışı korunur. Bağımsız ürün kabulü D09'dadır.

| Adım | Sabit kapsam | Çıkış ölçütü |
|---|---|---|
| D04.1 | Sunucu fiyatı ve parasal bütünlük | Müşteri fiyat/vergi/toplam gönderemez; aktif ürün ve geçerli seçimler kayıtlı fiyatla hesaplanır. Ayrı yönetici komutu override yetkisi ister. Negatif/kesirli/taşan/tutarsız kalemler reddedilir; sipariş-fatura yuvarlama ve snapshot korunur; child/audit/parent rollback ve current MariaDB okumaları test edilir. |
| D04.2 | Yenileme ve ödeme tekrarları | Aynı hizmet/dönem için tek fatura; paid invoice replay tek uzatma; owner/dönem/state kilidi ve concurrent/caller failure kabulü. |
| D04.3 | Gerçek ticaret akışı ve sorgu bütçesi | Katalog fiyatı → sipariş → fatura → ödeme → yenileme kalıcı akışı; sahte fiyat/boş servis bağımlılığı olmayan fixture; listeleme/batch sorgu bütçeleri, snapshot geçmişi ve F30 performans kabulü. |

D04.1, D04.2 ve D04.3 yerel uygulama ve doğrulama tamamlandı. Kapanış D04-CLOSURE.md; son kanıt D04-3-SONUC.md. D04'te kalan yerel uygulama adımı yok; sonraki faz D05. Bağımsız ürün/production kabulü açık. Sağlayıcı dış etkileri D06, HTTP/composition D08, reference-host/release kabulü D09 sınırını korur.
