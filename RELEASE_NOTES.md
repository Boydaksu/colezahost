# Coleza Host — Version 1.0.0 Stable Release Notes

**Release Date:** 2026-10-09  
**Version:** 1.0.0 (V1 Stable)  
**Distribution:** `coleza-host-v1.0.0.zip`  
**Security & Integrity:** Cryptographically signed using Ed25519 detached signatures and SHA256 per-file verification.

---

## 1. Executive Summary
**Coleza Host** is a next-generation, high-performance hosting commerce and operations management platform designed as a modern, reliable, and secure alternative to legacy software like WHMCS and WiseCP. Built with PHP 8.4 strict typing, modern architecture, and zero-silent-loss data governance, Coleza Host delivers unparalleled stability for hosting providers, cloud service brokers, and domain registrars.

---

## 2. Core Highlights & Platform Capabilities

### A. Zero-Silent-Loss WHMCS Migration Engine
- **Transactional Staging Pipeline:** Pristine raw source payloads preserved in staging tables (`StagingRecord`) before transformation.
- **Cent-for-Cent Financial Ledger Reconciliation:** Multi-currency double-entry ledger reconciliation between source WHMCS invoices/transactions and Coleza financial records with 0.00 difference down to cent precision.
- **Migration Safety Hold:** Automatic suppression of client logins, billing automated suspensions, and customer-facing emails during migration cutover.
- **Cryptographic Audit Seal:** Immutable `CutoverAuditSeal` cryptographically binding migration batches upon passing all 6 cutover gates.

### B. Enterprise Disaster Recovery & Privacy Protection
- **AES-256-GCM Encrypted Backups:** Full, database-only, and files-only backups with authenticated symmetric encryption.
- **Automated Retention & Multi-Storage:** Local disk, SFTP, and AWS S3 storage adapters with policy-driven pruning.
- **GDPR Privacy Tombstone Invariant (Golden G08):** Out-of-band persistent tombstones (`FileTombstoneStore`) survive catastrophic restore and immediately re-scrub resurrected PII before system unsealing, guaranteeing 0 PII resurrection leak.

### C. Constrained Shared-Host Compatibility
- **Zero External Daemon Prerequisite:** Operates seamlessly on standard shared hosting (cPanel, Plesk, DirectAdmin) without Redis, Memcached, RabbitMQ, Docker, or Node.js daemons.
- **DatabaseQueue & DatabaseLock:** Pure PDO database-backed queuing with exponential backoff and atomic mutual exclusion.
- **FileCache & DatabaseCache:** High-speed local key-value caching.
- **System Doctor Diagnostics:** 7-component automated health checks (Database, Cron, Queue, Storage, Providers, Modules, Backups).

### D. Full Concurrency Control & Provider Chaos Resilience
- **Double-Payment Replay Immunity:** Idempotency keys and ledger balance locking prevent race conditions under high concurrency.
- **Deterministic Exponential Backoff & Jitter:** Resilient external provider communication with circuit breaker isolation.
- **Capacity Contention Defense:** Atomic inventory allocation with pessimistic lock protection.

### E. Modern UI Design System & Full TR/EN Localization
- **WCAG 2.1 AA Compliant:** Contrast ratios exceeding 4.5:1 across all Light and Dark theme tokens.
- **Accessible Component Semantics:** Form controls with explicit ARIA binding, non-color-only status badges, accessible modal and drawer dialogs, and semantic DataTables.
- **100% TR/EN Symmetrical Parity:** Complete translations and matching placeholder tokens across English (`en_US`) and Turkish (`tr_TR`) for all operational domains.

---

## 3. System Requirements & Compatibility

| Component | Minimum Requirement | Recommended |
|---|---|---|
| **PHP Runtime** | PHP 8.4.0+ | PHP 8.4.24+ |
| **Extensions** | `pdo`, `pdo_sqlite` or `pdo_mysql`, `mbstring`, `json`, `filter`, `sodium` | `curl`, `zip`, `openssl` |
| **Database** | MySQL 8.0+, MariaDB 10.5+, or SQLite 3.35+ | MySQL 8.0+ / SQLite 3 |
| **Web Server** | Apache 2.4+ (mod_rewrite) or Nginx 1.20+ | Nginx with PHP-FPM |
| **Memory Limit** | 128 MB | 256 MB+ |

---

## 4. Verification & Integrity Checklist
Every release artifact is bundled with:
- `manifest.json`: Full release manifest containing file inventory, versions, and SHA256 checksums.
- `manifest.sig`: Detached Ed25519 cryptographic signature.
- `CHECKSUMS.sha256`: Standard SHA256 file manifest for command-line verification (`sha256sum -c CHECKSUMS.sha256`).

---

## 5. Getting Started & Installation
1. Extract `coleza-host-v1.0.0.zip` into your document root.
2. Navigate to `https://your-domain.com/install` in your browser.
3. The Web Installer wizard will inspect prerequisites, configure database, bootstrap root administrator, set branding, and lock itself upon completion (`installed.lock`).
