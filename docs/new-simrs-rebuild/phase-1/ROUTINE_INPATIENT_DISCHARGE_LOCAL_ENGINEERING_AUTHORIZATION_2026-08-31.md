# Routine Inpatient Discharge — Local Engineering Authorization

Date: 2026-08-31
Scope: local implementation and verification only; no commit, push, migration of a hosted database, or deployment.

## Authorized behavior

- One fixed disposition: `PULANG_ATAS_IZIN_DOKTER` (`Pulang atas izin dokter`).
- Exact physician role with `clinical.inpatient.discharge.execute`.
- The actor must own the exact Final `ROUTINE_INPATIENT_DISCHARGE_SUMMARY_V1`.
- The exact immutable Final-summary version is retained by foreign key, public ID, content digest, and placement-provenance digest. Its ward, bed, location sequence, and location event must still equal the current locked placement when discharge executes; legacy sequence zero is accepted only with the coherent legacy baseline.
- Once a discharge summary is Final, bed transfer is denied. Transfer may occur before finalization, after which the Final summary and discharge bind the new bed.
- The request must bind the expected summary version, current location sequence, source bed public ID, and idempotency key.
- One atomic transaction creates immutable discharge evidence and an immutable operation receipt, records required audit evidence, changes the encounter to `READY_FOR_RM`, and releases the bed from active occupancy while retaining its last placement identifiers and location history.
- Immutable receipt, discharge, summary-version, and location evidence uses MySQL shared current reads or PostgreSQL fresh `READ COMMITTED` reads; PostgreSQL runtime roles do not need row-lock privileges on immutable tables.
- Audit failure rolls back the discharge, status transition, bed release, and receipt.

## Explicit exclusions

No discharge against medical advice, death, referral, billing, claim, pharmacy, RMIK closure, external integration, or location-history mutation is authorized in this slice. `READY_FOR_RM` is not `CLOSED`; later RMIK closure remains a separate workflow.

All data remains within the established simulation and synthetic-data boundaries. No secrets are added.
