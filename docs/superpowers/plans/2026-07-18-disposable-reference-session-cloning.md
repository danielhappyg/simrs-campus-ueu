# Disposable Reference Session Cloning Plan

- **Date:** 2026-07-18
- **Scope:** operational preparation for isolated synthetic outpatient UAT branches
- **Non-goals:** scenario authoring, copying progressed clinical state, database reset, password management, stakeholder acceptance, deployment, or merge

## Contract

`simulation:clone-reference-session {code} --source=SIM-RJ-UEU-001 --duration=480 --appointment-offset=15` creates one new active session from a pristine synthetic reference source. The appointment offset is bounded from -1,440 minutes through the clone duration; a small negative offset prepares an already-due no-show branch without direct database editing. The command emits a minimized human or JSON summary, never patient names or identifier values. Any unsafe environment, invalid code/duration/offset, existing target, progressed/incomplete source, cross-session reference, unsupported task/identifier, or late write failure leaves no target session.

## Tasks

1. [x] Add a transaction-bound `ReferenceSessionCloneService` with explicit environment, source-graph, assignment, task, and stock invariants.
2. [x] Generate new opaque/public identifiers and remap all target references without copying progressed state.
3. [x] Record `source_session_id`, a new initial transition, and minimized append-only clone audit metadata.
4. [x] Add an Artisan command with bounded arguments, safe failure messages, and patient-identity-free JSON output.
5. [x] Add success, denial, source-immutability, remapping, rollback, output-minimization, and overdue no-show preparation tests.
6. [x] Update the UAT entry gate/run preparation, data dictionary, traceability/validation evidence, runbook, ADR, and README.
7. [x] Run complete local/CI-equivalent gates for implementation head `3cf3d4d`, push only to the existing private draft PR, and verify artifact `8428547776` remains `NOT_DEPLOYED`.

## Acceptance evidence

- A clone contains one session, patient, appointment, planned encounter, initial transition, ten remapped assignments, four initial tasks, and untouched synthetic stock.
- Learner supervisor links point only to target assignments; every case-scoped assignment/task points only to the target patient/encounter.
- Source counts, statuses, identifiers, tasks, stock, and timestamps remain unchanged.
- No clinical/progressed tables receive target rows.
- Invalid and late-failure paths create zero target records.
- Human/JSON command output contains no patient name, MRN, or NIK-like value.
