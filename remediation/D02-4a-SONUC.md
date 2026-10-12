# D02 — modül envanteri ve güncelleme kontrolü

12.10.2026. Başlangıç commit f4727f0a768faa47d384f48b33428192c4a3cbd9. Master plan P17.2/P17.3'ün veri tabanı sınırı.

Uyumluluk kontrolü yalnız okuma yerine CREATE TABLE çalıştırıyordu; MariaDB caller transaction'ını commit ediyordu. Modül açma/kapama/kurma da aynı sorunu taşıyordu. Eksik tablo boş ve uyumlu envanter kabul ediliyordu. Değişiklik öncesi dört native regresyon testi başarısız (D02-4a-before.txt).

Checker artık ortak canonical schema projection'ı ile yalnız SELECT çalıştırır. Eksik/yarım şema explicit installation/upgrade ister; gizli tablo kurmaz. Lifecycle installed schema no-DDL; raw/managed caller rollback kayıtları geri alır. Missing schema caller içinde before-DDL durur. Mevcut migration6 explicit installation yolunu kullanır; yeni migration/ALTER/veri dönüşümü yok. Fresh MySQL InnoDB; MyISAM writes veri değiştirmeden reddedilir. Lost managed caller read/write reddedilir. Eski modül kimliği/manifest/Unicode/disabled durumu ve tarihler korunur. Update/backup/rehearsal test fixtures kurulumu açıkça yapar.

9 ortak SQLite/MariaDB ve2 native yeni senaryo; canonical duplicate module identity veritabanında reddedilir. Final doğrulama:

| Paket | Test | Doğrulama | Sonuç |
|---|---:|---:|---|
| Ana paketin tamamı | 1328 | 10288 | Başarılı |
| Etkilenen MariaDB paketi | 50 | 372 | Başarılı |
| Bu adımda toplam | 1378 | 10660 | Hata/atlanan test yok |

MariaDB kapsamı: ModuleSchemaSafetyTest, DatabaseFoundationTest, InstallationAcceptanceTest, RuntimeSchemaSafetyTest. Bütün MariaDB paketi bu adımda yeniden çalıştırılmadı; önceki D02-3z457/3820 sonucu önceki source'a aittir. Final XML/txt ve D02-4a-file-hashes.json;23 migration/92 DDL kaynak dosyası (checker'ın kopya DDL'si kaldırıldı). Üretim verisine veya sağlayıcıya dokunulmadı.

D02 ve dört ana iş grubu tamamen kapanmadı; independent_acceptance=PENDING, production_ready=false. Module/audit standalone çoklu yazım atomikliği, malformed manifest ve required-module policy, tüm eski constraint/type/engine/prefix varyantları, actor/RBAC, gerçek update/backup/deploy acceptance ayrıca açık. Sonraki adım kalan Analytics subscription/read-model kurulum ve runtime DDL sınırları; D03–D09 açık.
