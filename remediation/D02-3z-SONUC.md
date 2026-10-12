# D02.3b — bakım ve kurtarma modu kalıcılığı

12.10.2026. Başlangıç commit:695fcf76013a723e0cac0cb37c367a89cedd043b. Orijinal P17 kurulum ve operasyonel kalıcılık kapsamı.

Bakım modu servisi gerçek kurulum tablosunda olmayan created_at kolonuna yazıyordu; constructor şema DDL'si caller transaction'ını kapatıyordu. Sonraki ayar yazımı başarısız olduğunda mode/reason/time/IP tuple yarım kalıyordu. Invalid/partial state normal erişime düşebiliyordu. D02-3z-before.txt:1 error/8 failure.

Migration23 ortak ayar şemasını ve ayrı fixed-id mode mutex tablosunu kurar. Fresh settings core-compatible is_encrypted default0 içerir; eski created_at kolonuna bağımlılık kalkar, eski tarihler/diğer ayarlar değiştirilmez. Runtime installed constructor/read DDL yapmaz; missing/partial schema güvenli durur. Dört ayar own transaction veya caller savepoint ile birlikte yazılır. Mod durumu ve izin kontrolü tek snapshot kullanır; paralel mod değişimleri farklı gerekçe/IP ile karışmaz. Unknown/partial/encrypted/duplicate state ve tutarsız cache NORMAL'e açılmaz.

File-backed geçiş outer caller transaction'a katılmaz; bu sınıra before-write hata verir. Dosya ön kontrolü, aynı dizinde temporary staging/rename, yayın failure'ında DB rollback/cleanup; NORMAL cache removal yalnız commit sonrasında yapılır. DB+filesystem distributed transaction değildir. Commit belirsizliği/çökme sonrası cache mismatch explicit reconciliation ister; otomatik geri yazma/retry yoktur. Persisted mode varsa farklı file override reddedilir; boş persisted state için eski file-only override korunur.

Geliştirmede ilk shared-table mutex dört worker'da deadlock verdi (D02-3z-development-lock.txt); separate PK lock table ile düzeltildi. Ortak test yordamı geçici tanılamadan sonra byte-for-byte eski haline getirildi.16 ortak SQLite/MariaDB ve2 native senaryo: kurulum, caller rollback/no-DDL, later write error, legacy data/replay/down refusal, missing/partial/unknown/key uniqueness, file/caller boundaries, publication race rollback/no temp leak, four-worker coherent transitions ve MyISAM refusal.

| Paket | Test | Doğrulama | Sonuç |
|---|---:|---:|---|
| Ana paket | 1319 | 10251 | Başarılı |
| MariaDB10.11.18 | 457 | 3820 | Başarılı |
| Toplam | 1776 | 14071 | Failure/error/skip yok |

Final D02-3z-suite.xml/txt ve D02-3z-mariadb.xml/txt; SHA-256 D02-3z-file-hashes.json.23 migrations/93 DDL source files. Yalnız yerel izole DB ve owned temporary fixture paths kullanıldı.

D02 IN_PROGRESS; bağımsız kabul PENDING; production_ready=false. Dört ana iş grubunun tamamı kapanmadı. Gerçek ürün girişleri/actor/RBAC/super-admin/cron policy, bütün DB constraints/type/engine/prefix matrix, doğrudan SQL ve diğer writers, cross-domain deadlock/retry açıktır. Legacy mode-only şeklin tüm installer/secret workflows uyumu kabul edilmedi; eksik encryption metadata tahminle eklenmez. Offline recovery, FS+DB commit/crash reconciliation ve dağıtık filesystem güvenliği D03/D05/D08 açıktır. Üretim upgrade/deploy yapılmadı. Sırada kalan domain şemaları ve veritabanı kısıt/yazım bütünlüğü var; D03–D09 açık.
