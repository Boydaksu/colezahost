# D02.3b — risk kayıtlarının kurulum ve işlem güvenliği

12.10.2026. Başlangıç commit:7917878fc9e300347030707cfa327847b9c5c06a. Orijinal master plan P14.1–P14.3 esas alındı.

Risk değerlendirmeleri/sinyalleri, manuel inceleme/kararları, izleme listeleri ve olay sıklığı kayıtlarının altı tablosu production kurulumunda eksikti. Dört servisin tekrar tablo oluşturması MariaDB'de çağıranın işlemini kapatıyordu. Değişiklik öncesi kurulum failure ve sekiz raw/managed transaction failure, D02-3x-install-before.txt ve D02-3x-transaction-before.txt içinde kaydedildi.

Migration22 altı tabloyu ve sekiz indeksi kurar. Bütün mevcut kolonlar herhangi bir CREATE öncesinde kontrol edilir; yarım şema açık düzeltme ister. Kurulu uygulama yolları şema DDL çalıştırmaz; risk kayıtları ve ilgisiz caller verisi birlikte geri alınabilir. Fresh MariaDB tabloları InnoDB'dir. Eksik indeksler explicit yükseltmede ayrı ayrı tamamlanır; oluşturma hataları yutulmaz. Eski kayıtlar, Unicode metadata/reasons, karar checksum ve tarihler migration tekrarında değiştirilmez. Down, yedek/retention ile açık bakım planı ister.

23 ortak SQLite/MariaDB senaryosu ve1 MariaDB engine testi eklendi. Dört servis x iki caller transaction türü; dört missing guard; altı partial table preflight; production kurulum, replay, sekiz indeks, failed history/fixture-only açık arşiv düzeltmesinden sonra devam, down refusal ölçüldü. Eski veri koruma kontrolleri korundu; migration adedi22 ve repair continuation listeleri güncellendi.

| Paket | Test | Doğrulama | Sonuç |
|---|---:|---:|---|
| Ana paket | 1288 | 10069 | Başarılı |
| MariaDB10.11.18 | 418 | 3461 | Başarılı |
| Toplam | 1706 | 13530 | Failure/error/skip yok |

Ana paket ilk final çalışmasında shared-host kuyruk testi, 64 MB'a ulaşan PHPUnit parent belleği nedeniyle sıfır iş işledi (D02-3x-main-memory-before.txt). Test tek başına 20 MB ile geçti. RunInSeparateProcess/PreserveGlobalState(false) ile test süreç belleğinden ayrıldı; 64 MB sınırı, beş iş/empty queue kontrolleri ve WorkerSupervisor üretim davranışı değiştirilmedi. Mevcut 1 MB exhaustion testi korunur. Tüm ana paket tekrar geçti. MariaDB final paketi de geçti; değişen shared-host test dosyası MariaDB paketine dahil olmadığı için MariaDB tekrarı gerekmedi.

Final tam paketler D02-3x-suite.xml/txt ve D02-3x-mariadb.xml/txt; dosya SHA-256 manifesti D02-3x-file-hashes.json.22 migration/93 CREATE TABLE kaynak dosyası. Yalnız yerel izole veritabanı kullanıldı.

D02 IN_PROGRESS; bağımsız kabul PENDING; production_ready=false. Bu adım tüm Fraud iş akışını tamamlamaz. Caller olmayan evaluation+signals/manual status+decision failure atomicity; review create/claim/resolve yarışları, tek immutable karar, actor/tenant/RBAC/audit ve snapshot güvenlik politikası; risk/JSON/input/privacy doğrulamaları; retention/restore ve tam eski constraints/engine/prefix matrisi açıktır. Eski MyISAM otomatik değiştirilmez; mevcut aynı adlı indeksin kolon/type uyumu kabul edilmiş sayılmaz. Test fixture arşivlemesi üretim veri dönüşüm çözümü değildir. Production migration/deploy yapılmadı. Sırada kalan domain şemaları ve çoklu yazım/veritabanı kısıt bütünlüğü var; D03–D09 açık.
