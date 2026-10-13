# Analitik toplu iş paketi — C01

Başlangıç: f7bccb23a1133b6163d7d2982b9bd1d04908c000. Orijinal master plan P15 önceliklidir. Bu dosya küçük yamaları modül kabulü gibi saymak yerine tek bir kapanış listesi tutar.

## Bu paketin bitiş ölçütleri

- [x] Günlük, dönemsel finans ve muhasebe karşılaştırması aynı canonical para birimi/minor-unit/event-date kaynağını kullanır; kaynak hatası sıfır ya da MATCHED olmaz.
- [x] Alacak yaşlandırma ve katkı payı gerçek fatura bakiyesi/ödeme ücretleriyle çalışır; takvim ve para birimi girişleri doğrulanır.
- [x] Beş pano eski hosting/finance alanlarını okumaz; kanıtı olmayan kapasite/SLA/cevap süresi sayılarını uydurmaz.
- [x] Golden dataset gerçek kurulum şemasında üretilir; bağımsız ledger tutarlarıyla fark ve bozuk rollup testleri vardır.
- [x] Günlük ve aylık seri okumaları eski/değişmiş girdileri güvenilir rapor gibi sunmaz; okuma veri değiştirmez ve DDL çalıştırmaz.
- [x] Gün/aralık üretimi ve audit tek işlem sınırında geri alınır; raw/managed caller işi korunur; period/currency upsert yarışı ele alınır.
- [x] Güncellik kaynağı eksik, işaretsiz ya da gelecekte tarihli olduğunda UNKNOWN/false döner; kaynak değişikliği ve seçilen currency dikkate alınır.
- [x] Ana paket ve gerçek MariaDB kabul paketi son kaynak halinde geçer; eski para beklentileri ve performans sınırları korunur.
- [x] Kanıtlar, dosya bütünlüğü, kalan engeller ve GitHub gönderimi tamamlanır.

## Modülün ayrıca gerektirdiği kabul sınırları

P15'in tümünün PASS olması; P14 bağımlılık kabulü, gerçek historical/as-of ve müşteri bazlı MRR hareketleri, immutable ledger/reference eşleşmesi, gerçek SLA/kapasite kaynaklarının varlığı, tüm currency precision/FX/timezone politikası, role/tenant scope girişlere bağlanması ve bağımsız reviewer/gatekeeper kabulünü gerektirir. Bunlar bu çalışma bitince kaybolmuş ya da otomatik kabul edilmiş sayılmaz. D02 ve D03–D09 bu checklist yüzünden kapanmaz.

## Dosya grupları

`Metrics/`, `Semantic/`, `Security/`, `Context/CurrencyAnalyticsContext.php`, `Context/TimezoneAnalyticsContext.php`: mevcut tanım/format/kur/izin yardımcıları; ana regresyon paketinde korunur, gerçek giriş/tenant/timezone kabulü ayrıca izlenir.

`Financial/FinancialMetricsService.php`, `ReadModels/ReadModelAggregationService.php`, `ReadModels/DailyAggregationEvidence.php`, `Reconciliation/LedgerReconciliationService.php`, `Testing/GoldenFinancialDataset.php`, `Context/AnalyticsFreshnessService.php`, `Dashboards/DashboardService.php`: ortak canonical kaynak ve rapor güvenilirliği için birlikte düzeltilen ve tam regresyonda doğrulanan çalışma yolları.

`Subscription/`: snapshot kaynağı kanonik; geçmiş okuma/capture yan etkisi pano sınırında incelenir. Per-customer hareket tarihi ve bağımsız kabul hâlâ gereklidir.

Diğer `Financial/`, `ReadModels/`, `Dashboards/`, `Context/`, `Reconciliation/` değer nesneleri: gerçek sonuç/eksik veri durumunu taşır; serialize/getter sözleşmeleri regresyon testleriyle korunur.

Durum: IMPLEMENTED_LOCAL_FULL_MAIN_AND_NATIVE_TESTS_PASS. Dokuz yerel paket ölçütü doğrulandı; bağımsız P15/D02 phase kabulü PENDING. GitHub gönderimi bu kanıt commit'iyle yapılır.
