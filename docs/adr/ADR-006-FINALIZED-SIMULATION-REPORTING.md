# ADR-006: Finalized Simulation Reporting Projection

- **Status:** Implemented with automated and narrow-browser coverage; pending native print/PDF review and Checkpoint 2 scope decision
- **Date:** 2026-07-16
- **Final decision authority:** Daniel Happy Putra, project manager/PIC
- **Implementation authority:** delegated autonomous product and engineering work under DEC-008
- **Scope:** outpatient-summary and debrief-evidence print views for one finalized synthetic outpatient encounter

## Context

`VAL-U05` proposes an outpatient summary and a debrief/audit-friendly report as the first print/export candidates, while Daniel retains the final Checkpoint 2 decision on the minimum export set. The application already preserves approved, versioned nursing, medical, result, pharmacy, closure, RMIK, coding, and debrief sources. It needs a working reference that stakeholders can inspect without creating a divergent manually copied summary or overstating the legal/interoperability maturity of the MVP.

Permenkes 24/2022 requires electronic medical-record activity to preserve security, confidentiality, integrity, and availability and includes processing/reporting in the record lifecycle. The current SATUSEHAT outpatient playbook represents the outpatient medical summary with a FHIR `Composition` and organizes it into history, allergy, examination, results, diagnosis, procedures, medication, education, disposition, follow-up, and visit-course sections. SATUSEHAT `DocumentReference` also distinguishes document metadata such as identifiers, status, subject, author, authenticator, custodian, and security labels. These are source structures for the internal teaching projection; they do not make the generated HTML a conformant FHIR resource, a submitted national record, or a legally signed hospital document.

## Decision

1. The first working reference contains two on-demand, read-only HTML reports:
    - `OUTPATIENT_SUMMARY`; and
    - `DEBRIEF_EVIDENCE`.
2. Both are available only for an encounter in `FINALIZED` state within a `SIMULATION` session that is `ACTIVE` or `COMPLETED`.
3. Access requires the separate `report.view` capability and the same exact patient/encounter scope used by the debrief. A deliberately session-wide facilitator assignment may access reports for that facilitated session.
4. All pages and printed output carry the permanent `SIMULASI — DATA SINTETIS` watermark and a statement that the output is a learning preview, not a legal medical record, certified PDF, or SATUSEHAT submission.
5. The outpatient summary is projected from current approved sources only:
    - approved nursing and medical versions;
    - the latest current diagnostic result per service request;
    - the latest medication-request revision and its dispense outcome;
    - the approved closure and performed procedures; and
    - approved, human-reviewed diagnosis and procedure coding assignments.
6. The debrief report uses only the curated material-event projection defined by ADR-004, the append-only shared-note versions defined by ADR-005, scenario learning outcomes, and non-scoring rubric-reference metadata. It never prints raw audit reasons, request correlation IDs, IP hashes, user agents, or arbitrary audit metadata.
7. Rendering is audited as `report.rendered`. Audit metadata is limited to the report type, section count, and source-record counts. It excludes report content, patient identity, diagnosis text, note bodies, and terminology hashes.
8. Responses use private no-store caching and no-index headers. The application does not persist the rendered HTML as a new clinical document.
9. Browser print/save-as-PDF is a convenience preview only. The MVP does not yet create a server-side PDF, digital signature, authenticator/custodian record, immutable exported-document hash, retention event, disclosure log, or external transmission artifact.
10. The outpatient summary follows the official section structure where the MVP has an approved source. Unsupported sections are omitted or honestly shown as not documented; no clinical content is invented to fill a standard.
11. `Composition`, `DocumentReference`, and security-label mapping remain future adapter work. No FHIR conformance claim is attached to these views.
12. This implementation is a working reference under `VAL-U05`, not Daniel's final export-scope approval. Checkpoint 2 can retain, revise, or remove either output.

## Component and trust boundary

```text
Authenticated user + active assignment
        |
        v
report.view + exact case/session policy
        |
        v
FINALIZED encounter gate
        |
        +--> Outpatient projection
        |      approved/current clinical sources
        |      approved human coding decisions
        |
        +--> Debrief projection
               curated material timeline
               shared note version lineage
               non-scoring rubric references
        |
        v
Watermarked no-store HTML/print view
        |
        +--> minimized report.rendered audit event
```

## HTTP contract

| Endpoint                                                 | Success                                       | Denial/conflict                                             |
| -------------------------------------------------------- | --------------------------------------------- | ----------------------------------------------------------- |
| `GET /encounters/{encounter}/reports/outpatient-summary` | Watermarked source-derived outpatient summary | `403` missing capability/wrong context; `409` not finalized |
| `GET /encounters/{encounter}/reports/debrief-evidence`   | Watermarked curated debrief evidence          | `403` missing capability/wrong context; `409` not finalized |

Both return `Cache-Control: private, no-store, max-age=0` semantics and `X-Robots-Tag: noindex, nofollow`.

## Verification contract

- finalized-state allow and pre-finalization conflict;
- capability, wrong-context, and direct-route denial;
- active- and completed-session reads;
- source-derived patient, history, examination, diagnosis, result, procedure, medication, plan, closure, and approved coding content;
- human-review and simulation/legal-boundary language;
- shared-note latest and prior versions plus non-scoring rubric status;
- absence of raw audit internals from output;
- minimized `report.rendered` audit metadata with no body fragments;
- private/no-store/no-index response headers;
- one `main`, one visible `h1`, no duplicate IDs, no page overflow, accessible 44-pixel screen actions, print CSS, and mobile/desktop browser review; and
- full backend, static-analysis, formatting, TypeScript, frontend, and production-build gates.

### Browser evidence (2026-07-16)

An isolated SQLite fixture at `http://127.0.0.1:8027` imported the exact supplied ICD-10 and ICD-9-CM workbooks, completed the synthetic reference encounter to `FINALIZED`, and added one shared facilitator note. Both reports were then rendered in the supported in-app browser at 1280×720 and 390×844.

- Each view retained exactly one `main` and one `h1`, no duplicate IDs, no page-level horizontal overflow, permanent simulation/non-legal language, and no captured console warning or error.
- Both screen actions measured 44 CSS pixels high at desktop and mobile sizes.
- The outpatient report's four 640-pixel tables remained inside `overflow-x: auto` containers at the 390-pixel viewport; the page itself remained 390 pixels wide.
- The outpatient projection visibly included the approved ICD-10 `R42` and ICD-9-CM `38.99` decisions. The debrief projection visibly included all 35 curated events, the shared note/version, and the non-scoring rubric boundary.
- Six successful report renders produced only the two expected minimized audit metadata shapes: report type, section count, and source counts.
- The print action invoked `window.print()`. The loaded stylesheet exposed A4 portrait page settings, hid `.screen-tools`, retained the watermark, removed screen framing, and applied break-avoidance rules. The browser does not expose its native print preview or print-media emulation to automation, so PDF pagination and native preview appearance remain a separate manual check and are not claimed here.

## Trade-offs

### Benefits

- Stakeholders receive a concrete document-shaped reference early enough to critique.
- The summary cannot drift from its approved source records because it is generated on demand.
- A separate capability and finalized-state gate make report access explicit and testable.
- The view is compatible with the current Laravel/Hostinger deployment model and requires no PDF dependency.

### Costs and limitations

- Browser-generated PDFs are not deterministic or server-attested artifacts.
- A report opened at two times can carry a different generation timestamp even though its finalized source content is the same.
- The debrief timeline remains capped at 300 material events and reports truncation honestly.
- No disclosure workflow, recipient/purpose capture, retention policy, bulk export, accessibility-tagged PDF, signature, or national-system payload exists.

## Revisit triggers

Replace or amend this ADR when:

- Daniel completes the `VAL-U05` Checkpoint 2 export decision;
- an institutional policy requires signed/immutable PDF documents or disclosure tracking;
- real-patient use, external recipients, bulk exports, or retention rules enter scope;
- SATUSEHAT sandbox submission is implemented;
- authenticators, custodians, security labels, or FHIR document bundles are required; or
- a stakeholder-approved report layout or additional document type replaces this working reference.

## References

- Ministry of Health, [Permenkes 24/2022 on Medical Records](https://jdih.kemkes.go.id/documents/peraturan-menteri-kesehatan-nomor-24-tahun-2022) and [official PDF](https://jdih.kemkes.go.id/common/dokumen/2022permenkes024.pdf)
- Ministry of Health, [SATUSEHAT outpatient interoperability playbook](https://satusehat.kemkes.go.id/platform/docs/id/interoperability/rme-rawat-jalan/)
- Ministry of Health, [SATUSEHAT DocumentReference resource](https://satusehat.kemkes.go.id/platform/docs/id/fhir/resources/document-reference/)
- [ADR-004: Finalized Encounter Debrief Projection](ADR-004-FINALIZED-DEBRIEF-PROJECTION.md)
- [ADR-005: Shared Debrief Notes and Non-Scoring Rubric References](ADR-005-DEBRIEF-NOTES-AND-RUBRIC-REFERENCES.md)
