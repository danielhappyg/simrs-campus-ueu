# Handoff — SIMRS Campus UEU teaching rebuild (outpatient core)

**Audience:** Daniel, facilitators, and any human or agent continuing this work without prior chat history.  
**Date:** 2026-08-23; current-truth baseline reconciled on 2026-08-25<br>
**Current production baseline:** `aabfff562dbe75d022da203f3f44215b055be614` on `main` / Vercel deployment `dpl_2th2jRdhHHof859F3WM9rWt3ZiTf`<br>
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

**Current production deployment (demo):** `dpl_2th2jRdhHHof859F3WM9rWt3ZiTf` for `aabfff5…b614`; Vercel state `READY`, target `production`, public alias `simrs-campus-ueu-demo.vercel.app`. The Supabase `laravel` schema records migration `2026_08_24_000100_create_outpatient_documentation_tables` in batch 7 and contains all four structured-document/RM tables. See `docs/operations/T0_CURRENT_TRUTH_BASELINE_2026-08-25.md`.

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

The bounded lifecycle guard is deployed and continuous hosted UAT Run 4 passed on 2026-08-24. The synthetic evidence encounter was retained; temporary role accounts were disabled, password values made unusable, sessions revoked and temporary credential/session files deleted. Closeout verification returned `active_session_rows=0`, `disabled_account_rows=4` and `preserved_closed_encounter_rows=1`. Global `simulation:reset` was not run because it is not encounter-scoped.

Next sequence:

1. Keep DEC-016 **Proposed** and obtain Clinical/Laboratory and RMIK review of the FINAL-only, active-order closure and late-result policy. Run 4 is implementation evidence, not owner acceptance or SAHABAT parity.
2. Treat the missing manual `active_lab_orders` denial audit honestly: the UI blocker passed; the server rejection is covered by automated tests. Repeat a hosted stale/direct denial only if an explicit acceptance plan requires it.
3. Deployment and migration for the exact `aabfff5` baseline were verified on 2026-08-25. Run a focused hosted UAT for **Structured Outpatient Documentation and RM Completeness v1**: nursing Draft/Final, medical Draft/Final, immutable version history, automatic checklist, review, sign-off, and closed read-only retrieval.
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
- [x] Apply and verify the explicit Supabase migration and deploy the exact pushed commit (`aabfff5`; reconciled 2026-08-25)
- [ ] Record focused hosted Structured RJ/RM v1 UAT evidence
- [ ] Obtain Clinical and RMIK decisions before broadening v1 fields/workflows or claiming owner acceptance
- [ ] Obtain Clinical/Laboratory and RMIK owner review of DEC-016
- [ ] Before schema work: remember Supabase migrate is **manual** on Vercel  
- [ ] Before validation work: use `SchemaAwareRules` / model classes, never `laravel.table` strings in `Rule::exists`  
- [ ] Do not restore Antrean MVP; do not wire live BPJS without an explicit new decision  
- [ ] Treat DEC-016 as Proposed until Clinical/Laboratory and RMIK owners approve; do not imply lifecycle parity

---

## 14. Related chat / agent context (optional)

Prior agent work on Simpan #47, UAT, lab #48, and production promote lives in Cursor agent transcripts under the project’s agent-transcripts folder (local to Daniel’s machine). Prefer **this handoff + git + UAT markdown** as the durable source of truth for other tools.
