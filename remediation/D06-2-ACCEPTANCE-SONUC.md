# D06.2 — ayrı süreç, kesinti ve gerçek HTTPS kabulü

16.10.2026. D06-2-KABUL.md içindeki dördüncü ve son sabit kontrol **IMPLEMENTATION_COMPLETE_LOCAL_VERIFIED**. **D06.2 4/4, yerel uygulama kapandı.** D06 fazı sürer; sonraki D06.3 otomasyon komut bağları/recovery. HTTP/cron/worker composition D08; bağımsız host ve gerçek harici sağlayıcı hesabı kabulü D09'da açık kalır. production_ready=false. Bu teslim yeni kontrol/faz eklemez; önceki D02–D05/D06.1 kapanışları korunur.

## Bulunan yarış ve düzeltme

Eski 578f1ca sürümünde durable processing kaydı duplicate create'i engelliyordu; ancak canlı ilk worker hâlâ sağlayıcı cevabını beklerken başka reconciler aynı kaydı tamamlayabiliyordu. Sonra ilk worker stale operation ile completion/hold yapmaya çalışıyordu. İki yeni regresyon (live create ve live suspend) önce **2 failure / 0 error** üretti. İlgili workflow/lifecycle/reconciler/operation-service eski byte hash'leri baseline'da korunur; diğer yeni protokol testleri eski sürümde çalıştırılmış gibi gösterilmez.

HostingExecutionLease, hizmet bazında create ve bütün lifecycle/reconcile çağrılarını aynı süreç/session mutex'inde toplar. MySQL/MariaDB aynı primary DB endpoint'inde DATABASE()+service ID ile adlandırılmış GET_LOCK(name,0) kullanır; lock session boyunca yaşar, transaction commit'inden bağımsızdır. SQLite file DB aynı gerçek DB dosyasına komşu kalıcı sidecar üzerinde nonblocking flock kullanır; in-memory DB için aynı PDO reentrant koruma vardır. Sidecar kapatılır ama çalışan başka sürecin kilidini bölmemek için silinmez. SQLite kabulü aynı host/local filesystem içindir; paylaşılan/ağ dosya sistemi kabulü diye sunulmaz.

Canlı worker varken ikinci komut veya recovery busy/unknown döner; kurtarma network probe'u ve local transition yapmaz. Reentrant PHP çağrısında MySQL GET_LOCK'ın recursive acquisition davranışı ayrıca engellenir. MySQL non-persistent PDO + autocommit gerekir; persistent session pooling'de fatal request sonrası lock taşınmasını varsayarak devam edilmez. Unsupported/busy/lost lock başarı sayılmaz. Remote POST öncesinde ve local completion içinde mevcut session/selected database/IS_USED_LOCK owner tekrar doğrulanır. Network boyunca DB transaction açık tutulmaz. finally lock'ı bırakır; worker öldürülünce process/file handle veya DB connection'ın kapanması lock'ı bırakır. Veritabanı named lock'ın session ve transaction davranışı için [MariaDB GET_LOCK](https://mariadb.com/docs/server/reference/sql-functions/secondary-functions/miscellaneous-functions/get_lock), dosya kilidi için [PHP flock](https://www.php.net/manual/en/function.flock.php).

findActiveOperationForService ve listOperationsForService transaction içindeyken current locking read kullanır. Başka connection operation ekledikten sonra eski repeatable-read snapshot'ının boş listesinden yeni claim kararı verilemez; bu durum gerçek MariaDB testiyle doğrulandı. Mevcut 26 canonical migration veya tablo yapısı değiştirilmedi.

## Gerçek protokol ve ayrı süreç kanıtı

Yeni stateful WHM fixture ayrı Python sürecinde gerçek loopback HTTPS açar. Sertifika/hostname doğrulaması açık CpanelCurlTransport gerçek form POST gönderir; fixture aynı credential/API version'ı kontrol eder, account identity/state'i POST'tan üretir ve request journal yazar. Journal password/token değerlerini kaydetmez. Başarılı paid order→create→suspend→unsuspend→terminate akışı gerçek Application admission, encrypted operations, kapasite, placement ve audit ile çalışır; memory transport başarı kanıtı değildir. Bu bir yerel contract fixture'ıdır, canlı cPanel hesabı değildir.

23 ortak vaka hem SQLite file DB'de hem MariaDB10.11'de:

- Tek TLS paid-order lifecycle; her mutasyon tam bir POST, doğru state ve son silmede kapasite release.
- Remote effect sonrasında drop, bounded timeout, HTTP503+success body, malformed JSON ve negatif WHM response; allocation/claim tutulur, ikinci create engellenir, fresh matching probe ikinci POST olmadan tamamlar.
- HTTP/malformed/yanlış domain/ambiguous verification active yapmaz; doğru probe ile recovery yapılır.
- Suspend/unsuspend/terminate kayıp yanıtı; eski local state korunur, farklı güncel yetkili actor salt okunur recovery yapar.
- Dört ayrı PHP süreci aynı create için aynı başlangıç bariyerinde yarışır: tek durable operation ve tek remote POST. Dört ayrı süreç suspend/terminate arasında yarışır: yalnız tek lifecycle mutasyonu; create de araya giremez.
- Canlı worker remote response bariyerindeyken ayrı recovery süreci network'e gitmeden reddedilir; operation processing kalır.
- Worker claim'den önce öldürülürse op/allocation oluşmaz; sonraki normal çağrı tek create gönderir.
- Worker kalıcı claim'den sonra fakat POST'tan önce öldürülürse allocation korunur; olmayan hesabın accountsummary sonucu otomatik replay/release izni sayılmaz. Yerel durum pending/processing ve manuel inceleme gerektirir.
- Worker create, suspend, unsuspend ve terminate remote effect'inden sonra OS process handle üzerinden öldürülür; server bariyeri serbest bırakılır; fresh PHP process doğru remote postcondition'ı okuyarak tamamlar, ikinci mutation göndermez.
- Gerçek HTTPS request beklerken actor pasifleştirilirse local activation yapılmaz; güncel başka staff aynı business receipt ile recover eder.

Bariyerler uyku süresine bakarak yarış varsaymaz: worker-ready/go, remote paused/release ve loser-done kayıtları kullanılır. Test kill'i yalnız fixture'ın oluşturduğu proc_open handle'ına uygulanır; canlı ürün/harici hesap süreci hedeflenmez. Zaman aşımı vakası 1 saniye client budget/2 saniye server gecikmesidir; diğer süreç bariyerleri 10 saniye client budget kullanır. Fixture ve çocuk süreçler her vakada kapatılır; native storage izole colezahost_d02_test_* veritabanıdır.

Ek iki reentrant ana test ve dört MariaDB-specific test: lease POST öncesi kaybolursa mutasyon yok; POST sonrası kaybolursa activation yok; eski snapshot current op'u gizleyemez; persistent DB session reddedilir. Bunlar ortak 23 vakanın farklı isimlerle çoğaltılması değildir. Test helper'ları önceki 42 command case'inden ayrıldı; mevcut testler silinmedi/atlatılmadı.

## Kurtarma sınırı ve uygulama sırası

Dış sağlayıcı command idempotency/fencing token vermediğinden genel dağıtık exactly-once garantisi iddia edilmez. Mutex aynı DB primary'sini kullanan bu komut yolları içindir; farklı primary/cluster node, manuel SQL ile lock bypass veya bağımsız WHM aktörü için garanti değildir. Canlı worker varken zorla unlock yapılmamalı. Session kopması sonrası uzak komutun sürüp sürmediği belirsizse worker ve uzak request'in durulduğu kontrollü bakım/uzlaştırma gerekir; yalnız timeout süresi doldu diye claim silinmez.

Operatör recovery sırası: (1) Aynı hizmetin aktif worker'ı varsa bekle; kill gerekiyorsa yalnız o worker'ın gerçekten durduğunu ve uzak request'in durulduğunu doğrula. (2) Güncel yetkili authenticated actor ile HostingApplicationService.reconcile(operation UUID, actor) çağır. (3) Matching postcondition ve değişmemiş ticari/hedef binding varsa yerel transaction tamamlar. (4) Unknown/changed binding ise kapasite ve claim korunur; pending kaydı reset edip yeni create gönderme. Yalnız response/body hatası veya accountsummary null hesabın yokluğunu kanıtlamaz. Bu teslim kontrollü crash fixture'larında remote mutation'ın bariyerden önce uygulandığını ayrıca bilir; bunu harici sağlayıcı operasyonunun tarihsel kanıtı diye sunmaz.

## Final doğrulama

- Tam ana paket **2307 test / 14054 assertion**, exit0; failure/error/skip/warning yok.
- Gerçek MariaDB **136 test / 1054 assertion**, yedi affected sınıf; discovery ve çalışan case ID'leri aynı; exit0, hata/atlama yok. Bütün native suite yeniden koşulmuş değildir.
- 23 gerçek TLS/süreç vakası her iki DB'de, iki reentrant ana ve dört MariaDB-specific ownership vakası. Önceki cPanel/backup/update gerçek protokol testleri tam ana pakette korunur.
- Final testten önce70 seçili source/test/workflow girdisi sabitlendi; canonical26 byte aynı; plan39/258, locked97 ve whitespace kontrolü geçti. Kanıt d06-acceptance-baseline/before/main/native/discovery/checks/summary/hashes. `python -X utf8 remediation/verify-d06-acceptance.py` bu kapanış byte sürümünü denetler; önceki verifier'lar tarihsel checkpoint'ler içindir.
- Provider CI Linux/Windows gerçek HostingProtocolTest'i; native job Python/CA/cURL bağımlılıklarıyla HostingProcessTest ve HostingExecutionTest'i de çalıştırır. Önceki backup/update/integrity workflow'ları korunur. GitHub sonucu push edilen commit üzerinde ayrıca doğrulanır.

D06.2'nin dört sabit kabulü kapandı. Kalan D06 adımları **D06.3 otomasyon**, **D06.4 SMTP/iyzico/registrar**, **D06.5 Doctor ve faz kabul matrisi**. Üretime deployment ve harici sağlayıcı mutasyonu yapılmadı.
