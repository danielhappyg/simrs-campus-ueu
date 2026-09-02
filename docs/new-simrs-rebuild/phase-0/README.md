# Phase 0 — Program and repository foundation

Status: **in progress** (governance v2 adopted for local implementation; no active governance consumer)
Gate: **G0 — OPEN** and **G3 — OPEN** for Teaching Parity Release 1.0
Product owner: Daniel Happy Putra (DEC-001 confirmed for now); RMIK Department named (DEC-011)

The exact governance-v2 proposal and ADR-018 were adopted through [`G0_GOVERNANCE_V2_ADOPTION_DECISION.json`](G0_GOVERNANCE_V2_ADOPTION_DECISION.json). That decision authorizes local governance-v2 implementation only. It does not activate a consumer, decide a capability, authorize a slice, deploy an application, accept a hosted/domain result, close project G0, or establish G3 acceptance.

The later [`G0_GOVERNANCE_V2_PROCESS_SIMPLIFICATION_DECISION_2026-08-30.json`](G0_GOVERNANCE_V2_PROCESS_SIMPLIFICATION_DECISION_2026-08-30.json) retires the proposed ADR-019 exact-wording reply requirement. Local engineering may proceed through bounded, testable slice records without waiting for that ceremony, while synthetic-data, no-secrets, no-live-integration, and no-premature-commit/push/deployment limits remain in force. ADR-019 and external trust provisioning remain unapproved and non-authoritative; the simplification does not activate a governance consumer or close G0/G3.

As observed by the dated 2026-08-29 Wave 6 artifact, the canonical consumer pointer is absent, so the active governance resolution is `pointer_missing`. No candidate or implementation evidence is operative governance authority. That observation keeps every `governance_decision_pointer` null and both G0 and G3 `OPEN`; a later state change requires a separate authorized operation and a new dated observation.

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
| `G0_GOVERNANCE_V1_HISTORICAL_HASH_MANIFEST.json` | Exact closed hash inventory that preserves v1 artifacts and tests as immutable historical evidence |
| `G0_GOVERNANCE_V2_ADOPTION_DECISION.json` | Immutable product-owner adoption record; authority is limited to local governance-v2 implementation |
| `G0_GOVERNANCE_V2_PROCESS_SIMPLIFICATION_DECISION_2026-08-30.json` | Append-only product-owner direction retiring the ADR-019 exact-wording ceremony while preserving local engineering and publication boundaries |
| `G0_GOVERNANCE_V2_CONTRACT.json` | Machine-readable closed governance-v2 states, rules, tiers, outcomes, and source bindings |
| `G0_GOVERNANCE_V2_IMPLEMENTATION_BLUEPRINT_2026-08-28.md` | Ordered implementation waves and separate authority gates; not a capability or release decision |
| `G0_BATCH_A_DECISION_READY_RECONCILIATION_2026-09-02.md` + `.json` | Exact 20-row dependency-root reconciliation with normalized evidence, proposed dispositions/targets, owner candidates, and normal plus denial/correction scenarios; decision-ready only, not approved or active |

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

## Separate governance and release states

These states must never be collapsed into one readiness claim:

1. **Immutable v1 historical integrity** means the closed 30-file v1 inventory still matches its planning-baseline hashes. It preserves history; v1 is not silently reactivated or rewritten by v2.
2. **Governance-v2 adoption** is effective only for local governance implementation under the synthetic/no-live boundary.
3. **Candidate validity** means a candidate passes the v2 contract, exact 268-row migration parity, and immutable-v1 checks. Candidate PASS is observational and does not select it.
4. **Active consumer selection** requires a separate attributable operation decision plus successful atomic pointer publication. No such canonical selection exists now.
5. **Capability disposition and slice authorization** require separate product-owner, affected-domain, co-owner, and independent-review/control decisions as dictated by the derived tier. Adoption and activation cannot supply these decisions.
6. **Project G0** is recomputed across all 268 current governance entries. It remains `OPEN` unless every required owner-governance condition is complete and matches the active gate register.
7. **Hosted and domain acceptance** require separate current, exact-SHA evidence and attributable acceptance; local tests, deployment, or file presence do not supply them.
8. **G3** is separate from G0 and additionally requires current engineering evidence, hosted role UAT, reconciliation, recovery, security, accessibility, performance, defect closure, and owner acceptance. G3 remains `OPEN`.

Engineering maps, tests, receipts, journals, generators, comparison output, and ledger evidence are non-authoritative for owner identity, capability disposition, slice authorization, or gate promotion.

## Historical v1 verification

The v1 authority and owner artifacts remain byte-preserved and runnable as historical integrity evidence. Their earlier institutional PKI and S0–S7 ceremony is not rewritten into proportional v2 authority. The unsigned v1 proposal generator remains available only for historical verification and candidate isolation. Generate only into a new candidate directory and verify its hashes; do not promote it as governance-v2 authority:

```bash
ruby scripts/generate-g0-owner-governance-snapshot.rb \
  --owner-snapshot-plan docs/new-simrs-rebuild/phase-0/G0_OWNER_GOVERNANCE_SNAPSHOT_PLAN_2026-08-25.json \
  --output /absolute/new/candidate-directory

ruby scripts/generate-g0-owner-governance-snapshot.rb \
  --verify-bundle /absolute/new/candidate-directory
```

The generator refuses an existing output directory, derives all source digests from the exact A–G files, and never overwrites canonical governance artifacts. Run `ruby scripts/validate-parity-governance.rb --mode integrity` and `ruby scripts/validate-g0-s0-intake.rb --mode integrity` to verify the preserved v1 surface. A historical v1 gate result does not select a governance consumer or promote the schema-v2 ledger.

## Active-pointer and recovery behavior

The schema-v2 ledger keeps the immutable `source_decision_pointer` separate from the nullable `governance_decision_pointer`. The source pointer provides A–G provenance only. A current governance pointer may be populated only through a complete, active, hash-valid pointer → selection → bundle → expanded decision chain.

A missing, unreadable, recovery-required, held, disabled, ambiguous, or otherwise invalid canonical resolution forces all current governance pointers to null and project G0/G3 to `OPEN`. Rollback publishes a new `rollback_hold` selection; recovery publishes a new `recovery_hold` or `disabled` selection. None reactivates an older activation or v1, and reuse requires a fresh, separately authorized activation.

Active-snapshot drift, a stale ledger binding, a changed selection/bundle/register hash, a capability row/order mismatch, or an independently recomputed gate mismatch instead makes generation or checking fail with no new ledger publication. An older mismatched ledger cannot be accepted as current evidence.

See [`../G0_G3_COVERAGE_LEDGER_README.md`](../G0_G3_COVERAGE_LEDGER_README.md) for the dated schema-v2 observation and gate interpretation.

## Explicit non-goals of Phase 0

- Mass generation of 268 menu pages
- Parity dispositions beyond scaffolding (Phase 1)
- Consumer activation, capability/slice authorization, push, deployment, hosted migration, or domain/G3 acceptance without their separate decisions
- Old-system data migration
- Real patient data or live BPJS, VClaim, SATUSEHAT, payment, LIS, PACS, device, or other external integration

## Related sources

- `../MASTER_REBUILD_BLUEPRINT.md`
- `../DELIVERY_ROADMAP.md`
- `../PROJECT_TEAM_AND_RACI.md`
- `../RISK_REGISTER.md`
- `../../vendor-simrs-assessment-2026-08-21/SYSTEM_KNOWLEDGE_BASE.md`
