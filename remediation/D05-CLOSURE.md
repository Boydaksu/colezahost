# D05 — yerel uygulama kapanışı

16.10.2026. **IMPLEMENTATION_COMPLETE_LOCAL_VERIFIED**; beş sabit adım tamamlandı. Bağımsız kabul PENDING; production_ready=false.

| Adım | Yerel sonuç | Kanıt |
|---|---|---|
| D05.1 | Doğrulanmış yedek teslimi ve kopya/retention güvenliği | D05-1-SONUC.md |
| D05.2 | Gerçek yerel HTTPS/S3 ve SSH/SFTP aktarımı | D05-2-SONUC.md |
| D05.3 | Consistent DB/files dump ve offline restore/key/privacy gate | D05-3-SONUC.md |
| D05.4 | İmzalı güncelleme, gerçek migration, staged yayınlama ve açık rollback; 4/4 | D05-4-CLOSURE.md |
| D05.5 | İmzalı private extension, izin/uyumluluk, declarative migration, health/enable, scoped API ve recovery | D05-5-SONUC.md |

Final ana 2155/13497, ilgili 14 MariaDB sınıfı 207/1608; hata/atlama yok. Tam native suite değildir. Canonical26 migration aynı. Source/evidence hash denetimi verify-d05-extensions.py.

D05'te kalan yerel adım yok. Yeni kanıtlanmış bir kusur dışında aynı adımlarla yeniden açılmaz. Sonraki faz **D06 sağlayıcı ve otomasyon**. Gerçek provider adapter/transport D06, ürün privacy/retention D07, bütün writer/entrypoint fencing ve authenticated activation D08, bağımsız host/hesap/browser kabulü D09 kapsamındadır. Uploaded PHP çalıştırma veya OS sandbox desteği iddia edilmez; desteklenmeyen migration açık reddedilir. Global rollback offline bakım ister, başarı Normal aktivasyonu değildir. Üretime deployment yapılmadı.
