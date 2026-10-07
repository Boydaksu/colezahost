# WHMCS Migration Cutover Runbook

1. Source scan/version/capability report; read-only DB/API credential.
2. Scope/mapping/conflict plan locked; mandatory dry-run.
3. Resolve blockers; ensure every source record terminally accounted for.
4. Verified target backup/checkpoint; imported services on Migration Hold.
5. Initial migration with notifications/automation/webhooks suppressed.
6. Reconcile clients/services/domains/invoice/payment/credit/ticket counts and financial totals.
7. If supported, perform delta sync; freeze old automation/writes for final delta.
8. Verify provider identities/domain expiry/selected critical records.
9. Update callbacks/webhooks/cron/email routing in cutover checklist.
10. Enable new automation only after old automation disabled and final reconcile PASS.
11. Release Migration Hold; run Golden smoke flows.
12. Seal migration; old source ceases to be authoritative. Archive evidence and remove source credentials per policy.
