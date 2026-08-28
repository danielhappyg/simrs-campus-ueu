# T1 nine-migration hosted cutover — execution control packet — 2026-08-27

**Current decision:** `NO-GO / NOT EXECUTED` until every blocking `PENDING` field in this packet is completed from action-time evidence
**Authorized scope:** one controlled migration-and-promotion attempt for the exact frozen release identities below
**Boundary:** SIMRS Campus UEU synthetic teaching simulation only
**Data posture:** `APP_MODE=SIMULATION`, `APP_SYNTHETIC_ONLY=true`; no real or plausibly real patient data
**External posture:** Klaim, BPJS/VClaim, SATUSEHAT, Apotek, LIS, PACS, payment and every other live production integration remain disabled

This is a new date-specific execution record. It does not replace the 25 August BG-02c4b packet or turn historical evidence into current approval. A blank, `PENDING`, ambiguous, mismatched or unavailable value is a stop condition. This document records the technical pre-maintenance backup and isolated restore in Receipt A; it does not record that maintenance, migration, promotion, teaching-access activation, reopen or rollback has occurred.

## 1. Frozen release and evidence identities

| Control | Exact value | Status / rule |
| --- | --- | --- |
| Runtime/application candidate SHA | `e81bf19fb1cc9918d103743edd2e1f5b48893880` | Local reviewed simulation-egress containment candidate; not pushed |
| Repository/release carrier SHA | `PENDING` | Must include the application candidate plus this refreshed packet in one batched local commit chain |
| Candidate relationship | The local candidate preserves the nine-migration manifest and adds fail-closed simulation outbound-traffic containment plus refreshed cutover evidence | Runtime substitution is intentional and independently tested; deployment identity must be refrozen |
| Exact Preview deployment | `PENDING` | The former `dpl_5bky2exRuW5HHgVbkr5GfxT7HWJh` is superseded and prohibited from promotion |
| Exact Preview URL | `PENDING` | Refreeze only after the batched GitHub push creates an exact Git-backed Preview |
| Preview observation | `PENDING` | Require `READY`, target `preview`, creation timestamp and allowed non-database smoke evidence |
| Required Preview source binding | Full Git source SHA must equal the final repository/release carrier containing `e81bf19fb1cc9918d103743edd2e1f5b48893880` | `PENDING`; never infer from branch, URL or timestamp |
| Current public Production baseline | SHA `42ab482de577fe38cef539a74f0b749d64485b19`; deployment `dpl_4uGZACFzKsHMnNUy6YshiJjeQN1Q` | Historical comparison point; refresh before cutover |
| Public canonical URL | `https://simrs-campus-ueu-demo.vercel.app` | Must remain the only public Production alias after one authorized promotion |
| Supabase project reference | `xbmsfvstcpngizcplqyg` | Exact target; action-time target binding still required |
| Predecessor preflight SQL | `T1_PRODUCTION_PROMOTION_READINESS_PREFLIGHT_2026-08-27.sql` | SHA-256 `a70ff74908a6eac5bf04f5313f38f5abf0f8985b9cd56d91cfdfdae77a1a6d4c` |
| Sanitized predecessor result | `T1_PRODUCTION_PROMOTION_READINESS_RESULT_2026-08-27.json` | SHA-256 `8564d3bc4252706690c6a1b6b15dedf1041e927552baae9c98d6a81854d31be1`; status `PRE_MIGRATION_CONTRACT_MATCH` |
| Pre-migration preservation SQL | `T1_NINE_MIGRATION_PREMIGRATION_PRESERVATION_2026-08-27.sql` | SHA-256 `10a662bb57123ad475d0f97f713877938b7b475c84fa7f55741c5bf0905de612`; action-time byte equality and independent review remain blocking |
| Post-migration acceptance SQL | `T1_NINE_MIGRATION_POSTMIGRATION_ACCEPTANCE_2026-08-27.sql` | SHA-256 `346807a8bfa005caa906f5c33ceb1324eb38c8b3901a97a51c133e143c9e5336`; action-time byte equality and independent review remain blocking |
| Vercel environment-scope result | `T1_VERCEL_ENVIRONMENT_SCOPE_RESULT_2026-08-28.json` | SHA-256 `6ae1471a89ffcc01d67f2787fc8be1ed259bf8c348fd07ec829814072f3fc7e4`; contains no secret value |

The Preview observation proves identity, URL, target and readiness only. It does not prove current source binding, database compatibility, authenticated behavior or authorization. Preview must not receive Production database credentials.

The original Preview `dpl_2ZrGq1tepp9Hf863hruJk4YTJGnH` is superseded and prohibited because operator-observed, non-secret Vercel CLI metadata before the scope correction showed that its immutable build was created after `DB_URL` had been targeted to both Production and Preview. The local CLI deployment `dpl_VPNjBRUTBXnb68oxdr7sySbRE2Sr` lacked the required GitHub source-binding metadata. The later exact Git-backed Preview `dpl_5bky2exRuW5HHgVbkr5GfxT7HWJh` is now also superseded and prohibited because it predates the reviewed simulation-egress containment in `e81bf19…3880`. `T1_VERCEL_ENVIRONMENT_SCOPE_RESULT_2026-08-28.json` records these boundaries and non-secret environment-scope actions without any secret value.

## 2. Authority and separation of duties

| Role | Named authority | Evidence / state |
| --- | --- | --- |
| Release authorization and final promotion go/no-go | Daniel Happy Putra | Explicit 2026-08-27 thread authorization for this exact cutover scope |
| Product decision authority | Daniel Happy Putra | Explicit 2026-08-27 thread authorization; synthetic teaching release only |
| Rollback/forward-recovery decision authority | Daniel Happy Putra | Explicit 2026-08-27 thread authorization; must make the action-time retained-write decision |
| Maintenance-reopen authority | Daniel Happy Putra | Explicit 2026-08-27 thread authorization; only this authority may approve `up` |
| Execution operator | Codex orchestrator | Named operator for the controlled procedure; must record action-time identity and timestamps |
| Independent reviewer | `PENDING` | **Blocking.** Must be a named person independent of the execution operator |
| Backup/restore custodian | `PENDING` | **Blocking.** Must control approved encrypted storage and restore receipts |
| Clinical domain-owner sign-off | `PENDING / NOT EVIDENCED` | Not implied by product/release authority; this cutover is not clinical acceptance |
| RMIK domain-owner sign-off | `PENDING / NOT EVIDENCED` | Not implied by product/release authority; parity acceptance remains separate |
| Security/domain control sign-off | `PENDING / NOT EVIDENCED` | Not implied by this packet; no independent sign-off is fabricated |
| Short-lived Production/Vercel/database access and expiry | `PENDING` | **Blocking;** record references only, never secret values |

Daniel Happy Putra's named authority does not substitute for the pending independent reviewer, custodian or domain-owner evidence. The operational promotion may proceed only when its blocking execution roles are complete; absent domain-owner sign-off means the result remains a synthetic technical release and cannot be described as clinical, parity or institutional acceptance.

## 3. Exact predecessor contract

The sanitized live read-only result captured `2026-08-27T05:26:52.049385Z` reports the only accepted predecessor:

- PostgreSQL major version 17 and Supabase schema `laravel`;
- exactly 31 Laravel migration rows;
- latest batch exactly `7`;
- last ordered row exactly `2026_08_24_000100_create_outpatient_documentation_tables`;
- all nine candidate rows absent;
- the reviewed foundation, marital-status and 13 sequence adoption shapes compatible;
- all later candidate namespace objects absent, including the teaching-role lease predecessor;
- zero non-synthetic patients and zero encounters without `registered_at`;
- tested Data API roles have no reviewed `laravel` schema, relation, column, sequence or routine privileges.

At action time, rerun the exact frozen predecessor query from the exact release carrier against the independently target-bound Production database. Require one unambiguous `PRE_MIGRATION_CONTRACT_MATCH` result with the same predecessor ledger. The query has no post-migration success meaning. Any SQL error, additional output, timeout, unknown state or `NO_GO_SCHEMA_DRIFT` aborts the cutover and does not authorize a retry.

## 4. Exact ordered migration manifest

Laravel must execute these exact files in this exact order during one unambiguous `migrate --force` attempt. Do not insert rows into `laravel.migrations` manually.

| Order | Migration | File SHA-256 | Intended effect |
| ---: | --- | --- | --- |
| 1 | `2026_08_21_000100_create_rebuild_foundation_tables` | `2e8edda3e2af1f50719f6f6fe614e5f3dbccac576fd743b65cfa669f072b8cbe` | Fail-closed adoption of the compatible existing audit foundation |
| 2 | `2026_08_22_000800_add_patient_marital_status` | `19c60bba317b9b136679302396c5943eaf0a91f16f071970e6d4f3a804c7183f` | Fail-closed adoption of the existing nullable marital-status column |
| 3 | `2026_08_22_001000_qualify_laravel_serial_sequence_defaults` | `10f0cecb82fd9e62361071b19fc9ae54d9b033c9c7a9d7a4bb94188339b6b68e` | Validate and qualify the 13 reviewed PostgreSQL sequence defaults |
| 4 | `2026_08_25_000100_create_break_glass_record_tables` | `77d7c8478e956b254476c408911f3a01924984ff920407a2ad3e2ed76621c13d` | Add six break-glass request/decision/activation/revocation/session/subject-lease tables |
| 5 | `2026_08_25_000200_create_security_ledger_tables` | `55af5a04944290119fdb3511364148956cf667444d87a03e57e003975be9500b` | Add protected append-only security ledger and outbox structures |
| 6 | `2026_08_25_000300_expand_audit_actor_attribution` | `060c64122e41f1bf974f092ff8a2bfc1baf34675f031b0ced2f2f162b10775f4` | Add actor type/reference and replace the legacy actor FK with the reviewed restrictive FK |
| 7 | `2026_08_26_000100_add_operational_worklist_indexes` | `e9077592ac53dd9ef704ac376e7bc7c45ecddc43040273597d7a5dcadb23f954` | Add the two reviewed encounter/lab worklist indexes |
| 8 | `2026_08_26_000200_create_daily_queue_allocator` | `5eca2d46ea0fba89cf4bfe26afb83a096e47aa249a08e89580937b508f0ac3e0` | Create the daily queue counter, backfill deterministic queue dates/numbers, and enforce daily uniqueness |
| 9 | `2026_08_27_000100_create_teaching_role_access_leases` | `242bc0864918fc4b132766ffb8a088ba6327abb76c4cdf53d275eec8051504d7` | Add fenced temporary teaching-access columns, lease table, roster mapping and one-active-lease enforcement |

Expected post-migration ledger: exactly 40 rows, all nine names recorded once and consecutively in one new batch `8`, with the teaching-role lease migration last. Any missing, duplicated, reordered, differently hashed or separately batched file is `NO-GO`.

## 5. Environment and pre-cutover blockers

Record only pass/fail or non-secret identifiers. Never retain `.env` contents, database URLs, tokens, passwords, cookies, private encryption identities or secret commitments.

| Gate | Action-time evidence | Status |
| --- | --- | --- |
| Clean checkout is exactly the final release carrier containing application candidate `e81bf19…3880` | `PENDING — local candidate committed; refreeze after this packet is committed` | Blocker |
| All nine migration hashes match section 4 | `PASS — all nine exact file hashes reverified from the clean detached checkout` | Retain action-time recheck |
| Exact replacement Preview deployment/URL is `READY`, target `preview`, source SHA exactly equals the final release carrier | `PENDING — the prior exact Preview is superseded by the local simulation-egress fix` | Blocker |
| Current public Production deployment/SHA refreshed and compatible with the predecessor | `PARTIAL — dpl_4uGZACFzKsHMnNUy6YshiJjeQN1Q / 42ab482…b19 READY; /up=200; /login=200. Action-time predecessor compatibility remains PENDING` | Blocker |
| PostgreSQL major 17, project `xbmsfvstcpngizcplqyg`, schema `laravel`, exact target binding | `TECHNICAL PASS in Receipt A — shared session pooler, verify-full TLS and pinned CA; independent reviewer reconciliation remains PENDING` | Blocker until role/review complete |
| `APP_MODE=SIMULATION`, `APP_SYNTHETIC_ONLY=true`, `DEMO_SEED_ENABLED=false` | `PASS for the next Production build — explicitly written as Production-only non-secret runbook controls and verified as three distinct encrypted Production metadata records` | Retain pre-promotion metadata recheck |
| `APP_MAINTENANCE_DRIVER=cache`, `APP_MAINTENANCE_STORE=database` | `PASS for the next Production build — explicitly written as Production-only non-secret runbook controls and verified as two distinct encrypted Production metadata records` | Retain pre-promotion metadata recheck |
| Session/cache and the maintenance command use the same Production-demo database, schema, cache table and cache prefix; Preview has no Production credential | `PARTIAL — future Production DB/schema/session/cache controls pass; seven sensitive keys are Production-only and Preview has a distinct APP_KEY; historical immutable Previews retain the prior database credential until rotation/revocation is proven` | Blocker |
| Simulation makes no external password-breach request and cannot select a default network mail transport | `LOCAL PASS — e81bf19…3880; independent security re-review GO; full suite 506 tests / 6,415 assertions. Deployment-effective proof PENDING` | Blocker until replacement Preview/Production evidence |
| Historical Preview database credential is rotated or revoked and older immutable Preview deployments can no longer authenticate | `PENDING` | Blocker before promotion or migration |
| All prohibited live integrations disabled | `PENDING` | Blocker |
| Independent reviewer, backup custodian, access expiry and recovery path complete | `PENDING` | Blocker |
| Frozen predecessor query returns the one exact accepted result | `PENDING` | Blocker |
| Pre-preservation and post-acceptance SQL both exist, have been independently reviewed, and their exact hashes match section 1 | `PENDING` | Blocker |
| External target-receipt procedure is frozen to project `xbmsfvstcpngizcplqyg`, the approved Production endpoint and CA trust evidence | `TECHNICAL PASS — procedure SHA 869ecf1…ff6c1, shared session pooler, verify-full TLS, CA SHA 7007235…3b7; named human reconciliation remains PENDING` | Blocker until role/review complete |

## 6. Backup A and post-drain backup B

Use the secret-safe PostgreSQL 17 backup and isolated-restore controls in the 25 August G1 packet, updated only with these action-time frozen identities. Both backups must be encrypted, checksummed, target-bound, published without replacement, and restored into separate empty PostgreSQL 17 targets. A backup without an independently matching restore receipt is not a backup gate.

### Receipt A — pre-maintenance source preservation

| Required evidence | Recorded value |
| --- | --- |
| Backup A ID / unique attempt ID / source snapshot / cutoff | `simrs-xbmsfvstcpngizcplqyg-laravel-20260827T220111Z`; snapshot-ID SHA-256 `284cc841…cbf03`; `2026-08-27T22:01:11Z` to `22:01:26Z` |
| Production target-binding evidence and authorized service/config hashes | Project `xbmsfvstcpngizcplqyg`; `aws-0-ap-southeast-1.pooler.supabase.com:5432`; shared-session-pooler; `sslmode=verify-full`; CA SHA-256 `70072358…3b7`; procedure SHA-256 `869ecf1c…ff6c1` |
| Encrypted archive SHA-256 / exact bytes / recipients-set hash | `d7501c3d…bbceb` / `228355` / recipient SHA-256 `601b128e…958d2`; no private identity or plaintext retained in this packet |
| Exporter/query/dump completion unambiguous | `PASS` — manifest commit marker published last; acceptance, dump and canonical hashes share the exported snapshot |
| Empty isolated PostgreSQL 17 restore target A | `PASS` — fresh isolated PostgreSQL 17 target; TCP disabled; no plaintext dump written |
| Source-versus-restore acceptance equality | `PASS` — acceptance `285ad1ed…cc07`; schema `4c81f5b9…e866`; data `c61d56bb…20b8`, each exactly equal source versus restore |
| Externally target-bound source receipt: project/endpoint/CA/service binding | `TECHNICAL PASS` — manifest `PASS`, exact project/endpoint/verify-full CA/session context recorded without credential values |
| Operator / custodian / independent reviewer / timestamps | Operator `Codex orchestrator`; manifest proposes custodian `Daniel Happy Putra`; independent human reviewer and explicit custodian acceptance remain `PENDING`; technical timestamps above |
| Receipt A verdict | `TECHNICAL PASS / GOVERNANCE NO-GO` — backup and isolated restore passed; separation-of-duties fields still block maintenance or promotion |

### Receipt B — final post-drain recovery point

Backup B is taken only after the shared maintenance marker is independently visible, the exact candidate has been promoted into the drained environment, `/up=200`, `/login=503`, more than the configured 60-second Vercel function maximum plus the approved observation margin has elapsed, and database activity proves no old application, external database or worker writer remains. Production uses synchronous queues, but that does not prove the absence of other database writers.

| Required evidence | Recorded value |
| --- | --- |
| Marker verification, writer-drain interval and no-old-writer proof | `PENDING` |
| Backup B ID / unique attempt ID / source snapshot / cutoff | `PENDING` |
| Production target-binding evidence and authorized service/config hashes | `PENDING` |
| Encrypted archive SHA-256 / exact bytes / recipients-set hash | `PENDING` |
| Exporter/query/dump completion unambiguous | `PENDING` |
| Independent empty PostgreSQL 17 restore target B | `PENDING` |
| Source-versus-restore acceptance equality | `PENDING` |
| Externally target-bound source receipt: project/endpoint/CA/service binding | `PENDING` |
| Operator / custodian / independent reviewer / timestamps | `PENDING` |
| Receipt B verdict | `PENDING — must be PASS before the migration attempt` |

Backup A precedes the drain and is not the preferred routable recovery point. Backup B is the recovery baseline after old writers are proven absent. If B is unavailable or ambiguous, do not migrate.

## 7. Shared maintenance and one-attempt execution ledger

The maintenance marker is database-cache-backed because Vercel functions do not share filesystem state. Do not use a bypass secret. Keep the marker active across candidate promotion, drain, backup B, migration, acceptance, smoke checks and log review. Static assets may remain available, but no application writer may bypass the marker.

| Order | Gate / action | Evidence / status |
| ---: | --- | --- |
| 1 | Freeze release, deployment, migration and SQL hashes; complete operator/reviewer/custodian/access fields | `PARTIAL — technical identities refrozen; reviewer/custodian/access fields PENDING` |
| 2 | Refresh Production, Preview, Supabase, environment and integration inventory with no drift | `PARTIAL — seven sensitive keys narrowed to Production, Preview has a distinct APP_KEY, explicit Production controls refreshed; replacement Preview, historical credential revocation and action-time integration inventory PENDING` |
| 3 | Run frozen predecessor query once; require exact `PRE_MIGRATION_CONTRACT_MATCH` | `PENDING` |
| 4 | Complete backup A and independent restore receipt A | `PENDING` |
| 5 | From the exact release checkout, activate shared database-cache maintenance without bypass | `PENDING` |
| 6 | Verify the same marker from a second request path; require `/login=503` | `PENDING` |
| 6A | While maintenance is independently visible, rotate/revoke the historical Supabase database credential, replace the Production-only `DB_URL` through a secret-safe channel, and prove older immutable Preview deployments can no longer authenticate | `PENDING` |
| 7 | Promote only the exact replacement Git-backed Preview recorded in section 1 once to Production; require `/up=200`, `/login=503` | `PENDING — deployment identity not yet assigned` |
| 8 | Verify public alias deployment/source equals the frozen candidate; no other alias/deployment substituted | `PENDING` |
| 9 | Wait more than 60 seconds plus approved margin and prove no old database writer remains | `PENDING` |
| 10 | Complete post-drain backup B and independent restore receipt B | `PENDING` |
| 11 | `migrate:status` shows the exact nine-name ordered pending set and 31-row/batch-7 predecessor | `PENDING` |
| 12 | Through the frozen external target-receipt procedure, run the exact pre-preservation SQL and retain its full-stdout hash plus unchanged preservation JSON/digest | `PENDING` |
| 13 | Run `migrate --force` exactly once; require an unambiguous successful exit | `PENDING` |
| 14 | Through the same target-bound receipt context, run the exact post-acceptance SQL with the pre-receipt hash and unchanged preservation contract | `PENDING` |
| 15 | Require exact before/after preservation-contract equality plus the exact 40-row ledger and structural/index/constraint/trigger/sequence/data-boundary acceptance | `PENDING` |
| 16 | While maintenance remains active, verify public alias/source, `/up=200`, `/login=503`, logs and the expanded privilege boundary | `PENDING` |
| 17 | Revoke temporary direct cutover access and independently verify revocation | `PENDING` |
| 18 | Independent reviewer reconciles every receipt and recommends `GO` or `NO-GO` to Daniel Happy Putra | `PENDING` |
| 19 | Daniel Happy Putra records final reopen decision; only then remove maintenance | `PENDING` |
| 20 | After reopen, require `/up=200`, `/login=200`, simulation banner, one permitted audited synthetic write and one denied-role request | `PENDING` |
| 21 | Explicitly revoke/close any teaching-role access window and prove zero retained auth artifacts; integrations still disabled | `PENDING` |

The operator may use the runbook's exact commands only after all earlier gates pass:

```bash
APP_MAINTENANCE_DRIVER=cache APP_MAINTENANCE_STORE=database \
  php artisan --env=vercel.local down --retry=60

php artisan --env=vercel.local migrate:status
php artisan --env=vercel.local migrate --force
php artisan --env=vercel.local migrate:status

APP_MAINTENANCE_DRIVER=cache APP_MAINTENANCE_STORE=database \
  php artisan --env=vercel.local up
```

These commands are not authorization and must not be copied into an unbound checkout. Do not run `up` as a diagnostic action.

## 8. Preservation, post-migration acceptance, promotion and reopen gates

The exact pre-preservation and post-acceptance SQL files in section 1 form one fail-closed receipt chain. The pre query captures a value-minimized preservation contract for 24 nonvolatile predecessor tables, including row counts and stable content digests while excluding only named volatile tables and the columns intentionally created or rewritten by these nine migrations. Retain the complete pre-query stdout SHA-256, the compact preservation JSON unchanged, and its SHA-256. The post query must receive those exact values, recompute the same contract after migration, and require exact JSON and digest equality. A count-only comparison is insufficient, and neither query may be hand-edited or rerun after an ambiguous result.

Both SQL files validate structural state plus their PostgreSQL session context: database name, current user, server version and SSL state. **They do not prove the Supabase project identity, network endpoint or certificate authority.** Execute each only through one independently reviewed external receipt procedure fixed before cutover to:

- project ref `xbmsfvstcpngizcplqyg` and the authorized Production database endpoint/port;
- independently resolved DNS/TLS endpoint evidence and the approved CA trust chain or pinned CA fingerprint/reference;
- the approved libpq service/config hash and expected database/user/PostgreSQL-17/SSL context;
- the exact pre/post SQL SHA-256, execution timestamp, complete stdout SHA-256 and single JSON status/result hash;
- operator, backup/restore custodian and independent reviewer identities without any credential or connection-string value.

The post receipt must match the pre receipt's authorized project, endpoint, CA/service binding and database/user/version/SSL session context. Its `expected_pre_receipt_sha256`, preservation JSON and preservation digest must equal the externally retained pre receipt exactly. Any wrong target, changed CA/service context, missing receipt field, hash mismatch, SQL error, timeout, additional result or status other than the exact accepted status is `NO-GO`. Do not claim that SQL output alone establishes the Production target.

Do not reuse the predecessor-only readiness query as post-migration evidence. The post-acceptance result must be value-minimized and must at least establish:

- PostgreSQL 17, schema and session-context equality to the externally target-bound pre receipt;
- exactly 40 migration rows and the exact nine names in order, each once, in batch `8`;
- adopted audit foundation, marital status and 13 qualified sequence contracts;
- break-glass and security-ledger tables, constraints, indexes, mutation guards and triggers;
- actor attribution columns/index and restrictive foreign key, with the exact pre-migration ordinary-audit and user/public-ID preservation contract unchanged;
- two operational indexes and the daily queue table/date/number uniqueness contract;
- teaching-access lease table, five user fencing columns, sequence, named constraints/indexes, roster mapping, immutable identity enforcement and partial one-active-lease uniqueness;
- synthetic-only and encounter-registration preconditions preserved;
- tested Data API roles remain denied `USAGE`/`CREATE` on the schema; all relation privileges including `MAINTAIN`; all column privileges; sequence privileges; and routine/function/procedure `EXECUTE`, including privileges inherited from `PUBLIC`.

The routine-denial gate is intentionally strict. If PostgreSQL's default `PUBLIC EXECUTE` makes any tested Data API role effectively able to execute a `laravel` routine, the correct action-time result is `NO-GO` pending a separately reviewed privilege correction; do not weaken the acceptance query.

### Promotion gate

Promotion is `GO` only when receipts A and B pass, the shared marker is active and independently visible, old writers are drained, the exact deployment/source binding matches, the exact nine migrations are still pending, and both Daniel Happy Putra and the named independent reviewer have recorded the action-time decision. Promotion does not reopen traffic.

### Reopen gate

Reopen is `GO` only after exact post-migration acceptance, exact-SHA public binding, maintenance-state health checks, log/privilege review, temporary direct-access revocation, and a named independent review. Daniel Happy Putra alone records the final maintenance-reopen authorization. Teaching-role access must remain inactive until the lease migration is accepted; any later window follows `T1_TEACHING_ROLE_ACCESS_RUNBOOK_2026-08-27.md`, uses one exact roster account at a time, lasts no more than 30 minutes and ends with explicit revocation.

### Closeout gate

The cutover closes only when the public permitted and denied synthetic checks pass, all temporary access is revoked, integrations remain disabled, backup retention is assigned, restore targets and secrets have approved disposition, and the evidence pack records operator/reviewer timestamps without secret or patient-like values.

## 9. Ambiguity, rollback and forward recovery

- A timeout, disconnect, missing exit code, partial output or unknown Vercel/database state is failure with unknown completion. Keep or reactivate maintenance and inspect state. **Never blindly retry** a backup, restore, promotion, migration or acceptance query.
- PostgreSQL makes each Laravel migration transactional where the migration permits it; the complete nine-migration run is not one all-or-nothing transaction. A later failure may therefore leave an accepted prefix recorded and later names pending. Treat that as partial completion requiring inspection and a reviewed recovery decision, not a second automatic `migrate` command.
- Never manually insert/delete migration-ledger rows, run `migrate:fresh`, use a generic `migrate:rollback`, edit protected facts, seed retained Production-demo data, or use Tinker/direct SQL as an improvised repair.
- Before any migration commit, an application-only return to the verified old deployment may be considered only while maintenance remains active and the unchanged predecessor is re-proven.
- After any candidate migration commits, any new audit/security/lease/queue write occurs, or completion is uncertain, do not reopen the old `42ab482…b19` writer. Its schema assumptions and actor-attribution behavior are no longer the accepted write contract.
- Prefer an exact reviewed forward corrective release. If database recovery is selected, use independently restored and receipt-proven backup B under maintenance. Backup A may be selected only with a recorded reason B cannot be used, an independent external write block, installation/verification of the shared marker on the restored target, no-writer proof and Daniel Happy Putra's retained-write decision.
- Every failed promotion, post-migration or smoke gate retains the shared marker. Only Daniel Happy Putra may authorize reopening after the named independent reviewer reconciles the evidence.
- Rollback/recovery never enables real data, broadens grants, activates integrations, fabricates domain acceptance or discards access/audit/security evidence.

| Recovery decision field | Recorded value |
| --- | --- |
| Failure/ambiguity time and last unambiguous ledger step | `PENDING / NOT APPLICABLE` |
| Shared maintenance-marker state and independent observation | `PENDING / NOT APPLICABLE` |
| Deployment/source, migration-ledger and schema inspection | `PENDING / NOT APPLICABLE` |
| Selected forward correction or receipt-proven restore B/A | `PENDING / NOT APPLICABLE` |
| Retained-write impact and no-writer proof | `PENDING / NOT APPLICABLE` |
| Daniel Happy Putra decision / independent reviewer recommendation | `PENDING / NOT APPLICABLE` |
| Final routing, health, privilege, synthetic and integration evidence | `PENDING / NOT APPLICABLE` |

## 10. Final decision record

| Decision field | Recorded value |
| --- | --- |
| Execution start/end in Asia/Jakarta and UTC | `PENDING` |
| Operator action-time identity | `PENDING — Codex orchestrator` |
| Independent reviewer identity and recommendation | `PENDING` |
| Backup/restore custodian and retention deadline | `PENDING` |
| Migration attempt result / exact batch | `PENDING` |
| Promotion result / exact public deployment and SHA | `PENDING` |
| Pre-preservation SQL SHA / target-bound receipt / stdout and preservation-contract hashes | `PENDING` |
| Post-acceptance SQL SHA / target-bound receipt / exact before-after digest equality | `PENDING` |
| Daniel Happy Putra reopen decision and timestamp | `PENDING` |
| Post-reopen synthetic smoke and denied-role result | `PENDING` |
| Temporary-access revocation and integration-disablement result | `PENDING` |
| Final verdict | `NO-GO / NOT EXECUTED` |

## References

- [T1 production promotion readiness](T1_PRODUCTION_PROMOTION_READINESS_2026-08-27.md)
- [Sanitized predecessor result](T1_PRODUCTION_PROMOTION_READINESS_RESULT_2026-08-27.json)
- [Frozen predecessor preflight SQL](T1_PRODUCTION_PROMOTION_READINESS_PREFLIGHT_2026-08-27.sql)
- [Pre-migration preservation SQL](T1_NINE_MIGRATION_PREMIGRATION_PRESERVATION_2026-08-27.sql)
- [Post-migration acceptance SQL](T1_NINE_MIGRATION_POSTMIGRATION_ACCEPTANCE_2026-08-27.sql)
- [Vercel environment-scope and replacement-Preview result](T1_VERCEL_ENVIRONMENT_SCOPE_RESULT_2026-08-28.json)
- [Vercel + Supabase synthetic demo runbook](VERCEL_SUPABASE_DEMO.md)
- [Temporary teaching-role access runbook](T1_TEACHING_ROLE_ACCESS_RUNBOOK_2026-08-27.md)
- [25 August BG-02c4b G1 execution-control record](BG_02C4B_G1_CUTOVER_EXECUTION_CONTROL_2026-08-25.md)
- [Current rebuild handoff](HANDOFF_SIMRS_TEACHING_REBUILD_2026-08-23.md)
