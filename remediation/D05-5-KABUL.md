# D05.5 — sabit kabul kapsamı

16.10.2026. Orijinal master plan P17.7/P17.9 ve EXTENSION_CONSTITUTION.md temel alınır. D05'in son sabit adımıdır.

| Kontrol | Beklenen davranış |
|---|---|
| Paket kabulü | Ortak imzalı, dondurulmuş paket; özel quarantine; exact checksum; traversal/symlink/case/ZIP bomb sınırları; katı descriptor/Core/API/dependency/izin doğrulaması |
| Etki öncesi onay | Salt okunur gerçek migration preview; paket digest'i ve tam izin listesine bağlı açık onay; doğru DB/module tree yedeğinin bağımsız doğrulanması |
| Kurulum/etkinleştirme | Namespaced declarative migration ve hash ledger; özel staged dosyalar; önce disabled kayıt; gerçek SQL/key/privacy/file health ve host adapter sözleşmesi geçince enable |
| İzolasyon API'si | Sadece kendi tablo/dosya/ayar/Vault namespace'i; açık origin izni; namespaced route; her çağrıda güncel izin/bağımlılık/disable/integrity denetimi |
| Hata ve recovery | Kalıcı guard/journal ve ortak DB/hedef lease; yarım DDL, audit hatası, ayrı süreç kesintisi ve yarış; açık gerçek DB/files rollback; otomatik Normal açılışı yok |

Desteklenen v1 migration grameri create_table JSON'dur; yüklenen PHP/raw SQL çalıştırılmaz. PHP paket dosyaları özel depolanır ve syntax kontrolünden geçer. Host'un güvenilir adapter kaydı gerekir; aynı süreç PHP'si işletim sistemi sandbox'ı değildir. Mevcut modülün upgrade/uninstall'ı fresh install gibi yorumlanmaz; duplicate/orphan kurulum reddedilir. Disable veriyi korur.

D06 gerçek provider adapter/transport; D07 ürün retention/erasure; D08 authenticated HTTP/CLI/cron/worker composition ve Normal aktivasyon; D09 bağımsız host/harici hesap/browser kabulü açık kalır. Bunlar D05'e yeni alt iş eklemez. Başarılı yerel test üretim hazır kabulü değildir.
