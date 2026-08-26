# T1 Operational Pagination and Complete Recap Export Evidence — 2026-08-26

## Result

**PASS for this local implementation slice.** The active outpatient registration, outpatient examination, laboratory and registration-recap lists no longer silently discard work beyond their former hard caps. Each endpoint now returns an unchanged row-array prop plus explicit total/range/navigation metadata, and the shared Indonesian UI exposes previous/next navigation.

The recap totals now describe the complete filtered result. CSV export ignores the current screen page, uses bounded-memory iteration, and includes all matching synthetic encounters that existed at the export start boundary.

This remains local development evidence. It is not hosted capacity, latency, PostgreSQL query-plan, cohort-load, owner-acceptance or G1/G2/G3 evidence.

## Implemented contract

Shared backend metadata:

- `app/Support/Http/InertiaPagination.php`
- `current_page`, `last_page`, `per_page`, `total`, `from`, `to`, `prev_page_url`, `next_page_url`

Shared UI:

- `resources/js/components/operational-pagination.tsx`
- Indonesian range and page text
- real Inertia links for available directions
- disabled non-link text for unavailable directions
- labelled navigation landmark, visible focus treatment, 44 px minimum touch targets, preserved page state and scroll

Operational routes:

| Route | Page size | Stable order | Page parameter | Compatibility behavior |
|---|---:|---|---|---|
| `pendaftaran.rawat-jalan.index` | 50 | `registered_at DESC, id DESC` | `encounter_page` | Registration form state is preserved; patient search remains independent |
| `pemeriksaan.rawat-jalan.index` | 100 | `registered_at ASC, id ASC` | `page` | Shared IGD/triage/inpatient React variants keep optional pagination props |
| `pemeriksaan.laboratorium.index` | 100 | `requested_at ASC, id ASC` | `page` | Result submission returns to the current sanitized search/page context |
| `pendaftaran.rekap` | 500 | `registered_at DESC, id DESC` | `page` | Totals cover the full filtered result, not the visible page |

Registration patient search fetches one sentinel row beyond its 20-row display maximum. If more matches exist, the page states: `Menampilkan 20 hasil pertama. Persempit pencarian untuk menemukan pasien lainnya.`

If a supplied page is beyond the last available page, the server redirects to the actual last page while preserving active filters. This also covers a laboratory result that removes the only row from page 2: the user returns to the now-valid page 1 instead of seeing `Halaman 2 dari 1`.

## Complete recap CSV behavior

The CSV branch runs before screen pagination and reuses the same authorized, filtered, synthetic-only query.

The export:

1. records the maximum matching encounter ID at export start;
2. excludes later inserts by enforcing that cutoff;
3. iterates downward by ID in chunks of 500 with the patient relation eager-loaded;
4. writes directly to a streamed download rather than constructing the complete file in application memory;
5. ignores a supplied screen `page` parameter; and
6. prefixes cells beginning with `=`, `+`, `-`, `@`, tab, carriage return, line feed, or leading whitespace followed by a formula marker so spreadsheet software does not interpret synthetic text as a formula.

This cutoff prevents later inserts from entering the export, but it is not a transactional snapshot of concurrent updates or deletions. An exact historical export would require a separately designed snapshot/background-export contract.

To prevent an unbounded synchronous export from exhausting a serverless request, CSV requires explicit valid start/end dates and temporarily refuses ranges longer than 31 inclusive days. The recap page displays the refusal in Indonesian. This is a provisional engineering safety limit, not an owner-approved reporting policy; a larger approved range requires a separately bounded asynchronous export design or a new owner decision.

## Automated evidence

Focused backend verification:

```text
php artisan test \
  tests/Feature/Performance/HighVolumeOperationalDeskQueryGrowthTest.php \
  tests/Feature/Outpatient/OutpatientFlowTest.php \
  tests/Feature/Outpatient/OutpatientLabFlowTest.php \
  tests/Feature/Outpatient/OutpatientPrintAndRecapTest.php

33 tests passed, 422 assertions
```

The focused tests prove:

- 101 synthetic active rows expose registration page 3 and examination/laboratory page 2;
- the range, total and page metadata match the rendered collections;
- 501 filtered recap rows produce 500 rows on page 1 and one row on page 2;
- totals remain 501 on both pages;
- the CSV contains its header plus all 501 rows even when requested from screen page 2;
- formula-leading MRN and patient-name fields are neutralized;
- missing or longer-than-31-day synchronous CSV ranges are refused visibly;
- impossible pages canonicalize to the last valid page, including after the last laboratory row on a page is completed;
- the laboratory success message survives the canonical redirect;
- invalid screen or CSV dates recover to a safe current-date filter before any database date predicate is executed;
- more than 20 patient-search matches are disclosed rather than silently hidden;
- a laboratory result submission preserves its current search and page; and
- small-versus-large query counts remain bounded for all four desks.

Focused shared-UI verification:

```text
npm run test:unit -- resources/js/components/operational-pagination.test.tsx
1 file passed, 3 tests passed
```

Complete local gates:

```text
composer test
Pint passed
PHPStan passed with 0 errors
PHPUnit: 419 tests, 417 passed, 2 skipped, 5,355 assertions

npm run format:check
npm run lint:check
npm run types:check
npm run test:unit
npm run build
All passed; Vitest: 8 files, 23 tests
```

## Remaining performance/capacity work

Risk R-13 remains **OPEN**. This slice removes silent truncation and guards relationship-query growth, but it does not prove scan cost or capacity. Remaining evidence includes:

- owner-approved concurrent teaching-cohort and service-level targets;
- representative PostgreSQL 17 data volume and concurrent workload;
- PostgreSQL `EXPLAIN (ANALYZE, BUFFERS)` evidence for encounter date/status/care-setting filters, laboratory status/request-time ordering and recap filters;
- evidence-based composite indexes rather than speculative migration changes;
- Vercel/Supabase latency, cold-start, error and resource measurements;
- browser rendering/accessibility checks at later pages; and
- facilitator pilot observation under the approved cohort profile.

## Release posture

- Local worktree only, based on unchanged baseline `d04b35f1f85ab0e6e56818d08c374f3a5bb3cb88`.
- No database migration was added in this slice.
- No commit, push, pull request, GitHub Actions run, Vercel deployment or Supabase migration was performed.
- Include this slice only in a later explicitly approved milestone batch.
