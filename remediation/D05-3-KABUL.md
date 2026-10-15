# D05.3 sabit kabul listesi

Birincil kaynak: orijinal P17.5–P17.9, F11/F12/F20 ve D05-PLAN.md. Bu liste yeni faz veya ek bir ürün kapsamı oluşturmaz.

1. **Tutarlı gerçek döküm:** MariaDB InnoDB snapshot; gerçek şema/satırlar, binary/Unicode/decimal/null/generated/FK; SQLite binary/text/index/sequence; caller transaction ve bağlantı ayarlarının korunması. Desteklenmeyen motor/nesne gizlenmez.
2. **Dosya ağacı ve paket:** recursive göreli yollar, açık alias, boş klasör; eksik/değişen/symlink/çakışan kaynak reddi. ZIP içeriği ile manifest tam eşleşir; dosya hash/size, yol/özel dosya/çakışma sınırları importtan önce doğrulanır.
3. **Taze hedefte restore ve kesinti:** streaming SQL ayrıştırma, offline fresh DB kabulü, tablo/satır/FK sağlık kontrolü; dosyaları sibling stage'de hazırlama ve eski ağacı saklayarak geçirme; kalıcı journal ve işlem sonlandırma testi; Recovery guard. Veritabanı DDL'sinin geri alındığı iddia edilmez.
4. **Zorunlu privacy/key kapıları:** kullanıcı içeren yedekte authoritative out-of-band inventory; doğru uygulama ve privacy anahtarları; tombstone reapplication ve related mapping/write/readback hatasında başarısızlık; hiçbir başarısız kapıdan sonra yeni dosyaların açılmaması. Başarı otomatik Normal/production açılışı değildir.

Mevcut nonempty DB üzerinde rollback yalnız açık replacement seçeneğiyle mümkündür; bu eski yolu atomik hale getiren update/rollback orchestration D05.4'e aittir. PreUpdateBackupService'in ortak mandatory verified backup hattına bağlanması D05.4'te kalır. Bütün ürünün HTTP/cron/worker girişlerinde aynı journal guard'ının bağlanması D08; bağımsız host/production kabulü D09'dur.

Yerel sonuçlar ve ham kanıtlar D05-3-SONUC.md ve D05-3-summary.json içinde kaydedilir. Bu belge tek başına PASS/bağımsız kabul kaydı değildir.
