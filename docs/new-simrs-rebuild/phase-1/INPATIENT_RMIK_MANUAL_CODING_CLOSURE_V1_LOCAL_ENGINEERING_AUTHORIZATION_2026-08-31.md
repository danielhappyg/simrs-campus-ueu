# Inpatient RMIK Manual Coding and Episode Closure V1

Status: **locally authorized for synthetic teaching implementation and engineering verification**
Date: 2026-08-31
Data boundary: `SIMULATION` mode with synthetic patients only

## Decision and scope

The inpatient teaching graph may proceed from routine physician discharge (`READY_FOR_RM`) to RMIK closure (`CLOSED`) through a dedicated `/rm/rawat-inap` workflow. This local engineering authorization is the implementation contract; it does not require an ADR with exact wording and is not an authorization to deploy, connect live terminology services, or process real patient data.

The exact operational actor is a non-administrator user with the `rmik` role and all three existing capabilities required by the action:

- `rmik.coding.write` for manual coding Draft versions;
- `rmik.review` for deterministic completeness snapshots; and
- `rmik.completeness.signoff` for final coding, signed review, and closure.

System administrators, the `admin` teaching role, physicians, nurses, and registrars are not substitutes for the exact RMIK actor on these mutations.

## Manual coding contract

The coding profile is fixed as `LOCAL_TEACHING_MANUAL_V1`. Diagnosis assignments use the label `ICD-10`; procedure assignments use `ICD-9-CM`. These are manual labels only. V1 performs no terminology lookup, code validation against a live catalogue, automated code suggestion, clinical inference, or automatic clinical decision.

Every coding version is bound to the exact Final physician discharge-coding-source version by internal foreign key, public version ID, content digest, and provenance digest. Authoritative assignments are normalized immutable rows. Each row records a public ULID, source statement kind/index/text hash/reference, fixed code system/profile, normalized manual code and display, ordinal, actor, and no-procedure attestation flag.

V1 requires at least one assignment for the principal diagnosis, every secondary diagnosis statement, and every performed-procedure statement. When the physician source explicitly records `NO_PROCEDURE_RECORDED`, the server creates the canonical no-procedure assignment; the browser does not invent a fake procedure code.

Saving a correction appends a new complete `DRAFT` coding version. It never edits an earlier version. Signoff appends an identical-content `FINAL` version and copies the normalized assignments inside the same transaction. Once the coding head is Final, ordinary coding changes are refused. Generic reopen and post-close addendum are explicitly outside V1.

## Completeness and signoff contract

Completeness items are system-derived and read-only. The client cannot submit or override PASS/FAIL values. A deterministic snapshot checks:

1. patient and episode identity are linked;
2. routine discharge evidence exists;
3. the exact Final discharge-summary version and digests remain valid;
4. the exact Final physician discharge-coding-source version and digests remain valid;
5. no current inpatient clinical document remains Draft;
6. no laboratory order remains active; and
7. normalized manual coding completely covers the exact physician source.

A saved review is an immutable Draft snapshot. Signoff requires the latest review to be current, Draft, bound to the latest complete Draft coding version/digest, and to contain zero blockers. In one transaction, signoff appends the identical-content Final coding version, recomputes the post-final snapshot, writes the immutable signed review against that exact Final version, records required audit and idempotency evidence, and changes only the encounter status from `READY_FOR_RM` to `CLOSED`.

Closure does not rewrite bed/location history, discharge evidence, discharge summary, or physician coding source. Routine discharge already released occupancy by moving the encounter out of bed-occupying states.

## HTTP and projection contract

- `GET /rm/rawat-inap` — RMIK worklist.
- `GET /rm/rawat-inap/{encounter}` — read-only sources, current coding/review, and immutable history.
- `POST /rm/rawat-inap/{encounter}/coding/draft` — append a manual Draft coding version.
- `POST /rm/rawat-inap/{encounter}/reviews` — persist the exact deterministic completeness snapshot.
- `POST /rm/rawat-inap/{encounter}/signoff` — finalize coding, sign the review, and close the episode atomically.

The Inertia contract is rooted at `inpatient_rm`. Detail actions use `save_coding_draft_url`, `save_review_url`, and `signoff_url`; all three are null after closure.

## Concurrency, evidence, and recovery

Coding and review versions use optimistic concurrency. Every mutation requires an idempotency key. Receipts bind actor, operation, request payload digest, encounter, exact physician source version/digests, exact coding version/content digest, and the exact result review when applicable. A same-key/same-payload replay returns the original result, including after closure; a same-key/different-payload request is refused. Receipt resolution is repeated after the encounter lock and after uniqueness/denial races.

Coding versions, normalized assignments, completeness reviews/items, and operation receipts are append-only at both model and database levels. PostgreSQL and MySQL guards allow deletion only inside the bounded synthetic-reset escape. The coding head has a database transition guard: only Draft-to-next-Draft or Draft-to-next-Final transitions may occur, with immutable identity/profile.

Synthetic reset removes the inpatient RMIK chain before physician discharge-coding-source evidence so foreign-key order remains safe. Migration rollback refuses while any inpatient RMIK business rows or correlated `rmik.inpatient.*` audit evidence remain. PostgreSQL/MySQL constraint names are short and distinct; down ordering drops checks/guards before dependent tables.

## Explicit exclusions

- real patient data or a production SIMRS;
- live ICD catalogue/API integration or semantic code validation;
- automatic coding, diagnosis, procedure inference, or clinical decision support;
- claims grouping, INA-CBG submission, billing, or external reporting;
- a generic reopen operation;
- post-close coding addenda or amendments; and
- deployment, migration execution outside local verification, or any external write.
