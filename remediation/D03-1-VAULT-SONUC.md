# D03.1 — ortak Vault, audit ve owner referansları

Referans `a7f7c2fbf0190458febf69382d6241e22a822b9e`. Master Security Constitution provider/API/webhook sırları için Vault ister. Yeni server ve persistent webhook sırları artık fiziksel vault_secrets kaydında tutulur; kendi alanlarında yalnız expected vault:v1: referansı vardır. D03.1 IN_PROGRESS; production_ready=false; independent_acceptance=PENDING.

## Ortak saklama

VaultService yeni değeri enc:v1: authenticated namespace/key JSON context'iyle şifreler. Alanların kolon/sıra case alias'ları için gerçek stored metadata scope kullanılır. VaultPersistence canonical primary/full composite unique ve InnoDB kontrolü yapar; ek unique index farklı kayda upsert yönlendiremez. Unique row reservation + ciphertext/version update + create/rotate audit tek transaction/savepoint'tir. Dört bağımsız worker aynı key'e yazınca tek kayıt,version4 ve dört create/rotate audit kalır. Audit başarısızlığı sır değişimini geri alır. Read decrypt'i doğrulamadan successful access audit yazmaz; delete de audit ile atomiktir.

VaultReferenceStore server için hostname SHA256 key, webhook için endpoint ID key kullanır. Referans owner'ın beklenen key'iyle uyuşmalıdır. Owner insert/update, secret ve audit birlikte geri alınır. Server metadata-only update secret version'ı artırmaz; explicit null removal Vault kaydını siler. Server hostname mevcut API'de immutable'dır; elle hostname değişimi reseal/reconciliation gerektirir. Webhook signing getter'ın eski plaintext kullanım sözleşmesi transport için korunur; owner DB'de signing key bulunmaz. Memory webhook modu kalıcı depo değildir. Webhook schema SQLite/MariaDB uyumlu hale geldi; kurulu caller içinde DDL yapılmaz.

Provider/registrar secret yolları zaten Vault kullanıyordu; metadata save/delete artık aynı parent transaction'a bağlandı. Metadata table InnoDB değilse mutation başlamaz. User API key authentication mevcut one-way key_hash kullanır; geri okunabilir plaintext eklenmedi. Outbound provider/registrar API credentials ortak Vault hattını kullanır.

## Mevcut verinin geçişi

VaultService.upgradeLegacyCiphertexts() eski authenticated empty-context GCM'yi yeni scope'lu envelope'a explicit reseal eder; version/date korunur, replay0 değişikliktir. Legacy Vault ciphertext okunabilir; bu, başka namespace'e bağlanmamış tarihsel ciphertext'in scope güvenliğini yükseltmiş saymaz. Explicit reseal uygulanmalıdır. Yanlış key/plaintext/unknown envelope authenticated read'i geçemez.

VaultReferenceUpgrade.upgrade(legacyPlaintextConfirmed:true) eski server enc:v1: alanını ve provenance teyitli eski webhook plaintext'ini owner+Vault+audit tek aggregate'inde taşır. Conflict varsa mevcut Vault sırrı üzerine yazmaz. Expected reference replay'de doğrulanır; secret/version değişmez. Önceki SecretStorageUpgrade reference marker'ını plaintext sanarak yeniden sarmaz. Önceki encrypted server alanı geçiş öncesinde authenticated compatibility yolu ile okunabilir; plaintext runtime fallback eklenmedi. Optional webhook tablosu hiç kurulmamışsa yeni bir modül yaratılmaz.

Bu API'ler **writer'ların durdurulduğu, yedek/orijinal key'in korunduğu ve provenance'ın doğrulandığı bakım** içindir. Runtime otomatik migration/key rotation yapılmaz. Key provisioning, key rotation/recovery ve installer/CLI girişlerinin admission bağları aynı D03.1 içinde açık. GitHub yayını deployment değildir.

## Testler

Başlangıç SQLite4 test4 failure. İlk focused40/89 koşusunda21 error: Server explicit SQLite migration bootstrap içinde missing Vault schema application-transaction guard'a takıldı. Sonra main1565/11132 koşusunda1 failure: Vault migration18 öncesinde erken kuruluyordu. Runtime server secret bootstrap create yoluna taşındı; migration3/meta beklentileri ve eski17→18 failure/resume fixture'ı değiştirilmedi. Preflight main1565/11138 ve unified13/32 ile native-focused14/74 ara kapsamdır.

Son kaynak freeze: **main1578/11171**, **affected native149/1031**,7 sınıf ve exact filtered discovery. Total seçili koşular 1727 test/12202 assertion; fail/error/skip0. Full native suite bu commit için çalıştırılmış sayılmaz. Önceki26 migration byte olarak aynı; source, ham XML/TXT ve manifest verify-d03-vault.py ile doğrulanır.

Ciphertext scope kopyalama, audit/metadata/owner geç hata, raw/managed caller rollback, legacy reseal/reference upgrade/replay, unconfirmed provenance, extra unique identity, null secret removal ve dört süreç version/audit ölçülür. Önceki SecretStorage/IdentityLifecycle/ProvisioningProbe/Capacity/ProviderVault/ProviderBrand sınıfları son kaynakta yeniden geçti.

## Kalan sınır

D03.1'de ortak Vault bağlantısı yerel olarak uygulandı. İki kalan alan: installer key provisioning/rotation/recovery ve bütün diğer secret/config/log/export yollarının denetimi. Gerçek actor/RBAC/context/impersonation kabulü D03.2–D03.4'te yapılır; sistemsel/internal çağrılarda audit actor0 mevcut sözleşmedir. Fiziksel API'lerin bulunması kendi başına UI/HTTP authorization veya üretim kabulü değildir.
