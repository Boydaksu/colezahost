# V1 Phase Execution Matrix

This matrix makes the V1 phases concrete. Exact PHP namespaces/file names may be defined in P01 conventions; changing responsibilities still requires ADR/CR.

| Phase | Primary domains/artifacts | Phase-specific PASS evidence |
|---|---|---|
| P00 | `project-control`, constitutions, scope/dependencies, CI/evidence schemas | Plan validator PASS; locked files/revisions exist; Builder/Reviewer/Gatekeeper protocol demonstrable |
| P01 | Kernel, Container, HTTP, DB, Migration, Validation, Events/Application contracts | Fresh bootstrap test; migration up/failure tests; architecture direction test; exception/error-code tests |
| P02 | Logger/Audit base, Storage, Cache, Lock, Queue, Scheduler, Health contracts | Duplicate cron/lock tests; retry/DLQ; runtime/memory stop; cache outage correctness; scheduler heartbeat |
| P03 | Identity, Organization, RBAC, I18n, Brand, Vault, Privacy/Module foundation | Permission matrix + IDOR; 2FA/recovery/session tests; TR/EN parity 100%; secret redaction; module boundary tests |
| P04 | Component library, admin/client shells, REST foundation/OpenAPI | Accessibility baseline; API auth/scope/rate-limit; UI uses Application Layer; no fake placeholder business state |
| P05 | Catalog, Money/Currency/FX, Pricing, Tax | Golden pricing/rounding/tax snapshots; property+mutation tests; customer/service override behavior |
| P06 | Orders, Billing, Payments core, Credit, manual Service | Golden Manual Commerce G01; partial/split/refund/credit invariants; renewal duplicate concurrency test |
| P07 | Finance, Document/Quote/Proforma/Contract | Finance ledger reconciliation; PDF TR/EN/Unicode/page breaks; immutable document snapshot/hash; quote→order price preservation |
| P08 | Notification, SMTP, Webhook, iyzico, API domain exposure | SMTP test+delivery log; webhook replay rejection; iyzico duplicate callback/refund; secrets masked; commerce API contract tests |
| P09 | Services, Provider capabilities, Server/Pool, Placement/Capacity | Placement health/capacity tests; reserve/commit/release; provider contract frozen; optimistic concurrency |
| P10 | cPanel adapter + Provisioning | Provider contract suite; create/suspend/unsuspend/terminate; timeout/429/5xx/uncertain response; no duplicate remote account; G02 |
| P11 | Automation, renewal/grace/suspend | Dry-run/observe/version; missed schedule recovery; overlap locks; G03 renewal paid/unpaid branches; destructive-action pause |
| P12 | TLD/Domain/Registrar adapter | Domain lifecycle/expiry snapshots; registrar timeout uncertainty/no double renewal; EPP step-up; G04 |
| P13 | Support/SLA/Attachments/Announcements | Org ticket isolation; attachment security; SLA scheduler; contextual action uses Commands; G03 support portion |
| P14 | Fraud, Abuse, Privacy enforcement | Explainable risk snapshot; velocity tests; erasure plan/legal hold; G08 tombstone restore; privacy export authorization |
| P15 | Metric Registry/Analytics read models | Golden financial dataset; invoice/cash/outstanding definitions; FX/timezone; source-ledger reconciliation = zero unexplained diff |
| P16 | Import/CSV/WHMCS/Adoption/Cutover | Zero silent loss accounting; source read-only; resume/idempotency; financial/service/domain reconciliation; G07 |
| P17 | Installer/Updater/Backup/Recovery/Health | Fresh shared-host install; mandatory verified backup; broken update recovery; full destroy/restore; Safe Mode; health doctor; G06 |
| P18 | Full release | G01–G08; security/perf/concurrency/chaos; shared-host matrix; TR/EN; installer/update/restore/migration rehearsals; signed RC→Stable |
