# Checkpoint 2 — Daniel Decision Packet — 20 August 2026

> **WORKING PACKET FOR DANIEL HAPPY PUTRA ONLY — NOT FACULTY ACCEPTANCE, MERGE INSTRUCTION BY ITSELF, DEPLOYMENT AUTHORIZATION, OR PILOT APPROVAL.**

- **Owner / acceptance authority:** Daniel Happy Putra
- **Scope:** synthetic outpatient reference MVP Checkpoint 2 review after local `LAB-REHEARSAL-001` journey + faculty-correction PRs
- **Hosting posture:** Vercel + Supabase demo and/or local isolated fixture only; campus production hosting TBD; do **not** assume Hostinger

## 1. Recommended merge order (docs then fixes)

| Order | PR | Purpose | CI posture (re-check at merge time) |
| --- | --- | --- | --- |
| 1 | [#26](https://github.com/danielhappyg/simrs-campus-ueu/pull/26) | Checkpoint 2 UAT docs, runbook/checklist alignment, dependency-hygiene baseline (no package upgrades) | Green / mergeable (`CLEAN`) |
| 2 | [#27](https://github.com/danielhappyg/simrs-campus-ueu/pull/27) | Cancel superseded DRAFT medical orders (`UAT-20260820-002` / `003`) | Green / mergeable (`CLEAN`) |
| 3 | [#28](https://github.com/danielhappyg/simrs-campus-ueu/pull/28) | Surface session synthetic stock on medical prescribing (`UAT-20260820-001`) | Green / mergeable (`CLEAN`) |
| 4 | [#29](https://github.com/danielhappyg/simrs-campus-ueu/pull/29) | Indonesian coding honesty path + gold-set `DX-ID-004` (`UAT-20260820-004` / VAL-A16); **no aliases** | Green / mergeable (`CLEAN`) after merging `#28`, rebuilding assets, aligning Linux CSS hash + manifest EOF |

Merge only after you accept the linked evidence. Do not treat green CI as faculty PASS.

**Sequential-merge note:** `#29` includes `#28` source + a rebuilt asset tree. Prefer still merging `#28` before `#29` so history stays ordered; if `#28` is already on `main`, `#29` should apply cleanly.

## 2. Reporter-proposed issue classifications (not yet Daniel decisions)

These are proposals for you to accept, revise, or reject in the dated UAT draft.

| Issue | Proposed classification | Proposed next action |
| --- | --- | --- |
| `UAT-20260820-001` | `MUST_FIX_BEFORE_UAT_RESUME` → resolved by PR #28 after merge + spot-check | Teach stock/name alignment; no auto-substitution |
| `UAT-20260820-002` | `MUST_FIX_BEFORE_UAT_RESUME` → resolved by PR #27 after merge + regression already in PR | Cancel orphan DRAFT medication requests on successor medical versions |
| `UAT-20260820-003` | `MUST_FIX_BEFORE_UAT_RESUME` → same root cause as `002` / PR #27 | Cancel orphan DRAFT service requests on successor medical versions |
| `UAT-20260820-004` | `DECISION_REQUIRED` under **VAL-A16** | Retain honesty (`NO_RELIABLE_CANDIDATE` + ManualAlternative); **do not** activate Indonesian aliases without explicit VAL-A16 decision |

## 3. Scenario status guidance for the dated draft

Primary evidence remains [Outpatient Checkpoint 2 UAT Record — 20 August 2026 Draft](OUTPATIENT_CHECKPOINT_2_UAT_RECORD_2026-08-20_DRAFT.md).

| Scenario cluster | Evidence already captured | Still needs your mark |
| --- | --- | --- |
| UAT-01–UAT-10 main journey | Local `LAB-REHEARSAL-001` / `ENC-SIM-Q0XZYBHFMREY` to `FINALIZED` (mixed browser + authenticated services) | PASS / FAIL / DECISION REQUIRED per scenario |
| UAT-00 safety/assignment | Automated support exists (work-queue scope, unsafe env fail-closed, synthetic boundary elsewhere) | Faculty/browser confirmation before PASS |
| Draft-guard residuals (UAT-02/03) | Unit coverage in `unsaved-changes-guard.test.tsx` | Observed disposable-session browser dirty-nav / Back / expired-session |
| Deny-case residuals (UAT-09/10) | Feature coverage: work-queue isolation, timeline/debrief/interop exact-case denials | Observed unrelated-assignment browser deny on finalized routes |
| Branch scenarios (01A/01B, 02B/02C, C01/C02) | Mostly `NOT RUN` | Fresh disposable fixtures if you require them before acceptance |

## 4. Automated evidence map (supports, does not replace PASS)

- Work queue / assignment isolation: `tests/Feature/WorkQueueTest.php`, `tests/Feature/WorkTaskInvariantTest.php`
- Draft guard UI: `resources/js/test/unsaved-changes-guard.test.tsx`
- Timeline deny: `tests/Feature/EncounterRecordTimelineTest.php`
- Debrief deny: `tests/Feature/EncounterDebriefTest.php`, `tests/Feature/DebriefNoteWorkflowTest.php`
- Interop preview capability/exact-case deny: `tests/Feature/OutpatientInteroperabilityPreviewTest.php`
- Orphan draft cancel: PR #27 + `MedicalAssessmentWorkflowTest::test_successor_medical_version_cancels_orphaned_draft_orders_from_superseded_draft`
- Stock alignment UX: PR #28 + pharmacy dispense-form unit tests
- Coding honesty: PR #29 + gold-set `DX-ID-004` OBSERVED as `NO_RELIABLE_CANDIDATE`

## 5. VAL decisions still only you can close

Keep every VAL row at `NOT DECIDED` until you write a final state. Highest-signal Checkpoint 2 items:

- **VAL-A16** — aliases / gold set / threshold (linked to `UAT-20260820-004` / `DX-ID-004`)
- **VAL-A11** — completeness checklist curriculum
- **VAL-U05** — outpatient summary / debrief-evidence retain-revise-remove

## 6. Explicit non-authorizations

Even after you merge the PRs and mark scenarios:

- no faculty-pilot authorization (Checkpoint 3 remains separate)
- no production clinical data / SATUSEHAT / BPJS-live use
- no campus-host or Hostinger assumption
- no automatic Dependabot mass-upgrade from the hygiene baseline

## 7. Suggested immediate Daniel actions

1. Review/merge PR #26, then #27–#29 in order (or request changes).
2. Enter classifications for `UAT-20260820-001`–`004` in the dated UAT draft.
3. Choose faculty rehearsal target: local fixture **or** Vercel + Supabase demo.
4. Mark scenario PASS/FAIL/DECISION and Checkpoint 2 outcome (`ACCEPTED` / `CONDITIONALLY ACCEPTED` / `REQUIRES ANOTHER RUN`).
5. Only after that, schedule faculty-facing UAT or decide a fresh dated record copy is required.
