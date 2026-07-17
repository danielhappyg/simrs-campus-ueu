# Outpatient Safety Disposition Design

- **Date:** 17 July 2026
- **Status:** Implemented and browser-rehearsed; stakeholder validation pending
- **Scope:** `E2E-02`, `OPD-005`, `OPD-006`, `SAF-04`, and `VAL-A01`–`VAL-A04`
- **Environment:** synthetic campus simulation only

## 1. Page foundation

- **Subject:** a paused outpatient simulation flow after a human-authored nursing safety escalation.
- **Audience:** the exact linked nursing supervisor and the active session facilitator.
- **Page job:** record one attributable human workflow disposition without offering diagnosis, treatment, emergency acuity, or a recommended answer.
- **Signature element:** a four-stage paused-flow rail: `Asesmen awal → ALUR DIHENTIKAN → keputusan manusia → lanjut rutin / transfer simulasi`.

## 2. Product contract

The feature starts only after an approved nursing-intake version has `safetyDecision=ESCALATE_TO_SUPERVISOR`. That approval must:

1. transition the encounter to `ESCALATED`;
2. keep every medical-assessment task `BLOCKED`;
3. create a `SAFETY_DISPOSITION` task for the linked nursing supervisor;
4. create the same task for the active session facilitator when that is a different assignment; and
5. bind task context to the source nursing version public ID and content hash without copying the safety rationale.

The workspace routes are:

```text
GET  /encounters/{encounter}/safety-disposition
POST /encounters/{encounter}/safety-disposition
```

Both routes require authenticated/active/verified simulation access, an active session, `safety-disposition.record`, and exact-case or deliberate session-wide facilitator scope.

## 3. Data contract

`outpatient_safety_dispositions` stores:

```text
public_id ULID unique
request_key ULID unique
encounter_id unique
source_clinical_entry_version_id
source_content_hash sha256
actor_user_id
actor_assignment_id
outcome enum string
rationale text
occurred_at timestamp(6)
created_at / updated_at
```

The record is append-only. It cannot be updated or deleted through Eloquent. Model invariants require matching session/case scope, an approved nursing source, the same stored content hash, an active authorized actor at creation, and a currently escalated encounter.

Outcomes:

| Outcome | Encounter target | Disposition tasks | Medical task |
| --- | --- | --- | --- |
| `RESUME_ROUTINE_FLOW` | `WAITING_CLINICIAN` | actor complete; duplicates cancelled | `READY` |
| `SIMULATED_TRANSFER` | `TRANSFERRED_SIMULATION` | actor complete; duplicates cancelled | `CANCELLED` |

The rationale is required, trimmed, 10–2000 characters, and shown in read-only disposition history after recording. No outcome is preselected.

## 4. Transaction and idempotency

`OutpatientSafetyDispositionService::record()` performs one transaction:

1. find and lock an existing row by request key;
2. return it only when encounter, actor, outcome, and normalized rationale all match;
3. lock encounter, session, active actor assignment, approved source version, and any existing encounter disposition;
4. verify active simulation, `ESCALATED`, source decision/hash, capability, and case scope;
5. create the append-only disposition;
6. transition the encounter with a normalized machine reason;
7. complete/cancel disposition tasks;
8. ready or cancel medical tasks; and
9. write the minimized audit event.

A conflicting request key, existing encounter disposition, non-escalated state, completed session, revoked assignment, stale source, or invalid scope raises a domain error. The surrounding transaction must leave the encounter, tasks, disposition table, transition timeline, and audit stream unchanged.

## 5. Read model

The Inertia page receives:

```text
boundary:
  classification, emergencyTriageClaim=false, clinicalRecommendation=false
encounter:
  publicId, number, status, environmentMode, location
patient:
  publicId, fullName, mrn, birthDate, administrativeSex, synthetic=true
session:
  code, scenarioTitle
source:
  versionPublicId, versionNumber, contentHash, author, approvedAt
  safetyDecision, safetyResponses[], handoffSummary
authorization:
  assignmentPublicId, role, canRecord
disposition:
  publicId, outcome code/label, rationale, actor, role, occurredAt | null
formOptions:
  requestKey, outcomes[]
urls:
  store, encounter, workQueue
```

`canRecord` is true only while the encounter is `ESCALATED`, no disposition exists, and the session/assignment remain active.

## 6. Interface hierarchy

1. permanent orange simulation boundary and explicit non-emergency/non-recommendation language;
2. patient/encounter context banner;
3. paused-flow rail with the current stop stage visually dominant;
4. exact source version/hash stamp and human-authored escalation facts;
5. two unselected outcome cards with operational consequences;
6. required rationale textarea and explicit confirmation button; or
7. a read-only recorded-disposition card with actor/time/provenance.

The page uses established UEU blue/orange tokens, one visible `h1`, semantic fieldset/legend/radio controls, associated error text, visible focus, 44-pixel coarse-pointer targets, and no page-level overflow at 390 CSS pixels.

Copy must include:

- `SIMULASI — DATA SINTETIS`
- `ALUR RUTIN DIHENTIKAN`
- `Bukan triase IGD`
- `Bukan rekomendasi diagnosis atau terapi`
- `Keputusan harus dibuat oleh manusia yang berwenang`

Copy must not include a recommended option, severity score, inferred diagnosis, treatment, emergency instruction, or claim of faculty approval.

## 7. Audit and minimization

Successful recording writes `clinical.outpatient_safety_disposition_recorded` with:

```text
metadata:
  outcome
  source_nursing_version_public_id
  source_nursing_content_hash
  disposition_tasks_completed
  disposition_tasks_cancelled
  medical_tasks_readied
  medical_tasks_cancelled
```

The event's protected `reason` field receives the rationale. Metadata excludes rationale, patient identity, clinical narrative, safety responses, handoff text, internal numeric IDs, and task context.

Workspace viewing may write a separate minimized view event containing only encounter state, source version public ID, and whether a disposition exists.

## 8. Verification contract

Backend tests prove:

- escalation creates authorized disposition tasks and leaves medical work blocked;
- linked supervisor and session facilitator can view; unrelated/wrong-case/revoked users cannot;
- neither outcome is inferred or accepted without a rationale;
- resume and transfer produce their exact encounter/task states;
- same request is idempotent, conflicting/late requests fail;
- source version/hash, actor, outcome, rationale, and time are append-only;
- audit metadata is minimized and rationale stays outside it; and
- a forced downstream failure rolls back every state change.

Frontend tests prove the permanent boundary, paused-flow rail, unselected native radios, required/error associations, read-only recorded state, mobile containment classes, and zero axe violations.

Browser validation uses a fresh escalated synthetic fixture and verifies supervisor and facilitator access, one recorded resume outcome, medical-task release, desktop/mobile layout, keyboard form operation, unique IDs, one main/h1, no horizontal overflow, and clean fresh console output.

## 9. Non-goals

- emergency-department triage or acuity scoring;
- automated clinical alerts, thresholds, diagnosis, treatment, or recommended disposition;
- real transfer/referral communication;
- institution/faculty-approved scenario content;
- SATUSEHAT/FHIR transmission or conformance;
- more than one escalation cycle per encounter;
- merge, deployment, or production data.
