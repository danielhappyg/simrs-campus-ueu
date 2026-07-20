# Multi-Session Work-Queue Safety Plan

- **Date:** 2026-07-20
- **Scope:** prevent task/context mixing when one synthetic demo identity has several active disposable-session assignments
- **Non-goals:** new clinical modules, multi-case session design, session lifecycle/retention, account provisioning, deployment, merge, or stakeholder acceptance

## Contract

`GET /work` automatically selects the only active assigned session. If several active assigned sessions exist, it returns no tasks until the user selects one. `GET /work?session={code}` returns tasks and summaries only for the exact active session assigned to that user. Invalid or unavailable selectors fail as `404` and the audit trail never stores the rejected value.

## Tasks

1. [x] Filter available assignments to active sessions and derive one authoritative selected-session context.
2. [x] Fail closed with zero tasks when several sessions exist and none is selected.
3. [x] Reject malformed or unavailable selectors without existence disclosure or selector echo in audit metadata.
4. [x] Add an accessible native session selector, explicit selection-required state, selected assignment aggregation, and task-level session provenance.
5. [x] Add focused backend, frontend interaction, and axe coverage.
6. [x] Update the interaction, acceptance, traceability, UAT, validation, and architecture records.
7. [x] Run complete local and CI-equivalent gates for implementation head `0b8ba4a`, push only to the existing private draft PR, and verify artifact `8464205479` remains `NOT_DEPLOYED`.

## Acceptance evidence

- one active session remains a one-step queue experience;
- two active sessions without a selector return both non-clinical assignment choices but zero tasks;
- an authorized selector returns only that session's task, summary, and context;
- another user's, inactive, malformed, or unknown session cannot be selected;
- visible task/session provenance remains explicit;
- audit evidence records counts and selected public ID only on success, and a reason category/count only on denial.
