# Golden E2E Scenarios

G01 Manual Commerce: Organization→Product→Order→Invoice→Manual Payment→Manual Service.
G02 Automated Hosting: Client→Order→Risk→Invoice→iyzico→Webhook→Queue→Placement→cPanel→Active→Email.
G03 Renewal: Scheduler→Renewal Invoice→Notification→Payment OR overdue/grace→Suspend.
G04 Domain: Availability→Order→Payment→Registrar Register→Active→Reminder→Renewal.
G05 Failure: Payment succeeds, cPanel timeout/uncertain response, retry/reconcile, no duplicate account.
G06 Recovery: Backup→intentional broken module/update→Safe/Recovery→Rollback/Restore→Health PASS.
G07 WHMCS Cutover: Scan→Map→Dry-run→Migrate→Reconcile→Migration Hold→Cutover→Seal.
G08 Privacy Restore: Backup user→Erase→Restore old backup→Apply tombstone→PII must not resurrect.

All become locked acceptance scenarios before stable V1.
