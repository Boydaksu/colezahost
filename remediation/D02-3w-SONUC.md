# D02.3b — şikâyet kayıtlarının kurulum ve işlem güvenliği

Tarih: 12.10.2026. Başlangıç commit: dd0dd9eaa433d6a8da8ec859326e04b74a66c0bf.
Orijinal master plan P14.4 ve migration/upgrade disiplini esas alındı.

## Değişiklik

Yeni kurulumda abuse_cases ve abuse_case_messages eksikti. Servis okuma/yazma sırasında tekrar CREATE TABLE çalıştırıyor; MariaDB'de çağıranın transaction'ını kapatıyordu. İki hata değişiklik öncesinde gerçek MariaDB testlerinde gösterildi: D02-3w-install-before.txt ve D02-3w-transaction-before.txt.

Migration21 iki tabloyu ve üç arama indeksini kurar. Kurulu şemada uygulama işlemleri DDL çalıştırmaz; caller rollback şikâyet, mesaj ve ilgisiz marker kaydını birlikte geri alır. Eksik şema aktif transaction içinde, yarım mevcut şema herhangi bir CREATE öncesinde durdurulur. Otomatik kayıt silme veya tahminî kolon dönüşümü yapılmaz. Migration tekrarında özgün kayıtlar ve tarihler korunur; eksik indeksler explicit upgrade'de tamamlanır. İndeks oluşturma hataları sessizce yutulmaz. Yeni MariaDB tabloları InnoDB kullanır. Down, yedek/retention ve açık bakım planı ister.

## Doğrulama

8 ortak SQLite/MariaDB senaryosu ve 1 MariaDB engine testi eklendi. Kurulum, Unicode veri koruma/replay, raw caller rollback/no-DDL, missing/partial schema guard, eksik indeks tamamlama, başarısız migration'ın history'ye yazılmaması ve açık fixture düzeltmesinden sonra devam, down refusal ölçüldü. Eski testlerin veri koruma kontrolleri korundu; toplam migration sayısı21 ve continuation listeleri güncellendi.

| Paket | Test | Doğrulama | Sonuç |
|---|---:|---:|---|
| Ana paket | 1265 | 9850 | Başarılı |
| MariaDB10.11.18 | 394 | 3188 | Başarılı |
| Toplam | 1659 | 13038 | Failure/error/skip yok |

Son kaynaklarla final tam paketler D02-3w-suite.xml/txt ve D02-3w-mariadb.xml/txt içinde; dosya SHA-256 manifesti D02-3w-file-hashes.json. 21 production migration;93 CREATE TABLE kaynak dosyası. Yalnız yerel izole veri tabanları kullanıldı.

## Kalan kapsam

D02 IN_PROGRESS; independent_acceptance=PENDING; production_ready=false. Şikâyet iş akışının tümü veya master plan P14 tamamlanmış sayılmaz. Aktör/tenant/RBAC, iç mesaj erişimi, staff authorization/audit, gerçek resource suspend, standalone çoklu yazım atomikliği/concurrency, case-number collision retry, FK/orphan/enum/check, retention/privacy/backup restore ve bütün DB constraint/engine/prefix matrisi açıktır. Eski MyISAM tabloları otomatik dönüştürülmez; aynı adlı eski indeksin exact kolon/type uyumu bu adımın kabulü değildir. Production upgrade/deploy yapılmadı. Sıradaki adım kalan domain şemaları ve veri tabanı kısıtlarıdır; D03–D09 devam ediyor.
