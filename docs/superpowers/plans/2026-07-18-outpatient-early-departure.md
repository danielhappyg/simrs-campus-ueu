# Outpatient Early Departure Implementation Plan

**Goal:** Add a human-recorded, attributable `Pulang atas permintaan sendiri` branch after clinical service begins without misclassifying it as cancellation/no-show or automatically finalizing an incomplete chart.

**Architecture:** Persist one append-only early-departure aggregate with an exact source-state/source-hash snapshot. A dedicated capability gates a transactional service that records the event, moves appointment/encounter to distinct terminal states, ends queue visibility, cancels unfinished tasks, and emits minimized audit. A dedicated Inertia workspace is linked from the encounter overview only when server eligibility is true.

**Tech stack:** Laravel 13, PHP 8.3+, Eloquent, Inertia, React 19, TypeScript, Tailwind CSS, PHPUnit, Vitest, Testing Library, axe-core.

## Constraints

- Synthetic simulation only; no real patient data or SATUSEHAT transmission.
- No merge or deployment. Update only `agent/outpatient-domain-spine` and draft PR #10.
- Preserve untracked `deliverables/` and the ignored release-control artifact.
- Use red-green-refactor for every production behavior.
- Do not implement a clinical safety verdict, recommendation, risk threshold, consent signature, automatic closure, coding, finalization, or report release.
- Keep vocabulary and role policy pending multidisciplinary validation.

## Task 1: Domain state and append-only provenance

- Add failing tests for the new terminal encounter/appointment states, allowed source states, append-only row, source snapshot/hash, unique encounter, and request idempotency.
- Implement migration, enum, model relationships/guards, and state-machine labels/transitions.
- Run the focused backend test to green.

## Task 2: Transactional workflow and authorization

- Add failing tests for exact medical-supervisor/session-facilitator scope, wrong role/case/session/revocation denial, task/queue reconciliation, prior-history preservation, minimized audit, competing request rejection, and rollback.
- Add `early-departure.record` to reference supervisor/facilitator assignments.
- Implement resolver and transactional service.
- Run focused backend tests to green.

## Task 3: HTTP contract and encounter navigation

- Add failing feature tests for GET/POST routes, server-derived overview URL, required confirmation/reason/communication summary, private headers, and read-only recorded state.
- Implement request, controllers, routes, overview action, and Wayfinder generation.
- Run focused feature tests and TypeScript generation checks to green.

## Task 4: UEU interface and accessibility

- Add failing React tests for permanent synthetic/non-clinical-advice copy, no implicit confirmation, required/error-linked inputs, consequence summary, source provenance, recorded read-only state, 44-pixel controls, and axe.
- Implement the dedicated UEU workspace and overview action.
- Run focused React, axe, and TypeScript tests to green.

## Task 5: Traceability and staged validation

- Add the curated longitudinal-record event without exposing protected free text.
- Update acceptance, traceability, data dictionary, role matrix, UAT guide, README, ADR status, and validation record with exact limitations.
- Browser-test a fresh synthetic early-departure fixture at desktop and 390×844.
- Run full PHP/React/MySQL/static/security/build/documentation gates.
- Commit, push, update draft PR #10, and wait for final remote CI. Do not merge or deploy.
