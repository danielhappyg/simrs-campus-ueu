# Outpatient Longitudinal Record Design

- **Date:** 17 July 2026
- **Status:** Approved autonomous reference-build increment
- **Scope:** `E2E-11`, `DOC-01`, `EMR-001`, and `EMR-003`
- **Environment:** synthetic campus simulation only

## 1. Problem

The application already creates attributable, immutable clinical versions and a curated material-event projection. However, `/encounters/{encounter}/timeline` currently renders the finalized teaching debrief, while the information architecture separately defines an encounter-level `Linimasa Rekam` and `/debrief` destination. During an active encounter, an assigned participant can inspect individual workspaces but cannot open one read-only chronological index of the shared record.

This conflation leaves `E2E-11` incomplete as a distinct workflow. The debrief is teaching evidence released after finalization; the longitudinal record is a minimum-necessary clinical provenance view that should remain available throughout the assigned case and after the session is completed.

## 2. Evidence and product response

The project evidence register maps Permenkes 24/2022 Articles 16–17 to complete, clear, chronological, attributable clinical information and one integrated multi-professional record. The Kementerian Kesehatan JDIH still listed Permenkes 24/2022 as `Berlaku` when rechecked on 17 July 2026. SATUSEHAT's current `Encounter` guidance represents one outpatient service sequence as one encounter, while its `Composition` guidance binds the subject, encounter, author, sections, and source entries.

The reference-build response is not a legal electronic medical record, a new stored Composition, or a SATUSEHAT submission. It is a deterministic read model over the application's existing source/version and material-event provenance. It never invents clinical content and never replaces the underlying approved source.

## 3. Decisions

1. `/encounters/{encounter}/timeline` becomes the active longitudinal-record route and renders `encounter/timeline`.
2. `/encounters/{encounter}/debrief` remains the finalized teaching-evidence route and renders `encounter/debrief`.
3. The longitudinal record is readable for an active or completed simulation session by:
   - an active exact patient/encounter assignment with `session.view`; or
   - a session-wide facilitator assignment with `session.view` and `session.facilitate`.
4. System-configuration privilege alone, a wrong-session assignment, a wrong-case assignment, or a missing `session.view` capability does not grant access.
5. The encounter does not need to be finalized. Planned, active, cancelled, no-show, record-review, and finalized encounters may be read within the same contextual policy.
6. The existing material-event projection remains the canonical event builder. It is shared with debrief and finalized reports so ordering and source labels cannot drift.
7. `appointment.terminated` joins the material allowlist. The event exposes outcome and source provenance but not the registrar's free-text reason or raw audit metadata.
8. Ordering is clinical occurrence time where a source occurrence exists, then recorded time, then stable event ID. A materially different recorded time remains visible.
9. The UI provides presentation-only category and program filters. Filtering never changes server truth, source order, or persistence.
10. Every event shows category, title, actor, program/role, primary time, source label/public ID/version, and recorded-time distinction where applicable.
11. The page states that it is an index of source provenance, not a copied clinical summary, legal record, autonomous verdict, or external transmission.
12. Opening the page records `record.timeline_viewed` with only displayed count, total count, and truncation state. It does not audit the rendered event payload.
13. The projection remains capped at 300 material events and reports truncation honestly.
14. The encounter overview gains a persistent `Buka linimasa rekam` action. The finalized debrief links back to the clinical timeline, keeping the destinations visibly distinct.

## 4. Data contract

The controller returns:

```text
encounter: publicId, number, status, serviceType, location, periodStart, environmentMode
patient: publicId, fullName, MRN, birthDate, administrativeSex, allergyStatus, synthetic
assignment: publicId, program, role
session: publicId, code, status, scenarioTitle
release: current encounter state and whether the session is completed/read-only
events[]:
  publicId, sequence, category, title, detail
  actor: name, program, role, assignmentPublicId
  source: label, publicId, version
  recordedAt, clinicalOccurrenceAt, primaryAt, showsRecordedTimeDifference
  outcome, tags[]
summary: displayedEventCount, totalAvailableEventCount, truncated, categoryCounts,
         programCounts, correctionCount, supervisionCount, handoffCount
urls: encounter, self, debrief?, workQueue
```

No event payload contains raw audit reasons, correlation IDs, IP hashes, user agents, request metadata, content hashes, diagnosis free text, or note bodies.

## 5. UI structure

The page uses the existing UEU clinical visual language and permanent simulation banner:

1. page header with `Linimasa Rekam Rawat Jalan` and navigation;
2. persistent patient/encounter context banner;
3. boundary callout explaining source provenance and read-only behavior;
4. summary cards for material events, corrections, supervision, and handoffs;
5. category and program filters with a live announced result count;
6. ordered event list with clinical/recorded time, source/version, actor/role, and non-color-only tags;
7. empty-filter and empty-record states;
8. explicit truncation notice when more than 300 material events exist.

The page owns exactly one `main` through the shared authenticated layout, one visible `h1`, logical native controls, visible focus, 44-pixel coarse-pointer targets, and no page-level horizontal overflow at 390 CSS pixels.

## 6. Failure and safety behavior

- unauthenticated requests remain behind the existing authentication middleware;
- inactive account and non-simulation requests remain behind existing middleware;
- contextual authorization failures return 403 without record payload;
- unsupported session state returns 403 through the resolver;
- an empty material history renders honestly instead of fabricating workflow stages;
- unknown audit actions never appear because the projection is allowlisted;
- event reasons remain excluded even when they exist in the audit table;
- the timeline has no mutation endpoint and no clinical authoring controls.

## 7. Verification

Backend feature tests must prove:

- active exact-case access before finalization;
- completed-session access;
- session-wide facilitator access;
- wrong session, wrong case, missing capability, and administrator denial;
- deterministic ordering and exact source/version/actor/time attribution;
- cancellation/no-show material-event projection without free-text reason disclosure;
- payload minimization, 300-event truncation, and minimized view-audit metadata;
- `/debrief` remains finalized-gated and `/timeline` is no longer an alias.

Frontend tests must prove:

- permanent simulation and read-only/source-boundary language;
- category/program filtering and announced count;
- source/version/actor/clinical and recorded times remain visible;
- no raw audit internals are rendered;
- empty and truncated states;
- no serious or critical automated accessibility violation.

Browser validation uses a fresh disposable synthetic SQLite fixture. It exercises the record before finalization and again after the reference journey is finalized, verifies navigation separation from debrief, retains one `main` and one `h1`, checks 390×844 containment and duplicate IDs, and records browser warning/error output. Native sequential keyboard traversal and native print/PDF remain separate manual validation items unless explicitly performed.

## 8. Non-goals

- cross-patient longitudinal history;
- a stored clinical summary or Composition;
- FHIR generation or transmission;
- legal signature, certified PDF, disclosure, or retention workflow;
- free-text clinical document rendering inside event cards;
- audit/security console access;
- grading, competence scoring, or autonomous clinical interpretation;
- new clinical-stage cancellation policy;
- stakeholder acceptance, merge, or deployment.

