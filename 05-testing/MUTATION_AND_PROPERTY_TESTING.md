# Mutation & Property Testing

Priority targets: Pricing, Tax, Money/rounding, Credit Ledger, Payment Allocation, Refund, Permission decisions, Domain renewal state, Provisioning idempotency.

Core invariants include:
- allocations <= payment amount;
- invoice paid amount cannot exceed valid settled allocations without explicit overpayment policy;
- total_refunded <= refundable paid amount;
- credit balance = ledger movements;
- one service+period renewal invoice under concurrency;
- one domain renewal side effect per idempotency period;
- finalized snapshot values do not change when current product/client config changes.
