# ADR: Post-closure outpatient amendment through addendum and renewed RM review

**Status:** **PROPOSED — pending Clinical, RMIK, and joint Clinical/Laboratory/RMIK owner decisions**<br>
**Date:** 2026-08-26<br>
**Scope:** SIMRS Campus UEU synthetic outpatient teaching workflow only<br>
**Decision pack:** [OUTPATIENT_POST_CLOSURE_AMENDMENT_FR_PACK.md](../new-simrs-rebuild/phase-1/OUTPATIENT_POST_CLOSURE_AMENDMENT_FR_PACK.md)

## Context

The current outpatient contract is intentionally fail-closed: FINAL clinical documents are immutable, a `CLOSED` encounter rejects late clinical/laboratory writes, and an attributable RMIK completeness sign-off closes the encounter. Structured RJ/RM v1 explicitly deferred correction/amendment and reopening. G0 Batch C also requires a separately owned correction/amendment decision and normal, denial, correction, and failure evidence.

The teaching system needs an owner-reviewable way to demonstrate a narrowly controlled post-closure correction without silently changing the clinical/RMIK evidence or implying that SIMRS Sahabat's policy is known. No current evidence authorizes generic reopening, overwrite, deletion, real-data use, or a live integration.

## Decision

If approved, implement a separate post-closure amendment workflow:

```text
CLOSED encounter (unchanged)
  -> approved amendment request
  -> immutable clinical addendum referencing a final original document/version
  -> new source-fingerprinted RMIK amendment review
  -> renewed RMIK sign-off
```

The encounter remains `CLOSED`. Original final documents, their version histories, original RMIK review/items, and original sign-off are never modified or replaced. A renewed sign-off certifies the addendum-specific completeness snapshot; it does not erase or relabel the earlier sign-off.

The workflow is guarded by proposed server capabilities, expected versions, source fingerprints, encounter-first locking, request idempotency keys, audit registration, and atomic data-plus-audit writes. `DEC-016` remains Proposed and its active-order/late-result guards continue to apply; this ADR grants no lab exception.

## Alternatives

| Alternative | Result | Disposition |
| --- | --- | --- |
| Keep the current denial | No correction teaching path; preserves all safety controls. | Fallback when owners defer/reject. |
| Generic reopen | Re-exposes old write paths and creates poorly bounded downstream effects. | Rejected. |
| Edit/overwrite a final record or review | Breaks provenance and immutable-final semantics. | Rejected. |
| Append-only addendum and renewed review | Preserves history, confines authority, and makes re-review explicit; requires extra schema/test/operational controls. | **Proposed.** |

## Consequences

Positive:

- preserves original clinical and RMIK evidence while exposing a teachable correction loop;
- forces explicit authorization, rationale, provenance, review, and audit; and
- keeps the closed-encounter contract narrow rather than creating a backdoor reopen.

Costs and risks:

- adds request, addendum, review, idempotency, reconciliation, and retention data structures;
- requires Clinical and RMIK policy choices that engineering cannot infer; and
- must not be deployed or represented as parity/production behavior before owner approval and synthetic acceptance evidence.

## Required gates before implementation

1. Named Clinical, RMIK, and joint Clinical/Laboratory/RMIK owner decisions recorded in the companion pack.
2. Decision on reason taxonomy, role separation, withdrawal/retention, renewed-sign-off meaning, and DEC-016 interaction.
3. Review of migration/rollback/recovery plan and audit/capability schema by technical/security owner.
4. Separate synthetic acceptance evidence for normal, denial, correction/amendment, and dependency failure, plus reconciliation.
5. Indonesian UI and accessibility verification, including no generic reopen action.

## Explicit exclusions

This ADR does not approve or introduce generic reopen, overwrite/delete, real patient data, production clinical use, Antrean, Apotek, prescriptions, pharmacy, coding, billing/claims, BPJS/VClaim/E-Klaim, SATUSEHAT, LIS/PACS, any other live integration, preliminary/corrected lab results, or a generalized cancellation/order engine.

## Supersession and rollback

Until approved, existing final/closed denials remain authoritative. If the proposal is rejected, deferred, or superseded, disable/defer the proposed workflow rather than adding a database workaround. Once amendment evidence exists, rollback must preserve it and disable routes/capabilities; it must not drop populated tables or erase audit history.

## References

- `docs/new-simrs-rebuild/phase-1/OUTPATIENT_ORDER_RESULT_CLOSURE_CONTRACT.md`
- `docs/new-simrs-rebuild/phase-1/STRUCTURED_RJ_DOCUMENTATION_RM_COMPLETENESS_V1_IMPLEMENTATION_DECISION.md`
- `docs/new-simrs-rebuild/phase-0/G0_BATCH_C_CORE_CARE_RMIK_PROPOSAL_2026-08-25.md`
