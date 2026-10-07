# Data Constitution

- Source-of-truth is domain persistence; analytics/cache/search are derived.
- Personal data must have classification/purpose/retention metadata where applicable.
- Data minimization: unnecessary personal data is not collected.
- Financial history uses ledger/snapshots; balances are derived/cached, not blindly mutated.
- Historical currency/price/tax values are not silently recalculated with current settings.
- Erasure is delete/anonymize/restrict/retain planning, not `DELETE user`.
- Legal Hold may block retention actions and must itself be reviewable/audited.
- Backup restore reapplies Privacy Tombstones before production opens.
- Migration guarantees Zero Silent Data Loss; every source record gets a terminal accounting state.
- Production PII is not copied to developer/AI fixtures; synthetic/anonymized data only.
