# Inpatient Discharge Coding Source v1 — Local Engineering Boundary

Status: locally implemented prerequisite; not a release, deployment, coding, claim, or billing authorization.

## Authorized workflow

- Profile: `INPATIENT_DISCHARGE_CODING_SOURCE_V1`.
- Exact physician role with `clinical.inpatient.discharge-coding-source.write`.
- The physician must be the physician assigned to the same encounter's discharge summary and remains the only author of the source.
- Immutable version chain: Draft versions followed by one terminal Final version, with optimistic version checking and idempotent operation receipts.
- A Final version records the physician's diagnosis statements and procedure attestation as clinical source text. It does not assign or validate ICD/procedure codes.
- Routine discharge requires both the exact Final discharge summary and exact Final discharge coding source. Their version identifiers and content/provenance digests are retained by the immutable discharge record, receipt, and audit event.

## Placement and transaction boundary

Writes use the canonical bed mutex and retain the current ward, bed, location event, and sequence snapshot. Transfer is denied after either the discharge summary or discharge coding source becomes Final. If transfer commits first, both Final sources must be created against the new placement.

Finalizing the source has no encounter-status, bed, location-history, RMIK, pharmacy, claim, billing, or external-integration side effect. Routine discharge continues to move the encounter to `READY_FOR_RM`, releases occupancy logically, and retains placement history.

## Evidence controls

Version and receipt rows are append-only at model, SQL-guard, and supported-engine trigger boundaries. Required audit or receipt failure rolls back the entire mutation. Synthetic reset removes the source chain only after dependent discharge evidence, while audit remains retained. Migration rollback refuses retained source, discharge binding, or correlated audit evidence.
