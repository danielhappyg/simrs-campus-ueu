# T1 Structured RJ/RM Local Browser Rehearsal — 2026-08-29

**Status:** `LOCAL / PASS FOR BOUNDED SINGLE-ACCOUNT REHEARSAL`
**Application fix:** local commit `8e9f8b44a4abfbfef6ed03cd3f72d1fd2661c214` (`fix: refresh RM review concurrency tokens`)
**Data boundary:** disposable local SQLite containing generated synthetic teaching data only
**Runtime boundary:** `APP_MODE=SIMULATION`; `APP_SYNTHETIC_ONLY=true`
**Acceptance boundary:** development evidence only; not hosted UAT, role-separation evidence, domain-owner acceptance, G0 closure, or G3 acceptance
**Publication boundary:** local commits only; no push, pull request, release, deployment, hosted migration, or Production-alias change

## Result

One rendered local browser journey completed the current Structured Outpatient Documentation and RM Completeness v1 path through nursing Draft/Final, medical Draft/Final, laboratory order/final result, RM completeness review, sign-off, encounter closure, and read-only archive retrieval. The journey exposed a stale client-side concurrency-token defect at RM sign-off. The bounded correction was rebuilt and repeated in the same local browser session; the encounter then closed successfully with an attributable audit event and no remaining mutation controls.

This rehearsal used one multi-role teaching account rather than four isolated dedicated accounts. It therefore proves that the current rendered workflow can complete locally, but it does not prove role activation/revocation, separation of duties, wrong-role denial, hosted session fencing, or owner acceptance. The formal successor remains [T1 hosted Structured RJ/RM v1 UAT protocol](T1_HOSTED_STRUCTURED_RJ_RM_UAT_PROTOCOL_2026-08-27.md).

## Environment and evidence handling

- The application ran only on `http://127.0.0.1:8000` from the current developer worktree.
- `php artisan rebuild:status` reported `APP_MODE: SIMULATION` and `APP_SYNTHETIC_ONLY: true` after the rehearsal.
- Four pending local migrations were applied to the ignored SQLite database before the journey. `TeachingCensusSeeder` then produced 36 generated census patients; the resulting database contained 6 teaching users and 36 encounters.
- The rendered login and authenticated shell used the compact `Mode Kampus` status treatment rather than a large front-page simulation banner.
- A temporary production asset build was used for the corrected browser retest. Generated `public/build` changes were restored or removed afterward; the local server and browser tab were closed.
- No credential, password, cookie, session identifier, connection string, patient-identifying real-world data, or live-integration payload is retained in this record.
- The exact browser product/version was not captured. The rehearsal used the Codex in-app browser at a desktop viewport, so browser-version-specific acceptance remains unproven.
- The corrected browser execution used working-tree bytes that were subsequently committed as `8e9f8b44a4abfbfef6ed03cd3f72d1fd2661c214`; it was not repeated from a clean checkout of that exact SHA. This record is therefore not exact-SHA release evidence.

## Evidence bindings

The minimized machine-readable observation is [`T1_LOCAL_STRUCTURED_RJ_RM_BROWSER_REHEARSAL_2026-08-29.json`](evidence/T1_LOCAL_STRUCTURED_RJ_RM_BROWSER_REHEARSAL_2026-08-29.json), SHA-256 `97f41d8e41a569ff370a4e31f10bb0c2b5b3e78c5216abeda0a401fa9c8583eb`. It binds:

- the full application-fix commit and tree SHA;
- the two changed source paths and their exact SHA-256 values;
- the ignored SQLite snapshot SHA-256 at `2026-08-29T20:27:41+07:00`, the four migrations applied for the rehearsal, minimized journey counts, public identifiers, and audit observations;
- the test results as transient command-output summaries rather than retained CI artifacts;
- the independent review as a transient, unretained task observation rather than an external attestation; and
- the retained R3 non-authority ledger, its failed currentness check, and the fail-closed gate state below.

The receipt does not embed the database, browser trace, command logs, credentials, sessions, or environment file. Its SQLite hash binds the mutable local file only at the stated capture time; it is not a portable database artifact or a substitute for exact-engine/hosted evidence.

## Bounded journey

The pre-seeded synthetic encounter was:

| Field | Local synthetic value |
| --- | --- |
| MRN | `SYNTH-CENSUS-006` |
| Encounter public ID | `01M16S987B93TT0Z5ZN9KJG9QD` |
| Clinic | `Poliklinik Gigi` |
| Final state | `CLOSED` |

The following rendered actions completed:

1. Nursing assessment saved as Draft and finalized as an immutable Final version.
2. Medical assessment saved as Draft and finalized with required anamnesis, objective examination, clinical assessment, and care-plan fields.
3. One hemoglobin laboratory order was created. Before its result was final, RM correctly showed `NO_ACTIVE_LAB_ORDERS` as the single blocker and disabled sign-off.
4. A final synthetic hemoglobin result removed the active-order blocker.
5. RM displayed two source documents, all seven checks as `Sesuai`, blocker count zero, and enabled review/sign-off.
6. Review v1 was saved as `DRAFT` while the encounter remained `READY_FOR_RM`.
7. After the corrected sign-off submission, review v2 became `SIGNED_OFF`, the encounter became `CLOSED`, and the detail returned as an archive with zero buttons and zero editable controls.

The retained local database projection was:

| Projection | Observed |
| --- | ---: |
| Clinical document heads | 2 |
| Clinical document versions | 4 |
| RM completeness reviews | 2 |
| Persisted checklist items | 14 |
| Active laboratory orders | 0 |
| Browser console warnings/errors after corrected closure | 0 |

## Attributable local audit chain

The local audit table retained these successful events in Asia/Jakarta time:

| Action | Recorded at | Resource public ID |
| --- | --- | --- |
| `clinical.nursing.draft.save` | `2026-08-29 19:57:25` | `01M16SHAN6QN9A0W6WRMWRRPE4` |
| `clinical.nursing.finalize` | `2026-08-29 19:57:36` | `01M16SHAN6QN9A0W6WRMWRRPE4` |
| `clinical.medical.draft.save` | `2026-08-29 19:57:50` | `01M16SJ26ZJPQ838B8CMCS3TJ6` |
| `clinical.medical.finalize` | `2026-08-29 19:58:01` | `01M16SJ26ZJPQ838B8CMCS3TJ6` |
| `clinical.lab.order.create` | `2026-08-29 19:58:29` | `01M16SK8HDRXZZ372N5HV5VHRZ` |
| `clinical.lab.result.write` | `2026-08-29 19:59:29` | `01M16SK8HDRXZZ372N5HV5VHRZ` |
| `rmik.completeness.review.save` | `2026-08-29 19:59:54` | `01M16SNW2B58QRMFTTJ65XXEDB` |
| `rmik.completeness.signoff` | `2026-08-29 20:09:15` | `01M16T6ZKB4K0Z44A9N4JNNHA5` |

The earlier rejected sign-off attempts produced no sign-off audit row because request validation rejected the stale version before the lifecycle service ran. This absence is evidence of the discovered presentation/submission defect, not denial-audit coverage.

## Defect found and corrected

After review v1 saved successfully, Inertia preserved the mounted page component. The visible props advanced from review v0 to v1, but both `useForm` instances retained their mount-time concurrency payload. The sign-off confirmation consequently submitted `expected_version: 0`; request validation rejected it, the modal remained open, the encounter stayed `READY_FOR_RM`, and no sign-off audit event was written.

The local correction refreshes `expected_version` and `source_fingerprint` from the currently rendered review immediately before both review-save and sign-off POSTs. This also closes the same retained-state defect for a repeated review save. The installed Inertia React implementation updates its internal submission reference synchronously, so the same-tick POST reads the refreshed values.

A frontend regression now renders review v0, rerenders the same component as complete review v1, and proves that both actions submit the current v1 and fingerprint. A transient independent read-only task observation found no P1/P2 issue in the two-file correction; no external reviewer attestation or durable review transcript was retained.

## Verification

| Check | Result |
| --- | --- |
| Focused outpatient PHP suites | 36 tests, 556 assertions, PASS |
| Full frontend unit suite | 12 files, 45 tests, PASS |
| TypeScript | PASS |
| ESLint on changed frontend files | PASS |
| Prettier on changed frontend files | PASS |
| Rendered corrected sign-off | review v2 `SIGNED_OFF`; encounter `CLOSED`; zero mutation controls |
| Browser warning/error console | Empty after corrected closure |
| Independent bounded review | Transient task observation: PASS with no P1/P2; no external attestation retained |

## Retained 268-capability ledger and current fail-closed gate binding

The receipt binds the latest retained observation [`G0_G3_COVERAGE_LEDGER_V2_2026-08-29_R3.json`](../new-simrs-rebuild/G0_G3_COVERAGE_LEDGER_V2_2026-08-29_R3.json), SHA-256 `0f312713000b0092de313baee2d3cc6d34695c2fefd717c620871268045ac58c`. R3 reports `governance_profile_binding.status=unavailable`, `reason_code=pointer_missing`, zero non-null governance pointers, all 268 capability rows `PENDING`, zero implementation-authorized rows, G0 `OPEN`, and G3 `OPEN`.

After the RM fix, the required currentness command failed with `$.engineering_evidence_map_v2: generated document differs from exact engineering projection`. The canonical consumer pointer and recovery marker were both absent at the recorded check. R3 must therefore **not** be treated as a current exact-engineering ledger. The current fail-closed conclusion is `G0_AND_G3_OPEN_UNPROVEN_NO_CURRENT_LEDGER` until a separately authorized append-only successor is produced.

This binding prevents the local browser PASS from floating independently of the stale-ledger condition. It does not change authority or authorize any capability, workflow slice, consumer operation, deployment, or acceptance.

## Explicitly unproven

- registration of a fresh encounter in the same journey;
- four distinct registrar, nurse, physician, and RMIK identities;
- temporary role-account activation, expiry, revocation, alternate-authentication fencing, and zero-artifact closeout;
- wrong-role route denial with zero mutation and attributable denial audit;
- hosted Vercel/Supabase schema, release SHA, session, proxy, and concurrency behavior;
- exact-SHA clean-checkout execution;
- complete keyboard, screen-reader, responsive, contrast, focus, touch-target, load, and performance acceptance;
- Clinical or RMIK owner acceptance, any of the 268 capability dispositions, G0 closure, or G3 acceptance;
- real-patient suitability or any live BPJS/VClaim/SATUSEHAT, LIS, PACS, payment, or other production integration.

The next acceptance step for this workflow remains a separately authorized hosted run of the four-role protocol. This bounded local result must not be promoted to hosted PASS, owner acceptance, deployment readiness, or G3 completion.
