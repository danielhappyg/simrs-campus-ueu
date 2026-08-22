# Handoff — SIMRS Campus UEU teaching rebuild (outpatient core)

**Audience:** Daniel, facilitators, and any human or agent continuing this work without prior chat history.  
**Date:** 2026-08-23  
**Git tip when written:** `315d912` on `main` (lab UAT evidence; product code tip for lab slice is `807bbf2` / PR #48)  
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
| UI tokens | UEU blue `#1b75bc`, orange `#f26a1b`, navy sidebar — copy campus SI patterns, not ad-hoc purple themes | `docs/new-simrs-rebuild/phase-1/UI_DIRECTION.md` |

**Hosting ops:** `docs/operations/CURRENT_HOSTING_POSTURE.md` · `docs/operations/VERCEL_SUPABASE_DEMO.md`

---

## 2. What is delivered today (teaching-demo quality)

### 2.1 Core RJ teaching arc (primary story)

```
Pendaftaran RJ → Pemeriksaan RJ (nurse + physician notes)
              → Order Lab → Laboratorium (result)
              → RM RJ (close)
              → optional Cetak + Rekap
```

**Facilitator script (use this in class):**  
[`docs/operations/TEACHING_RJ_FACILITATOR_RUNBOOK_2026-08-22.md`](TEACHING_RJ_FACILITATOR_RUNBOOK_2026-08-22.md)

**Encounter status spine** (notes drive status; lab does not):

```
REGISTERED → IN_EXAMINATION → READY_FOR_RM → CLOSED
     ↑              ↑                ↑            ↑
 Pendaftaran   Nursing note    Medical note   RM complete
```

### 2.2 Routes (auth + simulation middleware)

| Area | Method | Path |
| --- | --- | --- |
| Pendaftaran | GET/POST | `/pendaftaran/rawat-jalan` |
| Cetak | GET | `/pendaftaran/kunjungan/{encounter}/cetak` |
| Rekap | GET | `/pendaftaran/rekap` |
| Pemeriksaan RJ | GET | `/pemeriksaan/rawat-jalan`, `/pemeriksaan/rawat-jalan/{encounter}` |
| Clinical note | POST | `/pemeriksaan/rawat-jalan/{encounter}/entries` |
| Lab order | POST | `/pemeriksaan/rawat-jalan/{encounter}/lab-orders` |
| Lab desk | GET/POST | `/pemeriksaan/laboratorium`, `/pemeriksaan/laboratorium/{order}/results` |
| RM | GET/POST | `/rm/rawat-jalan`, `/rm/rawat-jalan/{encounter}/complete` |

### 2.3 Domain tables (Postgres `laravel` schema on demo)

| Table | Purpose |
| --- | --- |
| `patients` | Synthetic identity, MRN, wilayah, marital status |
| `encounters` | Visit + status spine + care_setting |
| `clinical_entries` | NURSING_INTAKE / MEDICAL_ASSESSMENT |
| `lab_service_requests` | Physician lab orders |
| `lab_diagnostic_results` | Nurse-entered synthetic results |
| `clinics` / `doctors` / `clinic_schedules` | Poli → dokter → jadwal |
| `wilayah_*` | Province → village cascade |
| `roles` / `permissions` / pivots | RBAC |
| `audit_events` | Append-only audit |

Slice write-up: [`docs/new-simrs-rebuild/phase-3/README.md`](../new-simrs-rebuild/phase-3/README.md)

### 2.4 Adjacent desks (shipped, not the primary UAT arc)

Also on `main` with SAHABAT-density desks:

- **IGD / Triage** — Pendaftaran + Pemeriksaan + triage stub worklist (PR #41)
- **Rawat Inap** — admission + examination desks (PR #42)

Treat these as parallel care settings. Deep clinical ED/RI parity is still unfinished.

### 2.5 Parity IDs for the RJ arc

| ID | Topic | Status (teaching) |
| --- | --- | --- |
| PAR-REG-003 | Pendaftaran RJ | Specified → implemented teaching desk |
| PAR-CLN-004 | Pemeriksaan RJ | Specified → notes live; many tabs stubbed |
| PAR-CLN-006 | Laboratorium | **Implemented (partial)** — RJ order + worklist + result; no tarif/LIS/specimen |
| PAR-RMIK-001 | RM RJ | Implemented teaching close |

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
| docs | `09f4f84`…`315d912` | Facilitator runbook + UAT Runs 1–3 evidence |

**Production lab promote (demo):** `dpl_4BJScnJZXo98j2AF1h7YydJhL3vx` for `807bbf2` — see lab UAT record.

---

## 4. Evidence and how to verify

### 4.1 Teaching UAT records

| Record | Doc | Verdict |
| --- | --- | --- |
| Runs 1–2 | [`TEACHING_UAT_CETAK_REKAP_METADATA_2026-08-22.md`](TEACHING_UAT_CETAK_REKAP_METADATA_2026-08-22.md) | PASS after #47 — register metadata, Simpan, cetak, rekap |
| Run 3 | [`TEACHING_UAT_LAB_SLICE_2026-08-22.md`](TEACHING_UAT_LAB_SLICE_2026-08-22.md) | PASS — HB order + FINAL result on production |

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
| nurse | `clinical.nursing.write`, `clinical.lab.result.write`, `clinical.amend` |
| physician | `clinical.medical.write`, `clinical.order.create`, `clinical.amend` |
| rmik | `rmik.review`, coding caps reserved |
| admin | user/role/audit/synthetic reset |

Detail: `docs/new-simrs-rebuild/phase-2/RBAC_MATRIX.md` · code: `app/Support/Authorization/`

Audit actions include: `patient.register`, `clinical.note.write`, `clinical.lab.order.create`, `clinical.lab.result.write`, `rmik.review.complete`.

---

## 7. Explicit stubs and out of scope

Say these honestly in demos and planning:

| Area | Status |
| --- | --- |
| Exam tabs SOAP / Diagnosa / Tindakan / **Order Rad** / **Resep** | Stub UI (Order Lab is live) |
| Exam header Cetak / Riwayat EMR / Order / Resep | Stub |
| Nav Klaim / BPJS / Apotek | **Soon** placeholders |
| Production SEP / VClaim / SATUSEHAT | Forbidden on this demo |
| LIS, specimen, tarif, PA/mikro lab desks | Not built |
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
| `docs/new-simrs-rebuild/PENDAFTARAN_SAHABAT_FIELD_MAP.md` | Field map |
| `docs/new-simrs-rebuild/DELIVERY_ROADMAP.md` | Long-arc roadmap |
| `docs/new-simrs-rebuild/TESTING_AND_UAT_STRATEGY.md` | Test/UAT program |

### UAT evidence

| Doc | Purpose |
| --- | --- |
| `docs/operations/TEACHING_UAT_CETAK_REKAP_METADATA_2026-08-22.md` | Runs 1–2 |
| `docs/operations/TEACHING_UAT_LAB_SLICE_2026-08-22.md` | Run 3 |
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

## 12. Recommended next work (pick one)

| Option | Why | Effort |
| --- | --- | --- |
| **A. Radiology (Order Rad)** | Same pattern as lab | Low–medium |
| **B. Resep / apotek handoff** | Completes diagnosis → order → pharmacy story | Medium |
| **C. Pemeriksaan/RM SAHABAT density** | Honest residual vs vendor desk | Medium UI |
| **D. ED clinical depth** | Desks exist (#41); acuity/FR incomplete | Medium |
| **E. PAR-CLN-006 FR pack** | Formalize lab fields from capture before expanding | Spec only |

Default recommendation after this handoff: **A or B** for product story; **E** if locking evidence before more lab features.

---

## 13. Handoff checklist for the next owner

- [ ] Clone `main`, confirm tip ≥ `807bbf2` for lab code  
- [ ] Read this file + facilitator runbook  
- [ ] Confirm demo `/up` and simulation banner  
- [ ] Obtain `DEMO_ACCOUNT_PASSWORD` out-of-band  
- [ ] Rehearse full RJ arc once (or trust UAT Runs 1–3)  
- [ ] Before schema work: remember Supabase migrate is **manual** on Vercel  
- [ ] Before validation work: use `SchemaAwareRules` / model classes, never `laravel.table` strings in `Rule::exists`  
- [ ] Do not restore Antrean MVP; do not wire live BPJS without an explicit new decision  

---

## 14. Related chat / agent context (optional)

Prior agent work on Simpan #47, UAT, lab #48, and production promote lives in Cursor agent transcripts under the project’s agent-transcripts folder (local to Daniel’s machine). Prefer **this handoff + git + UAT markdown** as the durable source of truth for other tools.
