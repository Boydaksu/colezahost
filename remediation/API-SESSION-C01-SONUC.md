# API ve oturum kalıcılığı — API-SESSION-C01

Başlangıç commit'i: `b7f8f493a303ecf9753bd1230d236c61d85c7252`. Kaynak: orijinal master plan P04.7 REST auth/scopes/rate-limit ve P04.8 API logs; oturum altyapısının D02 veri tabanı sınırı. Tam API/auth faz kabulü verilmez.

## Düzeltmeler

`ApiSessionSchema` dört mevcut tabloyu projection, full single-column primary key ve MySQL InnoDB açısından kontrol eder. API key_hash tam unique olmalıdır; session/rate ek unique index başka kimliğin upsert ile üzerine yazılmasına yol açabileceği için reddedilir. Partial tablolar automatic ALTER ile tahmin edilmez. Kurulu şemada CREATE çalıştırılmaz; açık raw/managed caller commit edilmez. Managed depth ile PDO durumu uyuşmazsa sorgu başlamaz. Açık iş caller'ında eksik şema iki sürücüde de reddedilir. Explicit SQLite migration setup transaction desteği ve standalone ilk kullanım kurulumu korunur. Fresh MySQL setup InnoDB ister; mevcut MyISAM otomatik dönüştürülmez.

RateLimiter SELECT-increment/DELETE-INSERT yarışını tek upsert ve kendi transaction'ındaki sonuç okumasıyla kaldırır. Upsert write lock sonuç okuması/commit bitene kadar tutulur. Hit önce, reset_at sonra güncellenerek MySQL'in assignment sırası eski pencereyi doğru değerlendirir. Rejected attempts eski politika gibi sayılır; exception commit sonrasında çıkar. Hit INT kapasitesinde saturate edilir, max budget bu değerin altında olmak zorundadır. Invalid key/budget/window before-write durur. Kota rollback ile tekrar kullanılamasın diye admission açık caller'dan önce yapılmalıdır; caller içinde hit sessiz commit yerine before-write hata verir. Shared connection'daki caller içine bağımsız transaction taklidi yapılmaz.

DatabaseSessionHandler SELECT-INSERT/UPDATE yerine tek upsert kullanır. Payload ve metadata aynı statement'ta yazılır; null metadata önceki değerleri korur. JSON_THROW_ON_ERROR invalid UTF-8/recursive payload'ı yazımdan önce durdurur. Expiration DELETE predicate'i last_activity cutoff'u lock alındığı anda kontrol eder; eski read'in ardından unconditional destroy yapılmaz. Read predicate stale expired snapshot'ı döndürmez; caller snapshot'ının en yeni payload'ı göreceği iddia edilmez. Session ID/lifetime validation eklendi.

API scopes create/auth yollarında list/string olarak doğrulanır. Malformed JSON, JSON object/scalar, boolean/boş scope sessiz default yetkiye dönüşmez. Required-scope kontrolleri korunur; expires_at eşitliğinde anahtar expired sayılır. Başarısız doğrulama last_used_at yazmaz. ApiRequestLogger aynı installed-schema guard'ını kullanır; başarılı request log/anahtar/session yazımları caller rollback'ine katılır.

## Kanıt ayrımı

`before` testi başlangıç kaynaklarıyla 10 testte8 failure verdi: dört tekrar DDL, invalid session JSON, malformed persisted scopes, invalid rate policy ve rollback edilebilir admission. Baseline source hash kayıtları Git başlangıç commit'inden alınır.

`focused` ilk dar SQLite10 test başarı kanıtıdır. `native-focused` geliştirme koşusu25 testte3 failure/1 warning verdi: worker, ValidationException yakalarken testin beklediği class alanını basmıyordu. Yalnız yeni admission action'ında bu tanılama eklendi; concurrency budget beklentisi gevşetilmedi. Bu bir başlangıç kaynak hatası kanıtı değildir. `native-affected`111/684 ara başarıdır. `main-initial`, `main-development`, `main-preflight` ve `native-final` eklenen guards/regresyonlardan önceki ara koşulardır; nihai kaynak başarıları diye sayılmaz.

Nihai ana paket `main.xml/txt`; nihai gerçek MariaDB paketi `native-acceptance.xml/txt`. Native kapsam yedi sınıftır: ApiSessionPersistenceTest, DatabaseFoundationTest, ModuleSchemaInstallTest, LegacyIdentitySchemaTest, RuntimeSchemaSafetyTest, ModuleSchemaSafetyTest, InstallationAcceptanceTest. Exact testcase kimlikleri `native-acceptance-expected.xml` ile karşılaştırılır; atlanan/ekstra/duplicate test ve failure/error/skip kabul edilmez. Bu adımda tüm MariaDB37 sınıfı yeniden koşulmadı; SUPPORT-C01'in önceki593 full sonucu current-source full başarı diye sunulmaz.

Nihai sayılar `API-SESSION-C01-test-summary.json` ve `state.json` içinde; `verify-api-session-c01.py` sonuçları ve dosya hash'lerini tekrar doğrular. 25 migration ve91 CREATE TABLE kaynak dosyası korunur; migration dosyalarında değişiklik yoktur.

## Kalan sınırlar ve sıra

Bu paket mevcut service persistence davranışlarını onarır; gerçek HTTP middleware admission-before-transaction bağını, rate key seçimi/client-IP trust/429 headers/distributed quotas veya endpoint idempotency'yi kabul etmiş sayılmaz. Session whole-payload son yazan kazanır; bağımsız alanları merge etmez. Logout/password-reset sonrası eski çalışan isteğin session'ı yeniden yazabilmesi, token consumption/revocation, API key revoke/auth yarışları, session rotation/fixation ve authorization Auth/2FA yaşam döngüsü paketinde incelenmelidir. Case-insensitive MySQL collation kimlik politikası değiştirilmedi. Genel veri tipi/collation/FK/check/index/trigger/invariant ve tüm domain matrisi ayrıca açıktır.

Sıradaki bağlantılı paket: Auth/2FA/token/session invalidation yaşam döngüsü; ardından kalan D02 domain/constraint matrisi ve bağımsız kurulum kabulü. D02 IN_PROGRESS; independent_acceptance=PENDING; production_ready=false. D03–D09 açık. Gerçek üretim/provider/deploy işlemi yapılmadı.

Nihai sonuç: tam ana paket **1472 test / 10856 assertion**, etkilenen yedi MariaDB sınıfı **123 test / 722 assertion**; toplam **1595 test / 11578 assertion**, failure/error/skip0. Exit code0. Native tam paket tekrarı yapılmadı.
