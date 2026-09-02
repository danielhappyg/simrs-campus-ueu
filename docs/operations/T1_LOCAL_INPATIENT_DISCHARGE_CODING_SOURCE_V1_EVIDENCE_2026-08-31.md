# Local inpatient discharge coding source v1 evidence — 2026-08-31

## Evidence boundary

**Classification: `LOCAL / NOT DEPLOYED`.** This record covers the current uncommitted `INPATIENT_DISCHARGE_CODING_SOURCE_V1` slice and its integration with inpatient transfer and atomic routine discharge. It records no commit, push, pull request, GitHub Actions run, Vercel deployment, hosted Supabase migration, hosted UAT, domain-owner acceptance, G0/G3 closure, production readiness, or SIMRS Sahabat parity acceptance.

The proof remained inside the established simulation boundary: synthetic records only, no secrets in the evidence, and no live BPJS, VClaim, SATUSEHAT, E-Klaim, ICD catalogue, pharmacy, payment, device, or other external integration.

## Proven workflow and invariants

- An assigned actor with the exact physician role and capability can save an immutable Draft and Finalize that same episode's **Diagnosis dan prosedur akhir** source. RMIK and other non-physician roles cannot author it.
- The source captures the physician's principal-diagnosis statement, optional secondary-diagnosis statements, and explicit procedure attestation/statements. It is a physician-authored source for later coding, not an ICD-coded result, RMIK sign-off, completeness decision, or record closure.
- Validation, expected-version checks, original-author ownership, terminal Final refusal, and exact `DISCHARGE_CODING_SOURCE_DRAFT_SAVE` / `DISCHARGE_CODING_SOURCE_FINALIZE` identifiers fail closed.
- Exact idempotency retry replays the original result. Changed payload under the same key and corrupted receipt/result/version/digest binding are denied.
- Every version stores a coherent server-derived placement snapshot. Real location history binds event identity/type and a complete sequence; a retained pre-history episode uses an explicit sequence-0 legacy baseline without fabricating an event.
- Two competing Finalization processes produce one Final and one denial. Durable third-connection verification confirms one terminal source chain.
- Final-source-first denies a later bed transfer under the terminal rule. Transfer-first completes the transfer, then Finalization binds coherently to the new current placement. Both races use independent application processes, native database waiting, and no harness bed pre-lock.
- Atomic discharge is denied before the coding source is Final, then succeeds after both the discharge summary and coding source are Final. The discharge and replay receipt bind the exact source/version public identities plus content and provenance digests.
- Audit failure and receipt failure roll the coding-source mutation back atomically.
- Immutable version and receipt evidence refuses update/delete, and refuses truncate as supported by each engine's trigger/privilege model. Runtime access is limited to `SELECT, INSERT`; owner, runtime, and bounded-reset identities are separate.
- Bounded reset removes the synthetic coding-source chain without broadening reset authority. Populated rollback refuses destructive down migration while retained evidence or bindings remain.

## Closed exact-engine catalogue

PostgreSQL 17.10 and MySQL 8.4.11 both passed the same 19 scenarios:

1. fresh migration;
2. empty down/reapply;
3. Draft then Final;
4. validation and authority denials;
5. terminal Final refusal;
6. exact idempotent replay;
7. changed-payload key conflict;
8. corrupt replay-binding denial;
9. discharge Final-source gate;
10. same-source competing Finalization;
11. transfer versus source, source first;
12. transfer versus source, transfer first;
13. audit-failure atomic rollback;
14. receipt-failure atomic rollback;
15. append-only engine refusal;
16. least-privilege runtime;
17. bounded synthetic reset;
18. populated-evidence down refusal; and
19. final invariant verification.

## Local quality gates and bounded review

| Gate | Result |
| --- | --- |
| Full backend suite | PASS — 694 tests |
| Full frontend suite | PASS — 103 tests |
| Dedicated coding-source harness contract | PASS — 8 tests; 126 assertions |
| Full portability-suite source-binding contract | PASS — 14 tests; 180 assertions |
| Working-tree diff check | PASS |
| Independent bounded application review | CLEAN after the MySQL down-order correction — no residual actionable P0–P2 finding |

The bounded review covered authorization ordering and exact-role policy, original-author and Final semantics, canonical bed/encounter/ward/bed locking, transfer terminal behavior, current-placement provenance, discharge source/version/digest binding, replay verification, audit atomicity, immutable database protection, least-privilege grants, reset coverage, and cross-engine down ordering. The review found one real MySQL portability defect: the first exact run attempted to drop coding-binding columns before their named `CHECK` constraints. The migration was corrected to drop those constraints first with engine-specific syntax, and both replacement exact runs passed. Earlier attempts are superseded and must not be used as evidence.

## Final matched exact-engine records

Both replacement records report `PASS`, contain 19 PASS scenarios and no failed scenario, are mode `0600`, contain 22 safe logical commands and 10 protocol results, and confirm database, temporary server, temporary user-state, and worker cleanup. They share source aggregate SHA-256 `e26543beb254fa1e8e403a2fcbd822dfc04b6a0bb8b56eeb849c5cbf03c17c56`, worker SHA-256 `2647be018cf5a8b24844ee7293462cbe16b35a5e44e13599b317a64b3ce02904`, and scenario-catalogue SHA-256 `e490ae54c95236343cf5c70a994ca32cbcbef76107ef0a407e4b01f908f9243e`.

- PostgreSQL 17.10 / `laravel` schema: `storage/app/portability-rehearsals/20260831T071216Z-postgresql17-inpatient-discharge-coding-source-262c67c32cd9.json`; artifact SHA-256 `055b826cbbaf0919d76981b024551e0cb53f212a254de9c89c835cd786f6d8c9`.
- MySQL 8.4.11 / InnoDB: `storage/app/portability-rehearsals/20260831T071221Z-mysql8411-inpatient-discharge-coding-source-3cddf4eaa5e8.json`; artifact SHA-256 `b50df518ff05b5a5034d7b786d00a3d607f45bf19a35abf06a86ff4fd072889d`.

## Remaining boundary

This local proof does not establish ICD catalogue integration, RMIK completeness/coding/sign-off/closure, final billing, claim preparation, reporting correctness, hosted Supabase compatibility, pooled/proxy behavior, browser UAT, backup/restore, load, capacity, SLA, owner acceptance, G0/G3 closure, parity, or production readiness. Commit, push, deployment, and hosted migration remain separate release decisions and were intentionally not performed here.
