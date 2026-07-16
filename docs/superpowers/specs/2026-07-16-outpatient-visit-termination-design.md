# Outpatient Visit Termination Design

- **Date:** 16 July 2026
- **Status:** Approach approved; written specification pending Daniel's review
- **Acceptance target:** `E2E-06 — cancel without deleting history`
- **Scope:** Synthetic outpatient reference MVP only

## 1. Problem

The outpatient blueprint and acceptance scenarios define `CANCELLED` and `NO_SHOW` as terminal encounter outcomes. The current application already contains the appointment, encounter, queue, work-task, transition, and audit primitives, but it has no visit-level termination service, endpoint, registration-workspace controls, or end-to-end tests.

This leaves `E2E-06` incomplete: a registrar can check in a synthetic appointment, but cannot cancel the visit while preserving that completed action and its history.

## 2. Decision

Implement a registrar-centred terminal workflow for the bounded states used by the reference registration path:

| Outcome | Appointment source | Encounter source | Additional rule |
|---|---|---|---|
| `CANCELLED` | `BOOKED` | `PLANNED` | Mandatory attributed reason |
| `CANCELLED` | `CHECKED_IN` | `ARRIVED` | Mandatory attributed reason; completed check-in remains complete |
| `NO_SHOW` | `BOOKED` | `PLANNED` | Mandatory attributed reason and `scheduled_at <=` the normalized server clock |

The appointment termination service will reject later clinical states. The existing encounter state machine may continue to describe broader future transitions, but this registrar endpoint will not expose them. Cancellation after nursing intake starts requires a separately designed facilitator/clinical workflow.

## 3. Authorization boundary

- The caller must be authenticated, active, verified, and inside the simulation middleware boundary.
- The caller must have an active `PatientRegister` assignment for the exact appointment/encounter context or the existing session-wide registrar assignment.
- A medical, nursing, pharmacy, RMIK-coding, administrator-only, inactive, cross-session, or cross-case account cannot terminate the visit through this endpoint.
- The service revalidates assignment, session, patient, appointment, encounter, and location relationships under database locks; UI visibility is not treated as authorization.

Facilitator-only termination is excluded from this increment. Facilitators can receive a later, deliberately broader workflow rather than inheriting registrar actions implicitly.

## 4. Request and response contract

Add one authenticated endpoint:

```text
POST /appointments/{appointment}/termination
```

The request contains:

- `outcome`: exactly `CANCELLED` or `NO_SHOW`;
- `reason`: trimmed, required, human-authored, 10–500 characters; and
- no patient identity, clinical payload, or client-supplied actor/time fields.

Successful termination redirects to the session registration workspace with a plain-language success message. Validation errors return the existing Inertia validation response. Authorization failures return `403`; invalid source state, conflicting terminal outcome, missing encounter, or a premature no-show return a safe `409` without exposing unrelated case data.

Repeated submission of the same terminal outcome is idempotent: it returns the existing terminal result without creating another transition, queue mutation, task mutation, or audit event. The first committed reason remains authoritative and a repeated request cannot replace it. A conflicting terminal outcome is rejected.

## 5. Transactional data flow

One dedicated patient-domain service will own the operation.

1. Lock the appointment and load the linked encounter, active session, patient, location, current queue event, and acting assignment.
2. Revalidate simulation mode, active session, exact context, allowed outcome, source status, non-empty reason, and no-show timing.
3. Transition the encounter through `EncounterTransitionService`, preserving its append-only transition and audit behavior. The transition reason is the authoritative attributed visit-termination reason.
4. Set the appointment status to `CANCELLED` or `NO_SHOW`. Preserve `checked_in_at` when a checked-in appointment is cancelled.
5. If an active queue entry exists, set it to `CANCELLED`, set `ended_at`, and store only a controlled operational marker such as `encounter_cancelled`; do not copy free-text reason into the public-queue source.
6. Set every unfinished work task for the encounter to `CANCELLED` with `completed_at`. Preserve tasks already `COMPLETE` or `CANCELLED` and preserve their timestamps.
7. Record a minimized appointment-level audit event containing terminal appointment status, encounter status, whether a queue entry ended, and the number of tasks cancelled. The actor, assignment, session, patient, encounter, server time, and correlation metadata continue to come from `AuditRecorder`.
8. Commit all changes together or roll back all changes.

No migration is required. Appointment terminal state remains on `appointment_registrations`; actor, reason, time, and status provenance remain on the existing append-only encounter transition and audit trail. This avoids duplicating reason/time fields with competing sources of truth.

## 6. Registration workspace

Each appointment card will receive server-derived termination capabilities and a termination URL.

- `BOOKED`/`PLANNED`: show Check-in plus a secondary visit-termination action. The dialog offers cancellation and, only after the scheduled time, no-show.
- `CHECKED_IN`/`ARRIVED`: show cancellation only.
- Terminal or later clinical states: show no termination control.
- Terminal cards show the existing appointment and encounter status labels. The encounter timeline remains the source for actor, reason, and time.

The confirmation dialog will include:

- an explicit synthetic-visit warning;
- the selected outcome in plain Indonesian;
- a required labelled reason field with programmatic error association;
- separate Cancel and Confirm controls;
- destructive styling only on the final confirmation;
- disabled/pending state while submitting; and
- focus containment and return through the existing Radix dialog primitive.

The workflow must retain the permanent `SIMULASI — DATA SINTETIS` boundary and the shared 44 CSS-pixel narrow/coarse-pointer target rule.

## 7. Read models and public queue

The registration payload will expose only derived action flags, the endpoint URL, and terminal labels needed by the card. It will not expose authorization internals.

The public queue projection must continue to omit terminal queue entries and all patient identity, visit reason, free-text termination reason, and clinical content. A cancelled checked-in visit therefore disappears from the active public queue while its internal queue event remains stored.

The encounter overview and chronological timeline will render the existing terminal encounter status and append-only transition reason without deleting earlier events.

## 8. Error and concurrency handling

- Database row locks prevent check-in and termination from committing incompatible outcomes concurrently.
- A termination that loses the race to check-in re-evaluates the locked state and may continue only if the resulting state is the supported `CHECKED_IN`/`ARRIVED` cancellation path.
- A check-in that loses the race to termination observes a non-`BOOKED` appointment and fails safely.
- Missing or mismatched appointment/encounter relationships fail before mutation.
- No-show before the scheduled time fails even if a client manually enables the UI control. The service compares the persisted appointment timestamp with Laravel's normalized server clock; the client does not decide whether the appointment is overdue.
- Later clinical states fail through the termination service even though broader transitions remain represented in the state enum.
- Queue/task update counts are recorded as metadata, not assumed from client state.

## 9. Test strategy

### Backend feature coverage

Tests will prove:

1. cancellation from `BOOKED`/`PLANNED` updates appointment and encounter atomically;
2. cancellation after check-in preserves the completed registration task and check-in history, cancels the active queue entry and ready nursing task, and satisfies `E2E-06`;
3. no-show is allowed only for a past `BOOKED`/`PLANNED` appointment and creates no queue entry;
4. repeated identical termination is idempotent;
5. a conflicting terminal outcome is rejected;
6. missing reason, premature no-show, later clinical state, wrong discipline, inactive assignment, and cross-session/cross-case requests are rejected without mutation;
7. transition and appointment audit events contain the expected minimized provenance;
8. the public queue excludes the terminated entry and sensitive reason text; and
9. the registration Inertia payload exposes actions only in the allowed states.

Tests will be written and observed failing before production implementation begins.

### Frontend component coverage

The registration-workspace test will prove:

- action visibility for booked, arrived, terminal, and later clinical states;
- no-show visibility only when permitted by the server payload;
- labelled required reason and associated validation message;
- correct outcome/reason submission;
- pending-state protection; and
- accessible dialog naming and focus behavior under axe/testing-library coverage.

### Regression gates

After focused green tests, run PHP formatting, PHPStan, the relevant backend suite, frontend unit tests, TypeScript, ESLint, Prettier, production build, Markdown validation, and the existing release/security gates appropriate to the changed scope.

## 10. Documentation and traceability

Implementation will update:

- `OUTPATIENT_TRACEABILITY_MATRIX.md` for `E2E-06` evidence;
- `OUTPATIENT_DOMAIN_SPINE_VALIDATION.md` with test and browser limitations;
- `OUTPATIENT_CHECKPOINT_2_UAT_GUIDE.md` with cancellation/no-show rehearsal steps;
- `ADR-003-OUTPATIENT-DOMAIN-SPINE.md` to remove the outdated statement that cancellation/no-show UI is wholly unimplemented; and
- the draft PR description and verification evidence.

No stakeholder acceptance, production suitability, or hosted deployment claim will be inferred from automated evidence.

## 11. Non-goals

This increment does not implement:

- cancellation after nursing intake or consultation begins;
- transfer, discharge-against-advice, clinical abandonment, or death workflows;
- automatic rescheduling or a replacement appointment;
- SMS/email notifications;
- refunds, billing reversals, BPJS/INA-CBG/e-claim effects;
- SATUSEHAT cancellation transmission;
- deletion or anonymization of completed history;
- facilitator-wide termination controls; or
- real-patient or production use.

## 12. Acceptance criteria

The increment is complete when:

- an authorized registrar can cancel a synthetic planned or arrived visit with a required reason;
- an authorized registrar can mark an overdue planned appointment as no-show;
- appointment, encounter, queue, unfinished tasks, transitions, and audit records remain transactionally consistent;
- completed actions and prior history remain immutable and visible;
- unsupported actors, contexts, outcomes, times, and states fail safely;
- the public queue leaks no identity or free-text reason;
- accessible registration controls expose only server-authorized actions;
- focused and full verification gates pass; and
- changes are committed and pushed only to the existing draft PR, with no merge or deployment.

## 13. Alternatives considered

1. **Broad facilitator-controlled termination across all state-machine sources.** More flexible, but it combines registrar, clinical-abandonment, transfer, and later-state policy decisions that require a separate multidisciplinary design.
2. **Backend-only terminal API.** Faster, but it does not satisfy operability or the acceptance scenario's observable workflow.
3. **Selected registrar workflow (this design).** Completes the promised reference path with a narrow authority and preserves a clean extension point for later clinical/facilitator termination policies.
