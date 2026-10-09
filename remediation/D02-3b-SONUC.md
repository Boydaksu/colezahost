# D02.3b — belge kalıcılığı ve WHMCS üyelik SQL düzeltmeleri

Tarih: 09.10.2026. Referans: orijinal master plan, bağımsız inceleme F02/F03/F16 ve CR/TCR-D02.3b. **D02.3b devam ediyor; D02 tamamlanmadı.** Bu rapor ilk uygulamanın kapsamını kaydeder; geniş kurulum kabulünün yerine geçmez.

## Uygulanan değişiklikler

- Teklif, proforma, belge sayacı ve bildirim duyurusu tabloları MariaDB ve SQLite için ayrı sürücü sözleşmesiyle oluşturuluyor. Çoklu CREATE yerine ayrı ifadeler kullanılıyor. MySQL uygulama transaction'ı içinde şema kurma girişimi bağımlılıklar çalışmadan reddediliyor; implicit commit ile başka işlem verisini yanlışlıkla kalıcılaştırmıyor.
- DocumentNumberGenerator mevcut tenant/type/year benzersiz anahtarında atomik upsert yapıyor. MariaDB bağlantıya özel sayaç sonucunu kullanıyor; ilk INSERT'in otomatik kayıt kimliği sayaçla karıştırılmıyor. SQLite yazma ve sayaç okuması aynı transaction içinde; çağıranın açık transaction'ını commit etmiyor. Eski sayaç sıfırlanmıyor. Numara ayrıldıktan sonra belge yazımı başarısız olursa sıra boşluğu olabilir; bu çalışma kesintisiz yasal numaralandırma iddiasında bulunmaz.
- Dördüncü production migration eksik belge/kalem/duyuru tablolarını tamamlıyor. Mevcut kayıtları ve sayaçları yeniden yazmıyor. Aynı migration doğrudan tekrar uygulandığında veri değişmiyor. Geri alma veri düşüren DROP çalıştırmak yerine doğrulanmış yedek gerektiriyor.
- WHMCS alt hesap üyelik INSERT'i sürücüye uygun benzersiz anahtar çatışmasıyla çalışıyor. Diğer SQL hataları sessizce yutulmuyor. Daha önce oluşmuş kullanıcı için eksik üyelik tamamlanıyor; mevcut üyelik rolü ve kullanıcı adı korunuyor.

## Test kanıtı

Düzeltme öncesi belgede 8 hata (`D02-3b-before.txt`), WHMCS'de 1 hata (`D02-3b-whmcs-before.txt`) gerçek MariaDB üzerinde kaydedildi. İlk numaralandırma uygulamasındaki surrogate-id karışıklığı da tenant izolasyonu beklentisiyle yakalandı ve düzeltildi; beklenti gevşetilmedi.

| Paket | Test | Assertion | Sonuç |
|---|---:|---:|---|
| Ana paket | 953 | 8.062 | Başarılı |
| MariaDB 10.11.18 | 47 | 389 | Başarılı |
| Toplam | 1.000 | 8.451 | Hata, başarısızlık, atlanan test yok |

Yeni MariaDB senaryoları: gerçek PDO kaydı ve ayrı bağlantıdan Türkçe içerik okuma; proforma tutar/kalem kalıcılığı; duyuru yayınlama; dört PHP işleminin bariyerden aynı anda 256 numara üretmesi ve eski 40 sayacından 296'ya ilerlemesi; tenant/type izolasyonu; dört şema girişinde transaction korunması; altı tablonun bütün satırlarının tekrar migration öncesi/sonrası aynılığı; yarım şema tamamlama; teklif ve proforma item INSERT'ine MariaDB trigger ile hata enjekte edilince parent/kalem bırakılmaması ve tekrarın başarısı; WHMCS mevcut/yeni alt hesap, tekrar aktarım ve rol korunması. Belge testlerinde native prepared statements kullanıldı. SQLite sayaç testleri çağıran transaction'ın açık kalmasını, rollback'i ve eski sayaç/tenant/type/year ayrımını doğruladı.

Ham son kanıt: `D02-3b-suite.txt/xml`, `D02-3b-mariadb.txt/xml`. İki mevcut migration testi yeni dördüncü migration nedeniyle adet 3→4 olarak güncellendi; eski veri/izin koruma beklentileri değiştirilmedi. Kaynak hash'leri `D02-3b-file-hashes.json` içinde. Üretim veri tabanına migration uygulanmadı.

## Sıradaki iş ve açık sınırlar

D02.3b içinde geniş fresh-install/upgrade matrisi ve kurulumun ortak şema sözleşmesi tamamlanacak. WebInstallerService halen yalnız initializeCoreSchema çağırıyor; eklenen domain migrationlarının sihirbaz akışında çalışması ayrıca bağlanmalı. Installer, organization/user/domain servislerinin tablo sözleşmeleri birlikte sınanmalı; tablo öneki desteği gerçek domain kullanımına göre değerlendirilmeli.

SystemDoctorService halen tanılama sırasında eksik/yanlış tablolar oluşturuyor (background_jobs, servers, installed_modules); cron ran_at ile installer run_at ayrışıyor. Bunlar veri kaynağı doğru seçilerek düzeltilmeli; yalnız AUTO_INCREMENT değişimi F26'yı kapatmaz. GoldenFinancialDataset'in SQLite'e özel ve üretimden farklı tabloları kanonik şema kabul edilmemeli (F17/F30, D04/D07).

Tenant bazlı sayaç aynı görünen belge numarasını farklı tenant'larda üretebilirken quotes/proforma_invoices numara alanları global UNIQUE. Bu eski sözleşme çelişkisi hâlâ açık; belge kimliği ve erişim sözleşmesiyle birlikte kabul matrisi içinde giderilmeli. Bu testler farklı tenant belgelerinin tam oluşturulması için kabul kanıtı değildir. Diğer belge dönüşüm/onay işlemlerinin eşzamanlılığı, güvenli WHMCS parola/izin aktarımı ve yetkilendirme de F16/F23 ve ilgili D03/D04/D07 kabulünde açık. Nihai bağımsız kabul PENDING, üretime hazır sonucu verilmedi.
