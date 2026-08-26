# Phase 0 — Program and repository foundation

Status: **in progress** (historical demo scaffolding exists; formal institutional owner authority remains open)
Gate: **G0 — OPEN** for Teaching Parity Release 1.0; the earlier interim ASAP-demo checkpoint was not formal parity acceptance
Product owner: Daniel Happy Putra (DEC-001 confirmed for now); RMIK Department named (DEC-011)

The later [G0 parity-control baseline](G0_PARITY_CONTROL_BASELINE_2026-08-25.md), [owner appointment pack](G0_OWNER_APPOINTMENT_PACK_2026-08-25.md), and machine registers supersede the older interim wording for formal G0. No named institutional appointments, S0-S7 sessions, or terminal owner decisions are currently recorded.

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
| `G0_OWNER_GOVERNANCE_SNAPSHOT_PLAN_2026-08-25.json` | Closed, digest-free snapshot identity/revision/timestamp plan for deterministic owner-policy generation |
| `G0_S0_INSTITUTIONAL_AUTHORITY_INTAKE_2026-08-26.md` | Fail-closed institutional choice and seven/eight-person authority-roster intake for S0 |

## Historical interim demo-scaffolding checklist

The checked items below describe the earlier synthetic-demo planning baseline only. They do not close formal G0 for Teaching Parity Release 1.0.

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

## Formal G0 exit remains open

Formal G0 requires verified institutional identities and public keys, approved owner-authority policy, active appointments, signed S0-S7 sessions, and terminal attributable dispositions for all 268 capabilities. Current progress and fail-closed requirements are governed by `G0_PARITY_CONTROL_BASELINE_2026-08-25.md` and `G0_OWNER_APPOINTMENT_PACK_2026-08-25.md`.

The unsigned proposal is now generated from the explicit snapshot plan. Generate only into a new candidate directory, verify the isolated bundle, and review its hashes before promoting canonical files:

```bash
ruby scripts/generate-g0-owner-governance-snapshot.rb \
  --owner-snapshot-plan docs/new-simrs-rebuild/phase-0/G0_OWNER_GOVERNANCE_SNAPSHOT_PLAN_2026-08-25.json \
  --output /absolute/new/candidate-directory

ruby scripts/generate-g0-owner-governance-snapshot.rb \
  --verify-bundle /absolute/new/candidate-directory
```

The generator refuses an existing output directory, derives all source digests from the exact A-G files, and never overwrites canonical governance artifacts. Run `ruby scripts/validate-parity-governance.rb --mode integrity` and `ruby scripts/validate-g0-s0-intake.rb --mode integrity` after reviewed promotion. `--mode g0` must remain red until real institutional approval and all 268 terminal decisions exist.

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
