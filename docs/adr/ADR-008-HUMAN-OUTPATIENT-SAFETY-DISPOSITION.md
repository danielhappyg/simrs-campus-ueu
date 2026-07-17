# ADR-008: Human Outpatient Safety Disposition

- **Status:** Accepted for implementation; stakeholder validation pending
- **Date:** 2026-07-17
- **Final decision authority:** Daniel Happy Putra, project manager/PIC
- **Implementation authority:** delegated autonomous product and engineering work under DEC-008
- **Scope:** `E2E-02`, `OPD-005`, `OPD-006`, `SAF-04`, and `VAL-A01`–`VAL-A04`

## Context

The outpatient reference flow already lets a nursing learner record a scenario-specific safety screen and choose `ESCALATE_TO_SUPERVISOR`. After the linked nursing supervisor approves that exact intake version, the encounter moves to `ESCALATED` and the routine medical-assessment task is blocked. The implementation stops there: no authorized human can yet record whether the scenario should resume its routine outpatient flow or be represented as a simulated transfer.

This is not an emergency-department triage protocol. The current product assumption deliberately calls the stage `Asesmen Awal dan Skrining Keselamatan`, contains no clinical thresholds in core code, and treats its questions as unvalidated scenario content. The software must therefore preserve human judgment, avoid diagnosis or treatment advice, and keep the remaining teaching options visibly pending medicine/nursing approval.

Indonesian patient-safety and hospital-accreditation instruments support an explicit patient-safety workflow, but they do not justify inventing a universal clinical algorithm for this campus simulator. FHIR R4 also treats Encounter status as workflow state and provides Task fields for human workflow context; it does not make a local status transition a clinical recommendation or a SATUSEHAT submission.

## Decision

1. Add one append-only `outpatient_safety_dispositions` record for each escalated synthetic encounter.
2. A disposition is bound to:
   - the encounter and simulation session;
   - the exact approved nursing-intake version and content hash that caused the escalation;
   - one active actor assignment and user;
   - one request key, outcome, required rationale, and occurrence time.
3. The only reference-build outcomes are:
   - `RESUME_ROUTINE_FLOW`, which transitions `ESCALATED` to `WAITING_CLINICIAN` and makes the existing medical-assessment task ready; and
   - `SIMULATED_TRANSFER`, which transitions `ESCALATED` to `TRANSFERRED_SIMULATION` and cancels the routine medical-assessment task.
4. These outcomes are workflow dispositions for a synthetic teaching scenario. They are not diagnoses, treatment recommendations, real referral instructions, emergency acuity categories, or faculty-approved clinical protocol.
5. Add a narrow `safety-disposition.record` capability. The linked supervisor and the session facilitator receive it in the reference fixture. Authorization still requires an active simulation session plus exact-case supervisor or deliberate session-wide facilitator scope.
6. When an approved nursing escalation is created, generate a ready `SAFETY_DISPOSITION` work task for the linked nursing supervisor and, when different, the active session facilitator. The medical task remains blocked.
7. The first valid disposition completes the actor task and cancels any duplicate disposition task. A resumed flow readies the medical task; a simulated transfer cancels it. No option is preselected or inferred.
8. The disposition service locks the encounter, session, source version, assignment, and competing disposition state in one database transaction. A repeated matching request key returns the existing disposition; reuse for different content fails. A later or competing disposition fails without partial task/status updates.
9. The disposition model is append-only. Its rationale is protected domain content and is not copied into task context.
10. Record `clinical.outpatient_safety_disposition_recorded` with actor, assignment, session, encounter, request correlation, and minimized metadata: outcome, source nursing version public ID/hash, and task-status counts. The rationale uses the audit event's protected `reason` field and is excluded from metadata.
11. The workspace permanently displays `SIMULASI — DATA SINTETIS`, `ALUR RUTIN DIHENTIKAN`, `BUKAN TRIAGE IGD`, and `BUKAN REKOMENDASI KLINIS`. It shows the exact source version/hash and human-authored escalation context, but offers no recommendation, severity score, threshold, or default outcome.
12. Direct workspace access after disposition is read-only while the simulation session remains active. Revoked, unrelated, wrong-case, wrong-session, or capability-missing assignments receive `403`.
13. This increment remains subject to `VAL-A01`–`VAL-A04`. It does not claim faculty validation, emergency clinical safety, SATUSEHAT conformance, merge approval, or deployment readiness.

## Options considered

### A. Append-only disposition plus encounter transition — selected

| Dimension | Assessment |
| --- | --- |
| Human accountability | High |
| Idempotency/concurrency | High |
| Clinical overclaim | Low with permanent boundaries |
| Implementation complexity | Moderate |
| Future teaching configurability | High |

This preserves the clinical source, the workflow decision, and the encounter state as separate but linked evidence.

### B. Store only an EncounterTransition reason

Rejected. EncounterTransition proves state movement, but a free-text reason plus target state is too weak for durable request idempotency, exact source-version binding, and a future configurable disposition vocabulary.

### C. Reuse the nursing approval record as the disposition

Rejected. Approving documentation quality is not the same decision as authorizing the next workflow state. Combining them would hide a meaningful human decision and weaken audit interpretation.

### D. Add automated thresholds or a recommended outcome

Rejected. No approved threshold ruleset or clinical protocol exists, and the reference system must not create treatment or emergency-triage advice.

## Trust boundary

```text
Approved nursing escalation (exact immutable version/hash)
        |
        v
Encounter ESCALATED + medical task BLOCKED
        |
        v
Active exact-case supervisor OR session-wide facilitator
with safety-disposition.record
        |
        v
Required human choice + rationale + request key
        |
        +--> RESUME_ROUTINE_FLOW --> WAITING_CLINICIAN + medical READY
        |
        +--> SIMULATED_TRANSFER --> TRANSFERRED_SIMULATION + medical CANCELLED
        |
        +--> append-only disposition + encounter transition + minimized audit
```

## Consequences

- `E2E-02` becomes executable end to end without adding clinical automation.
- A facilitator can act when the linked supervisor is unavailable, but only through an explicit capability and session-wide assignment.
- Competing human decisions are rejected transactionally rather than silently overwriting one another.
- Faculty still must validate the question set, wording, and allowed teaching dispositions before a pilot.
- A future configuration layer may replace the two reference outcomes, but it must preserve human authorship, source binding, auditability, and non-recommendation boundaries.

## Revisit triggers

Amend this ADR when medicine/nursing faculty approve a disposition vocabulary, a hospital SOP is supplied, an emergency workflow is added, more than one escalation cycle per encounter is required, or disposition data must be mapped externally.

## References

- Ministry of Health, [Permenkes No. 11 Tahun 2017 tentang Keselamatan Pasien](https://jdih.kemkes.go.id/documents/peraturan-menteri-kesehatan-nomor-11-tahun-2017)
- Ministry of Health, [Kepmenkes HK.01.07/Menkes/1596/2024 tentang Standar Akreditasi Rumah Sakit](https://jdih.kemkes.go.id/documents/keputusan-menteri-kesehatan-nomor-hk0107menkes15962024)
- Ministry of Health, [SATUSEHAT outpatient interoperability playbook](https://satusehat.kemkes.go.id/platform/docs/id/interoperability/rme-rawat-jalan/)
- WHO, [Global Patient Safety Action Plan 2021–2030](https://www.who.int/publications/i/item/9789240032705)
- HL7, [FHIR R4 Encounter](https://hl7.org/fhir/R4/encounter.html)
- HL7, [FHIR R4 Task definitions](https://hl7.org/fhir/R4/task-definitions.html)
- [Outpatient Acceptance Scenarios](../product/OUTPATIENT_ACCEPTANCE_SCENARIOS.md)
- [Assumption and Validation Register](../product/ASSUMPTION_AND_VALIDATION_REGISTER.md)
