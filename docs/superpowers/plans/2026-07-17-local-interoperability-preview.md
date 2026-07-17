# Local Outpatient Interoperability Preview Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a deterministic, source-linked, local FHIR R4-aligned outpatient mapping preview that is permanently synthetic, never transmitted, and explicit about every conformance gap.

**Architecture:** Add a pure read-model adapter under a new Interoperability module, expose it behind the existing finalized-report authorization boundary, and render a UEU Inertia inspection page. The adapter emits a local `Bundle.type=collection`, a separate source index, and explicit validation issues; it has no network client, endpoint configuration, or payload persistence.

**Tech Stack:** Laravel 13, PHP 8.3+, Eloquent, Inertia, React 19, TypeScript, Tailwind CSS, PHPUnit, Vitest, Testing Library, axe-core.

## Global Constraints

- Synthetic simulation data only; no real patient input, national identifiers, credentials, or production/sandbox endpoint.
- Do not merge or deploy. Push only to `agent/outpatient-domain-spine` and update draft PR #10.
- Preserve untracked `deliverables/` and the ignored release-control test artifact.
- Use failing tests before every production behavior change.
- Always return `readyForTransmission=false`, `transportState=NOT_SENT`, and `externalEndpoint=null`.
- Use `Bundle.type=collection`; do not claim a FHIR document, profile conformance, legal record, attestation, or SATUSEHAT submission.
- Do not add an HTTP client, queue, credential, endpoint setting, submit/send/connect action, or persisted mapped payload.
- Do not invent IHS, organization, practitioner, location, KFA, SNOMED, or LOINC identifiers.
- Do not expose internal numeric IDs, raw audit metadata, content hashes, credentials, or non-synthetic data.

---

### Task 1: Deterministic local mapping core

**Files:**
- Create: `tests/Feature/OutpatientInteroperabilityPreviewTest.php`
- Create: `app/Modules/Interoperability/Services/OutpatientFhirPreview.php`
- Create: `app/Modules/Interoperability/Support/FhirPreviewValidator.php`

**Interfaces:**
- Consumes: finalized `Encounter`, current approved/finalized clinical sources, and existing public ULIDs/versions.
- Produces: `OutpatientFhirPreview::build(Encounter): array` with `boundary`, `summary`, `bundle`, `sourceIndex`, and `validation`.

- [ ] **Step 1: Write failing deterministic-identity and boundary tests**

Seed and complete the reference journey, call the new service twice, and assert equality plus:

```php
$this->assertSame('collection', data_get($preview, 'bundle.type'));
$this->assertSame('4.0.1', data_get($preview, 'boundary.fhirVersion'));
$this->assertSame('NOT_SENT', data_get($preview, 'boundary.transportState'));
$this->assertFalse(data_get($preview, 'boundary.readyForTransmission'));
$this->assertNull(data_get($preview, 'boundary.externalEndpoint'));
```

Assert unique stable full URLs, Composition first, no `meta.profile`, no `request`, no production/SATUSEHAT endpoint, and no database numeric ID fields.

- [ ] **Step 2: Run the focused test and verify RED**

Run:

```bash
php artisan test --compact tests/Feature/OutpatientInteroperabilityPreviewTest.php
```

Expected: failure because `OutpatientFhirPreview` does not exist.

- [ ] **Step 3: Implement the minimal envelope, stable identity helper, and structural validator**

Create the service with constant non-resolving base URI, persisted finalization timestamp, stable resource IDs, explicit ordering, and permanent boundary. Create a validator that checks unique full URLs, FHIR ID shape, contained reference resolution, Composition-first ordering, collection type, and non-transmission flags.

- [ ] **Step 4: Run the focused test and verify GREEN**

Run the command from Step 2. Expected: deterministic core tests pass.

- [ ] **Step 5: Commit**

```bash
git add app/Modules/Interoperability tests/Feature/OutpatientInteroperabilityPreviewTest.php
git commit -m "feat: add deterministic interoperability preview core"
```

### Task 2: Source-complete outpatient resource mapping

**Files:**
- Modify: `tests/Feature/OutpatientInteroperabilityPreviewTest.php`
- Modify: `app/Modules/Interoperability/Services/OutpatientFhirPreview.php`

**Interfaces:**
- Consumes: approved nursing/medical versions, current result and medication revision, current pharmacy review/dispense, approved closure/procedures, and approved human coding decisions.
- Produces: Patient, Organization, Encounter, Observation, Condition, ServiceRequest, DiagnosticReport, MedicationRequest, QuestionnaireResponse, MedicationDispense, Procedure, and Composition resources plus exact source-index records.

- [ ] **Step 1: Add failing resource/source coverage tests**

Assert exact resource-type counts for the completed reference fixture, all clinical references resolve, each resource has a matching source-index row, nursing vitals retain numeric value and UCUM unit, current result and current medication revision are selected, human ICD-10/ICD-9-CM code/version data remains linked to its source, and no source-text-only concept receives an invented external code.

- [ ] **Step 2: Run the focused test and verify RED**

Run the command from Task 1. Expected: resource coverage and source-index assertions fail against the minimal envelope.

- [ ] **Step 3: Implement source loaders and resource mappers**

Add focused private mapper methods per resource type. Use `CodeableConcept.text` for unapproved mappings, `http://unitsofmeasure.org` only for configured UCUM sources, `http://hl7.org/fhir/sid/icd-10` and `http://hl7.org/fhir/sid/icd-9-cm` only for approved human coding decisions, and UTC timestamps derived from persisted sources.

- [ ] **Step 4: Add failing validation-issue tests**

Assert warnings for missing national identifiers, profile validation not run, and text-only terminology mappings. Each issue must have a safe `sourcePath` and optional public source ID, while `readyForTransmission` remains false even when structural validation passes.

- [ ] **Step 5: Implement validation issue generation and verify GREEN**

Generate deterministic, source-specific warnings without clinical payloads. Run the focused test and expect all mapping/validation tests to pass.

- [ ] **Step 6: Commit**

```bash
git add app/Modules/Interoperability/Services/OutpatientFhirPreview.php tests/Feature/OutpatientInteroperabilityPreviewTest.php
git commit -m "feat: map finalized outpatient sources locally"
```

### Task 3: Authorized preview route and minimized audit

**Files:**
- Create: `app/Http/Controllers/Interoperability/OutpatientInteroperabilityPreviewController.php`
- Modify: `routes/web.php`
- Modify: `tests/Feature/OutpatientInteroperabilityPreviewTest.php`

**Interfaces:**
- Consumes: `AssignmentContextResolver::forReport()`, `OutpatientFhirPreview::build()`, and `AuditRecorder`.
- Produces: route `encounters.interoperability-preview.show`, Inertia component `encounter/interoperability-preview`, privacy headers, and `interop.preview_viewed`.

- [ ] **Step 1: Write failing HTTP/auth/audit tests**

Cover finalized exact-case and session-wide facilitator success, completed-session read, non-finalized `409`, missing capability/wrong case/wrong session/admin `403`, private/no-store/no-index headers, and exact audit metadata keys:

```php
[
    'resource_count',
    'resource_type_counts',
    'validation_issue_count',
    'ready_for_transmission',
]
```

Assert the serialized audit metadata contains no patient/clinical/source/bundle/hash/endpoint fragment.

- [ ] **Step 2: Run the focused test and verify RED**

Run the focused test. Expected: route-not-defined failures.

- [ ] **Step 3: Implement the controller and route**

Authorize before projection, enforce `FINALIZED`, record minimized audit metadata, render the Inertia page, and apply private/no-store/no-index headers.

- [ ] **Step 4: Run the focused test and verify GREEN**

Run the focused test. Expected: route, authorization, privacy, and audit assertions pass.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Interoperability/OutpatientInteroperabilityPreviewController.php routes/web.php tests/Feature/OutpatientInteroperabilityPreviewTest.php
git commit -m "feat: expose authorized interoperability preview"
```

### Task 4: UEU inspection page and navigation

**Files:**
- Create: `resources/js/pages/encounter/interoperability-preview.tsx`
- Create: `resources/js/test/interoperability-preview.test.tsx`
- Modify: `app/Http/Controllers/Encounter/EncounterOverviewController.php`
- Modify: `app/Http/Controllers/Encounter/EncounterDebriefController.php`
- Modify: `resources/js/pages/encounter/show.tsx`
- Modify: `resources/js/pages/encounter/debrief.tsx`
- Modify: `resources/js/pages/encounter/timeline.tsx`

**Interfaces:**
- Consumes: controller preview payload and server-supplied navigation URLs.
- Produces: accessible resource inventory, validation issues, source/version index, formatted JSON, and conditional navigation.

- [ ] **Step 1: Write failing React and navigation tests**

Assert permanent simulation/`BELUM DIKIRIM`/non-conformance language, resource counts, validation issue paths, source versions, formatted `Bundle` JSON, absence of send/connect/retry actions, conditional navigation, semantic landmarks, and zero serious/critical axe violations.

- [ ] **Step 2: Run focused frontend tests and verify RED**

Run:

```bash
npm run test:unit -- resources/js/test/interoperability-preview.test.tsx resources/js/test/encounter-timeline.test.tsx resources/js/test/encounter-debrief.test.tsx
```

Expected: missing page/navigation failures.

- [ ] **Step 3: Implement the page and conditional links**

Use the authenticated layout, patient context banner, UEU tokens, semantic lists/tables, an `overflow-x-auto` JSON region, explicit `NOT_SENT` status, and server-provided URLs. Do not add mutation or transmission controls.

- [ ] **Step 4: Generate routes and verify GREEN**

Run Wayfinder, focused frontend tests, TypeScript, and the focused backend test. All must pass.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Encounter resources/js/pages/encounter resources/js/test/interoperability-preview.test.tsx resources/js/routes resources/js/actions
git commit -m "feat: add interoperability inspection workspace"
```

### Task 5: Traceability, browser evidence, and draft-PR gates

**Files:**
- Modify: `README.md`
- Modify: `docs/product/OUTPATIENT_TRACEABILITY_MATRIX.md`
- Modify: `docs/operations/OUTPATIENT_DOMAIN_SPINE_VALIDATION.md`
- Modify: `docs/operations/OUTPATIENT_CHECKPOINT_2_UAT_GUIDE.md`
- Modify: `docs/adr/ADR-006-FINALIZED-SIMULATION-REPORTING.md`

**Interfaces:**
- Consumes: implementation plus local/browser/CI evidence.
- Produces: explicit INT-01 progress without conformance, stakeholder, merge, or deployment overclaim.

- [ ] **Step 1: Update traceability and superseded future-work wording**

Record the local preview boundary, mapped resources, deterministic/source-index tests, privacy/audit behavior, and remaining external validation/master-data/transmission gaps. Amend ADR-006 only to point its former future Composition-mapping statement to ADR-007.

- [ ] **Step 2: Browser-validate a fresh finalized synthetic fixture**

Import the exact supplied ICD workbooks, complete the reference journey, sign in with an authorized synthetic account, and inspect the preview at desktop and 390×844. Verify resource inventory, warnings, source versions, contained JSON, one main/h1, no duplicate IDs, no page overflow, no enabled main control below 44 pixels, no transmission action, and a clean fresh warning/error log.

- [ ] **Step 3: Run full local verification**

Run the complete PHP and React suites, Pint, PHPStan, ESLint, Prettier, TypeScript, Wayfinder, production build, migration/seed, Composer/npm audits, Markdown/link validation, credential guard, and `git diff --check`. Preserve the ignored release-control artifact by moving it reversibly only during recursive lint/link checks.

- [ ] **Step 4: Commit documentation and push**

Commit only intended files, keep `deliverables/` untracked, push `agent/outpatient-domain-spine`, and update draft PR #10 with explicit no-merge/no-deploy language.

- [ ] **Step 5: Monitor final remote checks**

Wait for documentation, application/security, MySQL 8.4, and non-deploying release-candidate jobs. Record exact run/test/artifact evidence only after success; if a docs-only evidence commit is pushed, wait for that new head too.
