# D03.1 — açık uygulama anahtarı koruması

Referans `0d2e3e08d4c121a146f298f77b4bc6b008b6f66e`; F08'in runtime fallback açığı giderildi. D03.1 ve F08'in bütün lifecycle kabulü henüz kapanmadı; F09 sırların depolanması açık.

CoreServiceProvider app.key yoksa ortak bilinen anahtarı kullanmaz. config/app.php APP_KEY ortam değerini okur; ortamda yoksa null kalır. ApplicationKey.encryptor boş/non-string, base64 decode hatası,32 byte dışı veya tek bayt tekrarından oluşan anahtarı reddeder. Bu kontrol her zayıf anahtarı matematiksel olarak saptamaz; kurulum başına CSPRNG32 byte gereklidir. ApplicationKey.generate bu üretimi sağlar; uygulama açılışında otomatik çağrılmaz. Encryptor primitive'i açık fixture/maintenance anahtarlarını kabul etmeye devam eder; canlı container policy ApplicationKey üzerinden uygulanır.

Aynı explicit anahtarla yeni container mevcut ciphertext'i okur; farklı anahtarla authentication failure verir. Key/plaintext parametreleri SensitiveParameter ile stack argümanlarında korunur; hata mesajı key değerini yazmaz. Bu, configuration dump/export veya bütün logger yollarının sır sızdırmadığı kabulü değildir. Eksik key Vault resolution'ını SQL/DDL başlamadan durdurur. Mevcut ciphertext ve migration dosyaları değiştirilmedi.

Eski kodda ilk11 test13 assertion4 failure: raw/encoded bilinen c32, tekrarlı a32 ve APP_KEY env binding. Focused gelişim koşusunda synthetic-confidential-short-key fixture'ı yanlışlıkla32 byte olduğundan negatif test başarısızdı; geçersiz olduğu açık21 byte fixture ile düzeltildi. Genel geçerli32 byte kabulü bu failure'ı gizlemek için değiştirilmedi. Sonradan absent config, failure-message redaction ve no-database-effects testleri eklendi.

Son ana paket **1543 test/11049 assertion**; gerçek MariaDB etkilenen ProviderVaultSchemaTest+ProviderBrandSetupTest **22 test/213 assertion**, failure/error/skip0. Native2 sınıf koşusu tam703 test yerine sayılmaz; son tam native kanıtı önceki D02 commitindedir. F08 anahtar davranışları container üzerinde SQLite herhangi bir DB etkisi olmadan ölçülür; native kontrol mevcut Vault/provider kalıcılığına regresyon kontrolüdür.

## Anahtar ve mevcut kurulum sınırı

Yeni kurulumda ApplicationKey.generate sonucu özel deployment secret store içinde kalıcı tutulmalı ve APP_KEY olarak verilmelidir. Oluşturulan değer log, Git, HTML veya normal config export'a eklenmemelidir. Installer'ın otomatik güvenli provisioning bağını bu commit uygulamadı.

Geçerli mevcut key'i koruyun. Eksik/ortak key ile eski ciphertext varsa rastgele yeni key ayarlamak eski veriyi okunamaz yapar; uygulama bu durumda güvenli biçimde durur. Eski DB ve bilinen/orijinal key korunarak offline doğrulanmış yeniden şifreleme/rotation, kesinti geri dönüşü ve restore testi D03.1'de yapılacaktır. Bu commit veri silmez, key rotation veya legacy ciphertext migration yapmaz. GitHub gönderimi üretim deploy'u değildir.

Aynı paket içinde sıradaki somut iş: SMTP/system_settings, servers.auth_secret_encrypted ve user_two_factor.secret kayıtlarının gerçek şifreli storage hattı ile okunup yazılması; eski kayıtların açık ve atomik geçişi; installer key ve log/export sınırlarının tamamlanması. D03.2'ye geçilmedi.
