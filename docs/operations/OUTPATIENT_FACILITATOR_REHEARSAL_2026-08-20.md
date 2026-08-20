# Outpatient Facilitator Rehearsal — 20 August 2026

## Record status

- Evidence type: developer-operated Stage A entry-gate and monitor rehearsal
- Candidate code commit on `main`: `f4ddfdf`
- Register recheck branch / PR: `docs/icd9cm-register-recheck-2026-08-20` / PR #25
- Deployment status: **NOT DEPLOYED** (local `127.0.0.1:8028` only)
- Product-owner decision: **NOT DECIDED** (ICD-9-CM register recheck pending PIC merge)
- Data boundary: synthetic teaching data only
- Scope boundary: entry gate and facilitator session monitor only; full role journey **NOT RUN** in this record

This record supports Daniel's review before scheduling faculty Checkpoint 2 UAT. It is not faculty acceptance, merge authorization, deployment authorization, or evidence of clinical suitability.

## Rehearsal environment

- local isolated SQLite fixture with `APP_MODE=SIMULATION`, `APP_SYNTHETIC_ONLY=true`, `DEMO_SEED_ENABLED=true`;
- compiled frontend assets present (`public/build/manifest.json`);
- both development terminology releases active (ICD-10 `3c22aa15…`; ICD-9-CM `c13d074b…` pending register merge on PR #25);
- disposable session `LAB-REHEARSAL-001` cloned from pristine `SIM-RJ-UEU-001`;
- encounter `ENC-SIM-Q0XZYBHFMREY` in `PLANNED` state;
- no production clinical integration endpoints configured.

## Readiness gate

`php artisan simulation:lab-preflight --json` returned:

- overall status: `READY`
- passed checks: 17
- failed checks: 0
- terminology: ICD-10 `ICD10_2010` (18,543 concepts); ICD-9-CM `ICD9CM_2010` (4,626 concepts)

`php artisan simulation:lab-access enable --confirm=ENABLE-RESERVED-DEMO-ACCESS` returned `UNCHANGED` with ten active accounts.

## Session monitor

`php artisan simulation:lab-session-status LAB-REHEARSAL-001 --json` returned:

- status: `OK`
- phase: `READY_TO_START`
- active assignments: 10
- total tasks: 4
- open tasks: 3
- ready task: `REGISTRATION` / `RMIK` / `REGISTRAR`
- work queue path: `/work?session=LAB-REHEARSAL-001`

Automated HTTP/Inertia coverage in `WorkQueueTest::test_facilitator_sees_the_identity_minimized_monitor_for_an_authorized_disposable_session` passed (14 work-queue tests total on 20 August 2026).

## Browser walkthrough

A manual facilitator browser walkthrough at `http://127.0.0.1:8028/work?session=LAB-REHEARSAL-001` remains **NOT CONFIRMED** in this record. Automated CDP login did not complete the Inertia email form; Daniel should confirm **Jalur serah terima laboratorium** shows **Siap dimulai** before inviting participants.

## Open items before faculty UAT

1. Daniel PIC merge decision on PR #25 (ICD-9-CM register hash alignment).
2. Manual Stage A browser rehearsal with out-of-band `DEMO_ACCOUNT_PASSWORD` distribution.
3. Dated faculty UAT record from [UAT record template](OUTPATIENT_CHECKPOINT_2_UAT_RECORD_TEMPLATE.md).
4. Hosted-demo bootstrap on Supabase if UAT will use the Vercel URL (see [Vercel + Supabase demo](VERCEL_SUPABASE_DEMO.md)).
