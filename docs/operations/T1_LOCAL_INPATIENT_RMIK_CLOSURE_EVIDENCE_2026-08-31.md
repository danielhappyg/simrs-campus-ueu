# Local inpatient RMIK coding, completeness, and closure evidence — 2026-08-31

## Evidence boundary

**Classification: `LOCAL / NOT DEPLOYED`.** This record covers the current uncommitted inpatient RMIK manual-coding, completeness-review, sign-off, and episode-closure slice. It records no commit, push, pull request, hosted migration, deployment, hosted UAT, domain-owner acceptance, G0/G3 closure, production-readiness claim, or parity acceptance.

The proof remained inside the established simulation boundary: synthetic records only, disposable local databases only, no secrets in the evidence, and no live BPJS, VClaim, SATUSEHAT, E-Klaim, terminology catalogue, pharmacy, payment, device, or other external integration.

## Proven workflow and invariants

- An authorized RMIK actor saves manual source-bound coding as an immutable `DRAFT` version. Assignment rows are normalized, ordered, and bound to the exact Final physician-authored diagnosis/procedure statement hashes.
- A no-procedure attestation produces its server-owned normalized evidence row without exposing that row as an editable browser assignment.
- A current zero-blocker completeness review binds the exact source fingerprint, coding version, coding state, and stable coding `content_digest`.
- Sign-off is atomic: it appends an identical-content `FINAL` coding version, binds and changes the review to `SIGNED_OFF`, and moves the encounter from `READY_FOR_RM` to `CLOSED` in one transaction.
- The Draft and Final coding versions share the same stable `content_digest`; operation receipts retain their distinct request `payload_digest` binding. Coding-save receipts have a null review foreign key, while review and sign-off receipts bind the exact review.
- Exact idempotent replay returns the original result. A changed payload under the same key is denied. Same-key concurrent coding and sign-off each produce exactly one `APPLIED` and one `REPLAYED` result, including when both processes pre-read no receipt.
- Stale review, coding, and source bindings; missing assignment coverage; active blockers; corrupt receipt binding; unauthorized actors; and non-ready or closed encounters fail closed.
- Audit failure and receipt failure roll the entire business mutation back atomically.
- Immutable coding versions, assignments, reviews, review items, and receipts refuse direct update/delete SQL. The runtime identity has only `SELECT, INSERT` on this append-only evidence; mutable encounter and coding-head locks remain available without widening immutable-table privileges.
- Bounded synthetic reset removes the synthetic RMIK chain while retaining correlated audit evidence. Empty down/reapply succeeds, while down migration refuses retained business rows and retained correlated audit evidence.

## Closed exact-engine catalogue

PostgreSQL 17.10 and MySQL 8.4.11 both passed the same 21 scenarios:

1. fresh migration;
2. empty down/reapply;
3. retained-business-row down refusal;
4. source-bound Draft coding;
5. normalized assignment rows;
6. current zero-blocker review;
7. atomic Final sign-off and closure;
8. exact idempotent replay;
9. changed-payload key conflict;
10. stale review, coding, and source denials;
11. missing-assignment and blocker denial;
12. corrupt receipt-binding denial;
13. concurrent same-key coding race;
14. concurrent same-key sign-off race;
15. audit-failure atomic rollback;
16. receipt-failure atomic rollback;
17. append-only engine refusal;
18. least-privilege runtime;
19. bounded synthetic reset;
20. retained-audit down refusal; and
21. final invariant verification.

Both race scenarios used two independent application processes, observed real database waiting, required the exact `APPLIED` plus `REPLAYED` outcome pair, and performed durable verification from a third connection.

## Local quality gates and bounded review

| Gate | Result |
| --- | --- |
| Dedicated Ruby harness contract | PASS — 9 tests; 148 assertions |
| Focused PHP workflow and SQL-guard slice | PASS — 12 tests; 131 assertions |
| Focused frontend component slice | PASS — 8 tests |
| Focused TypeScript, ESLint, and Prettier checks | PASS |
| PostgreSQL 17.10 exact rehearsal | PASS — 21/21 scenarios |
| MySQL 8.4.11 exact rehearsal | PASS — 21/21 scenarios |
| Independent bounded P0–P2 review | CLEAN after the corrections recorded below |

The bounded review covered role/capability enforcement, exact browser POST projection, current-state refresh, source and assignment binding, explicit Draft/Final state, digest semantics, review currency, idempotency and receipt binding, race convergence, audit atomicity, append-only database protection, reduced runtime privileges, migration lifecycle, and synthetic reset ordering.

The review found and closed the following material issues before the final replacement runs:

- explicit Final coding state and stable Draft-to-Final content identity were completed;
- post-lock same-key replay reconciliation was added so a race loser replays rather than returning a stale/closed denial;
- the RMIK schema-mutation scope was added to the protected SQL guard so fresh migration can create the source-binding foreign key safely;
- controller projection and browser payload round trips were aligned, including assignment kind/text, no-procedure filtering, refreshed version/digest bindings, and the 255-character description limit;
- an exact PostgreSQL run exposed `FOR UPDATE` reads on immutable history under a `SELECT, INSERT` runtime. Those immutable reads were changed to plain reads while locks remain on the mutable encounter and coding head. Both replacement exact engines then passed without widening immutable grants.

There is no remaining actionable P0, P1, or P2 finding in this bounded node review.

## Final matched exact-engine records

Both final records report `PASS`, contain 21 PASS scenarios and no failed scenario, are mode `0600`, contain 21 safe logical commands and 11 protocol results, and confirm database, temporary server, temporary user-state, and temporary worker cleanup. They match the current source and share:

- source aggregate SHA-256 `d09f4d9428e2ac8c514c038bd6dbc7d76910b0465cd96a57815327691feb176f`;
- embedded worker SHA-256 `548acc1798b28a6f8b41b3e8444978db2e31c65659246618f77a6276f0522d79`; and
- scenario-catalogue SHA-256 `dcf49dd6019788b57060a43a472c63c6314ab584ba1041c0215a53417b03b8c3`.

- PostgreSQL 17.10 / `laravel` schema: `storage/app/portability-rehearsals/20260831T133353Z-postgresql17-inpatient-rmik-8dfd320e3d0f.json`; artifact SHA-256 `f9fad0a2c8399cd3773d57ce32737a397aba89bb1926d138be38353a8ec4374b`.
- MySQL 8.4.11 / InnoDB: `storage/app/portability-rehearsals/20260831T133429Z-mysql8411-inpatient-rmik-5fc5316ac9e3.json`; artifact SHA-256 `81b75d121a9a2a2a397c4a366d5680a431734572f03fa6a486837faec5eb2667`.

Earlier RMIK rehearsal attempts, including the first two PASS records bound to source aggregate `c2c71766dabf0fa8a93daac74932924cde4e0371a375a897e192da14f1ca9287`, are superseded and must not be used as final evidence.

## Remaining boundary

This local proof does not establish terminology-catalogue integration, automated clinical coding, billing or claim correctness, hosted Supabase compatibility, pooled/proxy behavior, browser UAT, backup/restore, load, capacity, SLA, domain-owner acceptance, G0/G3 closure, parity, or production readiness. Commit, push, deployment, and hosted migration remain separate release decisions and were intentionally not performed here.
