# Architecture Constitution

- Modular monolith only unless a future ADR proves a split is necessary.
- Framework/Foundation has no business policy.
- UI/API/CLI/Automation/Modules invoke Application Commands/Queries.
- Domain code must not depend on Infrastructure implementation namespaces.
- Direct cross-domain repository mutation is forbidden.
- Raw SQL to Core tables from extensions is forbidden.
- Finalized finance/document snapshots are immutable; corrections are explicit records.
- Database migration and business-data migration are separate systems.
- Shared-host baseline must remain functional when Redis/worker/search/telemetry are absent.
- Demand-Driven Foundation Rule: no speculative infrastructure without V1 consumer.
- Public contracts are versioned; breaking changes require compatibility review/version bump.
