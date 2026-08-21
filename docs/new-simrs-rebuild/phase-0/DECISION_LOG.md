# Decision log (`DEC-*`)

Status: active  
Created: 2026-08-21  
Rule: every material scope, safety, stack, environment or ownership choice gets a DEC row. Inferred legacy behavior is never recorded as Approved without evidence and owner.

## Register

| ID | Date | Status | Decision | Owner | Evidence / notes |
|---|---|---|---|---|---|
| DEC-001 | 2026-08-21 | Accepted (confirmed for now) | Daniel Happy Putra is product owner and program/architecture lead for the rebuild until UEU replaces the appointment. | Daniel Happy Putra | User confirmation 2026-08-21 (“Confirm for now”). |
| DEC-002 | 2026-08-21 | Accepted | Commit the `docs/new-simrs-rebuild/` planning package and `docs/vendor-simrs-assessment-2026-08-21/` assessment pack as the documentation baseline. | Product owner | Orientation B = Yes. |
| DEC-003 | 2026-08-21 | Accepted | Create Phase 0 scaffolding in-repo. No mass feature generation as a substitute for parity. | Product owner | Orientation C = Yes. |
| DEC-004 | 2026-08-21 | Accepted | Continue the clean-slate parity program in the existing repository `simrs-campus-ueu`. | Product owner | User statement 2026-08-21. |
| DEC-005 | 2026-08-21 | Accepted | `MASTER_REBUILD_BLUEPRINT.md` and the `docs/new-simrs-rebuild/` set are the planning baseline for the program. | Product owner | User confirmation 2026-08-21 (“Accept”). |
| DEC-006 | 2026-08-21 | Accepted | Initial teaching release uses generated synthetic data only; no vendor data/credential migration. | Product owner | `DATA_MIGRATION_AND_CUTOVER.md` default. |
| DEC-007 | 2026-08-21 | Accepted | Do not push/deploy/activate external services without explicit authorization and rollback path. | Product owner | Superseded in part by DEC-010 for this demo deploy only. |
| DEC-008 | 2026-08-21 | Accepted | Continue Laravel modular monolith + React/Inertia + relational DB; Vercel + Supabase remains the synthetic demo host with explicit constraints. Recorded in ADR-015. | Architecture lead | Scored against `TOOL_SELECTION_GUIDE.md`; see ADR-015. |
| DEC-009 | 2026-08-21 | Accepted | Keep vendor assessment, Laravel prototype, rebuild docs, and discussion deliverables as separate evidence sources. | Product owner | Orientation report. |
| DEC-010 | 2026-08-21 | Accepted | Authorize push to `origin/main` and deploy of the synthetic Vercel + Supabase demo for immediate testing. Production integrations remain disabled. | Product owner | User 2026-08-21 (“Deploy immediately”). Rollback: previous Vercel deployment / prior git SHA. |
| DEC-011 | 2026-08-21 | Accepted | RMIK Department is the named departmental owner for RMIK/coding/reports and teaching-owner representation until a named individual is recorded. | Product owner | User 2026-08-21 (“RMIK Department”). |

## Template for new entries

```text
ID: DEC-0NN
Date:
Status: Proposed | Accepted | Superseded | Rejected
Decision:
Owner:
Consulted:
Evidence label impact:
Reversal path:
Review date:
```

## Related ADRs

- Historical simulation ADRs: `docs/adr/ADR-001` … `ADR-014`
- Stack ratification for parity program: `docs/adr/ADR-015-STACK-SELECTION-FOR-PARITY-PROGRAM.md`
