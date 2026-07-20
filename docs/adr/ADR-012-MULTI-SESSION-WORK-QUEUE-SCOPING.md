# ADR-012: Fail-Closed Multi-Session Work-Queue Scoping

- **Status:** Accepted and implemented at `0b8ba4a`; complete local and feature-head CI gates passed, PR remains draft/unmerged
- **Date:** 2026-07-20
- **Final decision authority:** Daniel Happy Putra, project manager/PIC
- **Implementation authority:** delegated autonomous product and engineering work under DEC-008

## Context

The disposable-reference clone can create several active sessions while retaining the same ten synthetic demo identities. The prior work queue loaded every active assignment and task for the signed-in user, then labelled the entire page with only the first assignment's session. Two cloned sessions could therefore expose correctly authorized tasks under an incorrect visible session context. This is a workflow-integrity risk even though it does not cross the user's authorization boundary.

## Decision

The work queue is scoped to exactly one active, assigned simulation session:

1. one available active session is selected automatically;
2. more than one available active session requires an explicit selection before any task is returned or actionable;
3. the selected session is URL-addressable with `?session={code}` so a facilitator can distribute an exact UAT entry path;
4. a supplied selector must match an active session assigned to the current user, using an exact bounded code; invalid or unavailable selectors return `404` without revealing whether another session exists;
5. inactive-session assignments are excluded from the available session list;
6. task, summary, encounter-orbit, program, role, and capability presentation are derived only from assignments in the selected session;
7. every task row repeats its session code as provenance; and
8. successful views and denied selections create minimized audit evidence without recording the rejected selector or request fingerprint.

The selector does not change authorization. Every task destination continues to enforce its own assignment, capability, encounter, patient, version, and state policies.

## Options considered

### A. Keep one combined queue and add session labels

Rejected because a combined queue still permits accidental cross-session task selection and leaves the page-level scenario/program context ambiguous.

### B. Default to the earliest or newest active session

Rejected because ordering is not an informed user choice and can silently send a participant into a different disposable run.

### C. Require one explicit session when several are active

Selected because it fails closed, supports exact UAT links, and preserves the existing one-session path without an extra step.

## Consequences

- Multiple disposable sessions can coexist without mixing their task lists in one visible queue.
- Participants receive one additional selection step only when their account has assignments in several active sessions.
- Facilitators must record and distribute the exact session code for each UAT branch.
- This does not decide future multi-case-within-one-session behavior, account lifecycle, retention, class size, or combined-role policy.
- Browser rehearsal and stakeholder validation remain required; automated coverage is not Checkpoint 2 acceptance.

## Verification required

- single-session automatic selection;
- multi-session no-selection response with zero tasks and a selection-required state;
- authorized selection scopes tasks, summaries, and visible context;
- invalid and unassigned selectors fail closed with minimized audit evidence;
- inactive sessions are unavailable;
- native keyboard-operable selector, explicit empty state, repeated task provenance, and automated accessibility check;
- complete SQLite/MySQL, static, frontend, documentation, build, and non-deploying release gates.

## Related records

- [ADR-011: Disposable Reference Session Cloning](ADR-011-DISPOSABLE-REFERENCE-SESSION-CLONING.md)
- [Outpatient Interaction Specifications](../design/OUTPATIENT_INTERACTION_SPECIFICATIONS.md)
- [Outpatient Acceptance Scenarios](../product/OUTPATIENT_ACCEPTANCE_SCENARIOS.md)
- [Outpatient Checkpoint 2 UAT Guide](../operations/OUTPATIENT_CHECKPOINT_2_UAT_GUIDE.md)
