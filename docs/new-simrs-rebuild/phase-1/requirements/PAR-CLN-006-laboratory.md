# Parity Requirement: PAR-CLN-006 Laboratory (teaching partial)

## Control information

- Legacy menu/category: Pemeriksaan / Laboratorium
- Disposition: **Reproduce (partial)**
- Parity status: **Specified; not accepted**
- Business owner: Clinical/Laboratory owner **TBD** (interim product owner Daniel); RMIK Department consulted for closure
- Affected actors: physician/learner ordering, nurse/lab learner entering result, RMIK reviewing closure, supervisors
- Evidence: menu and CAP-CLN-006 observed; hosted teaching UAT Run 3 demonstrates synthetic RJ lab order → FINAL result → `COMPLETED`
- Evidence limitation: closure, late-result, preliminary, amendment, reopen and cancellation behavior are **Unknown** in SIMRS Sahabat
- Target milestone: Phase 3 outpatient teaching slice

## Business outcome

An authorized learner can place a synthetic outpatient lab order and an authorized lab actor can record one attributable final teaching result. RM cannot close the encounter while an active lab order is unresolved.

This lifecycle rule is **Proposed NEW teaching safety**, not a claim of observed SAHABAT behavior. See [`../OUTPATIENT_ORDER_RESULT_CLOSURE_CONTRACT.md`](../OUTPATIENT_ORDER_RESULT_CLOSURE_CONTRACT.md).

## Preconditions and master data

- Encounter and patient are synthetic and belong to the outpatient teaching graph.
- Encounter is not `CLOSED`.
- Actor is authorized server-side for the requested action.
- The bounded teaching lab catalogue supplies the test code and label; it is not a LIS catalogue.

## Workflow and state transitions

1. Physician/authorized learner creates a lab order on an open RJ encounter.
2. Order enters `ACTIVE` and appears on the Laboratory worklist.
3. Nurse/lab-authorized learner records one `FINAL` synthetic result while the encounter is open.
4. Result creation and order transition to `COMPLETED` occur atomically.
5. The completed result is visible on the encounter and is immutable.
6. RM closure is allowed only when no `ACTIVE` lab order remains.

```text
ACTIVE --(one FINAL result)--> COMPLETED
```

No other transition is approved in this slice.

## Business rules and validation

| Rule ID | Rule | Error behavior | Evidence/source |
|---|---|---|---|
| BR-LAB-001 | Every order and result is bound to the same synthetic patient encounter | Reject orphan/cross-encounter writes | Proposed NEW / architecture |
| BR-LAB-002 | A result may be entered only while its encounter is not `CLOSED` | Reject without mutation; reason `encounter_closed` | Proposed NEW / teaching safety |
| BR-LAB-003 | A result may be entered only for an `ACTIVE` order | Reject without mutation; reason `order_not_active` | Proposed NEW / teaching safety |
| BR-LAB-004 | This slice accepts `FINAL` only | Reject preliminary or any unapproved status | Proposed NEW / teaching safety |
| BR-LAB-005 | One final result completes the order and is immutable | Reject duplicate; reason `result_already_final` | Proposed NEW / teaching safety |
| BR-LAB-006 | An `ACTIVE` lab order blocks RM closure | Keep encounter open; reason `active_lab_orders` | Proposed NEW / teaching safety |
| BR-LAB-007 | Authoritative state is rechecked transactionally using encounter-first locking | Roll back the state change if validation or audit fails | Proposed NEW / concurrency safety |
| BR-LAB-008 | Exact specimen, reference range, verification and release rules | Not implemented; blocks full parity acceptance | **Unknown** |

## Authorization and audit

- `clinical.order.create`: create a lab order.
- `clinical.lab.result.write`: create the one final result.
- `rmik.review`: access the RMIK worklist.
- `rmik.completeness.signoff`: close only after active-order clearance.
- Authorization is evaluated before business-state disclosure.
- Audit records successful order/result writes and attributable business denials. Shared wrong-role denials remain under the global authorization audit.

## Exceptions and corrections

Preliminary results, amendments/corrections, encounter reopening and order cancellation are not built. Final results cannot be overwritten or edited. These require Clinical/Laboratory and RMIK owner approval plus a separate attributable workflow.

## Acceptance criteria (synthetic)

1. Authorized physician creates an `ACTIVE` order for the correct open encounter.
2. Authorized lab actor records one `FINAL` result; the order becomes `COMPLETED`.
3. `PRELIMINARY`, duplicate-final, non-active-order and closed-encounter result attempts fail without mutation.
4. An active order prevents RM closure; completing the order allows closure.
5. Unauthorized roles are denied without business-state disclosure.
6. Success and denial audit evidence is attributable and includes stable reason codes where applicable.
7. Supported local database tests and PostgreSQL schema `laravel` CI pass.

## Explicitly out of scope

- LIS/instrument integration, specimens, tarif/charges and reference ranges;
- PA and microbiology sub-desks;
- live BPJS/VClaim/SATUSEHAT;
- radiology/generalized order engine;
- real patient data.

Klaim, BPJS and Apotek remain **Soon**.

## Open owner decisions

1. Clinical/Laboratory owner approval of FINAL-only teaching behavior.
2. RMIK Department approval of active-order closure blocking and late-result rejection.
3. Required future preliminary/review/release states.
4. Attributable amendment, cancellation and reopen policies.
5. Full specimen, catalogue, reference range and reporting requirements.
