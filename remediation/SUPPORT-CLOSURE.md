# Destek veri işlemleri toplu paketi — SUPPORT-C01

Başlangıç9271d10d8e14f820b71e81cc51f6a40f02366530. D02 kurulum/kalıcılık, orijinal P13.1/P13.2/P13.4 ve P13.3/P13.6 schema bağımlılıkları. Yeni ürün özellikleri veya P13 phase PASS'i değildir.

- [x] Sekiz destek servisinin12 tablosu kurulu şemada DDL çalıştırmaz; MariaDB caller commit edilmez; InnoDB engine ve tam kolon preflight vardır.
- [x] Eski ticket invoice/order nullable kolon geçişi, migration9 SQLite transactional bootstrap ve veri/tarih/konuşma koruması sürer; bilinmeyen partial schema before-DDL reddedilir.
- [x] Manual/unassign/round-robin/least-loaded ticket+rotation+log tek işlemde commit/rollback olur; raw/managed caller'ın önceki işi korunur.
- [x] Department mutex + current reads ile dört worker dağılımı, manuel replay ve eski caller snapshot'ında güncel previous-agent/rotation/load doğrulanır.
- [x] Ticket+ilk mesaj, public reply ve internal note raw/managed caller'a katılır; mesaj veya ticket update fault'unda yarım kayıt bırakmaz.
- [x] Eksik/yarım command log veya attachment metadata şeması komut işleyicisi/depolama etkisinden önce durdurulur.
- [x] Mevcut test beklentileri korunarak tam ana ve native paketler geçer; kanıt/keşif eşitliği/hash/GitHub gönderimi tamamlanır.

Durum IMPLEMENTED_LOCAL_FULL_MAIN_AND_NATIVE_TESTS_PASS. Yedi yerel paket ölçütü doğrulandı; GitHub gönderimi bu kanıt commit'iyle yapılır. Genel P13/D02 bağımsız kabulü PENDING.

P13 genel kabulü: gerçek UI/Application/actor/organization yetki bağları, gerçek context command/provider sonucu, dış depolama+DB crash/rollback/recovery/cleanup ve private dosya erişimi, SLA politika/history/cron geçişleri, tam legacy type/FK/index/constraint matrisi, P12 bağımlılığı ve bağımsız review/gatekeeper ayrıca açık. Assignment kullanan cooperating writer'ın departman kilidi dışındaki bypass SQL/diğer membership writer kapsamı genel immutable audit/authorization kabulü değildir. D02 ve D03–D09 kapanmaz.
