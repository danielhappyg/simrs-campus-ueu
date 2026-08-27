# E-Klaim Simulation Runbook

> [!CAUTION]
> **HISTORICAL REFERENCE — NOT CURRENT CLEAN-SLATE RUNTIME EVIDENCE**
>
> **DO NOT EXECUTE THIS RUNBOOK AGAINST CURRENT `HEAD` OR ANY HOSTED ENVIRONMENT.** It preserves commands and routes for an earlier never-sent E-Klaim teaching prototype; current `HEAD` does **not** contain that claim-simulation runtime. For current status, inspect the [current route surface](../../routes/web.php), [T1 local evidence boundary](T1_LOCAL_MILESTONE_APPROVAL_PACK_2026-08-26.md), [production-promotion readiness record](T1_PRODUCTION_PROMOTION_READINESS_2026-08-27.md), and [G0–G3 coverage ledger](../new-simrs-rebuild/G0_G3_COVERAGE_LEDGER_README.md).

## Purpose

Historically, this procedure ran the synthetic BPJS/E-Klaim claim exercise after the earlier reference outpatient journey reached `FINALIZED`. It is retained for design provenance only, not as an executable procedure.

This runbook never uses the supplied E-Klaim binaries, a hospital server, BPJS credentials, production identifiers, or an external endpoint.

## Preconditions

- SIMRS Campus is running in `APP_MODE=SIMULATION`.
- `APP_SYNTHETIC_ONLY=true`.
- The reference encounter is finalized.
- ICD-10 and ICD-9-CM assignments are approved by the simulated RMIK workflow.
- The actor is the encounter-scoped RMIK coder with `claim.manage`.
- `config/eclaim.php` remains:
    - `mode=SIMULATION_ONLY`;
    - `outbound_enabled=false`; and
    - `endpoint=null`.

## Browser exercise

1. Sign in as the reference RMIK coder account created by the demo seeder.
2. Open the finalized encounter's debrief or timeline.
3. Select **Simulasi E-Klaim**.
4. Confirm the permanent `NOT_SENT` boundary and the observed-installation label.
5. Run **Buat klaim** and inspect the synthetic `new_claim` request/response.
6. Run **Kirim data klaim** and identify the encounter dates, approved codes, and teaching billing components.
7. Run **Jalankan grouper simulasi**. Confirm the output says it is an educational placeholder.
8. Run **Finalisasi klaim simulasi**.
9. Run **Simulasikan pengiriman**. Confirm the receipt says `submission=NOT_SENT`.
10. Expand all five immutable exchange events and compare their request/response hashes.

## Expected reference example

| Element           | Expected teaching value |
| ----------------- | ----------------------- |
| Diagnosis         | `R42`                   |
| Procedure         | `38.99`                 |
| Billing total     | Rp275,000               |
| Simulated group   | `SIM-RJ-001`            |
| Transport state   | `NOT_SENT`              |
| External endpoint | `null`                  |

The code and amount are fixtures. They are not a tariff claim and must not be compared with a real hospital reimbursement decision.

## Historical verification commands

```bash
php artisan test tests/Feature/EClaimSimulationWorkflowTest.php
npx vitest run resources/js/test/eclaim-simulation.test.tsx
vendor/bin/phpstan analyse app/Modules/Claims app/Http/Controllers/Claims --memory-limit=512M
npm run types:check
```

## Failure interpretation

| Failure                        | Meaning                                        | Action                                                                       |
| ------------------------------ | ---------------------------------------------- | ---------------------------------------------------------------------------- |
| Encounter is not finalized     | Clinical/RMIK prerequisites are incomplete     | Finish the reference journey; do not bypass the gate                         |
| Approved ICD code missing      | Claim sources are incomplete or invalidated    | Return to coding review                                                      |
| Action requires another status | A checkpoint was attempted out of order        | Complete the immediately preceding step                                      |
| Assignment forbidden           | Actor lacks exact case scope or `claim.manage` | Use the correct RMIK coder assignment                                        |
| Unsafe configuration           | Endpoint/outbound setting was introduced       | Restore `endpoint=null` and `outbound_enabled=false`; investigate the change |

## Reset guidance

Claim events are intentionally append-only. Do not delete or edit a progressed case. Clone a fresh pristine reference session and complete its encounter to create another independent teaching branch.

## Evidence boundary

A successful run demonstrates only:

- local mapping from finalized synthetic SIMRS sources;
- ordered claim-state transitions;
- role/case authorization;
- deterministic fake responses;
- immutable hashes and audit events; and
- absence of an external transport path in this increment.

It does not demonstrate E-Klaim version compatibility, real grouper correctness, BPJS acceptance, SATUSEHAT conformance, stakeholder acceptance, hosted deployment, or production readiness.
