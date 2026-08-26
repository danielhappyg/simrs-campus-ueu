# Handoff — SIMRS Campus UEU teaching rebuild (outpatient core)

**Audience:** Daniel, facilitators, and any human or agent continuing this work without prior chat history.  
**Date:** 2026-08-23; current-truth baseline reconciled on 2026-08-25<br>
**Current production baseline:** `42ab482de577fe38cef539a74f0b749d64485b19` on `main` / Vercel deployment `dpl_4uGZACFzKsHMnNUy6YshiJjeQN1Q`<br>
**Earlier lifecycle baseline:** `5e9c43a` (synthetic/PostgreSQL hardening; lab slice originated in `807bbf2` / PR #48)<br>
**Live demo:** https://simrs-campus-ueu-demo.vercel.app  
**Repo:** https://github.com/danielhappyg/simrs-campus-ueu  

This is the **start-here** pack for the clean-slate teaching SIMRS. It is not limited to the latest lab commit: it covers product identity, the full RJ teaching arc, adjacent care desks, evidence, known traps, and what to build next.

---

## 1. What this product is

| Item | Value |
| --- | --- |
| Name | SIMRS Campus UEU — campus teaching hospital information system |
| Posture | **Simulation only** — `APP_MODE=SIMULATION`, `APP_SYNTHETIC_ONLY=true` |
| Data | Synthetic `example.invalid` accounts and patients only |
| Hosting now | Vercel (PHP community runtime) + Supabase Postgres schema `laravel` |
| Campus production host | **TBD** — do not treat the Vercel demo as clinical production |

**North-star decisions (must not be re-litigated casually):**

| ID | Decision | Doc |
| --- | --- | --- |
| DEC-013 | Live vendor SIMRS is **UI/IA reference**; the failed Antrean/work-queue teaching MVP is **anti-reference** (do not restore) | `docs/new-simrs-rebuild/phase-0/DECISION_LOG.md` |
| DEC-014 | **SAHABAT Data Pasien desk density** is the minimum bar for Pendaftaran (and similar care desks) | same + `docs/new-simrs-rebuild/PENDAFTARAN_SAHABAT_FIELD_MAP.md` |
| DEC-015 | Every interactive teaching screen, including login and authenticated desks, retains a restrained, permanent **`SIMULASI — DATA SINTETIS`** indicator; backend synthetic-only enforcement remains mandatory | same + `docs/new-simrs-rebuild/phase-1/UI_DIRECTION.md` |
| DEC-016 (**Proposed**) | Active lab orders block RM close; closed encounters reject late results; one immutable FINAL result completes an active order. This is Proposed NEW teaching safety, not SAHABAT-observed parity; Clinical/Laboratory and RMIK approval remains unresolved | same + `docs/new-simrs-rebuild/phase-1/OUTPATIENT_ORDER_RESULT_CLOSURE_CONTRACT.md` |
| UI tokens | UEU blue `#1b75bc`, orange `#f26a1b`, navy sidebar — copy campus SI patterns, not ad-hoc purple themes | `docs/new-simrs-rebuild/phase-1/UI_DIRECTION.md` |

**Hosting ops:** `docs/operations/CURRENT_HOSTING_POSTURE.md` · `docs/operations/VERCEL_SUPABASE_DEMO.md`

---

## 2. What is available today (teaching-demo quality)

“Available” means the code exists on `main`. It does **not** mean complete SAHABAT parity, clinical production readiness, or faculty acceptance. Build availability and parity acceptance are tracked separately in the parity matrix.

### 2.1 Core RJ teaching arc (primary story)

```
Pendaftaran RJ → Pemeriksaan RJ (structured nursing + medical documents)
              → Order Lab → Laboratorium (one FINAL result)
              → RM RJ (automatic checklist, review, attributable sign-off)
              → optional Cetak + Rekap
```

**Facilitator script (use this in class):**  
[`docs/operations/TEACHING_RJ_FACILITATOR_RUNBOOK_2026-08-22.md`](TEACHING_RJ_FACILITATOR_RUNBOOK_2026-08-22.md)

**Encounter status spine** (structured documents drive status; lab does not):

```
REGISTERED → IN_EXAMINATION → READY_FOR_RM → CLOSED
     ↑              ↑                ↑            ↑
 Pendaftaran  Nursing document Medical FINAL  RM sign-off
                                   Current incomplete checklist or ACTIVE lab order blocks sign-off
```

### 2.2 Routes (auth + simulation middleware)

| Area | Method | Path |
| --- | --- | --- |
| Pendaftaran | GET/POST | `/pendaftaran/rawat-jalan` |
| Cetak | GET | `/pendaftaran/kunjungan/{encounter}/cetak` |
| Rekap | GET | `/pendaftaran/rekap` |
| Pemeriksaan RJ | GET | `/pemeriksaan/rawat-jalan`, `/pemeriksaan/rawat-jalan/{encounter}` |
| Structured document | POST | `/pemeriksaan/rawat-jalan/{encounter}/documents/{documentType}/draft`, `/pemeriksaan/rawat-jalan/{encounter}/documents/{documentType}/final` |
| Lab order | POST | `/pemeriksaan/rawat-jalan/{encounter}/lab-orders` |
| Lab desk | GET/POST | `/pemeriksaan/laboratorium`, `/pemeriksaan/laboratorium/{order}/results` |
| RM | GET/POST | `/rm/rawat-jalan`, `/rm/rawat-jalan/{encounter}`, `/rm/rawat-jalan/{encounter}/reviews`, `/rm/rawat-jalan/{encounter}/signoff` |

### 2.3 Domain tables (Postgres `laravel` schema on demo)

| Table | Purpose |
| --- | --- |
| `patients` | Synthetic identity, MRN, wilayah, marital status |
| `encounters` | Visit + status spine + care_setting |
| `clinical_entries` | Legacy outpatient notes retained read-only; non-outpatient legacy use remains |
| `outpatient_clinical_documents` / `outpatient_clinical_document_versions` | Current structured document heads + immutable version history |
| `outpatient_rm_completeness_reviews` / `outpatient_rm_completeness_items` | Versioned completeness snapshot, checklist items, reviewer and sign-off provenance |
| `lab_service_requests` | Physician lab orders |
| `lab_diagnostic_results` | Nurse-entered synthetic results |
| `clinics` / `doctors` / `clinic_schedules` | Poli → dokter → jadwal |
| `wilayah_*` | Province → village cascade |
| `roles` / `permissions` / pivots | RBAC |
| `audit_events` | Append-only audit |

Slice write-up: [`docs/new-simrs-rebuild/phase-3/README.md`](../new-simrs-rebuild/phase-3/README.md)

### 2.4 Adjacent desks (available, not the primary UAT arc)

Also on `main` with SAHABAT-density desks:

- **IGD / Triage** — Pendaftaran + Pemeriksaan + triage stub worklist (PR #41)
- **Rawat Inap** — admission + examination desks (PR #42)

Treat these as partial teaching desks in parallel care settings. Automated tests exist, but hosted role-based UAT and deep clinical ED/RI parity are still unfinished.

### 2.5 Parity IDs for the RJ arc

| ID | Build availability | Parity acceptance |
| --- | --- | --- |
| PAR-REG-003 | Teaching desk available; registration/cetak/rekap slice evidence recorded | **Not accepted** — remains Specified |
| PAR-CLN-004 | Versioned structured nursing/medical documents available; many broader clinical tabs remain stubbed | **Not accepted** — remains Specified; Clinical owner acceptance unresolved |
| PAR-CLN-006 | RJ order + worklist + immutable FINAL result available; no tarif/LIS/specimen/preliminary/amendment | **Not accepted** — remains Specified; lifecycle guard is Proposed NEW |
| PAR-RMIK-001 | Automatic completeness checklist, durable review, attributable sign-off, and ACTIVE-lab blocker available | **Not accepted** — remains Specified; RMIK and Clinical/Laboratory approval unresolved |

Matrix: [`docs/new-simrs-rebuild/PARITY_REQUIREMENTS_MATRIX.md`](../new-simrs-rebuild/PARITY_REQUIREMENTS_MATRIX.md)

---

## 3. Milestone history (rebuild desk era → lab)

Read bottom-up for “how we got here.” Older Checkpoint 2 / Antrean MVP work is **historical**; DEC-013 forbids treating the failed MVP as the UX target.

| PR | Commit | What landed |
| --- | --- | --- |
| — | `f07dce1` | Phase 3 outpatient slice: register, exam notes, RM |
| — | `f134a74` / `ddb8c50` | Demo parity + RBAC schema hardening |
| **#39** | `d15cfa6` | Pendaftaran RJ → SAHABAT-grade desk density |
| **#40** | `7d6dee7` | Pemeriksaan + RM worklist densify |
| **#41** | `c10a22f` | ED/IGD registration, exam, triage desks |
| **#42** | `f17c252` | Rawat Inap admission + exam desks |
| **#43** | `5ce73c3` | Wilayah cascade + teaching census density |
| **#44** | `8880514` | Harden Pendaftaran when wilayah reads fail |
| **#45** | `cf75b53` | Teaching cetak bukti/antrian/SEP + rekap |
| **#46** | `80d6d80` | Align Pendaftaran metadata codes/labels |
| **#47** | `9fc0b3b` | Fix Simpan HTTP 500 (`SchemaAwareRules`) |
| **#48** | `807bbf2` | Lab order + laboratorium result teaching slice |
| **#50** | `c6979de` | Enforce the bounded outpatient lab-result/RM-closure lifecycle contract |
| docs | `09f4f84`…`315d912` | Facilitator runbook + UAT Runs 1–3 evidence |
| — | `2596063` | Structured outpatient Draft/Final documents, immutable versions, automatic RM completeness review and attributable sign-off |
| — | `aabfff5` | Shorten PostgreSQL constraint names for portable hosted migration |
| **#55** | `42ab482` | G0 parity governance validator, owner-control baseline and attributable rebuild-admin correction path |

**Current production deployment (demo):** `dpl_4uGZACFzKsHMnNUy6YshiJjeQN1Q` for `42ab482…b19`; Vercel state `READY`, target `production`, public alias `simrs-campus-ueu-demo.vercel.app`. The Supabase `laravel` schema remains on the previously verified structured-document baseline: migration `2026_08_24_000100_create_outpatient_documentation_tables` in batch 7 and all four structured-document/RM tables present. See `docs/operations/T0_CURRENT_TRUTH_BASELINE_2026-08-25.md`.

---

## 4. Evidence and how to verify

### 4.1 Teaching UAT records

| Record | Doc | Verdict |
| --- | --- | --- |
| Runs 1–2 | [`TEACHING_UAT_CETAK_REKAP_METADATA_2026-08-22.md`](TEACHING_UAT_CETAK_REKAP_METADATA_2026-08-22.md) | PASS after #47 — register metadata, Simpan, cetak, rekap |
| Run 3 | [`TEACHING_UAT_LAB_SLICE_2026-08-22.md`](TEACHING_UAT_LAB_SLICE_2026-08-22.md) | PASS — HB order + FINAL result on production |
| Run 4 | [`TEACHING_UAT_CONTINUOUS_RJ_LIFECYCLE_2026-08-24.md`](TEACHING_UAT_CONTINUOUS_RJ_LIFECYCLE_2026-08-24.md) | **PASS with one partial manual-evidence item** — continuous role-specific journey, lifecycle denials, close, cetak and rekap on `c6979de` |

Run 4 proves one continuous role-switched hosted journey on a single synthetic encounter. The active-order blocker passed in the RM UI, but the disabled action meant the manual run did not send a stale/direct close request and therefore did not generate an `active_lab_orders` denial audit. Automated tests remain the evidence for that server rejection. This is an explicit partial manual-evidence boundary, not a product failure. DEC-016 remains **Proposed** and still requires Clinical/Laboratory and RMIK owner review.

### 4.2 Automated tests (local)

```bash
php artisan test --filter=Outpatient
# Includes OutpatientFlowTest + OutpatientLabFlowTest (+ related outpatient suites)
```

### 4.3 Live demo smoke

1. `GET https://simrs-campus-ueu-demo.vercel.app/up` → 200  
2. Banner **SIMULASI — DATA SINTETIS** after login  
3. Follow facilitator runbook § Full teaching arc  

### 4.4 Program test strategy

`docs/new-simrs-rebuild/TESTING_AND_UAT_STRATEGY.md`

---

## 5. Demo accounts (emails only)

Password: Vercel / secure store env **`DEMO_ACCOUNT_PASSWORD`** only. Never commit or paste into slides.

| Email | Role / use |
| --- | --- |
| `registrar.demo@example.invalid` | Pendaftaran, rekap, cetak |
| `nurse.demo@example.invalid` | Nursing note, lab result |
| `physician.demo@example.invalid` | Medical note, lab order |
| `rmik.demo@example.invalid` | RM close |
| `mahasiswa.rmik@example.invalid` | Solo multi-role rehearsal |
| `admin.rebuild@example.invalid` | Admin bootstrap |
| `fasilitator.simulasi@example.invalid` | Used in Pendaftaran UAT Run 2 (roster account; may not match DemoActorsSeeder) |

Seeder source: `database/seeders/DemoActorsSeeder.php`

**Ops caveat:** Local `.env.vercel.local` can go **stale** vs Vercel Production. Prefer password from Vercel dashboard or a freshly pulled env if login returns `auth.failed`.

---

## 6. RBAC (teaching)

| Role | Capabilities that matter for the arc |
| --- | --- |
| registrar | `patient.search`, `patient.view`, `patient.register`, `encounter.list`, `encounter.open`, `encounter.cancel` |
| nurse | `clinical.nursing.write`, `clinical.lab.result.write`, `clinical.amend` (reserved; amendment workflow not built) |
| physician | `clinical.medical.write`, `clinical.order.create`, `clinical.amend` (reserved; amendment workflow not built) |
| rmik | `rmik.review` (worklist), `rmik.completeness.signoff` (close), coding caps reserved |
| admin | user/role/audit/synthetic reset |

Detail: `docs/new-simrs-rebuild/phase-2/RBAC_MATRIX.md` · code: `app/Support/Authorization/`

Audit actions include: `patient.register`, `clinical.outpatient_document.draft.save`, `clinical.outpatient_document.finalize`, `clinical.lab.order.create`, `clinical.lab.result.write`, `rmik.completeness.review.save`, and `rmik.completeness.signoff`.

---

## 7. Explicit stubs and out of scope

Say these honestly in demos and planning:

| Area | Status |
| --- | --- |
| Exam tabs Diagnosa / Tindakan / **Order Rad** / **Resep** | Stub UI (structured nursing/medical documentation and Order Lab are live) |
| Exam header Cetak / Riwayat EMR / Order / Resep | Stub |
| Nav Klaim / BPJS / Apotek | **Soon** placeholders |
| Production SEP / VClaim / SATUSEHAT | Forbidden on this demo |
| LIS, specimen, tarif, PA/mikro lab desks | Not built |
| Preliminary lab result, final-result amendment, lab cancellation, encounter reopen | Not built; requires Clinical/Laboratory + RMIK approval |
| ICD coding UI | Not built |
| Pharmacy dispense / charges / kasir | Not built |
| Antrean kerja / old teaching MVP | Do not restore (DEC-013) |
| Real patient data | Never |

Density honesty: `docs/new-simrs-rebuild/SAHABAT_VS_DEMO_GAP.md` (historical “why it looked thin”); Pendaftaran has since been densified (#39+) but Pemeriksaan/RM may still lag SAHABAT bar in places.

---

## 8. Lessons and traps (do not rediscover)

| Trap | Fix / rule |
| --- | --- |
| **`Rule::exists` on schema-qualified `laravel.clinics`** parsed as DB connection `laravel` → Simpan HTTP 500 | Use model classes via `SchemaAwareRules` (PR #47). Never pass `schema.table` strings into Laravel exists/unique rules on Postgres. |
| **React controlled Pendaftaran Simpan** looks dead if fields not bound | Fill desk UI fields properly; refresh once if needed; Inertia POST is the reliable path |
| **`APP_DEBUG`** | Keep `false` on Vercel Production/Preview after diagnosis |
| **Vercel does not migrate** | Apply new migrations on Supabase separately (`php artisan migrate --force` with Session Pooler env, or MCP/SQL with schema `laravel`) |
| **Lab RBAC after deploy** | New capability `clinical.lab.result.write` must exist on nurse role on hosted DB |
| **Browser recording** | Harness may capture few frames; UAT markdown + IDs are primary evidence |
| **Schema-qualified models** | Prefer `UsesSchemaQualifiedTable` + `HasPublicUlid` for new domain tables |

---

## 9. Doc map (read in this order)

### Start here

1. **This handoff**  
2. Facilitator runbook — `docs/operations/TEACHING_RJ_FACILITATOR_RUNBOOK_2026-08-22.md`  
3. Phase 3 slice — `docs/new-simrs-rebuild/phase-3/README.md`  
4. Demo ops — `docs/operations/VERCEL_SUPABASE_DEMO.md`

### Product / parity

| Doc | Purpose |
| --- | --- |
| `docs/new-simrs-rebuild/phase-0/DECISION_LOG.md` | Locked decisions |
| `docs/new-simrs-rebuild/phase-1/UI_DIRECTION.md` | UI/IA rules |
| `docs/new-simrs-rebuild/PARITY_REQUIREMENTS_MATRIX.md` | 268-menu parity |
| `docs/new-simrs-rebuild/phase-1/OUTPATIENT_SLICE_DISPOSITIONS.md` | RJ dispositions |
| `docs/new-simrs-rebuild/phase-1/requirements/PAR-REG-003-rawat-jalan-registration.md` | Registration FR |
| `docs/new-simrs-rebuild/phase-1/requirements/PAR-CLN-004-rawat-jalan-examination.md` | Examination FR |
| `docs/new-simrs-rebuild/phase-1/STRUCTURED_RJ_DOCUMENTATION_RM_COMPLETENESS_FR_PACK.md` | Combined clinical/RMIK pack; bounded v1 engineering authorized, broader owner decisions open |
| `docs/new-simrs-rebuild/phase-1/STRUCTURED_RJ_DOCUMENTATION_RM_COMPLETENESS_WIREFRAME.md` | Indonesian/UEU handoff; bounded v1 authorized, Clinical/RMIK acceptance pending |
| `docs/new-simrs-rebuild/phase-1/STRUCTURED_RJ_DOCUMENTATION_RM_COMPLETENESS_V1_IMPLEMENTATION_DECISION.md` | Product-authorized narrow v1 engineering boundary; Clinical/RMIK acceptance still pending |
| `docs/new-simrs-rebuild/phase-1/STRUCTURED_RJ_RM_OWNER_DECISION_PACK_2026-08-25.md` | Ready-to-record Clinical, Laboratory and RMIK decisions for structured v1 and DEC-016 |
| `docs/new-simrs-rebuild/PENDAFTARAN_SAHABAT_FIELD_MAP.md` | Field map |
| `docs/new-simrs-rebuild/DELIVERY_ROADMAP.md` | Long-arc roadmap |
| `docs/new-simrs-rebuild/TESTING_AND_UAT_STRATEGY.md` | Test/UAT program |

### UAT evidence

| Doc | Purpose |
| --- | --- |
| `docs/operations/TEACHING_UAT_CETAK_REKAP_METADATA_2026-08-22.md` | Runs 1–2 |
| `docs/operations/TEACHING_UAT_LAB_SLICE_2026-08-22.md` | Run 3 |
| `docs/operations/TEACHING_UAT_CONTINUOUS_RJ_LIFECYCLE_2026-08-24.md` | Run 4 continuous lifecycle evidence and cleanup record |
| `docs/new-simrs-rebuild/TESTER_NOTES_2026-08-22_CETAK_SEP_REKAP.md` | Scope lock for cetak/SEP |

### Vendor / assessment (context, not implementation)

| Doc | Purpose |
| --- | --- |
| `docs/vendor-simrs-assessment-2026-08-21/` | 268-menu assessment pack |
| `docs/SIMRS_CAMPUS_MASTER_PLAN.md` | Program plan |
| Root `README.md` | Local bootstrap + safety |

### Older Checkpoint 2 / pre-rebuild lab (do not confuse with #48)

`docs/operations/OUTPATIENT_CHECKPOINT_2_*`, `OUTPATIENT_LAB_PILOT_RUNBOOK.md` — faculty Checkpoint 2 era and MVP-era lab pilot. Useful for history; **not** the current clean-slate lab implementation.

---

## 10. Code entry points

| Concern | Path |
| --- | --- |
| Routes | `routes/web.php` |
| Pendaftaran | `app/Http/Controllers/Outpatient/OutpatientRegistrationController.php` |
| Cetak / rekap | `OutpatientPrintController.php`, `OutpatientRecapController.php` |
| Pemeriksaan | `OutpatientExaminationController.php` |
| Lab | `app/Http/Controllers/Clinical/LaboratoryController.php` |
| RM | `OutpatientRmController.php` |
| Lab catalog | `app/Support/Clinical/LabTestCatalog.php` |
| Schema-safe validation | `app/Support/Database/SchemaAwareRules.php` |
| Audit | `app/Support/Audit/AuditRecorder.php` |
| UI RJ exam | `resources/js/pages/pemeriksaan/rawat-jalan/show.tsx` |
| UI lab desk | `resources/js/pages/pemeriksaan/laboratorium/index.tsx` |
| UI Pendaftaran | `resources/js/pages/pendaftaran/rawat-jalan.tsx` |
| Tests | `tests/Feature/Outpatient/OutpatientFlowTest.php`, `OutpatientLabFlowTest.php` |

---

## 11. What is NOT in git (warn consumers)

Cloning GitHub alone will **not** include:

| Local-only path | Notes |
| --- | --- |
| `docs/legacy-visual-field-capture/` | Screenshots / field dictionaries — privacy intake may still be pending |
| `deliverables/` | North-star decks and working PPTX/PDF |
| `.vercel/` | Local Vercel project link |
| `.env`, `.env.vercel.local` | Secrets — gitignored |
| Browser-harness recordings under `~/.config/browser-harness/...` | Cited in UAT; not in repo |

Committed visual oracles for SAHABAT (DEC-014): `docs/new-simrs-rebuild/_evidence/sahabat-page-*.png` when present.

---

## 12. Current next work — focused UAT and owner acceptance

### 2026-08-26 local continuation — not deployed

- The unpublished worktree now contains a transactional Asia/Jakarta daily queue allocator shared by RJ, IGD, and RI, with local PostgreSQL 17 contention, rollback-reuse, query-plan, and same-host recovery evidence. This does not change hosted behavior or owner acceptance.
- Exact-engine local MySQL 8.4.11 verification is now recorded in `docs/operations/T1_LOCAL_MYSQL84_INTEGRATION_EVIDENCE_2026-08-26.md`: all current migrations, the full application suite, the focused administration/audit slice, and rollback/reapply passed. The host used PHP 8.5.7 rather than CI PHP 8.3; hosted evidence, concurrency, performance, and recovery remain unproven.
- A reusable current-manifest portability harness now passes fresh migration and the complete 453-test PHP suite on a harness-owned PostgreSQL 17.10 cluster/private Unix socket/`laravel` schema (452 passed, 1 skipped; 5,863 assertions) and an isolated exact MySQL 8.4.11/InnoDB server (446 passed, 7 skipped; 5,834 assertions). E2E-01/02/03/04/05/12/15/16 focused slices also pass on both engines against identical pre/post backend, migration, harness, workflow-catalogue, and pre-run manifest bindings; E2E-02 now includes the continuous synthetic registrar-to-nurse-to-physician IGD route journey and wrong-role denial (12 tests; 202 assertions on each engine). The ordinary manifest gate parses both records and rejects stale inner bindings; bootstrap mode cannot claim PASS. This is local PHP 8.5.7 portability evidence only; hosted execution, PHP 8.3 equivalence, contention/load, and owner acceptance remain open. See `docs/operations/T1_LOCAL_CURRENT_MANIFEST_PORTABILITY_EVIDENCE_2026-08-27.md` and `docs/operations/T1_CONTINUOUS_EMERGENCY_SCAFFOLD_EVIDENCE_2026-08-27.md`.
- An earlier changed-source security snapshot covered all 42 files in its then-current inventory and found zero P0/P1/P2 findings and three open P3/low candidates: password-reset account enumeration, unbounded total work in recap CSV export, and origin-trusting pagination redirects. It is not a complete review of the later 137-file milestone. No P3 remediation has been authorized or applied, and hosted proxy/load behavior remains unknown. A separate defensive review drove the portability harness's isolated-cluster, binding, executable-path, cleanup, and evidence-directory hardening.
- A bounded local accessibility increment corrected programmatic labels and table action semantics across the current registration, examination, laboratory, and recap surfaces. Four representative page states and first/middle/last pagination states pass local axe WCAG 2.1 A/AA DOM checks. A sanitized native-browser rehearsal then passed route semantics, one real validation-focus response, three contrast samples, and four-route 320-pixel reflow after finding and fixing a page-level overflow defect. This remains `OPEN/PARTIAL`: complete keyboard traversal, screen reader, native 200% zoom, full contrast/focus/touch coverage, hosted UAT, and owner acceptance remain open. See `docs/operations/T1_LOCAL_ACCESSIBILITY_ENGINEERING_EVIDENCE_2026-08-27.md` and `docs/operations/T1_LOCAL_NATIVE_BROWSER_ACCESSIBILITY_REHEARSAL_2026-08-27.md`.
- Registration failed-submit behavior now exposes stable inline error IDs and control associations plus a linked, named error summary that receives focus after each failed Inertia attempt. A four-error automated state—including one composite input—passes local semantic, repeat-focus, and axe regression; the native browser additionally proved one real Laravel response with summary focus, linked invalid-control focus, and a visible three-pixel invalid-state ring. Screen-reader announcement, complete keyboard order, and hosted owner acceptance remain unproven.
- The G0–G3 engineering overlay has been refreshed for the allocator and bounded accessibility contributions. The formal gate remains `OPEN`: all 268 accountable-owner, approval, and release-evidence references are still pending, and no capability has owner acceptance `PASS`.
- A local-only BG-03 comparison resolver now evaluates the proposed privileged-access fact chain without changing any Gate result. Default `off` is query/telemetry free; shadow facts require UTC database time, bounded TTL/deadline chronology, strong assurance, exact runtime/scope bindings, no revocation, and an HMAC-bound session. The bounded E2E-16 slice passes SQLite, PostgreSQL 17, and exact MySQL 8.4 locally. Hosted observation, engine-specific load acceptance, owner acceptance, and all BG-04 through BG-08 activation/cutover work remain open. See `docs/operations/T1_BG03_PRIVILEGED_ACCESS_SHADOW_EVIDENCE_2026-08-27.md`.
- GitHub publication is deliberately batched to protect the available free quota. Local implementation, tests, and review may continue, but commit, push, PR, GitHub Actions, Vercel deployment, and hosted Supabase migration require a separate explicit milestone approval.
- The next correction-safe outpatient candidate is documented in `docs/new-simrs-rebuild/phase-1/OUTPATIENT_POST_CLOSURE_AMENDMENT_FR_PACK.md` and `docs/operations/ADR_OUTPATIENT_POST_CLOSURE_AMENDMENT_2026-08-26.md`. Both are proposals only. Do not implement the amendment request/addendum/re-review lifecycle until named Clinical and RMIK owners decide its semantics.
- The highest-leverage cross-setting correction candidate is now documented in `docs/new-simrs-rebuild/phase-1/CROSS_SETTING_PRECLINICAL_ENCOUNTER_CANCELLATION_FR_PACK_2026-08-27.md` and `docs/operations/ADR_CROSS_SETTING_PRECLINICAL_ENCOUNTER_CANCELLATION_2026-08-27.md`. It proposes cancellation only for synthetic `REGISTERED` RJ/IGD/RI encounters with zero dependent facts, while preserving queue/registration/bed history and inventing no downstream reversal. It is **PROPOSED / NOT AUTHORIZED**; do not add the route/state until the named owners record all required choices.
- An owner-independent prerequisite now routes existing IGD/RI clinical-note writes through an encounter-first locked transaction with fresh state validation and atomic audit. Local full-suite verification passes, but PostgreSQL/MySQL independent-process race evidence remains open. This does not implement or approve cancellation. See `docs/operations/T1_CROSS_SETTING_CLINICAL_ENTRY_LOCKING_EVIDENCE_2026-08-27.md`.
- The T1 exact-source milestone freeze, semantic evidence gate, and approval pack are complete locally. They must remain `NOT_DEPLOYED` until the publication gate above is explicitly opened.

The bounded lifecycle guard is deployed and continuous hosted UAT Run 4 passed on 2026-08-24. The synthetic evidence encounter was retained; temporary role accounts were disabled, password values made unusable, sessions revoked and temporary credential/session files deleted. Closeout verification returned `active_session_rows=0`, `disabled_account_rows=4` and `preserved_closed_encounter_rows=1`. Global `simulation:reset` was not run because it is not encounter-scoped.

Next sequence:

1. Keep DEC-016 **Proposed** and obtain Clinical/Laboratory and RMIK review of the FINAL-only, active-order closure and late-result policy. Run 4 is implementation evidence, not owner acceptance or SAHABAT parity.
2. Treat the missing manual `active_lab_orders` denial audit honestly: the UI blocker passed; the server rejection is covered by automated tests. Repeat a hosted stale/direct denial only if an explicit acceptance plan requires it.
3. The structured migration baseline at `aabfff5` and current application deployment at `42ab482` were verified on 2026-08-25. Run a focused hosted UAT for **Structured Outpatient Documentation and RM Completeness v1**: nursing Draft/Final, medical Draft/Final, immutable version history, automatic checklist, review, sign-off, and closed read-only retrieval.
4. Obtain Clinical and RMIK owner acceptance decisions for the bounded fields, Draft/Final meaning, checklist, and one-person teaching sign-off. Engineering availability alone does not change PAR-CLN-004 or PAR-RMIK-001 to Accepted.
5. Draft the **PAR-CLN-007 Radiology requirements pack** after the focused UAT and owner review. Do not implement “Order Rad like Lab” until scheduling, verification, correction and PACS boundaries have an approved workflow and acceptance contract.
6. Preserve the unresolved lifecycle boundary: preliminary results, amendment, reopen and cancellation remain unbuilt.

---

## 13. Handoff checklist for the next owner

- [ ] Clone `main`, confirm the intended production baseline is ≥ `c6979de` (lifecycle contract; synthetic/PostgreSQL hardening is included)
- [ ] Read this file + facilitator runbook  
- [ ] Confirm demo `/up` and simulation banner  
- [ ] Obtain `DEMO_ACCOUNT_PASSWORD` out-of-band  
- [x] Continuous role-specific RJ UAT Run 4 recorded on 2026-08-24; use its partial manual-evidence note accurately
- [x] Draft combined PAR-CLN-004 + PAR-RMIK-001 owner-decision pack and wireframe
- [x] Record the bounded v1 engineering implementation decision without claiming Clinical/RMIK acceptance
- [x] Implement and locally verify the bounded Structured Outpatient Documentation and RM Completeness v1 engineering slice
- [x] Apply and verify the explicit Supabase migration (`aabfff5`) and promote the current exact pushed application commit (`42ab482`; reconciled 2026-08-25)
- [ ] Record focused hosted Structured RJ/RM v1 UAT evidence
- [ ] Obtain Clinical and RMIK decisions before broadening v1 fields/workflows or claiming owner acceptance
- [ ] Record Registration, IGD, Bed Management, Clinical, RMIK, Reporting, downstream-domain, and technical decisions for the proposed pre-clinical cancellation contract before implementation
- [ ] Obtain Clinical/Laboratory and RMIK owner review of DEC-016
- [x] Record bounded local native-browser accessibility evidence for registration, examination, laboratory, and recap, including the 320-pixel reflow correction
- [x] Implement and locally verify non-authoritative BG-03 comparison mode without changing legacy authorization outcomes
- [x] Run local BG-03 PostgreSQL 17/MySQL 8.4 portability evidence while keeping default `off`
- [x] Bind the final PostgreSQL/MySQL pair through a semantic manifest gate that rejects stale inner execution digests
- [ ] Run separately approved hosted BG-03 observation/load evidence before any shadow activation; keep default `off`
- [ ] Complete native keyboard, screen-reader, true 200% zoom, touch-target, and hosted accessibility acceptance evidence
- [ ] Before schema work: remember Supabase migrate is **manual** on Vercel  
- [ ] Before validation work: use `SchemaAwareRules` / model classes, never `laravel.table` strings in `Rule::exists`  
- [ ] Do not restore Antrean MVP; do not wire live BPJS without an explicit new decision  
- [ ] Treat DEC-016 as Proposed until Clinical/Laboratory and RMIK owners approve; do not imply lifecycle parity

---

## 14. Related chat / agent context (optional)

Prior agent work on Simpan #47, UAT, lab #48, and production promote lives in Cursor agent transcripts under the project’s agent-transcripts folder (local to Daniel’s machine). Prefer **this handoff + git + UAT markdown** as the durable source of truth for other tools.
