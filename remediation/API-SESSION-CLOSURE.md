# API-SESSION-C01 — yerel veri kalıcılığı kabul ölçütleri

Referans: `b7f8f493a303ecf9753bd1230d236c61d85c7252`. Orijinal P04.7/P04.8 ve P02 oturum altyapısıyla ilgili D02 onarım paketi. Bu liste bağımsız faz kabulü değildir.

Son test sonuçları ve doğrulama: `API-SESSION-C01-SONUC.md`, `verify-api-session-c01.py`.

1. Kurulu dört API/oturum tablosunda setup ve iş yolları DDL çalıştırmaz; raw/managed caller korunur, kaybolmuş managed caller sorgudan önce reddedilir.
2. Eksik iş şeması açık caller içinde kurulmaz. Partial columns, canonical primary key, MySQL InnoDB, API hash unique ve session/rate beklenmeyen unique kimlikleri görünür hata verir; mevcut kayıtlar silinmez.
3. Hız sınırı ilk, mevcut ve expired pencerede atomik upsert + kilitli işlem okuması kullanır; dört gerçek süreç tam izin verilen sayıda kabul edilir.
4. Rejected attempt sayımı commit edilir; quota caller rollback ile geri getirilemez. Invalid policy yazımdan önce reddedilir; reset statement failure eski sayacı korur.
5. Aynı oturuma dört ilk yazım tek kayıt ve aynı yazara ait payload/metadata bırakır; update null metadata'nın önceki korunma davranışını sürdürür. Caller rollback ve statement failure korunur.
6. Expired oturum temizliği conditional DELETE kullanır; stale caller okuması peer tarafından yenilenen oturumu silemez. Aktif oturum ve GC davranışı korunur.
7. Session JSON encoding failure eski payload'ı korur. Malformed API scope JSON/list/type, required-scope ve expiry sınırı yetkisiz erişime açılmaz.

SQLite explicit migration setup desteği korunur; transaction dışındaki eski standalone ilk kullanım kurulumu uyumluluk için devam eder. Yeni tablo, migration veya historical backfill yoktur.

Açık kabul işleri: gerçek HTTP middleware ordering/429/header/identical key policy/idempotency; Auth/2FA token lifecycle ve revocation-yazım yarışları; actor/tenant/RBAC; binary/case-sensitive identity ve genel type/collation/FK/index/trigger matrix; üretim kurulum/upgrade ve bağımsız reviewer. D02 IN_PROGRESS, independent_acceptance=PENDING, production_ready=false.

Yukarıdaki yedi yerel ölçüt nihai ana/etkilenen native paketleriyle tamamlandı. Bağımsız kabul bekliyor.
