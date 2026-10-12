# D02.3b devamı — kalıcı otomasyon eylem deneme kaydı

Tarih: 12.10.2026. Orijinal plan P11.2 (run history), P11.6 (safety) ve inceleme F14 kapsamındaki eksik kayıt davranışı düzeltildi. D02 IN_PROGRESS; bağımsız kabul PENDING; production_ready=false.

## Sorun ve sonuç

Eski otomasyon yalnız bütün eylemler tamamlandıktan sonra geçmiş yazıyordu. Bir eylem dış etki oluşturduktan sonra kayıt yazımı kesilirse hangi eylemin başladığı kalıcı değildi. Nondestructive eylem caller transaction içinde çalışabiliyor, kayıt rollback edilirken dış etki kalabiliyordu. Ayrıca handler başka action id/type için SUCCESS döndürdüğünde run başarılı sayılabiliyordu. D02-3p-before.txt bu üç baseline testin üç başarısızlığını gösterir.

Yeni automation_action_attempts tablosu ve migration 16 eylem planını tek transaction'da PREPARED yazar. Her eylem başlamadan DISPATCHING kaydı autocommit edilir; aynı kayıt conditional update ile yalnız bir kez başlayabilir. Action parametre tanımı ve correlation değiştirilemez. Başlangıç plan hash'i ve gerçekten dispatch edilen güncel context/resolve hash'i ayrıdır. Rule version/hash, approval/delay id ve pending fingerprint tutulur. Final run history ordered action_attempt_ids ile denemelere bağlanır. Journal raw context/parameter/output veya hata/sır metni saklamaz; sabit reason code kullanır.

Matching handler SUCCESS yalnız REPORTED_SUCCESS'dir. Bu, sağlayıcıda işlem gerçekleştiğine dair bağımsız kanıt değildir. Failure/skipped/exception/yanlış receipt OUTCOME_UNKNOWN; pause/missing handler/admission deny BLOCKED kaydedilir. Yanlış action id/type ve SUCCESS+error cevapları başarılı run yapamaz. Prepare/start/finish kayıt hataları yutulmaz: handler öncesi hata dış etkiyi, outcome yazım hatası da sonraki eylemi engeller. Caller Connection ve raw PDO transaction effect öncesi reddedilir. Approved/claimed inputs değişmişse effect başlamaz.

DatabaseAutomationFactory ve CoreServiceProvider kalıcı journal kullanır; ACTIVE/onaylı/gecikmiş yollar bağlanır. Inspector.listUnresolvedActionAttempts salt okunur PREPARED/DISPATCHING/OUTCOME_UNKNOWN kayıtlarını history olmasa da gösterir, otomatik retry izni vermez. Unknown status veya bozuk identity/hash sessizce kaybolmaz. Eski pending recovery görünümü korunur. Migration ve kurulum yalnız şema kurar; application request/runtime factory DDL yapmaz.

## Doğrulama

22 ortak SQLite/MariaDB senaryosu ve iki ek MariaDB senaryosu vardır. Gerçek SQL trigger ile planın ikinci insert'i, start ve outcome yazımı reddedilir; partial plan rollback, no effect ve next-action stop ölçülür. Yeniden factory oluşturma sonrası belirsiz kayıtların korunması, read-only inspection, approval/delay bağı ve replay no-effect, input substitution/contradictory receipt, secret exclusion ve migration replay veri koruması sınanır. MariaDB dört ayrı worker'da aynı attempt için tek start, ayrıca ayrı native PDO bağlantısında handler çalışırken committed DISPATCHING görünürlüğünü doğrular. Bu testler gerçek crash veya live provider testi değildir.

| Paket | Test | Doğrulama | Sonuç |
|---|---:|---:|---|
| Ana paket | 1184 | 8944 | Başarılı |
| MariaDB 10.11.18 | 304 | 1998 | Başarılı |
| Toplam | 1488 | 10942 | Failure/error/skip yok |

Final ham kanıt D02-3p-suite.txt/xml ve D02-3p-mariadb.txt/xml; changed-file SHA-256 D02-3p-file-hashes.json. Eski BlastRadius transaction testi daha erken refusal exception bekler; no-effect ve permit/retry-after-rollback beklentileri korunur. Üç migration-adedi testi 15→16 güncellenir. DDL kaynak envanteri 92'dir; metin referansı kabul kanıtı değildir. Geliştirme loglarındaki hatalar final başarılı kanıtın yerine kullanılmaz.

## Kalan işler

Provider operation/receipt/configuration provenance ile action attempt bağlama ve controlled reconciliation/retry hâlâ açık. Bu adım cPanel readback'e otomatik karar veya destructive retry eklemez. REPORTED_SUCCESS dış sonucun kesin kanıtı, action hash yetki kanıtı değildir. Aynı ACTIVE event yeniden çağrılırsa yeni dispatch oluşturulur; journal global event idempotency veya provider-side exactly-once sağlamaz. Daha önce başlamış eski worker'lar journal garantisine dahil değildir; üretim cutover ayrıca planlanmalıdır.

Elle new AutomationEngine/null journal compatibility, gerçek actor RBAC/tenant sınırları ve bütün Application/HTTP/CLI/cron girişlerinin kabulü açık. Journal retention/privacy/backup ve eski raw run_history output/error politikası ayrıca incelenecek. Gerçek sağlayıcı hesabı/üretim verisi kullanılmadı, üretime deployment yapılmadı. Sonraki adım: eylem deneme kaydına provider operation bağını ve salt okunur kanıtı iliştirmek; bağlı girdi değiştiğinde kararın reddini doğrulamak.
