# D02.3b devamı — altyapı şeması ve transaction güvenliği

Tarih: 12.10.2026. D02 IN_PROGRESS, independent_acceptance=PENDING, production_ready=false. Referans commit: 1570988eba8d58ebc94cf2c32f89753a425558bc.

## Düzeltilen davranış

Önbellek, veritabanı kilidi, genel tekrar işlem koruması ve scheduler heartbeat yolları her kullanımda CREATE TABLE IF NOT EXISTS veriyordu. MariaDB'de tablo zaten var olsa da bu DDL, çağıranın açık transaction'ını commit ediyor; sonraki rollback veriyi geri alamıyordu. Migration geçmişini okuyan yol da aynı sorunu taşıyordu. D02-3s-before.txt beş özgün failure (dört transaction ve eksik production kurulumu), D02-3s-metadata-before.txt ayrı metadata failure kanıtıdır.

Yeni migration 17 cache, locks, idempotency_keys ve cron_heartbeats tablolarını production kuruluma bağlar. Kurulu tablolarda gerekli kolonlar SELECT ile kontrol edilir; normal kullanım CREATE/ALTER çalıştırmaz. Eksik tablo raw PDO veya Connection transaction içinde başlamadan hata verir. Mevcut fakat yarım şema kendiliğinden değiştirilmez; veri korunarak açık upgrade istenir. Kurulum dışında legacy first-use transaction dışında uyumluluk için sürer.

SQLite migration runner'ın transactional DDL'si açık installSchema parametresiyle desteklenir. Bu parametre MySQL'in transaction guard'ını aşamaz. Migration rollback'i etkin lock/replay/heartbeat kayıtlarını otomatik silmez, açık bakım ve retention planı gerektirir. Eski migrationlar aynıdır; üç mevcut migration sayısı beklentisi 16'dan 17'ye yükselir.

## Doğrulama

13 ortak senaryo SQLite ve gerçek MariaDB 10.11.18 üzerinde çalışır; MariaDB native prepares kullanır. Caller rollback, query log'da no-DDL, clean production install, missing/partial schema, Unicode cache/replay sonuçları ve lock sahibinin korunması, tekrar migration, failed migration sonrası yalnız 17'den devam, callback'in çalışmadan durması ve explicit transactional installation sınırı ölçülür. Geliştirmede SQLite kurulum ayrımından kaynaklanan iki error düzeltildi; bunlar eski ürün açığının fail-first kanıtı değildir.

| Paket | Test | Doğrulama | Sonuç |
|---|---:|---:|---|
| Ana paket | 1217 | 9361 | Başarılı |
| MariaDB 10.11.18 | 339 | 2494 | Başarılı |
| Toplam | 1556 | 11855 | Failure/error/skip yok |

Final kanıt: D02-3s-suite.txt/xml ve D02-3s-mariadb.txt/xml; değişen dosyaların SHA-256 listesi D02-3s-file-hashes.json. 17 migration, 92 DDL kaynak. Dört source migration reference güncellendi; metin envanteri şema kabulü sayılmaz. Gerçek provider veya üretim verisi kullanılmadı.

## Kalan sınırlar

Bu kolon kontrolü eski engine/collation/tip/NULL/default/unique/FK/index/trigger varyantlarının uyumunu kanıtlamaz. Generic lock TTL/ownership ve SQL error classification, cache yazım atomikliği, serialized replay/expiry/failure sonrası retry ve scheduler overlap/heartbeat ownership ayrıca inceleme ister. Doctor cron_runs ve Foundation cron_heartbeats writer hattı birleşmiş değildir. Yerel transaction testi dış servislerde exactly-once veya güvenli retry kabulü değildir.

Diğer domainlerin runtime DDL yolları, legacy şema/kısıt/önek matrisi ve D03–D09 açık. Sonraki adım kalan D02 schema girişlerini regresyonlarla ele almaktır. Bağımsız kabul ve üretime hazır olma onayı verilmemiştir.
