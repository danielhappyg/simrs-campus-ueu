# ADR-003: Outpatient Identity, Encounter, and Check-in Spine

- **Status:** Implemented working reference decision; pending Daniel/checkpoint acceptance
- **Date:** 2026-07-15
- **Final decision authority:** Daniel Happy Putra, project manager/PIC
- **Implementation authority:** delegated autonomous product and engineering work under DEC-008
- **Scope:** synthetic identity, registration, encounter context, check-in, queue, assignment scope, audit, and first patient-context screens

## Context

The platform foundation had users, simulation sessions, contextual assignments, work tasks, and append-only audit events, but no canonical patient or encounter. Clinical modules cannot be added safely until every profession works from one identity and one visit context, status changes have a single transactional path, and the reference application prevents real-data entry.

The current assignment model grants one learner role once per simulation session and narrows that grant to a patient and encounter. Silently creating a second encounter in the same session would leave it without a coherent clinical assignment. The reference model therefore needs an explicit case-run boundary before nursing and medicine work begins.

## Decision

Implement the first outpatient increment as a shared domain spine with these boundaries:

1. A `simulation_session` is one isolated run of one scenario and has one shared outpatient `encounter` in the reference MVP.
2. A second case uses a cloned or new simulation session. This is a working teaching-model assumption, not a permanent institutional decision.
3. Synthetic patients may be searched or retained as duplicate candidates within the session, but the session can create only one appointment-backed encounter.
4. A new identity is verified against the scenario brief; selecting an existing synthetic record requires two synthetic identifiers. The server derives and validates that distinction.
5. Patient and identifier persistence requires explicit synthetic flags. Identifier namespaces must begin with `urn:ueu:simrs-campus:synthetic:` and database checks reject a false synthetic flag.
6. Registration is serialized by a session-scoped cache lock and a database uniqueness constraint. The request ULID remains idempotent for safe retry.
7. Registration creates or reuses the synthetic patient, issues namespaced MRN/NIK-like identifiers when needed, creates the appointment and planned encounter, records the initial transition, narrows clinical assignments to the case, and writes the audit event in one transaction.
8. Check-in locks the appointment, verifies the active registrar context, transitions the encounter to `ARRIVED`, creates the minimal queue entry, completes the registration task, releases the nursing task, and writes audit evidence transactionally.
9. Encounter status changes use `EncounterTransitionService`; direct model status mutation is rejected. Transition history is append-only through the application model.
10. Patient-context access requires an active simulation session, active assignment, enabled capability, and either an exact patient/encounter scope or an explicitly allowed session-wide registration/facilitation scope.
11. The public queue projection is available only for active simulation sessions, is rate-limited and bounded, and returns only ticket, masked cue, and status.
12. Patient-context screens keep the synthetic marker, two identity cues, encounter, location, status, allergy-not-assessed state, and acting role visible.

## Enforced invariants

| Boundary | Enforcement |
|---|---|
| Synthetic patient only | Eloquent saving guard plus database `CHECK` |
| Synthetic identifier namespace | Eloquent saving guard plus generated fixture namespace |
| Simulation encounter only | Eloquent saving guard plus database `CHECK` |
| One encounter per scenario run | session-scoped lock, service validation, and unique database key |
| Identity/session consistency | appointment, encounter, assignment, queue, transition, and work-task model guards |
| Active contextual authorization | controller resolver and domain-service recheck under row lock |
| Declared encounter states | enum transition graph plus transactional service |
| No direct status overwrite | encounter model rejects dirty status outside transition persistence |
| Retry safety | request-key uniqueness and session-scoped lock |
| Minimal public disclosure | dedicated serializer, active-session check, throttle, and result limit |
| Provenance | actor assignment, patient, encounter, reason, server time, and audit correlation |

## Consequences

### Positive

- Nursing, medicine, pharmacy, and RMIK can now attach future records to one stable patient/encounter boundary.
- Browser requests cannot grant themselves a role, case, session, or workflow state.
- Duplicate handling is explicit and never auto-merges records.
- Registration and check-in are safe to retry and do not leave partial queue or task handoffs.
- The encounter page presents recorded history rather than inferring progress from enum order.

### Costs and current limitations

- Multi-case sessions are not supported by this assignment shape. Supporting them later requires a deliberate assignment-case relation and migration rather than relaxing authorization.
- Registration currently captures the minimum identity slice; configured social/demographic extensions remain incomplete.
- Allergy content is deliberately shown as not yet assessed until the nursing increment supplies versioned source data.
- Registrar cancellation is implemented only from coherent `PLANNED` or `ARRIVED` registration states, and overdue no-show is implemented only from `PLANNED`; both preserve completed history and append attributed terminal provenance.
- Queue calling, service start, queue completion, later clinical-stage termination, and later encounter stages remain outside this bounded increment.
- Application model guards do not replace least-privilege database credentials or future tamper-evident audit storage.
- No current code or screen is approved for real-patient care.

## Verification contract

This decision remains viable only while the following pass:

- synthetic flag and namespace rejection tests;
- new/existing identity-verification tests;
- duplicate decision and reason tests;
- registration transaction, idempotency, and one-encounter tests;
- check-in transaction and repeat-request tests;
- wrong-role, inactive-session, revoked-assignment, and exact-case denial tests;
- direct state-mutation and invalid-transition tests;
- public queue disclosure tests;
- patient-context component accessibility test;
- SQLite migration/seed, MySQL migration smoke, static analysis, lint, type checks, and production build.

## Revisit triggers

Create a replacement ADR or approved amendment when:

- Daniel decides that one teaching session must contain multiple simultaneous patient cases;
- Checkpoint 1 finds that the registration/check-in boundary is pedagogically incorrect;
- institutional identity, MRN, consent, or queue-display rules are approved for a future pilot;
- a real external sandbox requires identifier mapping or an appointment/encounter adapter;
- assignment scope moves from one case to a many-case relation; or
- the application is proposed for anything beyond synthetic teaching simulation.

## References

- [ADR-002](ADR-002-PLATFORM-FOUNDATION.md)
- [Outpatient service blueprint](../product/OUTPATIENT_SERVICE_BLUEPRINT.md)
- [Outpatient data dictionary](../product/OUTPATIENT_DATA_DICTIONARY.md)
- [Outpatient role matrix](../product/OUTPATIENT_ROLE_MATRIX.md)
- [Outpatient acceptance scenarios](../product/OUTPATIENT_ACCEPTANCE_SCENARIOS.md)
- [Assumption and validation register](../product/ASSUMPTION_AND_VALIDATION_REGISTER.md)
