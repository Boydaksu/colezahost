# Mandatory Test Matrix

| Area | Mandatory suites |
|---|---|
| Foundation | Unit, architecture, DB/migration, error/logging, compatibility |
| Identity/RBAC | Unit, integration, permission matrix, IDOR/BOLA, session/2FA/security |
| Pricing/Tax | Unit, property/invariant, mutation, currency/rounding, snapshot |
| Billing/Payments/Credit | Integration, property, concurrency, webhook replay, refund, reconciliation |
| Provisioning | Contract, idempotency, timeout/429/5xx/conflict, duplicate prevention, reconciliation |
| Domains | Registrar contract, expiry/renewal concurrency, transfer, timeout uncertainty |
| Support | Permission/organization isolation, attachments, SLA scheduler |
| Privacy | Export/erasure/retention/legal-hold/tombstone restore/module handler |
| Migration | Golden source dataset, resume/idempotency, conflicts, zero silent loss, financial reconcile |
| Documents | Template compile, TR/EN, PDF Unicode/page-break, snapshots/hash, visual regression |
| Analytics | Golden dataset, semantic metric, FX/timezone, reconciliation, permissions |
| Release | Installer, update, rollback, backup/restore, disaster recovery, shared-host E2E |
