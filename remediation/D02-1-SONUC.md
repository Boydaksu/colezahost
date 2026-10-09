# D02.1 — Kurulum, RBAC şeması ve atomik numaralar

Tarih: 09.10.2026. Adım durumu: IMPLEMENTATION_COMPLETE; bağımsız kabul bekliyor. D02 fazı devam ediyor.

## Uygulanan düzeltmeler

- Installer ve RBAC ortak `RbacSchema` üzerinden tablo oluşturuyor. Scope/description, izin eşlemeleri ve organizasyon kapsamı aynı sözleşmede. `super_admin` adı eski kurulumlarla uyumluluk için korunuyor; gerçek wildcard izin eşlemesi artık oluşturuluyor.
- Global rol kapsamı NULL yerine 0 ile saklanıyor. MariaDB primary key kısıtı global rol atamasını engellemiyor; aynı kullanıcı/rol/kapsam ikinci kez atanamıyor. Gerçek organizasyon ID'leri pozitif olmak zorunda.
- İlk üretim migration dosyası eklendi: `database/migrations/2026_10_09_000001_normalize_identity_rbac.php`. Eski installer ve NULL kapsamlı RBAC şemaları dönüştürülebiliyor. Rol ID'leri, adları, metadata, izinler, organizasyon kapsamları ve atama tarihleri korunuyor. Eşdeğer global rol tekrarları birleştiriliyor. Geçersiz izin JSON'u sessizce atlanmıyor.
- Migrator, MariaDB DDL'nin implicit commit davranışına uyarlandı. Başarı kaydı migration bittikten sonra yazılıyor. Migration/rollback işlemleri veri tabanına bağlı advisory lock ile sıralanıyor. Uygulama transaction'ı içinden migration başlatılması reddediliyor. SQLite migration'ları transaction içinde çalışmaya devam ediyor.
- Sıra üretimi Connection içindeki sürücüye uygun atomik işleme taşındı. Fatura, ödeme, iade, hizmet, kredi, finans hareketi, gider ve settlement numaraları bu yöntemi kullanıyor. MariaDB aynı bağlantıya özgü LAST_INSERT_ID ile kendi ayırdığı değeri alıyor; başka bağlantının artırdığı değeri okumuyor. SQLite okuma/yazma transaction içinde kalıyor.
- Tablo önekli kurulum ve yönetici izinleri aynı RBAC sözleşmesiyle doğrulandı.

## Gerçek test kanıtı

**MariaDB 10.11.18**, yalnız 127.0.0.1:33079 üzerinde ve ayrı `remediation/runtime/mariadb-d02` dizininde çalıştı. ServBay'in mevcut veri tabanına dokunulmadı. Her test rastgele isimli kendi test veri tabanını oluşturdu ve kapattı; iş sonunda test sunucusu düzgün kapatıldı. Runtime verisi sürüm kontrolünden hariç.

İlk dört MariaDB testi düzeltme öncesinde 3 hata ve 1 başarısızlık verdi: SQLite SQL sözdizimi, eksik organization_id, global rolde NULL yasağı ve üretim migration eksikliği. Kanıt: `D02-1-before.txt/xml`.

| Paket | Test | Assertion | Sonuç |
|---|---:|---:|---|
| Mevcut paket + SQLite geçiş regresyonları | 922 | 7.986 | Başarılı |
| Gerçek MariaDB entegrasyonu | 16 | 101 | Başarılı |
| Toplam | 938 | 8.087 | Hata/başarısızlık/atlanan test yok |

MariaDB paketi sekiz sıra üreticisini, fresh installer → admin → RBAC akışını, organizasyon ayrımını, eski izin/tarih korumasını, tablo önekini, migration'ın tekrar çalışmasını ve yarıda kalan DDL'nin başarı sayılmadan devam edebilmesini kapsıyor. Dört ayrı PHP işlemi bariyerden aynı anda bırakıldı; **256 fatura numarasının 256'sı benzersiz**, aralık 000001–000256. Gerçek ayrı veri tabanı bağlantıları kullanıldı.

Ham kanıt: `D02-1-mariadb.txt/xml`, `D02-1-suite.txt/xml`. `git diff --check` sorun göstermedi. Windows/ServBay için tekrar çalıştırma araçları: `start-d02-db.ps1`, `run-d02-tests.ps1`, `stop-d02-db.ps1`. MariaDB paketi DSN verilmediğinde hata verir; SQLite'e düşmez ve testleri atlamaz.

## Açık işler

D02 tamamlanmadı. F02'nin sıra üretimi bölümü giderildi; bildirim tercihleri/WHMCS sorguları, diğer SQLite DDL'leri ve kapasite hesabı D02.3'te ele alınacak. İlk rapordaki bazı ON CONFLICT eşleşmelerinin zaten SQLite/MariaDB dalları olduğu doğrulandı; salt metin eşleşmesi hata sayılmıyor.

F03'ün kurulum/RBAC çelişkisi ve identity migration'ı ele alındı. Tüm domain tabloları için sürümlü migration/kurulum kabulü açık. Identity migration dosyası öneksiz varsayılan şemaya yöneliktir; önekli geçiş aynı RbacSchema API'sinin prefix parametresiyle çalışır. Migration yıkıcı şekilde geri alınmaz; down açık hata verir ve doğrulanmış yedekten geri dönüş ister. MariaDB DDL kısmen uygulanabilir; diğer migration'ların tekrar çalışabilir olması ve yedek/geri dönüş kabulü ayrıca doğrulanacak.

F16 yalnız sıra çakışması açısından ele alındı. Ödeme/tahsis/refund/credit transaction'ları, kuyruk rezervasyonu, kapasite kilidi ve webhook benzersizliği açık. F06 token sütunu/indeksi henüz eklenmedi.

**Sıradaki adım D02.2:** token verisi ve benzersizlik migration'ı; ödeme/tahsis/bakiye ve kuyruk rezervasyonunda atomiklik; aynı kayda paralel istek ve hata sırasında geri dönüş testleri. Üretime hazır veya tüm bulgular kapandı sonucu çıkarılmaz.
