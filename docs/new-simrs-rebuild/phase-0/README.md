# Phase 0 — Program and repository foundation

Status: **in progress** (owners confirmed for now; blueprint accepted; stack ADR-015 accepted)  
Gate: **G0 — PASS (interim)** for ASAP synthetic demo testing; strengthen named individual contacts when available  
Product owner: Daniel Happy Putra (DEC-001 confirmed for now); RMIK Department named (DEC-011)

## Purpose

Establish ownership, decision rights, evidence structure, environment boundaries and a clean documentation baseline before mass parity implementation.

## Artifacts in this folder

| File | Purpose |
|---|---|
| `CURRENT_BASELINE.md` | Branch/tree snapshot + **SAHABAT minimum desk bar** (DEC-014) from assessed screenshots |
| `OWNERS_AND_RACI.md` | Named / interim / TBD owners for G0 |
| `DECISION_LOG.md` | `DEC-*` register for Phase 0+ |
| `PROTOTYPE_REUSE_MAP.md` | Keep / Adapt / Retire / Isolate for the existing Laravel prototype |
| `RELEASE_EVIDENCE_INDEX.md` | Index for release/commit/deploy evidence (states kept separate) |
| `ENVIRONMENT_AND_CREDENTIAL_BASELINE.md` | Synthetic-only and secret/endpoint boundary checklist |

## Exit gate (G0)

- [x] Named owners (or dated TBD with accountable interim) for all RACI roles
- [x] Blueprint accepted as planning baseline by product owner (DEC-005)
- [x] Decision, risk and release-evidence structure present
- [x] Synthetic-only environment confirmed for demo deploy; production endpoints remain disabled
- [x] Repository documentation baseline committed
- [x] Stack re-scored (DEC-008 / ADR-015)
- [x] Orientation baseline + SAHABAT minimum desk bar recorded (`CURRENT_BASELINE.md`, DEC-014)
- [ ] Named individual contact inside RMIK Department (org named; person optional follow-up)
- [ ] Distinct UEU executive sponsor (interim cover accepted for ASAP demo)
- [ ] Privacy-reviewed git intake of `docs/legacy-visual-field-capture/` (still untracked locally)

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
