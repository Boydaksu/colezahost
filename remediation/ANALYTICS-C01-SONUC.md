# ANALYTICS-C01 â€” toplu raporlama dÃ¼zeltmesi

Referans: `f7bccb23a1133b6163d7d2982b9bd1d04908c000`. Orijinal ZIP/P15.3â€“P15.7 referans alÄ±nÄ±r. Paket, D02 iÃ§indeki eski ÅŸema/raporlama hatalarÄ±nÄ± birlikte dÃ¼zeltir; P15 phase PASS veya Ã¼retim kabulÃ¼ deÄŸildir. Ana testler tamamlandÄ±; tam native sonuÃ§ ve GitHub gÃ¶nderimi bekleniyor.

## Son davranÄ±ÅŸ

GÃ¼nlÃ¼k ve dÃ¶nemsel finans `FinancialSourceReader` Ã¼zerinden aynÄ± invoice issue_date, settled paid_at, persisted refunded_at, currency ve minor-unit politikasÄ±nÄ± kullanÄ±r. DB/kaynak hatalarÄ± sÄ±fÄ±r gelir sayÄ±lmaz. GÃ¼nlÃ¼k kayÄ±tta integer toplamlar ve mevcut producer evidence korunur. Currency registry decimal0â€“2 desteklenir; 3+ precision aÃ§Ä±k upgrade/reconciliation ister. DÃ¶nem raporundaki kaynak okumalarÄ± tek transaction/snapshot iÃ§inde yapÄ±lÄ±r. Eski toplam300/net260/tax40/cash100/refund15/net85, aging450 ve contribution670/margin74.44 beklentileri deÄŸiÅŸmedi.

Alacak yaÅŸlandÄ±rmasÄ± `total_minor-paid_amount_minor` ve gerÃ§ek due_date ile, seÃ§ilen currency ve as-of'a kadar dÃ¼zenlenmiÅŸ faturalarÄ± kullanÄ±r. KÄ±smen Ã¶denmiÅŸ bakiye hesaplanÄ±r; gelecekte dÃ¼zenlenen faturalar alÄ±nmaz. Bu mevcut bakiyenin verilen tarihe gÃ¶re yaÅŸlandÄ±rÄ±lmasÄ±dÄ±r; geÃ§miÅŸteki gerÃ§ek bakiye yeniden kurulmuÅŸ sayÄ±lmaz. Tarih/para birimi ve finite/nonnegative direct cost doÄŸrulamasÄ± vardÄ±r.

Muhasebe karÅŸÄ±laÅŸtÄ±rmasÄ± `cash_refunded` ve gerÃ§ek financial_account_transactions minor/event/currency alanlarÄ±nÄ± kullanÄ±r. Rollup yoksa canonical live kaynak okunur. Rollup varsa eksik takvim reddedilir; tutar/evidence bozukluÄŸu aÃ§Ä±k discrepancy olur. Kaynak hatasÄ± MATCHED0 olmaz. Summary'nin tarih/currency'si comparison scope ile eÅŸleÅŸmek zorundadÄ±r. Golden dataset kopya CREATE TABLE yerine gerÃ§ek 25 migrasyonu kurar, 31 gÃ¼nlÃ¼k raporu gerÃ§ek producer ile Ã¼retir; baÄŸÄ±msÄ±z95000 payment/10000 refund ledger tutarlarÄ±, ghost ledger ve bozuk rollup kontrolleri korunur. Fixture gerÃ§ek provider Ã¶deme zinciri kabulÃ¼ deÄŸildir.

GÃ¼nlÃ¼k seri okuyucu policy/precision/content evidence doÄŸrular. AylÄ±k seri okuyucu tam takvim + her gÃ¼nlÃ¼k evidence + canonical ay sonu snapshot Ã¼zerinden beklenen alanlarÄ± salt okunur yeniden tÃ¼retip stored monthly row ile karÅŸÄ±laÅŸtÄ±rÄ±r. Eski monthly row gÃ¼ncel doÄŸrulanmÄ±ÅŸ girdilerle uyuÅŸuyorsa okunabilir; uyuÅŸmazlÄ±k/eksik input hata verir. Okuma bootstrap/backfill/DDL yapmaz. Hash imza veya mutable kaynak geÃ§miÅŸinin doÄŸruluÄŸu deÄŸildir.

GÃ¼n ve ay Ã¼retimi transaction/savepoint ile Ã§alÄ±ÅŸÄ±r; insert/select/update yarÄ±ÅŸÄ± unique period/currency upsert ile deÄŸiÅŸtirilmiÅŸtir. AralÄ±k rebuild bÃ¼tÃ¼n gÃ¼nler + audit + iÃ§erik checksum'unu aynÄ± iÅŸlemde tamamlar. Audit hatasÄ±nda standalone yazÄ±lar geri alÄ±nÄ±r; raw/managed caller'Ä±n daha Ã¶nceki iÅŸi korunur ve dÄ±ÅŸ transaction commit edilmez. Audit namespace yanlÄ±ÅŸlÄ±ÄŸÄ± gerÃ§ek logger tipiyle giderildi. Subscription capture audit atomikliÄŸi bu boundary'nin parÃ§asÄ± deÄŸildir.

Owner pano geÃ§miÅŸ tarih iÃ§in sessiz current-service snapshot Ã¼retmez; explicit canonical snapshot gerekir. Owner MRR trend source/precision kontrolÃ¼, gÃ¼nlÃ¼k cash trend ve inclusive30 gÃ¼nlÃ¼k window kullanÄ±r. Finance canonical live finansÄ± kullanÄ±r. Sales gerÃ§ek orders.total_minor/currency_code, products/services ve normalleÅŸtirilmiÅŸ aylÄ±k Ã¼rÃ¼n gelirini kullanÄ±r; customer count global kalÄ±r. Ops gerÃ§ek services/expiry_date/abuse_cases okur. Kapasite Ã¶lÃ§Ã¼mÃ¼ olmayan durumda null dÃ¶ner. Support backlog bÃ¼tÃ¼n aÃ§Ä±k tickets'tÄ±r; opened cohort, department isimleri ve current resolved status/updated_at proxy kullanÄ±lÄ±r. SLA tablosu varsa gerÃ§ek first response ve tamamlanmÄ±ÅŸ SLA breach kayÄ±tlarÄ±ndan hesaplanÄ±r; kaynak yokken sÃ¼re/SLA null olur. Ã–nceki uydurma42.5/24.5 ve resolved/opened oranÄ± kaldÄ±rÄ±ldÄ±.

Freshness eski/eksik/geÃ§ersiz evidence, gelecek aggregate/date veya kaynak hatasÄ±nda UNKNOWN/false ve nullable zamanÄ±/lag'i dÃ¶ndÃ¼rÃ¼r. SeÃ§ilen currency'nin mevcut finansÄ± gÃ¼nlÃ¼k raporla yeniden karÅŸÄ±laÅŸtÄ±rÄ±lÄ±r; backdated refund/payment ve yeni event gÃ¼nÃ¼ iÃ§in olmayan rollup inconsistent olur. Lag en son rebuild'in yaÅŸÄ±dÄ±r. TutarlÄ±lÄ±k bildirimi sadece sorgulanan mevcut financial rollup kapsamÄ±ndadÄ±r; identity/provider/support/subscription kaynaklarÄ±nÄ±n veya tÃ¼m historik aralÄ±ÄŸÄ±n gÃ¼ncelliÄŸini kanÄ±tlamaz.

## Test kanÄ±tÄ±

BaÅŸlangÄ±Ã§6 regresyonun6'sÄ± baÅŸarÄ±sÄ±z: `ANALYTICS-C01-before.txt`. Ä°lk tam ana koÅŸu1420 Ã¶ncesi1402 testte1 hata: geÃ§ersiz month, schema kontrolÃ¼nden Ã¶nce validation almalÄ±ydÄ±; source sÄ±rasÄ± dÃ¼zeltildi. `ANALYTICS-C01-main-initial.*` korunur. Ä°lk focused native20/147 geÃ§ti; sonraki6 ortak dashboard/input regresyonu o koÅŸunun kapsamÄ± deÄŸildir. Son ana paket1420/10725, failure/error/skip0; parasal beklentiler ve admin500ms sÄ±nÄ±rÄ± korunur. Native final iki disjoint partition; toplam/identity proof tamamlanÄ±nca aÅŸaÄŸÄ±ya yazÄ±lacak.

## Kalan kabul iÅŸleri

1. GerÃ§ek historical/as-of invoice/subscription/support olaylarÄ±, mÃ¼ÅŸteri bazlÄ± new/churn/expansion/contraction ve aylÄ±k subscription count0 placeholder'Ä±n kaldÄ±rÄ±lmasÄ±; immutable source/reference ledger baÄŸlarÄ±.
2. Application/read-query sÄ±nÄ±rÄ±, bÃ¼tÃ¼n HTTP/CLI/cron giriÅŸlerinde tenant/RBAC ve actor/audit sÃ¶zleÅŸmesi; mevcut domain SQL sorgularÄ± bu genel kabulÃ¼n yerine geÃ§mez.
3. Tam precision/FX/timezone, gerÃ§ek capacity/provider/SLA kaynak kurulumu ve doÄŸruluÄŸu, subscription snapshot upsert/audit atomikliÄŸi ve mutable source concurrency kapsamÄ±.
4. DiÄŸer domainlerin D02 install/upgrade/kÄ±sÄ±t matrisi, P14 baÄŸÄ±mlÄ±lÄ±k PASS'i ve baÄŸÄ±msÄ±z reviewer/gatekeeper. D03â€“D09 aÃ§Ä±k.

25 migration korunur; yeni ÅŸema yok. Golden kopya DDL kaldÄ±rÄ±ldÄ±ÄŸÄ± iÃ§in CREATE TABLE kaynak dosyasÄ±91'e dÃ¼ÅŸer; bu91 modÃ¼lÃ¼n kabul edildiÄŸi anlamÄ±na gelmez. Bu paket ayrÄ± kÃ¼Ã§Ã¼k yama sayacÄ± yerine `ANALYTICS-CLOSURE.md` iÃ§inde9 bitiÅŸ Ã¶lÃ§Ã¼tÃ¼yle takip edilir.

## Tekrarlama

Yerel test DB'si `remediation/start-d02-db.ps1` ile başlatılır. İzole MariaDB DSN/user/password env değişkenleri test ortamına ayarlanır; gerçek production database kullanılmaz. Ana paket `php -c <php.ini> vendor/phpunit/phpunit/phpunit --log-junit remediation/ANALYTICS-C01-main.xml`; tam native kabul iki ayrı terminal/process ile `-c remediation/ANALYTICS-C01-native-a-config.xml` ve `-c remediation/ANALYTICS-C01-native-b-config.xml` üzerinden koşulur. Tam keşif `-c phpunit-mariadb.xml --list-tests-xml remediation/ANALYTICS-C01-native-expected.xml` ile alınır. Her native test random isimli kendi DB'sini açıp kapatır. Flags failOnRisky/failOnWarning/output strict korunur.

Native birleşik kanıtı iki bölümün test id kümelerinin disjoint olması ve tam keşif kümesine eşitliğiyle doğrulanır. Hash/state/complete coverage kontrolü `python remediation/verify-analytics-c01.py` üzerinden tekrarlanabilir. Metin/XML/JSON yeni kanıtları Git LF satır sonuna normalize edildikten sonra SHA256 alınır. Run dosyaları final kaynak hashleri ve başlangıç commit ile birlikte değerlendirilir; önceki başarısız veya dar kapsamlı koşular final tam paket diye sunulmaz. İş sonunda yalnız projeye ait DB `remediation/stop-d02-db.ps1` ile durdurulur.

## Final kabul sonucu

Ana1420/10725 + tam native563/4531 = toplam1983/15256. Failure/error/skip0. Ana kapsam: Unit1363/9471, Integration48/1239, Architecture9/15. Native271+292, tüm35 sınıf; test id kümeleri disjoint ve tam563 keşifle eşit. Raw native process exit0 koşuları korunur. Kaynak final native koşuları başladıktan sonra değiştirilmedi.25 migration,91 DDL source. D02 IN_PROGRESS; independent_acceptance PENDING; production_ready false.
