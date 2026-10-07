# Domain Boundaries

Hard bounded contexts:
Identity; Organizations; Catalog; Pricing; Orders; Billing; Payments; Finance; Services; Provisioning; Domains; Support; Notifications; Automation; Documents; Risk/Fraud; Abuse; Privacy; Analytics; Migration.

## Forbidden dependency examples
- Support cannot mutate InvoiceRepository; it calls Billing Application Command.
- Automation cannot directly update service state; it calls Service command.
- Fraud cannot directly set Order DB fields; it returns/evaluates decision consumed by Order flow.
- Theme cannot call repositories or domain services.
- Provider module cannot query Core tables with raw SQL.
- Analytics cannot be consulted as authoritative billing/payment state.

## Event vs Extension Point
Events observe facts. Extension Points permit controlled replacement/augmentation at explicit boundaries. Unlimited mutation hooks are forbidden.
