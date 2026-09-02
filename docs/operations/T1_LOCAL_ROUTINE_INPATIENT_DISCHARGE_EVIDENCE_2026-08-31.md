# Local routine inpatient discharge evidence — 2026-08-31

## Evidence boundary

**Classification: `LOCAL / NOT DEPLOYED`.** This record covers the current uncommitted atomic Routine Inpatient Discharge slice and its integration with the immutable Final discharge summary, inpatient placement history, bed occupancy, audit, replay receipts, and bounded reset. It records no commit, push, pull request, GitHub Actions run, Vercel deployment, hosted Supabase migration, hosted UAT, owner acceptance, G0/G3 closure, production readiness, or SIMRS Sahabat parity acceptance.

The proof remained inside the established simulation boundary: synthetic records only, no secrets in the evidence, and no live BPJS, VClaim, SATUSEHAT, E-Klaim, pharmacy, payment, device, or other external integration.

## Proven workflow and invariants

- A current inpatient episode with an exact immutable Final discharge-summary version can be discharged atomically.
- The discharge records the exact Final version and its content/provenance digests, changes the encounter to `READY_FOR_RM`, preserves the last placement snapshot for records work, and releases the active bed claim.
- Missing, Draft, stale-summary, and stale-placement submissions fail closed.
- An identical idempotency retry replays the original result; changed payload under the same key and corrupt receipt/result binding are denied.
- Two competing discharge processes produce one applied result and one denial, one discharge, one receipt, and one released bed.
- When Finalization wins the Final-versus-transfer race, transfer is denied by the terminal rule and discharge can follow. When transfer wins, Finalization binds coherently to the new placement and discharge can follow.
- A real admission waiting on the same canonical bed mutex succeeds only after the prior discharge releases that bed. Fresh third-connection checks verify the released-bed reuse and durable state.
- Audit failure and receipt failure roll back the discharge, receipt, success audit, encounter transition, and bed release together.
- Immutable discharge and receipt rows refuse runtime update, delete, and truncate. PostgreSQL owner triggers also refuse all three operations; MySQL owner triggers refuse update/delete while runtime privileges refuse truncate because MySQL has no truncate trigger.
- Runtime access is least privilege, with immutable history limited to `SELECT, INSERT`; migration owner and bounded-reset identities remain separate.
- Bounded reset deletes only the synthetic discharge chain and related synthetic episode data while retaining required audit/reset evidence. Populated rollback refuses destructive down migration while correlated evidence remains.

## Closed exact-engine catalogue

PostgreSQL 17.10 and MySQL 8.4.11 both passed the same 19 scenarios:

1. fresh migration;
2. empty down/reapply;
3. successful routine discharge;
4. missing/Draft/stale summary denials;
5. stale placement denial;
6. exact idempotent replay;
7. changed-payload key conflict;
8. corrupt replay-binding denial;
9. same-discharge competing execution;
10. Final-versus-transfer, Final first;
11. Final-versus-transfer, transfer first;
12. released-bed reuse;
13. audit-failure atomic rollback;
14. receipt-failure atomic rollback;
15. append-only engine refusal;
16. least-privilege runtime;
17. bounded synthetic reset;
18. populated-evidence down refusal; and
19. final invariant verification.

All four race scenarios used two independent application processes, observed a native database wait, used no harness bed pre-lock, reported no deadlock, and completed durable verification through a third connection.

## Contract gates

| Gate | Result |
| --- | --- |
| Ruby harness syntax | PASS |
| Embedded PHP worker syntax | PASS |
| Dedicated discharge harness contract | PASS — 8 tests; 132 assertions |
| Full portability-suite contract with discharge source binding | PASS — 13 tests; 168 assertions |
| Working-tree scope check | PASS — harness, contract, full-suite binding, and this evidence record only for this workstream |

## Final exact-engine records

Both records report `PASS`, contain 19 PASS scenarios and no failed scenario, are mode `0600`, contain 25 safe logical commands and 11 protocol results, and confirm database, temporary server, temporary user state, and worker cleanup. They share source aggregate SHA-256 `53ca4d5983c93ce7115490635c00803a67a219efd24cd722cba8bf990298887b`, worker SHA-256 `8e78b3c7a50ff54c674e6dd49bbf3c579489b8160c53b27e4365063113317378`, and scenario-catalogue SHA-256 `9c97a170705bf09f925aa674137da36898a8479ae6a6c7312539a1bc108552a1`.

- PostgreSQL 17.10 / `laravel` schema: `storage/app/portability-rehearsals/20260831T055136Z-postgresql17-inpatient-discharge-862c95c39f5d.json`; artifact SHA-256 `e47d78aff57f4054405f838a9c77ca63fe564eda603dda71d52c56c0e0267b41`.
- MySQL 8.4.11 / InnoDB: `storage/app/portability-rehearsals/20260831T055139Z-mysql8411-inpatient-discharge-e9154cd7736b.json`; artifact SHA-256 `19a0a5e02b0cd9c1f135c603a9d232f7d26e934b4b3a866f245b131a33136ee1`.

## Remaining boundary

This local proof does not establish hosted Supabase migration compatibility, pooled/proxy behavior, browser UAT, backup/restore, load, capacity, SLA, clinical/registration/RMIK acceptance, final-bill or coding completion, claim preparation, reporting correctness, G0/G3 closure, parity, or production readiness. Commit, push, deployment, and hosted migration remain separate release decisions and were intentionally not performed here.
