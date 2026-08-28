# G3 Security Remediation Register — 28 August 2026

## Status

**LOCAL TECHNICAL REMEDIATION PASS / NOT PUSHED / NOT DEPLOYED / G3 NOT ACCEPTED**

This register records remediation of the eight findings from the sealed offline Standard scan of exact release carrier `c63c017a1e9dc2bd2922ce9fc9235b7cc90703db` (scan ID `167dd03f-70c6-4ab3-8989-3f741848c07b`). It does not authorize a push, migration, maintenance window, Vercel promotion, credential use, or G3 acceptance.

The remediation is split into two local commits:

- `2837399d06e00f1f736b6234b47e508d3500b8aa` — authentication and operational boundaries;
- `bebcfbeec1fa6762a82a6524a61ff3ea18d61947` — exact runtime release integrity.

Both commits are local only at the time of this record. The user-protected paths `deliverables/`, `docs/legacy-visual-field-capture/`, `docs/operations/UAT_20260820_002_003_DRAFT_ORDER_REMEDIATION.md`, and `lang/` were excluded and untouched.

## Finding dispositions

| Sealed finding | Severity | Local disposition | Evidence |
| --- | --- | --- | --- |
| Release verifier does not authenticate the complete runtime against the claimed Git commit | Medium | Remediated in `bebcfbe`: schema-v2 manifest binds the exact runtime path/hash/mode set; assembly rechecks source bytes; verification rejects extras, omissions, altered bytes and modes; promotion fails closed without an independently trusted archive digest | Focused release suite `28/28`, 123 assertions; full combined CI pass |
| Password-reset endpoint lacks request-level throttling | Low | Remediated in `2837399`: normalized email+IP and broad IP budgets are consumed before managed-roster suppression | Auth/settings focused suite `35` tests, `34` pass, `1` unrelated PostgreSQL-only skip, 225 assertions |
| Fresh wilayah import deletes master data before validating the replacement | Low | Remediated in `2837399`: complete first-pass validation with per-file SHA-256 snapshot; exact-byte second pass inside one transaction; source drift, parse failure or write failure rolls back | Wilayah suite under 128 MB `16/16`, 96 assertions; 91,599 bundled rows; 186 upserts; 41.5 MiB peak |
| Synchronous recap CSV export has no hard total-work or concurrency ceiling | Low | Remediated in `2837399`: 31-day and configurable row ceilings, stable cutoff, per-account/global request budgets, per-account active-export lock and spreadsheet-formula neutralization | Operational focused suite `38/38`, 433 assertions; full combined CI pass |
| Password-reset responses reveal account and managed-roster state | Low | Remediated in `2837399`: success, unknown account, managed roster, broker throttle and request limits return the same public status/body/session shape | Focused auth tests plus notification/token assertions |
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
PHPUnit: 534 tests, 531 passed, 3 skipped, 6,727 assertions
Exit: 0
```

The production frontend bundle also completed successfully. A second build after the two local commits produced no `public/build` diff, proving the committed bundle matches the current source-generated output.

The initial full-suite reproduction of the wilayah defect exhausted the normal 128 MB PHP limit while retaining more than 71,000 village rows. The corrected two-pass import was then proved under `memory_limit=128M` and peaked at 43,515,904 bytes (41.5 MiB). Cross-file buffering reduced the bundled 91,599-row write to 186 upsert queries: 1 province, 2 regency, 15 district and 168 village upserts.

## Residual and action-time gates

The following remain open and prevent any G3 acceptance claim:

1. Run an independent security diff revalidation against the two local remediation commits and retain its sealed result.
2. Prove the inpatient mutex with real PostgreSQL concurrent transactions; SQLite feature tests cannot demonstrate row-lock scheduling. The stable mutex intentionally leaves a zero-valued `1000-01-01` sentinel in `daily_queue_counters`.
3. At promotion, obtain the trusted archive SHA-256 from independently approved artifact metadata or a promotion record. Never derive it from the downloaded tar or its adjacent sidecar.
4. Confirm trusted-proxy/header configuration before relying on source-IP reset budgets. Uniform HTTP outcomes cannot eliminate statistical timing or mail-arrival inference completely.
5. Treat opaque passkey handles as ephemeral across `APP_KEY` rotation; refreshing the security page issues valid replacements.
6. Rotate or revoke the historical database credential during the controlled maintenance window and prove older immutable Preview deployments can no longer authenticate.
7. Complete named reviewer, backup custodian, access expiry/recovery, backup/restore, exact-SHA migration, hosted role UAT, reconciliation, accessibility, performance, operations and owner/domain sign-offs.

## Safety boundary

All work remains `APP_MODE=SIMULATION` and synthetic-only. No real patient data was used. No live BPJS, VClaim, SATUSEHAT, payment, messaging or other production integration was enabled. No secret value is included in this record or either remediation commit.

## References

- [Nine-migration cutover execution control](T1_NINE_MIGRATION_CUTOVER_EXECUTION_CONTROL_2026-08-27.md)
- [Release candidate artifact contract](RELEASE_CANDIDATE_ARTIFACT.md)
- [Integration disablement inventory](T1_INTEGRATION_DISABLEMENT_INVENTORY_2026-08-28.md)
- [Vercel environment-scope result](T1_VERCEL_ENVIRONMENT_SCOPE_RESULT_2026-08-28.json)
