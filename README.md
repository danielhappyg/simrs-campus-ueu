# SIMRS Campus Universitas Esa Unggul

Teaching-first hospital information system for integrated health-sciences education. The reference MVP models a coherent outpatient journey for Medicine, Nursing, Medical Records and Health Information (RMIK), and Pharmacy before later expansion to other study programs.

This is a **simulation environment only**. It accepts synthetic patient data and is not authorized for real clinical care.

## Current status

The research, product contract, UEU Clinical design package, and secure application foundation are implemented. The current application includes:

- Laravel 13, React 19, TypeScript, Inertia 3, Vite, and Fortify;
- administrator-provisioned accounts with inactive-account rejection, password throttling, email verification, passkeys, and two-factor support;
- fail-closed `SIMULATION` and synthetic-only middleware;
- versioned scenarios, simulation sessions, contextual assignments, capabilities, and work tasks;
- a user-scoped work queue with the UEU encounter-orbit design and fail-closed exact-session selection when one demo identity has several active disposable sessions;
- public ULID identifiers and an application-level append-only audit trail;
- deterministic opt-in demo fixtures using reserved `example.invalid` accounts;
- a fail-closed, transaction-bound command that deep-clones only a pristine synthetic reference graph into a separately attributable session, remaps every case/assignment/task/supervision reference, and supports an explicitly overdue appointment for an isolated no-show rehearsal without resetting retained data;
- one source-linked outpatient workflow covering registration/check-in, bounded registrar cancellation and overdue no-show without history deletion, nursing intake, supervised medical assessment, orders/results, pharmacy review/dispense, supervised closure, reproducible RMIK completeness review, and attributed correction;
- an append-only human safety-disposition workflow that keeps an escalated encounter and medical task paused until the linked nursing supervisor or session facilitator explicitly resumes the synthetic routine flow or records a simulated transfer, with no default or clinical recommendation;
- a distinct append-only `Pulang atas permintaan sendiri` branch after clinical service begins, recorded only by the exact medical supervisor or session facilitator with explicit confirmation, exact source hashes, preserved prior work, no clinical verdict, and no automatic closure, coding, finalization, or transmission;
- a reusable unsaved-change guard on the versioned nursing, medical, and closure authoring forms, with a visible local-change state, the same explicit stay/save-draft-then-leave/discard choices for Inertia links and in-session browser Back/Forward, and a bounded stale-session recovery path that keeps the clinical delta in the original tab while reauthentication opens separately;
- checksummed immutable ICD-10/ICD-9-CM release import and search, plus explainable ICD-10 diagnosis and ICD-9-CM performed-procedure candidates that always require separate coder and linked-supervisor actions;
- a versioned synthetic coding retrieval evaluator with separate diagnosis/procedure top-1/top-5 reporting, negative controls, ambiguity controls, and unapproved Indonesian/stress proposals kept outside reference metrics;
- coder-requested diagnosis and performed-procedure correction loops through the exact responsible clinical author, linked medical supervisor, successor closure, and replacement RMIK review, with stale assignments marked `REVIEW_REQUIRED`; and
- guarded simulation commands that can either complete a fresh reference fixture or stop at an active diagnosis/procedure correction through the same domain services for demonstration and staged validation; and
- a read-only, fail-closed Hostinger staging preflight that separates automated runtime checks from sanitized manual account evidence; and
- a manifest-bound, runtime-only release-candidate build that produces a short-lived CI artifact without enabling deployment; and
- a distinct, read-only longitudinal outpatient record that exposes a curated, source/version/actor/time-attributed event projection during active or completed simulation sessions, while keeping finalized debrief evidence on its own route; and
- a deterministic, read-only FHIR R4-aligned local interoperability preview for finalized synthetic encounters, with stable source provenance, human-approved ICD-10/ICD-9-CM coding, explicit mapping gaps, and no endpoint or transmission capability; and
- automated PHP, JavaScript, static-analysis, formatting, build, and database-migration checks.

The reference workflow is a concrete development model, not a faculty-pilot or clinical-use release. Local MySQL migration/rollback, the complete backend suite on real MySQL, and full synthetic reference-journey backup/restore have passed. Draft PR #10 repeated the application, documentation, MySQL 8.4, and non-deploying release-candidate gates successfully without merging or deploying; the first remote artifact was downloaded and verified against both GitHub and embedded integrity metadata. Separate browser rehearsals completed both diagnosis- and procedure-source correction chains through successor approvals, replacement RMIK review, human ICD-10/ICD-9-CM decisions, correction resolution, and encounter finalization while preserving exact source timestamps. Later browser passes exercised the distinct longitudinal-record route, human safety-disposition route, patient-requested early-departure route, multi-session work-queue scoping, and unsaved-clinical-draft recovery. The draft-guard pass verified visible navigation plus marked Back/Forward interception, all three explicit choices, append-only save-before-leave, no-version discard, generic expired-session recovery without clinical-text echo, separate-tab same-account reauthentication, authorized retry, 390×844 containment/focus, and an empty warning/error console. Automated accessibility coverage guards the complete sign-in Tab order and programmatic error associations. The Hostinger preflight and non-deploying release-candidate contracts are implemented, but actual account evidence and the separate staging deploy/rollback rehearsal remain pending. Stakeholder validation of the safety questions/dispositions, early-departure vocabulary/roles/incomplete-record policy, longitudinal record, procedure-correction responsibility policy, validated Indonesian coding aliases, expert approval of the draft gold set and pilot threshold, remaining native keyboard/manual browser review, and stakeholder UAT also remain pending. No production deployment workflow is enabled until the Hostinger preflight and rollback design are verified.

## Local development

Requirements: PHP 8.3+, Composer 2, Node.js 22+, npm, and SQLite.

```bash
composer install
npm ci
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
npm run build
composer dev
```

Open `http://localhost:8000`. Public self-registration is intentionally unavailable.

### Optional synthetic demo

Set these values only in the ignored local `.env` file:

```dotenv
DEMO_SEED_ENABLED=true
DEMO_ACCOUNT_PASSWORD=choose-at-least-12-characters
```

Then, on a disposable local database:

```bash
php artisan migrate:fresh --seed
```

The learner account is `mahasiswa.keperawatan@example.invalid`; its password is the local value you selected. `migrate:fresh` deletes existing tables and must never be used against an environment containing data that should be preserved.

Prepare a disposable branch from the still-pristine reference session without deleting prior evidence:

```bash
php artisan simulation:clone-reference-session UAT-MAIN-001 --duration=480
```

Use a unique uppercase code for every run. The command is restricted to an explicitly opted-in, synthetic-only, non-production environment; it refuses a progressed or malformed source and rolls back every target record if any write fails. It prints no patient name, MRN, or NIK-like value. To prepare an immediately due no-show branch through the same contract:

```bash
php artisan simulation:clone-reference-session UAT-NO-SHOW-001 --duration=480 --appointment-offset=-5
```

The default appointment offset is 15 minutes after session start. The negative offset is a bounded fixture-preparation input, not a schedule policy or acceptance of class duration. When more than one disposable session is active for an account, open the exact work queue (for example, `/work?session=UAT-MAIN-001`) or choose one session before tasks become available. See [ADR-011](docs/adr/ADR-011-DISPOSABLE-REFERENCE-SESSION-CLONING.md), [ADR-012](docs/adr/ADR-012-MULTI-SESSION-WORK-QUEUE-SCOPING.md), and the [Checkpoint 2 UAT guide](docs/operations/OUTPATIENT_CHECKPOINT_2_UAT_GUIDE.md).

### Optional verified ICD catalogs

The raw ICD workbooks are intentionally not stored in Git. After enabling the synthetic demo fixture, import an explicitly verified local file with its complete expected SHA-256:

```bash
php artisan terminology:import ICD_10 /absolute/path/to/icd10.xlsx --sha256=<64-character-approved-hash>
php artisan terminology:import ICD_9_CM /absolute/path/to/icd9cm.xlsx --sha256=<64-character-approved-hash>
```

The command validates the selected system, workbook schema, version, codes, duplicates, blank rows, formulas, and checksum before atomic activation. See the [coding reference register](docs/research/CODING_REFERENCE_REGISTER.md) for the approved development hashes and redistribution boundary.

After both exact releases are active, reproduce the draft synthetic retrieval baseline without creating clinical records or aliases:

```bash
php artisan coding:evaluate-gold-set --fail-on-reference-miss
```

The command intentionally reports pending expert-review gaps without treating them as an approved accuracy threshold.

On a fresh isolated demo fixture with both exact releases active, this command reproduces the completed reference journey for recovery or demonstration validation:

```bash
php artisan simulation:complete-reference-journey
```

It refuses production, non-synthetic, missing-terminology, and partially progressed contexts. The two fixture codes are attributed manual coder selections from the active releases; the command does not claim that free-text retrieval or autonomous coding is clinically accurate.

For correction-state UI and accessibility validation, use a separate fresh isolated fixture and prepare exactly one attributed branch:

```bash
php artisan simulation:prepare-reference-correction diagnosis
# or, on another fresh fixture
php artisan simulation:prepare-reference-correction procedure
```

The command stops at `AMENDMENT_PENDING`, assigns the exact medical/closure author, and blocks the coder until the guarded successor workflow is completed. Repeating the same command is a no-op; requesting the other branch against that progressed fixture is rejected.

## Quality gates

```bash
composer lint:check
composer types:check
php artisan test
php artisan wayfinder:generate --with-form
npm run format:check
npm run lint:check
npm run types:check
npm run test:unit
npm run build
```

The application workflow also validates dependency manifests, vulnerability advisories, and MySQL migrations. See the [foundation runbook](docs/operations/FOUNDATION_RUNBOOK.md) for environment checks and recovery boundaries.

## Read-only hosting preflight

On the exact built staging runtime, collect a machine-readable result without changing the host:

```bash
php artisan ops:hosting-preflight --json
```

The command returns `INCOMPLETE` until every required Hostinger/GitHub item has sanitized evidence, and `BLOCKED` for unsafe runtime configuration, failed evidence, or malformed evidence. See the [Hostinger staging preflight guide](docs/operations/HOSTINGER_STAGING_PREFLIGHT.md) before supplying an evidence file. A `READY` preflight permits consideration of a separately authorized staging rehearsal; it does not deploy or satisfy `OPS-02`.

## Non-deploying release candidate

After production dependencies and frontend assets are built in a clean checkout, generate and assemble an identifiable runtime candidate:

```bash
php artisan ops:release-manifest release-manifest.json --commit=<checked-out-sha>
php artisan ops:assemble-release release-manifest.json storage/app/release-candidate
```

CI performs these steps only after the application and MySQL jobs pass, then uploads a short-lived immutable tar plus SHA-256 sidecar. It does not contact Hostinger, expose environment secrets, migrate a database, switch a release, merge, or deploy. See the [release candidate artifact guide](docs/operations/RELEASE_CANDIDATE_ARTIFACT.md).

## Product and architecture references

- [Approved project charter](docs/PROJECT_CHARTER.md)
- [Campus SIMRS master plan](docs/SIMRS_CAMPUS_MASTER_PLAN.md)
- [Legacy assessment](docs/LEGACY_ASSESSMENT.md)
- [ADR-001: Teaching-first modular monolith](docs/adr/ADR-001-REBUILD-ARCHITECTURE.md)
- [ADR-002: Same-origin platform foundation](docs/adr/ADR-002-PLATFORM-FOUNDATION.md)
- [ADR-003: Outpatient domain spine](docs/adr/ADR-003-OUTPATIENT-DOMAIN-SPINE.md)
- [ADR-007: Deterministic local interoperability preview](docs/adr/ADR-007-LOCAL-INTEROPERABILITY-PREVIEW.md)
- [ADR-008: Human outpatient safety disposition](docs/adr/ADR-008-HUMAN-OUTPATIENT-SAFETY-DISPOSITION.md)
- [ADR-010: Unsaved clinical draft guard](docs/adr/ADR-010-UNSAVED-CLINICAL-DRAFT-GUARD.md)
- [ADR-011: Disposable reference-session cloning](docs/adr/ADR-011-DISPOSABLE-REFERENCE-SESSION-CLONING.md)
- [ADR-012: Fail-closed multi-session work-queue scoping](docs/adr/ADR-012-MULTI-SESSION-WORK-QUEUE-SCOPING.md)
- [Outpatient evidence register](docs/research/OUTPATIENT_EVIDENCE_REGISTER.md)
- [Outpatient service blueprint](docs/product/OUTPATIENT_SERVICE_BLUEPRINT.md)
- [Outpatient role and permission matrix](docs/product/OUTPATIENT_ROLE_MATRIX.md)
- [Outpatient data dictionary](docs/product/OUTPATIENT_DATA_DICTIONARY.md)
- [Assumption and validation register](docs/product/ASSUMPTION_AND_VALIDATION_REGISTER.md)
- [Outpatient acceptance scenarios](docs/product/OUTPATIENT_ACCEPTANCE_SCENARIOS.md)
- [Outpatient traceability matrix](docs/product/OUTPATIENT_TRACEABILITY_MATRIX.md)
- [Computer-assisted coding specification](docs/product/COMPUTER_ASSISTED_CODING_SPEC.md)
- [Coding reference register](docs/research/CODING_REFERENCE_REGISTER.md)
- [Computer-assisted coding validation record](docs/operations/COMPUTER_ASSISTED_CODING_VALIDATION.md)
- [Synthetic coding retrieval baseline](docs/operations/CODING_GOLD_SET_BASELINE.md)
- [Local MySQL and recovery validation](docs/operations/LOCAL_MYSQL_RECOVERY_VALIDATION.md)
- [Hostinger staging preflight](docs/operations/HOSTINGER_STAGING_PREFLIGHT.md)
- [Release candidate artifact](docs/operations/RELEASE_CANDIDATE_ARTIFACT.md)
- [Checkpoint 2 outpatient UAT facilitator guide](docs/operations/OUTPATIENT_CHECKPOINT_2_UAT_GUIDE.md)
- [Checkpoint 2 outpatient UAT record template](docs/operations/OUTPATIENT_CHECKPOINT_2_UAT_RECORD_TEMPLATE.md)
- [GitHub publication checklist](docs/operations/GITHUB_PUBLICATION_CHECKLIST.md)
- [UEU Clinical design system](docs/design/UEU_CLINICAL_DESIGN_SYSTEM.md)
- [Information architecture](docs/design/INFORMATION_ARCHITECTURE.md)
- [Outpatient critical-path wireframes](docs/design/OUTPATIENT_WIREFRAMES.md)
- [Outpatient interaction specifications](docs/design/OUTPATIENT_INTERACTION_SPECIFICATIONS.md)

## Authority and validation gates

Daniel Happy Putra is the sole project manager/PIC and final authority for scope, priority, acceptance, and releases during the reference-build phase. Stakeholder input is concentrated at three checkpoints after a concrete model exists:

1. workflow-baseline validation;
2. end-to-end UAT using one shared synthetic case; and
3. faculty-pilot readiness after security, accessibility, and deployment evidence is available.

These checkpoints improve the model without transferring final product authority. Real patient data remains prohibited until a separate institutional clinical, privacy, legal, security, and operational approval process is completed.
