# Assessment and Procurement Baseline

Date: 2026-08-21

This SIMRS is currently used as a campus teaching and management environment, not as an operating hospital system. The standards below are therefore applied in two ways:

1. as immediate requirements for protecting any personal, credential, log, or realistic clinical data already present; and
2. as purchase-readiness requirements if the product may later be used for real health-service operations or integration demonstrations.

## Current authoritative references

- [Permenkes No. 24 of 2022 on Medical Records](https://jdih.kemkes.go.id/documents/peraturan-menteri-kesehatan-nomor-24-tahun-2022) remains in force and requires electronic medical records to follow security and confidentiality principles.
- [Indonesia Law No. 27 of 2022 on Personal Data Protection](https://peraturan.bpk.go.id/Details/229798/) classifies health information as specific personal data and establishes controller/processor duties. A teaching deployment is not exempt merely because it is not a hospital if it processes identifiable personal data.
- [SATUSEHAT interoperability guidance](https://satusehat.kemkes.go.id/platform/docs/id/interoperability/) defines service-module and use-case flows, including outpatient, emergency, inpatient, pharmacy, claims, and thematic registries.
- [SATUSEHAT API environment guidance](https://satusehat.kemkes.go.id/platform/docs/id/api-catalogue/integrations/) distinguishes sandbox and production FHIR R4 endpoints and requires bearer-token authentication.
- [SATUSEHAT validation guidance](https://satusehat.kemkes.go.id/platform/docs/id/api-catalogue/validasi/) requires validation of FHIR structures and terminologies including ICD-10, ICD-9-CM, LOINC, SNOMED CT, and relevant code systems.
- [OWASP ASVS 5.0.0](https://owasp.org/www-project-application-security-verification-standard/) is an appropriate procurement and verification baseline for the web application, covering authentication, session management, authorization, secure communication, configuration, data protection, and security logging.
- [ISO/IEC 25010:2023](https://www.iso.org/standard/78176.html) provides the product-quality model for specifying and evaluating software quality and acceptance criteria.
- [ISO 27799:2025](https://www.iso.org/standard/84647.html) provides health-information security controls based on ISO/IEC 27002:2022 and explicitly includes electronic health-record systems and custodians of personal health information.

## Evidence scale

| Label | Meaning |
|---|---|
| Observed | Directly seen in the authorized live interface or response. |
| Owner-confirmed | Confirmed by UEU, such as the intentional lecturer–administrator role and campus-only use. |
| Manual-documented | Described by the vendor's 2018 manual, which may be stale. |
| Vendor-stated | Claimed by vendor material but not demonstrated in this deployment. |
| Inferred | Plausible model derived from routes, fields, dependencies, and common SIMRS workflow; requires confirmation. |
| Unknown | Cannot be established from an administrative interface and requires vendor/system evidence. |

## Acceptance domains

The purchase assessment must cover the complete system across:

1. functional suitability and complete workflow execution;
2. data model, master-data governance, traceability, and portability;
3. interoperability and reconciliation;
4. role/action authorization, teaching isolation, and audit trails;
5. privacy, security, secret management, and incident handling;
6. reliability, performance, capacity, backup, restore, and disaster recovery;
7. maintainability, supported versions, dependency lifecycle, and release management;
8. usability, accessibility, training, and documentation;
9. vendor ownership, SLA, source-code/data escrow, exit plan, and total cost;
10. controlled acceptance testing with synthetic data and measurable pass/fail criteria.

## Important limitation

An administrative browser review can establish scope, visible design, configuration surfaces, and selected behavior. It cannot prove database integrity, server-side authorization, encryption at rest, code security, backup recoverability, performance under load, integration correctness, or disaster recovery. Those require architecture documents, source/configuration evidence, redacted logs, controlled test cases, and vendor demonstrations.
