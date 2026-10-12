# D02.3b devamı — kalıcı kural sürümleri ve yarım kalan işlerin görünürlüğü

Tarih: 12.10.2026. Orijinal P11.2 ve inceleme F14 kapsamında yerel düzeltme/test adımıdır. D02 IN_PROGRESS; independent_acceptance=PENDING; production_ready=false.

## Sonuç

Migration 14 automation_rule_versions ledger'ını kurar. Tam JSON snapshot event filtreleri/manual scope/schedule interval, nested koşullar, THEN/ELSE eylem parametreleri, approval role/timeout/reason ve mevcut rule ayarlarını saklar. Restore yalnız açıkça desteklenen built-in türleri üretir; custom executable türler/object/resource verisi reddedilir. Önceki sığ snapshot yerine memory manager da aynı sözleşmeyi kullanır. Eski bellek geçmişi geri kazanılmış gibi gösterilmez.

RuleVersionManagerInterface container üzerinden engine'in kalıcı deposuna bağlıdır. Sürüm numarası önce singleton satır kilidi, ardından latest version current read ile atanır. INSERT hatası, rollback ve paralel yayın eski kayıtları değiştirmez ve numara tüketmez. Sürüm id/snapshot/header tutarsızlığı okunurken durdurulur. Uygulama request'inde DDL yoktur; factory migration 14 tablosunun bulunmasını ister. Önceki 13 migration değiştirilmez.

executeVersion açık sürümün tanımını yükler. Aynı kayıt numarasına sahip fakat değiştirilmiş bir rule nesnesi çalıştırılamaz; yeni yayın gerekir. Condition bir kez değerlendirilir. Pending approval/delay kayıtları source rule version/fingerprint ve condition/branch sonucunu korur. v2 yayımlandıktan sonra v1 işi v1 action parametrelerini kullanır; resumed result/history özgün ELSE/condition/version değerlerini gösterir. Eski provenance'sız D02-3j kayıtları uyumlu okunur ve unknown metadata korunur. Fingerprint imza/authorization veya veritabanı üzerinde tamper-proof koruma değildir.

AutomationRecoveryInspector sonucun bulunmadığı, başarısız olduğu veya çeliştiği APPROVED/DISPATCHING/DISPATCHED işlerini salt okunur listeler. Başka correlation/rule geçmişi kabul edilmez. Tamamlanmış tek SUCCESS outcome normal tamamlanmış iş olarak ayrılır. Araç hiçbir işi yeniden başlatmaz; automatic_retry_allowed=false. Dış sağlayıcıda sonucun belirsiz olduğu iş için güvenli otomatik kurtarma veya exactly-once sağlandığı iddia edilmez.

## Test kanıtı

İlk regresyon (D02-3k-before.txt) davranış değiştiren trigger filtre/condition/action değişikliğinin diff'te görünmediğini gösterdi. Ortak 13 test SQLite ve gerçek MariaDB'de complete restore/diff, manual/schedule/filter kapsamı, v1 onay ve erteleme eylemlerinin korunması, ELSE sonuçları, schema rerun, rollback, gerçek trigger INSERT hatası sonrası devam, invalid object/provenance, bozuk snapshot ve read-only recovery incelemesini sınar. MariaDB ayrıca dörder ayrı süreçle 1–4 farklı version allocation ve yeni PDO/eski repeatable-read snapshot ile current read davranışını sınar. Üç migration adedi 13→14; eski AutomationPersistenceCases explicit install listesi migration 14 şemasını içerir. Mevcut testler atlanmadı veya başarısızlığı gizlemek için gevşetilmedi.

| Paket | Test | Assertion | Sonuç |
|---|---:|---:|---|
| Ana paket | 1074 | 8482 | Başarılı |
| MariaDB 10.11.18 | 186 | 1206 | Başarılı |
| Toplam | 1260 | 9688 | Failure/error/skip yok |

Ham kanıt D02-3k-suite.txt/xml ve D02-3k-mariadb.txt/xml; değişen dosya SHA-256 özetleri D02-3k-file-hashes.json. Envanter 90 CREATE TABLE kaynak dosyasıdır; metin taraması bütün schema/workflow kabulü değildir.

## Kalan işler

Güvenli worker lease/retry, external idempotency/reconciliation ve gerçek provider sandbox kanıtı; kalıcı blast-radius sayaçları; approval+delay birlikte uygulama; actor RBAC/tenant sınırları; cron/HTTP/CLI Application Command/Query girişlerinin tümü; backup/privacy/retention ve diğer domain schema matrix açık. Read-only envanter authentication/production endpoint veya sağlayıcı sonucu yerine geçmez. Üretim migrationı/restore yapılmadı; bağımsız veya release PASS sonucu üretilmedi. Sonraki bounded adım kalıcı blast-radius ve kontrollü kurtarma sözleşmesinin hazırlanmasıdır.
