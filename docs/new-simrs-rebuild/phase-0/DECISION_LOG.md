# Decision log (`DEC-*`)

Status: active  
Created: 2026-08-21  
Rule: every material scope, safety, stack, environment or ownership choice gets a DEC row. Inferred legacy behavior is never recorded as Approved without evidence and owner.

## Register

| ID | Date | Status | Decision | Owner | Evidence / notes |
|---|---|---|---|---|---|
| DEC-001 | 2026-08-21 | Accepted (interim) | Daniel Happy Putra is interim product owner and program/architecture lead for G0 drafting until UEU formally confirms or replaces the appointment. | Daniel Happy Putra | User authorization 2026-08-21 (orientation A = Yes). Does not by itself complete G0 sponsor sign-off. |
| DEC-002 | 2026-08-21 | Accepted | Commit the `docs/new-simrs-rebuild/` planning package and `docs/vendor-simrs-assessment-2026-08-21/` assessment pack as the documentation baseline in this repository. | Interim product owner | User authorization 2026-08-21 (orientation B = Yes). Docs only; not a claim of implemented parity. |
| DEC-003 | 2026-08-21 | Accepted | Create Phase 0 scaffolding in-repo (owners placeholders, DEC log, prototype reuse map, release-evidence index, environment/credential baseline). No mass feature generation. | Interim product owner | User authorization 2026-08-21 (orientation C = Yes). |
| DEC-004 | 2026-08-21 | Accepted | Continue the clean-slate parity program in the **existing** repository `simrs-campus-ueu` (no mandatory new remote). Preserve existing local WIP; no destructive reset. | Interim product owner | User statement 2026-08-21. Branch strategy for large rebuild work remains open (DEC follow-up). |
| DEC-005 | 2026-08-21 | Proposed (working baseline) | Treat `MASTER_REBUILD_BLUEPRINT.md` and the `docs/new-simrs-rebuild/` set as the **planning baseline**. Formal G0 sponsor acceptance still required. | Interim product owner | Pending executive sponsor / UEU confirmation. |
| DEC-006 | 2026-08-21 | Accepted | Initial teaching release uses **generated synthetic data only**; no migration of vendor SIMRS data, credentials, cookies, or earlier prototype production-like datasets. | Interim product owner | `DATA_MIGRATION_AND_CUTOVER.md` default; user program constraints. |
| DEC-007 | 2026-08-21 | Accepted | Do not push, deploy, publish, delete, or activate external services without explicit user authorization and a verified rollback path. | Interim product owner | Program safety boundary. Demo push to existing Vercel remains separately authorized later. |
| DEC-008 | 2026-08-21 | Proposed | Re-evaluate the existing Laravel modular monolith + Vercel/Supabase demo stack against `TOOL_SELECTION_GUIDE.md` and record continue/constrain/replace in an ADR before Phase 2 exit. | Architecture lead (interim) | ADR-001 remains accepted for the prior simulation reference build; parity-program ratification is separate. |
| DEC-009 | 2026-08-21 | Accepted | Keep four evidence sources separate: (1) vendor assessment, (2) Laravel teaching prototype, (3) new rebuild docs, (4) discussion deliverables. Do not merge claims across them. | Interim product owner | Orientation report 2026-08-21. |

## Template for new entries

```text
ID: DEC-0NN
Date:
Status: Proposed | Accepted | Superseded | Rejected
Decision:
Owner:
Consulted:
Evidence label impact: (what becomes Approved / remains Pending evidence)
Reversal path:
Review date:
```

## Related ADRs

Historical simulation-build ADRs live under `docs/adr/` (ADR-001 … ADR-014). They describe the outpatient teaching prototype. They do **not** automatically dispose PAR-* rows or close G0.
