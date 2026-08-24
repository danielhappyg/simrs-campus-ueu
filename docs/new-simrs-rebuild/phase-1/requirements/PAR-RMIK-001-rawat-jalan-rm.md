# Parity Requirement: PAR-RMIK-001 RM Rawat Jalan (canonical)

## Control information

- Legacy menu/category: RM / Rawat Jalan
- Disposition: **Reproduce**; EMR IPP RJ (PAR-RMIK-006) consolidates pending RMIK confirmation
- Business owner: **RMIK Department**
- Affected actors: medical record officer, coder, RMIK student, supervisor
- Evidence: **Observed** menu/route `/rm/rawatjalan`; EMR IPP route reuses similar process_form posts (Observed structural)
- Target milestone: Phase 3 outpatient slice (after clinical source exists)
- Owner-decision pack: [`../STRUCTURED_RJ_DOCUMENTATION_RM_COMPLETENESS_FR_PACK.md`](../STRUCTURED_RJ_DOCUMENTATION_RM_COMPLETENESS_FR_PACK.md), [`../STRUCTURED_RJ_DOCUMENTATION_RM_COMPLETENESS_WIREFRAME.md`](../STRUCTURED_RJ_DOCUMENTATION_RM_COMPLETENESS_WIREFRAME.md), and [`../STRUCTURED_RJ_DOCUMENTATION_RM_COMPLETENESS_V1_IMPLEMENTATION_DECISION.md`](../STRUCTURED_RJ_DOCUMENTATION_RM_COMPLETENESS_V1_IMPLEMENTATION_DECISION.md) — **bounded v1 engineering authorized; RMIK acceptance and parity acceptance pending**

## Business outcome

Complete outpatient record quality/coding readiness for the encounter so claim preparation and reports use authorized, attributable clinical facts.

## Preconditions

- Encounter with clinical documentation from PAR-CLN-004 and no `ACTIVE` lab order
- Coding terminology releases available (teaching catalogues)
- Actor has record review / coding capabilities

## Workflow and state transitions

1. Trigger: encounter enters RM worklist (criteria **Unknown**)
2. Sequence: completeness review → verify no active lab order → coding candidates/decisions → supervisor actions as required → close
3. Resulting state: coded/complete flags (**Unknown** exact statuses)
4. Downstream: Klaim RJ, reports, optional SatuSehat sandbox preview

## Business rules

| Rule ID | Rule | Evidence |
|---|---|---|
| BR-RM-001 | Coding requires human confirmation; suggestions are not auto-final | Proposed NEW (teaching safety) |
| BR-RM-002 | Corrections create attributable chain to clinical authors when policy requires | Proposed NEW |
| BR-RM-003 | Exact completeness checklist | **Unknown** — RMIK Department discovery |
| BR-RM-004 | An `ACTIVE` lab order blocks outpatient RM closure | Proposed NEW (teaching safety); reason `active_lab_orders`; **not SAHABAT-observed** |
| BR-RM-005 | A `CLOSED` encounter rejects late lab results and new clinical writes | Proposed NEW (teaching safety) |

## Authorization and audit

`rmik.review` controls worklist access; `rmik.completeness.signoff` controls closure; RMIK coding capability controls coding writes. Audit all coding decisions and completeness sign-off.

## Acceptance criteria (synthetic)

1. RMIK user can open outpatient encounter for review after clinical docs exist.
2. Coding decision recorded with actor/time/code system version.
3. Non-RMIK cannot finalize coding.
4. SatuSehat remains non-transmitting sandbox (PAR-RMIK-005 Replace).
5. RM close fails without mutation while an active lab order exists and succeeds after its one final result completes the order.

## Open unknowns

Completeness checklist; filing interaction; claim status filters on RM screen; IPP vs classic differences — **RMIK Department** must confirm before Verified. Preliminary results, amendment/correction, encounter reopening and lab-order cancellation are not built. DEC-016 remains **Proposed** until Clinical/Laboratory and RMIK owners approve [`../OUTPATIENT_ORDER_RESULT_CLOSURE_CONTRACT.md`](../OUTPATIENT_ORDER_RESULT_CLOSURE_CONTRACT.md).
