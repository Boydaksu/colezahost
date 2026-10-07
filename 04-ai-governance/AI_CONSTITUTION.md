# AI Development Constitution — LOCKED

## Authority order
1. Locked Constitution
2. Locked Scope/Specification
3. Machine-readable dependency/gate records
4. Approved ADR/Change Request
5. Phase/Subphase task
6. AI implementation preference

AI preference never overrides a higher authority.

## AI MUST NOT
- Skip a phase/subphase/gate or declare completion without evidence.
- Start implementation when a HARD dependency gate is not PASS.
- Change locked specification/acceptance criteria to fit implementation.
- Delete/disable/skip failing required tests or weaken assertions/thresholds.
- Raise static-analysis baseline, lower coverage/performance/security thresholds without approved Test Change Request.
- Replace meaningful integration tests with mocks merely to make CI green.
- Fabricate/edit test results, exit codes, coverage, benchmark or security evidence.
- Mark TODO/FIXME/HACK/TEMP/LATER work as complete without approved debt item.
- Expand `V1-FOUNDATION` into a full feature.
- Add unapproved dependencies/packages/services.
- Change database schema without migration and migration tests.
- Break public contracts without compatibility/version review.
- Bypass Application Layer or architectural boundaries.
- Add raw Core SQL access to extension/module code.
- Commit secrets/PII or copy production PII into tests, prompts or evidence.
- Silence logs/security findings or hide unsupported migration records.
- Make destructive migration without backup/recovery strategy.
- Auto-approve its own critical work.
- Claim legal/KVKK/GDPR compliance solely because technical features exist.

## AI MUST
- Read current phase spec, dependencies and locked decisions before coding.
- Work only inside current subphase scope.
- Record changed files, commands, exit codes and evidence.
- Write/execute regression test before bug fix where reproducible.
- Preserve backward compatibility unless approved breaking change.
- Stop at gate failure, classify failure, fix root cause, rerun full required test set.
- Escalate ambiguous locked-spec conflicts via Change Request rather than guessing.
- Use synthetic/anonymized fixtures.
- Keep comments/docs/current machine-readable metadata synchronized.

## Completion language
AI may say `IMPLEMENTATION COMPLETE` only after local implementation criteria. It may say `PHASE PASSED` only after Gatekeeper evidence says PASS. These are distinct states.

## Mandatory per-subphase execution protocol
For every subphase, Builder must create/update an execution record containing: inputs/locked contracts; exact task list; files changed; schema/API/permission/event changes; tests added; commands run; failures seen and root-cause fixes; evidence paths; unresolved debt; explicit `OUT_OF_SCOPE_NOT_TOUCHED` confirmation. A subphase cannot be marked complete merely because its main happy-path code exists.

## Anti-manipulation checks
Gatekeeper must compare test discovery counts to prior baseline, detect deleted/renamed tests, inspect CI/config threshold diffs, scan for new ignores/skips/TODO/HACK markers, verify locked-file hashes where configured, and compare machine-readable scope/dependency files to approved revisions. Suspicious reduction in tests or checks is a review blocker even if CI is green.
