# Outpatient Safety Disposition Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Complete `E2E-02` with an attributable human disposition that either resumes routine outpatient simulation flow or records a simulated transfer, while giving no clinical recommendation.

**Architecture:** Add an append-only disposition aggregate bound to the exact approved nursing escalation, a narrow capability/work-task type, and a transactional service that coordinates disposition provenance, encounter transition, work-task release/cancellation, and minimized audit. Expose it through one authorized Inertia workspace with a permanent simulation/non-emergency boundary and no default outcome.

**Tech Stack:** Laravel 13, PHP 8.3+, Eloquent, Inertia, React 19, TypeScript, Tailwind CSS, PHPUnit, Vitest, Testing Library, axe-core.

## Global constraints

- Synthetic simulation data only; no real patient data or external transfer.
- Do not merge or deploy. Push only to `agent/outpatient-domain-spine` and update draft PR #10.
- Preserve untracked `deliverables/` and the ignored release-control test artifact.
- Use failing tests before every production behavior change.
- Do not implement emergency triage, thresholds, severity scoring, diagnosis, treatment, or a recommended/default disposition.
- Keep `VAL-A01`–`VAL-A04` pending stakeholder validation.
- Keep the rationale out of work-task context and audit metadata.
- Do not expose internal numeric IDs or non-synthetic data.

---

### Task 1: Persist an attributable append-only disposition

**Files:**
- Create: `database/migrations/2026_07_17_000100_create_outpatient_safety_dispositions_table.php`
- Create: `app/Modules/Clinical/Enums/OutpatientSafetyDispositionOutcome.php`
- Create: `app/Modules/Clinical/Models/OutpatientSafetyDisposition.php`
- Create: `tests/Feature/OutpatientSafetyDispositionWorkflowTest.php`

- [x] **Step 1: Write failing persistence and invariant tests**

Cover exact source nursing version/hash, actor assignment/user, outcome, rationale, occurred-at, unique request key, one disposition per encounter, cross-context rejection, and update/delete rejection.

- [x] **Step 2: Run the focused test and verify RED**

```bash
php artisan test --compact tests/Feature/OutpatientSafetyDispositionWorkflowTest.php
```

Expected: missing migration/enum/model failures.

- [x] **Step 3: Implement the migration, enum labels, relations, casts, and append-only model guards**

Use restrictive foreign keys and indexes for encounter/source/actor lookup. Enforce source and actor context at create time without adding clinical decision logic to the model.

- [x] **Step 4: Run the focused test and verify GREEN**

- [x] **Step 5: Commit**

```bash
git add database/migrations app/Modules/Clinical/Enums app/Modules/Clinical/Models tests/Feature/OutpatientSafetyDispositionWorkflowTest.php
git commit -m "feat: persist outpatient safety dispositions"
```

### Task 2: Create the escalation task and transactional outcomes

**Files:**
- Modify: `app/Modules/Teaching/Enums/Capability.php`
- Modify: `app/Modules/Teaching/Enums/WorkTaskType.php`
- Modify: `app/Modules/Clinical/Services/ClinicalDocumentationService.php`
- Create: `app/Modules/Clinical/Services/OutpatientSafetyDispositionService.php`
- Modify: `app/Modules/Teaching/Services/ReferenceOutpatientJourneyBuilder.php`
- Modify: `database/seeders/DemoSimulationSeeder.php`
- Modify: `tests/Feature/OutpatientSafetyDispositionWorkflowTest.php`
- Modify: `tests/Feature/NursingIntakeWorkflowTest.php`

- [x] **Step 1: Add failing task, authorization, outcome, idempotency, audit, and rollback tests**

Assert supervisor/facilitator tasks, blocked medical work, resume/transfer states, required active capability/scope, same-request idempotency, competing-decision failure, minimized audit metadata, and transaction rollback after a forced downstream failure.

- [x] **Step 2: Run focused backend tests and verify RED**

```bash
php artisan test --compact tests/Feature/OutpatientSafetyDispositionWorkflowTest.php tests/Feature/NursingIntakeWorkflowTest.php
```

- [x] **Step 3: Add the narrow capability and task type to reference assignments**

`SAFETY_DISPOSITION` requires `safety-disposition.record`. Grant that capability only to the linked supervisors and facilitator reference assignments that may perform the action.

- [x] **Step 4: Create disposition tasks when the nursing escalation is approved**

Create/update tasks for the linked nursing supervisor and session facilitator, safely deduplicate assignments, bind source public ID/hash in context, and leave medical work blocked.

- [x] **Step 5: Implement the transactional disposition service**

Lock request/encounter/session/actor/source/competing state, validate scope and source, create the append-only row, transition the encounter, reconcile tasks, and write minimized audit.

- [x] **Step 6: Run focused backend tests and verify GREEN**

- [x] **Step 7: Commit**

```bash
git add app/Modules database/seeders tests/Feature
git commit -m "feat: resolve outpatient safety escalations"
```

### Task 3: Authorized HTTP workspace and work-queue routing

**Files:**
- Create: `app/Http/Controllers/Clinical/OutpatientSafetyDispositionWorkspaceController.php`
- Create: `app/Http/Controllers/Clinical/StoreOutpatientSafetyDispositionController.php`
- Create: `app/Http/Requests/Clinical/StoreOutpatientSafetyDispositionRequest.php`
- Modify: `app/Modules/Teaching/Services/AssignmentContextResolver.php`
- Modify: `app/Http/Controllers/Work/WorkQueueController.php`
- Modify: `routes/web.php`
- Modify: `tests/Feature/OutpatientSafetyDispositionWorkflowTest.php`
- Modify: `tests/Feature/WorkQueueTest.php`

- [x] **Step 1: Write failing route, payload, denial, privacy, and work-queue tests**

Cover supervisor/facilitator GET, wrong capability/case/session/revocation denial, required outcome/rationale, response privacy headers, read-only post-decision view, and `SAFETY_DISPOSITION` action URL.

- [x] **Step 2: Run focused tests and verify RED**

- [x] **Step 3: Implement the dedicated resolver, request, controllers, routes, and queue mapping**

The read model exposes the exact source version/hash and authorized human context. It sets `canRecord=false` after disposition and generates a fresh ULID request key only for a recordable state.

- [x] **Step 4: Generate Wayfinder routes and verify GREEN**

```bash
php artisan wayfinder:generate --with-form
php artisan test --compact tests/Feature/OutpatientSafetyDispositionWorkflowTest.php tests/Feature/WorkQueueTest.php
```

- [x] **Step 5: Commit**

```bash
git add app/Http app/Modules/Teaching routes resources/js/routes resources/js/actions tests/Feature
git commit -m "feat: expose safety disposition workspace"
```

### Task 4: UEU paused-flow interface

**Files:**
- Create: `resources/js/pages/clinical/safety-disposition.tsx`
- Create: `resources/js/test/safety-disposition.test.tsx`
- Create or modify: `resources/js/types/clinical.ts`

- [x] **Step 1: Write failing React tests**

Assert permanent synthetic/non-emergency/non-recommendation copy, paused-flow rail, exact source stamp, no preselected radio, required rationale/error associations, outcome consequences, read-only recorded state, mobile containment, and zero axe violations.

- [x] **Step 2: Run the focused frontend test and verify RED**

```bash
npm run test:unit -- resources/js/test/safety-disposition.test.tsx
```

- [x] **Step 3: Implement the accessible UEU interface**

Use a semantic fieldset with native radios, one explicit submit control, established UEU tokens and components, visible focus, one h1, 44-pixel targets, and no clinical recommendation/default.

- [x] **Step 4: Run focused React, axe, TypeScript, and backend tests and verify GREEN**

- [x] **Step 5: Commit**

```bash
git add resources/js/pages/clinical/safety-disposition.tsx resources/js/test/safety-disposition.test.tsx resources/js/types/clinical.ts
git commit -m "feat: add human safety disposition interface"
```

### Task 5: Traceability, browser evidence, and draft-PR gates

**Files:**
- Modify: `README.md`
- Modify: `docs/product/OUTPATIENT_TRACEABILITY_MATRIX.md`
- Modify: `docs/product/OUTPATIENT_DATA_DICTIONARY.md`
- Modify: `docs/product/OUTPATIENT_ROLE_MATRIX.md`
- Modify: `docs/operations/OUTPATIENT_DOMAIN_SPINE_VALIDATION.md`
- Modify: `docs/operations/OUTPATIENT_CHECKPOINT_2_UAT_GUIDE.md`

- [x] **Step 1: Update product and operational documentation**

Record the implemented human-disposition boundary and automated coverage while keeping `VAL-A01`–`VAL-A04`, faculty acceptance, merge, and deployment pending.

- [x] **Step 2: Browser-validate a fresh escalated synthetic fixture**

At desktop and 390×844, verify supervisor/facilitator access, permanent boundaries, no default outcome, keyboard operation, one resume decision, medical-task release, source/version provenance, one main/h1, unique IDs, no page overflow, 44-pixel controls, and clean fresh warning/error logs.

- [x] **Step 3: Run full local verification**

Run complete PHP/React suites, MySQL-equivalent coverage where configured, Pint, PHPStan, ESLint, Prettier, TypeScript, Wayfinder, production build, migration/seed, Composer/npm audits, Markdown/link validation, credential guard, and `git diff --check`. Move the ignored release-control artifact reversibly only during recursive lint/link checks.

- [ ] **Step 4: Commit documentation and push**

Commit only intended files, keep `deliverables/` untracked, push `agent/outpatient-domain-spine`, and update draft PR #10 with explicit no-merge/no-deploy language.

- [ ] **Step 5: Monitor final remote checks**

Wait for documentation, application/security, MySQL, React, and non-deploying release-candidate jobs. Record exact evidence only after success; if an evidence-only commit creates a new head, wait for that head too.
