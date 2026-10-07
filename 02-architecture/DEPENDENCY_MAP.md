# Domain Dependency Map

## Dependency waves
A Governance → B Foundation → C Identity/Org/RBAC/I18n/Brand/Vault/Module/Privacy foundation → D Catalog/Currency/Pricing/Tax → E Orders/Billing/Credit/Manual Payment → F Finance/Online Payment/Documents → G Services/Providers/Servers/Placement → H Provisioning/cPanel → I Domains/Registrar → J Support/Notification/Automation maturity → K Fraud/Abuse/Privacy enforcement → L Analytics → M Import/WHMCS → N Operations hardening → O RC/Stable.

## Rules
- Unmet HARD dependency blocks implementation.
- Specs/research/test fixtures may be prepared while blocked, production implementation may not.
- Circular domain dependencies are CI failures.
- Foundation is demand-driven: no component without a real V1 consumer.
- Contract freeze precedes adapter implementation.
- API/UI evolve with their domain, not as separate duplicated business implementations.
- Cross-cutting Privacy/Analytics/Automation are incremental, not one giant late phase.

Canonical machine-readable graph: `09-machine-readable/domains.json`.
