# G0 governance v2 independent technical/security review — 2026-08-29

**Review ID:** `G0-GOV-V2-INDEPENDENT-REVIEW-2026-08-29-CODEX-HUBBLE-2`
**Review time:** `2026-08-29T04:06:17+07:00`
**Reviewer:** Independent Codex technical/security reviewer
**Agent path:** `/root/ci_portability_review`
**Agent name:** `Hubble the 2nd`
**Reviewer nature:** AI agent; not a human, product owner, domain owner, institutional authority, deployment approver, or G3 acceptor.
**Verdict:** `PASS`
**Verdict scope:** Gate A local governance-v2 implementation readiness only
**Authority effect:** None. This review is technical/security evidence and does not itself confer adoption or implementation authority.

## Reviewed artifacts

| Artifact | Exact reviewed SHA-256 | Source status in the reviewed bytes |
| --- | --- | --- |
| `docs/adr/ADR-018-PROPORTIONAL-G0-GOVERNANCE-PROFILE.md` | `cd7834e7f5b0be99acee3f1b46f8af9fc81b77418541fddf7276fadc26edf962` | `Proposed / not approved / no implementation authority` |
| `docs/new-simrs-rebuild/phase-0/G0_PROPORTIONAL_GOVERNANCE_V2_PROPOSAL_2026-08-28.md` | `f5c635006f878a68c4b0775be5262115fe38e185562d3be558e02d8b28c38695` | `PROPOSAL / NOT APPROVED / NOT AUTHORITATIVE` |

No canonical adoption-decision draft or record was used as review evidence. This review assesses only the exact proposal and ADR bytes above. A later revision to either artifact requires a new hash-bound independent review.

## Authority boundary

This PASS means that I found no blocking technical/security defect in the design for Gate A local implementation of governance schemas, validators, deterministic migration tooling, fixtures, and tests within the synthetic teaching boundary. It does not:

- adopt governance v2 or substitute for an attributable product-owner adoption decision;
- appoint me or any agent as a product, clinical, RMIK, nursing, laboratory, radiology, pharmacy, finance, security, privacy, or other domain authority;
- authorize consumer activation or treat observation-only candidate validation as activation;
- approve any capability disposition, family decision, slice, or application workflow implementation;
- authorize hosted migration, deployment, real patient data, or a live integration;
- close G0, establish owner acceptance, or establish G3 acceptance; or
- convert engineering evidence, tests, receipts, ledgers, or this review into owner authority.

## Review method

1. Recomputed SHA-256 over the two reviewed working-tree files and confirmed the exact values recorded above.
2. Read the complete proposal and ADR and mapped the authority gates, closed contracts, data flows, mutation boundary, failure modes, verification matrix, and delivery sequence.
3. Performed adversarial analysis of malformed and duplicate JSON, stale or conflicting hashes, under-tiered decisions, mixed-risk family expansion, authority substitution, path traversal, symlink and file-type attacks, unsupported filesystems, concurrent mutation, partial writes, crash points, unreadable pointers, rollback/recovery misuse, stale ledger bindings, governance-field injection, and secret leakage.
4. Checked that historical v1 artifacts remain separately hash-bound and runnable, and that v2 migration and profile selection cannot reinterpret v1 as activated authority.
5. Ran the current ADR contract (`12` runs, `481` assertions, no failures), proposal contract (`7` runs, `183` assertions, no failures), and v1 integrity validator (`268` requirements, `268` batch assignments, `268` governed decisions; passed).

## Findings

No P1 or P2 blocking finding was identified for Gate A local implementation only.

### 1. Fail-closed machine contracts — PASS

The design requires closed schemas, recursive duplicate-key rejection, exact ordered enums and consequence flags, strict types, exact source hashes, deterministic tier derivation, complete 268-row expansion, append-only correction history, and rejection of unknown, missing, stale, conflicting, or under-tiered inputs. Formal gates cannot consume an invalid or ambiguous bundle or pointer.

### 2. Historical v1 preservation — PASS

The ADR defines a closed ordered 30-file v1 hash inventory, preserves the v1 validator and negative suites, imports only immutable source facts with every owner decision still pending, and requires dual-run parity. V1 is historical never-activated evidence and is never a rollback or recovery activation target.

### 3. Canonicalization and provenance — PASS FOR IMPLEMENTATION

The design requires deterministic canonical JSON, exact proposal/ADR and source hashes, canonical manifest order, source-row hashes, validator-contract binding, exclusive output creation, readback, and refusal to overwrite. Validator constants must be cross-checked against the machine contract so prose and implementation cannot drift silently.

### 4. Authority and state separation — PASS

Adoption, consumer activation, capability/slice decisions, delivery, evidence, hosted acceptance, deployment, G0, and G3 are distinct dimensions and gates. Product and affected-domain authority cannot be replaced by an agent, implementer, test, reviewer, evidence map, or ledger. Triggered T2 and all T3 independent reviewers are separated from the executor and every event author and approver.

### 5. Canonical paths, symlinks, and atomic durability — PASS FOR IMPLEMENTATION

Mutation is restricted to exact repository-root paths and rejects traversal, symlinks at any component, non-regular files, caller overrides, cross-device staging, and noncanonical roots outside guarded fixtures. Activation, rollback, disable, and recovery require a capability probe for exclusive creation, advisory locking, same-device atomic rename, file and directory `fsync`, and durable readback; unsupported Windows or network filesystems remain validation-only.

### 6. Rollback, recovery, and concurrency — PASS FOR IMPLEMENTATION

One exclusive lock serializes mutation. Immutable selections and a hash-chained journal are durably created before pointer publication. Pre-rename failure preserves the previous pointer; post-rename readback failure makes consumers fail closed. Rollback and recovery publish new reachable `rollback_hold`, `recovery_hold`, or `disabled` selections that force G0/G3 open. They never reactivate v1, and reuse requires a fresh activation decision.

### 7. Ledger and evidence separation — PASS

The schema-v2 ledger keeps immutable source pointers separate from active governance pointers, independently recomputes G0 across all 268 rows, rejects stale pointer/selection/bundle/register bindings, and keeps G0 distinct from G3. The evidence-map producer is engineering-only and is structurally forbidden from emitting owner, approval, tier, disposition, pointer, or gate fields.

### 8. Secrets, synthetic data, and live-system boundaries — PASS

The proposal and ADR prohibit private keys, passwords, tokens, connection strings, and secret content in repository artifacts or receipts. V2 is synthetic-only and cannot authorize real patient data or live BPJS, VClaim, SATUSEHAT, payment, LIS, PACS, device, or other production integration. Errors report identifiers or locations without exposing values.

### 9. CI and negative-test plan — PASS FOR IMPLEMENTATION

The verification matrix covers closed-schema negatives, exact machine-contract parity, deterministic 268-row migration, family/tier/authority adversarial cases, immutable history, v1 preservation, dual-run drift, separate activation authority, concurrency and crash injection at each durability boundary, rollback/recovery, canonical paths and symlinks, evidence-map separation, independent ledger recomputation, and deterministic CI ordering. Activation remains a later decision after implementation evidence and observation-only dual comparison.

### 10. Maintainability — PASS

The design uses Ruby standard library only, separates validation, generation, migration comparison, dispatch, selection, evidence-map, and ledger responsibilities, and avoids a database, queue, network service, or institutional PKI dependency. The cost of maintaining v1 and v2 suites during migration is explicit and proportionate to preserving history and rollback safety.

## Residual risks and mandatory follow-through

These are not Gate A design blockers, but they remain unproven until implementation and must fail closed:

1. Canonical JSON behavior, nested duplicate rejection, exact encoding/newline behavior, and deterministic byte output require executable golden and adversarial tests.
2. Filesystem capability probes and durability semantics vary by operating system and filesystem; unsupported or uncertain environments must remain validation-only.
3. Concurrency, signal/crash injection, `O_EXCL`, file `fsync`, both selection/journal directory `fsync` operations, pointer rename, pointer-directory `fsync`, and post-rename readback require real fixture tests before activation.
4. Rollback and unreadable/missing-pointer recovery require reachable held/disabled selections, exact prior-state derivation, hash-chain validation, and stale-authorization tests.
5. The complete 30-file v1 manifest and exact 268-row migration comparison must be generated and independently verified without rewriting historical bytes.
6. CI must retain all v1 compatibility tests and add every v2, dual-run, pointer, evidence-map, ledger, and this independent-review contract without `continue-on-error`; runtime growth must be monitored.
7. Product and affected-domain owners remain necessary for capability decisions. This technical review supplies no domain authority and cannot close any of the 268 pending decisions.
8. Any request involving production, real data, or live integration is outside this review and requires a separate governance instrument and new technical/security review.

## Verdict

**PASS — Gate A local governance-v2 implementation only.**

The exact reviewed proposal and ADR provide a technically coherent, security-conscious, fail-closed basis for local implementation of the governance-v2 machinery and tests. This PASS is review evidence only. It becomes relevant to Gate A only alongside a separate attributable product-owner adoption decision bound to the same exact hashes. Consumer activation, capability authority, application workflow implementation, deployment, real data, live integrations, G0 closure, and G3 acceptance remain prohibited or separately governed.
