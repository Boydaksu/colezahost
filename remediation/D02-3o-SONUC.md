# D02.3b devamı — sağlayıcı readback ve kalıcı provisioning uzlaştırması

Tarih: 12.10.2026. Recovery hazırlığı sırasında mevcut P09/F14 sınırındaki kritik retry kararları düzeltildi. D02 IN_PROGRESS; independent_acceptance=PENDING; production_ready=false.

## Düzeltilen hatalar

UncertainResponseReconciliationService eksik username/server, desteklenmeyen provider ve accountsummary error/null cevabını RECONCILED_NOT_FOUND olarak döndürüyordu. ProvisioningRetryRunner bu sonuca dayanarak yeni create account akışına ilerleyebiliyordu. Servis yalnız username eşleşmesiyle başka domain/plan veya askıda hesap için de local completed/active kararı verebiliyordu. D02-3o-before.txt üç özgün regresyonun başarısızlığını gösterir: eksik hedefin absence sayılması, API error'ın absence sayılması ve yanlış domainin completion sayılması.

Yeni RECONCILIATION_REQUIRED/unknown sözleşmesi belirsizliği korur. RetryRunner unrecognized/unknown sonucu createacct yoluna düşürmez; yalnız explicit isNotFound kararı o yola gidebilir. Bu adım cPanel için authoritative absence üretmez. Dolayısıyla eski hata mesajındaki “does not exist” ifadesi otomatik yeni hesap yaratma izni vermez; operatör/gerçek absence proof gerekir. Network/server failure için mevcut unreachable backoff ve attempt politikası ayrı korunur; queued manual observation status değiştirmez, unknown automatic job incelemeye alınır ve attempt counter değişmez. Legacy unreachable runner dalı ayrıca failure attempt sayabilir.

## Readback ve kalıcı karar

Yeni getVerifiedAccountSummary yalnız GET accountsummary kullanır; HTTP200 ve strict metadata result1, tek account ve açık user/domain/plan/ip/suspended ister. Yanlış/eksik/çoklu account, auth/HTTP/schema error ve null cevap absence kabul edilmez. Kullanıcı/domain/package ve server/provider bağı eşleşmeli; hesap etkin ve IP geçerli olmalıdır. Eski getAccountSummary diğer caller'larda aynı kalır; onların genel güvenliği bu adımla kabul edilmiş sayılmaz.

Account summary, şu anki hedef durum için gözlemdir; geçmiş komutun bunu gerçekleştirdiği kanıtlanmaz. Başarılı provisioning kararının result_json ve audit'i sanitized ACCOUNT_POSTCONDITION, historical_operation_applied_proven=false, automatic_retry_allowed=false ve input fingerprint içerir. API ek alanları, email/password/token veya exception/error reason metni kopyalanmaz. Unknown gözlem existing provisioning_audit_logs tablosunda reconciliation action, reason code ve fingerprint ile kalıcıdır. Queued manual observation status değiştirmez; unknown automatic retrying job audit ile aynı transaction'da reconciliation_required durumuna alınır, next_attempt_at temizlenir, attempt counter değişmez. Bu iş due retry listesinden çıkar ve aynı hizmet için fresh workflow start engellenir. Doğru postcondition varsa pending local service+placement+operation completion+audit birlikte transaction'da commit edilir; gerçek SQL trigger yazım hatasında tamamı rollback olur.

Remote GET transaction dışında yapılır. Commit sırasında operation row lock ve current read ile UUID/id/service/server/provider/action/payload/correlation/status/attemptCount yeniden karşılaştırılır. Service row kilitlenir ve yerel service domain/username/status/placement bağı denetlenir. Local service ve operation servisleri aynı Connection kullanmalıdır; caller transaction içinde probe reddedilir. Completed job tekrar GET/attempt/audit üretmez; stale DTO yeni completed/failed kararı ezemez. Bu davranış provider-side exactly-once veya lease garantisi değildir.

Resmî API referansı: [accountsummary](https://api.docs.cpanel.net/openapi/whm/operation/accountsummary/), [WHM API 1 girişi](https://api.docs.cpanel.net/whm/introduction). Bu referanslar endpoint sözleşmesi için kullanıldı; canlı cPanel sunucusu/test hesabı sorgulanmadı. Testler mevcut transport üzerinden kontrollü yanıtlarla çalıştı.

## Test kanıtı

22 ortak SQLite/MariaDB senaryosu sağlam aktif hesap ve audit, unknown kalıcılığı/no counter mutation, yanlış domain/plan/suspension/IP/user, missing/multiple/malformed response, success görünümlü HTTP302/400/401/403/404/503 body, unsupported action/provider ve missing/mismatched server, local domain drift, audit trigger rollback/retry, stale state, caller transaction, unknown no-create, secret redaction, completed replay ve unknown automatic job hold/fresh workflow block sınırlarını doğrular. D02-3o-hold-before.txt geliştirme sırasında iki başarısız hold/start guard regresyonudur. MariaDB iki ek test dörder ayrı worker'da tek completion/attempt/audit ve eski repeatable-read snapshot üzerinden committed sonucu ezememe ölçütlerini doğrular. Yeni provisioning worker native prepares kullanır; bütün eski worker yolları için bu iddia yapılmaz.

Özgün başarılı fixture'lara suspended=0 eklendi; completion/no-duplicate beklentileri değişmedi. API not-found error'ı safe retry sayan eski test, üç fail-first kanıtına göre unknown ve isNotFound=false beklentisine yükseltildi; test silinmedi/atlanmadı. Geliştirme sırasında eksik positive fixture'lar provisioning ve full-main çıktılarında görünür; son kabul aşağıdaki iki tam pakettir.

| Paket | Test | Assertion | Sonuç |
|---|---:|---:|---|
| Ana paket | 1162 | 8873 | Başarılı |
| MariaDB 10.11.18 | 280 | 1864 | Başarılı |
| Toplam | 1442 | 10737 | Failure/error/skip yok |

Final ham kanıt D02-3o-suite.txt/xml ve D02-3o-mariadb.txt/xml; changed-file SHA-256 D02-3o-file-hashes.json. Şema/migration değişmedi: 15 migration ve 91 DDL source envanteri aynı. Üretim veri/provider çağrısı veya production deploy yapılmadı.

## Açık işler

Genel automation action-level attempt journal, sağlayıcı operation/idempotency/lease/ACK ve controlled retry, authoritative absence/ownership proof, ilk dış komutun hangi server configuration'da çalıştığı provenance'ı, provider state'nin query sonrası değişimi, actor RBAC/tenant ve tüm Application/HTTP/CLI/cron girişleri açık. Matching username/domain/plan ve stable id, önceden var olan aynı hesabın bu operation'a ait olduğu kriptografik ownership kanıtı değildir. Completion desired local postcondition kabulüdür, geçmiş API komutunun kanıtı değildir. Yeni endpoint/otomatik destructive retry eklenmedi. Sanitized username/domain/IP observation'ları hâlâ privacy/retention/backup kapsamına alınmalıdır; diğer permissive account summary caller'ları ayrıca incelenmelidir. Sonraki bounded iş otomasyonun action-level attempt kaydını doğrulama/karar hattına bağlamak ve eksik sonuçları iş bazında izlemektir.
