# Phase 0 — Program and repository foundation

Status: **in progress** (scaffolding started 2026-08-21)  
Gate: **G0 — BLOCKED** until formal sponsor acceptance of owners + blueprint  
Interim product owner: Daniel Happy Putra (see `OWNERS_AND_RACI.md`, DEC-001)

## Purpose

Establish ownership, decision rights, evidence structure, environment boundaries and a clean documentation baseline before mass parity implementation.

## Artifacts in this folder

| File | Purpose |
|---|---|
| `OWNERS_AND_RACI.md` | Named / interim / TBD owners for G0 |
| `DECISION_LOG.md` | `DEC-*` register for Phase 0+ |
| `PROTOTYPE_REUSE_MAP.md` | Keep / Adapt / Retire / Isolate for the existing Laravel prototype |
| `RELEASE_EVIDENCE_INDEX.md` | Index for release/commit/deploy evidence (states kept separate) |
| `ENVIRONMENT_AND_CREDENTIAL_BASELINE.md` | Synthetic-only and secret/endpoint boundary checklist |

## Exit gate (G0)

- [ ] Named owners (or dated TBD with accountable interim) for all RACI roles
- [ ] Blueprint accepted as planning baseline by sponsor / product owner
- [ ] Decision, risk and release-evidence structure present
- [ ] Synthetic-only environment confirmed; no unknown production credentials/endpoints
- [ ] Repository documentation baseline committed

## Explicit non-goals of Phase 0

- Mass generation of 268 menu pages
- Parity dispositions beyond scaffolding (Phase 1)
- Push, deploy, production integration enablement
- Old-system data migration

## Related sources

- `../MASTER_REBUILD_BLUEPRINT.md`
- `../DELIVERY_ROADMAP.md`
- `../PROJECT_TEAM_AND_RACI.md`
- `../RISK_REGISTER.md`
- `../../vendor-simrs-assessment-2026-08-21/SYSTEM_KNOWLEDGE_BASE.md`
