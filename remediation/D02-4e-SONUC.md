# D02 — aylık raporlarda eksik kaynak ve sessiz sıfır koruması

13.10.2026; başlangıç commit9465ae3cdf2b260c3d89469800c9c0a5f6133af1. Orijinal master plan P15.2/P15.4/P15.6; bağımsız faz kabulü değildir.

Aylık hesap, eksik günlük satırları sıfır sayarak kısmi toplam yayımlıyor; subscription SELECT hatasını yutarak önceki MRR/ARR değerini0 ile değiştirebiliyordu. İlk9 SQLite regresyonunda8 failure bu yolları doğruladı (`D02-4e-before.txt`; ilk9 senaryo, sonradan eklenen4 vaka baseline artifact'ında yok).

Artık her takvim gününün doğru tarih ve seçilen currency ile bulunması gerekir. Ay00/13, hatalı biçim, newline/null suffix reddedilir; registry ve precision0–2 doğrulanır. Complete zero month yalnız günlük producer ve canonical month-end snapshot testinde sıfır üretir; missing day zero değildir. Monthly girdiler stored2-decimal integer cents ile toplanır; negatif/invalid amount ve DECIMAL12,2 kapasite aşımı reddedilir. Selected-currency snapshot tam ay sonuna ait olmalı, source=services:canonical_minor_units ve currency_decimal_places registry ile eşleşmeli. Missing table/column hata olarak yukarı çıkar; stale/missing/legacy/malformed metadata aylık yazımdan önce durur. Eski id/tutar/tarih korunur. Devam eden ay için final monthly row yerine bu koşulların sağlanması gerekir; ayrı provisional/month-to-date model eklenmedi.

Değişen davranış ve testler:
- ReadModelAggregationService.php: monthly validation, calendar coverage, snapshot source guard ve safe stored-cents summation; daily producer ve schema contract değişmedi.
- MonthlySourceCases.php ve SQLite/MariaDB MonthlySourceTest:13 ortak vaka; kaynak/tablo/kolon hatası, stale/foreign currency, metadata/precision, partial/invalid replacement date, invalid month/currency, negative snapshot/storage overflow, leap February29days/JPY/genuine0/replay.
- DailyMonthlyReadModelAggregationTest.php: doğrudan legacy read-model seed yerine invoice/payment/refund→daily producer ve canonical service→snapshot producer. Eski para beklentileri aynı.
- AnalyticsSchemaCases.php: raw caller transaction içinde31 günlük üretim+ay sonu snapshot+monthly; rollback üçünü de kaldırır. Şema migration/replay ve no-DDL kontrolleri korunur.

| Paket | Test | Doğrulama | Sonuç |
|---|---:|---:|---|
| Ana paketin tamamı | 1382 | 10518 | Başarılı |
| Etkilenen MariaDB | 55 | 338 | Başarılı |

Final failure/error/skip0. Native kapsam yalnız dört analitik sınıf; bütün MariaDB paketi bu adımda tekrar çalıştırılmadı. Önceki full D02-4b481/4025 farklı source durumuna aittir. MariaDB10.11.18 isolated localhost33079; PHP8.4.24/PHPUnit13.4.1.24 migrations/92 CREATE TABLE source; new migration/backfill yok. Production/provider işlem yapılmadı.

Çalıştırma kanıtı: `php vendor/phpunit/phpunit/phpunit --colors=never --log-junit remediation/D02-4e-suite.xml` (başlatıcı exit0; sonuç ayrıca JUnit failure/error/skip0 ile kontrol edildi). Native direct PHP executable aynı installed current php.ini ile `-c phpunit-mariadb.xml --filter MonthlySourceTest|DailySourceTest|AnalyticsSchemaTest|SubscriptionSourceTest --colors=never --log-junit remediation/D02-4e-mariadb.xml`; exit0 + JUnit doğrulandı. İlk native launcher denemesinde CMD pipe argüman hatası nedeniyle test başlamadı; direct executable ile düzeltildi, final artifact yalnız tamamlanan run. İlk geliştirme kontrolünde test fixture'a yanlış eklenen bir assertion yakalanıp kaldırıldı; final paketler sonrası source değiştirilmedi. XML/txt ve changed-file SHA256 manifest kaydedildi.

**Açık kalanlar:** Calendar coverage günlük kayıtların yeni canonical policy ile üretildiğini kanıtlamaz; complete legacy daily rows hâlâ aylık hesapta kabul edilebilir. Üretici/policy/precision/tutar kanıtını kalıcı ve atomik bağlayan upgrade sonraki adım. Metadata security signature değildir; snapshot amount tampering/gerçek historical as-of/freshness, ledger reconciliation, tenant/RBAC/timezone, isolated source reads/concurrent upsert, atomic range+audit ve new/churned subscription counts0 placeholder ayrıca açık. GetMonthlySeries eski kayıtları doğrulamadan okumaya devam eder. D02 IN_PROGRESS, independent_acceptance=PENDING, production_ready=false; dört ana grup ve D03–D09 kapanmadı.
