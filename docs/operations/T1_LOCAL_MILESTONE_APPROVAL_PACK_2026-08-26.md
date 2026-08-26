# T1 Local Milestone Approval Pack — 2026-08-26

## Decision summary

**Current state: `LOCAL / NOT_DEPLOYED`.** This pack now binds 33 unpublished candidate paths for the IGD/RI teaching-continuity and accessibility follow-up on the published base `1e188215ff4b39af547cda75a1904cfe1d062f75`. The generator requires `HEAD`, `origin/main`, and that exact base to match and requires an empty staging area. The JSON manifest binds every included modified, untracked, and deleted path by SHA-256; deleted paths, when present, bind the bytes in `HEAD` because no worktree bytes remain.

The manifest excludes itself from self-hashing. It also excludes `deliverables/`, `docs/legacy-visual-field-capture/`, `docs/operations/UAT_20260820_002_003_DRAFT_ORDER_REMEDIATION.md`, and `lang/` before file inspection. The generator never opens, hashes, or adds a protected path.

No new commit, push, pull request, GitHub Actions run, deployment, hosted migration, credential transmission, or hosted mutation is authorized or recorded by this follow-up pack. The base commit already has a verified Vercel Preview; the public production alias remains a separate cutover decision.

## What the manifest proves

- The repository and staging preconditions held when the manifest was generated.
- The publishable dirty-tree inventory was parsed from NUL-delimited Git status output.
- Included worktree files and deleted `HEAD` blobs were regular, non-symlink paths contained by the repository and passed the generator's suspicious-name and secret-material gates.
- Five exact ignored, mode-`0600` local database records are bound by path and SHA-256 without embedding their content:
  - daily queue concurrency: local atomic allocation and rollback-reuse evidence only;
  - query plans: local single-user plan-topology evidence only; and
  - recovery: local same-host disposable backup/restore evidence only;
  - current-manifest harness-owned PostgreSQL 17.10 private-cluster fresh migration, full-suite, and eight focused workflow slices; and
  - current-manifest exact MySQL 8.4.11 fresh migration, full-suite, and the same eight focused workflow slices.

These are integrity bindings, not a claim that ignored files belong in a future commit.

## Boundaries that remain open

- **G0 — OPEN:** formal institutional authority, named owner decisions, and governance verification remain incomplete.
- **G3 — OPEN:** the current coverage ledger keeps the formal gate open; runtime, automated evidence, hosted UAT, reconciliation, and owner acceptance remain incomplete.
- **Exact-engine portability — LOCAL PASS FOR BOUND BACKEND BYTES:** a harness-owned PostgreSQL 17.10 cluster using a private Unix socket and an isolated exact MySQL 8.4.11/InnoDB server passed fresh migration, the full PHP suite, and eight focused workflow slices against identical backend, migration, harness, workflow-catalogue, and pre-run manifest bindings. Identical pre/post binding checks and the manifest's semantic inner-record gate reject drift. The host used PHP 8.5.7 rather than CI PHP 8.3. Hosted execution, PHP 8.3 equivalence, MySQL queue contention, cross-setting independent-process clinical locking, load, and owner acceptance remain open.
- **Hosted UAT — NOT RUN:** no authenticated role-based hosted workflow was exercised for this milestone.
- **Owner acceptance — NOT ACCEPTED:** engineering evidence and reference presence do not substitute for owner approval.
- **Accessibility — OPEN/PARTIAL:** four representative operational page states and first/middle/last pagination states have current local axe/semantic evidence. A disposable native-browser rehearsal additionally passed one real validation-focus response, three contrast samples, and four-route reflow at 320 CSS pixels after finding and fixing a page-overflow defect. Complete keyboard traversal, screen reader, native 200% zoom, full contrast/focus/touch coverage, hosted UAT, and owner acceptance remain open.
- **Security findings — THREE EARLIER LOCAL P3 FINDINGS OPEN:** an earlier changed-source security snapshot covered 42 of 42 then-current inventory files and found zero P0/P1/P2 findings and three low/P3 candidates: account-existence disclosure during password reset, unbounded total work in the outpatient recap CSV export, and origin-trusting pagination redirects. No remediation has been authorized or applied. That snapshot is not relabelled as a complete review of the later 137-file milestone. A separate defensive review of the portability harness found and drove closure of stale-binding, local-tunnel, executable-path, cleanup, and evidence-directory issues before the retained final pair. Hosted proxy, load, and edge behavior remain unproven. The previously recorded blocking hosted legacy audit row remains a separate cutover precondition.
- **BG-03 — LOCAL EXACT-ENGINE COMPARISON PASS ONLY:** an independent read-only review found the non-authoritative observer preserves every legacy authorization result, keeps `off` query/telemetry free, rejects malformed timing/TTL/assurance/runtime/session facts, sanitizes telemetry, and has a SQLite-local resolver ceiling of ten queries. The current E2E-16 slice, including BG-03, passes PostgreSQL 17 and MySQL 8.4 locally. Hosted observation, engine-specific load acceptance, activation, enforcement, and cutover remain `NOT_RUN` or unauthorized.
- **Cross-setting encounter cancellation — PROPOSED / NOT AUTHORIZED:** a decision-ready CAN-01 through CAN-20 contract and ADR now expose the required Registration, Patient Identity, Scheduling, IGD, Bed Management, Clinical, RMIK, Reporting, downstream-domain, data/reset, and technical choices. No `CANCELLED` state, route, migration, UI, or runtime behavior has been implemented; named authority rows remain blank.
- **Publication/deployment/migration — FALSE:** no current GitHub publication, GitHub Actions execution, Vercel deployment, or hosted database migration is claimed.

## Deterministic verification

Run from the fixed repository root with no staging changes:

```bash
ruby scripts/generate-t1-local-milestone-manifest.rb --check
ruby tests/Documentation/T1LocalMilestoneManifestTest.rb
git diff --check
```

If only evidence-neutral documentation bytes change, deliberately refresh and check the ordinary manifest:

```bash
ruby scripts/generate-t1-local-milestone-manifest.rb --write
ruby scripts/generate-t1-local-milestone-manifest.rb --check
```

If any harness-defined execution byte changes, ordinary `--write` and `--check` fail closed. The authorized local sequence is `--write-inventory`, `--check-inventory`, fresh PostgreSQL/MySQL rehearsals, evidence-path replacement, then ordinary `--write` and `--check`. Inventory mode says `UNVERIFIED_FOR_BOOTSTRAP` and cannot emit a portability PASS claim. The generator accepts only those four closed actions; it accepts no caller-selected repository root, output path, date, evidence path, or deployment target. A failed ordinary check means the batch must not be treated as current.

## Current local verification result

| Verification | Result |
| --- | --- |
| PHP application suite | 453 tests; 451 passed, 2 skipped; 5,856 assertions |
| PHPStan | 0 errors |
| Frontend unit tests | 12 files, 45 tests, PASS |
| Bounded accessibility automation | Current RJ/IGD/RI registration, IGD/RI clinical-note, worklist, laboratory, recap and pagination states: zero detected axe WCAG 2.1 A/AA violations in tested DOM states; named/linked error summaries, repeated failed-submit focus, semantic tabs and unambiguous row actions PASS; aggregate accessibility remains `OPEN/PARTIAL` |
| Bounded native-browser accessibility | Registrar, nurse, physician and RMIK route/permission surfaces across IGD registration, triage, IGD detail/worklist and RI registration/detail/worklist rendered with simulation banners, labels/captions and no console errors; bounded IGD/RI note journeys and the corrected real IGD multi-error summary/focus path passed; 320-pixel and 200%-equivalent proxy reflow checks passed with intentional table scrolling contained; screen reader, complete keyboard order, true browser zoom, full contrast and full touch-target coverage remain `NOT_RUN` |
| TypeScript, ESLint, Prettier | PASS |
| Coverage-ledger contract | 21 tests, 221 assertions, PASS before final evidence-note refresh |
| Milestone-manifest contract | 19 tests, 276 assertions, PASS; stale inner bindings, wrong engines, duplicate/malformed JSON, claim inflation, shared-binding disagreement, legacy loopback PostgreSQL, and bootstrap bypass fail closed |
| Parity-governance adversarial contract | 214 tests, 1,627 assertions, PASS |
| Owner-snapshot generator contract | 7 tests, 30 assertions, PASS |
| G0/S0 intake contract | 37 tests, 181 assertions, PASS |
| Queue/query-plan/recovery harness contracts | 12/145, 14/175, and 10/69 tests/assertions, all PASS |
| PostgreSQL 17 disposable evidence | Final queue, query-plan, and recovery artifacts are mode `0600`, ignored, and bound by exact SHA-256 in the manifest |
| Portability harness contract | 12 tests, 152 assertions, PASS |
| PostgreSQL 17.10 current-manifest application suite | Harness-owned private cluster/Unix socket and fresh private-`laravel` migration; 453 tests; 452 passed, 1 skipped; 5,863 assertions; PASS |
| MySQL 8.4.11 current-manifest application suite | Fresh InnoDB migration; 453 tests; 446 passed, 7 skipped; 5,834 assertions; PASS |
| Current-manifest focused workflow slices | E2E-01/02/03/04/05/12/15/16 all PASS on PostgreSQL 17.10 and MySQL 8.4.11 with identical per-slice counts |
| Independent portability reviews | Defensive-security GO; release-gate integrity GO with no P0–P3 findings; local exact-engine scope only |
| MySQL 8.4 rollback/reapply | Last two T1 migrations rolled back and reapplied; schema probes changed from `0 0 0 0` to `1 1 2 1`; PASS |
| MySQL 8.4 administration/audit slice | 26 tests; 26 passed; 126 assertions; PASS |
| BG-03 focused SQLite-local comparison suite | 66 tests; 66 passed; 315 assertions; independent read-only review: GO for local comparison evidence only |
| Cross-setting cancellation decision-pack contract | 7 tests; 116 assertions; proposed/unapproved status, CAN/FR universes, owner coverage, safety boundaries, canonical requirement links, and companion links PASS |
| Cross-setting IGD/RI clinical-entry locking | 24 tests; 3,244 assertions; encounter-first locked state, synthetic/care-setting/status revalidation under lock, existing transitions, authorization-before-cross-setting/state disclosure, centralized denial audit, atomic audit rollback, and architecture contract PASS |
| Earlier changed-source security snapshot | 42/42 then-current files reviewed; 0 P0/P1/P2; 3 P3/low findings remain open; not asserted as complete for the later 137-file milestone |
| Generated rehearsal databases remaining | 0 |

These are local engineering results for the frozen bytes and the exact ignored artifacts. The current backend execution digest now has local PostgreSQL 17 and exact MySQL 8.4 coverage, including the bounded BG-03 slice. The results do not cover evidence-only documentation bytes, convert any workflow to hosted PASS or owner acceptance, provide PHP 8.3 CI-runtime equivalence, prove engine-specific contention/load behavior, or establish G3 acceptance.

Formal G0 is also checked explicitly as an expected red gate:

```bash
ruby scripts/validate-parity-governance.rb --mode g0
```

For this frozen local state the command must exit non-zero and report **523 unresolved governance errors**. A zero exit would require a fresh institutional-governance review; a different error count makes this pack stale or inconsistent. The expected non-zero result is proof that pending decisions and placeholder owners were not converted into fabricated approval.

## One future explicit batch approval requested

After reviewers run the commands above and inspect the manifest, request one explicit decision in this form:

> Approve the exact SHA-256-bound T1 LOCAL milestone manifest dated 2026-08-26 as one publication batch: create the reviewed commit, push it, and permit one budgeted GitHub Actions application event. This approval does not authorize deployment, production promotion, hosted UAT, credential use, or any hosted database migration.

Until that single explicit batch approval is given, the work remains local. Deployment and hosted migration require their own later evidence and authority and are outside this requested publication batch.
