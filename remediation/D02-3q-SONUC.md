# D02.3b devamı — eylem kayıtlarıyla bağlı başarı kanıtı

Tarih: 12.10.2026. Orijinal plan P11.2 run history ve F14 recovery bütünlüğü kapsamında. D02 IN_PROGRESS; independent_acceptance=PENDING; production_ready=false.

## Açık ve düzeltme

Kurtarma denetimi SUCCESS geçmişini değerlendirirken D02-3p'de eklenen eylem kayıtlarını dikkate almıyordu. Eksik/silinmiş action row, OUTCOME_UNKNOWN row veya başka approval'a ait row, başarılı history ile işi görünmez yapabiliyordu. D02-3q-before.txt üç özgün başarısızlığı gösterir. Bu açık çözülmeden gerçek provider sonucuna dayalı uzlaştırma kararı eklenmez.

Yeni ActionAttemptIdentity writer'ın definition/input hash sözleşmesini ortaklaştırır; eski hashlerin byte biçimi değiştirilmez. Inspector history/pending/action için üç toplu SELECT ile envanter alır, action başına yeni sorgu yapmaz. Önceki linked-version okumaları korunur; genel sorgu bütçesi kabulü değildir. Mevcut pending/history kimlik, version, frozen policy, branch/context ve final success ölçütlerine ek olarak:

- History action_attempt_ids, ordered action listesinin tamamına ve yalnız bir dispatch'e ait olmalı.
- Her row aynı rule/correlation/pending kind/id/fingerprint, version/hash ve action id/type/definition hash'e bağlı olmalı.
- Planlanan ve gerçekten dispatch edilen input hash'leri beklenen pending girdisine eşit olmalı.
- Bütün rowlar REPORTED_SUCCESS/HANDLER_REPORTED_SUCCESS olmalı; eksik, unknown veya blocked kayıt başarıyı kapatamaz.
- Aynı pending veya dispatch içindeki referans verilmeyen ek row/dispatch de tamamlanmayı engeller.
- created/start/finish/history sırası, approval decision veya delay execute_at sınırı ve ordered eylemlerin zaman sırası tutarlı olmalı.

Başka işe ait kayıt, değişmiş parametre/sürüm/zaman, reversed/duplicate/partial reference veya ek dispatch local completion sayılmaz. Eski SUCCESS history'de attempt reference yoksa kayıt backfill edilmez; incelemede kalır ve otomatik retry izni verilmez. Sağlıklı yeni approval/delay ve coherently executed legacy delay, mevcut unknown provenance alanlarını koruyarak yerel denetimi tamamlayabilir.

Inspector salt okunurdur. Eylem, sağlayıcı isteği, retry, pending reopen, backfill veya yeni destructive endpoint çalıştırmaz. Yeni schema/migration/index yoktur; 16 migration ve 92 DDL kaynak korunur.

## Test kanıtı

Sekiz yeni shared test mevcut 19 recovery testine eklenir. 27 shared SQLite/MariaDB senaryosu, MariaDB mevcut iki concurrency/current-read testiyle 29 hedefli senaryodur. Yeni kapsam üç özgün açık, 24 row bozulması, eski reference yokluğu/no backfill, iki ordered action positive ve reversed/duplicated/partial/unknown refs, unreferenced ikinci dispatch ve overlap zamanlarıdır. Her bozulmada evidence/pending/history değişmeden kalır; yeni handler çağrısı veya retry yoktur. Olumlu eski beklentiler gevşetilmedi, test silinmedi/atlanmadı.

| Paket | Test | Doğrulama | Sonuç |
|---|---:|---:|---|
| Ana paket | 1192 | 9040 | Başarılı |
| MariaDB 10.11.18 | 312 | 2109 | Başarılı |
| Toplam | 1504 | 11149 | Failure/error/skip yok |

Final kanıt D02-3q-suite.txt/xml ve D02-3q-mariadb.txt/xml; changed-file SHA-256 D02-3q-file-hashes.json. Hedefli development logları final tam paketlerin yerine kullanılmaz. Yeni fixture'da eksik provenance alanları tamamlandı; validator değiştirilmedi. Bu recovery test dosyasının MariaDB parent bağlantısı native prepared statements kullanır; eski worker yollarının tamamı için bu iddia yapılmaz. Üretim verisi, canlı provider çağrısı veya deployment yapılmadı.

## Sınırlar ve sonraki adım

Bağlanmış history hâlâ yerel handler gözlemidir; sağlayıcının gerçek sonucunu veya historical operation ownership'ini kanıtlamaz. Hash yetki/imza değildir, bütün SQL kanıtlarının tutarlı kötü niyetli değiştirilmesine karşı koruma iddia edilmez. Bu salt okunur snapshot yeni yürütme izni veya lease değildir. Direct ACTIVE event dedup, provider operation/configuration provenance, dış effect idempotency/lease/ACK, controlled retry ve kesin yokluk/ownership kanıtı açık kalır.

İncelenen ServiceSuspend/Unsuspend/Terminate adapter'ları yalnız ServiceService'in yerel durum komutlarını çağırıyor; mevcut provider operation id varmış gibi bağlanmadı. Sonraki iş gerçek sağlayıcı komutunu Application akışından açık operation/attempt bağıyla geçirmek ve readback kararını yalnız bu bağla kabul etmektir. Actor/tenant RBAC, HTTP/CLI/cron girişleri, retention/privacy/backup, diğer domain kurulum matrisi ve D03–D09 kabulü açık.
