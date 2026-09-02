# T1 Local Structured Emergency Triage and Disposition V1 Exact-Engine Evidence

Status: **PASS — LOCAL DISPOSABLE ENGINES ONLY**
Executed: 2026-09-01 Asia/Jakarta
Scope: PostgreSQL 17.10 and MySQL 8.4.11 in isolated local databases

Deployment, hosted readiness, Clinical/IGD, Nursing, RMIK, facility, and parity acceptance claims remain **false**. This record does not authorize a commit, push, hosted migration, or deployment.

## Bound implementation and verification

- Harness: `scripts/rehearse-local-emergency-portability.rb`
- Harness contract: `tests/Documentation/LocalEmergencyPortabilityHarnessContractTest.rb`
- Core migration: `database/migrations/2026_09_01_000400_create_structured_emergency_triage_disposition_tables.php`
- Admission-claim and handoff integrity migration: `database/migrations/2026_09_01_000500_add_inpatient_patient_admission_claim.php`
- Complete test catalogue: `tests/Feature/Emergency` and `tests/Unit/Emergency`
- Authorization: `docs/new-simrs-rebuild/phase-1/STRUCTURED_EMERGENCY_TRIAGE_DISPOSITION_V1_LOCAL_ENGINEERING_AUTHORIZATION_2026-09-01.md`
- Shared source-binding aggregate: `b34528805eb4659982ac764f3ea1d2fce239706a38213a89a90815a65ee73778`
- Harness binding: `e73f9e92bb8f68fd4a97fb2bf09ef889cca9b85643f920879e4a14d17478a81e`
- Migration catalogue binding: `7fabb4c7d01803cad89e71e138e33caf7121d7dff5b7b53941d40d0102c38ee2`
- Test catalogue binding: `32de02e82e9bd6155dab88ef1d166d41f03ef9817d9820472930e120c7af3be8`
- Isolated execution-batch catalogue binding: `fcf243bed44cb7b5f57d134aa07d9aa5ed6c00fd04c25fbc95917c2007c6f605`

Both engines used the identical final source binding. Each feature file ran against a pristine owner-reset disposable database so MySQL DDL implicit commits could not contaminate another test process. An additional clean `migrate:fresh` then proved the final migration set independently of the test lifecycle.

## Exact-engine execution register

| Engine | Result | Tests | Assertions | Evidence | SHA-256 | Mode | Strict cleanup |
|---|---|---:|---:|---|---|---|---|
| PostgreSQL 17.10 (`server_version_num=170010`) | PASS | 34/34 | 301 | `storage/app/portability-rehearsals/20260901T025237Z-emergency-postgresql17-64dce76c56bb.json` | `f0722e41fdf59b8c557b0e830942893f5c6916fbc35e1409b913b51d75486597` | `0600` | PASS |
| MySQL 8.4.11 (InnoDB) | PASS | 34/34 | 301 | `storage/app/portability-rehearsals/20260901T025211Z-emergency-mysql8411-3d48108a200b.json` | `f9753d275783d4bf22e830239a317a6bdcb7b2d97c71b34c7a0a4df80237b0c3` | `0600` | PASS |

Both private evidence files report kind `SIMRS_LOCAL_STRUCTURED_EMERGENCY_PORTABILITY`, status `PASS`, claim `LOCAL_DISPOSABLE_EXACT_ENGINE_ONLY`, fresh migration `PASS`, zero skips, and both cleanup flags true: disposable database removed and temporary server removed.

## Verified structured journey

- Fixed versioned MERAH/KUNING/HIJAU/HITAM vocabulary and exact-role administration.
- Manual attributable ABCDE, vitals, per-field missingness, initial triage, and retriage.
- Structured nursing and medical Draft-to-Final version chains with engine-stable persisted JSON shape.
- Initial-triage gate before emergency laboratory or radiology orders.
- Per-order diagnostic follow-up proposal, acceptance, covering-physician acknowledgement binding, and stale-evidence refusal.
- Five physician dispositions, signed-disposition closure, and read-only legacy history projection.
- Pre-signed correction intent, expiry/revocation/conflict refusal, inpatient handoff, admission claim, graph integrity, and compensation boundaries.
- Idempotent mutation receipts, canonical fingerprints, append-only guards, synthetic reset, recovery digest/count coverage, and audit-schema coverage.

## Regression readback

- Complete local emergency feature/unit suite: 34 tests; 301 assertions; all passed.
- Emergency diagnostic-gate compatibility fixtures across laboratory and radiology: 30 tests; 298 assertions; all passed.
- Dedicated portability harness contract: 6 runs; 129 assertions; 0 failures and 0 errors.
- Ruby syntax and Pint checks passed.

## Claim boundary

This record proves only the bound local implementation bytes on disposable PostgreSQL 17.10 and MySQL 8.4.11. It does not prove hosted Supabase migration, Vercel deployment, production configuration, capacity, clinical validation, named domain-owner acceptance, operational support readiness, bed availability outside the governed local graph, or parity with another SIMRS. No BPJS, VClaim, SATUSEHAT, LIS/device, PACS/RIS, mail, billing, pharmacy, or other external integration was exercised.
