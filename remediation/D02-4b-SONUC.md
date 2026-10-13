# D02 — analiz tablolarının kurulum ve kayıt güvenliği

13.10.2026. Başlangıç commit b24d108d2c255e63fd8c8270be2b0755c7134d15. Orijinal P15.2/P15.4 ve P17.2 veri tabanı kapsamı.

MariaDB'de aylık tablo YEAR_MONTH ayrılmış sözcüğü nedeniyle kurulamıyordu; servisler runtime CREATE ile caller işlemini commit ediyordu. SQLite unique period/currency key kurmuyordu, MariaDB index hataları catch ile gizleniyordu. İlk before kanıtı3 error/1 failure; özgün snapshot sınıfı için ayrıca1 failing rollback izolasyon testi (bağımlılıkları karma; full baseline sonucu değildir).

Migration24 üç analytics tablosunu ve dönem/para birimi unique key'lerini kurar. Şema/veri/indeks preflight bütün mevcut tabloları CREATE öncesinde kontrol eder. Eski kayıtlar/tutarlar/ids/tarihler/Unicode/metadata korunur; duplicate veriler otomatik silinmez veya birleştirilmez. Eksik indeks explicit migration ile tamamlanır; çakışmalı indeks/yarım şema/uygunsuz engine açık hata verir. Installed runtime DDL yok; raw/managed caller rollback korunur. YEAR_MONTH adı korunarak SQL'de quote edilir; ortak schema projection da validated identifiers quote eder.

12 ortak SQLite/MariaDB ve1 native yeni senaryo. Migration24/failure23 continuation, replay, down refusal,3 unique key, duplicate history/partial-last-table before sibling CREATE, missing/conflicting index, caller rollback ve MyISAM.

| Paket | Test | Doğrulama | Sonuç |
|---|---:|---:|---|
| Ana paketin tamamı | 1340 | 10399 | Başarılı |
| MariaDB paketinin tamamı | 481 | 4025 | Başarılı |
| Toplam | 1821 | 14424 | Hata/atlanan test yok |

Final XML/txt ve D02-4b-file-hashes.json;24 migration/92 CREATE TABLE kaynak dosyası. Yalnız yerel izole veritabanı; üretim deploy/upgrade yapılmadı.

D02 açık; independent_acceptance=PENDING; production_ready=false. Dört ana grup tamamen kapanmadı. Bu adım rapor hesaplarının doğruluğunu tamamlamaz: eski hosting_services ve decimal source queries kanonik services/minor-money kolonlarıyla uyuşmuyor; bazı source errors sıfır metric olarak gizleniyor. **Sıradaki öncelik bu kaynak uyumsuzluğu ve sessiz sıfır üretimini düzeltmek.** Para/tenant/timezone/date/freshness ve MRR/ledger semantics, concurrent application upsert, audit+snapshot ve range rebuild failure atomicity, tüm legacy constraints/prefix matrix ile D03–D09 açık.
