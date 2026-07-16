# ADR-005: Shared Debrief Notes and Non-Scoring Rubric References

- **Status:** Implemented and narrow-browser validated; pending instructor and program UAT
- **Date:** 2026-07-16
- **Final decision authority:** Daniel Happy Putra, project manager/PIC
- **Implementation authority:** delegated autonomous product and engineering work under DEC-008
- **Scope:** finalized-case debrief notes, note revision history, rubric references, contextual authorization, and learner-visible boundaries

## Context

ADR-004 releases a curated, minimum-necessary event projection only after an encounter reaches `FINALIZED`. The working page already exposes learning outcomes and guided questions, but it cannot yet preserve a facilitator's synthesis or show which rubric draft a future review should use.

The 2025 INACSL Healthcare Simulation Standards state that simulation activities require a planned debriefing process that can include feedback, debriefing, or guided reflection. The same standards separate debriefing from evaluation of learning and performance. The WHO competency framework likewise treats measurable outcomes and assessment approaches as curriculum decisions rather than conclusions that software should infer. These references support an attributable teaching-record layer, but they do not define UEU's rubric, pass threshold, or program-specific competence rules.

The design must therefore add useful instructor authorship without turning the event timeline into a clinical record, a private surveillance notebook, or an unapproved grading engine.

## Requirements and constraints

### Functional

- An explicitly authorized facilitator can create a shared debrief note after finalization.
- A later correction creates a successor version and requires a change reason; previous versions remain readable.
- All debrief-authorized participants in the same case can read the latest note and its revision provenance.
- The page shows scenario-versioned rubric references and their validation state.
- The reference fixture demonstrates the model with one clearly marked draft rubric reference.

### Non-functional

- Synthetic-only and permanent simulation labelling remain mandatory.
- Writes are transactional, idempotent, attributable, auditable, and server-authorized.
- Note content is plain text, bounded to 4,000 characters, and never copied into clinical-source records.
- The design must remain practical on the selected Laravel/MySQL-compatible modular monolith and shared-hosting target.
- The read path should remain a small bounded query for a classroom case; no queue or cache is required.

## Decision

1. Debrief notes are **teaching evidence**, not clinical documentation, legal attestation, or learner grades.
2. Note reads reuse the ADR-004 `debrief.view` case/session boundary and remain available while a simulation session is `ACTIVE` or `COMPLETED`.
3. Note writes require a separate `debrief.write` capability, a `FINALIZED` encounter, an `ACTIVE` simulation session, and a matching case assignment or deliberately session-wide facilitator assignment.
4. The reference profile grants `debrief.write` only to the named facilitator. Supervisors or other instructors can receive it later only through an explicit assignment decision.
5. Every note is shared with all participants who can open that encounter's debrief. The first increment has no private-facilitator visibility, hidden learner annotation, or selective disclosure.
6. A logical `debrief_note` owns an append-only sequence of `debrief_note_version` rows. Version 1 creates the note. Revision creates the next version under a row lock and requires a non-empty change reason.
7. Version content and provenance are immutable. Ordinary application endpoints cannot update or delete a version.
8. Supported note types are `FACILITATOR_SYNTHESIS`, `GUIDED_REFLECTION`, and `FOLLOW_UP_ACTION`. Type is fixed on the logical note; only body text changes in a successor version.
9. Create and revise requests carry ULID request keys. Repeating the same authorized request returns the existing result without creating another note/version.
10. Scenario `rubric_references` are versioned configuration metadata containing code, title, reference version, validation status, source label, and linked learning-outcome numbers. They contain no criteria score, weight, grade, pass/fail rule, or automated judgment.
11. The reference rubric state is `PENDING_PROGRAM_REVIEW`. The page must explicitly say that it is non-scoring and cannot be used as an approved grade.
12. Notes appear in a dedicated debrief section, separate from the immutable source-event timeline. Audit events record create/revise actions but are not used as the note content store.
13. ADR-006 adds an on-demand HTML/print debrief-evidence projection that preserves simulation labelling, note versions, rubric validation state, and the same contextual access boundary. It is not a signed or managed export artifact.

## Component and data flow

```text
Finalized encounter + active facilitator assignment
        |
        v
POST note/revision endpoint
        |
        v
DebriefNoteService
  - lock encounter/session/lineage
  - recheck debrief.write + case scope
  - enforce request-key idempotency
  - append immutable version
  - write minimized audit event
        |
        v
EncounterDebriefController read model
  - curated source timeline (ADR-004)
  - shared note lineages/latest versions
  - scenario rubric references
        |
        v
Facilitator authoring / participant read-only debrief UI
```

## Storage contract

### `debrief_notes`

- public ULID;
- encounter and creator-assignment foreign keys;
- create request key, unique within the encounter;
- immutable note type; and
- created timestamp.

### `debrief_note_versions`

- public ULID;
- note and author-assignment foreign keys;
- revision request key, unique within the note;
- monotonically increasing version number;
- plain-text body;
- required change reason from version 2 onward;
- content SHA-256; and
- authored timestamp.

### `simulation_scenarios.rubric_references`

- nullable JSON configuration bound to the existing scenario version;
- each item contains only reference metadata and learning-outcome links; and
- validation status must be displayed, never inferred.

## HTTP contract

| Endpoint                                     | Success                                                                                                    | Safe conflict/denial behavior                                                                                               |
| -------------------------------------------- | ---------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------- |
| `POST /encounters/{encounter}/debrief/notes` | Create version 1 or return the idempotent prior result, then redirect to debrief                           | `403` wrong capability/context; `409` not finalized or session not active; `422` invalid/oversized/unsafe attestation input |
| `POST /debrief-notes/{note}/versions`        | Append the next version or return the idempotent prior result, then redirect to the same encounter debrief | `403` wrong capability/context; `409` stale lineage/session state; `422` missing change reason or invalid body              |

## Interface contract

- Every viewer sees a `Catatan debrief bersama` section with latest versions and explicit author/time/version provenance.
- Earlier versions remain available in a compact revision history when a note has changed.
- Only `canAuthorNotes=true` renders the create/revise forms; hiding the form is not the authorization control.
- Forms require the simulation-teaching attestation and explain that notes are shared, non-clinical, and non-scoring.
- `Referensi rubrik` shows code/version/status and linked learning outcomes. Draft/pending references use warning styling and plain-language limitations.
- Empty states distinguish `belum ada catatan` from access denial or loading failure.

## Error handling and reliability

- The service rechecks authorization and encounter/session state inside the transaction to prevent time-of-check/time-of-use drift.
- Unique request-key and lineage constraints protect against double submit.
- Content hashes make accidental mutation detectable; model guards reject update/delete of versions.
- A failed audit write rolls back the note transaction when recorded inside the same transaction.
- No background job is needed at reference-MVP volume. If one case later has hundreds of notes, pagination becomes mandatory.

## Trade-offs

### Benefits

- Facilitators can preserve a transparent synthesis tied to the same case learners experienced.
- Learners see exactly what was added, by whom, and whether it was revised.
- Rubric readiness becomes visible without pretending an unapproved rubric or score exists.
- Separate capability and storage boundaries reduce accidental clinical-record or surveillance overreach.

### Costs and limitations

- Shared-only notes cannot support private facilitator preparation; that omission is intentional for the first safety boundary.
- Plain text avoids unsafe rendering and keeps authorship clear but does not support rich formatting or event anchoring.
- Scenario JSON is sufficient for a small set of rubric references but should become a dedicated versioned rubric module if criteria authoring or reuse grows.
- No scoring, gradebook integration, note moderation, export, or retention lifecycle is included.

## Verification contract

- create and revision idempotency;
- finalized and active-session write gates;
- correct facilitator/context allow plus learner, wrong-session, wrong-case, and completed-session write denials;
- immutable prior versions, hashes, and required change reason;
- participant read access with no authoring controls;
- rubric pending/non-scoring language and provenance;
- minimized audit events with no full note body;
- React accessibility coverage and narrow-screen review; and
- full backend, static-analysis, formatting, TypeScript, frontend, build, migration, and recovery checks.

## Revisit triggers

Create an amendment or replacement when:

- Daniel approves a UEU rubric, weighting, grading, or pass/fail policy;
- a program requires private notes, learner-authored reflection, or role-specific visibility;
- notes must anchor to exact timeline events;
- exports, LMS/gradebook integration, moderation, retention, or legal hold are added;
- note volume requires pagination/search; or
- the platform is proposed for non-synthetic or real-care use.

## References

- [INACSL Healthcare Simulation Standards of Best Practice, revised 2025](https://www.inacsl.org/healthcare-simulation-standards)
- [WHO Global Competency and Outcomes Framework for Universal Health Coverage](https://www.who.int/publications/i/item/9789240034662)
- [ADR-004: Finalized Encounter Debrief Projection](ADR-004-FINALIZED-DEBRIEF-PROJECTION.md)
- [ADR-006: Finalized Simulation Reporting Projection](ADR-006-FINALIZED-SIMULATION-REPORTING.md)
- [Outpatient Acceptance Scenarios](../product/OUTPATIENT_ACCEPTANCE_SCENARIOS.md)
- [Outpatient Role Matrix](../product/OUTPATIENT_ROLE_MATRIX.md)
- [Assumption and Validation Register](../product/ASSUMPTION_AND_VALIDATION_REGISTER.md)
