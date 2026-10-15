# D03.2 — organizasyon ve üyelik yetkisi

15.10.2026. Başlangıç: `ee2dd00b30fbd981361c8447f9477b07ec317db8`. Birincil referans, orijinal ZIP içindeki P03.3 organizations/memberships/invitations/switching, Decision Register ve Test Matrix. ZIP ve karşılaştırılan belgelerin hash'leri `D03-2-baseline.json` içinde. D03.2 yerel uygulama tamamlandı; sonraki D03.3. Bağımsız kabul bekliyor; production_ready=false.

## Kanıtlanan açıklar

Değişmemiş kaynak üzerinde 3 test / 3 failure / 0 error (`D03-2-before.xml/txt`): başka hesabın ID'siyle davetin e-postasını göndererek üyelik kazanma; davetle owner rolü verme; başka kullanıcıya ait oturumu yeniden sahiplenerek organizasyonunu değiştirme. Önceki altı organizasyon testi bu açıkları kapsamıyordu. Üyelik değiştirme/çıkarma ve davet iptali komutları eksikti.

## Son davranış

| İşlem | Backend kuralı |
|---|---|
| Oluşturma/listeleme | Güncel etkin hesap; kalıcı owner + üyelik + audit aynı işlemde. Liste, geçersiz rol ve inactive organizasyonu dışlar. |
| Davet | Aynı organizasyonda güncel owner/admin; geçerli e-posta ve pozitif sınırlı TTL. Roller member/billing/technical/admin; admin davetini yalnız owner verir. Owner/global admin/wildcard rolü davetle verilmez. |
| Davet kabul | Etkin hesabın DB e-postası, çağrıdaki e-posta ve davet e-postası eşleşir; hesap e-postası doğrulanmıştır. Tam token hash'i, tek kayıt, geçerli organizasyon, expires_at > now ve tüketilmemiş durum kilit altında tekrar doğrulanır. Mevcut üyeliğin rolü davetle değiştirilmez. |
| Rol değişimi/çıkarma | Aynı organizasyonda güncel owner/admin. Owner kaydı ve owner üyeliği korunur. Admin, başka admini veya kendisini değiştiremez; admin atamasını/değişimini yalnız owner yapar. |
| Bekleyen yetki devri | Admin üyeliği değiştiğinde/çıkarıldığında bekleyen organizasyon davetleri iptal edilir. Legacy şema davet eden ID'sini tutmadığı için tüm bekleyen davetleri iptal etmek bilinçli muhafazakâr politikadır; gerektiğinde tekrar gönderilir. |
| Davet iptali | Aynı organizasyon, güncel yönetici ve pending kayıt; admin davetini yalnız owner iptal eder. Başka organizasyonun davet ID'si etkisizdir. |
| Organizasyon seçimi | Oturum deposu zorunlu, aynı Connection ve gerçek transaction. Oturum mevcut, kullanıcıya ait, süresi dolmamış ve iptal edilmemiş olmalı. Oturum oluşturulmaz, sahibi/IP/user-agent/last_activity değiştirilmez; diğer payload korunur. |
| Aktif organizasyon çözümleme | `resolveActiveOrganization()` her kullanımda güncel kullanıcı/organizasyon/üyeliği okur. Session'daki eski veya sahte rol otorite değildir; cache rol alanı temizlenir. Üyelikten çıkarılmış kullanıcı eski seçimle yetki alamaz. |

Actor ID'leri authenticated application boundary tarafından sağlanır; bu servis bir HTTP kimlik doğrulayıcısı değildir. Yeni role enum'u global RBAC yetkisi vermez. Ownership transfer komutu eklenmedi; owner olağan üyelik komutlarıyla düşürülemez/çıkarılamaz. Detaylı ürün komut RBAC/context bağlantısı D03.4, HTTP giriş noktaları D08 kapsamındadır. Bunlar tamamlandı gibi gösterilmez.

Organization satırı aynı organizasyonun davet/üyelik işlemlerini serialize eder. Yetki kararları current locking read kullanır; eski repeatable-read snapshot admin/üyelik/davet durumunu yeniden geçerli yapamaz. Güvenlik audit kaydı aggregate'in içindedir, token veya davet e-postası kaydedilmez. Raw PDO ve managed parent savepoint'leri korunur; child insert/audit hatası ve parent rollback owner, üyelik, davet, oturum ve audit değişikliklerini birlikte geri alır.

Runtime organizasyon şeması kuruluysa DDL çalışmaz; caller içinde eksik şema ve nontransactional MyISAM reddedilir. Tam composite membership unique kimliği doğrulanır. 26 canonical migration değişmedi; şema değişikliği gerekmedi. Eski üyelikte oluşturulma tarihi bulunmaması güvenlik önkoşulu sayılmaz; mevcut legacy upgrade veri koruma testleri aynen geçer. Eski test fixture'ına gerçek kullanıcı kayıtları eklendi; session rol cache beklentisi güncel üyelikten çözümleme ölçütüne yükseltildi.

## Doğrulama

- Tam ana paket: **1799 test / 12242 assertion**.
- Gerçek MariaDB 10.11: **137 test / 960 assertion**, tam beş native class ve discovery/JUnit bire bir eşleşmesi.
- İkisinde failure/error/skip=0. Ana paket mimari/güvenlik kontrollerini içerir.
- Dört paralel süreç: tek davet tüketimi, tek kullanılabilir replacement, yönetici düşürme/davet yarışı ve üyelikten çıkarma/oturum seçimi yarışı. Raw/managed caller ve child/audit failure; stale snapshot, MyISAM ve eksik unique indeks negatifleri.
- Etkilenen mevcut native alanlar: IdentityLifecycle, ApiSessionPersistence, InstallationAcceptance, LegacyIdentitySchema. Tam native suite yeniden çalıştırılmadı; yalnız bu ölçülen kapsam raporlanır.

Ham çıktılar: `D03-2-main.xml/txt`, `D03-2-native.xml/txt`, `D03-2-native-discovery.xml/txt`. Son kaynak SHA256 ve migration baseline: `D03-2-baseline.json`. `python remediation/verify-d03-organizations.py` kaynak, kanıt, test kimlikleri ve state tutarlılığını doğrular. Geçmiş D03.1 commitindeki verifier o commit için tarihsel kanıttır; D03.2 state'i üzerinde geçmiş gate yeniden çalıştırılmaz. State'te kapanmış D03.1'e ait eski remaining_buckets etiketi de boş listeyle tutarlı hale getirildi.

Bu yerel uygulama/test kapanışıdır. Bağımsız ürün kabulü ve production kurulumu tamamlanmış sayılmaz. Deployment veya gerçek müşteri verisi üzerinde bakım yapılmadı.
