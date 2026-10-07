# Master Roadmap

## Resmî scope etiketleri
- **V1-MUST:** Eksikse V1 stable yayınlanamaz.
- **V1-FOUNDATION:** Contract/data model/extension point V1'de vardır; tam kullanıcı özelliği sonraki sürümde açılabilir.
- **V1.1:** İlk production sürümünden sonra ilk genişleme dalgası.
- **V2+:** Bilinçli olarak ilk sürüm ailesinin dışında.

## V1 ana fazları
| Faz | Başlık | Ana çıktı |
|---|---|---|
| P00 | Project Control Bootstrap | Constitution, scope, dependency ve gate sistemi |
| P01 | Core Foundation | Kernel, HTTP, DB, migrations, events, architecture boundaries |
| P02 | Platform Primitives | Logs, storage, cache, locks, queue, scheduler, health contracts |
| P03 | Identity & Platform Security | Identity, org, RBAC, i18n, brand, vault, module/privacy foundation |
| P04 | UI & API Foundation | Design system, admin/client shell, accessibility, API foundation |
| P05 | Catalog / Pricing / Tax | Product, currency, pricing, tax ve snapshots |
| P06 | Manual Commerce Vertical | Order → invoice → manual payment → credit → manual service |
| P07 | Finance & Documents | Finance baseline, document engine, quote, proforma, contracts |
| P08 | Integration Foundation | iyzico, notifications, webhooks, domain API exposure |
| P09 | Service / Provider Core | Services, capabilities, servers, pools, placement |
| P10 | Automated Hosting | Provisioning workflow + cPanel + full hosting E2E |
| P11 | Renewal & Automation | Renewal, overdue/grace, suspend, automation hardening |
| P12 | Domains & Registrar | TLD/domain lifecycle + one production registrar adapter |
| P13 | Support & Announcements | Tickets, SLA, attachments, contextual operations, announcements |
| P14 | Risk / Abuse / Privacy | Fraud baseline, abuse cases, DSAR/retention/erasure enforcement |
| P15 | Analytics | Metric registry, semantic layer, read models, dashboards |
| P16 | Import / WHMCS Migration | Generic import + CSV + WHMCS migration/cutover |
| P17 | Operations Hardening | Installer, updater, backup, recovery, system health |
| P18 | Release Candidate | Full regression, security/perf/chaos, shared-host matrix, stable gate |

## V1.1 ana fazları
P110 Multi-brand & Themes; P111 Provider Expansion; P112 Communication Expansion; P113 Support/Incident/Status; P114 Finance/Reconciliation; P115 Analytics/Reports; P116 Privacy/Risk Expansion; P117 Migration Expansion; P118 Enhanced Operations/Performance.

## V2+ ana fazları
P200 Reseller; P201 Affiliate/Acquisition; P202 Live Chat/Omnichannel; P203 Marketplace/Ecosystem; P204 AI Assistants; P205 CMS/Public Frontend; P206 Advanced Documents/e-Sign; P207 Advanced Finance/Open Banking; P208 Mobile/Realtime; P209 Data Platform/Warehouse/Search; P210 Enterprise Identity/SSO.

## Stable V1 için altı Golden E2E akış
1. Hosting satışı: Client → Product → Order → Risk → Invoice → Payment → cPanel → Active → Notification → Renewal.
2. Domain: Availability → Order → Payment → Registrar → Active → Renewal.
3. Support: Ticket → Assignment → Reply → SLA → Resolution.
4. Upgrade: Service → Quote → Prorata → Invoice → Payment → Provider change → Verify.
5. Recovery: Broken update/module → Safe Mode → Diagnose → Rollback/Restore → Health.
6. WHMCS cutover: Scan → Map → Dry-run → Backup → Migrate → Reconcile → Hold → Cutover → Seal.
