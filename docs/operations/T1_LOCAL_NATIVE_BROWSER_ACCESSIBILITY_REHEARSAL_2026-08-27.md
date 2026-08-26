# T1 Local Native-Browser Accessibility Rehearsal — 2026-08-27

## Decision summary

**Engineering conclusion: `OPEN / PARTIAL_PASS`.** A disposable native-browser rehearsal now provides local rendered evidence for four current T1 operational routes, including a real Laravel validation-error round trip, computed contrast samples, and reflow checks at 320 CSS pixels. The run also found and locally fixed one cross-route page-overflow defect.

This is not a WCAG conformance statement, assistive-technology acceptance, hosted role-based UAT, owner acceptance, or G3 closure. The tested bytes are base commit `d04b35f1f85ab0e6e56818d08c374f3a5bb3cb88` plus the current unpublished worktree. No commit, push, pull request, GitHub Actions run, deployment, hosted migration, or hosted mutation occurred.

```yaml
classification: LOCAL_NOT_DEPLOYED
standard_reference: WCAG_2_1_AA
data_boundary: synthetic_only
native_browser_engineering_result: PARTIAL_PASS
screen_reader: NOT_RUN
true_browser_zoom_200_percent: NOT_RUN
hosted_uat: NOT_RUN
owner_accessibility_acceptance: NOT_ACCEPTED
g3_status: OPEN
```

## Containment and synthetic-data proof

The first disposable launch was discarded after an orchestration review identified that an ignored local environment could theoretically supply `DB_URL`. Its browser tab and loopback server were closed, the port was confirmed closed, and the exact temporary directory was removed. No result from that launch is retained as gate evidence.

The retained run used:

- a sanitized temporary source copy excluding `.git`, `.env*`, `.vercel`, local SQLite files, runtime storage, cached configuration, `public/hot`, and repository documentation/deliverables;
- an environment allowlist (`env -i`) with `APP_ENV=local`, `APP_DEBUG=false`, `APP_MODE=SIMULATION`, `APP_SYNTHETIC_ONLY=true`, `BREAK_GLASS_MODE=off`, explicit empty `DB_URL`, explicit disposable SQLite path, database sessions, array mail, sync queue, array cache, stderr logging, and loopback-only `APP_URL`;
- `DEMO_SEED_ENABLED=true` and a fresh disposable credential only for migration/seeding, followed by a server restart with seeding disabled and the bootstrap credential absent from the server environment;
- a Laravel server bound only to `127.0.0.1:8766`; and
- a new browser origin with no reused application session.

All migrations completed through `2026_08_26_000200_create_daily_queue_allocator`. Direct inspection of the disposable SQLite database returned:

```text
patients|36|0 non-synthetic
users|6|0 addresses outside @example.invalid
```

No external application endpoint was configured or observed. OS-level egress blocking or packet capture was not used, so this record does not claim that all browser or operating-system network traffic was impossible.

## Browser environment

| Property | Observed value |
| --- | --- |
| Browser | Codex in-app native browser; exact engine version and user-agent were not exposed by the control surface |
| Normal viewport | 1280 × 720 CSS pixels |
| Device pixel ratio | 2 |
| Narrow reflow viewport | 320 × 720 CSS pixels |
| 200% viewport-equivalent proxy | 640 × 720 CSS pixels, derived from the 1280-pixel normal width |
| Data | Seeded synthetic teaching census only |
| Console | No error or warning entries on the four retained route passes |

The 640-pixel pass is a viewport-equivalent reflow proxy, not evidence of activating the browser's native 200% zoom command. True browser zoom remains `NOT_RUN`.

## Route and semantic results

| Route | Rendered heading | Table caption | Named page navigation | Result |
| --- | --- | --- | --- | --- |
| `/pendaftaran/rawat-jalan` | Data Pasien · Pendaftaran Rawat Jalan | Daftar pendaftaran pasien hari ini | Setting layanan; Navigasi halaman pendaftaran hari ini | `PARTIAL_PASS` |
| `/pemeriksaan/rawat-jalan` | Pemeriksaan · Rawat Jalan | Daftar pasien pada worklist pemeriksaan | Setting layanan; Navigasi halaman kunjungan aktif | `PARTIAL_PASS` |
| `/pemeriksaan/laboratorium` | Pemeriksaan · Laboratorium | Daftar order laboratorium aktif | Setting layanan | `PARTIAL_PASS` |
| `/pendaftaran/rekap` | Rekap pendaftaran | Rekap kunjungan berdasarkan filter pendaftaran | Setting layanan; Navigasi halaman kunjungan | `PARTIAL_PASS` |

Each route rendered the permanent `SIMULASI — DATA SINTETIS` note. A rendered control-name scan found zero unnamed non-hidden inputs, selects, textareas, or buttons in the main content on all four routes.

## Real validation-error round trip

The browser submitted an otherwise populated synthetic outpatient-registration form with an intentionally over-length insurance number. The retained Laravel response contained two field errors because the browser driver did not persist the controlled date value into React form state; this driver limitation does not change the focus and error-association observations below.

Observed after the server response:

- the named `role="alert"` summary became `document.activeElement`;
- the summary used `tabindex="-1"` and `aria-labelledby="registration-error-summary-title"`;
- summary links targeted `#date_of_birth` and `#insurance_number`;
- both inputs exposed `aria-invalid="true"` and stable `aria-describedby` references (`date_of_birth-error` and `insurance_number-error`);
- clicking the insurance-error summary link moved focus to `#insurance_number`; and
- the focused invalid input rendered a three-pixel error-colored focus ring through `box-shadow`.

An earlier native constraint-validation attempt also moved focus to the required date input before any server request. Sequential full-page Tab order and Enter/Space activation are not accepted from this run because the in-app control surface did not advance browser focus when injecting Tab and did not provide a trustworthy keyboard transcript.

## Rendered contrast samples

The native browser returned the following computed colors and WCAG contrast ratios:

| Element | Foreground | Background | Ratio | Threshold | Result |
| --- | --- | --- | ---: | ---: | --- |
| Registration heading | `rgb(15, 23, 42)` | white page background | 17.85:1 | 3:1 for observed 24 px semibold large text | `PASS` |
| Primary `Simpan` button | `rgb(255, 255, 255)` | `rgb(27, 117, 188)` | 4.86:1 | 4.5:1 | `PASS` |
| Validation summary text | `rgb(153, 27, 27)` | `rgb(254, 242, 242)` | 7.60:1 | 4.5:1 | `PASS` |

These samples do not constitute a complete color-contrast audit of every text, icon, border, state, or route.

## Reflow defect found and fixed locally

Initial 320-pixel measurements showed page-level horizontal expansion on all four routes because wide-table minimum widths propagated through flex/grid automatic minimum sizing. The document widths reached 762, 879, 459, and 860 pixels respectively.

The local correction:

- adds `min-w-0` to shared content/shell boundaries and all T1 table-owning sections/scroll wrappers;
- clips root-level horizontal overflow while leaving the explicit table containers scrollable; and
- changes recap filters to a constrained one-column base grid with shrinkable selects and wrapping actions.

The exact current build was then retested at 320 CSS pixels:

| Route | Controls clipped outside an intentional scroll container | Table container client/scroll width | Page scroll position on fresh navigation |
| --- | ---: | --- | ---: |
| Registration | 0 | 270 / 832 px | 0 |
| Examination | 0 | 270 / 896 px | 0 |
| Laboratory | 0 | 294 / 720 px | 0 |
| Recap | 0 | 286 / 896 px | 0 |

All four routes also returned zero clipped controls outside intentional table scrolling at the 640-pixel viewport-equivalent proxy. The regression contract now asserts `min-w-0` table containment on each page plus the recap one-column/wrapping filter layout.

## Local automated gate after the browser fix

| Gate | Result |
| --- | --- |
| Focused operational accessibility test | 1 file, 4 tests passed |
| Complete frontend suite | 10 files, 32 tests passed |
| TypeScript | Passed (`tsc --noEmit`) |
| ESLint | Passed |
| Prettier | Passed |
| Production build | Passed (2,306 modules transformed) |

## Evidence not established

- VoiceOver, NVDA, TalkBack, or another screen reader: `NOT_RUN`.
- Complete keyboard-only traversal, activation, focus restoration after pagination, and trap review: `NOT_RUN`.
- Native browser zoom at 200% and text-only resize: `NOT_RUN`; the 640-pixel result is only an explicit proxy.
- Complete rendered focus-visibility review for every interactive state: `NOT_RUN`.
- Touch-target geometry for every action: `NOT_RUN`.
- Hosted role-based accessibility UAT against an exact deployed SHA: `NOT_RUN`.
- Product-owner, accessibility/UX owner, Clinical, RMIK, Laboratory, and other affected owner acceptance: `NOT_ACCEPTED`.

Therefore this evidence narrows the local engineering gap but does not change any capability or workflow owner-acceptance value and does not close G3.
