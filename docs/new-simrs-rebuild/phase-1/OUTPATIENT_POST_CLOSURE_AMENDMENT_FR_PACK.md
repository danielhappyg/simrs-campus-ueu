# Outpatient post-closure amendment and renewed RM review — FR and owner-decision pack

**Status:** **PROPOSED NEW — owner decision required; no implementation approval**<br>
**Date:** 2026-08-26<br>
**Environment:** `APP_MODE=SIMULATION`, synthetic data only<br>
**Related parity capabilities:** `PAR-CLN-004`, `PAR-RMIK-001`; related lifecycle decision `DEC-016` remains Proposed<br>
**Companion ADR:** [ADR_OUTPATIENT_POST_CLOSURE_AMENDMENT_2026-08-26.md](../../operations/ADR_OUTPATIENT_POST_CLOSURE_AMENDMENT_2026-08-26.md)

## 1. Decision request and boundary

This is an implementation-ready proposal for a narrowly bounded teaching workflow:

```text
Closed outpatient encounter
  -> amendment request -> owner decision
  -> attributable clinical addendum (append-only)
  -> renewed RMIK completeness review -> renewed sign-off
```

It does **not** approve or implement the workflow. It does not establish SIMRS Sahabat parity, professional-record policy, legal electronic signatures, or production readiness. The current implementation deliberately treats a final document as immutable and a `CLOSED` encounter as non-writable; this proposal changes neither rule except through the separately authorized addendum endpoint described below.

The proposal is intentionally not a generic reopen. The encounter remains `CLOSED`; the original clinical documents, historical versions, original completeness review, and original sign-off remain immutable evidence. A successful addendum creates new evidence and requires a new RMIK completeness snapshot/sign-off before the amendment is represented as reviewed.

## 2. Evidence and constraints

| Evidence / constraint | What it supports | What it does not support |
| --- | --- | --- |
| Structured RJ/RM v1 | Final documents are versioned and immutable; a completeness snapshot has a source fingerprint and RMIK sign-off closes the encounter. | A correction policy or a reopen mechanism. |
| Outpatient order/result/closure contract | `CLOSED` rejects late result writes; active lab orders block closure; success and audit commit atomically. | Amendment, cancellation, preliminary-result, or vendor policy. |
| Current documentation service and schema | Encounter-first locking, optimistic versions, one document per type, immutable version history, server-side lifecycle denials. | A post-closure clinical write path. |
| Current RMIK service and schema | Versioned review snapshots, stale-source denial, active-order blocker, read-only clinical sources. | A renewed review of an addendum. |
| Audit schema/recorder and authorization matrix | Attributable success/denial events and server capability checks are established patterns. | A newly approved amendment role or action. |
| G0 Batch C proposal | Amendment/correction and four separated synthetic scenarios need owner evidence before closure. | Any owner appointment or approval. |

All evidence is from the synthetic rebuild. Vendor routes show that outpatient and RM desks exist, not their correction rules. `PAR-CLN-004` and `PAR-RMIK-001` therefore remain **Specified / not accepted**.

## 3. Options considered

| Option | Consequence | Decision |
| --- | --- | --- |
| A. Keep the current permanent denial | Safest and simplest; cannot demonstrate a bounded correction teaching scenario. | Retain as the fallback if owners defer/reject. |
| B. Generic encounter reopen | Makes every earlier write path reachable again and risks hidden downstream state changes. | Rejected. |
| C. Direct edit of a final document or review | Destroys provenance and conflicts with immutable FINAL/current audit intent. | Rejected. |
| D. Approved request -> append-only clinical addendum -> renewed completeness review | Preserves original evidence, narrows authority and permits a visible review loop. Adds schema, workflow, reconciliation, and owner-policy burden. | **Proposed choice.** |

## 4. Proposed architecture

### 4.1 New bounded records

The following are proposed migration targets, not current tables. Names may change in an approved implementation, but their constraints must not weaken.

| Record | Required immutable/provenance fields | Key constraints |
| --- | --- | --- |
| `outpatient_post_closure_amendment_requests` | `public_id`, `encounter_id`, `requested_by_user_id`, request reason code, bounded reason text, request state, `version`, decision actor/time/reason, `idempotency_key`, timestamps | One request is tied to one closed outpatient encounter; `(requested_by_user_id, idempotency_key)` unique; approved request may have one final addendum. |
| `outpatient_clinical_document_addenda` | `public_id`, `encounter_id`, `amendment_request_id`, referenced document public ID/type/version, author, finalizer, structured addendum fields, state, definition version, version, finalized time | No update/delete after `FINAL`; the referenced original must be final and remain unchanged; one final addendum per approved request. |
| `outpatient_clinical_document_addendum_versions` | addendum ID, actor, version, state, definition version, canonical fields, created/finalized time | Unique `(addendum_id, version)`; append-only history. |
| `outpatient_rm_completeness_amendment_reviews` and items | encounter, amendment request/addendum, baseline sign-off review ID, reviewer/sign-off actor/time, version, extended source fingerprint, state, immutable item snapshot | Unique `(encounter_id, version)` within the amendment-review series; no overwrite of the baseline review. |
| optional `outpatient_amendment_operation_receipts` | actor, operation, idempotency key, request digest, result public ID, completed time | Required if a unique key alone cannot safely return a retry's original result without duplicating writes/audit. |

Foreign keys to source documents, addenda, and signed review evidence should be `restrictOnDelete`; user references follow the established audit-attribution policy. No ordinary data-retention/deletion workflow is introduced here.

### 4.2 State model

```text
Encounter status stays CLOSED throughout

Request: DRAFT -> SUBMITTED -> APPROVED -> ADDENDUM_DRAFT -> ADDENDUM_FINAL
                           \-> DENIED
                           \-> WITHDRAWN (only before an approval; optional owner choice)

After ADDENDUM_FINAL:
  Amendment review: NOT_STARTED -> DRAFT -> SIGNED_OFF
                                      \-> STALE (derived; source changed before sign-off)
```

`WITHDRAWN` is optional because a withdrawal policy needs an owner decision. It must never delete the submitted request. `STALE` is not an editable historical state: it means the current review fingerprint no longer matches the approved addendum/baseline and a new snapshot is required.

### 4.3 Invariants

1. Only a synthetic outpatient encounter already in `CLOSED` state is eligible; the operation does not transition it to `REGISTERED`, `IN_EXAMINATION`, or `READY_FOR_RM`.
2. No endpoint updates `outpatient_clinical_documents`, their versions, current RMIK reviews, review items, lab results/orders, or encounter closure timestamp to “correct” a record.
3. An addendum references exactly one final original document/version and states the bounded reason/correction narrative; it is a new document, not a replacement.
4. A request must be `APPROVED` before an addendum draft/finalization is permitted. Only one final addendum may consume that approval.
5. An addendum final is immutable. A correction to an addendum is a new request and addendum, never an overwrite.
6. The original RMIK `SIGNED_OFF` review remains immutable. Addendum sign-off is a separate, attributable review whose fingerprint includes baseline review identity, addendum final version and applicable active-order state.
7. RMIK reviews evidence but cannot alter clinical text; clinical actors cannot sign off RMIK review through this workflow.
8. Existing `DEC-016` guards still apply: an active lab order prevents renewed sign-off; late lab results remain denied for the closed encounter. This proposal does not turn an amendment into a lab exception.
9. Every accepted mutation and its success audit event commit in one transaction. A failed audit write rolls back the mutation; a business denial is itself auditable when authorization has passed.
10. Authorization is evaluated before business-state disclosure. A wrong-role request receives the shared authorization denial, not an explanation of the closed encounter or its amendment state.

## 5. Proposed roles and capability boundary

These are candidate server capabilities; they are **not approved roles**. UI visibility must derive from them, but cannot substitute for server enforcement.

| Candidate capability | Proposed holder | Bounded action | Explicit prohibition |
| --- | --- | --- | --- |
| `clinical.outpatient.amendment.request` | Authorized clinician who identifies the discrepancy | Submit a request on a closed synthetic encounter. | Cannot approve, alter original, or self-bypass review. |
| `clinical.outpatient.amendment.approve` | Named clinical owner/supervisor delegate | Approve or deny a submitted request with rationale. | Cannot author the related addendum unless owners explicitly allow and record a separation-of-duty exception. |
| `clinical.outpatient.amendment.addendum.write` | Approved request's named clinical author | Save that request's addendum draft. | Cannot write without approval or edit a final addendum. |
| `clinical.outpatient.amendment.addendum.finalize` | Same named clinical author by default | Finalize the bounded addendum. | Cannot modify original document or encounter state. |
| `rmik.completeness.review` | Existing authorized RMIK actor | Save a new amendment completeness snapshot. | Cannot edit clinical source. |
| `rmik.completeness.signoff` | Existing authorized RMIK actor | Sign off only a current complete amendment snapshot. | Cannot reopen or waive a failed blocker. |

Break-glass/administrator access is excluded from routine amendment authoring, approval, and RMIK sign-off. Any future exception needs a separately approved capability, elevated audit schema, and owner rule.

## 6. Workflow scenarios and stable outcomes

| Scenario | Proposed server outcome | Audit action / reason |
| --- | --- | --- |
| Normal | Authorized clinician submits a request; named approver approves; named author finalizes an addendum; RMIK saves a current complete snapshot and signs it off. Encounter remains `CLOSED`. | Request, decision, draft/final, review/save, and sign-off success events; correlate to request/addendum/review IDs. |
| Denial: wrong role | Deny before fetching protected amendment or encounter workflow state. | Existing `authorization.denied` / `authorization_check_failed`. |
| Denial: non-closed/non-outpatient/non-synthetic encounter | No request is created. | `clinical.outpatient.amendment.request.submit` / `encounter_not_closed`, `not_outpatient`, or `synthetic_only`. |
| Denial: unapproved/denied/consumed request | No addendum write/finalization. | Addendum action / `request_not_approved`, `request_denied`, or `request_already_consumed`. |
| Correction | A correction never edits the original or an earlier addendum. Submit a new request that references the applicable final source, then repeat approval/addendum/review. | New correlation chain; prior chain stays readable. |
| Failure: stale version/fingerprint | Reject without mutation; reload/re-snapshot is required. | `stale_version` or `source_stale`. |
| Failure: active lab order | Reject only renewed sign-off, retain the current encounter, addendum, and draft review. | `active_lab_orders`. |
| Failure: audit persistence | Roll back data mutation and return a controlled server error; operator records/retries only after recovery. | No false success; operational incident evidence outside the failed transaction. |
| Failure: duplicate transport retry | Same actor/key/payload returns the prior result without duplicate addendum/review/audit; mismatched payload is denied. | `idempotency_key_conflict` when payload differs. |

Reason codes are proposed contracts and must be registered in `AuditEventSchemaRegistry` before any route is exposed. Audit metadata must use public IDs, versions, definitions, request correlation and bounded field-key digests only—never free-text clinical content.

## 7. Concurrency, idempotency, and transaction contract

- Lock order: **encounter -> amendment request -> referenced source/addendum -> latest amendment review**. Reuse this order for request decision, addendum finalization, and sign-off to avoid lock inversion.
- Require `expected_version` for state-changing request, addendum, and review operations. Require `expected_source_fingerprint` for review save/sign-off, calculated from baseline signed review, all final addenda in scope, and active-order identifiers.
- Submit/create operations require a client-generated idempotency key and canonical request-payload digest. Same key + same actor + same digest is a successful replay; same key with a different digest is a `422` denial. Keys must be sufficiently random and retained for the synthetic scenario's reconciliation window.
- The finalization transaction rechecks that the request is approved and unconsumed, the original is final, the encounter is closed/outpatient/synthetic, and the actor is the approved author. It then locks/marks the request consumed, writes the immutable addendum/version, and records audit success atomically.
- The sign-off transaction rechecks the current fingerprint and all current blockers after it acquires locks. Browser disabled states are guidance only.

## 8. Proposed UI and accessibility contract

Indonesian is primary. The persistent banner remains **“SIMULASI — DATA SINTETIS”**.

| Surface | Required labels/behavior |
| --- | --- |
| Closed encounter | **“Kunjungan sudah ditutup”**, **“Ajukan Permintaan Addendum”**, a read-only link to the original sign-off, and no **“Buka Kembali Kunjungan”** action. |
| Request | **“Alasan Addendum”**, **“Dokumen yang Dirujuk”**, **“Kirim Permintaan”**, **“Menunggu Keputusan”**, **“Disetujui”**, **“Ditolak”**. Reasons are bounded and explain why the request exists, not a copied clinical record. |
| Approval | **“Setujui Permintaan”**, **“Tolak Permintaan”**, **“Catatan Keputusan”**, actor/time, and explicit named author. |
| Addendum | **“Addendum Catatan Klinis”**, **“Merujuk Catatan Final Versi …”**, **“Simpan Draf”**, **“Finalisasi Addendum”**, **“Addendum final tidak dapat diubah.”** |
| RMIK loop | **“Pemeriksaan Ulang Kelengkapan RM”**, **“Sumber berubah—muat ulang”**, **“Masih ada order laboratorium aktif”**, **“Tanda Tangan Ulang Kelengkapan RM”**. Never label the original review as replaced. |

All fields need persistent visible labels, programmatic name/role/value, descriptive error association, keyboard-operable controls, focus moved to the validation summary after submit failure, status changes announced with `aria-live`, non-colour-only state indicators, and a minimum 44 by 44 CSS-pixel pointer target. The three-column outpatient density may stack on smaller widths without hiding encounter identity, amendment status, or the synthetic-data warning. No protected clinical text goes into toast, URL, title, telemetry, or audit metadata.

## 9. Migration, rollback, recovery, and reconciliation

### Migration plan

1. Add the new tables, enum/check constraints, foreign keys, unique indexes, audit action schemas, capabilities, policies, and route/controller/service seams in one reviewed release.
2. Backfill **nothing**. Historical `CLOSED` encounters receive no implicit requests, addenda, or renewed reviews.
3. Verify PostgreSQL `laravel` schema qualification and the supported local test database. Run migrations on an isolated synthetic copy before any hosted rehearsal.
4. Expose UI only after owner approval, capability assignment, and completed acceptance evidence.

### Rollback and recovery

- Before any evidence exists, an ordinary rollback may remove only the new empty structures.
- After a request, addendum, review, or audit event exists, the migration `down()` must refuse to drop populated evidence. Disable routes/capabilities to stop new work; retain records for diagnosis and reconciliation. Do not use `DELETE`, manual SQL correction, or migration rollback to erase an amendment chain.
- If a deployment fails before transaction commit, the database must contain neither a partial mutation nor a success audit event. If it fails after commit but before a response, retry with the same idempotency key and reconcile the receipt/event instead of submitting again.
- Recovery means restore service availability, validate schema/audit registry/capabilities, reconcile transactions below, then resume only the affected synthetic scenario. It is not an authorization to reopen encounters or alter history.

### Reconciliation controls

For each affected synthetic encounter, reconcile:

1. exactly one request per unique successful request key, with attributable decision;
2. zero-or-one final addendum per approved request and matching immutable version history;
3. each final addendum points to an unchanged final original document/version;
4. each amendment sign-off has one current fingerprint, a matching baseline review, complete item snapshot, no active lab orders, and a matching success audit event;
5. every accepted mutation has one correlated success event, and denials have registered stable reasons without clinical payload leakage; and
6. no encounter status transition out of `CLOSED`, no original document update, no original RMIK sign-off update, and no real/non-synthetic patient association.

## 10. Acceptance and test matrix

| Dimension | Minimum synthetic evidence |
| --- | --- |
| Normal | Closed synthetic encounter -> approved request -> final addendum -> current complete renewed review -> renewed RMIK sign-off; original document/review and encounter `CLOSED` unchanged. |
| Authorization | Each wrong role gets 403 before business-state disclosure; RMIK cannot write addendum; clinical actor cannot sign off. |
| Immutability/correction | Attempts to update/delete original/final addendum or overwrite a prior review fail; second correction follows a new request chain. |
| Lifecycle | Open/non-outpatient/non-synthetic encounters denied; DEC-016 active-order and late-result guards remain intact. |
| Concurrency | Two approvals/finalizations/sign-offs and stale browser submissions yield one durable winner, one stable stale/consumed denial, and no duplicate records. |
| Idempotency | Retry same key/payload returns the same result; key collision with changed payload is denied; success audit not duplicated. |
| Failure/atomicity | Force audit-write failure for request decision, addendum final, and sign-off; assert no data mutation survives. |
| Schema and audit | Unique/FK/check constraints, audit registry variants, safe metadata guards, actor attribution, PostgreSQL `laravel` schema and local database pass. |
| UI/a11y | Indonesian labels, keyboard-only flow, focus/error/announcement checks, narrow layout, disabled reason, and no hidden generic reopen. |
| Facilitator UAT | Four separately recorded scenarios: normal, denial, correction/amendment, dependency outage/failure; use isolated fixtures and reconcile after each. |

## 11. Traceability and exclusions

| Proposed requirement | Workflow evidence | Owner accountable |
| --- | --- | --- |
| `FR-CLN-RJ-AMEND-001` | Submit and decide a post-closure amendment request without changing closed encounter history. | Clinical owner |
| `FR-CLN-RJ-AMEND-002` | Write/finalize an attributable, append-only clinical addendum against an approved request. | Clinical owner |
| `FR-RMIK-RJ-AMEND-001` | Produce a fresh source-fingerprinted RM completeness review after an addendum. | RMIK Department |
| `FR-RMIK-RJ-AMEND-002` | Renew sign-off only when current completeness and lifecycle guards pass. | RMIK Department + Clinical/Laboratory for DEC-016 impact |
| `NFR-AMEND-001` | Enforce synthetic-only authorization, audit, idempotency, immutability, and accessibility. | Technical/security consultation; domain owners accept scope |

This proposal explicitly excludes generic encounter reopen, original-record overwrite, deletion, real patient data, production use, Antrean, Apotek, prescriptions, pharmacy/inventory, coding, billing, claims, BPJS, VClaim, E-Klaim, SATUSEHAT, LIS, PACS, any live integration, preliminary/corrected lab results, and a general cancellation engine.

## 12. Owner sign-off record

No blank row is approval. An approval must identify the named authority, decision, dated conditions, supporting evidence, and whether it changes this proposed design.

| Domain / required decision | Authorized owner | Approve / revise / reject / defer | Conditions or revisions | Evidence reviewed | Date |
| --- | --- | --- | --- | --- | --- |
| Clinical |  |  | Reason taxonomy, addendum content, author/finalizer and approver separation. |  |  |
| RMIK Department |  |  | Renewed review/sign-off meaning, retention, and separation of duties. |  |  |
| Clinical/Laboratory + RMIK |  |  | Confirm DEC-016 blockers still apply to renewal; identify any superseding decision. |  |  |
| Technical/security |  |  | Capability, audit, migration, idempotency, recovery and accessibility controls. |  |  |
| Product scope acknowledgement | Daniel Happy Putra / delegate |  | Synthetic teaching scope only; no production/integration approval. |  |  |

**Consequence:** approve-with-revisions requires this pack, ADR, schema, services, tests, Indonesian UI, runbook, and UAT script to change together. Reject/defer leaves the existing final/closed denial behavior in place and does not authorize a workaround.

## References

- `docs/new-simrs-rebuild/phase-1/STRUCTURED_RJ_DOCUMENTATION_RM_COMPLETENESS_FR_PACK.md`
- `docs/new-simrs-rebuild/phase-1/STRUCTURED_RJ_DOCUMENTATION_RM_COMPLETENESS_V1_IMPLEMENTATION_DECISION.md`
- `docs/new-simrs-rebuild/phase-1/STRUCTURED_RJ_RM_OWNER_DECISION_PACK_2026-08-25.md`
- `docs/new-simrs-rebuild/phase-1/OUTPATIENT_ORDER_RESULT_CLOSURE_CONTRACT.md`
- `docs/new-simrs-rebuild/phase-0/G0_BATCH_C_CORE_CARE_RMIK_PROPOSAL_2026-08-25.md`
- `app/Support/Clinical/OutpatientDocumentationService.php`
- `app/Support/Clinical/OutpatientRmCompletenessService.php`
- `app/Support/Audit/AuditEventSchemaRegistry.php`
- `app/Support/Authorization/RoleCapabilityMatrix.php`
