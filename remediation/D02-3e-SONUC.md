# D02.3b devamı — sağlayıcı kimliği ve atomik marka kurulumu

Tarih: 11.10.2026. Başlangıç commit: 7fcc124094e8bb12106c6ed53a00a0276f0d2b96. Referans: D02 kurulum matrisi, F03/F16 ve kısmi F23. D02 ve D02.3b IN_PROGRESS; bağımsız kabul PENDING.

## Değişiklik

BrandSetupService ilk organization veya ilk aktif brand satırını artık sağlayıcı kaydı sanmıyor. Migration 8/core initializer tek kilit satırı ve kuruluş/marka/sahip bağını tutan installer_provider_identity tablosunu hazırlıyor. CHECK kuralları yarım tuple ve singleton dışı satırı reddediyor. Önceki migration dosyaları değiştirilmedi; legacy kayıtlar tahminen eşleştirilmedi.

Kurulum aktif bir ownerUserId ve global installer.brand.configure izni gerektiriyor. WebInstaller bunu admin adımının kaydından aktarıyor. RBAC'in yeni hasPermissionFromInitializedSchema metodu mevcut izin tablolarını DDL olmadan okuyor; MySQL transaction içinde güncel kilitli okumayı kullanıyor. Eski hasPermission API'si korunuyor. Diğer RBAC çağrılarının tümü DDL'den arındırılmış veya tam uygulama yetkilendirmesi kabul edilmiş sayılmaz.

Yeni sağlayıcı kuruluşuna gerçek sahip yazılıyor ve owner üyeliği oluşuyor; eski NOT NULL owner_user_id varyantı da çalışıyor. Mevcut müşteri kuruluşları ve başka aktif markalar seçilip değiştirilmez. Kuruluş, marka, owner üyeliği, bağ ve ayarlar aynı transaction'da kalır. Tek bağ satırı üzerinde kilit dört eşzamanlı kurulumu aynı kayıt çiftinde birleştirir. Bağdaki sahip/kuruluş/marka eksik, pasif veya tutarsızsa işlem başka kayda yönelmez ve bağın sahibi sessizce değiştirilmez.

Eski brand ayarları varsa ama kalıcı bağ yoksa işlem açık uzlaştırma ister. bindExistingProvider, yetkili aktif sahip ve açık organization/brand id'leriyle legacy sağlayıcıyı bağlar. Mevcut farklı sahibi veya owner üyeliği olan şirket devralınmaz. Önceden bağlı farklı kimlik yeniden atanmaz. NULL legacy provider owner ancak bu açık işlemde seçilen yöneticiye bağlanır. Bu metodun kullanıcı arayüzü/komut/kimlik bağlamı/audit kabulü D03/D08'de açık; üretim kaydı bağlanmadı.

## Test kanıtı

İlk 5 MariaDB regresyonu **3 API sözleşmesi hatası ve 2 davranış başarısızlığı** verdi (`D02-3e-before.txt`). Üç hata yeni ownerUserId argümanının eski API'de bulunmamasıdır; eski API'nin müşteri kaydını değiştirmesi ayrıca owner argümanı olmayan bağımsız testte satır farkıyla kanıtlandı. Protected Customer adı/slug'ı Provider Company/provider-company olarak değişmişti. Eksik sahipliğin reddedilmemesi de ayrı başarısızlıktı.

| Paket | Test | Assertion | Sonuç |
|---|---:|---:|---|
| Ana paket | 968 | 8.139 | Başarılı |
| MariaDB 10.11.18 | 73 | 583 | Başarılı |
| Toplam | 1.041 | 8.722 | Hata, başarısızlık, atlanan test yok |

9 ortak senaryo SQLite/MariaDB'de çalışıyor: müşteri ve diğer marka satırlarının birebir korunması, tekrar kurulumda aynı id'ler, NOT NULL owner, eksik sahip ve normal kullanıcı reddi, sahipliksiz çağrının müşteri verisine dokunmaması, legacy bağ olmadan durma ve açık id'lerle tekrar bağlama, başka müşterinin şirketini bağlama reddi, settings INSERT trigger hatasında kuruluş/marka/üyelik/bağ/ayar rollback'i ve tekrarın başarısı, migration tekrarında mevcut bağın korunması. MariaDB parent bağlantısı native prepares kullanıyor.

Ek MariaDB testi 4 PHP sürecini bariyerden aynı anda başlatır: hepsi aynı organization/brand id'lerine döner; yalnız bir kuruluş, bir marka ve bir owner üyeliği kalır. Ham son kayıtlar D02-3e-suite.txt/xml ve D02-3e-mariadb.txt/xml; hash'ler D02-3e-file-hashes.json. Üç migration-adedi testi 7→8 güncellendi. Eski direct BrandSetup testine gerçek bootstrap admin ve açık sahip eklendi; sonuç/marka beklentileri korunuyor.

## Kalan kabul

Şema envanteri yeni ProviderIdentitySchema ile 88 CREATE TABLE kaynak dosyası içeriyor. Kalan domainlerin migration bağlantıları, eski veri tabanı kısıtları ve application seviyesinde tablo öneki desteği açık. Caller kimliğinin gerçek HTTP/CLI/automation girişine bağlanması, ince yetki/audit, operational-mode ve ownership transfer kabulü D03/D08'de ayrıca sınanmalı. Bu adım uygulama sınırlarının tamamını güvenli ilan etmez. Yeni migration ve legacy bağlama üretim veri tabanına uygulanmadı. Üretime hazır veya bağımsız PASS sonucu verilmedi.
