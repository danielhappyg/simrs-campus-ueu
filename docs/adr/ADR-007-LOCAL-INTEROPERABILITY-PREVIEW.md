# ADR-007: Deterministic Local Interoperability Preview

- **Status:** Accepted for autonomous reference implementation; stakeholder and external-profile validation pending
- **Date:** 2026-07-17
- **Final decision authority:** Daniel Happy Putra, project manager/PIC
- **Implementation authority:** delegated autonomous product and engineering work under DEC-008
- **Scope:** `INT-01`, `INT-02`, `OPD-002`, `OPD-003`, `OPD-004`, and `SAF-03`

## Context

The outpatient MVP already stores stable public identifiers, immutable clinical versions, typed observations, clinician-authored diagnoses, service requests/results, medication requests/reviews/dispenses, performed procedures, encounter closure, and human-reviewed coding. The acceptance contract still requires a deterministic local adapter that exposes how those sources would relate in a FHIR-aligned representation and identifies mapping gaps at their exact source fields.

The current SATUSEHAT outpatient playbook describes one outpatient service sequence as one `Encounter` and uses Patient, Condition, Observation, ServiceRequest, DiagnosticReport, Procedure, MedicationRequest, QuestionnaireResponse, MedicationDispense, and Composition across the outpatient flow. SATUSEHAT also requires national organization, patient, practitioner, and location identifiers for real submission. The campus simulation has intentionally synthetic local identities and no national IHS identity, credential, endpoint, sandbox transmission, or external validation result.

FHIR R4 distinguishes a `Composition` from a FHIR document: a document requires the Composition as the first entry of a `Bundle.type=document` and includes referenced resources. Creating that shape would imply document and attestation maturity the MVP does not possess. A `Bundle.type=collection` can instead hold deterministic resource-shaped mappings for local inspection without claiming a conformant document or transmission package.

## Decision

1. Add one read-only `Pratinjau Pemetaan Interoperabilitas` for a finalized synthetic outpatient encounter.
2. Access uses the existing `report.view` capability, exact-case/session-wide facilitator scope, active/completed simulation-session boundary, and finalized encounter gate.
3. The preview is generated on demand from current approved/finalized source records. It creates no database rows other than a minimized view-audit event and never mutates clinical sources.
4. The preview envelope contains:
   - a permanent simulation and non-transmission boundary;
   - a deterministic FHIR R4-aligned `Bundle` with `type=collection`;
   - a source index linking every mapped resource to its internal public source ID/version; and
   - explicit validation issues for unavailable national identifiers, unrun profile validation, and source concepts represented only as local text.
5. The bundle uses stable non-resolving `https://simrs-campus-ueu.example.invalid/fhir/{type}/{id}` full URLs. It contains no production or sandbox endpoint and performs no HTTP call.
6. Resource IDs are derived only from existing public ULIDs or a stable public-source-plus-code key. Request time, database numeric IDs, randomness, and environment hostnames do not affect the mapping.
7. The bundle timestamp and Composition date use the encounter finalization time so identical sources produce byte-stable JSON.
8. The Composition has `status=preliminary`, no attester, and no conformance profile. Its title and text state that it is a synthetic local mapping preview.
9. The initial resource set is:
   - `Composition`, `Patient`, `Organization`, and `Encounter`;
   - nursing vital `Observation` resources;
   - clinician-authored `Condition` resources with approved human ICD-10 coding when available;
   - `ServiceRequest`, result `Observation`, and `DiagnosticReport` resources;
   - current `MedicationRequest`, current `QuestionnaireResponse` review, and `MedicationDispense` resources;
   - performed `Procedure` resources with approved human ICD-9-CM coding when available.
10. Source text is placed in FHIR `CodeableConcept.text` when the MVP lacks an approved external terminology mapping. The adapter never invents SNOMED, LOINC, KFA, practitioner, organization, or location identifiers.
11. Numeric observations retain their original values and UCUM system/code where the source definition already supplies them. No unit conversion occurs.
12. All references resolve to a full URL contained in the local bundle. The Composition section entries refer only to included resources.
13. `interop.preview_viewed` audit metadata contains resource counts/types, validation issue count, and `ready_for_transmission=false`; it excludes the bundle, patient identity, clinical text, hashes, and source-index content.
14. The UI shows the mapping boundary, resource inventory, validation issues, source/version index, and formatted JSON. It provides no submit, send, retry, credential, endpoint, or “connected” action.
15. This increment does not satisfy SATUSEHAT conformance, sandbox validation, legal-document requirements, `OPS-02`, or institutional interoperability approval.

## Options considered

### A. Local collection preview — selected

| Dimension | Assessment |
| --- | --- |
| Clinical/legal overclaim | Low when boundaries remain permanent |
| Implementation complexity | Moderate |
| Source traceability | High |
| Future adapter reuse | High |
| External dependency | None |

**Benefits:** deterministic, inspectable, source-linked, testable offline, and incapable of accidental transmission.

**Costs:** it is not a profile validator and intentionally reports unresolved national-identifier/terminology gaps.

### B. FHIR document bundle

Rejected for the reference phase. A document bundle plus Composition can imply frozen attestation, custodian, and document semantics that are not institutionally approved or technically present.

### C. Direct SATUSEHAT sandbox adapter

Rejected for this increment. It would require credentials, national master-data identities, network operations, environment authorization, retry/idempotency design, disclosure rules, and external validation evidence.

### D. Manually copied mapping table only

Rejected because it would drift from the real source model and would not satisfy the executable deterministic-adapter requirement.

## Trust boundary

```text
Authenticated report.view user
        |
        v
Simulation session + exact case/facilitator + FINALIZED gate
        |
        v
Approved/current source projection
        |
        v
Deterministic local FHIR-aligned collection + source index + warnings
        |
        +--> no network client
        +--> no endpoint/credential
        +--> no persistence of mapped payload
        +--> minimized interop.preview_viewed audit event
```

## Consequences

- Stakeholders can inspect real mapping structure rather than a speculative spreadsheet.
- The adapter boundary becomes testable before any external integration is authorized.
- Missing national identifiers and incomplete terminology mappings remain visible instead of being replaced by fabricated values.
- A later conformant adapter may reuse source selection and stable identity rules, but must add approved profiles, master-data resolution, validator evidence, security, consent/disclosure policy, idempotent transmission, and external response provenance.

## Revisit triggers

Amend this ADR when Daniel separately authorizes a SATUSEHAT sandbox increment, institutional identifiers become available, approved terminology maps are supplied, a real FHIR validator is selected, a document/attestation policy is approved, or the mapping must leave the local simulation boundary.

## References

- Ministry of Health, [SATUSEHAT outpatient interoperability playbook](https://satusehat.kemkes.go.id/platform/docs/id/interoperability/rme-rawat-jalan/)
- Ministry of Health, [SATUSEHAT Composition resource](https://satusehat.kemkes.go.id/platform/docs/id/fhir/resources/composition/)
- Ministry of Health, [SATUSEHAT resource catalogue](https://satusehat.kemkes.go.id/platform/docs/id/fhir/resources/)
- HL7, [FHIR R4 Bundle](https://hl7.org/fhir/R4/bundle.html)
- HL7, [FHIR R4 Composition](https://hl7.org/fhir/R4/composition.html)
- [ADR-006: Finalized Simulation Reporting Projection](ADR-006-FINALIZED-SIMULATION-REPORTING.md)
