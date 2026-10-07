# V1 Scope Contract

## V1 tanımı
Gerçek üretimde WHMCS/WiseCP yerine kullanılabilecek, shared hosting üzerinde çalışabilen ilk production sürümü. Feature breadth kontrollü, kritik akış derinliği yüksektir.

## V1-MUST domainleri
Foundation; Installer/Update/Backup/Recovery/Health; Identity; Organizations; RBAC; Impersonation; TR/EN Localization; single-active Brand; Catalog; Currency/FX; Pricing; Tax; Orders; Billing; Payments; Credit Ledger; Finance baseline; Services; Provider/Server/Placement; cPanel provisioning; Domains/TLD/Registrar; Support/basic SLA; Email notifications + Notification Center; Automation; REST API/Webhooks; Extension system; Vault; Fraud baseline; Abuse basic; Privacy enforcement; Analytics dashboards; Documents/Quote/Proforma/Contract; Generic Import/CSV; WHMCS migration; Observability; Queue/Scheduler/Concurrency; full AI/Test governance.

## Gerçek V1 adapter hedefleri
- Hosting: **cPanel**.
- Payment: **Manual/Bank + iyzico**.
- Registrar: **1 production registrar** (implementation başlamadan mevcut sağlayıcı kilitlenir).
- Email: **SMTP**.
- Backup storage: **Local + SFTP + S3-compatible**.

## V1 UI sınırı
- Tek aktif brand.
- Tek yüksek kaliteli default client theme.
- Modern admin shell, dark/light/system, density, DataTable, drawers, command palette, Action Center.
- Admin/client mobile'da günlük operasyonlar; karmaşık config desktop-first.

## V1-MUST iş akışları
Hosting sale, domain registration/renewal, support, service upgrade, disaster recovery, WHMCS migration/cutover.

## V1'e alınmayacaklar
Full reseller, affiliate, live chat, public CMS/blog, website builder, marketplace, AI support agent, AI fraud decision, AI analytics assistant, NL reporting, full multi-brand UI, qualified e-sign, full Open Banking, full ERP/accounting, advanced budget planning, data warehouse requirement, Elasticsearch/OpenSearch requirement, native mobile, complex KYC, aggressive browser fingerprinting.
