# D02 — günlük fatura, tahsilat ve iade kaynakları

13.10.2026. Başlangıç commit a5c584c883aa6423d41065c56d30fd344644349f. Master plan P15.3/P15.4 kanonik veri uyumu.

Eski alanlar/durumlar gerçek kurulumda yanlış0 üretiyordu; source errors eski günlük raporu zero ile overwrite ediyordu.11 native baseline testinde10 failure; genuine empty source case geçiyordu.

Artık integer minor invoice/payment/refund alanları ve seçilen currency kullanılır. Fatura issue_date, tahsilat paid_at, iade refunded_at ile ilgili güne alınır; creation tarihi ödeme sayılmaz. Draft/cancelled invoice ve pending/failed payment dışarıda kalır. Partial/full refunded payment gross cash olarak korunur, persisted refund ayrıca düşülür; failed attempts/reservations sayılmaz. Fees ayrı kalır, net cash collected-refunded contract'ı korunur. Currency precision0–2; daha yüksek hassasiyet açık storage/metric upgrade ister.

Missing/partial financial veya count source, unknown period state, negatif/invalid amount ve tarihsiz issued/settled/refund kayıt before-write durur. Eski id/tutar/tarih değiştirilmez. Genuine empty0 üretir.16 ortak SQLite/MariaDB senaryo; ilk geliştirme import hatası testle yakalanıp düzeltildi, invoice-date counterexample sonrası guard eklendi ve final paketler yeniden doğrulandı. Existing major amount/monthly/rebuild expectations production fixture'larla korunur.

| Paket | Test | Doğrulama | Sonuç |
|---|---:|---:|---|
| Ana paketin tamamı | 1369 | 10477 | Başarılı |
| Etkilenen MariaDB paketi | 29 | 217 | Başarılı |
| Bu adım toplamı | 1398 | 10694 | Hata/atlanan test yok |

Native kapsam DailySourceTest+AnalyticsSchemaTest; full MariaDB bu adımda yeniden çalıştırılmadı. Final XML/txt + D02-4d-file-hashes.json.24 migrations/92 CREATE TABLE source, schema/new migration/backfill yok. Yalnız izole yerel DB; üretim/provider işi yok.

D02 açık; independent_acceptance=PENDING; production_ready=false. **Sırada aylık toplamların eski/yeniden üretilmiş günlük kayıtları güvenli ayırması ve sessiz kaynak hataları var.** Genel FinancialMetrics/Dashboard legacy paths, ledger/as-of/mutable state history, timezone conversion/tenant/RBAC/global counters, full precision/config changes, source isolation/upsert ve range atomiklik ayrıca açık. Counts users/tickets için globaldir; resolution current status+updated_at proxy'sidir. Gün penceresi DB stored/session wall-clock kullanır; bütün zaman dilimleri kabul edilmedi. Dört D02 ana grubunun tamamı ve D03–D09 kapanmadı.
