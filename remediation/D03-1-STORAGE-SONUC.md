# D03.1 — SMTP, sunucu ve TOTP şifreli saklama

Başlangıç `35fc60a85b558c42339b7cb695f085e65e2f8d46`. Üç özgün düz metin saklama açığı yerel olarak giderildi. D03.1 hâlâ IN_PROGRESS; production_ready=false ve independent_acceptance=PENDING.

SecretCipher domain/Vault içinde ortak Encryptor AES-256-GCM kullanır. enc:v1: payload aynı prefix/hostname/user_id context'inde açılır; başka sunucu/kullanıcıya kopyalama authentication error verir. Ciphertext prefix'i doğrulanmadan eski değer plaintext olarak okunmaz. Encryptor'ın eski boş-context Vault ciphertext sözleşmesi korunur; mevcut vault_secrets kayıtları bu adımda değiştirilmez.

EmailSetupService SMTP password'ı seal edip bütün ayarları tek transaction/savepoint içinde yazar. Yanıt maskelenir; readConfiguration transport için plaintext'i yalnız authenticated decode sonrası verir. ServerService create/update seal, model read open kullanır; null password removal desteklenir ve model serialization'ın eski maskelemesi korunur. Hostname context'i sabittir; veritabanında elle hostname değiştirmek yeniden seal gerektirir. TwoFactorService setup ciphertext saklar, doğrulama kilitli current state'i decode eder; secret/QR yalnız mevcut onboarding yanıtında bir kez görülebilir. Eksik key setup'ta SQL/DDL başlatamaz.

SecretStorageUpgrade.upgrade(legacyPlaintextConfirmed:true) **unprefixed canonical** system_settings/servers/user_two_factor alanları için açık bakım API'sidir. Installed columns ve MariaDB InnoDB ön koşulu; current row locks; bütün conversion planını doğruladıktan sonra tek transaction'da update. Caller raw/managed rollback'e katılır; geç SQL failure üç grubu birlikte geri alır. Tekrarında doğru key'le mevcut envelope doğrulanır ve0 değişiklik verir. Yanlış key, bilinmeyen enc: version, teyitsiz plaintext veya128 byte TOTP sütununu aşacak ciphertext hiçbir kaydı değiştirmez. Diğer bütün tarihsel şema varyantlarının compatibility kabulü değildir. SQLite schema uzunluğu otomatik uygulamadığı için size guard iki sürücüde de explicit'tir.

**Bakım sınırı:** mevcut DB yedeği ve orijinal key korunmalı; writer süreçleri durdurularak provenance doğrulanmalı ve upgrade uygulama sürümü değişmeden önce çalıştırılmalıdır. Plaintext=true bayrağı eski değerin gerçekten plaintext olduğu operatör bilgisidir; is_encrypted=1 etiketi kanıt sayılmaz. Runtime yeni key üretmez ve eski ciphertext'i plaintext sanarak kurtarmaya çalışmaz. Endpoint/CLI/installer upgrade admission ve key rotation/recovery bağları sonraki D03.1 işidir. Bu commit deploy değildir.

## Kanıt

Eski kodla ilk3 SQLite regresyonu3 failure verdi. Gelişim main1546/11057, focused12/27 ve preflight main1560/11083 nihai kapsamdan önceki başarılı koşulardır. İlk native130/8851 error: bağımsız provisioning fixture worker ServerService'e test key injection eksikti. Worker explicit sentetik key'e bağlandı; production fallback eklenmedi ve beklentiler gevşetilmedi. Sonrasında uzun legacy TOTP size guard/regresyonu eklendi.

Son kaynak freeze ile **main1561/11084**, **native131/895**. Native altı sınıf: SecretStorageTest, IdentityLifecycleTest, ProvisioningProbeSafetyTest, CapacityAndNotificationTest, ProviderVaultSchemaTest, ProviderBrandSetupTest. Exact filtered discovery kimlikleri çalışma ile birebir; fail/error/skip0. Toplam seçili koşular 1692 test/11979 assertion. Bütün native suite çalıştırılmış sayılmaz; önceki tam D02 kanıtı kendi commitindedir. Eski fixture'lara yalnız explicit sentetik key dependency eklendi; diğer davranış assertions korundu. Önceki26 migration byte olarak aynı.

Restart/read, yanlış key, kopyalanmış ciphertext, unknown version, plaintext runtime rejection, provenance, replay, raw/managed caller rollback, late update/SMTP failure, query log'da sentetik plaintext bulunmaması, InnoDB ve TOTP column capacity ölçülür. Bu query log kontrolü bütün logger/export yollarının kabulü değildir.

## Aynı D03.1 paketinde açık kalanlar

Security Constitution provider/API/webhook sırları için Vault ister. WHM şu an kendi encrypted field'ında SecretCipher kullanır; vault_secrets reference/audit hattına taşınmış sayılmaz. Provider/API/webhook Vault birliği ve diğer secret storage yollarının audit'i; installer key provisioning ve rotation/recovery; bütün config/log/export redaction denetimi açık. F09'un bütün ürün genelinde kapandığı veya D03.1 bittiği iddia edilmez.
