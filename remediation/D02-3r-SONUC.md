# D02.3b devamı — hizmet otomasyonunda yerel/sağlayıcı sınırı

Tarih: 12.10.2026. Orijinal plan P11.3/P11.5 ve F14 davranış düzeltmesi. D02 IN_PROGRESS; bağımsız kabul PENDING; production_ready=false.

## Düzeltilen davranış

Askıya alma, yeniden açma ve sonlandırma adaptörleri, sunucuya yerleştirilmiş bir hizmette gerçek provider komutu çalıştırmadan yerel durum değiştirip SUCCESS döndürüyordu. D02-3r-before.txt üç özgün başarısız testi gösterir. Provider workflow hazır olmadığı için bu yola yeni bir uzak mutation eklenmedi; hatalı başarı kapatıldı.

Üç adapter artık domainin executeLocalAutomationLifecycle metodunu kullanır. Aynı transaction'da service kilidi/current read, placement current read ve mevcut lock_version ile state-machine/CAS uygulanır. Herhangi bir placement kaydı (evicted/reserved/migrating/unassigned veya NULL server dahil) ve legacy username/server_name/ip_address, PROVIDER_LIFECYCLE_REQUIRED hatası üretir; hizmetin status/lock_version alanları değişmez. Eksik placement tablosu sessizce boş hedef kabul edilmez. Böyle hizmetler sağlayıcı iş akışı sağlanana kadar failed/review durumundadır.

Yerel olumlu sonuç açıkça LOCAL_SERVICE_STATE ve provider_outcome_proven=false taşır; uzak hesap üzerinde başarı iddia etmez. Kör integer cast kaldırılır: id pozitif canonical integer, supplied version ise sıfır veya pozitif canonical integer olmalı. Suffix, exponent, padding, float/bool/array ve overflow başka hizmet veya geçerli lock'a dönüştürülemez. HEAD=0f87265 adaptörlerinin değişmemiş kaynaklarında iki malformed testin failure'ı D02-3r-input-before.txt dosyasındadır.

## Doğrulama

12 ortak SQLite/MariaDB senaryosu, MariaDB iki ek senaryoyla 14 hedefli test. Kimlik ve sürüm varyantları üç adapter üzerinde denenir; yerel olumlu davranış, legacy remote identity, placement durumları, missing table, stale version, context fallback ve caller rollback, persistent failed history/journal ve no automatic retry ölçülür. MariaDB eski repeatable-read snapshot'ın başka native bağlantıda committed placement'ı gizleyememesini ve dört native worker'da aynı version için tek local değişikliği doğrular.

| Paket | Test | Doğrulama | Sonuç |
|---|---:|---:|---|
| Ana paket | 1204 | 9263 | Başarılı |
| MariaDB 10.11.18 | 326 | 2373 | Başarılı |
| Toplam | 1530 | 11636 | Failure/error/skip yok |

Final kanıt D02-3r-suite.txt/xml ve D02-3r-mariadb.txt/xml; changed-file SHA-256 D02-3r-file-hashes.json. Eski olumlu adapter ve Golden test beklentileri değiştirilmedi. Geliştirme worker exception namespace hatası düzeltildi; test atlanmadı. Son ek legacy case iki tam final pakette çalıştı. 16 migration/92 DDL kaynak aynı; runtime DDL eklenmedi. Gerçek provider veya üretim verisi kullanılmadı, üretime deployment yapılmadı.

## Kalan işler

Gerçek lifecycle Application workflow, trusted actor/tenant RBAC, provider command/operation-attempt/configuration provenance ve readback, provider lease/idempotency/ACK ve controlled retry açık. Yeni endpoint veya dış işlem çalıştırılmadı. Yerleşim yokluğu uzak hesabın kesin yokluğunu kanıtlamaz; mevcut yerel başarı yalnız bookkeeping sonucudur. Yeni placement/provisioning'in check sonrasında başlamasına karşı bütün writer'larda ortak lock/provider lease garantisi yoktur.

Domainin doğrudan yerel lifecycle metotları, manual/overdue yolları ve cancel/renew/billing adapter girişleri bu adımla sağlayıcı güvenliği kabulü kazanmaz. Failed action sonrası sonraki action/notification davranışı mevcut engine policy'sini kullanır; bütün kuralın atomik iptali iddia edilmez. Girdi reason metni/diğer alanlar ve genel API validation, retention/privacy/backup, Application/HTTP/CLI/cron ve D03–D09 kabulü açık. Gerçek lifecycle Application command ve actor/tenant/provider operation bağı için D03 yetki önkoşulları gereklidir. Önce D02 kalan schema/runtime DDL/kısıt kabul matrisi tamamlanacak; ardından D03 yetki fazına geçilecektir.
