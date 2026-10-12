# D02.3b — risk değerlendirmesi ve manuel karar bütünlüğü

12.10.2026. Başlangıç commit:2e8b58bb9ecdd2a4864a14dbe4b54cc9ddd27d87. Orijinal P14.1–P14.3 esas alındı.

İkinci sinyal yazımı hata verdiğinde değerlendirme ve ilk sinyal kalıyordu; karar yazımı hata verdiğinde inceleme kapanmış görünüyordu. Altı standalone/raw/managed MariaDB failure D02-3y-atomic-before.txt içinde. Dört ayrı işlem aynı değerlendirmeye dört inceleme ve aynı incelemeye dört karar yazabildi: D02-3y-concurrency-before.txt. Baseline kaynak ref/hash ve300ms scheduling probe açıklaması D02-3y-baseline-source-hashes.json; final testte probe kapalıdır.

Değerlendirme+sinyaller ve inceleme kapanışı+karar artık tek işlemde yazılır. Mevcut caller transaction savepoint ile korunur; başarısız yazım sonrası ilgisiz caller işi commit edilebilir. Yeni şema DDL/migration yok. InnoDB/id PK gereksinimi veya lost caller ihlalinde yazımdan önce durulur. Review create persisted evaluation parent'ını kilitler, özgün kimliği kullanır; claim/escalate/resolve güncel review row'u kilitler. Create/resolve parent-before-review sırası tutarlıdır. Cooperating writers aynı değerlendirmede tek inceleme, aynı incelemede tek terminal karar üretir; eski snapshot bunu ezemez veya incelemeyi yeniden açamaz.

Kaybolmuş özgün değerlendirme artık otomatik 'review' kararı sayılmaz. Eski duplicate review/decision veya pending+existing decision açık uzlaştırma ister; geçmiş kararlar silinmez. JSON metadata hatası terminal status bırakmaz. Karar checksum biçimi değiştirilmedi.

15 ortak SQLite/MariaDB ve6 native senaryo eklendi: write failure/rollback ve caller marker commit, successful caller rollback, missing/duplicate/inconsistent legacy evidence, invalid metadata, authoritative identity, lost caller; dört parallel worker grubu, peer committed state/stale snapshot ve MyISAM refusal. Eski kurulum/no-DDL/replay kontrolleri korunur.

| Paket | Test | Doğrulama | Sonuç |
|---|---:|---:|---|
| Ana paket | 1303 | 10205 | Başarılı |
| MariaDB10.11.18 | 439 | 3722 | Başarılı |
| Toplam | 1742 | 13927 | Failure/error/skip yok |

Final D02-3y-suite.xml/txt ve D02-3y-mariadb.xml/txt; SHA-256 D02-3y-file-hashes.json.22 migration/93 DDL kaynak. Yalnız yerel izole veritabanları kullanıldı.

D02 IN_PROGRESS; bağımsız kabul PENDING; production_ready=false. Cooperating writers için serialization sağlandı; direct SQL/bulk restore/old writer ve DB-level unique/FK/check/trigger/type/prefix matrix açıktır. Actor/tenant/RBAC/reassignment/step-up/audit ve gerçek ürün girişleri; metadata'nın checksum kapsamı, privacy/retention/restore güvenliği ayrıca açık. Deadlock caller transaction'ını tümden abort ederse savepoint bunu geri yaratmaz; otomatik retry veya bütün domain lock-order kabulü yoktur. Production upgrade/deploy yapılmadı. Sıradaki D02 işi kalan şemalar, veritabanı kısıtları ve diğer yazımların bütünlüğüdür; D03–D09 açık.
