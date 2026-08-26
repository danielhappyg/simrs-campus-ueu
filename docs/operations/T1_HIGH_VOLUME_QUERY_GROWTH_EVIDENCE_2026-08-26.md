# T1 High-Volume Operational Desk Query-Growth Evidence — 2026-08-26

## Result

**PASS for the local query-topology slice.** Four authenticated operational list routes render 25 synthetic rows without adding per-row `SELECT` queries when compared with the same warmed route rendering one row.

This is local development evidence only. It does not close risk R-13, define a UEU cohort-size or response-time target, prove concurrent-user capacity, or establish hosted PostgreSQL/Vercel performance acceptance.

## Scope and test contract

Authoritative automated test:

- `tests/Feature/Performance/HighVolumeOperationalDeskQueryGrowthTest.php`

The test covers these current teaching workflows:

| Desk | Named route | Role | Rendered collection | Query relationship already exercised |
|---|---|---|---|---|
| Pendaftaran rawat jalan | `pendaftaran.rawat-jalan.index` | Registrar | `todaysEncounters` | encounter → patient; clinic → doctor → schedule |
| Pemeriksaan rawat jalan | `pemeriksaan.rawat-jalan.index` | Physician | `encounters` | encounter → patient |
| Pemeriksaan laboratorium | `pemeriksaan.laboratorium.index` | Nurse | `orders` | order → requester; order → encounter → patient |
| Rekap pendaftaran | `pendaftaran.rekap` | Registrar | `rows` | encounter → patient |

For each route, the test:

1. creates only synthetic patients, encounters and orders;
2. authenticates a role that owns the required capability;
3. warms the route once so framework initialization is not misclassified as row growth;
4. measures database `SELECT` statements while rendering one visible row;
5. grows the applicable visible collection to 25 rows;
6. measures the same request again and confirms that the response really contains all 25 rows; and
7. fails if the larger response adds more than one `SELECT` relative to the smaller response.

The single-query tolerance is deliberately narrow enough to detect per-row lazy loading while avoiding a brittle assertion against harmless framework/database bookkeeping. The guard measures query count, not query duration.

## Verification

Executed locally from the working tree based on commit `d04b35f1f85ab0e6e56818d08c374f3a5bb3cb88`:

```text
vendor/bin/pint --test tests/Feature/Performance/HighVolumeOperationalDeskQueryGrowthTest.php
PASS

php artisan test tests/Feature/Performance/HighVolumeOperationalDeskQueryGrowthTest.php
5 tests passed, 174 assertions

composer test
Pint passed
PHPStan passed with 0 errors
PHPUnit: 419 tests, 417 passed, 2 skipped, 5,355 assertions
```

No controller change was required: the measured routes already use bounded result sets and eager loading for the relationships rendered by these pages.

## Boundaries and remaining acceptance work

This evidence does not prove:

- absolute response latency or a percentile target;
- concurrent classroom or hospital-like load;
- production-sized datasets, report/export workloads, write contention or queue backpressure;
- PostgreSQL query plans, index adequacy or Supabase resource behavior;
- Vercel cold-start, network or browser rendering performance;
- accessibility, usability or domain-owner acceptance; or
- G1/G2/G3 acceptance.

R-13 remains **OPEN**. Closing it still requires owner-approved workload and service targets, representative PostgreSQL data volume, concurrent load tests, query-plan/index evidence, hosted measurements, error/latency monitoring, and a facilitator pilot that reflects the approved teaching cohort.

## Release posture

- Local implementation and verification only.
- No commit, push, pull request, GitHub Actions run, Vercel deployment or Supabase migration was performed for this slice.
- This file and its test should be included in a later explicitly approved milestone batch.
