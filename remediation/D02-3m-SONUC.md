# D02.3b devamı — kalıcı global yıkıcı işlem sınırı

Tarih: 12.10.2026. P11.2/P11.6 ve F14 kapsamında düzeltme. D02 IN_PROGRESS; bağımsız kabul PENDING; production_ready=false.

## Düzeltilen davranış

Mevcut factory her çalışan için yeni bellek sayacı kuruyordu. Engine kontrolü handler öncesinde, kaydı yalnızca başarılı handler sonrasında yapıyordu. Yeniden başlatma, eşzamanlı yürütme ve belirsiz sonuçta sınır aşılabiliyordu. İki özgün regresyon sınır 5 olduğu halde 7 handler çağrısı gösterdi; D02-3m-before.txt ham kanıttır.

Engine artık BlastRadiusLimiterInterface.tryAcquire ile handler'dan önce kontenjan ayırır. Factory ve application container DatabaseBlastRadiusLimiter kullanır. Migration 15 automation_blast_policy ve automation_destructive_permits tablolarını, rolling timestamp indeksini kurar. Policy kuruluma genel 5 girişim/3600 saniyedir; farklı destructive türler aynı bütçeyi paylaşır. Çalışan ayarı kalıcı policy ile uyuşmazsa işlem durur; istek sırasında DDL veya sessiz kurulum yoktur.

Admission singleton otomasyon kilidi altında current-read ile kontrol edilir. Permit commit edilmeden handler başlamaz. Dış etkinin uygulanıp uygulanmadığı başarısız yanıttan anlaşılamayacağı için failed/exception/crash sonucu permit'i geri bırakmaz. Reddedilen girişimler ekstra permit üretmez. Pencere dışındaki kayıtlar sayımdan çıkar, audit amacıyla silinmez. Resume bütçeyi sıfırlamaz; mevcut ALL pause limit nedeniyle DESTRUCTIVE'a düşürülmez. Dry-run, observe, handler bulunmaması ve yıkıcı olmayan işlemler permit tüketmez.

Caller Connection veya raw PDO transaction içindeki destructive admission reddedilir. Aksi halde dış etki gerçekleşip caller rollback yaptığında permit silinebilirdi. Bu sınırlama eski çağıran akışların transaction dışındaki güvenli sınırına taşınmasını gerektirebilir. Eksik lock/policy/ledger, policy'nin sonradan değişmesi, permit INSERT ve pause UPDATE hatalarında handler başlamaz. Migration tekrarı mevcut policy/permit/pause'u korur; down migration otomatik audit silmez. Bellek limiter'ının eski record/reset test API'si korunur; üretim factory onu kullanmaz.

## Doğrulama

22 ortak senaryo SQLite ve gerçek MariaDB'de restart, uncertain/returned failure, türler arası global sınır, pause/resume, veritabanı saatiyle expiry ve audit, dry-run/observe/missing handler, advisory-check yarışı, caller/raw PDO rollback, eksik/çelişkili policy/lock/ledger ve gerçek SQL trigger yazım hatalarını sınar. Pre-15 şemadan geçişte pending/version payload ve pause aynen korunur; eksik yeni şemada runtime factory durur. Onaylı ve zamanı gelmiş ertelenmiş destructive akışlar aynı bütçeye uyar; destructive containment altında bildirim yolu çalışır.

MariaDB'de iki ek test, dört ayrı worker'ın kalan tek permit için yarışmasında yalnızca bir handler/simulated effect kaydı ve toplam beş permit ölçer. Birinde callback simulated effect sonrasında response-loss exception üretir; permit tüketilmiş kalır. Ayrı bir bağlantı, handler sırasında committed permit'i ve restart sonrasında aynı sayımı okur. Yeni admission worker native prepared statements kullanır; bütün eski worker yolları native sayılmaz. Simulated effect gerçek sağlayıcı testi değildir.

| Paket | Test | Assertion | Sonuç |
|---|---:|---:|---|
| Ana paket | 1118 | 8684 | Başarılı |
| MariaDB 10.11.18 | 235 | 1566 | Başarılı |
| Toplam | 1353 | 10250 | Failure/error/skip yok |

Ham tam paket kanıtı D02-3m-suite.txt/xml ve D02-3m-mariadb.txt/xml; değişen dosya SHA-256 özetleri D02-3m-file-hashes.json. Kurulum migration beklentileri 14'ten 15'e güncellendi; eski koruma testleri gevşetilmedi ve atlanmadı. Şema envanteri 91 CREATE TABLE kaynak dosyasıdır; metin taraması kabul/çalıştırma kapsamı kanıtı değildir.

## Geçiş ve açık sınırlar

GitHub yayını production deploy değildir; üretim verisi değiştirilmedi. Upgrade sırasında eski worker'lar durdurulmalı, migration 15 kurulmalı ve aynı kod sürümü kullanılmalıdır. Ledger eski kodun belirsiz dış etkilerini geriye dönük uydurmaz. İlk aktive edilişte önceki yıkıcı etkilerin yeni pencereyle çakışmaması için tam 3600 saniyelik durdurma/bekleme ve operatör incelemesi gerekir; sadece migration çalıştırmak eski bir saatin global sınırını ispatlamaz.

Permit satırı admission kanıtıdır, tamamlanmış dış etki kanıtı değildir. Commit sonrası crash kontenjan tüketebilir; provider komutu ile pause arasındaki son yarış ve DB/provider exactly-once problemi çözülmüş sayılmaz. İşlem başlatılabilen eylemler DestructiveActionRegistry'nin mevcut built-in listesiyle sınırlıdır; yeni destructive handler aynı güvenlik sınıflandırmasına eklenmelidir. Doğrudan engine kurulumu/HTTP/CLI/cron girişlerinin tamamı, actor RBAC/tenant, lease/ACK, güvenli retry ve gerçek provider reconciliation açık. Kalıcı ledger retention/privacy/backup ilgili D05/D07 fazlarında tamamlanmalıdır; burada otomatik silme veya reset endpoint'i eklenmedi. Sonraki adım yarım kalan otomasyonun dış sonuç uzlaştırması ve güvenli yeniden yürütme sınırlarıdır.
