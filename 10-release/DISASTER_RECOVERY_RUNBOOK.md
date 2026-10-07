# Disaster Recovery Runbook

Target success condition: old server can disappear and platform can be restored to a new shared hosting account with application package + verified backup + separately held recovery key.

1. Provision supported PHP/DB hosting and HTTPS.
2. Upload signed release ZIP; choose `Restore existing installation`.
3. Verify environment and backup manifest/checksum/encryption.
4. Restore configuration/files/private storage/database in controlled order.
5. Restore/rotate environment-local secrets as policy requires; never bundle recovery key inside backup.
6. Apply schema compatibility/migrations and Privacy Tombstones.
7. Configure cron/scheduler; confirm heartbeat and queue recovery.
8. Verify email/payment/provider/registrar credentials/endpoints.
9. Run health + smoke G01/G04-safe checks without unintended external side effects.
10. Open read-only then production mode; record DR evidence.
