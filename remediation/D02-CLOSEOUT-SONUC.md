# D02-CLOSEOUT — yerel uygulama kapanışı

D02'nin ilk dört çıkış ölçütü karşılandı; aktif faz D03. Bağımsız ürün kabulü PENDING, production_ready=false. Başlangıç `577438873305ca987f58e954dace44c2227917de`.

Fatura kaydı numara/başlık/kalemleri tek transaction'da saklar. İkinci kalem ve JSON hatası önceki yazımları geri alır. Mevcut raw/managed caller commit edilmez; başarılı işlem caller rollback'e katılır. InnoDB olmayan invoice storage reddedilir. Ortak Connection.transaction raw caller için rastgele savepoint kullanır; kaybolmuş managed işlem mutasyon ve yeni işlem başlatamaz, teşhis okumaları kullanılabilir. Üretim migration dosyalarının26'sı da byte olarak değişmedi.

İlk10 SQLite regresyonu eski kodda2 error/6 failure verdi (`before`). Gelişim `unit`12/41 ve native-focused13/69 koşuları tam kabulün yerine geçmez. Ek native c sınıfının ilk denemesinde katalog explicit setup ve zorunlu telefon fixture'ı eksikti; `native-c-development` başarısız koşu ayrı korundu. Production schema yöntemi ve geçerli telefon girdisiyle düzeltildi; beklentiler gevşetilmedi, katalog baseline migration'a eklenmiş gibi gösterilmedi.

Son kaynakla bütün ana paket **1529 test / 11030 assertion**. Bütün native keşfi **703 test / 5399 assertion**,40 sınıf. a/b/c koşuları 396+305+2 test olarak ayrık ve tamdır; c yalnız yeni SQL sınıfıdır, diğer native sınıfları ikinci kez sayılmaz. Toplam **2232 test / 16429 assertion**; failure/error/skip0. Native a/b başladıktan sonra production kodu değişmedi; ek test sınıfı c'de ayrı çalıştırıldı. Freeze, keşif, ham XML/TXT, coverage JSON ve dosya hash manifesti `verify-d02-closeout.py` ile karşılaştırılır.

Fresh-install→login→permission→invoice→payment→replay çapraz senaryosu, ikinci settlement'ta tek allocation, eski veri korunması, caller rollback, geçersiz JSON ve MyISAM reddi doğrudan kapanış testlerinde ölçülür. İlk SQL bulgusundaki domain fiyat/kişi güncellemesi native prepared sorguyla ayrıca çalıştırıldı. Önceki gerçek dört worker sequence/financial/credit/refund/webhook/queue/capacity testleri tam native paket içinde yeniden geçti.

F03'ün bütün ürün modül başlangıcı, F16'nın bütün aktör/sağlayıcı/domain işlemleri, gerçek HTTP/UI, uzak sağlayıcılar ve backup/restore kabulü bu sonuca dahil değildir. DomainCatalogService açık modül setup'ı kullanır;26 baseline migration katalog tablolarını kurmaz. Bu ve sonraki işler kendi fazlarında `D02-CLOSURE.md` / `D03-PLAN.md` ile açık tutulur. Tarihsel paketlerin eski state/hash kanıtı kendi Git commitinde doğrulanır; güncel worktree için bu paket geçerlidir.
