# D03.1 — provisioning işlem verilerinin Vault’ta saklanması

Referans `28cfa90fb2a81a42155af95760d0a123ca42fe43`. Orijinal master Security Constitution sırların loglanmamasını ve provider sırlarının Vault’ta saklanmasını ister. Önceki EGRESS paketi audit/export kopyalarını temizlemişti; operasyonel giriş ve sonuç JSON’u sırları açık saklamaya devam ediyordu. Bu paket yalnız provisioning operasyonlarının saklama sınırını tamamlar. D02 kapalı, D03.1 IN_PROGRESS, independent_acceptance=PENDING, production_ready=false.

## Değişiklik

ProvisioningPayloadStore tüm payload/result JSON’unu `provisioning` namespace altında ortak AES-GCM Vault’ta saklar. Secret alan adı tahmini yapılmaz; işaretsiz/nested sırlar da şifreli içerikte kalır. Owner satırında yalnız `vault:v1:` referansı bulunur. UUID, service/server ID, provider, action, correlation ve payload/result column kapsamı SHA256 ile beklenen secret_key’i belirler. Başka owner/column referansı veya değişmiş owner metadata’sı reddedilir. Var olan getPayload()/getResultData() API’si gerçek execution verisini döndürür; diagnostic redaction operational verinin yerine geçirilmez. JSON_THROW_ON_ERROR ve JSON_PRESERVE_ZERO_FRACTION kullanılır.

Queue owner insert + Vault/write/access audit aynı transaction içinde; recordAttempt owner lock, önceki veri doğrulaması, result Vault write, durum/retry sayacı, provisioning audit ve reload tek transaction/savepoint içinde yürür. Geç provisioning audit hatası hepsini geri alır. Raw PDO/managed caller transaction sahipliği korunur; outer rollback owner/Vault/audit kayıtlarını beraber geri alır. Bound probe anahtar lock’u queue/attempt/reconciliation öncesinde alınır. Kurulu tam şema ve InnoDB metadata preflight yapılır; caller transaction içinde first-use DDL yoktur. ensureTables standalone provisioning + Vault/audit şemasını kurar; 26 baseline migration byte olarak değişmedi.

KeyLifecycleService inventory yeni owner referanslarını ve onların çözülen JSON’unun array/object biçimini deep verify ve rotation sırasında doğrular; bütün Vault ciphertext’leri mevcut rotasyon mekanizmasıyla yenilenir. Restore requiresVerification kapısı provisioning verisini de hesaba katar. Mevcut restore verification üzerinden kullanılır; bu paket tam dump/file recovery kabulü değildir.

## Eski kayıt geçişi ve kullanım

Runtime plaintext JSON fallback yoktur. Mevcut plaintext operation kayıtlarını içeren kurulumda yeni worker’ları başlatmadan bakım geçişi gerekir. CLI bu sunucuda/production’da çalıştırılmadı; değişiklik GitHub’a gönderilir, deployment sayılmaz.

Writers durmuşken ve JSON kayıtlarının güvenilir eski uygulama verisi olduğu doğrulandıktan sonra, kurulu provisioning/Vault/security-audit şeması ve eski veriye ait doğru APP_KEY veya APP_KEY_FILE ile:

```text
php tools/provisioning-secret-maintenance.php --maintenance --confirmed-legacy-json
```

Bu komut şema oluşturmaz ve anahtar dosyası değiştirmez. Bütün legacy JSON’u önceden çözer; malformed JSON, yanlış ref/key veya mevcut conflicting Vault owner işlemi reddeder. Vault + owner reference + security audit aynı bakım transaction’ında tutulur. Mevcut referanslar doğrulanır; ikinci geçiş `upgraded_fields=0` döndürür (doğru erişim audit kayıtları oluşabilir). Bakım bittiğinde mevcut application-key verify ve yeniden başlatma kontrolleri uygulanmalıdır. Legacy provenance bayrağı bilinmeyen/bozuk veriyi güvenilir hâle getirmez.

## Test kanıtı

Yeni ilk iki regresyon düzeltme öncesi doğrudan koşuda iki failure verdi: açık parola ve owner reference splice. İlk fixture koşusunda eksik standalone operations şeması fark edildi ve fixture ensureTables ile düzeltildikten sonra gerçek açıklar gösterildi. Geliştirme koşusunda mevcut iki probe testi owner metadata’sını sonradan değiştiriyordu; artık eksik server/missing provider senaryoları başlangıçta doğru owner scope ile queue edilir. Bu testlerin unknown/no-remote-read assertion’ları korunur.

Son ana suite **1651 test / 11490 assertion**; affected MariaDB 10.11 **56 test / 388 assertion**; fail/error/skip0. Native dört sınıf ve exact discovery: ProvisioningSecretTest, KeyLifecycleTest, ProvisioningProbeSafetyTest, SecretEgressTest. Full native suite bu kaynakta yeniden koşulmadı. Native ilk hazırlık koşusu 56/388 geçti; XML/TXT final kanıt ayrı tutulur. Ana XML tamamlanmış aynı kaynak koşusudur; main-console-tail.txt terminalin son bölümüdür, tam konsol dökümü değildir. Uygulama ve test PHP kaynakları başarılı koşu ile son hash arasında değiştirilmedi.

SQLite/MariaDB ortak yeni testler: nested/unlabelled sırların owner tablosunda bulunmaması; restart exact payload/result/float round-trip; owner splice reddi; geç provisioning audit failure rollback; raw/managed parent rollback; yanlış key read/attempt öncesi etkisizlik; configured key yokken queue öncesi etkisizlik; plaintext runtime reddi, confirmed migration admission, malformed batch rollback ve ikinci geçişte sıfır upgrade. Key lifecycle ortak ek testinde gerçek korumalı yeni key dosyası ile rotation/restart, eski writer reddi ve owner corruption’ın deep verification’ı durdurması ölçülür. Mevcut dört-worker reconciliation testi tek completion/audit koşulunu korur.

Security/architecture/dependency scan PASS: 1035 PHP dosyası, altı signature pattern ve üç forbidden-function kuralı. Bu DLP ya da tüm ürün güvenlik kabulü değildir. Hash/source-freeze, native discovery ve 26 migration kontrolü `verify-d03-provisioning-storage.py` ile doğrulanır; tarihsel paket verifiers kendi commitlerinde geçerlidir.

## Sabit kalan kapsam

Genel D03.1 denetiminin üç alt işi devam eder: operational carriers (automation context/action/execution snapshot; domain registrar operation JSON/EPP saklama); bağımsız privacy signing keys ve legacy integrity; comprehensive sink/legacy export/response acceptance. Bu paket domain ve automation kayıtlarını şifrelemiş sayılmaz. Actor/step-up/RBAC D03.2–D03.4, bütün HTTP composition D08, tam restore D05 ve bağımsız kabul D09’da kalır. Yeni D02 alt fazı açılmadı.
