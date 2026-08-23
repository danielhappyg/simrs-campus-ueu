# Outpatient order, result and closure contract

Status: **Proposed NEW teaching-safety contract; owner approval unresolved**

Date: 2026-08-23

Applies to: synthetic outpatient lab teaching slice (PAR-CLN-004, PAR-CLN-006, PAR-RMIK-001)

Required approvers: Clinical/Laboratory owner and RMIK Department

## Decision boundary

This contract is a fail-closed rule for SIMRS Campus UEU. It is **not observed or validated SIMRS Sahabat parity**. Available vendor evidence shows the relevant desks and a lab order/result path, but does not establish how SAHABAT handles active orders at RM closure, late results, preliminary results, amendments, reopening or cancellation.

The bounded teaching implementation may enforce this contract before domain-owner approval as a safety control. PAR-CLN-006 and PAR-RMIK-001 remain **Specified / not accepted** until the named owners review the workflow and acceptance evidence.

## Safety boundary

- `APP_MODE=SIMULATION` and synthetic-only enforcement remain mandatory.
- Real patient data is prohibited.
- No live BPJS, VClaim, SATUSEHAT or LIS connection is introduced.
- Klaim, BPJS and Apotek remain **Soon**.
- The failed Antrean/work-queue MVP remains retired under DEC-013.

## Minimal contract

| Situation | Required behavior | Stable denial reason |
|---|---|---|
| RMIK tries to close a `READY_FOR_RM` encounter with one or more `ACTIVE` lab orders | Reject closure; keep encounter open and identify the active-order blocker | `active_lab_orders` |
| Authorized lab actor submits a result for a `CLOSED` encounter | Reject without creating or changing a result/order | `encounter_closed` |
| Authorized lab actor submits a result for an order that is not `ACTIVE` | Reject without mutation | `order_not_active` |
| Authorized lab actor submits another result after a `FINAL` result exists | Reject; the existing final result remains immutable | `result_already_final` |
| Authorized lab actor submits a new result for an open encounter and `ACTIVE` order | Accept `FINAL` only; create one attributable result and move the order to `COMPLETED` | — |
| RMIK closes `READY_FOR_RM` with no `ACTIVE` lab order | Close the encounter and record the attributable RM action | — |

Authorization is checked before protected clinical state is disclosed. Wrong-role attempts use the shared authorization denial audit; they do not receive a business-state reason.

## State and transaction rules

```text
Encounter open + Lab order ACTIVE
          |
          +-- FINAL result recorded --> Lab order COMPLETED
          |                                  |
          |                                  +-- RM may close when no ACTIVE order remains
          |
          +-- RM close attempt ----------> DENIED: active_lab_orders

Encounter CLOSED
          +-- result attempt ------------> DENIED: encounter_closed
```

For every entry, order, result and close write, the server rechecks authoritative state inside the write transaction. Concurrent actions use a consistent encounter-first lock order; browser button state is guidance, not the enforcement boundary. A successful state mutation and its audit event must commit together.

## Teaching UI behavior

- The RM desk shows the active lab-order count and disables the close action when the count is non-zero, with an Indonesian explanation.
- The lab result desk offers **Final** only.
- Server-side enforcement remains authoritative if a stale browser or direct request bypasses the visible button state.
- The permanent `SIMULASI — DATA SINTETIS` indicator remains visible.

## Explicitly not built

The following workflows require a separate requirement, owner approval, state model, authorization and audit design before implementation:

- preliminary results;
- amendment or correction of a final result;
- encounter reopening after RM closure;
- lab-order cancellation;
- radiology or a generalized cross-module order engine.

Until those workflows are approved, final results are immutable and closed encounters remain closed. Do not use direct database edits as a teaching workflow.

## Acceptance evidence required

Using synthetic data, automated tests and facilitator UAT must demonstrate:

1. an active lab order blocks RM closure and leaves both records unchanged;
2. a final lab result completes the order, after which RM closure succeeds;
3. a closed encounter rejects a late result even if test setup contains an inconsistent active order;
4. preliminary and duplicate final submissions are rejected without mutation;
5. wrong-role requests fail before business-state disclosure;
6. success and business denial paths produce attributable audit evidence with stable reasons;
7. PostgreSQL schema `laravel` CI and the supported local test database both pass.

Hosted UAT Run 3 proves order → FINAL result → `COMPLETED` only. It does not prove this complete lifecycle contract; use the facilitator runbook for a fresh continuous run.

## Approval gate

| Owner | Review needed | Status |
|---|---|---|
| Clinical/Laboratory owner | FINAL-only teaching result policy, active-order meaning, future correction/cancellation workflow | Unresolved |
| RMIK Department | RM closure blocker, late-result policy, future reopen/amendment workflow | Unresolved |
| Product owner | Bounded teaching implementation and evidence package | Approved for work; not domain acceptance |

If either domain owner rejects this proposal, record a superseding DEC and revise the contract, code and UAT together. Do not relabel the current rule as SAHABAT-observed without new evidence.
