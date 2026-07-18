# ADR-009: Human-Recorded Outpatient Early Departure

- **Status:** Implemented with automated and bounded browser evidence; faculty validation pending
- **Date:** 2026-07-18
- **Final decision authority:** Daniel Happy Putra, project manager/PIC
- **Implementation authority:** delegated autonomous product and engineering work under DEC-008
- **Scope:** later clinical-stage termination gap in `E2E-06`, `OPD-001`, `EMR-001`, `AUD-01`, and `SAF-04`

## Context

The reference MVP already distinguishes a pre-service appointment cancellation from an overdue no-show. Both are registrar actions and are limited to coherent `PLANNED` or `ARRIVED` states. Once nursing or medical service has begun, the application correctly rejects that registrar endpoint, but it has no bounded way to record that the synthetic patient requested to leave before the routine outpatient journey was completed.

This is not a late cancellation and cannot be represented as `NO_SHOW`: the patient attended and prior service must remain attributable. It is also not the existing safety-disposition transfer, which is limited to an approved nursing escalation.

Permenkes 24/2022 requires the electronic record to span patient arrival through discharge/referral/death and requires clinical information to be complete, clear, chronological, and attributable. The SATUSEHAT outpatient playbook represents a completed visit as a finished Encounter and maps “Pulang atas permintaan sendiri” to `Encounter.hospitalization.dischargeDisposition` code `aadvice` (“Left against advice”). FHIR R4 keeps encounter lifecycle status separate from discharge disposition.

The campus system remains a synthetic teaching reference. It must therefore record the human event and its provenance without offering clinical advice, deciding whether departure is safe, or claiming that an incomplete chart is ready for coding/finalization.

## Decision

1. Add a distinct append-only outpatient early-departure aggregate with the single bounded outcome `PATIENT_REQUESTED_DEPARTURE` and the interoperability mapping `aadvice`.
2. Add distinct internal terminal states:
   - encounter: `DEPARTED_ON_REQUEST`;
   - appointment: `DEPARTED_ON_REQUEST`.
   These states must never be presented as cancellation, no-show, routine clinical closure, transfer, or finalization.
3. Allow recording only after clinical service has begun and before clinical closure: `IN_INTAKE`, `WAITING_CLINICIAN`, `IN_CONSULTATION`, `AWAITING_RESULT`, `AWAITING_PHARMACY`, or `CLOSURE_PENDING`.
4. Exclude `ARRIVED` because the existing registrar cancellation remains the bounded pre-clinical action. Exclude `ESCALATED` because that state already has a separate human safety-disposition contract. Exclude all post-closure and terminal states.
5. Authorize only:
   - the exact case-scoped medical supervisor with the dedicated `early-departure.record` capability; or
   - the session-wide facilitator with the same capability.
   Registrar, learner, unrelated supervisor, administrator, revoked assignment, and cross-session/cross-case access are denied at the server.
6. Require an explicit human confirmation, a 10–1000 character patient/representative-stated reason, and a 10–2000 character factual communication summary. The interface provides no default clinical verdict, treatment recommendation, risk score, or “safe to leave” wording.
7. Persist exact actor assignment/user, source encounter status, occurrence time, request key, and a minimized clinical-source snapshot containing only current nursing/medical public IDs, version numbers, statuses, and content hashes. Store the snapshot integrity hash. Clinical text is not copied into the snapshot.
8. Make the row append-only and unique per encounter. A repeated identical request key is idempotent; a competing request or a different context is rejected.
9. In one transaction:
   - create the departure record;
   - transition the encounter to `DEPARTED_ON_REQUEST` and set its period end;
   - update the appointment to `DEPARTED_ON_REQUEST` while preserving check-in time;
   - complete any active public queue event with a non-sensitive machine reason;
   - cancel unfinished work tasks while preserving completed work; and
   - append minimized audit metadata without the stated reason or communication summary.
10. Expose a dedicated read/write workspace from the encounter overview only when the server-derived action is available. After recording, the same workspace is read-only and shows actor, time, source status, source hashes, reason, and communication summary.
11. Keep the longitudinal record available under its existing contextual policy and add a curated early-departure event that excludes free text from the event card.
12. Do not automatically create an approved encounter closure, RMIK completeness approval, diagnosis, coding assignment, final report, debrief release, or SATUSEHAT transmission. A later policy may define how incomplete early-departure charts enter RMIK review.

## Component and data flow

```text
Exact medical supervisor or session facilitator
        |
        v
Dedicated capability + case/session + source-state gate
        |
        v
Explicit confirmation + stated reason + communication summary
        |
        v
Transactional early-departure service
        |
        +--> append-only departure row + source snapshot/hash
        +--> encounter DEPARTED_ON_REQUEST + period end
        +--> appointment DEPARTED_ON_REQUEST
        +--> queue COMPLETED; unfinished tasks CANCELLED
        +--> minimized audit and curated timeline event
        |
        v
Read-only attributable result; no clinical recommendation/finalization
```

## Options considered

### A. Distinct early-departure event and terminal state — selected

| Dimension | Assessment |
| --- | --- |
| Semantic accuracy | High; attendance and prior care remain explicit |
| Clinical overclaim | Low; no safety verdict or automatic closure |
| Auditability | High; append-only actor, time, state, and source snapshot |
| Implementation complexity | Moderate |
| Future interoperability | Clear `aadvice` mapping without transmission |

### B. Reuse appointment cancellation

Rejected because it would imply the attended clinical service never occurred and would give a registrar a clinical-stage action.

### C. Reuse the safety transfer outcome

Rejected because a patient-requested departure is not equivalent to an escalated safety transfer and may occur without an approved nursing escalation.

### D. Auto-create an approved clinical closure and release RMIK/coding

Rejected because required clinical sources may be incomplete. Manufacturing an approved closure would overstate what was documented and reviewed.

## Consequences and trade-offs

- The application gains an executable later-stage stop path while preserving the semantic difference between appointment administration and clinical disposition.
- An early-departure encounter is terminal but not finalized. This is intentional and visible.
- The branch cannot currently produce finalized reports, debrief release, or coding completion. A later faculty-approved incomplete-record policy must decide whether and how RMIK reviews these cases.
- The vocabulary, authorized roles, form content, and teaching usefulness remain assumptions until multidisciplinary validation.

## Revisit triggers

Amend this ADR when faculty approves a wider disposition vocabulary, an incomplete-chart RMIK workflow, consent/signature requirements, disclosure/export policy, or real SATUSEHAT profile mapping.

## References

- Ministry of Health, [Permenkes 24/2022 on Medical Records](https://jdih.kemkes.go.id/documents/peraturan-menteri-kesehatan-nomor-24-tahun-2022)
- Ministry of Health, [SATUSEHAT outpatient interoperability playbook](https://satusehat.kemkes.go.id/platform/docs/id/interoperability/rme-rawat-jalan/)
- Ministry of Health, [SATUSEHAT Encounter resource](https://satusehat.kemkes.go.id/platform/docs/id/fhir/resources/encounter/)
- HL7, [FHIR R4 Encounter](https://hl7.org/fhir/R4/encounter.html)
- HL7 Terminology, [Encounter discharge disposition value set](https://terminology.hl7.org/4.0.0/ValueSet-encounter-discharge-disposition.html)
- [ADR-003: Outpatient Domain Spine](ADR-003-OUTPATIENT-DOMAIN-SPINE.md)
- [ADR-008: Human Outpatient Safety Disposition](ADR-008-HUMAN-OUTPATIENT-SAFETY-DISPOSITION.md)
