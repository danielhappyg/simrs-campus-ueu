# Phase 0 — Current baseline (orientation snapshot)

Status: **active working baseline**  
Date: 2026-08-21  
Authority: DEC-001 (product owner for now); DEC-013 (vendor UI reference); **DEC-014 (SAHABAT minimum desk density)**  
Session rule: Phase 0 planning / evidence alignment. No mass menu generation. Synthetic-only.

## 1. What this system is

Clean-slate **UEU teaching SIMRS** whose first product goal is approved **functional parity** with the assessed vendor SIMRS (SIMRS Sahabat / SIMRS 3.0 — RS UEU), then a separate post-parity improvement program.

The vendor system is the **functional and UI-familiarity reference**. It is **not** the technical architecture, database schema, secret model, or source-code template to copy.

## 2. Evidence sources (keep separate)

| Source | Location | Use |
|---|---|---|
| Vendor assessment pack | `docs/vendor-simrs-assessment-2026-08-21/` | 268 menus, workflows, roles, findings |
| Visual / field capture | `docs/legacy-visual-field-capture/` (**untracked locally as of this snapshot**) | Screenshots, 3,375 fields, 717 forms, 118 tables |
| SAHABAT desk print evidence | `docs/new-simrs-rebuild/_evidence/sahabat-page-1.png`, `sahabat-page-2.png`; also `~/Downloads/SIMRS SAHABAT.pdf` | **Minimum Pendaftaran desk density** (DEC-014) |
| Classic REG live capture | `docs/legacy-visual-field-capture/screenshots/menus/REG/` | CAP-REG-001…005 structural + visual states |
| Rebuild planning docs | `docs/new-simrs-rebuild/` | SoT for phases, gates, PAR-* |
| This repository application | Laravel + Inertia/React | Implementation under test — not “parity accepted” |

Evidence labels (O/M/I/U/P) remain mandatory. A screenshot proves visibility in that state only — not validation, authz, posting, or integration success.

## 3. Repository snapshot (do not reset)

| Item | Value |
|---|---|
| Remote | `origin` → `https://github.com/danielhappyg/simrs-campus-ueu.git` |
| Branch at orientation | `feature/pendaftaran-sahabat-desk` @ `af16e8d` |
| `main` tip | `ddb8c50` (behind feature branch) |
| `rebuild/clean-slate` tip | `f134a74` |
| Stack | Laravel modular monolith + React/Inertia + relational DB (ADR-015); Vercel + Supabase = **synthetic demo only** |

### Working tree to preserve

- Modified: `app/Http/Controllers/Outpatient/OutpatientRegistrationController.php` (schema-qualified validation rules — uncommitted relative to `af16e8d`)
- Untracked: `docs/legacy-visual-field-capture/`, `lang/`, `.vercel/`, `deliverables/`

Do not `git clean`, hard-reset, or overwrite these without explicit owner instruction.

## 4. SAHABAT minimum bar (binding for interactive desks)

**Decision:** For registrar/clinical **desk** screens that replace a SAHABAT registration or examination surface, the **minimum acceptable product bar** is the assessed SAHABAT **Data Pasien** three-column desk — not a thin MVP form and not the failed Antrean-kerja shell (DEC-013 anti-reference).

### 4.1 Primary visual oracle (user-supplied PDF / evidence PNGs)

Assessed from `sahabat-page-1.png` + `sahabat-page-2.png` (Chrome print of vendor Data Pasien):

| Zone | Required silhouette / content |
|---|---|
| Top action strip | Riwayat, EMR, Ambil RegOn, Cari Pasien, Approval SEP, Data Kunjungan — **Cari Pasien live**; others may be honest disabled stubs (no fake-live BPJS/EMR) |
| Left — Data Pribadi | No. RM, NIK, Nama, JK, Tempat/Tgl lahir, Agama, Pendidikan, Pekerjaan, Provinsi→Kabupaten→Kecamatan→Kelurahan, Dusun/Jalan, Domisili (+ Auto), Telepon, Email, Suku/Bahasa, Catatan |
| Center — PJ + Kunjungan | PJ Nama (Auto/Edit), Kode booking, Tgl kunjungan + Baru, **Poliklinik → Dokter → Jadwal** (seeded masters, no free-text klinik), Cara masuk, Cara bayar, No asuransi + Cek/FR/FP stubs, Catatan kunjungan |
| Right — outputs | No. Antrian (persist queue #), SEP / Gelang / Kartu / Consent / Fast Track as UI stubs, Cetak stub, **Simpan** live |

Rough minimum: **~25+** teaching-safe inputs/selects in one desktop composition (~1280px), three columns — not a single stacked card with ~7 fields.

### 4.2 Live campus capture (structural floor / discovery)

From `docs/legacy-visual-field-capture/` REG screenshots + catalogues:

| Capture | Menu | Screenshot | Rendered controls (catalogue) | Role for rebuild |
|---|---|---|---:|---|
| CAP-REG-003 | Rawat Jalan (classic) | `screenshots/menus/REG/CAP-REG-003-rawat-jalan.png` | **224** | Full classic footprint for parity discovery; denser than modern Data Pasien (status nikah, SatuSehat Id, hambatan, richer Cetak set). **Do not invent backend rules from empty/default cells.** |
| CAP-REG-005 | Rawat Jalan v2 | `…/CAP-REG-005-rawat-jalan-v2.png` | **136** | Closest structural sibling to the PDF Data Pasien desk; synthetic wave found save/feedback defects — rebuild must not copy those defects |
| CAP-REG-002 | IGD | `…/CAP-REG-002-igd.png` | **143** | ED registration minimum discovery set |
| CAP-REG-001 | Rawat Inap | `…/CAP-REG-001-rawat-inap.png` | **14** (shell/filters) | Incomplete base state; admission desk needs further synthetic evidence |

**Interpretation rule:** Classic CAP-REG-003 field *presence* raises the discovery backlog; the **shipped teaching minimum** for Pendaftaran RJ is DEC-014 (modern Data Pasien silhouette + mapped persist fields in `PENDAFTARAN_SAHABAT_FIELD_MAP.md`). Extra classic-only fields stay Pending evidence / disposition unless an owner promotes them.

### 4.3 Explicitly not required to copy from SAHABAT

- Production BPJS / SEP / SatuSehat / FR / FP biometric send
- Vendor schema, secrets, cookies, or plaintext settings
- Broken HTTP Display Admisi pattern
- Antrean kerja / work-queue MVP IA
- Vendor template defaults that look like real DOB/phone/wilayah (Observed UI only — not approved defaults)

## 5. What the codebase already contains (honest)

| Layer | Status | Parity claim? |
|---|---|---|
| Phase 0 docs, DEC/ADR, env baseline | Largely present; this file closes orientation drift | N/A |
| Phase 1 outpatient / ED / inpatient slice packs | Drafted; remaining domains Pending | Spec only |
| Phase 2 RBAC + synthetic reset | Teaching-demo quality landed | Foundation, not full contextual RBAC |
| Phase 3 outpatient spine | Register → exam notes → RM complete | **Not** full PAR acceptance |
| SAHABAT-density Pendaftaran desk | On `feature/pendaftaran-sahabat-desk` + field map | Must meet §4; Pemeriksaan/RM density still thin |

`phase-3/README.md` “MVP delivered” means teaching-demo vertical slice — **not** G3 parity acceptance.

## 6. Phase 0 residual checklist

- [x] Orientation report reviewed; SAHABAT screenshots assessed (this file)
- [x] Reuse map refreshed (`PROTOTYPE_REUSE_MAP.md`)
- [x] DEC-014 recorded
- [ ] Commit intake of `docs/legacy-visual-field-capture/` (owner auth; privacy review; no secrets)
- [ ] Formal `.env*` / CI secret scan without pasting values (`ENVIRONMENT_AND_CREDENTIAL_BASELINE.md`)
- [ ] Named SMEs / executive sponsor beyond interim
- [ ] Merge strategy: feature desk → `main` only after review + synthetic checks

## 7. Related

- `PROTOTYPE_REUSE_MAP.md`
- `DECISION_LOG.md` (DEC-013, DEC-014)
- `../SAHABAT_VS_DEMO_GAP.md`
- `../PENDAFTARAN_SAHABAT_FIELD_MAP.md`
- `../../legacy-visual-field-capture/CAPTURE_SUMMARY.md`
- `../../legacy-visual-field-capture/SYNTHETIC_EXECUTION_REPORT_2026-08-21.md`
