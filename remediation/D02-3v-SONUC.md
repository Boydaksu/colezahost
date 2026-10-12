# D02.3b devamı — tek marka ve varsayılan para birimi yazım bütünlüğü

Tarih: 12.10.2026. Referans: c345521b2abb7f037b2fc0e52399a84f2590e796. D02 IN_PROGRESS; independent_acceptance=PENDING; production_ready=false.

## Düzeltilen davranış

Failed currency INSERT sonrası eski default flag kayboluyor, çoklu aktif Brand ve currency defaults ilk satıra göre seçiliyordu. İlk dört gerçek MariaDB failure D02-3v-before.txt içindedir. Unchanged installer kaynağının ikinci active Brand/provider org açması D02-3v-installer-before.txt ile ayrıca gösterildi. Başlangıçtaki domain kaynaklarında dörder worker dört active Brand/dört default currency oluşturdu: D02-3v-concurrency-before.txt. Baseline scheduling gap 300ms genişletildi, algoritmalar değiştirilmedi; kaynak SHA-256/ref D02-3v-baseline-source-hashes.json. Final testler baseline mode kullanmaz.

Migration 20, configuration_write_locks metadata table'ını kurar. Brand/Currency ensure ve core initializer bu tabloyu önceden hazırlar. Fixed scope row upsert + current row lock, aynı connection transaction'ında cooperate eden Brand/domain/installer writer'larını ve currency default değişimini sıraya alır. Default clear/new INSERT tek transaction veya caller savepoint'indedir; failed INSERT eski default'ı korur. Raw PDO ve Connection caller transaction'ı commit edilmez; failure sonrası unrelated caller işi commit edilebilir. Snapshot stale olsa da lock altında current read sonradan committed marka/default'ı görür.

Çoklu active/default veya inactive default automatic selection/change yapmaz; explicit reconciliation gerekir. Installer boş binding + existing active brand durumunda org/brand açmaz, explicit binding ister; bind/update yalnız unique active brand ile çalışır. Tek aktif currency/no default fallback kendini default'a promote etmez; birkaç active currency/no default açık selection ister. Normal boş config concurrent fallback'lerinde tek brand/TRY oluşur.

Kilit id primary key'i/scope identity ve MySQL mutex/business table engine'i kontrol edilir. MyISAM veya incompatible mutex schema automatic ALTER yerine durur; eski default veri kaybı önlenir. Installer atomic writes'taki organization/member/binding/settings tabloları da InnoDB gerektirir. Geliştirmede yeni SQLite mutex kimliğinin NULL kabulü D02-3v-development-null-identity.txt ile gösterilip explicit NOT NULL ile düzeltildi; bu eski repository fail-first kanıtı değildir. Son kaynaklarla iki tam paket yeniden çalıştırıldı. Genel Connection transaction implementation'ı değiştirilmedi. Migration down writer'ların durdurulmasını ve maintenance planını ister.

## Doğrulama

16 ortak SQLite/native MariaDB 10.11.18 senaryosu ve 6 MariaDB ek senaryo. Failed insert rollback, raw/managed caller savepoints/commit, raw installer rollback, missing/corrupt/PK-less mutex ve lost caller guard, Unicode/replay preservation, ambiguity/inactive policy; dörder separate worker'da brand create, currency defaults, empty fallback ve mixed installer/domain writes; old raw snapshot sonrası separate connection committed values; MyISAM rejection. Provider/network veya üretim verisi kullanılmadı.

| Paket | Test | Doğrulama | Sonuç |
|---|---:|---:|---|
| Ana paket | 1257 | 9791 | Başarılı |
| MariaDB 10.11.18 | 385 | 3109 | Başarılı |
| Toplam | 1642 | 12900 | Failure/error/skip yok |

Final kanıt D02-3v-suite.txt/xml ve D02-3v-mariadb.txt/xml; SHA-256 D02-3v-file-hashes.json. 20 migration/93 DDL kaynak. Eski unrelated-active-brand installer testi single-active V1 kuralına yükseltildi: ikinci brand/org success yerine unchanged records/binding rejection ölçer. Legacy explicit binding ve RBAC/rollback pozitifleri korunur. Migration count/repair continuation ve latest rollback refusal message beklentileri güncellendi; eski failure-point counts ve history-preservation kontrolleri korunur.

## Kalan sınırlar

Bu uygulamanın ortak kilidi kullanan writer'ları için serialization'dır; is_active/is_default business flags üzerinde DB unique constraint eklenmedi. Doğrudan SQL, bulk restore veya ortak kilidi atlayan writer bu kuralı bozabilir; runtime ambiguous records'ı reddeder. Eski conflicting records migration ile silinmez/deactivate edilmez/default seçilmez. Production upgrade ve old worker quiescence uygulanmadı.

Application actor/tenant/RBAC ve default approval/CAS, gerçek giriş yolları, cross-scope lock-order/deadlock ve retry policy, all-domain DB constraint/engine/prefix matrix, FX transport/provenance/complete rates/immutable snapshots ve decimal conversion D03/D04/D06/D08/D09 ayrıca açıktır. DB deadlock caller transaction'ını tümden abort ederse savepoint onu yeniden yaratamaz; otomatik retry yoktur. Brand fallback provider ownership/binding inference değildir. Sıradaki D02 işi kalan schema ve DB-level kısıt/engine uyumluluğu matrisidir. Bağımsız kabul ve üretime hazır olma onayı verilmedi.
