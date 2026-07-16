# ADR-004: Finalized Encounter Debrief Projection

- **Status:** Implemented working reference decision; pending instructor UAT
- **Date:** 2026-07-16
- **Final decision authority:** Daniel Happy Putra, project manager/PIC
- **Implementation authority:** delegated autonomous product and engineering work under DEC-008
- **Scope:** debrief release, contextual access, chronological event projection, and data minimization

## Context

The outpatient journey now records attributable registration, encounter, clinical-version, result, pharmacy, closure, record-quality, correction, and human coding events. Exposing the append-only `audit_events` rows directly would be unsafe and pedagogically poor: those rows also contain request-correlation identifiers, IP hashes, user-agent strings, raw reasons, and implementation metadata. The project charter nevertheless requires learners and instructors to debrief the full multidisciplinary history after finalization.

The reference MVP therefore needs a read model that preserves source provenance without treating a security log as a learner-facing record.

## Decision

1. `FINALIZED` is the deterministic release gate for the reference debrief. Before that state, an otherwise authorized request receives a safe conflict response.
2. Access requires an active, non-revoked assignment with `debrief.view` in the encounter's simulation session. A case-scoped assignment must match both patient and encounter. A deliberately session-wide assignment with the capability may read finalized cases in that session.
3. Debrief remains readable while a session is `ACTIVE` or `COMPLETED`; it cannot be used for learner mutation in either state.
4. Final coding approval releases a debrief work item to every currently eligible participant/instructor assignment. Opening the workspace completes only that personal work item; it never changes encounter or source-record state.
5. The learner-facing timeline is an explicit allowlist of material workflow actions. Page views, searches, authorization denials, request/security activity, and unknown actions are excluded.
6. Projection fields are limited to a curated title/detail, stage, actor name, program, role, public assignment/source references, source version, event outcome, and relevant times/tags.
7. Raw audit reason, metadata payload, content hashes, IP hash, user agent, request-correlation identifier, database identifiers, and raw action code are not sent to the page.
8. Default order uses clinical occurrence time when a supported source record provides it, then recorded time and event ULID for deterministic ties. Recorded time is shown separately when it differs from clinical occurrence by at least one minute.
9. Filters alter presentation and URL state only. They do not reorder, edit, or imply deletion of source events.
10. The first projection is bounded to the latest 300 material events. A visible truncation warning reports both displayed and available counts; the reference fixture must remain below this boundary.
11. Debrief is permanently labelled `SIMULASI — DATA SINTETIS`. It is learning evidence, not a legal record, clinical attestation, or production audit console.
12. Authored debrief notes and non-scoring rubric references are governed by ADR-005 as a separate teaching-evidence layer. Rubric scoring and exports remain separate future increments so they cannot silently become clinical-source content or unapproved surveillance scoring.

## Enforced invariants

| Boundary                        | Enforcement                                                                     |
| ------------------------------- | ------------------------------------------------------------------------------- |
| Release only after finalization | Controller state gate after contextual authorization                            |
| Same-session/case access        | Dedicated assignment resolver and direct feature-denial tests                   |
| Read after session completion   | Debrief-specific session-state policy                                           |
| No raw audit disclosure         | Allowlisted server-side projection and response-content tests                   |
| Deterministic chronology        | Clinical/recorded-time projection with stable tie-breakers                      |
| Immutable source history        | Projection reads append-only audit/source versions; no source mutation endpoint |
| Discoverable final stage        | Final coding approval releases capability-checked work tasks                    |
| Simulation-only representation  | Middleware, patient banner, release copy, and synthetic fixture boundary        |

## Consequences

### Positive

- Learners can trace the same patient and encounter across all participating professions.
- Instructors can discuss handoffs, corrections, exact-version supervision, and the boundary between coding candidates and human decisions.
- Security/operations metadata stays in the audit store instead of leaking into the teaching UI.
- A completed class session does not make its finalized learning evidence disappear.

### Costs and current limitations

- Events not in the material-action allowlist are intentionally absent until product review adds a safe presentation.
- The 300-event boundary requires pagination/export design if a future case becomes substantially more complex.
- Clinical occurrence enrichment currently covers clinical-entry versions, encounter-closure versions, diagnostic results, and medication-dispense records. Other actions use recorded time.
- Shared versioned debrief notes and non-scoring rubric references are implemented under ADR-005. ADR-006 adds source-derived HTML/print working references; rubric criteria/scoring, signed documents, managed disclosures, and final export scope are not implemented.
- Assignment expiry still ends access even when the session is complete; longer archive access requires an explicit retention/access decision.
- Instructor UAT must confirm usefulness, terminology, and whether the one-minute recorded-time threshold is appropriate.

## Verification contract

This decision remains viable only while the following pass:

- correct-role/correct-case access after `FINALIZED`;
- wrong-role, wrong-session, and wrong-case direct-route denials;
- authorized pre-finalization conflict response;
- completed-session read access;
- curated event allowlist and raw-metadata non-disclosure assertions;
- deterministic clinical/recorded-time chronology and version attribution;
- personal debrief task completion without encounter mutation;
- React filter/URL-state and automated axe coverage;
- full PHP, static-analysis, formatting, TypeScript, frontend, build, migration, and security checks.

## Revisit triggers

Create an approved amendment or replacement when:

- Daniel or instructor UAT changes the release policy;
- a session needs several simultaneous cases or archive access beyond assignment expiry;
- debrief-note visibility/versioning changes, rubric scoring, or exports are added beyond ADR-005;
- the event volume requires pagination;
- minimum-necessary content differs by participant role; or
- the product is proposed for any non-synthetic or clinical use.

## References

- [Project Charter](../PROJECT_CHARTER.md)
- [Outpatient Service Blueprint](../product/OUTPATIENT_SERVICE_BLUEPRINT.md)
- [Outpatient Role Matrix](../product/OUTPATIENT_ROLE_MATRIX.md)
- [Outpatient Acceptance Scenarios](../product/OUTPATIENT_ACCEPTANCE_SCENARIOS.md)
- [Information Architecture](../design/INFORMATION_ARCHITECTURE.md)
- [Interaction Specifications](../design/OUTPATIENT_INTERACTION_SPECIFICATIONS.md)
- [Assumption and Validation Register](../product/ASSUMPTION_AND_VALIDATION_REGISTER.md)
- [ADR-005: Shared Debrief Notes and Non-Scoring Rubric References](ADR-005-DEBRIEF-NOTES-AND-RUBRIC-REFERENCES.md)
