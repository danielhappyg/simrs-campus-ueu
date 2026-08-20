# Outpatient Facilitator Rehearsal — 20 August 2026

## Record status

- Evidence type: developer-operated Stage A entry-gate/monitor rehearsal, then afternoon end-to-end synthetic journey evidence
- Candidate code commit on `main` at Stage A: `f4ddfdf`
- ICD-9-CM register recheck: PR [#25](https://github.com/danielhappyg/simrs-campus-ueu/pull/25) **MERGED** 20 August 2026 (`Recheck REF-COD-001 ICD-9-CM development release hash`)
- Deployment status: **NOT DEPLOYED** for faculty UAT (local `127.0.0.1:8028` only for this record)
- Product-owner faculty outcome: **NOT DECIDED** (PASS/FAIL/VAL marks remain Daniel-only)
- Data boundary: synthetic teaching data only
- Scope boundary: Stage A covered entry gate + facilitator monitor; afternoon covered shared-encounter journey to `FINALIZED` with residual draft-guard, deny-case, and branch scenarios still open

This record supports Daniel's review before scheduling faculty Checkpoint 2 UAT. It is not faculty acceptance, merge authorization, deployment authorization, or evidence of clinical suitability.

## Rehearsal environment

- local isolated SQLite fixture with `APP_MODE=SIMULATION`, `APP_SYNTHETIC_ONLY=true`, `DEMO_SEED_ENABLED=true`;
- compiled frontend assets present (`public/build/manifest.json`);
- both development terminology releases active after PR #25 merge (ICD-10 and ICD-9-CM checksum-locked imports);
- disposable session `LAB-REHEARSAL-001` cloned from pristine `SIM-RJ-UEU-001`;
- shared encounter `ENC-SIM-Q0XZYBHFMREY` (started `PLANNED`, afternoon journey reached `FINALIZED`);
- no production clinical integration endpoints configured.

## Readiness gate (Stage A)

`php artisan simulation:lab-preflight --json` returned:

- overall status: `READY`
- passed checks: 17
- failed checks: 0
- terminology: ICD-10 `ICD10_2010` (18,543 concepts); ICD-9-CM `ICD9CM_2010` (4,626 concepts)

`php artisan simulation:lab-access enable --confirm=ENABLE-RESERVED-DEMO-ACCESS` returned `UNCHANGED` with ten active accounts.

## Session monitor (Stage A baseline)

`php artisan simulation:lab-session-status LAB-REHEARSAL-001 --json` returned at Stage A start:

- status: `OK`
- phase: `READY_TO_START`
- active assignments: 10
- total tasks: 4
- open tasks: 3
- ready task: `REGISTRATION` / `RMIK` / `REGISTRAR`
- work queue path: `/work?session=LAB-REHEARSAL-001`

Automated HTTP/Inertia coverage in `WorkQueueTest::test_facilitator_sees_the_identity_minimized_monitor_for_an_authorized_disposable_session` passed (14 work-queue tests total on 20 August 2026).

## Browser / journey evidence

- Stage A morning: automated CDP login did not complete the Inertia email form; facilitator browser confirmation of **Jalur serah terima laboratorium** / **Siap dimulai** was still required before inviting participants.
- Afternoon 20 August 2026: local `LAB-REHEARSAL-001` / `ENC-SIM-Q0XZYBHFMREY` was exercised end-to-end to `FINALIZED` using mixed browser observation and authenticated workflow-service evidence. Detailed scenario notes, issue IDs, and open decisions live in [Outpatient Checkpoint 2 UAT Record — 20 August 2026 Draft](OUTPATIENT_CHECKPOINT_2_UAT_RECORD_2026-08-20_DRAFT.md).

## Open items before faculty UAT

1. Daniel classification of issues `UAT-20260820-001`–`004` and scenario PASS/FAIL/DECISION marks in the dated UAT draft.
2. Remaining evidence gaps called out in that draft: draft-guard branches, deny-case checks, selected branch scenarios (`UAT-01A/B`, `UAT-02B/C`, `UAT-C01/C02`), and VAL retain/revise/remove decisions.
3. Out-of-band `DEMO_ACCOUNT_PASSWORD` distribution for any faculty-facing Stage A browser rehearsal.
4. Hosted-demo bootstrap on Supabase only if faculty UAT will use the Vercel URL (see [Vercel + Supabase demo](VERCEL_SUPABASE_DEMO.md)). Campus production hosting remains TBD and is not a Checkpoint 2 prerequisite.
