# Structured RJ/RM v1 owner decision pack — 2026-08-25

**Status:** DRAFT FOR OWNER DECISION
**Scope:** Synthetic teaching workflow only
**Related parity:** PAR-CLN-004, PAR-CLN-006, PAR-RMIK-001
**Related decision:** DEC-016 remains Proposed

## Decision boundary

This pack asks authorized domain owners to accept, revise, reject, or defer the bounded teaching behaviour already implemented. It does not ask them to approve real-patient use, legal electronic signatures, complete clinical-record standards, live integrations, or full SIMRS Sahabat parity.

Engineering evidence may show that a rule works as implemented. It cannot establish that the rule is the correct Clinical, Laboratory, or RMIK policy.

## Current evidence snapshot

- Exact deployed baseline: `aabfff562dbe75d022da203f3f44215b055be614`.
- Structured documentation and RM migration is present on the hosted Supabase `laravel` schema.
- Automated gate passed: 151 PHP tests / 1,183 assertions and 20 frontend tests, plus static and formatting checks.
- Focused hosted authenticated Structured RJ/RM v1 UAT remains pending.
- Hosted lifecycle Run 4 passed the earlier continuous RJ flow but did not test the new structured Draft/Final and RM completeness screens.
- The implementation remains teaching-available and not parity-accepted.

## Decision response

For every row, record one of:

- **Approve as written**
- **Approve with revisions**
- **Reject**
- **Defer**

Every approval record requires the owner name/role, conditions or revisions, evidence considered, and date.

## A. Clinical owner decisions

| ID | Decision required | Implemented v1 behaviour | Owner response / conditions |
| --- | --- | --- | --- |
| CLN-01 | Nursing content boundary | `Asesmen keperawatan` is required; `Catatan tambahan` is optional | |
| CLN-02 | Medical content boundary | `Anamnesis`, `Pemeriksaan objektif`, `Asesmen klinis`, and `Rencana pelayanan` are required; additional notes are optional | |
| CLN-03 | Applicability | Both Final nursing and Final medical documents are required for every bounded RJ encounter | |
| CLN-04 | Draft/Final meaning | Explicit Final action; author/finaliser attribution; Final is immutable; no correction, deletion, amendment, or reopen in v1 | |
| CLN-05 | Encounter transition | First structured write starts examination; Medical Final moves the encounter to `READY_FOR_RM` | |
| CLN-06 | Professional finalisation | One authorized professional may finalise their own document; learner/supervisor workflow is deferred | |
| CLN-07 | Visible context | Patient and visit context remains visible, but v1 adds no invented vital ranges, allergy rules, diagnoses, prescriptions, disposition, or legal-signature claim | |

## B. RMIK Department decisions

| ID | Decision required | Implemented v1 behaviour | Owner response / conditions |
| --- | --- | --- | --- |
| RMIK-01 | Completeness source | Only Final documentation counts as complete | |
| RMIK-02 | Automatic checklist | Checks encounter linkage, Final nursing provenance, Final medical required fields/provenance, and active lab orders | |
| RMIK-03 | Review authority | RMIK assesses completeness but cannot edit clinical facts | |
| RMIK-04 | Sign-off model | One holder of `rmik.completeness.signoff` may review and close in v1 | |
| RMIK-05 | Review provenance | Review version, reviewer/time, checklist snapshot, and source fingerprint are retained | |
| RMIK-06 | Incomplete records | Close remains unavailable while the current review is incomplete; correction and reopen workflows remain deferred | |
| RMIK-07 | Student boundary | RMIK student/supervisor review is not included in v1 | |

## C. Joint Clinical/Laboratory/RMIK decision — DEC-016

| ID | Decision required | Implemented fail-closed behaviour | Owner response / conditions |
| --- | --- | --- | --- |
| LABRM-01 | Active order at RM close | Any `ACTIVE` lab order blocks RM closure | |
| LABRM-02 | Result status | Only one immutable `FINAL` result is accepted in v1 | |
| LABRM-03 | Closed encounter | A `CLOSED` encounter rejects a late result without mutation | |
| LABRM-04 | Duplicate/non-active order | Duplicate Final and non-active-order submissions fail without mutation | |
| LABRM-05 | Deferred lifecycle | Preliminary results, result amendment, order cancellation, and encounter reopen remain unbuilt | |
| LABRM-06 | DEC-016 disposition | Accept, revise, reject/supersede, or explicitly defer the Proposed teaching policy | |

## Explicitly deferred from this decision

- diagnoses and ICD coding;
- procedures, prescriptions, pharmacy and inventory effects;
- tariffs, charges, cashier, claims and accounting effects;
- clinical correction/amendment and encounter reopen;
- learner/supervisor co-signature;
- preliminary laboratory results and result correction;
- radiology/PACS and LIS;
- BPJS/VClaim/SATUSEHAT and all live integrations; and
- real patient data or clinical-production operation.

## Approval record

| Domain | Authorized owner | Decision | Conditions / required revision | Evidence considered | Date |
| --- | --- | --- | --- | --- | --- |
| Clinical | | | | | |
| Laboratory | | | | | |
| RMIK | RMIK Department / named delegate | | | | |
| Product scope acknowledgement | Daniel Happy Putra | | | | |

## Consequence rules

1. **Approve as written:** update the applicable DEC/parity evidence only after focused hosted UAT passes.
2. **Approve with revisions:** revise the FR pack, implementation, tests, UI labels, runbook, and UAT script together; do not patch only the screen.
3. **Reject:** create a superseding decision and remove or replace the rejected behaviour safely.
4. **Defer:** retain the current behaviour only as explicitly unaccepted fail-closed teaching behaviour; parity status remains unchanged.
5. No decision in this pack authorizes real data or a live external integration.

## References

- `docs/operations/T0_CURRENT_TRUTH_BASELINE_2026-08-25.md`
- `docs/new-simrs-rebuild/phase-1/STRUCTURED_RJ_DOCUMENTATION_RM_COMPLETENESS_FR_PACK.md`
- `docs/new-simrs-rebuild/phase-1/STRUCTURED_RJ_DOCUMENTATION_RM_COMPLETENESS_V1_IMPLEMENTATION_DECISION.md`
- `docs/new-simrs-rebuild/phase-1/OUTPATIENT_ORDER_RESULT_CLOSURE_CONTRACT.md`
- `docs/new-simrs-rebuild/phase-0/DECISION_LOG.md`
- `docs/new-simrs-rebuild/phase-0/OWNERS_AND_RACI.md`
