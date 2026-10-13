# D02 — abonelik raporunun gerçek hizmet kaynağı

13.10.2026. Başlangıç commit209fd2e8aee2fa34bd79769bec6fe25031f8cfc3. Master plan P15.2'nin kanonik kayıt uyumu.

Gerçek services tablosunda gelir varken eski hosting_services sorgusu MRR0 üretiyordu. Kaynak hatası sessizce sıfır olarak kaydediliyordu. Başlangıç9 native regresyon testinde8 failure; available-empty source zero baseline'da da geçer.

Artık active canonical services ve seçilen currency okunur; recurring_amount_minor, currencies.decimal_places ile major değere çevrilir. One-time işlemler abonelik sayılmaz. Unknown currency/cycle, negatif tutar/geçersiz müşteri ve gerçek olmayan tarih explicit error verir. Missing/partial source PDO error yutulmaz; eski snapshot değişmez. Genuine empty canonical source0 üretir. Currency trim/uppercase tutarlı, source/precision metadata kaydedilir. Önceki tarihli snapshot source/precision bilinmiyorsa yanlış growth/churn üretmek yerine explicit reconciliation ister; eski satırlar silinmez.

Mevcut derived tables/MRR contract0–2 decimal precision destekler; daha yüksek precision storage upgrade yapılana kadar açıkça reddedilir. Bu bütün currency/ledger semantics'in kabulü değildir. Ek history counterexamples geliştirme aşamasında2 fail verdi, sonra guard eklendi ve final paketler yeniden doğrulandı.

| Paket | Test | Doğrulama | Sonuç |
|---|---:|---:|---|
| Ana paketin tamamı | 1353 | 10428 | Başarılı |
| Etkilenen MariaDB paketi | 26 | 190 | Başarılı |
| Bu adım toplamı | 1379 | 10618 | Hata/atlanan test yok |

Native kapsam SubscriptionSourceTest+AnalyticsSchemaTest; full MariaDB bu adımda yeniden çalıştırılmadı.13 yeni ortak senaryo, production migration schema fixtures. Subscription unit ve rollback fixture canonical service/currency kurulumu kullanır; eski beklenen amounts/performans bütçesi korunur. Owner/performance canonical hizmetleri de seed eder; diğer eski finance/Sales/Ops fixtures ayrıca açık olan yollar içindir.24 migration/92 DDLsource, yeni migration/şema dönüşümü yok. Final XML/txt+SHA256manifest; üretim/provider işlemi yapılmadı.

D02 açık; independent_acceptance=PENDING; production_ready=false. **Sırada günlük fatura/tahsilat/iade raporlarının eski kaynakları ve sessiz sıfır davranışı var.** FinancialMetrics/Dashboard diğer kaynakları, historical as-of/movement classifications, full precision/ledger reconciliation, eski metadata uzlaştırması, tenant/RBAC, source snapshot concurrency/upsert/audit failure atomicity ve dört D02 ana grubunun kalanı açık. D03–D09 tamamlanmadı.
