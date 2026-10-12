# D02.3b devamı — marka, para birimi ve kur geçmişi şeması

Tarih: 12.10.2026. Referans commit: 889183520cea38c7c623271d228c11404a3ed73f. D02 IN_PROGRESS; independent_acceptance=PENDING; production_ready=false.

## Düzeltilen davranış

BrandService ve CurrencyService şema girişleri, mevcut tabloda da CREATE TABLE IF NOT EXISTS vererek MariaDB caller transaction'ını örtülü commit ediyordu. Marka güncellemesi ve para birimi yazımı rollback edilemiyordu. Marka tablosu core installer'da vardı, currencies ve fx_snapshots production migrationlarda yoktu. D02-3u-before.txt üç gerçek failure gösterir.

Migration 19 üç tabloyu domain sözleşmesiyle herhangi bir CREATE öncesinde topluca preflight eder; missing tabloları tamamlar, partial mevcut tabloda sessiz ALTER yapmaz. Mevcut marka/para birimi kayıtları ve kur geçmişi aynen korunur; business default veya test rate kaydı seed edilmez, FX provider oluşturulmaz. Core installer brands kolon defaults'ı değiştirilmez. Installed schema checks ve marka runtime yolları DDL çalıştırmaz; missing schema raw/managed application transaction içinde reddedilir. Legacy outside-transaction setup uyumluluğu sürer.

Ek failure D02-3u-fx-before.txt, missing FX tablosunda captureFxSnapshot'ın fallback TRY yazıp kur sağlayıcısını çağırdığını gösterir. Yeni persistence preflight fallback yazımı/provider I/O öncesinde çalışır. Bu kanıt şema düzeltmesinden sonra, unchanged capture metodu üzerinde alınmıştır. Explicit SQLite migration setup transaction içinde rollback edilebilir; MySQL'de caller transaction guard'ı explicit flag ile aşılamaz. Down financial history'yi silmek yerine backup/retention planı ister.

## Doğrulama

12 ortak SQLite/native MariaDB 10.11.18 senaryosu; caller rollback, clean install without seeds, installed no-DDL workflow, Unicode brand settings/currency decimal precision/default/active flags ve byte-identical raw FX JSON/id/date/provider korunması, three partial sibling shapes, missing raw PDO guard, failed migration/resume ve core brand column metadata'nın değişmemesi, destructive rollback refusal ve missing FX schema için zero provider calls/zero fallback writes. Static ve counting provider yalnız test taklitleridir; gerçek dış kur bağlantısı çalıştırılmadı. Unknown historical created_at repair fixture'da NULL bırakılır; otomatik production repair değildir.

| Paket | Test | Doğrulama | Sonuç |
|---|---:|---:|---|
| Ana paket | 1241 | 9601 | Başarılı |
| MariaDB 10.11.18 | 363 | 2779 | Başarılı |
| Toplam | 1604 | 12380 | Failure/error/skip yok |

Final kanıt D02-3u-suite.txt/xml ve D02-3u-mariadb.txt/xml; SHA-256 D02-3u-file-hashes.json. Üç migration-adedi testi 18→19; Foundation/Provider repair continuation yeni 19'u içerir, özgün failure-point sayıları korunur. 19 migration/92 DDL kaynak. Eski olumlu brand/currency/FX beklentileri zayıflatılmadı; metin envanteri bağımsız kabul değildir.

## Kalan işler

Bu adım P03.7/P05.3 için kurulum önkoşuludur; tek aktif marka ve tek default currency eşzamanlılık garantisi değildir. Count-then-insert, legacy çoklu aktif marka ve ilk-satır seçimi, standalone default currency update/insert failure atomikliği, tüm writer'larda ortak policy/lock ve unique/CAS constraint ayrıca açık.

Gerçek FX transport, rate completeness/validation/provenance ve replay/immutable snapshot kabulü, invalid providerName fallback, decimal conversion ve tarih/JSON/input validation, Application actor/tenant/RBAC ve sorgu bütçeleri ayrıca D03/D04/D06/D09 işidir. Kolon SELECT'i eski type/engine/collation/NULL/default/unique/FK/index/trigger uyumluluğunu kanıtlamaz. Core ve standalone brand kolon defaults farklılığı korunur; bu adım otomatik policy birleştirmesi yapmaz. Normal currency read/write API'leri migration veya explicit ensure gerektirir; hepsine lazy setup eklenmedi.

Sırada single-active Brand/default currency yarışları ve başarısız yazımdaki veri bütünlüğü; sonra kalan domain schema/kısıt/önek matrisi var. D02 ve D03–D09, bağımsız kabul ve üretime hazır olma onayı açık. Üretim verisi veya deployment kullanılmadı.
