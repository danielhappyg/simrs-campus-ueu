# ADR-011: Disposable Reference Session Cloning

- **Status:** Accepted and implemented; full local gates passed, CI evidence pending
- **Date:** 2026-07-18
- **Final decision authority:** Daniel Happy Putra, project manager/PIC
- **Implementation authority:** delegated autonomous product and engineering work under DEC-008

## Context

Checkpoint 2 requires one main outpatient journey plus isolated cancellation, no-show, escalation, early-departure, and correction branches. The facilitator guide forbids using `migrate:fresh` to repair shared or retained data and requires participants to act without direct database edits. The repository currently supplies one opt-in `SIM-RJ-UEU-001` reference session. Reusing a progressed run would mix provenance; destructive reseeding would erase evidence; copying a progressed clinical graph would reproduce state that the new rehearsal did not earn.

The teaching model already carries `simulation_sessions.source_session_id`, but no application service uses that provenance seam.

## Decision

Implement a command-backed domain service that creates a fresh active reference run by deep-copying only a **pristine, synthetic, planned** source graph.

The clone transaction will:

1. run only outside production when `APP_MODE=SIMULATION`, `APP_SYNTHETIC_ONLY=true`, and the opt-in demo-fixture boundary is enabled;
2. require a new unique session code, a bounded explicit duration, and a bounded appointment offset; the default appointment is 15 minutes after session start, while an explicit negative offset prepares an already-due no-show branch without direct database editing;
3. accept only the published `OPD-REF-001` v1 fixture with one synthetic patient, one booked appointment, one planned encounter, the initial transition, the expected active role assignments/supervision graph, the four initial work tasks, and untouched synthetic stock;
4. reject checked-in, progressed, terminal, non-synthetic, revoked, incomplete, ambiguous, or partially cloned sources;
5. create new public IDs, request keys, MRN/NIK-like values, appointment code, encounter number, task IDs, stock-lot IDs, and timestamps;
6. preserve user/role/capability semantics while remapping every patient, encounter, assignment, supervisor, task, and registration reference to the new session;
7. set `source_session_id` to the immediate source and record minimized clone provenance in the append-only audit trail;
8. copy no clinical entry, result, prescription, review, dispense, closure, coding, debrief, early-departure, queue, or other progressed state; and
9. roll back the entire target graph if any invariant or write fails.

The command is a preparation tool, not scenario authoring, reset, deletion, acceptance evidence, deployment, or authorization to reuse passwords. A clone remains synthetic and must be operated through the same application policies as any other session.

## Options considered

### A. Destructive reseed for every branch

| Dimension | Assessment |
| --- | --- |
| Complexity | Low |
| Evidence retention | Unacceptable on shared/retained data |
| Operator safety | High risk of targeting the wrong database |
| UAT usability | Requires developer intervention |

Rejected because it conflicts with the UAT guide and can destroy prior evidence.

### B. Clone only the pristine reference graph

| Dimension | Assessment |
| --- | --- |
| Complexity | Moderate |
| Evidence retention | Strong; every run is separate |
| Operator safety | Fail-closed and transaction-bound |
| UAT usability | One explicit command per branch |

Selected because it uses the existing domain model and provenance field without copying earned clinical state.

### C. Copy any current session and reset selected tables

| Dimension | Assessment |
| --- | --- |
| Complexity | High and grows with every module |
| Evidence retention | Ambiguous |
| Operator safety | High risk of stale IDs and leaked progressed state |
| UAT usability | Superficially convenient but hard to verify |

Rejected because a deny-list reset is not a reliable proof that no progressed state survived.

## Consequences

- Facilitators can prepare multiple isolated branches before a rehearsal without editing the database or deleting retained runs.
- The source must remain pristine until all desired clones are prepared; a pristine clone may itself serve as a later source.
- Session duration is an operational command input, not an accepted class-length policy; `VAL-T05` remains open.
- Demo accounts remain shared development identities. Password distribution, rotation, and combined-role acceptance remain external UAT responsibilities.
- This advances staged-validation readiness but does not constitute Checkpoint 2 acceptance, a hosted deployment, or OPS-02 evidence.

## Verification required

- success-path graph and remapping assertions;
- new identifiers and no cross-session foreign keys;
- exact initial task/status and stock reset evidence;
- source immutability and clone provenance;
- refusal of unsafe environment, invalid/duplicate target, progressed source, and malformed source graph;
- transaction rollback on a late failure;
- complete SQLite and MySQL suites plus static, documentation, build, and release-candidate gates.

## Related records

- [Project Charter](../PROJECT_CHARTER.md)
- [Outpatient Checkpoint 2 UAT Guide](../operations/OUTPATIENT_CHECKPOINT_2_UAT_GUIDE.md)
- [Outpatient Data Dictionary](../product/OUTPATIENT_DATA_DICTIONARY.md)
- [Assumption and Validation Register](../product/ASSUMPTION_AND_VALIDATION_REGISTER.md)
