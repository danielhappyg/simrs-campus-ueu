# T1 ten-migration hosted cutover — execution control packet — 2026-08-28

**Current decision:** `NO-GO / NOT EXECUTED`
**Authorized product boundary:** SIMRS Campus UEU synthetic teaching simulation only
**Runtime boundary:** `APP_MODE=SIMULATION`, `APP_SYNTHETIC_ONLY=true`
**External boundary:** Klaim, BPJS/VClaim, SATUSEHAT, Apotek, LIS, PACS, payment, and every other live integration remain disabled

This packet supersedes the nine-migration packet for the current local candidate. It does not alter or invalidate the historical evidence carried by `c63c017`; that evidence simply cannot authorize this changed migration set. A blank, `PENDING`, ambiguous, mismatched, stale, or unavailable value below is a stop condition.

No maintenance, hosted migration, Vercel promotion, teaching-role activation, reopen, or rollback is recorded by this document.

## 1. Frozen local candidate and evidence

| Control | Exact value | State |
| --- | --- | --- |
| Runtime/application migration candidate | `28ab1d8122f59bd36ffbb5909650efdc07438334` | Local reviewed code candidate containing the bed-claim migration |
| Local evidence carrier | `297eead3656bcf772f0cd8fce31008a0777708d3` | Local documentation commit; not pushed and not a deployable hosted identity |
| Current pushed application base | `5f6a8b29a866e9e09c346808c3a00bcf76c3d6ef` | Pushed to `origin/main`; GitHub-hosted jobs are externally blocked before execution by the account billing/spending limit and therefore are not green release evidence |
| Current pushed-base Preview observation | `dpl_7s4Nu8k2rsFK5GopeYb4LSCCDCPP` / `https://simrs-campus-ueu-demo-7yrxvize2-danielhappyg.vercel.app` | Vercel `READY`, Preview, exact Git source SHA `5f6a8b29a866e9e09c346808c3a00bcf76c3d6ef`; `/up=200`, hostile forwarded-header `/up=200`, `/login=500` because Preview has no Production database binding; not role-UAT or promotion evidence |
| Final repository/release carrier | `PENDING` | Must be one exact clean commit containing this complete control chain; the new hosted receipt remains local so the current pushed application base cannot be substituted |
| Exact Git-backed Preview deployment and URL | `PENDING` | Must be built from the final release carrier; the current pushed-base Preview observation cannot be substituted |
| Current public Production identity | `dpl_4uGZACFzKsHMnNUy6YshiJjeQN1Q` / `https://simrs-campus-ueu-demo.vercel.app` | Vercel `READY`; remains on the older database-aligned deployment and must remain unchanged until an authorized promotion; exact source SHA still requires action-time refresh |
| Supabase project reference | `xbmsfvstcpngizcplqyg` | Project reference only; action-time endpoint, TLS, database, user, and schema binding remain required |
| Ten-migration predecessor preflight | `T1_TEN_MIGRATION_PRODUCTION_READINESS_PREFLIGHT_2026-08-28.sql` | SHA-256 `17e2cf2a3715d8d5a3741f59023d113079dc2aaf3e700c2a092df2366cf104a5`; sanitized hosted result `T1_TEN_MIGRATION_PRODUCTION_READINESS_RESULT_2026-08-28.json`, SHA-256 `123ec7f0f37120e59116a8978183fa52ecb15b9dcd851e51cc9f5c89ccd474bc`, status `PRE_MIGRATION_CONTRACT_MATCH`, `promotion_authorized=false` |
| Pre-migration preservation SQL | `T1_TEN_MIGRATION_PREMIGRATION_PRESERVATION_2026-08-28.sql` | SHA-256 `f95d5c18ed64f15ad01e04600550259fb52552320a8a4c11da480dd94ba95ed8`; independent review and action-time byte equality required |
| Post-migration acceptance SQL | `T1_TEN_MIGRATION_POSTMIGRATION_ACCEPTANCE_2026-08-28.sql` | SHA-256 `8c52504779f701e1c770aea07580d9f6658c5e00024134bc1742e8eb799c95af`; independent review and action-time byte equality required |
| Local PostgreSQL bed-claim concurrency evidence | `T1_LOCAL_POSTGRESQL_INPATIENT_BED_CLAIM_CONCURRENCY_EVIDENCE_2026-08-28.md` | PASS locally; explicitly not hosted proof |
| Local PostgreSQL shared application-state evidence | `T1_LOCAL_TRUSTED_EDGE_SHARED_MAINTENANCE_VALIDATION_2026-08-28.md` | Exact PostgreSQL 17.10 private-schema maintenance/cache/lock/session PASS; Supabase/PgBouncer and hosted multi-instance proof still pending |
| Local PostgreSQL 17.10 catalog rehearsal | All 23 repository migrations applied to one generated empty `laravel` schema; 42 base tables, 55 candidate-table indexes, and 56 candidate-table constraints (`f=17`, `p=11`, `u=28`) observed; generated database removed and absence rechecked | `LOCAL PASS`; not predecessor-ledger, hosted, backup, or promotion evidence |

The final release carrier and Preview identity cannot be populated from a local-only commit. They are frozen only after the user-authorized batched push creates one clean remote SHA and Vercel reports a Git-backed Preview built from that same SHA.

## 2. Authority and separation of duties

| Role | Named authority | State |
| --- | --- | --- |
| Product/release decision authority | Daniel Happy Putra | Named |
| Execution operator | Codex orchestrator | Named |
| Independent technical reviewer | Daniel Happy Putra, acting independently from the Codex execution operator | `PENDING EXPLICIT ACCEPTANCE` |
| Backup/restore custodian | Daniel Happy Putra | `PENDING EXPLICIT ACCEPTANCE` |
| Temporary Vercel/Supabase access authority and expiry | Daniel Happy Putra | `PENDING EXPLICIT ACCEPTANCE AND EXPIRY` |
| Fail-closed unattended recovery/reopen authority | Daniel Happy Putra | `PENDING EXPLICIT ACCEPTANCE` |
| Clinical, RMIK, security, and other affected domain-owner sign-off | `PENDING` | Not implied by technical release authority |

The role-appointment packet is the authoritative acceptance record. Broad implementation authorization and possession of a credential do not themselves complete separation-of-duties or domain sign-off.

## 3. Exact predecessor contract

The only acceptable hosted predecessor is:

- PostgreSQL major version 17, target schema `laravel`;
- exactly 31 `laravel.migrations` rows, last batch `7`;
- last row `2026_08_24_000100_create_outpatient_documentation_tables`;
- all ten candidate migration names absent;
- `laravel.inpatient_bed_claim_mutexes`, its primary-key relation, and `laravel.encounters_care_bed_status_idx` absent;
- the prior audit, marital-status, sequence, teaching-role, queue, and candidate-object predecessor contracts exact;
- zero patients where `is_synthetic IS NOT TRUE` and zero encounters lacking `registered_at`;
- reviewed Data API roles denied from the `laravel` schema.

Run the exact frozen ten-migration predecessor SQL immediately before maintenance. Require one unambiguous `PRE_MIGRATION_CONTRACT_MATCH` result with `promotion_authorized=false`. The historical nine-migration result is comparison evidence only. Any SQL error, timeout, additional output, drift, or unknown state is `NO-GO` and does not authorize a retry.

## 4. Exact ordered migration manifest

Laravel must execute these exact files, once, in order, during one unambiguous `migrate --force` attempt. Never manually insert migration-ledger rows.

| Order | Migration | File SHA-256 |
| ---: | --- | --- |
| 1 | `2026_08_21_000100_create_rebuild_foundation_tables` | `2e8edda3e2af1f50719f6f6fe614e5f3dbccac576fd743b65cfa669f072b8cbe` |
| 2 | `2026_08_22_000800_add_patient_marital_status` | `19c60bba317b9b136679302396c5943eaf0a91f16f071970e6d4f3a804c7183f` |
| 3 | `2026_08_22_001000_qualify_laravel_serial_sequence_defaults` | `10f0cecb82fd9e62361071b19fc9ae54d9b033c9c7a9d7a4bb94188339b6b68e` |
| 4 | `2026_08_25_000100_create_break_glass_record_tables` | `77d7c8478e956b254476c408911f3a01924984ff920407a2ad3e2ed76621c13d` |
| 5 | `2026_08_25_000200_create_security_ledger_tables` | `55af5a04944290119fdb3511364148956cf667444d87a03e57e003975be9500b` |
| 6 | `2026_08_25_000300_expand_audit_actor_attribution` | `060c64122e41f1bf974f092ff8a2bfc1baf34675f031b0ced2f2f162b10775f4` |
| 7 | `2026_08_26_000100_add_operational_worklist_indexes` | `e9077592ac53dd9ef704ac376e7bc7c45ecddc43040273597d7a5dcadb23f954` |
| 8 | `2026_08_26_000200_create_daily_queue_allocator` | `5eca2d46ea0fba89cf4bfe26afb83a096e47aa249a08e89580937b508f0ac3e0` |
| 9 | `2026_08_27_000100_create_teaching_role_access_leases` | `242bc0864918fc4b132766ffb8a088ba6327abb76c4cdf53d275eec8051504d7` |
| 10 | `2026_08_28_000100_create_inpatient_bed_claim_mutexes` | `c2ba20c90dcc61e6b957306258bdad0ebc40dc82272d0d600482c68a78c4da36` |

Expected post-migration ledger: exactly 41 rows; candidate rows 32–41 occur once, consecutively, in batch `8`; the bed-claim mutex migration is last. A missing, duplicated, reordered, differently hashed, or separately batched file is `NO-GO`.

## 5. Preservation and acceptance contract

The pre-preservation query records the exact database/user/PostgreSQL/TLS context and a value-minimized digest contract for the 24 nonvolatile predecessor tables. It excludes only the seven named volatile/runtime tables and the columns intentionally added or rewritten by the first nine migrations. Migration ten must not alter predecessor row content.

The post query must receive the exact pre-receipt and preservation digests and require:

- exact database/user/PostgreSQL/TLS context equality;
- exactly 42 `laravel` base tables and the exact 41-row ledger;
- exactly 11 candidate-created tables and their reviewed columns, types, nullability, defaults, indexes, constraints, and triggers;
- all 39 candidate primary/unique constraints bound by exact name, type, validated/non-deferrable state, backing index, and the exact index-column contract; all 17 candidate foreign keys bound semantically; no candidate `CHECK` or exclusion constraint permitted;
- `inpatient_bed_claim_mutexes(bed_code varchar(64) primary key, created_at, updated_at)` and zero mutex rows before smoke activity;
- healthy non-unique B-tree `encounters_care_bed_status_idx(care_setting, bed_code, status)`;
- 22 qualified sequences, 62 required indexes, 18 restrictive foreign keys, three mutation-guard functions, and 16 candidate triggers;
- exact equality of the 24-table predecessor preservation contract;
- synthetic-only patient state, queue invariants, disabled teaching accounts, empty security/access tables, and reviewed Data API role denials.

The post result remains `promotion_authorized=false`. It proves database acceptance only; it does not authorize traffic, role activation, or live integrations.

## 6. Blocking pre-cutover gates

- [ ] Final clean release carrier SHA frozen and all ten migration hashes reverified.
- [ ] Git-backed Preview bound to that SHA; isolated from Production credentials; required unauthenticated routes observed.
- [ ] Current Production SHA/deployment and Vercel environment scopes refreshed without exposing values.
- [ ] Historical Preview database credential rotation/revocation proven.
- [ ] Independent reviewer, backup custodian, temporary-access expiry, and unattended recovery/reopen authority explicitly accepted.
- [ ] Fresh ten-migration predecessor result is one exact match.
- [ ] Pre/post SQL bytes and hashes independently reviewed and rechecked.
- [ ] Shared database-backed maintenance configuration and no-bypass behavior proven on the exact candidate.
- [ ] Backup A and final post-drain Backup B each encrypted, checksummed, target-bound, and restored into separate empty PostgreSQL 17 targets with exact source/restore equality.
- [ ] Old application and other writers drained for more than the configured 60-second function maximum plus the approved margin.
- [ ] One retained action-time `GO` records identities, timestamps, operator, reviewer, custodian, and rollback authority.

## 7. One-attempt execution ledger

1. Recheck the clean release carrier, Preview source binding, Production baseline, project/schema/TLS binding, environment scope, and ten hashes.
2. Run the frozen predecessor preflight; require one exact match.
3. Capture pre-preservation receipt and exact contract digest.
4. Complete and independently restore Backup A.
5. Activate shared database-backed maintenance with no bypass; prove `/up=200` and `/login=503` from an independent session.
6. Promote the exact candidate into the drained environment without reopening traffic.
7. Wait the writer-drain interval and prove no old application or other writer remains.
8. Complete and independently restore Backup B; this is the preferred routable recovery point.
9. Record one action-time `GO` for the exact identities.
10. Run one `migrate --force` attempt. Retain exact start/end time, exit state, and sanitized migration status.
11. Run the exact post-acceptance query once with the bound pre-receipt values.
12. While maintenance remains active, run hosted smoke, reconciliation, security, accessibility, performance, and temporary role-based UAT.
13. Revoke every temporary role account and access path; prove expiry/revocation.
14. Obtain action-time reopen approval, then remove maintenance and verify the canonical public alias.

An interruption, timeout, ambiguous command state, or partial prefix is not permission to rerun. In particular, migrations 1–9 may have committed while migration 10 failed. Inspect the exact ledger, schema objects, application behavior, and backups; keep maintenance active; then obtain a reviewed forward-recovery or restore decision.

## 8. Rollback and recovery

The ten migrations are individually transactional where PostgreSQL and the migration permit it; the full batch is not one atomic transaction. Do not rely on automatic `migrate:rollback` after traffic or smoke writes. The preferred recovery baseline is the independently restored post-drain Backup B.

Before any restore or reviewed migration-down decision, classify retained writes and record whether preserving them is required. A structural recovery inspection must include the exact ledger plus `inpatient_bed_claim_mutexes`, `inpatient_bed_claim_mutexes_pkey`, and `encounters_care_bed_status_idx`. Any recovery keeps shared maintenance active and requires fresh database acceptance, exact-SHA deployment binding, hosted UAT, access revocation, and reopen approval.

## 9. Secret and unattended-operation boundary

Credentials may be supplied ephemerally to an authorized process only. Never echo them, pass them on a visible command line, retain them in shell history/output/evidence, write them to repository files, or commit them. Receipts contain only non-secret identifiers, hashes, timestamps, boolean controls, and minimized counts.

If the user is away, all missing evidence fails closed. The operator may complete local tests and read-only preparation, but must not infer a human sign-off, waive a mismatch, reopen traffic, or claim hosted success without the required retained receipts.

## 10. Related records

- [Historical nine-migration packet — superseded, do not execute](T1_NINE_MIGRATION_CUTOVER_EXECUTION_CONTROL_2026-08-27.md)
- [Ten-migration predecessor preflight](T1_TEN_MIGRATION_PRODUCTION_READINESS_PREFLIGHT_2026-08-28.sql)
- [Sanitized ten-migration predecessor result](T1_TEN_MIGRATION_PRODUCTION_READINESS_RESULT_2026-08-28.json)
- [Ten-migration pre-preservation SQL](T1_TEN_MIGRATION_PREMIGRATION_PRESERVATION_2026-08-28.sql)
- [Ten-migration post-acceptance SQL](T1_TEN_MIGRATION_POSTMIGRATION_ACCEPTANCE_2026-08-28.sql)
- [Cutover role appointment packet](T1_CUTOVER_ROLE_APPOINTMENT_PACKET_2026-08-28.md)
- [Local inpatient bed-claim concurrency evidence](T1_LOCAL_POSTGRESQL_INPATIENT_BED_CLAIM_CONCURRENCY_EVIDENCE_2026-08-28.md)
- [Local trusted-edge and PostgreSQL shared-state evidence](T1_LOCAL_TRUSTED_EDGE_SHARED_MAINTENANCE_VALIDATION_2026-08-28.md)
- [G3 security remediation register](G3_SECURITY_REMEDIATION_REGISTER_2026-08-28.md)
