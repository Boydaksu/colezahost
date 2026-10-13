# SUPPORT-C01 — destek veri işlemleri

Referans9271d10d8e14f820b71e81cc51f6a40f02366530. Orijinal master plan P13.1/P13.2/P13.4 ve schema bağımlılıkları; aktif düzeltme fazı D02. Birbiriyle ilişkili veri hataları tek pakette ele alındı. Bu yerel veri sözleşmesi doğrulaması P13 phase PASS veya gerçek hizmet masası kabulü değildir.

## Düzeltilen davranış

Sekiz servisin12 mevcut tablosu için tam kolon gereksinimleri tanımlandı: departments/agents, tickets/messages, SLA policies/ticket SLA, assignment log/rotation state, canned responses, announcements, attachment metadata, context command log. Kurulu schema ensureTables/installSchema çağrısında yeniden CREATE/ALTER çalıştırmaz. Başlangıçtaki gereksiz CREATE MySQL caller işlemini implicit commit edebiliyor; attachment/context kurucuları ise kurulu schema'da dahi caller transaction'ını reddediyordu. Yeni SupportPersistence preflight bu iki davranışı kaldırır. MariaDB'de tablo engine'i InnoDB olmak zorundadır; sessiz engine dönüşümü yapılmaz. Yeni kurulum DDL'si InnoDB'yi açıkça seçer.

Mevcut migration9 ve diğer24 migration dosyaları değiştirilmedi. Schema biçimi/type/index değişmediği için yeni migration yok; toplam25 korunur. Explicit ensureTables/installSchema API'lerinin migration9 içindeki SQLite transactional kurulum yolu korunur. Bu API'ler business first-use değildir; business yolları requireSchema üzerinden eksik/yarım kurulumu before-write reddeder. Bilinen eski support_tickets invoice_id/order_id nullable INT kolonlarının eklenmesi özgün kurulum yoluyla sürer; diğer eksik kolonlar tahmin edilmez. Installed replay konuşma, ticket id/tarih ve içeriği değiştirmez. Tam FK/index/type/legacy constraint uyumu bu kolon/engine kontrolünün kabul kapsamı değildir.

Dört atama stratejisi (manual, unassign, round-robin, least-loaded) talep/personel/sıra/geçmiş değişikliklerini aynı transaction veya savepoint içinde yapar. Log INSERT hatasında ticket ve rotation state geri döner. Caller'ın raw PDO ya da Connection-managed işlemi commit edilmez; önceki işi korunur. Round-robin departman satırını ortak mutex olarak kilitler; department→ticket sırası kullanılır, eligibility ve rotation current-read ile seçilir. Least-loaded eski repeatable-read COUNT yerine kilitli güncel atama satırlarını sayar. Dört worker iki agent'a2+2 dağılım yapar. Aynı manuel atamanın eşzamanlı tekrarı tek log'u geri döndürür. Unknown strategy artık sessiz round-robin'e çevrilmez; inactive department reddedilir. Stale caller department ile güncel ticket department farklıysa yazmadan caller refresh ister.

Ticket create+ilk mesaj, public reply ve internal note mevcut transaction sınırlarını SupportPersistence savepoint helper ile kullanır. Raw caller'a katılabilir. İlk mesaj INSERT hatasında yeni ticket kalmaz; ticket UPDATE hatasında eklenen reply geri alınır. Managed/raw caller marker'ları bozulmaz. Bu ticket statü geçmişinin veya actor/organization authorization zincirinin genel kabulü değildir.

Command log ve attachment metadata schema'sı işleyici/depolama çağrısından önce kontrol edilir. Eksik tabloyla provider handler veya file storage başlatılmaz. Bu check dış etki sonrası audit INSERT/crash/response-loss veya caller rollback ile storage+DB distributed atomiklik garantisi değildir; durable command journal ve file compensation/recovery ayrıca gereklidir. Gerçek context handler/provider ve storage erişim zinciri bu pakette işletilmedi.

## Regresyonlar ve sonuçlar

Başlangıç13 test:12 failure +1 error. Sekiz installed schema DDL/caller senaryosu, dört assignment log failure ve raw caller ticket create. Default tüm test keşfi çıktı üretmediği için yalnız o işe ait süreç source değişmeden durduruldu; before.txt bunu belirtir. before-direct.txt/before.xml gerçek dosya bazlı başlangıç kanıtıdır. İki ek preflight karşı örnek ara kod üzerinde2 failure verdi: handler ve file put schema hatasından önce çağrılıyordu; development-source-check.txt başlangıç commit full-baseline sonucu değildir.

İlk destek paketi80/551 geçti. İlave caller/fault/legacy/preflight testleriyle son destek90/584 geçti. İlk native26/181 geçti; son eklenen iki fault ve iki stale-caller native senaryosu bu koşunun kapsamı değildir. İlkmain1441/10788 geçti; sonmain1443/10795, failure/error/skip0. Native final36 sınıf/593 test tam keşifle disjoint iki bölüme ayrıldı; nihai sonuç ve test kimliği eşitliği doğrulandı. Önceki test beklentileri gevşetilmedi. Synthetic local service/SQL fixtures gerçek UI/provider/SLA cron E2E olarak sunulmaz.

## Kalan modül kabulü

- Application/HTTP/CLI/cron actor/RBAC/organization bağları ve gerçek destek ekranları.
- Gerçek context command sonucu, dış etki/audit crash journal ve idempotent recovery.
- Private attachment gerçek depolama/download yetkileri; file+DB cleanup/rollback/recovery.
- SLA politika/default yarışları, pause/resume/breach/time semantics ve gerçek cron/history akışı.
- Ticket departman transferi/üyelik/diğer writer'ların bütün kilit ve immutable audit sözleşmesi; key/type/FK/index/legacy shape matrisi.
- P12 bağımlılık PASS'i ve bağımsız reviewer/gatekeeper. D02 IN_PROGRESS, production_ready false, D03–D09 açık.

## Kanıtı tekrar çalıştırma

Yerel DB start-d02-db.ps1 ile başlatılır; izole MariaDB env DSN/user/password tanımlanır. Main `php -c <php.ini> vendor/phpunit/phpunit/phpunit --log-junit remediation/SUPPORT-C01-main.xml`. Native iki bağımsız process: `-c remediation/SUPPORT-C01-native-a-config.xml` ve `-c remediation/SUPPORT-C01-native-b-config.xml`. Tam discovery `-c phpunit-mariadb.xml --list-tests-xml remediation/SUPPORT-C01-native-expected.xml`. Her native test random isimli kendi DB'sini kullanır. Strict/output/warning/risky ayarları korunur. `python remediation/verify-support-c01.py` hash/state/full test identity proof'u kontrol eder. Ham başlangıç, ara ve final sonuçlar ayrı korunur. Metin/XML/JSON UTF-8 LF olarak normalize edildikten sonra hash alınır; Git commit byte'larıyla ayrıca doğrulanır. Yalnız projeye ait DB stop-d02-db.ps1 ile kapatılır.

## Final sonuç

Tam ana1443/10795 + tam native593/4737 = toplam2036/15532; failure/error/skip0. Native291+302, bütün36 sınıf ve tam593 discovery id kümesi tam olarak bir kez kapsanır. Raw native process exit0. Kod final koşular başladıktan sonra değiştirilmedi.25 migration/91 CREATE TABLE source; SupportPersistence ayrıca mevcut nullable link ALTER ve savepoint helper'ı olarak envanterde gösterilir. D02 IN_PROGRESS, independent_acceptance PENDING, production_ready false. Paket kaynak/kanıtları bu commit ile GitHub main'e gönderilir.
