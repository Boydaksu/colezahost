# D03.1 — kalıcı özel anahtar dosyası

Referans `e1c37cdbccc70eec4bbedcd8344d79ae5a7e2738`. D02 yeniden açılmadı. D03.1 IN_PROGRESS; production_ready=false; independent_acceptance=PENDING.

Yeni kurulumda tools/provision-application-key.php uygulama dışında özel yerel dizine CSPRNG 32 byte anahtar oluşturur. Dosya exclusive create ile açılır; var olan anahtar üzerine yazılmaz. Dosya boşken erişim korunur, sonra anahtar yazılır/flush/fsync yapılır. Başarısız persist korumalı geçersiz bir dosya bırakabilir; otomatik silme veya yeniden üretme yoktur. Runtime APP_KEY_FILE dosyasını tekrar doğrular. APP_KEY ile birlikte verilmesi reddedilir. Mevcut APP_KEY desteği korunur; runtime yeni key üretmez.

Windows owner mevcut SID, protected inheritance ve yalnız owner/SYSTEM Allow erişimi kontrol edilir. Symlink/reparse ve uygulama içindeki dizin reddedilir. POSIX owner/0700/0600 dalı uygulanmıştır; bu Windows yerel koşusu Linux dalının gerçek OS kabulü değildir. Dosya yalnız sabit boyut sınırında okunur; malformed/weak key reddedilir. Yanıt yalnız domain-separated SHA256 key_id'dir; key stdout/stderr/argümanlara verilmez.

ConfigRepository app config, default SecretCipher, VaultReferenceStore ve ServerService aynı explicit source resolver'ı kullanır. Test ayrı PHP süreciyle dosyadan aynı anahtar kimliğini yeniden açar. CLI overwrite girişimi ilk dosyayı değiştirmez. Güvenli olmayan mevcut dizin otomatik permission repair görmez.

## Güvenlik taraması

İlk ana koşu Windows ACL kontrolündeki proc_open nedeniyle 1 failure verdi. Genel yürütme yasağı kaldırılmadı: tools/reviewed-processes.json yalnız PrivateKeyFile.php'nin incelenmiş tam byte SHA256 özeti için tek proc_open çağrısını kabul eder. Betik statiktir; path environment verisidir, kod interpolasyonu yoktur. Mutlak PowerShell executable, argüman dizisi ve bypass_shell kullanılır; alt sürece key/APP_KEY verilmez. PHP mimari testi ve Python tarama aynı içerik özetini denetler. İçerik değişmesi, başka path/işlev, ikinci çağrı, eksik veya bozuk policy olumsuzları sınanır. Özeti güncellemek ayrıca kaynak incelemesi gerektirir; manifest üretimi inceleme yerine geçmez.

## Gerçek kanıt

Eski config e1c37cd ile file-source regresyonu 1 test/1 failure; eski config çıktısı test sonrası geri yüklenmiştir. Yeni focused 12 test/46 assertion. Preflight main 1590/11218, 1 güvenlik-tarama failure; preflight native 149/1031 pass. Bu ara çıktılar ayrı preflight dosyalarında korundu.

Son freeze üzerinde tam ana paket **1591 test/11223 assertion**; yedi sınıflık affected MariaDB **149 test/1031 assertion**. Failure/error/skip0. Full native suite çalıştırılmış sayılmaz; son full native D02 tarihsel commitine aittir. 26 migration değişmedi. XML/TXT, source freeze, exact discovery ve hash manifest verify-d03-keyfile.py ile doğrulanır. Python process-policy negatifleri ve repository scanner PASS.

## Kalan iş

Bu komut mevcut ciphertext'i yeni anahtara dönüştürmez; şifreli verisi olan kurulum rastgele yeni dosyaya geçirilmemelidir. Kurulum sihirbazının key/database bağlaması, bütün cipher alanlarının tek transaction ile anahtar rotasyonu, eski writer reddi ve restore key eşleşmesi sonraki adımda yapılacaktır. Genel secret/config/log/export denetimi de D03.1 içinde açık. Dosya yedeği DB yedeğinden ayrı ve kısıtlı erişimde tutulmalıdır; dosyanın varlığı recovery kabulü değildir. GitHub yayını deployment değildir.

Kanıt biçimi: policy JSON satır sonları test sonrası CRLF→LF normalize edildi; parsed policy ve bütün executable PHP/Python byte içeriği aynı kaldı. Freeze kaydı bu yalnız biçim değişikliğini belirtir.
