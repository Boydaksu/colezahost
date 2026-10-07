# Performance Budgets

Budgets are regression targets on a documented reference environment, not marketing SLAs.

Initial targets:
- Simple API read backend target: <= 200 ms typical reference run.
- Client dashboard backend target: <= 400 ms.
- Admin dashboard backend target: <= 500 ms.
- Typical primary page query budget: <= 30 unless approved dataset exception.
- List tests must detect N+1 growth (e.g. 100 rows cannot add ~100 relationship queries).
- Large report/export must switch to async/chunked path.
- Queue workers honor runtime/memory budgets and gracefully stop before host limits.

Exact baselines are re-measured and locked during P01/P04 on the chosen reference environment; changing them requires Test Change Request.
