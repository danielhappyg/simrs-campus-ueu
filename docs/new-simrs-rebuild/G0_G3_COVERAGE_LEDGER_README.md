# G0–G3 coverage ledger

The coverage ledger is a deterministic, observation-only inventory for the 268 assessed capabilities and the canonical E2E-01 through E2E-16 workflows. It reports governance and delivery evidence; it does not create authority, activate a governance consumer, authorize a capability or slice, deploy an application, or accept G0 or G3.

## Current state

Governance v2 has been adopted only for local implementation. The first dated 2026-08-29 Wave 6 artifact observed no canonical active consumer pointer or active selection. It records `governance_profile_binding.status=unavailable`, `reason_code=pointer_missing`, `gate_summary.g0.status=OPEN`, and `gate_summary.g3.status=OPEN`; every `governance_decision_pointer` is `null`.

That first schema-v2 artifact is now an immutable observation bound to validator contract 1.0.0. The create-only [`G0_G3_COVERAGE_LEDGER_V2_2026-08-29_R2.json`](G0_G3_COVERAGE_LEDGER_V2_2026-08-29_R2.json) successor is bound to validator contract 1.2.0. The retained R3 through [`G0_G3_COVERAGE_LEDGER_V2_2026-09-02_R10.json`](G0_G3_COVERAGE_LEDGER_V2_2026-09-02_R10.json) successors remain immutable observations. The create-only [`G0_G3_COVERAGE_LEDGER_V2_2026-09-03_R11.json`](G0_G3_COVERAGE_LEDGER_V2_2026-09-03_R11.json) successor is the current schema-v2 local observation and binds the cashier-collection engineering-evidence successor. Every predecessor remains immutable historical evidence and is not rewritten or reinterpreted by R11.

This is an expected fail-closed observation. It is not evidence of activation, deployment, hosted acceptance, domain acceptance, or release readiness.

The current selector lock is repository-local. It detects replacement after the R4 ledger writer opens it, but—without the separately proposed ADR-019 host-trust pin—it cannot prove that a same-UID process did not replace the lock before the writer started. R11 does not resolve or mutate that selector: it copies the exact fail-closed R10 governance observation and changes only closed engineering-evidence paths. R11 therefore remains a local observation and must not be used to activate, promote, or provision a governance consumer. Its `governance_profile_binding` remains `pointer_missing`, all governance decision pointers remain `null`, and G0 and G3 remain `OPEN`.

The tariff records mark PAR-ADM-011, PAR-ADM-018, and PAR-ADM-019 as locally implemented with current automated and exact-engine evidence. R4/R6 retain the bounded radiology performance-to-tariff/source evidence, and R5/R7 retain the bounded laboratory verified-result tariff/source evidence. R6/R8 retain the bounded accommodation evidence. R7/R9 retain the exact cash-settlement evidence. R8/R10 append only exact append-only cash-settlement-correction evidence to PAR-FIN-001, PAR-FIN-002, and E2E-14. R9/R11 append only cashier collection-batch evidence paths to PAR-FIN-012 and E2E-14, including the exact 22-scenario PostgreSQL 17.10 and MySQL 8.4.11 artifacts. PAR-FIN-012 remains `NOT_IMPLEMENTED` with governance pointer missing because post-handoff adjustment and dependency-outage/ambiguous-ack behavior remain outside this V1 evidence. This does not prove owner acceptance, real-hospital tariff, settlement, correction, collection-batch, or treasury acceptance, hosted UAT, migration, deployment, G0, or G3; it also does not prove domain acceptance.

It does not prove owner acceptance, hosted UAT, migration, deployment, G0, or G3.

## Files and version boundary

- [`G0_G3_COVERAGE_EVIDENCE_MAP_2026-08-27.json`](G0_G3_COVERAGE_EVIDENCE_MAP_2026-08-27.json) and [`G0_G3_COVERAGE_LEDGER_2026-08-27.json`](G0_G3_COVERAGE_LEDGER_2026-08-27.json) are immutable schema-v1 historical snapshots. The preserved 2026-08-26 map and ledger are older historical snapshots. Governance v2 does not rewrite or reinterpret their bytes.
- [`G0_G3_COVERAGE_EVIDENCE_MAP_V2_2026-08-29.json`](G0_G3_COVERAGE_EVIDENCE_MAP_V2_2026-08-29.json) is a closed engineering-evidence overlay. It cannot contain owner, approval, disposition, tier, pointer, or gate authority.
- [`G0_G3_COVERAGE_EVIDENCE_MAP_V2_2026-08-29_R2.json`](G0_G3_COVERAGE_EVIDENCE_MAP_V2_2026-08-29_R2.json) is its append-only refreshed successor. Its artifact ID is `COVERAGE-ENGINEERING-EVIDENCE-MAP-V2-2026-08-29-R2`, its exact SHA-256 is `581c0d5eccdd42b818270b3d214e600e33ac12bf6d1895143e644be43783f27f`, and its closed predecessor record preserves the original map at exact SHA-256 `3b1005a31087896b412a8f64a3dbfced2c0ab655be78c32abd7c0743ae3811d5` without rewriting or reinterpreting it.
- [`G0_G3_COVERAGE_EVIDENCE_MAP_V2_2026-09-02_R3.json`](G0_G3_COVERAGE_EVIDENCE_MAP_V2_2026-09-02_R3.json) is the immutable tariff evidence predecessor. Its artifact ID is `COVERAGE-ENGINEERING-EVIDENCE-MAP-V2-2026-09-02-R3`, its exact SHA-256 is `c59c15593c0db6f37ef144f95a7d04d22df60404ee7cf2e68562501cc07316f8`, and it must not be rewritten after later source bytes advance.
- [`G0_G3_COVERAGE_EVIDENCE_MAP_V2_2026-09-02_R4.json`](G0_G3_COVERAGE_EVIDENCE_MAP_V2_2026-09-02_R4.json) is the immutable radiology evidence predecessor. Its artifact ID is `COVERAGE-ENGINEERING-EVIDENCE-MAP-V2-2026-09-02-R4`, its exact SHA-256 is `e040fb70399c78c2921dd69fe6236850bf5b9ee8ad0a3de19413a43a67f8158e`, and its closed predecessor record binds R3 without being rewritten by later successors.
- [`G0_G3_COVERAGE_EVIDENCE_MAP_V2_2026-09-02_R5.json`](G0_G3_COVERAGE_EVIDENCE_MAP_V2_2026-09-02_R5.json) is the immutable laboratory evidence predecessor. Its artifact ID is `COVERAGE-ENGINEERING-EVIDENCE-MAP-V2-2026-09-02-R5`, its exact SHA-256 is `07bef2b234f31336eb730dce4d035b830b91dc7995c2f979f2ba59be0558a921`, and it remains unchanged.
- [`G0_G3_COVERAGE_EVIDENCE_MAP_V2_2026-09-02_R6.json`](G0_G3_COVERAGE_EVIDENCE_MAP_V2_2026-09-02_R6.json) is the immutable accommodation engineering-evidence predecessor. Its artifact ID is `COVERAGE-ENGINEERING-EVIDENCE-MAP-V2-2026-09-02-R6`, its exact SHA-256 is `d50430bb982df8d8dfb0a789ab0b2b0c8211557ed1d46d42e51f7126cc22cc08`, and it binds exact R5 bytes plus the bounded accommodation catalogue, final evidence record, and two exact disposable-engine artifacts.
- [`G0_G3_COVERAGE_EVIDENCE_MAP_V2_2026-09-02_R7.json`](G0_G3_COVERAGE_EVIDENCE_MAP_V2_2026-09-02_R7.json) is the immutable exact cash-settlement engineering-evidence predecessor. Its artifact ID is `COVERAGE-ENGINEERING-EVIDENCE-MAP-V2-2026-09-02-R7`, its exact SHA-256 is `45194af7aaabfda54069e4c22a420340f7b4940751d75485a56f34787e94c7fc`, and it binds exact R6 bytes plus the bounded settlement catalogue, final evidence record, and two exact disposable-engine artifacts.
- [`G0_G3_COVERAGE_EVIDENCE_MAP_V2_2026-09-02_R8.json`](G0_G3_COVERAGE_EVIDENCE_MAP_V2_2026-09-02_R8.json) is the immutable cash-correction engineering-evidence predecessor. Its artifact ID is `COVERAGE-ENGINEERING-EVIDENCE-MAP-V2-2026-09-02-R8`, its exact SHA-256 is `4bdfb6bf3f528b0d09b048049e2b047a678e8cb5711133929b6b494f15ae0415`, and it binds exact R7 bytes plus the bounded cash-correction catalogue, final evidence record, and two exact disposable-engine artifacts.
- [`G0_G3_COVERAGE_EVIDENCE_MAP_V2_2026-09-03_R9.json`](G0_G3_COVERAGE_EVIDENCE_MAP_V2_2026-09-03_R9.json) is the current append-only local engineering-evidence map. Its artifact ID is `COVERAGE-ENGINEERING-EVIDENCE-MAP-V2-2026-09-03-R9`, its exact SHA-256 is `f8785833c7259a798b0185938e6e45c15c1ec3c7e8c7efe46b2d27038f9f84dc`, and it binds exact R8 bytes plus the bounded cashier-collection catalogue, final evidence record, and two exact disposable-engine artifacts.
- [`G0_G3_COVERAGE_LEDGER_V2_2026-08-29.json`](G0_G3_COVERAGE_LEDGER_V2_2026-08-29.json) is the first pointer-aware, dated schema-v2 observation. Its artifact ID is `G0-G3-COVERAGE-LEDGER-V2-2026-08-29`, its exact SHA-256 is `690becdf75a08d17b992d8dad754313f33d0f9c8ea2c692b9b12b8b837f43ac5`, and it must never be edited, regenerated, or reinterpreted.
- [`G0_G3_COVERAGE_LEDGER_V2_2026-08-29_R2.json`](G0_G3_COVERAGE_LEDGER_V2_2026-08-29_R2.json) is the append-only successor. Its artifact ID is `G0-G3-COVERAGE-LEDGER-V2-2026-08-29-R2`, its exact SHA-256 is `0b89705741bb052629593b9023f1c8d19c7827489a7e8e6ce6e46af1efd5f1c6`, and its closed `sources.superseded_ledger` record binds the predecessor path, SHA, artifact ID, and `supersedes_without_rewriting_or_reinterpreting_predecessor` relationship.
- [`G0_G3_COVERAGE_LEDGER_V2_2026-08-29_R3.json`](G0_G3_COVERAGE_LEDGER_V2_2026-08-29_R3.json) is the next append-only successor. Its artifact ID is `G0-G3-COVERAGE-LEDGER-V2-2026-08-29-R3`, its exact SHA-256 is `0f312713000b0092de313baee2d3cc6d34695c2fefd717c620871268045ac58c`, and its closed `sources.superseded_ledger` record binds R2 by exact path, SHA, artifact ID, and the same non-rewrite relationship.
- [`G0_G3_COVERAGE_LEDGER_V2_2026-08-29_R4.json`](G0_G3_COVERAGE_LEDGER_V2_2026-08-29_R4.json) is the retained direct predecessor of R5. Its artifact ID is `G0-G3-COVERAGE-LEDGER-V2-2026-08-29-R4`, its exact SHA-256 is `473cba5aec79a09621dda979ebbf0b4ec4c456aaede5bac159291c58062cd8a4`, and its closed `sources.superseded_ledger` record binds R3 by exact path, SHA, artifact ID, and the same non-rewrite relationship.
- [`G0_G3_COVERAGE_LEDGER_V2_2026-09-02_R5.json`](G0_G3_COVERAGE_LEDGER_V2_2026-09-02_R5.json) is the immutable tariff observation predecessor. Its artifact ID is `G0-G3-COVERAGE-LEDGER-V2-2026-09-02-R5`, its exact SHA-256 is `1cef27463e0d5f7c887e68d6031671e13b3b7bbf980bd7ed1c01db6e73ec09f2`.
- [`G0_G3_COVERAGE_LEDGER_V2_2026-09-02_R6.json`](G0_G3_COVERAGE_LEDGER_V2_2026-09-02_R6.json) is the immutable radiology observation predecessor. Its artifact ID is `G0-G3-COVERAGE-LEDGER-V2-2026-09-02-R6`, its exact SHA-256 is `a43e151b0829bbd36c45f1f28ae07a20669165ceb83fe36fd8b01aae0ac05f50`.
- [`G0_G3_COVERAGE_LEDGER_V2_2026-09-02_R7.json`](G0_G3_COVERAGE_LEDGER_V2_2026-09-02_R7.json) is the immutable laboratory observation predecessor. Its artifact ID is `G0-G3-COVERAGE-LEDGER-V2-2026-09-02-R7`, its exact SHA-256 is `3163e1054d83c17b6938149e6a5af934bfc0bdc333226697fed8bde178dcad23`, and it remains unchanged.
- [`G0_G3_COVERAGE_LEDGER_V2_2026-09-02_R8.json`](G0_G3_COVERAGE_LEDGER_V2_2026-09-02_R8.json) is the immutable accommodation observation predecessor. Its artifact ID is `G0-G3-COVERAGE-LEDGER-V2-2026-09-02-R8`, its exact SHA-256 is `3df5165be14946794002cc121e1f3f92f64232d6e39057e9d48d65636dfb4665`, and it binds exact R7 and R6 bytes while preserving every governance, authority, owner, hosted, deployment, G0, and G3 fact.
- [`G0_G3_COVERAGE_LEDGER_V2_2026-09-02_R9.json`](G0_G3_COVERAGE_LEDGER_V2_2026-09-02_R9.json) is the immutable exact cash-settlement observation predecessor. Its artifact ID is `G0-G3-COVERAGE-LEDGER-V2-2026-09-02-R9`, its exact SHA-256 is `290f60ab0ab060a6fd58920fd53313bade83b0c0584a5dd6de4a24a64d951654`, and it binds exact R8 and R7 bytes while preserving every governance, authority, owner, hosted, deployment, G0, and G3 fact.
- [`G0_G3_COVERAGE_LEDGER_V2_2026-09-02_R10.json`](G0_G3_COVERAGE_LEDGER_V2_2026-09-02_R10.json) is the immutable cash-correction observation predecessor. Its artifact ID is `G0-G3-COVERAGE-LEDGER-V2-2026-09-02-R10`, its exact SHA-256 is `40ed0a478be4c7e9077cbbe3e46609ff900cf9e8e075c93a52aad0b6a3a50bb8`, and it binds exact R9 and R8 bytes while preserving every governance, authority, owner, hosted, deployment, G0, and G3 fact.
- [`G0_G3_COVERAGE_LEDGER_V2_2026-09-03_R11.json`](G0_G3_COVERAGE_LEDGER_V2_2026-09-03_R11.json) is the current schema-v2 local observation. Its artifact ID is `G0-G3-COVERAGE-LEDGER-V2-2026-09-03-R11`, its exact SHA-256 is `aedb8ec542b9aeb8111d9877b3355f21d04f5f48adcd3572cdadb95fe5ed0452`, and it binds exact R10 and R9 bytes while preserving every governance, authority, owner, hosted, deployment, G0, and G3 fact.
- [`scripts/generate-g0-g3-coverage-ledger.rb`](../../scripts/generate-g0-g3-coverage-ledger.rb) retains the schema-v1 path and provides the separate schema-v2 R4 generator path. It verifies the immutable original → R2 → R3 chain before building R4. Generation never mutates a predecessor, the consumer pointer, or any selection.
- [`scripts/generate-g0-g3-coverage-tariff-successors.rb`](../../scripts/generate-g0-g3-coverage-tariff-successors.rb) is the successor-specific create-only publisher for the R3 map and R5 ledger. It binds the exact R2/R4 predecessor hashes, uses a closed tariff-only delta catalogue, rejects unsafe paths, symlinks, hardlinks, secrets, schema drift, and overwrites, and has no governance-selector mutation path.
- [`scripts/generate-g0-g3-coverage-radiology-successors.rb`](../../scripts/generate-g0-g3-coverage-radiology-successors.rb) is the successor-specific create-only publisher for the current R4 map and R6 ledger. It binds exact R3/R5 predecessor hashes, refreshes only the closed radiology evidence catalogue, rejects unsafe inputs and overwrites, and cannot mutate governance selection or gates.
- [`scripts/generate-g0-g3-coverage-laboratory-successors.rb`](../../scripts/generate-g0-g3-coverage-laboratory-successors.rb) is the successor-specific create-only publisher for the current R5 map and R7 ledger. It binds exact R4/R6 predecessor hashes, appends only the closed laboratory evidence catalogue, rejects unsafe inputs and overwrites, and cannot mutate governance selection or gates.
- [`scripts/generate-g0-g3-coverage-accommodation-successors.rb`](../../scripts/generate-g0-g3-coverage-accommodation-successors.rb) is the successor-specific create-only publisher for the immutable R6 map and R8 ledger predecessors. It binds exact R5/R7 predecessor hashes and the final accommodation record plus exact PostgreSQL/MySQL artifacts, appends only the bounded accommodation evidence catalogue, rejects unsafe inputs and overwrites, and cannot mutate governance selection or gates.
- [`scripts/generate-g0-g3-coverage-settlement-successors.rb`](../../scripts/generate-g0-g3-coverage-settlement-successors.rb) is the successor-specific create-only publisher for the immutable R7 map and R9 ledger predecessors. It binds exact R6/R8 predecessor hashes and the final settlement record plus exact PostgreSQL/MySQL artifacts, appends settlement evidence only to PAR-FIN-001, PAR-FIN-002, and E2E-14, rejects unsafe inputs and overwrites, and cannot mutate governance selection or gates.
- [`scripts/generate-g0-g3-coverage-cash-correction-successors.rb`](../../scripts/generate-g0-g3-coverage-cash-correction-successors.rb) is the successor-specific create-only publisher for the current R8 map and R10 ledger. It binds exact R7/R9 predecessor hashes and the final correction record plus exact PostgreSQL/MySQL artifacts, appends correction evidence only to PAR-FIN-001, PAR-FIN-002, and E2E-14, rejects unsafe inputs and overwrites, and cannot mutate governance selection or gates.
- [`scripts/generate-g0-g3-coverage-cashier-collection-successors.rb`](../../scripts/generate-g0-g3-coverage-cashier-collection-successors.rb) is the successor-specific create-only publisher for the current R9 map and R11 ledger. It binds exact R8/R10 predecessor hashes and the final cashier-collection record plus exact PostgreSQL/MySQL artifacts, appends evidence paths only to PAR-FIN-012 and E2E-14, rejects unsafe inputs and overwrites, and cannot mutate governance selection, implementation authority, completion state, or gates.

## The two capability pointers

Each schema-v2 capability keeps two non-interchangeable fields:

- `source_decision_pointer` identifies the immutable A–G source-register path, source SHA, row index, and row SHA. It preserves historical traceability but cannot confer owner authority or populate a current governance decision.
- `governance_decision_pointer` is either a hash-bound reference resolved through the current active pointer → immutable selection → bundle → expanded decision register, or explicit `null`. Only a complete, active, hash-valid chain can supply current governance state, disposition, owner, and decision provenance.

A source row, engineering evidence path, test result, receipt, journal record, or file-presence check must never be promoted into a non-null `governance_decision_pointer`.

## Governance resolution and gates

The schema-v2 ledger records the closed `governance_profile_binding` for the active resolution chain and independently recomputes project G0 across all 268 current governance entries. It compares the complete result with the bound gate register rather than trusting a self-declared gate. Missing rows, changed hashes, stale bindings, or a gate mismatch fail closed.

The following dimensions remain separate:

1. Immutable v1 historical integrity proves the preserved v1 bytes still match their closed hash manifest.
2. Governance-v2 adoption authorizes local governance implementation only.
3. Candidate validity proves a candidate bundle is structurally and cryptographically valid; it does not make the candidate active.
4. Active consumer selection requires a separate operation decision and an atomic, hash-bound pointer transaction.
5. Capability disposition and slice implementation require separate attributable owner decisions under the derived authority tier.
6. Project G0 is the independently recomputed completeness of all required current governance decisions.
7. Hosted/domain acceptance requires separate current evidence and accountable acceptance; it is not implied by local tests or a selected consumer.
8. G3 requires G0 plus current exact-SHA engineering evidence, hosted role UAT, reconciliation, recovery, security, accessibility, performance, defect closure, and owner acceptance.

`gate_summary.g0` and `gate_summary.g3` are therefore independent observations. Engineering or hosted evidence can populate only its permitted evidence dimensions; it cannot appoint an owner, authorize a capability, close G0, or establish G3 acceptance.

Engineering evidence also remains granular: `runtime_availability` distinguishes absence, partial scaffolding, and complete implementation; a test-file reference is not a current automated PASS; and database evidence is recorded separately for SQLite, PostgreSQL 17, MySQL 8.4, and other compatibility runs. MySQL 9.7.1 compatibility never substitutes for MySQL 8.4. Provisional workflow mappings, including the reporting bindings to E2E-15, remain observations rather than approval or readiness.

## Fail-closed pointer behavior

Only an `active`, fully hash-valid v2 consumer chain may produce non-null governance pointers. A missing, unreadable, recovery-required, held, disabled, ambiguous, or otherwise invalid canonical resolution makes all capability governance pointers `null` and forces both G0 and G3 to `OPEN`.

Staleness after an active resolution is different: active-snapshot drift, a ledger binding that no longer matches the canonical pointer, a changed selection/bundle/expanded-register/gate-register hash, a row/order mismatch, or an independently recomputed gate mismatch makes generation or checking fail. The generator must not publish a fresh `OPEN` ledger for that inconsistency, and an older ledger is no longer acceptable as current evidence.

Rollback never silently restores an earlier activation or v1. It publishes a new `rollback_hold` selection with pointer status `held`. Recovery publishes either a new `recovery_hold` selection with status `held` or a new `disabled` selection. Held and disabled states remain non-operative and keep G0/G3 `OPEN` until a fresh, separately authorized activation succeeds. Failed post-publication readback also remains fail closed until explicit recovery.

## Verification

Run from the repository root:

```bash
ruby scripts/generate-g0-g3-coverage-ledger.rb --check --snapshot-date 2026-08-27
ruby scripts/generate-g0-g3-coverage-ledger.rb --check --snapshot-date 2026-08-29 --schema-version 2
ruby scripts/generate-g0-g3-coverage-tariff-successors.rb --kind map --check
ruby scripts/generate-g0-g3-coverage-tariff-successors.rb --kind ledger --check
ruby scripts/generate-g0-g3-coverage-radiology-successors.rb --kind map --check
ruby scripts/generate-g0-g3-coverage-radiology-successors.rb --kind ledger --check
ruby scripts/generate-g0-g3-coverage-laboratory-successors.rb --kind map --check
ruby scripts/generate-g0-g3-coverage-laboratory-successors.rb --kind ledger --check
ruby scripts/generate-g0-g3-coverage-accommodation-successors.rb --kind map --check
ruby scripts/generate-g0-g3-coverage-accommodation-successors.rb --kind ledger --check
ruby scripts/generate-g0-g3-coverage-settlement-successors.rb --kind map --check
ruby scripts/generate-g0-g3-coverage-settlement-successors.rb --kind ledger --check
ruby scripts/generate-g0-g3-coverage-cash-correction-successors.rb --kind map --check
ruby scripts/generate-g0-g3-coverage-cash-correction-successors.rb --kind ledger --check
ruby scripts/generate-g0-g3-coverage-cashier-collection-successors.rb --kind map --check
ruby scripts/generate-g0-g3-coverage-cashier-collection-successors.rb --kind ledger --check
ruby tests/Documentation/G0G3CoverageLedgerTest.rb
ruby tests/Documentation/G0G3CoverageTariffSuccessorsTest.rb
ruby tests/Documentation/G0G3CoverageRadiologySuccessorsTest.rb
ruby tests/Documentation/G0G3CoverageLaboratorySuccessorsTest.rb
ruby tests/Documentation/G0G3CoverageAccommodationSuccessorsTest.rb
ruby tests/Documentation/G0G3CoverageSettlementSuccessorsTest.rb
ruby tests/Documentation/G0G3CoverageCashCorrectionSuccessorsTest.rb
ruby tests/Documentation/G0G3CoverageCashierCollectionSuccessorsTest.rb
```

The original schema-v2 check still proves the exact deterministic historical R4 ledger bytes and selector-bound observation contract. The successor checks prove exact deterministic predecessor bytes through R8/R10, current R9/R11 bytes, predecessor immutability, bounded accommodation, settlement, cash-correction, and cashier-collection engineering deltas, current evidence hashes, and unchanged fail-closed governance. All publisher generations are create-only and refuse to overwrite an existing artifact. No publisher can establish owner acceptance, hosted readiness, deployment, G0, or G3.

All application/patient examples and test fixtures remain synthetic. Governance artifacts may contain attributable decision references and authority metadata, but never patient data or secrets. No ledger, map, decision pointer, engineering result, or gate observation authorizes real patient data or live BPJS, VClaim, SATUSEHAT, payment, LIS, PACS, device, or other external integration.
