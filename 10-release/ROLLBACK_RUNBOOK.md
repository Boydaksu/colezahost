# Update Rollback Runbook

1. Freeze writes / enter appropriate maintenance mode.
2. Capture current failure evidence and operation/update ID.
3. Determine file-only vs DB-changing failure.
4. If file switch is safely reversible, restore previous release files and health-check.
5. If DB rollback is proven safe and tested for this migration, execute approved rollback path.
6. Otherwise enter Recovery Mode; do not improvise destructive `down()` operations.
7. Restore verified pre-update DB/files backup as approved.
8. Reapply migrations only to matching version and apply Privacy Tombstones.
9. Run DB/storage/queue/scheduler/module/provider health checks and Golden smoke flows.
10. Exit maintenance only after Gatekeeper/operator approval; retain incident evidence.
