# Local Outpatient Interoperability Preview Design

- **Date:** 17 July 2026
- **Status:** Approved autonomous reference-build increment
- **Scope:** `INT-01`, `INT-02`, `OPD-002`, `OPD-003`, `OPD-004`, and `SAF-03`
- **Environment:** synthetic campus simulation only

## 1. Problem

The outpatient reference journey now has coherent source records and source-derived reports, but `INT-01` remains unimplemented. Reviewers cannot inspect a concrete Patient/Encounter/clinical-resource mapping, verify stable cross-resource references, or see which source fields are not yet eligible for SATUSEHAT mapping.

A direct submission adapter would be unsafe and premature. The application has no national patient, practitioner, organization, or location identities; no SATUSEHAT credential or approved endpoint; no external-profile validator; and no institutional transmission policy. The next useful step is an executable local mapping preview that is structurally honest about every missing prerequisite.

## 2. Official-source response

The current SATUSEHAT outpatient playbook treats a single outpatient sequence as one Encounter and identifies Patient, Condition, Observation, ServiceRequest, DiagnosticReport, Procedure, MedicationRequest, QuestionnaireResponse, MedicationDispense, and Composition across the flow. Its resource guidance binds clinical resources to Patient and Encounter and expects UTC-aware dates plus national identifiers for real exchange.

FHIR R4 requires Composition to be the first entry only when a Bundle is a document. Because this MVP cannot claim attested-document status, the preview uses `Bundle.type=collection`, keeps Composition preliminary, attaches no profile assertion, and presents every mapping as local simulation evidence only.

## 3. Product contract

The preview route is `GET /encounters/{encounter}/interoperability-preview` and renders `encounter/interoperability-preview`.

Success requires:

- authenticated, active, verified account;
- simulation middleware;
- active or completed simulation session;
- `report.view` and exact case scope, or a deliberate session-wide facilitator assignment;
- encounter status `FINALIZED`.

Wrong capability/context returns `403`; a non-finalized encounter returns `409`. Successful responses use private/no-store/no-index headers.

## 4. Projection contract

```text
boundary:
  classification, environment, mode, transportState
  fhirVersion, satusehatProfileStatus, readyForTransmission
  legalStatus, externalEndpoint
summary:
  totalResources, resourceTypeCounts, validationIssueCount
bundle:
  resourceType=Bundle, type=collection, identifier, timestamp, entry[]
sourceIndex[]:
  resourceType, resourceId, fullUrl
  sourceType, sourcePublicId, sourceVersion
validation:
  status=PREVIEW_ONLY, readyForTransmission=false, issues[]
urls:
  encounter, debrief, outpatientReport, workQueue
```

Each validation issue contains `code`, `severity`, `message`, `sourcePath`, and optional `sourcePublicId`. Messages never contain credentials, endpoints, raw clinical payloads, or internal numeric IDs.

## 5. Resource rules

| Source | Preview resource | Stable source/version rule |
| --- | --- | --- |
| Synthetic patient | Patient | patient public ULID; synthetic MRN identifier only |
| Campus simulation | Organization | fixed local simulation organization ID |
| Finalized encounter | Encounter | encounter public ULID and finalization period |
| Approved nursing vital | Observation | nursing version ULID plus configured observation code |
| Current diagnosis | Condition | condition public ULID; human ICD-10 decision when approved |
| Service request | ServiceRequest | request public ULID and medical source version |
| Current result | Observation + DiagnosticReport | result public ULID/version and basedOn request |
| Current medication order | MedicationRequest | request public ULID/revision and medical source version |
| Current pharmacy review | QuestionnaireResponse | review public ULID/version and medication request |
| Actual dispense | MedicationDispense | dispense public ULID and medication request |
| Performed procedure | Procedure | procedure public ULID and closure version; human ICD-9-CM when approved |
| Final summary organization | Composition | encounter public ULID; sections reference included resources |

Every full URL uses the non-resolving `example.invalid` origin. Composition is the first entry for readability, not because the collection is a document.

## 6. Determinism and validation

- The same finalized database state produces the same canonical bundle JSON on repeated calls.
- Bundle and resource order is explicit: Composition, Patient, Organization, Encounter, then clinical resources by source sequence/public ID.
- All timestamps are normalized to UTC ISO 8601 and originate from persisted source/finalization times.
- All references must match an included `entry.fullUrl`; unresolved references are projection errors.
- All resource IDs must match the FHIR R4 `id` character/length boundary.
- All entries have unique full URLs.
- No `meta.profile`, national IHS identifier, practitioner reference, KFA/SNOMED/LOINC code, or external endpoint is invented.
- The preview always reports `readyForTransmission=false` and `transportState=NOT_SENT`.
- Source-text-only concepts produce a validation warning tied to their public source.

## 7. UI

The UEU page contains:

1. permanent `SIMULASI — DATA SINTETIS` and `BELUM DIKIRIM` boundary;
2. patient/encounter context banner;
3. explicit local-preview/non-conformance explanation;
4. resource and validation summary cards;
5. resource-type inventory;
6. validation issue list with source paths;
7. source/version mapping table;
8. formatted JSON inside a horizontally contained `<pre>` region;
9. navigation back to encounter, debrief, outpatient report, and work queue.

The page has one `main`, one visible `h1`, accessible issue/inventory semantics, 44-pixel coarse-pointer controls, and no page-level overflow at 390 CSS pixels. It has no send/connect/retry/upload/download action.

## 8. Audit and minimization

Successful view records `interop.preview_viewed` with only:

```text
resource_count
resource_type_counts
validation_issue_count
ready_for_transmission=false
```

The event excludes patient identity, clinical text, bundle JSON, source index, public source IDs, content hashes, correlation metadata, credentials, and endpoints.

## 9. Verification

Backend tests prove authorization/finalization/privacy headers, deterministic repeated projection, stable identifiers/references, required resource coverage, exact source/version indexing, unit preservation, human coding provenance, current-result/current-medication selection, non-transmission boundaries, validation issues, and minimized audit metadata.

Frontend tests prove permanent boundary language, counts/inventory/issues/source index/JSON presentation, no send/connect action, mobile containment classes, and no serious/critical axe violations.

Browser validation uses a fresh finalized synthetic fixture built with the supplied checksummed ICD-10 and ICD-9-CM workbooks. It verifies the rendered resource inventory, validation warnings, one main/h1, no duplicate IDs, 390×844 containment, control sizes, absence of transmission actions, and clean fresh warning/error logs.

## 10. Non-goals

- FHIR profile conformance or validator certification;
- a `Bundle.type=document` or legal/attested Composition;
- SATUSEHAT sandbox/production authentication or transmission;
- IHS, organization, practitioner, location, KFA, SNOMED, or LOINC master-data lookup;
- retry queues, idempotency keys, consent/disclosure, response reconciliation, or external audit provenance;
- XML, bulk export, signed PDF, or persisted mapped payload;
- stakeholder acceptance, merge, deployment, or OPS-02.
