# G3 Security Remediation Register — 28 August 2026

## Status

**LOCAL TECHNICAL REMEDIATION PASS / SEALED DIFF: 0 REPORTABLE, 2 LOW DEFERRED / NOT PUSHED / NOT DEPLOYED / G3 NOT ACCEPTED**

This register records remediation of the eight findings from the sealed offline Standard scan of exact release carrier `c63c017a1e9dc2bd2922ce9fc9235b7cc90703db` (scan ID `167dd03f-70c6-4ab3-8989-3f741848c07b`). It does not authorize a push, migration, maintenance window, Vercel promotion, credential use, or G3 acceptance.

The remediation is split into four local commits:

- `2837399d06e00f1f736b6234b47e508d3500b8aa` — authentication and operational boundaries;
- `bebcfbeec1fa6762a82a6524a61ff3ea18d61947` — exact runtime release integrity;
- `10926bd219d1ace6b0687378b1f9cded9bf2b2a2` — residual reset-timing and cross-user CSV concurrency controls;
- `1013b5f8247c3a4d633f2ba9c7506e8770da2242` — buffered CSV delivery and PostgreSQL database-wait ceiling.

All four commits are local only at the time of this record. The user-protected paths `deliverables/`, `docs/legacy-visual-field-capture/`, `docs/operations/UAT_20260820_002_003_DRAFT_ORDER_REMEDIATION.md`, and `lang/` were excluded and untouched.

## Finding dispositions

| Sealed finding | Severity | Local disposition | Evidence |
| --- | --- | --- | --- |
| Release verifier does not authenticate the complete runtime against the claimed Git commit | Medium | Remediated in `bebcfbe`: schema-v2 manifest binds the exact runtime path/hash/mode set; assembly rechecks source bytes; verification rejects extras, omissions, altered bytes and modes; promotion fails closed without an independently trusted archive digest | Focused release suite `28/28`, 123 assertions; full combined CI pass |
| Password-reset endpoint lacks request-level throttling | Low | Remediated in `2837399`: normalized email+IP and broad IP budgets are consumed before managed-roster suppression | Auth/settings focused suite `35` tests, `34` pass, `1` unrelated PostgreSQL-only skip, 225 assertions |
| Fresh wilayah import deletes master data before validating the replacement | Low | Remediated in `2837399`: complete first-pass validation with per-file SHA-256 snapshot; exact-byte second pass inside one transaction; source drift, parse failure or write failure rolls back | Wilayah suite under 128 MB `16/16`, 96 assertions; 91,599 bundled rows; 186 upserts; 41.5 MiB peak |
| Synchronous recap CSV export has no hard total-work or concurrency ceiling | Low | Remediated across `2837399`, `10926bd`, and `1013b5f`: 31-day and configurable row/byte/time ceilings, stable cutoff, per-account/global request budgets, owner-safe per-account and global active-export slots, fully buffered bounded generation, PostgreSQL transaction-local statement timeout, and spreadsheet-formula neutralization; the response has an exact content length and no application stream callback that a slow client can prolong | Recap focused suite `22` pass, `1` PostgreSQL-only skip, 149 assertions; cross-user capacity, lock release, byte, runtime, buffered-response and database-timeout regressions; independent re-verification status `fixed`; full combined CI pass |
| Password-reset responses reveal account and managed-roster state | Low | Remediated in `2837399` and completed in `10926bd`: success, unknown account, managed roster, broker throttle and request limits return the same public status/body/session shape; managed and custom-limited short circuits use Laravel's configured password-broker timebox | Auth suite `49` pass, `1` PostgreSQL-only skip, 240 assertions; notification/token and deterministic timebox assertions |
| Passkey management exposes numeric database identifiers | Low | Remediated in `2837399`: URL-safe encrypted handles, authenticated-owner-scoped resolution, numeric and foreign handles return 404; settings and registration JSON omit database IDs | Focused security settings tests; frontend lint, type and unit checks |
| Pagination redirects and links trust the effective request host | Low | Remediated in `2837399`: paginator links and out-of-range redirects are relative; hostile `Host` regression coverage added | Operational focused suite and full combined CI pass |
| Concurrent inpatient registrations can double-book one bed | Low | Remediated in `2837399`: a stable database-row mutex is held in the registration transaction before occupancy recheck and encounter creation | Inpatient focused tests; source/transaction review; PostgreSQL scheduling proof remains below |

The passkey review also closed an adjacent revocation gap not listed in the sealed scan: passkey login now requires an application `User` whose status is `ACTIVE`, still denies managed teaching-roster accounts, and blocks inactive-user passkey management.

## Combined local verification

The authoritative combined run after all remediation and the bounded-memory import correction was:

```text
composer ci:check
Frontend: 12 files, 45 tests passed
PHP formatting: passed
PHP static analysis: passed, 0 errors
PHPUnit: 542 tests, 538 passed, 4 skipped, 6,770 assertions
Exit: 0
```

The production frontend bundle also completed successfully. A second build after the original remediation pair produced no `public/build` diff. The later follow-ups change backend, test, and evidence files only; the final combined run still regenerated and verified frontend routes, formatting, types, and tests successfully.

The follow-up independent source-to-sink review of the CSV finding marked it `fixed`: it confirmed the bounded normal response removes the prior slow-client callback path and that PostgreSQL statement timeout closes the blocking-query/expired-lease path. Hosted verification must still prove that the configured cache is shared and atomic across all application workers.

The initial full-suite reproduction of the wilayah defect exhausted the normal 128 MB PHP limit while retaining more than 71,000 village rows. The corrected two-pass import was then proved under `memory_limit=128M` and peaked at 43,515,904 bytes (41.5 MiB). Cross-file buffering reduced the bundled 91,599-row write to 186 upsert queries: 1 province, 2 regency, 15 district and 168 village upserts. The production-sized regression now launches the real migration and import commands in a fresh 128 MB child process against a disposable SQLite database, avoiding retained memory from unrelated PHPUnit cases while keeping the application command under its normal limit.

## Sealed remediation diff scan

Codex Security diff scan `de0afb55-7377-4370-886b-8f8046cc0da6` was sealed over exact range `c63c017a1e9dc2bd2922ce9fc9235b7cc90703db..0fa00978973e86f4a2375a6dfc2e07ce380cf1b9`.

- Changed-source inventory: `22/22` reviewed and closed.
- Discovery: four candidates; validation suppressed two and deferred two.
- Final findings: `0` reportable; no P0, P1, P2, or P3 finding.
- Coverage: partial only because two deployment/runtime facts remain deliberately unresolved, both calibrated low severity.
- Deferred proof 1: confirm every supported edge overwrites attacker-supplied forwarding headers, blocks direct-origin access, and shares atomic rate-limit/cache state.
- Deferred proof 2: measure distinct-bed admission lock waits against disposable PostgreSQL and the intended worker/connection topology.

The crafted-manifest behavior was reproduced, but it was rejected as a security finding because the documented workflow has no lower-trust manifest channel: canonical CI generates and immediately consumes the fixed manifest, while approved promotion binds the finished artifact digest. Password-reset timing was also rejected under the mandatory simulation mail-egress guard and documented log mail transport. This evidence-register update is documentation-only and is outside the release runtime allowlist.

## Residual and action-time gates

The following remain open and prevent any G3 acceptance claim:

1. Close the two low-severity deferred scan proofs: trusted-edge/header and shared-cache behavior, plus real PostgreSQL distinct-bed concurrency. SQLite tests cannot demonstrate row-lock scheduling; the stable mutex intentionally leaves a zero-valued `1000-01-01` sentinel in `daily_queue_counters`.
2. At promotion, obtain the trusted archive SHA-256 from independently approved artifact metadata or a promotion record. Never derive it from the downloaded tar or its adjacent sidecar.
3. Treat opaque passkey handles as ephemeral across `APP_KEY` rotation; refreshing the security page issues valid replacements.
4. Rotate or revoke the historical database credential during the controlled maintenance window and prove older immutable Preview deployments can no longer authenticate.
5. Complete named reviewer, backup custodian, access expiry/recovery, backup/restore, exact-SHA migration, hosted role UAT, reconciliation, accessibility, performance, operations and owner/domain sign-offs.

## Safety boundary

All work remains `APP_MODE=SIMULATION` and synthetic-only. No real patient data was used. No live BPJS, VClaim, SATUSEHAT, payment, messaging or other production integration was enabled. No secret value is included in this record or either remediation commit.

## References

- [Nine-migration cutover execution control](T1_NINE_MIGRATION_CUTOVER_EXECUTION_CONTROL_2026-08-27.md)
- [Release candidate artifact contract](RELEASE_CANDIDATE_ARTIFACT.md)
- [Integration disablement inventory](T1_INTEGRATION_DISABLEMENT_INVENTORY_2026-08-28.md)
- [Vercel environment-scope result](T1_VERCEL_ENVIRONMENT_SCOPE_RESULT_2026-08-28.json)
