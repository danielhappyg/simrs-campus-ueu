# Outpatient post-closure amendment — local engineering authorization

- Status: **LOCAL WORKING-TREE ENGINEERING IMPLEMENTATION AND DETERMINISTIC TESTING AUTHORIZED; DOMAIN AND PARITY ACCEPTANCE OPEN**
- Date: 2026-08-30
- Product owner: Daniel Happy Putra
- Environment: `APP_MODE=SIMULATION`; synthetic data only
- Scope: `PAR-CLN-004` and `PAR-RMIK-001` teaching mechanics only
- Related decision: `DEC-016` remains **Proposed**

## Bound proposal bytes

This record authorizes a closed local engineering profile over these exact proposal bytes. It does not change their Clinical/RMIK owner-decision rows or represent either proposal as domain-approved.

| Source | SHA-256 |
| --- | --- |
| [`OUTPATIENT_POST_CLOSURE_AMENDMENT_FR_PACK.md`](OUTPATIENT_POST_CLOSURE_AMENDMENT_FR_PACK.md) | `6c7174fafda1b5fb1ee76a9841349a639b5ce985904ba6ebec8fba6118c3723b` |
| [`ADR_OUTPATIENT_POST_CLOSURE_AMENDMENT_2026-08-26.md`](../../operations/ADR_OUTPATIENT_POST_CLOSURE_AMENDMENT_2026-08-26.md) | `62469954f9d4ece51ddb175a5a300edd71c05a09dcbb80020d172e819d4f3eff` |

If either source hash changes, this authorization fails closed until an updated local engineering record explicitly binds the new bytes.

## Closed teaching profile

The locally implementable workflow is:

```text
CLOSED outpatient encounter (unchanged)
  -> request by physician
  -> decision by a different physician
  -> append-only addendum authored and finalized by the requesting physician
  -> addendum-specific RMIK completeness snapshot and renewed sign-off
```

The original Final clinical document, its versions, the original RMIK review/items/sign-off, encounter status and closure time remain immutable. There is no generic reopen and no withdrawal transition in this profile.

### Reasons and actors

- The exact request reason codes are `CLINICAL_CORRECTION`, `MISSING_INFORMATION`, `WRONG_ENTRY`, and `OTHER`; unknown values fail validation.
- `OTHER` requires a trimmed `reason_note` of 1–500 Unicode scalar values. For the other codes the note is optional, but when present has the same 500-scalar maximum.
- The requesting physician is the only author and finalizer for that request: `requested_by_user_id == author_user_id == finalized_by_user_id`.
- A different physician approves or denies: `decision_by_user_id != requested_by_user_id`. The requester cannot decide; the decision actor cannot author or finalize the related addendum.
- Administrator or break-glass status grants no routine request, decision, addendum, review or sign-off authority.

### State and evidence

- Request states are exactly `SUBMITTED`, `APPROVED`, `DENIED`, and `CONSUMED`. An approved request becomes `CONSUMED` only when its Final addendum commits. No `DRAFT` request and no `WITHDRAWN` state or action is authorized.
- Addendum states are exactly `DRAFT` and `FINAL`; addendum state is not stored in the request-state field.
- An approved request can produce at most one Final addendum. A denied or consumed request cannot write an addendum.
- Every Final addendum and every historical addendum version is append-only. A correction to an addendum starts a new request and chain.
- A renewed RMIK sign-off means only: **the addendum-specific completeness snapshot was reviewed and passed at that source fingerprint**. It never replaces, revises, erases, or re-labels the original completeness review or original sign-off.
- An RMIK actor may review/sign off under the existing narrow RMIK capabilities, but cannot edit clinical text. A physician in this workflow cannot perform RMIK review/sign-off unless independently authorized by the existing RMIK capability matrix; this record creates no such assignment.

### Fingerprint and lifecycle guard

The addendum-review source fingerprint is lowercase SHA-256 over UTF-8 canonical JSON for an object with exactly:

1. profile string `outpatient-amendment-source-fingerprint-v1`;
2. baseline signed review public identity, version and stored source fingerprint;
3. every Final addendum public ID, version and canonical content digest, ordered by public ID bytewise; and
4. every currently `ACTIVE` lab-order public ID, ordered bytewise.

Canonical JSON recursively sorts object keys bytewise, preserves array order, emits no insignificant whitespace, and is hashed without a trailing newline. An addendum content digest uses the same rule over its definition version plus bounded structured addendum fields; free-text content is never copied into audit metadata.

Any active lab order blocks renewed RMIK sign-off. The existing closed-encounter late-result denial remains unchanged. This is a retained fail-closed teaching guard only: `DEC-016` remains Proposed and no Clinical/Laboratory or RMIK acceptance is inferred.

### Replay, capabilities and audit

Every accepted state-changing operation is keyed by the exact tuple `(actor_user_id, operation, idempotency_key)` and stores a lowercase SHA-256 canonical request digest. A retry with the same tuple and digest returns the original result without duplicate mutation or success audit; the same tuple with a different digest fails with `idempotency_key_conflict`. Authorization, encounter-first locking, expected versions, current fingerprint recheck and atomic mutation-plus-audit remain mandatory.

The implementation must use these separate server capabilities; UI visibility is not authorization:

- `clinical.outpatient.amendment.request`
- `clinical.outpatient.amendment.approve`
- `clinical.outpatient.amendment.addendum.write`
- `clinical.outpatient.amendment.addendum.finalize`
- `rmik.completeness.review`
- `rmik.completeness.signoff`

The approval capability permits approve/deny only. It does not imply request, author, finalizer, RMIK, administrator or break-glass authority. Audit metadata contains public IDs, versions, codes, operation correlation and digests only—not reason notes or clinical text.

### Retention, rollback and reset

- No backfill is authorized.
- Empty, never-used amendment structures may be rolled back locally.
- Once any request, addendum/version, amendment review/item, replay receipt or correlated audit evidence exists, rollback/down must refuse to drop the populated structures. Disable the narrow routes/capabilities and retain evidence for reconciliation.
- Synthetic reset may remove the patient-domain chain through declared database cascades while preserving the separate reset audit record; it is not an ordinary amendment deletion path.

## Authority matrix

| Authority | Current value |
| --- | --- |
| Create local application code, migrations, fixtures and deterministic tests for the exact profile above | `true` |
| Run local synthetic migrations and tests on disposable/local test databases | `true` |
| Produce local synthetic verification evidence | `true` |
| Clinical owner acceptance | `false` |
| RMIK owner acceptance | `false` |
| Clinical/Laboratory acceptance or `DEC-016` approval | `false` |
| Sahabat parity acceptance, G0 closure or G3 acceptance | `false` |
| Real patient data or production clinical use | `false` |
| BPJS, VClaim, E-Klaim, SATUSEHAT, LIS, PACS or any live integration | `false` |
| Commit, push, pull request, release or publication | `false` |
| Hosted migration or deployment | `false` |

Blank owner-decision rows in the FR pack remain blank and are not consent. Local engineering evidence may show that this teaching profile works; it cannot convert any `false` above to `true`.

## Completion boundary

Local engineering completion requires the FR pack's schema, immutability, authorization/separation, concurrency, replay, audit-atomicity, rollback/refusal, lifecycle, accessibility and reset tests. Any change to reason codes, actor separation, withdrawal, fingerprint inputs, active-lab behavior, retention, capability assignments, or renewed-sign-off meaning requires a new explicit record.
