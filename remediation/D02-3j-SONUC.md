# D02.3b devamı — otomasyon kayıtlarının kalıcılığı

Tarih: 11.10.2026. Referans: orijinal P11.2/P11.6 ve inceleme F14. Kapsam bu devam adımının yerel uygulama/veritabanı düzeltmeleridir. D02 IN_PROGRESS; bağımsız kabul PENDING; production_ready=false.

## Uygulanan değişiklikler

Migration 13 automation_pending, automation_run_history, automation_emergency_pause ve automation_state_lock kurar. Durum/geçmiş filtrelerinin indeksleri vardır. Tekrar kurulum mevcut pause ve pending kayıtlarını sıfırlamaz. DatabaseAutomationFactory dört kalıcı depoyu engine'e bağlar ve eksik güvenlik/şema durumunda başlangıcı durdurur; uygulama isteklerinde DDL yoktur. CoreServiceProvider engine ve dört interface için bu depoları varsayılan olarak kaydeder; yeni application container aynı güvenlik durumunu okur. Önceki 12 migration değiştirilmez; üç adet beklentisi 12→13 olur.

Approval/delay payload'ları event adı, sabit correlation/timestamp, context, ActionDefinition parametreleri ve karar/dispatch durumlarını JSON olarak tutar. Object/resource/closure içeren veri ve özel çalıştırılabilir ActionInterface implementasyonları reddedilir; PHP serialize/unserialize yoktur. Nesneleri veriden yeniden üretirken tarih ve kararları yeniden oynatmaz. Onay kararları ve claim durumları singleton kilidi altında atomik saklanır; rollback kayıtları geri alır. History append-only INSERT kullanır, aynı run_id önceki kanıtı ezemez.

Engine gerekli approval/delay yöneticisi yokken active eyleme geçmez. Süresi dolmuş onay uygulanmaz; timeout sınırı eşitlikte de geçerlidir. Global/Destructive pause işleri bekletir; resumed actor/zaman/gerekçe kalıcıdır. Approved ve delayed çalıştırmaların son sonucu history'ye yazılır. Eski pending veri sözleşmesinde rule version bulunmadığı için resumed kayıtta version=0 ve rule_version_unknown işareti vardır. Kaynak condition/branch de eski pending sözleşmesinde yoktur; history branch=UNKNOWN, condition_unknown=true ile belirsizliği açıkça taşır. TriggerContext correlation id ve event zamanı ile AutomationRunRecord zamanı her okumada yeniden üretilmez.

Delayed iş dış etki başlamadan DISPATCHING olur. Dört eşzamanlı süreçten biri claim alır; diğerleri eyleme geçmez. Kesilen süreçten kalan DISPATCHING/APPROVED kaydı otomatik tekrar çalıştırılmaz. Bu işlem provider üzerinde exactly-once veya güvenli otomatik recovery kanıtı değildir. Sonuç/history yazımı ile dış etki tek transaction olamaz; belirsiz sonuçların uzlaştırılması sonraki D06 çalışmasıdır.

## Test kanıtı

D02-3j-before.txt iki başarısız regresyonu gösterir: event correlation id her okumada değişiyordu; approval manager yoksa onay gerektiren kural engellenmiyordu. Yeni kalıcı depolar için ortak 15 senaryo SQLite ve gerçek MariaDB'de restart/karar/expiry/iptal/claim/pause/history/dry-run/rollback ve JSON veri sınırlarını ölçer. MariaDB ayrıca yeni native PDO bağlantısından okuma, dört worker'da tek claim ve dört worker'da tek onay kararı ve eski repeatable-read snapshot üzerinden ikinci karar/claim reddini sınar. Son tam koşu aşağıdadır; testler gevşetilmedi veya atlanmadı.

| Paket | Test | Assertion | Sonuç |
|---|---:|---:|---|
| Ana paket | 1060 | 8414 | Başarılı |
| MariaDB 10.11.18 | 171 | 1099 | Başarılı |
| Toplam | 1231 | 9513 | Hata, başarısızlık ve skip yok |

Ham kanıt: D02-3j-suite.txt/xml ve D02-3j-mariadb.txt/xml. Dosya özetleri D02-3j-file-hashes.json. Şema envanteri 89 CREATE TABLE kaynak dosyasıdır; metin referansı bütün şemaların/akışların kabulü değildir.

Son resumed-history UNKNOWN metadata düzeltmesi ana pakette ve ayrıca gerçek MariaDB otomasyon paketinin tamamında doğrulandı: 19 test / 131 assertion, hata/failure/skip yok. Ek tekrarın ham kanıtı D02-3j-automation-mariadb-final.txt/xml; yukarıdaki tekil paket toplamına tekrar eklenmez. MariaDB tam paketi devam ederken yapılan bu son metadata değişikliği için ek regresyon koşusu kullanıldı; tam paketin tüm testlerinin son sürümde yeniden çalıştığı iddia edilmez.

## Kalan işler

Kalıcı rule/version ve blast-radius sayaçları; iş kesilmesinde lease/recovery/reconciliation; onaylayan kişinin backend RBAC/tenant kontrolü; approval sonucu ile rule version/branch bağlantısı; pause değişimiyle dış komut arasındaki yarış; gerçek cron/HTTP/CLI Application Command girişleri ve sağlayıcı sandbox testleri açık. Factory uçtan uca yerel engine testlerinde kullanıldı; bütün ürün girişlerinin migration/factory bağını kullandığı iddia edilmez. Bu kayıtların context/action/result verisi privacy/retention ve backup kapsamına D05/D07'de alınmalıdır. Üretim migrationı çalıştırılmadı ve bağımsız/release PASS üretilmedi.
