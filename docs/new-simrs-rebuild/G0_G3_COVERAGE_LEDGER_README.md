# G0–G3 coverage ledger

The coverage ledger is a deterministic, observation-only inventory for the 268 assessed capabilities and the canonical E2E-01 through E2E-16 workflows. It reports governance and delivery evidence; it does not create authority, activate a governance consumer, authorize a capability or slice, deploy an application, or accept G0 or G3.

## Current state

Governance v2 has been adopted only for local implementation. The first dated 2026-08-29 Wave 6 artifact observed no canonical active consumer pointer or active selection. It records `governance_profile_binding.status=unavailable`, `reason_code=pointer_missing`, `gate_summary.g0.status=OPEN`, and `gate_summary.g3.status=OPEN`; every `governance_decision_pointer` is `null`.

That first schema-v2 artifact is now an immutable observation bound to validator contract 1.0.0. The create-only [`G0_G3_COVERAGE_LEDGER_V2_2026-08-29_R2.json`](G0_G3_COVERAGE_LEDGER_V2_2026-08-29_R2.json) successor is bound to validator contract 1.2.0. The create-only [`G0_G3_COVERAGE_LEDGER_V2_2026-08-29_R3.json`](G0_G3_COVERAGE_LEDGER_V2_2026-08-29_R3.json) successor is bound to validator contract 1.3.0 and is the current schema-v2 observation. The original and R2 remain immutable historical evidence and are not rewritten or reinterpreted by R3.

This is an expected fail-closed observation. It is not evidence of activation, deployment, hosted acceptance, domain acceptance, or release readiness.

## Files and version boundary

- [`G0_G3_COVERAGE_EVIDENCE_MAP_2026-08-27.json`](G0_G3_COVERAGE_EVIDENCE_MAP_2026-08-27.json) and [`G0_G3_COVERAGE_LEDGER_2026-08-27.json`](G0_G3_COVERAGE_LEDGER_2026-08-27.json) are immutable schema-v1 historical snapshots. The preserved 2026-08-26 map and ledger are older historical snapshots. Governance v2 does not rewrite or reinterpret their bytes.
- [`G0_G3_COVERAGE_EVIDENCE_MAP_V2_2026-08-29.json`](G0_G3_COVERAGE_EVIDENCE_MAP_V2_2026-08-29.json) is a closed engineering-evidence overlay. It cannot contain owner, approval, disposition, tier, pointer, or gate authority.
- [`G0_G3_COVERAGE_LEDGER_V2_2026-08-29.json`](G0_G3_COVERAGE_LEDGER_V2_2026-08-29.json) is the first pointer-aware, dated schema-v2 observation. Its artifact ID is `G0-G3-COVERAGE-LEDGER-V2-2026-08-29`, its exact SHA-256 is `690becdf75a08d17b992d8dad754313f33d0f9c8ea2c692b9b12b8b837f43ac5`, and it must never be edited, regenerated, or reinterpreted.
- [`G0_G3_COVERAGE_LEDGER_V2_2026-08-29_R2.json`](G0_G3_COVERAGE_LEDGER_V2_2026-08-29_R2.json) is the append-only successor. Its artifact ID is `G0-G3-COVERAGE-LEDGER-V2-2026-08-29-R2`, its exact SHA-256 is `0b89705741bb052629593b9023f1c8d19c7827489a7e8e6ce6e46af1efd5f1c6`, and its closed `sources.superseded_ledger` record binds the predecessor path, SHA, artifact ID, and `supersedes_without_rewriting_or_reinterpreting_predecessor` relationship.
- [`G0_G3_COVERAGE_LEDGER_V2_2026-08-29_R3.json`](G0_G3_COVERAGE_LEDGER_V2_2026-08-29_R3.json) is the next append-only successor. Its artifact ID is `G0-G3-COVERAGE-LEDGER-V2-2026-08-29-R3`, its exact SHA-256 is `0f312713000b0092de313baee2d3cc6d34695c2fefd717c620871268045ac58c`, and its closed `sources.superseded_ledger` record binds R2 by exact path, SHA, artifact ID, and the same non-rewrite relationship.
- [`scripts/generate-g0-g3-coverage-ledger.rb`](../../scripts/generate-g0-g3-coverage-ledger.rb) retains the schema-v1 path and provides the separate schema-v2 R3 generator path. It verifies both immutable prior observations and the exact original → R2 chain before building R3. Generation never mutates either predecessor, the consumer pointer, or any selection.

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
ruby tests/Documentation/G0G3CoverageLedgerTest.rb
```

The schema-v2 check targets R3 and requires its exact deterministic bytes. The generator rejects duplicate keys, unknown fields or IDs, unsafe paths, symlinks, stale or mismatched predecessor hashes, incomplete capability/workflow catalogues, secret-looking content, and inconsistent gate results. Schema-v1 retains its existing same-directory atomic replacement behavior and retired-prototype exclusions. Schema-v2 emits deterministic bytes only to its new R3 artifact and refuses to overwrite an existing output. Schema-v2 generation is read-only with respect to governance selection and is valid as current evidence only while its complete profile binding still matches the canonical pointer state.

All application/patient examples and test fixtures remain synthetic. Governance artifacts may contain attributable decision references and authority metadata, but never patient data or secrets. No ledger, map, decision pointer, engineering result, or gate observation authorizes real patient data or live BPJS, VClaim, SATUSEHAT, payment, LIS, PACS, device, or other external integration.
