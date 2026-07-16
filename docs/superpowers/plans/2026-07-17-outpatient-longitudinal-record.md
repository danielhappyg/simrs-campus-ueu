# Outpatient Longitudinal Record Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Provide a distinct, context-authorized, read-only longitudinal record for the shared synthetic outpatient encounter while keeping finalized debrief teaching evidence separate.

**Architecture:** Reuse the existing curated material-event projection as the single deterministic event builder. Add a dedicated contextual resolver and controller for `/timeline`, a focused Inertia page, and navigation from encounter/debrief surfaces. Preserve `/debrief` as the finalized teaching workspace and keep raw audit internals out of both projections.

**Tech Stack:** Laravel 13, PHP 8.3+, Eloquent, Inertia, React 19, TypeScript, Tailwind CSS, PHPUnit, Vitest, Testing Library, axe-core.

## Global Constraints

- Synthetic simulation data only; no real patient input, credentials, or production endpoint.
- Do not merge or deploy. Push only to `agent/outpatient-domain-spine` and update draft PR #10.
- Preserve untracked `deliverables/` and unrelated local artifacts.
- Use failing tests before every production behavior change.
- Keep `/debrief` finalized-gated and distinguish it from `/timeline`.
- Do not expose audit reasons, correlation IDs, IP hashes, user agents, arbitrary metadata, content hashes, or clinical free text.
- Do not claim legal record, certified output, FHIR transmission, autonomous clinical verdict, stakeholder acceptance, native keyboard completion, or native print completion.

---

### Task 1: Contextual record-timeline authorization and route separation

**Files:**
- Create: `tests/Feature/EncounterRecordTimelineTest.php`
- Create: `app/Http/Controllers/Encounter/EncounterRecordTimelineController.php`
- Modify: `app/Modules/Teaching/Services/AssignmentContextResolver.php`
- Modify: `routes/web.php`
- Modify: `tests/Feature/EncounterDebriefTest.php`

**Interfaces:**
- Consumes: `AssignmentContextResolver::activeAssignments()` and existing `EncounterDebriefTimeline::build(Encounter): array`.
- Produces: `AssignmentContextResolver::forRecordTimeline(User, Encounter): Assignment`, route `encounters.timeline.show`, and Inertia component `encounter/timeline`.

- [ ] **Step 1: Write failing feature tests**

Add tests that seed the reference outpatient case and assert:

```php
$this->actingAs($nurse)
    ->get(route('encounters.timeline.show', $encounter))
    ->assertOk()
    ->assertInertia(fn (Assert $page) => $page
        ->component('encounter/timeline')
        ->where('encounter.status.code', EncounterStatus::Planned->value));
```

Also cover completed-session exact-case access, active session-wide facilitator access, wrong session, wrong case, missing `session.view`, and administrator denial. Change the existing completed-session alias assertion to call `encounters.debrief.show` and add a check that pre-finalization `/debrief` still returns 409.

- [ ] **Step 2: Run the focused test and verify RED**

Run:

```bash
php artisan test --compact tests/Feature/EncounterRecordTimelineTest.php tests/Feature/EncounterDebriefTest.php
```

Expected: the new timeline assertions fail because `/timeline` still renders `encounter/debrief` and rejects pre-finalization access.

- [ ] **Step 3: Implement the resolver, controller shell, and route split**

Implement `forRecordTimeline()` with active/completed simulation-session support, exact-case `session.view`, and session-wide `session.view + session.facilitate`. Add a dedicated controller that returns patient, encounter, assignment, session, release, empty event/summary placeholders, and URLs. Point only `/timeline` to the new controller; leave `/debrief` on `EncounterDebriefController`.

- [ ] **Step 4: Run the focused test and verify GREEN**

Run the command from Step 2. Expected: authorization and route-separation tests pass.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Encounter/EncounterRecordTimelineController.php app/Modules/Teaching/Services/AssignmentContextResolver.php routes/web.php tests/Feature/EncounterRecordTimelineTest.php tests/Feature/EncounterDebriefTest.php
git commit -m "feat: separate longitudinal record access"
```

### Task 2: Deterministic material-event record projection

**Files:**
- Modify: `tests/Feature/EncounterRecordTimelineTest.php`
- Modify: `app/Modules/Teaching/Services/EncounterDebriefTimeline.php`
- Modify: `app/Http/Controllers/Encounter/EncounterRecordTimelineController.php`

**Interfaces:**
- Consumes: `EncounterDebriefTimeline::build(Encounter): array`.
- Produces: the documented `events` and `summary` payload plus `record.timeline_viewed` audit evidence.

- [ ] **Step 1: Add failing projection and minimization tests**

Create material events with differing clinical and recorded times, exact source versions, and actors. Assert stable order and fields using Inertia paths such as:

```php
->where('events.0.source.version', 'v1')
->where('events.0.actor.name', $nurse->name)
->where('events.0.showsRecordedTimeDifference', true)
->where('summary.displayedEventCount', 3)
```

Terminate a fresh appointment with a unique free-text reason, then assert an `appointment.terminated` card exists with the terminal outcome while the serialized response excludes that reason and raw keys (`reason`, `correlation`, `ip`, `userAgent`, `metadata`, `contentHash`). Add 301 allowlisted events and assert only 300 are returned with `truncated=true`.

- [ ] **Step 2: Run the focused test and verify RED**

Run:

```bash
php artisan test --compact tests/Feature/EncounterRecordTimelineTest.php
```

Expected: timeline content/audit assertions fail because the controller has placeholders and `appointment.terminated` is not allowlisted.

- [ ] **Step 3: Connect the event builder and add the termination presentation**

Inject `EncounterDebriefTimeline` and `AuditRecorder` into the controller, spread the builder payload into the Inertia response, and write `record.timeline_viewed` metadata containing only:

```php
[
    'displayed_event_count' => data_get($timeline, 'summary.displayedEventCount'),
    'total_available_event_count' => data_get($timeline, 'summary.totalAvailableEventCount'),
    'truncated' => data_get($timeline, 'summary.truncated'),
]
```

Add `appointment.terminated` to `MATERIAL_ACTIONS`, map `CANCELLED` to `Dibatalkan` and `NO_SHOW` to `Tidak hadir`, and expose only that normalized outcome in the card detail.

- [ ] **Step 4: Run the focused test and verify GREEN**

Run the command from Step 2. Expected: all record-projection tests pass.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Encounter/EncounterRecordTimelineController.php app/Modules/Teaching/Services/EncounterDebriefTimeline.php tests/Feature/EncounterRecordTimelineTest.php
git commit -m "feat: project longitudinal record events"
```

### Task 3: Accessible longitudinal-record page

**Files:**
- Create: `resources/js/pages/encounter/timeline.tsx`
- Create: `resources/js/test/encounter-timeline.test.tsx`
- Modify: `resources/js/pages/encounter/show.tsx`
- Modify: `resources/js/pages/encounter/debrief.tsx`
- Modify: `app/Http/Controllers/Encounter/EncounterOverviewController.php`
- Modify: `app/Http/Controllers/Encounter/EncounterDebriefController.php`

**Interfaces:**
- Consumes: the controller data contract from Tasks 1–2.
- Produces: read-only `encounter/timeline` UI, overview/debrief navigation, category/program presentation filters, and accessible announced counts.

- [ ] **Step 1: Write failing React tests**

Render `encounter/timeline` with at least three events across two categories/programs. Assert permanent simulation labelling, the source-index boundary, source/version and actor/role text, clinical plus recorded time, category/program filters, live result count, truncation notice, and absence of raw audit labels. Run axe and require no serious/critical violations. Extend encounter/debrief tests to expect the new timeline navigation URL.

- [ ] **Step 2: Run the frontend tests and verify RED**

Run:

```bash
npm run test:unit -- resources/js/test/encounter-timeline.test.tsx resources/js/test/encounter-debrief.test.tsx
```

Expected: failure because `encounter/timeline.tsx` and the navigation controls do not exist.

- [ ] **Step 3: Implement the page and navigation**

Build a focused page using `PatientContextBanner`, native `<select>` controls, an `aria-live="polite"` count, semantic `<ol>`, `<time>`, visible text tags, honest empty/truncation states, and UEU tokens already used in encounter/debrief pages. Add `timeline` URLs to overview and debrief payloads and render `Buka linimasa rekam` actions. Show `Buka debrief` only when the server supplies a finalized authorized URL.

- [ ] **Step 4: Run the frontend tests and verify GREEN**

Run the command from Step 2. Expected: both files pass with zero axe serious/critical findings.

- [ ] **Step 5: Generate route bindings and run focused backend regression**

Run:

```bash
php artisan wayfinder:generate --with-form
php artisan test --compact tests/Feature/EncounterRecordTimelineTest.php tests/Feature/EncounterDebriefTest.php
```

Expected: generation exits 0 and focused backend tests pass.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Encounter/EncounterOverviewController.php app/Http/Controllers/Encounter/EncounterDebriefController.php resources/js/pages/encounter/timeline.tsx resources/js/pages/encounter/show.tsx resources/js/pages/encounter/debrief.tsx resources/js/test/encounter-timeline.test.tsx resources/js/routes resources/js/actions
git commit -m "feat: add longitudinal record workspace"
```

### Task 4: Traceability and staged validation

**Files:**
- Modify: `README.md`
- Modify: `docs/product/OUTPATIENT_TRACEABILITY_MATRIX.md`
- Modify: `docs/operations/OUTPATIENT_DOMAIN_SPINE_VALIDATION.md`
- Modify: `docs/operations/OUTPATIENT_CHECKPOINT_2_UAT_GUIDE.md`

**Interfaces:**
- Consumes: implementation and verification evidence from Tasks 1–3.
- Produces: explicit `E2E-11` implementation evidence and an updated UAT observation step without claiming stakeholder acceptance.

- [ ] **Step 1: Update documentation**

Record route separation, authorization boundary, event/data minimization, termination-card coverage, automated counts, browser evidence, and limitations. Keep `EMR-001` and `EMR-003` as `IN PROGRESS` while multidisciplinary UAT and broader patient-level longitudinal history remain open.

- [ ] **Step 2: Run the Markdown and stale-claim checks**

Run the repository's tracked-Markdown validator and search current product/operations docs for claims that `/timeline` is a finalized debrief alias. Historical files under `docs/superpowers/**` remain historical design records.

- [ ] **Step 3: Browser-validate a fresh synthetic fixture**

Build production assets, create a disposable SQLite database, seed demo assignments, and serve it on an unused localhost port. In the in-app browser:

1. sign in as an exact-case nursing learner;
2. open `/timeline` before finalization and confirm it differs from `/debrief`;
3. inspect filters, source/version/actor/time presentation, focus behavior, landmarks, duplicate IDs, 390×844 containment, and retained warning/error logs;
4. complete the deterministic reference journey in the fixture;
5. reopen the timeline and debrief to confirm separate navigation and finalized content;
6. stop the server and retain no fixture in Git.

- [ ] **Step 4: Run full local verification**

Run fresh backend, frontend, formatting, static-analysis, lint, type, generated-route, dependency-audit, build, and diff checks using the same CI commands. Work around only the known ignored `storage/framework/testing/release-control-e2e` recursion artifact without deleting or modifying it.

- [ ] **Step 5: Commit documentation**

```bash
git add README.md docs/product/OUTPATIENT_TRACEABILITY_MATRIX.md docs/operations/OUTPATIENT_DOMAIN_SPINE_VALIDATION.md docs/operations/OUTPATIENT_CHECKPOINT_2_UAT_GUIDE.md
git commit -m "docs: validate longitudinal outpatient record"
```

### Task 5: Draft-PR handoff and remote gates

**Files:**
- No new application files expected.
- Modify documentation only if final CI/artifact evidence must be recorded.

**Interfaces:**
- Consumes: verified local branch.
- Produces: synchronized remote feature branch and updated draft PR #10; no merge or deployment.

- [ ] **Step 1: Verify final diff and branch boundary**

Confirm only intended tracked files are staged/committed and `deliverables/` remains untracked and unstaged. Re-read this plan and the design spec requirement by requirement.

- [ ] **Step 2: Push the approved feature branch**

```bash
git push origin agent/outpatient-domain-spine
```

- [ ] **Step 3: Update draft PR #10**

Add `E2E-11`, `DOC-01`, `EMR-001`, and `EMR-003` scope, source grounding, data-minimization boundary, local/browser evidence, remaining limitations, and explicit `no merge / no deploy` language.

- [ ] **Step 4: Monitor remote checks**

Wait for documentation, PHP/React/security, MySQL 8.4, and non-deploying release-candidate checks. Record run IDs, test counts, artifact identity/digests, and `NOT_DEPLOYED` only after the checks finish successfully.

- [ ] **Step 5: Commit and push CI evidence if documentation changes**

If a docs-only CI-evidence commit is created, push it and wait for the new head checks before reporting. Verify PR #10 remains open, draft, unmerged, and based on `main`.

