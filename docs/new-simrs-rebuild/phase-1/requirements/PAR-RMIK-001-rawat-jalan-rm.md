# Parity Requirement: PAR-RMIK-001 RM Rawat Jalan (canonical)

## Control information

- Legacy menu/category: RM / Rawat Jalan
- Disposition: **Reproduce**; EMR IPP RJ (PAR-RMIK-006) consolidates pending RMIK confirmation
- Business owner: **RMIK Department**
- Affected actors: medical record officer, coder, RMIK student, supervisor
- Evidence: **Observed** menu/route `/rm/rawatjalan`; EMR IPP route reuses similar process_form posts (Observed structural)
- Target milestone: Phase 3 outpatient slice (after clinical source exists)

## Business outcome

Complete outpatient record quality/coding readiness for the encounter so claim preparation and reports use authorized, attributable clinical facts.

## Preconditions

- Encounter with clinical documentation from PAR-CLN-004
- Coding terminology releases available (teaching catalogues)
- Actor has record review / coding capabilities

## Workflow and state transitions

1. Trigger: encounter enters RM worklist (criteria **Unknown**)
2. Sequence: completeness review → coding candidates/decisions → supervisor actions as required → ready for claim
3. Resulting state: coded/complete flags (**Unknown** exact statuses)
4. Downstream: Klaim RJ, reports, optional SatuSehat sandbox preview

## Business rules

| Rule ID | Rule | Evidence |
|---|---|---|
| BR-RM-001 | Coding requires human confirmation; suggestions are not auto-final | Proposed NEW (teaching safety) |
| BR-RM-002 | Corrections create attributable chain to clinical authors when policy requires | Proposed NEW |
| BR-RM-003 | Exact completeness checklist | **Unknown** — RMIK Department discovery |

## Authorization and audit

RMIK roles only for coding write; audit all coding decisions and completeness sign-off.

## Acceptance criteria (synthetic)

1. RMIK user can open outpatient encounter for review after clinical docs exist.
2. Coding decision recorded with actor/time/code system version.
3. Non-RMIK cannot finalize coding.
4. SatuSehat remains non-transmitting sandbox (PAR-RMIK-005 Replace).

## Open unknowns

Completeness checklist; filing interaction; claim status filters on RM screen; IPP vs classic differences — **RMIK Department** must confirm before Verified.
