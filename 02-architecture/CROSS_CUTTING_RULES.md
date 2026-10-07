# Cross-Cutting Rules

## Concurrency
Critical operations use idempotency + authoritative lock + DB unique/constraint + transaction where appropriate. Required for invoice renewal, payment webhook/refund, credit adjustment, provisioning and domain renewal.

## Queue/Scheduler
Database queue is baseline. Logical queues isolate critical/billing/provisioning/domains/notifications/bulk/maintenance. DLQ, retry/backoff, runtime/memory budgets, chunking and resumability are mandatory. Scheduler owns time; queue owns execution. Missed-run policies are explicit.

## Caching
Cache is optional optimization. Event-driven invalidation + TTL safety net. Cache outage degrades performance, not correctness. Queue/lock failover requires controlled policy.

## Security/Privacy
Secrets never log; PII minimization/redaction. Sensitive reveal may require permission + reason + step-up + audit. Data export/erasure and backup tombstone behavior are first-class.

## Error model
Stable error codes, client-safe messages, admin actionable messages, developer stack trace only in protected dev context. Correlation/request/operation IDs propagate across queue/provider/webhook chains.
