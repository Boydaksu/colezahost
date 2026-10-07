# Evidence Policy

Every phase evidence package contains at minimum:
`manifest.json`, `changed-files.txt`, test results, static analysis, architecture results, security results, coverage, applicable performance/concurrency/migration reports, screenshots for UI changes, review report and gate decision.

Evidence is generated from real tools/commands. Builder/AI must not manually edit numeric results. Evidence identifies commit/spec/phase and timestamps. If rerun, previous evidence is retained or superseded explicitly, never silently overwritten.
