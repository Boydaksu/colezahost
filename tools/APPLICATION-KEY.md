# Kalıcı uygulama anahtarı

Yeni kurulum için uygulama dizini dışında, yalnız bu anahtarlara ayrılmış yerel bir dizin seçin. Dizinin üst dizini mevcut olmalıdır. Komut anahtar dosyasını yalnız ilk kez oluşturur; var olan dosyayı değiştirmez. Anahtar çıktıya veya komut satırına yazılmaz; yalnız kimlik özeti döner.

```text
php tools/provision-application-key.php <mutlak-özel-dizin/application.key>
```

Çalışan uygulamanın ortamında `APP_KEY_FILE` değerini bu mutlak dosya yoluna ayarlayın, `APP_KEY` değerini kaldırın ve süreçleri yeniden başlatın. İki kaynak aynı anda verilirse uygulama reddeder. Mevcut `APP_KEY` yapılandırması desteklenmeye devam eder. Runtime anahtar üretmez; eksik veya güvensiz dosyada çalışmayı durdurur.

**Şifreli verisi olan kurulumda bu komutun ürettiği yeni anahtara geçmeyin.** Bu komut veri dönüşümü veya anahtar rotasyonu yapmaz. Mevcut anahtarı kaybetmeyin. Anahtar rotasyonunda bütün şifreli alanların eski anahtarla okunup yeni anahtarla birlikte dönüştürülmesi gerekir; bu işlem D03.1'in sonraki adımıdır. Kurulum sihirbazı ve geri yükleme akışına bağlama da henüz tamamlanmamıştır.

POSIX sistemlerinde sahiplik ve dizin `0700`/dosya `0600` izinleri doğrulanır; PHP POSIX desteği gerekir. Windows'ta sahip mevcut hesap olmalı, kalıtım kapalı olmalı ve yalnız bu hesap ile SYSTEM için erişim bulunmalıdır. Yeni dizin/dosya bu kurala göre korunur; mevcut bir dizinin izinleri kendiliğinden değiştirilmez. Windows PowerShell ve PHP süreç başlatma/iconv desteği gerekir; kontrol çalışmıyorsa dosya kabul edilmez. Windows PHP'nin `fileperms()` değeri ACL yerine kullanılmaz. Symlink/reparse noktaları reddedilir. Ağ diskleri desteklenmez.

Windows izin kontrolü tek, sabit PowerShell betiğini doğrudan argüman dizisiyle çalıştırır. Dosya yolu ortam verisidir; betiğe eklenmez. Alt sürece anahtar veya APP_KEY iletilmez. `reviewed-processes.json` bu kaynak dosyanın SHA256 özetini sabitler. Kaynak değişikliği istisnayı geçersiz kılar. Tarama başka dosyaları, başka çalıştırma işlevlerini ve ikinci bir süreç çağrısını reddeder. İstisna özeti yenilenmeden önce değişiklik ayrıca incelenmelidir.

Anahtar dosyasını veritabanı yedeğinden ayrı, erişimi kısıtlanmış bir yedekte saklayın. Anahtarı Git'e, loglara veya kurulum özetlerine eklemeyin. Dosyanın varlığı kendi başına geri yükleme doğrulaması değildir; veritabanı ile anahtar eşleşmesi sonraki yaşam döngüsü çalışmasında doğrulanacaktır.

Yerel testler Windows izin kontrolünü gerçekten çalıştırır. POSIX dalının gerçek Linux sisteminde kabulü ayrıca gereklidir; Windows koşusu bunu kanıtlamaz.
