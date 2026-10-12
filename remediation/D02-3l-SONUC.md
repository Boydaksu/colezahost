# D02.3b devamı — onay ve ertelemenin atomik birleşik akışı

Tarih: 12.10.2026. P11.2/F14 mevcut kapsamının düzeltmesidir. D02 IN_PROGRESS; independent_acceptance=PENDING; production_ready=false.

## Sonuç

Onay ve delay içeren kuralda approve sonrası doğrudan eylem çalıştırılması düzeltildi. Engine bekleme politikasını pending JSON snapshot'una yazar; DatabaseApprovalDelayCoordinator approval kararı, delayed child, approval-child bağı ve DELAYED handoff history'sini aynı Connection transaction'ında, singleton/current-read kilidi altında saklar. Hiçbir dış eylem handoff sırasında çalışmaz. Bekleme approval sonrası child scheduled_at üzerinden başlar; approval öncesinde geçen süre bekleme penceresinden düşülmez. Açık delay=0 eskisi gibi onaydan sonra bir kez doğrudan çalışabilir.

Yeni linked_delay_id alanı additive/nullable JSON'dur; eski payload okunur. Fiziksel SQL şeması, tablo veya indeks değiştirilmedi; 14 migration aynı kalır, runtime DDL yoktur. Factory ve CoreServiceProvider coordinator interface'ini gerçek depoya bağlar; eksik coordinator ile delay bypass yapılamaz. Aynı actor tekrarı orijinal child id/kararı döndürür ve yeni job/history üretmez. İş iptal edilmiş veya tamamlanmış olsa bile bu yanıt orijinal handoff'un tekrarıdır; güncel job durumu delay deposundan okunur. Farklı actor kararı ezemez; bu string sahiplik koruması backend RBAC/tenant yetkilendirmesinin tamamlandığı anlamına gelmez.

Delay politikası kayıtlı özgün version/hash üzerinden doğrulanır; yeni JSON alanı frozen sürümle çelişemez. D02-3k payload'ında delay alanı eksikse v1'in delay'i kullanılır; daha yeni v2'nin policy'si alınmaz. Sürüm/hash ve açık policy olmayan tarihi onay, delay=0 varsayımıyla çalıştırılmaz; uzlaştırma gerekir. Kaynaksız manual approval çağrısı explicit delay_seconds sağlayabilir, bilinmeyen diğer provenance alanları unknown kalır. Parmak izi DB tamper-proof imza veya authorization kanıtı değildir.

Coordinator Global/Destructive pause'u kilit altında tekrar kontrol eder. History INSERT veya son approval UPDATE hatasında karar, child ve handoff history birlikte rollback olur. Eksik/yanlış linked job yeni job üretilerek gizlenmez. Final dispatch history approval/delay bağı taşır. RecoveryInspector normal scheduled/cancelled handoff'u belirsiz external sonuç diye tekrar listelemez; in-flight delay bir kere ve eksik linked child ayrıca görünür. Handoff/dry-run/observe history gerçek final başarı yerine geçmez; hiçbir belirsiz dış eylem otomatik tekrar başlatılmaz.

## Test kanıtı

D02-3l-before.txt özgün approve yolunun DELAYED yerine SUCCESS verip delay'i atladığını gösterir. Geliştirme sırasındaki D02-3l-policy-before.txt ise explicit delay ile kayıtlı frozen version çelişkisinin durdurulması için ikinci başarısız regresyondur. Ortak 22 senaryo SQLite ve MariaDB'de restart, same-actor replay, v1/v2/ELSE bağı, due dispatch, explicit zero/manual policy, geçmiş veri fallback, expiry, Global/Destructive pause, trigger ile history/approval yazım hatası ve retry, outer rollback, iptal, missing coordinator/link ve inspector ölçütlerini sınar. MariaDB'de iki ek test dörder süreçle aynı actor için dört başarılı replay/tek child ve farklı actor için tek kabul edilmiş karar/child doğrular.

Eski AutomationPersistenceCases doğrudan approval-only fixture'ı explicit delay_seconds=0 ile korunur; condition/version unknown beklentileri değiştirilmez. Belirsiz tarihi politikayı durduran yeni negatif test eklenir. Container coordinator binding beklentisi güçlendirilir. Mevcut korumalar gevşetilmez; testler atlanmaz.

| Paket | Test | Assertion | Sonuç |
|---|---:|---:|---|
| Ana paket | 1096 | 8567 | Başarılı |
| MariaDB 10.11.18 | 210 | 1362 | Başarılı |
| Toplam | 1306 | 9929 | Failure/error/skip yok |

Ham kanıt D02-3l-suite.txt/xml ve D02-3l-mariadb.txt/xml; değişen dosya SHA-256 özetleri D02-3l-file-hashes.json. Kaynak değişiklikleri tamamlandıktan sonra iki tam paket çalıştırıldı. Üretim verisi değiştirilmedi.

## Kalan işler

Kalıcı destructive-action/blast-radius sayaçları ve global işlem sınırlaması; worker lease/ACK ve pause/dış komut arasındaki yarış; güvenli retry/real provider reconciliation/idempotency; backend actor RBAC/tenant ve tüm Application/HTTP/CLI/cron girişleri; privacy/retention/backup ve diğer domain şemaları açık. Eksik sonuç için inspector, dış sağlayıcı mutabakatının veya doğrulanmış restore'un yerine geçmez. Üretim cutover'da eski worker'lar durdurulup tutarlı kod sürümü kullanılmalıdır; GitHub'a gönderme production deploy değildir. Sonraki bounded adım kalıcı yıkıcı işlem sınırlarıdır.
