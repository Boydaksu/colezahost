# Release Constitution

- Stable package contains vendor + compiled assets; no production Composer/Node requirement.
- Stable update requires signature/checksum, compatibility check, mandatory verified backup, migrations, health checks.
- Critical update cannot bypass backup requirement.
- Failed DB migration never opens normal production mode.
- File rollback automatic where safe; unsafe DB rollback routes to Recovery Mode + restore decision.
- Stable release requires full regression, security, performance, concurrency, installer, upgrade, backup/restore, shared-host and migration rehearsal gates.
- RC is not Stable; stable promotion is a distinct gate.
