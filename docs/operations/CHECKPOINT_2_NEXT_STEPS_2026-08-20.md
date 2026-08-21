# Checkpoint 2 Next Steps — 20 August 2026

> **WORKING COORDINATION NOTE — NOT UAT EVIDENCE, FACULTY ACCEPTANCE, OR PILOT APPROVAL.**

- **Owner:** Daniel Happy Putra
- **Scope:** next actions after the 20 August 2026 doc/runbook, faculty-correction merges, and synthetic demo publish

## What is already in place

The following artifacts now exist and are aligned:

- [Current hosting posture](CURRENT_HOSTING_POSTURE.md)
- [Outpatient Checkpoint 2 UAT Facilitator Guide](OUTPATIENT_CHECKPOINT_2_UAT_GUIDE.md)
- [Outpatient Checkpoint 2 Entry Gate Validation — 20 August 2026](OUTPATIENT_CHECKPOINT_2_ENTRY_GATE_VALIDATION_2026-08-20.md)
- [Outpatient Checkpoint 2 UAT Record Template](OUTPATIENT_CHECKPOINT_2_UAT_RECORD_TEMPLATE.md)
- [Outpatient Checkpoint 2 UAT Record — 20 August 2026 Draft](OUTPATIENT_CHECKPOINT_2_UAT_RECORD_2026-08-20_DRAFT.md)
- [Outpatient Facilitator Rehearsal — 20 August 2026](OUTPATIENT_FACILITATOR_REHEARSAL_2026-08-20.md)
- [Checkpoint 2 Facilitator Quickstart — 20 August 2026](CHECKPOINT_2_FACILITATOR_QUICKSTART_2026-08-20.md)
- [Checkpoint 2 Daniel Decision Packet — 20 August 2026](CHECKPOINT_2_DANIEL_DECISION_PACKET_2026-08-20.md)
- [Dependency Hygiene Baseline — 20 August 2026](DEPENDENCY_HYGIENE_BASELINE_2026-08-20.md)
- [Dependency Update Candidates — 20 August 2026](DEPENDENCY_UPDATE_CANDIDATES_2026-08-20.md)
- [GitHub Publication Checklist](GITHUB_PUBLICATION_CHECKLIST.md)
- PR [#25](https://github.com/danielhappyg/simrs-campus-ueu/pull/25) ICD-9-CM register recheck **merged** to `main`
- Faculty-correction / hygiene PRs **merged** to `main`: [#26](https://github.com/danielhappyg/simrs-campus-ueu/pull/26), [#27](https://github.com/danielhappyg/simrs-campus-ueu/pull/27), [#28](https://github.com/danielhappyg/simrs-campus-ueu/pull/28), [#29](https://github.com/danielhappyg/simrs-campus-ueu/pull/29), [#30](https://github.com/danielhappyg/simrs-campus-ueu/pull/30)
- Synthetic demo production tip `5f8baf2` published on Vercel via branch `codex/vercel-supabase-demo` → https://simrs-campus-ueu-demo.vercel.app (login smoke HTTP 200). Campus production hosting remains TBD.

## Open actions before faculty Checkpoint 2 acceptance

1. **Daniel review of the merged ICD-9-CM baseline**
   - Confirm the merged PR #25 register outcome remains the working baseline for upcoming faculty rehearsal evidence.

2. **Choose the faculty rehearsal target**
   - Either local isolated fixture or the current Vercel + Supabase synthetic demo (now on tip `5f8baf2`).
   - Do not treat campus hosting as a prerequisite for Checkpoint 2.

3. **Close remaining rehearsal evidence gaps**
   - Afternoon 20 Aug 2026 local `LAB-REHEARSAL-001` reached `FINALIZED` (mixed browser + authenticated service evidence).
   - Still open before treating scenarios as PASS: draft-guard branches, deny-case checks, selected branch scenarios, and VAL retain/revise/remove decisions.
   - Automated support already exists and does **not** replace faculty PASS marks:
     - `resources/js/test/unsaved-changes-guard.test.tsx` covers dirty-nav / save-draft / reauth failure paths for `UnsavedChangesGuard`
     - `tests/Feature/WorkTaskInvariantTest.php` covers cross-assignment session boundary and capability deny cases
     - Post-merge local recheck on tip `5f8baf2` (20 Aug 2026 evening): 49 related PHPUnit cases + 15 Vitest cases passed (work-queue/deny/draft-guard/medical/pharmacy/coding honesty suites). Still **not** faculty PASS.
     - Faculty UAT still needs observed browser evidence on a disposable session for UAT-02/03/09 residual notes

4. **Spot-check merged faculty-correction fixes on a disposable session**
   - [#27](https://github.com/danielhappyg/simrs-campus-ueu/pull/27) cancels superseded DRAFT service/medication requests when a successor medical version is created (`UAT-20260820-002` / `003`).
   - [#28](https://github.com/danielhappyg/simrs-campus-ueu/pull/28) surfaces session synthetic stock on medical prescribing and names mismatched lots in pharmacy FEFO alerts (`UAT-20260820-001`).
   - [#29](https://github.com/danielhappyg/simrs-campus-ueu/pull/29) records Indonesian coding honesty guidance + gold-set observation `DX-ID-004` without activating aliases (`UAT-20260820-004` / VAL-A16).
   - Merged code is on `main` and the synthetic demo tip; do not treat merge/demo publish as faculty PASS.

5. **Daniel classification of the dated UAT draft**
   - Scenarios UAT-01–UAT-10 are largely `DECISION REQUIRED` with live evidence notes.
   - Issues `UAT-20260820-001`–`004` need Daniel classification.
   - If the exact faculty rehearsal date/session changes, create a fresh dated copy from the blank [UAT record template](OUTPATIENT_CHECKPOINT_2_UAT_RECORD_TEMPLATE.md).

6. **Keep dependency work docs-only unless Daniel later approves maintenance**
   - Current decision: avoid package changes on the validation path.
   - Open Dependabot PRs and [Dependency Update Candidates](DEPENDENCY_UPDATE_CANDIDATES_2026-08-20.md) wait for a separate low-risk maintenance branch after Daniel approval.

7. **Faculty corrections / acceptance**
   - Daniel records PASS/FAIL and VAL decisions; only then treat Checkpoint 2 as faculty-accepted.

## Still not authorized by the current evidence

- faculty-pilot approval (Checkpoint 3)
- production clinical data / SATUSEHAT / BPJS-live use
- campus-host assumption or Hostinger assumption
- automatic package upgrades from the hygiene baseline

## Practical next pick

Highest-value next actions now that residual deny probes and branch fixtures exist:

1. Classify `UAT-20260820-001`–`004` and mark scenario PASS/FAIL/DECISION in the dated UAT draft; or
2. complete facilitator-browser residuals still open: draft-guard dirty-nav / Back / expired-session (UAT-02/03), post-login only-assigned capabilities, and the named UI paths for 01A/01B/02B/02C/C01/C02 if you require browser observation before PASS.
