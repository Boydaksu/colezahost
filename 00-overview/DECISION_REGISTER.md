# Locked Decision Register

Aşağıdaki kararlar Change Request + ADR + impact analysis olmadan değiştirilemez.

- Native PHP custom lightweight Foundation; full framework dependency yok.
- Modular monolith ve bounded-context ayrımı.
- PHP 8.4+ ve MariaDB 10.11+ ana hedef; shared hosting compatibility temel gereksinim.
- Production release ZIP `vendor/` ve derlenmiş asset'lerle gelir; production Composer/Node/SSH/Docker zorunlu değildir.
- Admin/client aynı Core, ayrı route/layout/permission yüzeyleri.
- Application layer tüm business işlemlerinin tek kaynağıdır.
- User ≠ Organization/Client; kullanıcı birden fazla organization'a üye olabilir.
- Granular RBAC; admin ve organization permission namespace'leri ayrı.
- Admin impersonation internal olarak immutable audit edilir; client'a impersonation bildirimi gösterilmez; hassas işlemler kısıtlanır.
- Product provider-independent; Product → Service Definition → Provider yaklaşımı.
- Multi-currency, immutable FX/price/tax/billing snapshots.
- Payment ≠ Invoice; Payment Allocation; partial/split/refund/credit support.
- Credit ledger tabanlıdır.
- Service/Domain/Finance state machine'leri destructive update yerine operation/history kullanır.
- Provisioning idempotent workflow; reserve→provision→verify→commit.
- Provider credentials Vault'ta encryption + masked UI + audit.
- Domain ayrı bounded context; registrar transfer ile internal ownership transfer ayrı.
- Support ayrı Service Desk domain; ticket operations Application Commands çağırır.
- REST `/api/v1`, scopes, rate limits, idempotency, OpenAPI.
- Webhook HMAC + timestamp + event id + retry/delivery history.
- Extension manifest, permissions, namespaced routes/storage/tables; raw Core SQL/modification yasak.
- UI PHP SSR + progressive JS; production Node gerektirmez.
- Automation Trigger→Condition→Risk→Approval→Delay→Action→Audit; dry-run/observe/versioning.
- Installer/update/backup/recovery/system health production-grade ve shared-host compatible.
- App/Audit/Security/Business Timeline ayrı; correlation/request/operation IDs.
- Database Queue baseline; Redis optional; cache source-of-truth değildir.
- TR primary + EN secondary, %100 language key parity release gate.
- Fraud/Risk explainable rule-based; AI advisory only.
- Privacy/Data Governance Core domain; retention, erasure, legal hold, tombstones.
- Analytics Metric Registry/Semantic Layer; revenue/cash/invoice ayrımı.
- Import/Migration staging + dry-run + reconciliation + zero silent data loss.
- Document engine ortak; quote/proforma/contract ayrı business entities.
- AI phase/test/spec manipülasyonu yasak; evidence + independent review zorunlu.
