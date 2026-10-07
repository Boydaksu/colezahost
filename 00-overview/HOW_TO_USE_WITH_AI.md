# How to Use This Pack With an AI Developer

1. Place this plan under repository `project-control/` (or keep paths and adjust only through approved CR).
2. Select exactly one active phase/subphase in machine status.
3. Give Builder the canonical `AI_START_PROMPT.md` plus repository access.
4. Builder may implement only READY active subphase.
5. CI writes evidence; Builder summarizes but cannot forge/edit results.
6. Reviewer receives locked spec + diff + evidence in a clean context.
7. Gatekeeper marks PASS only when policies are satisfied.
8. Only then set the next phase/subphase READY.
9. Any scope/dependency/test/architecture change uses templates under `08-templates/`.
10. Keep V1.1/V2 folders visible to explain future intent, but their code is forbidden while V1 phase is active unless explicitly required as V1-FOUNDATION contract only.
