# D03.1 — paylaşılan diagnostic ve export sır sınırı

Referans `ccfbf1b5c10be5347e62a0dcf11af2a279b59239`; orijinal master Security Constitution parola/token/TOTP/recovery/card/EPP/provider sırlarının loglanmasını yasaklar. D02 kapalı; D03.1 IN_PROGRESS; production_ready=false; independent_acceptance=PENDING.

SecretRedactor diagnostic/export kopyalarını temizler; operational authentication/queue inputuna uygulanmaz. Known aliases (app.key, access_hash, API/SSH/private key, EPP, OTP/recovery, cookie/CSRF), setting key/value rows, JSON strings ve escaped embedded JSON korunur/maskelenir. URI userinfo ve encoded query credentials, basic/bearer, PEM/envelope/JWT/PAN biçimleri maskelenir. Opaque object/exception/resource; JsonSerializable context hook'u çalıştırılmaz; recursion depth bounded. Arbitrary unlabeled secret detection/DLP veya bütün sink kabulü değildir. company/span gibi eski kısa pan substring false positive'i giderildi; key_id metadata korunur.

Logger message/context/correlation/level/channel temizlenir; context'teki bilinen değerler unlabelled mesajdan da çıkarılır. Labeled text önce decode edilir; known value replacement field name'i değiştirip JSON redaction'ı atlatamaz. SensitiveParameter wrapper args, valid JSON/invalid UTF8 substitution var; raw serialization fallback yoktur. Config all/debug masked bulk diagnostics olur; runtime get raw değer döndürür. API log query/fragment/userinfo yerine route saklar; legacy read view aynı sınırı uygular.

Security audit yeni payload/useragent scrub ve eski payload safe read view kullanır. Immutable eski DB kanıtı yeniden yazılmaz. Provisioning audit raw_response_json/reconciliation/error diagnostics temizlenir; legacy audit getter maskelenir. Runtime payload/result veri sözleşmesi korunur: aynı operational input işi sürdürmek için erişilebilir. Bunun doğru Vault/encrypted persistence ve owner/rotation binding'i sonraki açık iştir; plaintext storage tamamlanmış sayılmadı.

Default collector ve PrivacyExportPackage boundary custom collector'ı da korur. Create checksum sanitized data üzerinde hesaplar; constructor legacy inputu temizler fakat eski checksum'ı sessizce yeniden üretmez. getExport integrity bozulduysa null döner; legacy unsanitized paket doğrulanmış gibi servis edilmez. Legacy stored export/retention ve tam privacy actor kabulü açıktır.

## Ham test kanıtı

Before 6 test/6 failure/error0: message/context, object hook, config+setting row, custom privacy package, audit DB payload, API query URL. Geliştirme18/95 ve33/149 PASS; son escaping-focused21/84 PASS. Preflight main1640/11456 ve native180/1089 PASS. Escaped JSON ve replacement-order testleri eklendikten sonra nihai kaynak yeniden sabitlendi ve her iki paket yeniden çalıştırıldı; ara çıktılar final sayılmadı.

Son tam ana paket **1642 test/11462 assertion**. Son affected native **180 test/1089 assertion**,7 sınıf/exact discovery; fail/error/skip0. Full native suite değildir. New/legacy audit, API route, provider audit ve payload round-trip SQLite/MariaDB'de doğrulandı; prior key lifecycle/Vault/identity/provisioning/API/privacy migration sınıfları tekrar geçti. 26 migration değişmedi. Source freeze/raw XML/TXT/hashes verify-d03-egress.py ile doğrulanır. Security/dependency scan PASS.

## Kalan açıklar ve kabul sınırı

D03-1-SECRET-INVENTORY.md dosya matrisi bu paketin uygulanmış sınırını ve kalan üç alt işi gösterir: operational secret carriers/kalıcılık; bağımsız privacy HMAC key'leri ve legacy integrity; bütün diğer sinks, legacy exports, direct query logs ve HTTP response/notification/backup acceptance. PrivacyTombstone ve RedactionAudit default HMAC keys hâlâ bilinen sabitlerdir; bu bulgu kapatılmış sayılmaz. Backup recovery D05, real HTTP/composition D08, actor/RBAC D03.2–D03.4'te kendi kabulüyle ilerler. GitHub push deployment değildir.
