# Uygulama anahtarı bakım sırası

Yeni dosya [provisioning komutuyla](APPLICATION-KEY.md) oluşturulur. Runtime kendiliğinden key üretmez veya dosya değiştirmez. Yeni kurulumda WebInstallerService.setupApplicationKey() veritabanı kurulumu sonrasında çağrılır. Mevcut korumalı dosyaya devam etmek için resumeExisting:true gerekir. SMTP aynı doğrulanmış key'i kullanır. Finalize dosyayı tekrar yükler, DB probe ve bilinen cipher/ref alanlarını doğrular; kaybolmuş/değişmiş dosya veya doğrulanmamış key ile kurulum kilidi yazılmaz. Session/lock key materyali taşımaz. Ortam değişkeninin dağıtımı ve HTTP arayüzünün bağlanması operatör/composition işidir; servis ortamı kendiliğinden değiştirmez.

## Mevcut kurulum ve rotasyon

1. Uygulamayı ve bütün arka plan yazıcılarını durdurun. DB ile **mevcut anahtarın** doğrulanmış yedeğini ayrı tutun. `--maintenance` bunu yaptığınızın açık kabulüdür; süreçleri komut kendisi durdurmaz.
2. Legacy plaintext varsa provenance teyidiyle SecretStorageUpgrade/VaultReferenceUpgrade ve CronSetupService.upgradeLegacyToken() işlemlerini önce uygulayın. Legacy cron tokeni tek yönlü hash'e çevrilir; runtime plaintext fallback yoktur. Bilinmeyen secret encoding/scope otomatik tahmin edilmez.
3. Mevcut APP_KEY/APP_KEY_FILE ortamıyla eski key'i DB'ye bağlayın ve kontrol edin:

```text
php tools/application-key-maintenance.php bind --maintenance
php tools/application-key-maintenance.php verify
```

4. Ayrı korumalı dosyada yeni key'i oluşturun. Eski dosyayı değiştirmeyin veya silmeyin. Mevcut key ortamını koruyarak dönüşümü çalıştırın:

```text
php tools/provision-application-key.php <mutlak-özel-dizin/next.key>
php tools/application-key-maintenance.php rotate <mutlak-özel-dizin/next.key> --maintenance
```

Vault, SMTP, TOTP ve önceki encrypted server alanları/probe/audit **tek DB transaction** ile dönüşür. Owner Vault referansları doğrulanır ve korunur. Cron/authentication hash'leri key'den bağımsızdır. Audit/sonraki yazım hatasında bütün cipher/version/probe geri alınır; yeni dosya durur. Caller transaction ve aynı key'e rotasyon reddedilir. Eski key'le çalışan bound yazıcılar probe lock üzerinden dönüşüm sonrası reddedilir.

5. DB commitinden sonra ortamı yeni APP_KEY_FILE yoluna alın, APP_KEY kaldırın ve süreçleri yeniden başlatın. Yeni ortamla `verify` çalıştırın; sonra yazıcıları açın. DB ve ortam değişimi tek atomik işlem değildir. Aralıkta hata/çökme olursa yazıcıları kapalı tutup zaten kaydedilmiş yeni dosyayı kullanın; rastgele başka key üretmeyin. DB commitinden sonra yalnız eski ortamı geri almak doğru rollback değildir.

## Geri yükleme sınırı

DB yedeği encrypted probe taşır; key dosyası ayrı erişimi kısıtlı yedekte tutulmalıdır. RestoreWizard uygulama key'ini ayrıca alır/ortamdan çözer. Probe ile bütün bilinen ciphertext/ref doğrulanmadan success veya preserved-file copy yapılmaz. Doğru arşiv parolası, doğru uygulama key'i anlamına gelmez. Cipher verisi olan unbound legacy yedek explicit reconciliation ister; nonsecret legacy yedekler desteklenir.

Restore SQL importu key doğrulamasından önce uygulanır ve MySQL DDL geri alınmış sayılmaz. Hatalı restore hedefini izole tutun; sonuç gerçek uygulanmış SQL sayısını taşır. Bu key kapısı bütün restore işleminin atomicity, path güvenliği veya tam modül recovery kabulü değildir; bunlar D05 kapsamındadır. Mevcut dump'ın SQLite literal escaping/MariaDB dump desteği de D05'te ele alınmalıdır.
