# D02.3b devamı — yarım kalan otomasyonun yerel sonuç bütünlüğü

Tarih: 12.10.2026. P11.2/P11.6 ve F14 kapsamındaki recovery hazırlığı. D02 IN_PROGRESS; independent_acceptance=PENDING; production_ready=false.

## Sonuç

Mevcut inspector yalnız rule/correlation ile eşleşen tek SUCCESS kaydını yeterli sayıyordu. Yanlış sürüm veya eksik/başarısız action sonucu işi görünmez yapabiliyordu. Parent-child onay bağı da yalnız id/rule/correlation kontrol ettiğinden değişmiş child parametresi özgün onaydan farklı bir işlem çalıştırabiliyordu. D02-3n-before.txt üç özgün başarısız regresyonda bu davranışları gösterir; değiştirilmiş child handler'a bir kez ulaşmıştır.

PendingExecutionIdentity, pending işin rule/context/actions/execution snapshot girdilerini SHA-256 ile bağlar; yeni kalıcı resumed history additive pending_execution_fingerprint içerir. Özet ve veri doğrulaması decision/claim/handler öncesinde hazırlanır. Null/InMemory history gereksiz persistence kurallarıyla object context desteğini kaybetmez. Inspector local completion için tek ACTIVE SUCCESS, exact input hash, doğru version/frozen hash/branch/condition/event/context, sıralı bütün action id/type/SUCCESS/error sonuçları ve final tarihten önce olmayan outcome arar. Başarılı header altında başarısız veya eksik eylem, stale tarih, duplicate receipt, wrong input ve DISPATCHING iş tamamlanmış sayılmaz. Eski hash'siz history geriye dönük uydurulmaz; unresolved kalır. Yeni çalıştırılmış kaynaksız legacy delay version=0/UNKNOWN işaretlerini korur ve exact local receipt ile incelenebilir.

ApprovalDelayLink özgün approved parent ile child id/state/context/action parameters ve frozen policy/snapshot eşleşmesini denetler. Coordinator same-actor replay ve DatabaseDelayManager claim aynı kontrolü kullanır. Child origin reference silinse bile approved parent'ın reverse bağı current-read ile görülür. Missing/mismatched/orphan child handler'dan önce durdurulur ve scheduled state korunur; otomatik yeni child yaratılmaz. Bekleme bitişi scheduled_at+approved seconds ile tutarlı olmalıdır. D02-3n-timing-before.txt bu kontrol öncesinde yalnız execute_at değişimiyle claim yapılabildiğini gösterir.

PendingStore SQL id/status ile JSON id/status'unu find/list/mutate yollarında karşılaştırır. Inspector history row id/rule/correlation ile payload bağını doğrular. Bozuk veri sessizce onaylanmaz veya düzeltilmez; inceleme gerekebilir ve tüm inspector çağrısı fail closed durabilir. Reverse lookup şu an approved parent JSON kayıtlarını tarar; yüksek hacimde relational link/index ve retention optimizasyonu ayrıca değerlendirilmelidir. İstek sırasında DDL yok; tablo/indeks değiştirilmedi, 15 migration ve 91 DDL kaynak envanteri aynı kaldı.

## Test kanıtı

19 ortak senaryo SQLite ve MariaDB'de sağlam direct approval/delay/restart/legacy, yanlış sürüm veya iş girdisi, bir receipt içinde 16 ayrı çelişki varyantı, duplicate/stale/partial/failed/unknown sonuç, context/action/policy/version/origin/timing bozulması, orphan, SQL/JSON kimlik ve statü çelişkisini sınar. Inspector pending state değiştirmez; hiçbir otomatik retry veya provider komutu başlatmaz. MariaDB'de ek dört worker testi tek linked-child claim gösterir; ayrı PDO bağlantısı ve eski repeatable-read snapshot üzerinden reverse parent lookup committed state'i görür. Eşleşen local receipt sonrası ikinci direct approval ve ikinci delayed dispatch handler'ı yeniden çalıştırmaz.

Özgün ApprovalDelayCases makeDue test yardımcı fonksiyonu beklemenin bittiğini simüle etmek için başlangıç ve bitiş tarihlerini beraber kaydırır. 22 mevcut senaryo ve bekleme süresi beklentileri korunur; yeni negatif timing testi tek timestamp tutarsızlığını yasaklar. Üç ek Unit testi bellek-only object context uyumluluğu ve persistent capture hatasında PENDING/no effect/no history sınırını doğrular. D02-3n-capture-before.txt bu geliştirme sırasında yeni kodda yakalanan iki hata/bir başarısızlığın kanıtıdır; orijinal baseline'a atfedilmez. Migration sayacı/test korumaları düşürülmedi; test atlanmadı. Ön geliştirme ana paket çıktısı D02-3n-target-main.txt, son kabul kanıtı aşağıdaki iki tam pakettir.

| Paket | Test | Assertion | Sonuç |
|---|---:|---:|---|
| Ana paket | 1140 | 8788 | Başarılı |
| MariaDB 10.11.18 | 256 | 1717 | Başarılı |
| Toplam | 1396 | 10505 | Failure/error/skip yok |

Ham final kanıt D02-3n-suite.txt/xml ve D02-3n-mariadb.txt/xml; değişen dosya SHA-256 özetleri D02-3n-file-hashes.json. Gerçek üretim/provider verisi değiştirilmedi; GitHub yayını production deploy değildir.

## Açık sınırlar ve sonraki iş

Bu hash cryptographic authorization, DB tamper-proof seal veya provider readback kanıtı değildir. DB'ye yazabilen biri bütün tutarlı kayıtları birlikte değiştirebilir. Local SUCCESS handler'ın bildirdiği sonuçtur; inspector boş liste verse bile sağlayıcı mutabakatı tamamlandı sayılmaz. Bütün eski history fingerprint'lerini doldurup belirsiz işi başarılı göstermek yasaktır; gerçek outcome/evidence ile ayrıca uzlaştırılmalıdır.

Action-level attempt journal, crash sonrası applied/not-applied/unknown ayrımı için gerçek provider query adapter'ları, kalıcı ve yetkili reconciliation kararları, controlled retry/idempotency, lease/ACK ve pause/provider yarışı açık. Kural/onay/snapshot veri değişimlerinin tamamına signature/immutability garantisi verilmez. Actor RBAC/tenant, bütün Application/HTTP/CLI/cron entrypoints, shared-host production provider doğrulaması, retention/privacy/backup ve kalan domain schema matrix diğer kabul işleridir. Bu adım otomatik yeniden çalıştırma endpoint'i eklemez. Sonraki bounded adım sağlayıcı sonucu doğrulama sözleşmesi ve kalıcı uzlaştırma kararlarıdır.
