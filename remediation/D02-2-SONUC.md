# D02.2 — Ödeme, bakiye, token ve kuyruk eşzamanlılığı

Tarih: 09.10.2026. Uygulama: IMPLEMENTATION_COMPLETE. Bağımsız kabul: PENDING. D02 fazı devam ediyor; sonraki adım D02.3.

## Sonuç

Ödeme kaydı, onay/red, tahsis, fatura bakiyesi, yerel iade ve kredi işlemleri transaction içinde yürütülüyor. MariaDB'de değiştirilecek kayıtlar FOR UPDATE ile güncel veri üzerinden okunuyor; kredi bakiyesi kullanıcı/organizasyon/para birimi kapsamındaki kalıcı kilitle sıralanıyor. Fatura fazla/negatif ödeme ve fazla/negatif iade kabul etmiyor. Başka müşterinin veya organizasyonun faturasına tahsis reddediliyor. Bir adım başarısız olursa bağlı finans kayıtları geri alınıyor.

Token araması artık metadata taramıyor. SHA-256 özeti UNIQUE sütunda saklanıyor; metadata içindeki tam token ayrıca doğrulanıyor. İkinci token ataması ve mevcut token'ın değiştirilmesi reddediliyor. 100 ödeme arasındaki lookup 3 sorguyla tamamlandı; SQLite ve MariaDB sorgu planlarında indeks kullanımı doğrulandı.

Callback, sağlayıcı doğrulamasından sonra benzersiz olay kaydını transaction içinde sahipleniyor. Ödeme/tahsis/fatura ve olayın işlenmiş durumu aynı commit ile kaydediliyor. Olay kimliğinde token'ın özeti kullanılıyor; uzun token doğrudan VARCHAR event_id alanına yazılmıyor. Yarışan callback'ler tek olay ve tek tahsis oluşturuyor.

Kuyruk rezervasyonu her alımda rastgele bir lease token üretir. Aktif kaydı alma işlemi MariaDB'de FOR UPDATE SKIP LOCKED ve koşullu UPDATE ile yapılır. Delete/retry/DLQ ancak mevcut token sahibi tarafından yapılabilir. Süresi dolup yeniden alınan işin eski çalışanı yeni rezervasyonu silemez veya başarısız iş kuyruğuna taşıyamaz. Bu, işin dış etkilerinde genel bir exactly-once garantisi değildir; uzun işler/lease süresi ve sağlayıcı eylemlerinin idempotency ölçütleri D06'da ayrıca ele alınacak.

## Dış iade ve muhasebe

Gateway iadesinde rezervasyon **sağlayıcı çağrısından önce ayrı commit** edilir; ağ isteği sırasında veri tabanı transaction'ı açık tutulmaz. Processing/unknown/verified rezervasyon varken yeni sağlayıcı veya manuel iade reddedilir. Böylece küçük bir belirsiz iadenin tekrarları kalan bakiye üzerinden yeniden gönderilemez.

Kesin sağlayıcı reddi rezervasyonu serbest bırakır. İstisna, eksik kesin hata kodu veya uyuşmayan cevap bilinmeyen sonuç sayılır; rezervasyon korunur ve mutabakat gerekir. Sağlayıcı başarı referansı yerel muhasebe güncellenmeden önce saklanır. Yerel hata sonrası `applyReservedGatewayRefund` doğrulanmış rezervasyonu ağ çağrısı yapmadan uygular; tekrar çağrı aynı refund kaydını döndürür. Unknown kaydı bu yöntemle doğrulanmış sayılmaz. Operatör mutabakat ekranı ve ayrıntılı RBAC D03/D06 kapsamında henüz tamamlanmadı.

İade yalnız bu ödemenin faturalara tahsis edilmiş kısmını azaltır; tahsis edilmemiş ödeme iadesi başka ödemenin fatura kredisini silmez. Kısmi iadeler tahsis ID sırasına göre tutarlı uygulanır. Bu adım gerçek iyzico sandbox kabulü değildir; sağlayıcı yanıtları kontrollü test doubles ile üretildi.

## Migration ve uygulama sınırı

Yeni migration: `database/migrations/2026_10_09_000002_payment_concurrency.php`. Eski metadata token'larını indeksli sütuna taşır; olay benzersizliği, ödeme/tahsis/iade indeksleri, iade rezervasyonları, kredi kapsam kilitleri ve queue reservation_token alanını ekler. Mükerrer eski token veya olayları sessizce birleştirmez/silmez: mutabakat gerektiren açık hata verir. SQLite geçişi transaction içinde; MariaDB DDL kısmen uygulanabilir ve sürümlü runner başarısız geçişi tamamlandı kaydetmez.

**Dağıtım sırası:** çalışan eski sürüm worker'larını durdur/drain et → doğrulanmış yedek al → migration uygula → yeni kodla worker'ları başlat. Yeni lease sözleşmesi eski id-only delete yapan worker ile eşzamanlı kullanılmamalı. Migration eski, tokensız rezervasyonları serbest bırakır. Üretim veri tabanında migration çalıştırılmadı; tüm deneyler ayrı yerel veri tabanlarında yapıldı.

Ödeme/fatura/kredi/queue schema initializer'ları MariaDB uygulama transaction'ı içinde çağrılırsa DDL ile implicit commit oluşturmak yerine hata verir. Connection, veri tabanı tarafından geri alınmış transaction sonrasında asıl hatayı korur ve sonraki transaction'ın kullanılabilmesini sağlar.

## Test kanıtı

İlk 6 regresyon düzeltme öncesi 6 başarısızlık verdi: onay/tahsis geri dönüşü, kredi geri dönüşü, fazla ödeme, iade geri dönüşü ve eski worker'ın delete/DLQ işlemleri. Kanıt: `D02-2-before.txt/xml`.

Son doğrulama:

| Paket | Test | Assertion | Sonuç |
|---|---:|---:|---|
| Ana paket | 940 | 8.035 | Başarılı |
| Gerçek MariaDB 10.11.18 | 26 | 222 | Başarılı |
| Toplam | 966 | 8.257 | Hata, başarısızlık, atlanan test yok |

MariaDB'de dört bağımsız PHP işlemi bariyerle aynı anda başlatıldı. Aynı ödeme bir kez tahsis edildi; dört adet 6.000'lük ödeme 10.000'lik faturayı aşamadı; kredi 10.000'den yalnız bir kez 6.000 düşüldü; kredi→fatura işlemi iki bakiyeyi korudu; paralel iade ödeme tutarını aşamadı; yarışan gateway iadeleri tek sağlayıcı isteği yaptı; dört callback bir processed/üç duplicate ve tek olay oluşturdu; 32 queue işi 32 benzersiz ID ile alındı. Önceki 256 benzersiz sıra numarası testi de pakette çalıştı.

SQLite hata enjeksiyonları callback olay kaydı, ödeme, tahsis, fatura, kredi ve iade geri dönüşlerini; unknown iade blokajını; başarı referansından ağsız/idempotent devam etmeyi ve çoklu fatura tahsislerini kapsıyor. Token migration testleri eski sütun eklenmesini, backfill'i, mükerrer kayıtta veri korunmasını ve sorgu bütçesini doğruluyor.

Ham son kanıt: `D02-2-suite.txt/xml`, `D02-2-mariadb.txt/xml`; dosya hash'leri `D02-2-file-hashes.json`. `run-d02-tests.ps1` artık adım adına göre ayrı kanıt dosyası üretir; D02.1 kanıtı korunmuştur. Test sunucusu iş sonunda düzgün kapatıldı.

## Kalan işler

F06 token indeksi/benzersizlik uygulandı; bağımsız kabul açık. F16'nın ödeme/tahsis, fatura, kredi, yerel/dış iade rezervasyonu, queue claim/ack ve webhook kısmı ele alındı. Kapasite rezervasyonu ve kalan domain işlem yarışları D02.3'te açık. İş akışı yetkileri, gerçek sağlayıcı mutabakatı, uzun iş lease yönetimi ve tamamlanmış ödeme sonrası hizmet yenileme idempotency'si ilgili D03/D04/D06 fazlarında açık.

D02.3: kalan MariaDB SQL/DDL uyumsuzlukları, kapasite rezervasyonu, geniş kurulum/migration kabulü. Sistemin tamamı üretime hazır veya bütün bulgular kapanmış sayılmaz.
