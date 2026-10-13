# IDENTITY-C01 — giriş, 2FA ve oturum yaşam döngüsü

Referans commit: `29b71c0da1358689f596d175f06e8a48b50c0d97`. Orijinal master plan P03.1 (sessions/password/login/reset/email verification), P03.2 (TOTP/recovery/trusted devices/admin policy) ve D02 transaction/kurulum sınırları. Yeni HTTP/UI akışı veya sonraki faz kabulü eklenmedi.

## Ne değişti?

IdentityPersistence installed schema projection, canonical primary/unique identity ve MySQL InnoDB kontrolünü paylaşır. Mevcut Auth/2FA explicit setup SQLite migration9 transactional bootstrap'ını korur. İş yolları açık caller içinde eksik tablo kurmaz; kurulu şemada CREATE yapılmaz. Lost Connection transaction depth/PDO mismatch sorgudan önce reddedilir. Own transaction veya random savepoint ile raw/managed caller ve unrelated önceki yazımlar korunur.

Üç yeni lifecycle tablosunda PK dışındaki unique index'ler reddedilir. Örneğin revoked_at veya last_used_step üzerindeki extra unique, MySQL ON DUPLICATE KEY UPDATE'i farklı session/user kaydına yönlendirerek fence'i etkisizleştirebilir. Migration ve business preflight bunu DML/DDL'den önce durdurur. İki `index-development-before` regresyonu bu kontrol eklenmeden önce2 failure verdi; başlangıç commit kanıtı değildir.

AuthService token replacement'ı DELETE+INSERT tek aggregate içinde yapar. Known user row'u token row'undan önce current FOR UPDATE ile kilitlenir; reset/email verify aynı tokenla iki kez başarı vermez. Duplicate legacy exact tokens ilk kayıt seçilerek tüketilmez. TTL ve expires_at eşitliği doğrulanır. Unknown email için opaque response shape korunur, usable orphan token saklanmaz. Authentication current user row'u kullanır; rehash eski snapshot hash'ini peer password reset üzerine yazmaz. Present disabled status, is_active=1 olsa bile erişime açılmaz.

Invalid reset token, pahalı/new-password hashing başlamadan reddedilir; invalid new-password input token hatasını maskelemez. Register concurrent unique collision structured duplicate-email refusal'a çevrilir; diğer PDO hataları lookup ile maskelenmez. Dörder registration worker'da tek kullanıcı oluşturulur.

Password reset user password, reset token deletion, kurulu 2FA bölümündeki mevcut trusted-device deletion ve mevcut session IDs revocation/deletion'ı aynı işlemde yapar. Partial optional 2FA schema error görünürdür; standalone Auth-only kullanımda eksik 2FA tabloları kurulmaz. Late child SQL failure ve successful caller rollback birlikte geri alır. Dışarıdaki email/cookie/HTTP response, caller commit'inden önce yayınlanmamalıdır; bu paket gerçek Application login/reset entrypoint bağını kabul etmez.

TwoFactorService state row'unu current lock ile okur. beginSetup enabled state'i silemez; inactive setup replacement önceki recovery/trust/login-step kayıtlarıyla birlikte atomiktir. confirmSetup yalnız pending state'te kabul edilir; enable + sekiz recovery INSERT aynı aggregate'tir. Recovery exact hash tek kayıtsa ve unused ise conditional update bir başarı verir; used+unused duplicate legacy hash de reddedilir. Disabled/malformed state recovery/trusted-device bypass açmaz.

TotpEngine matchingStep stateless primitive olarak matching counter döndürür. Login challenge bunu user state lock altında two_factor_login_steps'e kalıcı yazar; aynı/önceki step ikinci login başarısı vermez. Setup confirmation ve ilk login ayrı mevcut sözleşmeler olarak korunur; setup sonrası ilk login aynı geçerli window'da yapılabilir. Pending/disabled kullanıcıda verifyLoginCode'un eski no-challenge sonucu korunur; mandatory admin policy ve authenticated-user/challenge context gerçek giriş katmanında uygulanmalıdır. trustDevice enabled state ister; expired trusted token eşitlikte reddedilir. Bu yöntem tek başına actor authorization/step-up proof yerine geçmez.

Migration26 üç additive tablo kurar: session_lifecycle_lock (fixed id1), session_revocations (ID/time, payload/user metadata saklamaz), two_factor_login_steps (user/counter). Mevcut session kayıtlarına trusted/revoked default backfill yapılmaz. Migration bütün yeni tabloları DDL'den önce preflight eder; explicit replay mevcut revocations/counters'ı sıfırlamaz. down verified maintenance/backup olmadan reddedilir. Önceki25 migration dosyası değişmedi.

DatabaseSessionHandler cooperating read/write/destroy/user invalidation/GC için session mutex'i ve transaction/savepoint kullanır. Revoked ID yazımı reddedilir; read current tombstone/session state'i kullanır. Revocation insert ve session deletion birlikte geri alınır. GC tombstone silmez; UUID/session ID reuse desteklenmez. Bilinen revoked ID, eski çalışan istek tarafından yeni handler/connection ile tekrar yazılamaz. Session payload son yazan kazanır; alan merge yapılmaz.

## Test kanıtı ve değişen beklentiler

Başlangıç kaynaklarıyla seven-case `before` koşusu6 failure/1 error verdi: runtime DDL, enabled 2FA restart/reconfirm, session resurrection, expiry boundary, token replacement loss ve raw caller nested transaction. Kaynak hash/ref başlangıç Git blob'larından bağlanır. Sonraki focused7/28 ve atomic32/105 dar gelişim başarılarıdır. Native-focused41/304 ve native-atomic54/353 kapsam genişletilmeden/nihai full koşudan önce ayrı tutulur.

İlk full main1479/10864 koşusu1 error/4 failure verdi; bu ara geliştirme koşusudur. Golden recovery fixture email unique içermiyordu; canonical identity UNIQUE eklendi ve restore sonrasında duplicate email refusal assertion'ı eklenerek güçlendirildi. Production backup implementation değiştirilmedi. Inactive exception mesajı eski sözleşmesine döndürüldü; migration down explicit-maintenance contract'ına uyarlandı. DailyEvidence failure-resume fixture, migration25 marker'ını26 marker'ı hâlâ mevcutken kaldırdığı için expected remaining count25 ve repair continuation yalnız25 olarak korundu. Diğer whole migration counts/continuations26'ya genişletildi; historical failure points ve eski veri/rollback beklentileri korunur. Trusted-device unit fixture artık enabled 2FA kurar; disabled trust ayrıca reddetme testine sahiptir.

Nihai common45 + native13 ek senaryo; failure/caller matrix, migration replay/partial/no-DDL, duplicate/malformed states, durable revoke/GC, trusted invalidation, counter failure ve dört gerçek worker ile reset/verify/recovery/login/confirm/token replacement/reset-session/registration race; native repeatable-read stale caller ve engine/unique guards. Ana paket mimari, entegrasyon, golden ve shared-host mevcut kontrollerini içerir.

Main-preflight1514/10985 ve native-preflight331+353 =684/5287 ara broad başarılarıdır. Son unique guard/regresyonları ve hashing/registration refinements bu cohort başladıktan sonra eklendi; tek nihai kaynak koşusu diye sunulmaz. Final-focused45/129 ve native-final-focused58/380 son kaynaklarla geçti; ardından bütün final native paketi aynı frozen PHP kaynaklarıyla tekrar çalıştırıldı. Ara configs/discovery/frozen hashes ayrı korunur.

`main.xml/txt` nihai full main, `native-a/b.xml/txt` disjoint full native partitions. `native-expected.xml` tam discovery kimlik kümesiyle eşitlik kontrol edilir; combined XML ayrı koşu değildir. Failures/errors/skips/duplicate/omitted test kabul edilmez. Sayılar `IDENTITY-C01-test-summary.json`; tekrar doğrulama `verify-identity-c01.py`. Ara koşular full final sonucu diye sunulmaz.

## Kalan sınırlar

Known session ID revocation repair, bütün login proof'larının user-wide generation/lease sözleşmesi değildir. Önceden authenticated bir request henüz persisted olmayan **yeni** session ID üretebiliyorsa bunun eski password generation'a bağlı olduğunu mevcut write signature'ından anlamak mümkün değildir. Gerçek Application login/challenge/session-issuance bağında versioned auth proof gerekir. Aynı şekilde yeni trust token issuance için fresh challenge/actor authorization ve cookie flags, rotation/fixation, CSRF, rate limiting, reset enumeration/timing, password policy ve mandatory admin enforcement D03/D04 kabul işleridir.

TOTP secret at-rest encryption, security-event/immutable audit ve retention/privacy/counter/secret rotation kabulü tamamlanmadı. Whole caller rollback challenge tüketimini de geri alır; dış erişim kararı/yanıt commit sonrasına bağlanmalıdır. Direkt SQL/old uncooperative writer, ledger deletion, older backup restore veya unknown schema rewrite invariants'ı bozabilir. Revoked IDs otomatik temizlenmez; ledger/counter retention ve güvenli eski backup restore/reconciliation ayrıca tasarlanmalıdır. Binary/collation/full types/FK/check/index/trigger matrisi açıktır.

Session mutex bütün cooperating session işlemlerini serialize eder; shared-host için basit correctness sınırıdır, yüksek hacim benchmark/distributed throughput kabulü değildir. Uzun caller transaction lock'u commit/rollback'e kadar tutar; global lock order'ın bütün diğer domain writers ve deadlock retries kabulü yoktur. Üretim upgrade/deploy/provider erişimi yapılmadı.

Sıradaki D02 bağlantılı paket organizations/invitations/session switching + impersonation/audit aggregate bütünlüğü; kalan domain/constraint matrisi ve bağımsız kurulum kabulü. D02 IN_PROGRESS, independent_acceptance=PENDING, production_ready=false; D03–D09 açık. P03.1/P03.2 fazları PASS sayılmaz.

Nihai sonuç: tam ana paket **1517/10989**, tam MariaDB **688/5314** (38 sınıf, 304+384 disjoint ve exact discovery); toplam **2205 test / 16303 assertion**. Failure/error/skip0, exit code0.26 migration/93 CREATE TABLE source.
