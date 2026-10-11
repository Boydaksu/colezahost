# D02.3b devamı — eski kimlik kolonlarının güvenli geçişi

Tarih: 11.10.2026. Başlangıç commit: c62cccf8daabb50d9756e312b6c80de5b77415a3. Referans: F03, D02 kurulum matrisi, CR/TCR-D02.3b devamı. D02 ve D02.3b IN_PROGRESS; bağımsız kabul PENDING.

## Uygulama

LegacyIdentitySchema bilinen Auth/WHMCS ve eski installer kullanıcı/organizasyon kolon düzenlerini additive ALTER ile tamamlar. CREATE IF NOT EXISTS mevcut kolonları tamamlamadığı için initializeCoreSchema bu ortak geçişi kullanır. Migration 7, ilk altı migrationı geçmiş kurulumlarda da kolon geçişini uygular; önceki migration kaynakları değiştirilmedi.

Mevcut kimlik, email, parola hash'i, ad, tarih, şirket, sahip ve üyelik/rol kayıtları korunur. Eksik first_name/last_name alanları eklenir; mevcut tam ad tahminen bölünmez. name yoksa mevcut ayrı ad/soyad birleştirilir. Yeni bilinmeyen tarih alanlarına eski bir tarih uydurulmaz. Organizasyonda eksik billing_currency için kanonik USD varsayılanı eklenir; mevcut para birimi alanı değiştirilmez ve finans tutarı/para birimi çevrilmez.

Eksik status, is_active değerinden; eksik is_active, yalnız açık active statüsünden türetilir. NULL değerler güvenli biçimde tamamlanır. Bilinen suspended/inactive statüsü is_active=1 ile çelişiyorsa aktif flag 0'a çekilir; mevcut suspended statüsü korunur. Erişim durumunu belirleyen iki kolonun da olmadığı ya da temel kimlik kolonlarının eksik olduğu tablo, core CREATE/ALTER başlamadan açık hata verir. Geçiş parola üretmez ve belirsiz hesabı açmaz. MySQL uygulama transaction'ı içinde initializeCoreSchema DDL çağrısı da reddedilir.

AdminBootstrap yeni yönetici için name alanını açıkça yazar; legacy NOT NULL name tablosunda INSERT çalışır. Açık yönetici bootstrap talebi hedef hesabın name/first/last, status/is_active ve güncelleme zamanını tutarlı yazar. Bu işlem migrationdan ayrıdır; migration diğer kullanıcıların parolasını veya rolünü değiştirmez. Yetki/kurulum kilidi ve bootstrap transaction kabulünün tümü D03'te hâlâ açık.

## Test kanıtı

İlk dört MariaDB regresyonu düzeltmeden önce dört başarısızlık verdi (`D02-3d-before.txt`); eksik status/is_active/name kolonları PHP uyarılarıyla da görünür oldu. Test beklentileri gevşetilmedi. Hata bekleyen unknown-schema testi exception yakalamayı assertion dışında yapar; PHPUnit'in RuntimeException tabanlı assertion hatası başarı diye yakalanamaz.

| Paket | Test | Assertion | Sonuç |
|---|---:|---:|---|
| Ana paket | 959 | 8.106 | Başarılı |
| MariaDB 10.11.18 | 63 | 517 | Başarılı |
| Toplam | 1.022 | 8.623 | Hata, başarısızlık, atlanan test yok |

Ortak senaryolar SQLite ve MariaDB'de aynı beklentilerle çalıştı: eski kullanıcı ve şirket kolonlarının birebir korunması; üyelik/rol korunması; pasif flag'den inactive statüsü; eski status-only suspended kaydında pasif flag ve ad birleştirme; legacy NOT NULL name üzerinde yeni admin; pasif hesabın gerçek AuthService authenticate yolunda reddi; conflicting status/flag'de hesabın kapanması; bilinmeyen erişim düzeninde hiçbir tablo/kolon eklenmemesi.

Trigger ile ilk UPDATE'e hata enjekte edilen migration 7'de kullanıcı pasif kaldı. MariaDB'nin kalıcılaşmış kısmi DDL'si üzerinden tekrar ve SQLite transaction rollback sonrasında tekrar geçiş tamamlandı; eski hash/ad korundu. MariaDB core initializer application transaction'ını commit etmedi. Ham son kanıt: `D02-3d-suite.txt/xml`, `D02-3d-mariadb.txt/xml`; hash'ler `D02-3d-file-hashes.json`.

Üç migration-adedi testi 6→7 güncellendi; veri/izin koruma beklentileri korunuyor. G06 recovery fixture'ına gerçek password_hash kolonu ve fixture hash'i eklendi; parolasız sahte user tablosunu desteklemek için production preflight gevşetilmedi. G06'nın gerçek backup/restore güvenilirliği bu fixture değişimiyle kapanmış sayılmaz (D05/F11/F12).

## Kalan kapsam

`D02-SCHEMA-INVENTORY.json` 87 CREATE TABLE kaynak dosyasını, görünür şema girişlerini ve doğrudan migration metin referanslarını listeler. Bu statik envanter çalıştırma veya uyumluluk kanıtı değildir; domainler aynı tabloya farklı kolon sözleşmeleriyle bakabilir. Kalan domainlerin kalıcılık/migration bağını ve eski tablo kısıtlarını bu liste üzerinden tek tek sınamak gerekiyor.

Bilinen kolon düzenlerinin geçişi yerel testte geçti; mevcut VARCHAR boyutu, NULL/default, unique/FK/index sözleşmeleri yeniden yazılmadı ve tüm eski varyantlar desteklenmiş sayılmaz. App seviyesinde tablo öneki desteği açık. BrandSetup'ın mevcut ilk müşteri şirketini provider kuruluşu sanması ve legacy NOT NULL owner alanına yeni provider kaydı yazması ayrıca düzeltilmeli; bu kolon migrationı brand/ownership kabulünün yerine geçmez. Diğer domain kurulumları, erişim/yetki, gerçek sağlayıcı ve üretim upgrade/rollback kabulü hâlâ açık. Üretim veri tabanına geçiş uygulanmadı. D02 bitmeden D03'e veya production_ready=true sonucuna geçilmeyecek.
