# Checkpoint 2 Next Steps — 20 August 2026

> **WORKING COORDINATION NOTE — NOT UAT EVIDENCE, MERGE AUTHORIZATION, OR PILOT APPROVAL.**

- **Owner:** Daniel Happy Putra
- **Scope:** next actions after the 20 August 2026 doc/runbook and dependency-hygiene alignment pass

## What is already in place

The following artifacts now exist and are aligned:

- [Current hosting posture](CURRENT_HOSTING_POSTURE.md)
- [Outpatient Checkpoint 2 UAT Facilitator Guide](OUTPATIENT_CHECKPOINT_2_UAT_GUIDE.md)
- [Outpatient Checkpoint 2 Entry Gate Validation — 20 August 2026](OUTPATIENT_CHECKPOINT_2_ENTRY_GATE_VALIDATION_2026-08-20.md)
- [Outpatient Checkpoint 2 UAT Record Template](OUTPATIENT_CHECKPOINT_2_UAT_RECORD_TEMPLATE.md)
- [Outpatient Checkpoint 2 UAT Record — 20 August 2026 Draft](OUTPATIENT_CHECKPOINT_2_UAT_RECORD_2026-08-20_DRAFT.md)
- [Outpatient Facilitator Rehearsal — 20 August 2026](OUTPATIENT_FACILITATOR_REHEARSAL_2026-08-20.md)
- [Checkpoint 2 Facilitator Quickstart — 20 August 2026](CHECKPOINT_2_FACILITATOR_QUICKSTART_2026-08-20.md)
- [Dependency Hygiene Baseline — 20 August 2026](DEPENDENCY_HYGIENE_BASELINE_2026-08-20.md)
- [Dependency Update Candidates — 20 August 2026](DEPENDENCY_UPDATE_CANDIDATES_2026-08-20.md)
- [GitHub Publication Checklist](GITHUB_PUBLICATION_CHECKLIST.md)
- PR [#25](https://github.com/danielhappyg/simrs-campus-ueu/pull/25) ICD-9-CM register recheck **merged** to `main`

## Open actions before faculty Checkpoint 2 acceptance

1. **Daniel review of the merged ICD-9-CM baseline**
   - Confirm the merged PR #25 register outcome remains the working baseline for upcoming faculty rehearsal evidence.

2. **Choose the faculty rehearsal target**
   - Either local isolated fixture or the current Vercel + Supabase synthetic demo.
   - Do not treat campus hosting as a prerequisite for Checkpoint 2.

3. **Close remaining rehearsal evidence gaps**
   - Afternoon 20 Aug 2026 local `LAB-REHEARSAL-001` reached `FINALIZED` (mixed browser + authenticated service evidence).
   - Still open before treating scenarios as PASS: draft-guard branches, deny-case checks, selected branch scenarios, and VAL retain/revise/remove decisions.
   - Automated support already exists and does **not** replace faculty PASS marks:
     - `resources/js/test/unsaved-changes-guard.test.tsx` covers dirty-nav / save-draft / reauth failure paths for `UnsavedChangesGuard`
     - `tests/Feature/WorkTaskInvariantTest.php` covers cross-assignment session boundary and capability deny cases
     - Faculty UAT still needs observed browser evidence on a disposable session for UAT-02/03/09 residual notes

4. **Review faculty-correction fix PRs**
   - [PR #27](https://github.com/danielhappyg/simrs-campus-ueu/pull/27) cancels superseded DRAFT service/medication requests when a successor medical version is created (addresses `UAT-20260820-002` / `003`).
   - [PR #28](https://github.com/danielhappyg/simrs-campus-ueu/pull/28) surfaces session synthetic stock on medical prescribing and names mismatched lots in pharmacy FEFO alerts (addresses `UAT-20260820-001`).
   - Merge/test decision remains Daniel's; do not treat the PRs alone as faculty PASS.

5. **Daniel classification of the dated UAT draft**
   - Scenarios UAT-01–UAT-10 are largely `DECISION REQUIRED` with live evidence notes.
   - Issues `UAT-20260820-001`–`004` need Daniel classification.
   - If the exact faculty rehearsal date/session changes, create a fresh dated copy from the blank [UAT record template](OUTPATIENT_CHECKPOINT_2_UAT_RECORD_TEMPLATE.md).

6. **Keep dependency work docs-only unless Daniel later approves maintenance**
   - Current decision: avoid package changes on the validation docs branch.
   - Open Dependabot PRs and [Dependency Update Candidates](DEPENDENCY_UPDATE_CANDIDATES_2026-08-20.md) wait for a separate low-risk maintenance branch after Daniel approval.

7. **Faculty corrections / acceptance**
   - Daniel records PASS/FAIL and VAL decisions; only then treat Checkpoint 2 as faculty-accepted.

## Still not authorized by the current evidence

- deployment approval
- faculty-pilot approval
- production-data use
- campus-host assumption or Hostinger assumption
- automatic package upgrades from the hygiene baseline

## Practical next pick

Highest-value next actions after this docs PR:

1. Daniel classifies `UAT-20260820-001`–`004` and marks scenario outcomes in the dated draft; or
2. run the remaining draft-guard / deny-case / branch scenarios against a fresh disposable session and append evidence to a new dated UAT copy.
