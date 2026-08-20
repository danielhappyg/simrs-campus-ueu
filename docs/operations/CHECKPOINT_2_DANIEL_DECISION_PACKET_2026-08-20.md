# Checkpoint 2 — Daniel Decision Packet — 20 August 2026

> **WORKING PACKET FOR DANIEL HAPPY PUTRA ONLY — NOT FACULTY ACCEPTANCE OR PILOT APPROVAL.**

- **Owner / acceptance authority:** Daniel Happy Putra
- **Scope:** synthetic outpatient reference MVP Checkpoint 2 review after local `LAB-REHEARSAL-001` journey + faculty-correction merges
- **Hosting posture:** Vercel + Supabase demo and/or local isolated fixture only; campus production hosting TBD; do **not** assume Hostinger
- **Code tip (as of this packet update):** `main` / demo production branch `codex/vercel-supabase-demo` at `5f8baf2` (includes PRs #26–#30)

## 1. Merge status (docs then fixes)

| Order | PR | Purpose | Status |
| --- | --- | --- | --- |
| 1 | [#26](https://github.com/danielhappyg/simrs-campus-ueu/pull/26) | Checkpoint 2 UAT docs, runbook/checklist alignment, dependency-hygiene baseline (no package upgrades) | **Merged** to `main` |
| 2 | [#27](https://github.com/danielhappyg/simrs-campus-ueu/pull/27) | Cancel superseded DRAFT medical orders (`UAT-20260820-002` / `003`) | **Merged** to `main` |
| 3 | [#28](https://github.com/danielhappyg/simrs-campus-ueu/pull/28) | Surface session synthetic stock on medical prescribing (`UAT-20260820-001`) | **Merged** to `main` |
| 4 | [#29](https://github.com/danielhappyg/simrs-campus-ueu/pull/29) | Indonesian coding honesty path + gold-set `DX-ID-004` (`UAT-20260820-004` / VAL-A16); **no aliases** | **Merged** to `main` |
| 5 | [#30](https://github.com/danielhappyg/simrs-campus-ueu/pull/30) | Align committed Vite CSS hash with Linux CI | **Merged** to `main` |

Synthetic demo production tip published at https://simrs-campus-ueu-demo.vercel.app (`5f8baf2` on `codex/vercel-supabase-demo`). Application checks on tip `5f8baf2` were green after #30. Do **not** treat merge or demo publish as faculty PASS.

## 2. Reporter-proposed issue classifications (not yet Daniel decisions)

These are proposals for you to accept, revise, or reject in the dated UAT draft.

| Issue | Proposed classification | Proposed next action |
| --- | --- | --- |
| `UAT-20260820-001` | `MUST_FIX_BEFORE_UAT_RESUME` → code on `main` via PR #28; still needs disposable-session spot-check | Teach stock/name alignment; no auto-substitution |
| `UAT-20260820-002` | `MUST_FIX_BEFORE_UAT_RESUME` → code on `main` via PR #27; regression test present; spot-check still useful | Cancel orphan DRAFT medication requests on successor medical versions |
| `UAT-20260820-003` | `MUST_FIX_BEFORE_UAT_RESUME` → same root cause as `002` / PR #27 | Cancel orphan DRAFT service requests on successor medical versions |
| `UAT-20260820-004` | `DECISION_REQUIRED` under **VAL-A16** | Retain honesty (`NO_RELIABLE_CANDIDATE` + ManualAlternative); **do not** activate Indonesian aliases without explicit VAL-A16 decision |

## 3. Scenario status guidance for the dated draft

Primary evidence remains [Outpatient Checkpoint 2 UAT Record — 20 August 2026 Draft](OUTPATIENT_CHECKPOINT_2_UAT_RECORD_2026-08-20_DRAFT.md).

| Scenario cluster | Evidence already captured | Still needs your mark |
| --- | --- | --- |
| UAT-01–UAT-10 main journey | Local `LAB-REHEARSAL-001` / `ENC-SIM-Q0XZYBHFMREY` to `FINALIZED` (mixed browser + authenticated services) | PASS / FAIL / DECISION REQUIRED per scenario |
| UAT-00 safety/assignment | Automated support exists (work-queue scope, unsafe env fail-closed, synthetic boundary elsewhere) | Faculty/browser confirmation before PASS |
| Draft-guard residuals (UAT-02/03) | Unit coverage in `unsaved-changes-guard.test.tsx` | Observed disposable-session browser dirty-nav / Back / expired-session |
| Deny-case residuals (UAT-09/10) | Feature coverage + residual actingAs probes on `CP2-RESIDUAL-001` / tip `7d667de` | Observed facilitator-browser deny confirmation if you still require it before PASS; VAL-U05 retain/revise/remove |
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
- Post-merge local recheck (tip `5f8baf2`, 20 Aug 2026 evening): 49 related PHPUnit + 15 Vitest cases passed — still not faculty PASS
- Residual deny addendum (tip `7d667de`): disposable `CP2-RESIDUAL-001` FINALIZED + 32 targeted PHPUnit deny cases + actingAs HTTP probes documented in the dated UAT draft §8A — still not faculty PASS

## 5. VAL decisions still only you can close

Keep every VAL row at `NOT DECIDED` until you write a final state. Highest-signal Checkpoint 2 items:

- **VAL-A16** — aliases / gold set / threshold (linked to `UAT-20260820-004` / `DX-ID-004`)
- **VAL-A11** — completeness checklist curriculum
- **VAL-U05** — outpatient summary / debrief-evidence retain-revise-remove

## 6. Explicit non-authorizations

Even after merges, demo publish, and scenario marks:

- no faculty-pilot authorization (Checkpoint 3 remains separate)
- no production clinical data / SATUSEHAT / BPJS-live use
- no campus-host or Hostinger assumption
- no automatic Dependabot mass-upgrade from the hygiene baseline

## 7. Suggested immediate Daniel actions

1. Enter classifications for `UAT-20260820-001`–`004` in the dated UAT draft.
2. Choose faculty rehearsal target: local fixture **or** Vercel + Supabase demo at https://simrs-campus-ueu-demo.vercel.app (`5f8baf2`).
3. Spot-check the three faculty-correction behaviors on a disposable session if you require browser confirmation before PASS.
4. Mark scenario PASS/FAIL/DECISION and Checkpoint 2 outcome (`ACCEPTED` / `CONDITIONALLY ACCEPTED` / `REQUIRES ANOTHER RUN`).
5. Only after that, schedule faculty-facing UAT or decide a fresh dated record copy is required.
