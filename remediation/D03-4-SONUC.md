# D03.4 — ayrıntılı yetki ve impersonation

15.10.2026; başlangıç 8a95e2b2ba31d25aea7e828113f32187a08fe683. Birincil plan P03.4/P03.5, Security Constitution default-deny/admin step-up ve Test Matrix organization isolation. D03.4 yerel uygulama tamamlandı. D03'te kalan yerel uygulama adımı yok; bağımsız kabul bekliyor. Sonraki D04.

## Başlangıç açıkları

Değişmemiş kaynakta beş test/beş failure/0 error: anonymous invoice read, caller isAdmin=true ile foreign invoice erişimi, organization role wildcard'ının system financial permission vermesi, namespace wildcard prefix taşması, başkasının session'ını impersonation ile yeniden sahiplenme. Before JUnit/stdout korunur.

## Yetki ve işlem sınırları

CommandAuthorizer mevcut authenticated application/API actor ID'sini tekrar etkin hesap/status ile doğrular. Caller admin boolean'ı authority değildir. Order/Invoice/Payment/Quote ApplicationService girişleri anonim/etkisiz/kapsam dışı actor'ı reddeder; kendi kaynağı ve organizasyon kaynağı için de güncel üyelik gerekir. Organization owner/admin/billing ve granular organization izinleri kendi namespace'inde değerlendirilir. System staff yetkisi gerçek system role permission'ından gelir. Global superadmin * kasıtlı tam yetkidir; organization * system izni vermez; system rolündeki org.* da organization rolüne dönüşmez. Role scope ile assignment scope çakışması reddedilir. RBAC runtime prepare kurulu şemada DDL çalıştırmaz ve MyISAM'i reddeder.

Ticaret local mutation + security audit aynı transaction/savepoint; actor/kaynak ve yetki okumaları current lock altında tutulur. Kurumsal listeler artık üyelikten çıkılmış kaynakları sızdırmaz. Eski repeatable-read permission snapshot'ı peer revoke sonrası yetki vermez. Invoice/payment mevcut current reads korunur; order/quote ID hydration caller içinde current read kullanır. Quote application commands kalıcı PDO ister; memory-only permission bypass yoktur. Eski olumlu commerce fixture'ları gerçek kullanıcı, rol ve admin proof içerir; salt is_admin=true başarı kanıtı sayılmaz.

Refund payments.refund izni, gerçek admin session actor eşleşmesi ve payments.refund:paymentId purpose'lu single-use step-up ister. Manual refund/consume/audit yerel aggregate'tir. Remote gateway çağrısı DB transaction'ı dışında kalır: yetki/proof admission ayrı commit edilir, mevcut durable provider coordinator rezervasyon/remote lifecycle'ı yürütür. Uzak etki rollback olmuş sayılmaz; başarısız dış işlem proof'u tüketmiş olabilir ve yeniden doğrulama gerekir. Whole provider lifecycle/reconciliation D06'dadır.

RoleAdministrationService var olan role assign/revoke için güncel roles.manage ve target/role/org tuple digest'ine bağlı step-up ister. Actor/target etkin, role scope tutarlı, organization atamasında current target membership zorunlu; proof tüketimi, role mapping ve audit atomiktir. Bootstrap/installer'ın düşük seviye RBAC API'leri güvenilir kurulum primitive'leri olarak kalır; kullanıcı komutu bunları doğrudan kullanmaz.

## Impersonation

Start authenticated gerçek admin, users.impersonate, zorunlu admin 2FA ve users.impersonate:target purpose'lu fresh step-up ister. Gerekçe zorunlu; nested/self/inactive/admin target reddedilir. Gerçek session owner admin olarak korunur, effective customer ayrı typed payload'dır. Start/stop random session ID ve CSRF'yi yeniler, eski ID'yi kalıcı revoke eder; cached organization/step-up temizlenir. Oturum/Vault proof consumption/security audit tek aggregate; audit hatasında eski session ve proof geri gelir.

EffectiveUser her komutta gerçek actor, target, süre ve current impersonation permission'ını tekrar doğrular. SessionCommerceService müşteri okumalarını effective customer ile çalıştırır; real admin'in invoices.view yetkisi müşteriye taşınmaz. Impersonation operasyonları allowlist'tir; bilinmeyen/yüksek riskli komutlar, refund, role mutation, secret/2FA/API-key/account değişimi kapalıdır. Stop, yetkisi kaldırılmış adminin güvenli çıkışını destekler; başkasının user_id'sini session owner alanına yazmaz. Stop audit target eski payload user_id=0 hatası yerine gerçek customer ID'sidir.

## Destek

SupportApplicationService caller'ın user/staff/org-role/department/internal-note bayraklarını kullanmaz. Session'dan current effective actor; DB'den current role/organization membership ve active department assignment alınır. Show/list/conversation, create/reply, internal note, status ve assignment aynı yetki hattındadır. Create author'ı actor'dır; public active department ve current organization membership gerekir. Customer internal note göremez/yazamaz. Agent atanmadığı veya çıkarıldığı department'ta işlem yapamaz; superadmin istisnası açıktır. Mutations CSRF doğrular ve actor/impersonator audit ile atomiktir. Pure permission helper'ın boş department listesi ve SQL filtreleri de default-deny/scoped hale getirildi.

Domain servisleri trusted internal persistence/lifecycle primitive'leridir; dış HTTP/UI çağrıları Application girişlerini kullanmalıdır. API'deki authenticated actor context'i HTTP composition tarafından sağlanır, request body'den user ID/role üretilmez. Browser/router/API-key-to-context composition ve tüm ekran kabulü D08'de kalır. Yeni application entry'leri CoreServiceProvider'a bağlandı; deploy yapılmadı.

## Doğrulama

- Tam ana paket: **1829 test/12324 assertion**.
- Exact 11 native class MariaDB: **228 test/1616 assertion**; discovery ve JUnit test ID'leri bire bir eşleşir.
- Failure/error/skip=0; ana suite mimari/güvenlik kontrollerini içerir.
- Four-process impersonation proof consumption/rotation; current permission stale snapshot; raw/managed parent rollback; start/stop audit failure; financial/support mutation audit failure; department/internal-note/CSRF/role target scope negatifleri.
- Önceki authentication, organization, identity/session, support, financial concurrency, documents, SQL/install ve legacy upgrade native regresyonları dahil. Tam native suite yeniden çalıştırılmadı.
- 26 canonical migration byte olarak değişmedi. Şema eklenmedi. Source freeze/test ham çıktıları/hashes ve state closure verifier birlikte saklanır. Önceki paket verifier'ları kendi commitleri için tarihsel kanıttır.

Yerel uygulama kapanışıdır; bağımsız ürün kabulü/production-ready iddiası yoktur. D04 sipariş/fatura/yenileme/finans performansı, D05 recovery/update/extensions, D06 provider/automation, D07 raporlama/privacy/support/migration, D08 entrypoint/composition ve D09 bağımsız kabul kendi sınırlarında devam eder.
