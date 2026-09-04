# Outpatient admission and SATUSEHAT administrative gender

Implementation scope approved by Daniel on 5 September 2026. This note describes the local changes and the checks needed when reviewing the preview.

## Patient metadata

`patients.sex` uses the SATUSEHAT/FHIR AdministrativeGender codes, with Indonesian display labels:

| Code | Display |
| --- | --- |
| `male` | Laki-laki |
| `female` | Perempuan |
| `other` | Lainnya |
| `unknown` | Tidak diketahui |

The code system is `http://hl7.org/fhir/administrative-gender`. The selected value is administrative gender; this implementation does not create a separate clinical-sex field or a live SATUSEHAT connection.

Existing `LAKI_LAKI`, `PEREMPUAN`, and `TIDAK_DIKETAHUI` values are translated to `male`, `female`, and `unknown` respectively. Patient identifiers and other patient data are preserved. Unrecognised values must be investigated before migration; they must not be silently reclassified.

Official references: [SATUSEHAT Patient](https://satusehat.kemkes.go.id/platform/docs/id/fhir/resources/patient/), [SATUSEHAT neonatal mapping](https://satusehat.kemkes.go.id/platform/docs/id/interoperability/neonatus/), and [HL7 AdministrativeGender](https://hl7.org/fhir/valueset-administrative-gender.html).

## Admission workflow

1. The physician finalizes the outpatient medical assessment, then signs the disposition: `KONTROL_ULANG`, `SEMBUH`, or `RAWAT_INAP`.
2. `RAWAT_INAP` records the admission reason and receiving-unit handoff note, bound to the final medical-document version. The source encounter remains `IN_EXAMINATION`; its disposition displays that admission is pending.
3. The request appears in the inpatient registrar worklist. Signing alone does not create an inpatient encounter or reserve a bed.
4. The registrar selects an available managed bed and completes admission. Patient and bed locking, source-version checks, and an idempotency receipt protect the operation.
5. Completion creates a linked `DARI_RJ` inpatient encounter and placement event. The source outpatient encounter moves to `READY_FOR_RM`. The inpatient admission time records completion of the admission, while the original visit retains its own timestamp.
6. Non-admission dispositions move the outpatient encounter to `READY_FOR_RM`. Signed disposition evidence forms part of RMIK completeness review.

Before handoff, the signing physician can issue a new disposition version with a correction reason and expected-version check. Changing a pending `RAWAT_INAP` decision to `KONTROL_ULANG` or `SEMBUH` withdraws that pending request. Previous signed versions remain intact. Completed handoffs cannot be undone through the ordinary correction or encounter-cancellation controls.

Direct inpatient registration remains available for a planned order or external referral, with an admission-authority reference. A free-standing direct submission cannot claim `DARI_RJ` or `DARI_IGD`; those sources use their linked handoff worklists.

## Preview acceptance walkthrough

Use synthetic patients and separate physician/registrar roles.

- Register patients using each of the four gender values and confirm Indonesian labels in registration, examination, and printing.
- Finalize an outpatient medical assessment and confirm the encounter still needs a disposition.
- Sign `RAWAT_INAP`; confirm one pending request appears and no bed is occupied yet.
- Complete the request as registrar; confirm one linked inpatient encounter, the selected bed, the preserved source visit, and the actual inpatient admission time.
- Retry the same operation; confirm it returns the original result without another admission.
- Before admission, correct a pending request and confirm its earlier signed version remains intact. Change one request to `KONTROL_ULANG` and confirm it disappears from the admission queue. Try a stale correction and confirm it is rejected.
- Try an occupied bed or a patient already admitted; confirm a clear rejection without a partial admission.
- Confirm a registrar cannot sign a clinical disposition and a physician cannot perform the registrar handoff.
- Complete separate `KONTROL_ULANG` and `SEMBUH` journeys and verify RMIK review.
- Try direct admission without an order/referral reference and with a forged `DARI_RJ` source; both must be rejected.

## Release preparation

These changes require database migrations and updated frontend assets. Apply only the migrations belonging to this change when preparing a hosted preview; the existing deferred warehouse migrations remain outside this scope. The repository's Vercel setup serves committed `public/build` assets, so a later hosted release must include a fresh asset build.

Migration order for this change:

1. `2026_09_05_000100_create_outpatient_disposition_handoff_tables.php`
2. `2026_09_05_000100_migrate_patient_sex_to_satusehat_administrative_gender.php`
3. `2026_09_05_000200_guard_outpatient_admission_evidence.php`

Inspect the target schema and existing gender values before migration. The gender migration deliberately refuses unsupported values or incompatible schema constraints; it does not silently rewrite them. Signed evidence is append-only and rollback must not discard it. Existing active encounters with finalized medical documentation still need a physician disposition; no retrospective physician attestation is generated automatically. Closed encounters are preserved.

## Local verification and status

- Related outpatient, inpatient, emergency, and gender regression run: 130 passed, one skipped, 1,654 assertions.
- Expanded disposition/admission tests: seven passed, including unauthorized actions, stale versions, withdrawn requests, occupied beds, retries, and rollback on mandatory audit-write failure.
- Isolated PostgreSQL 17 verification in the private `laravel` schema: 14 passed, 97 assertions, covering gender registration/migration, disposition/handoff, immutable evidence, and synthetic reset. The disposable database was cleaned up; no hosted database was used.
- Additional direct-admission caller regression tests: 49 passed; the local portability rehearsal script passed its syntax check.
- Clinical-entry locking, cancellation, synthetic-boundary, and audit-architecture regression checks: 28 passed.
- Full frontend unit suite: 204 tests passed. Lint, TypeScript checks, and PHPStan passed.
- Production frontend build passed using an isolated temporary output directory. Tracked `public/build` assets were not replaced.

The code-review pass prompted additional audit-failure rollback checks, immutable-evidence guards, and stale/cancellation protections. Automated verification is not a substitute for the synthetic-patient preview walkthrough above.

These changes are local only: no commit, deployment, or hosted migration was performed for this implementation. A hosted preview still requires the scoped migrations and a fresh committed asset build. Production deployment is a separate action. Physician signing here means authenticated, versioned application attestation, not certified electronic-signature integration. Live SATUSEHAT submission and post-admission reversal/compensation workflows are outside this change.
