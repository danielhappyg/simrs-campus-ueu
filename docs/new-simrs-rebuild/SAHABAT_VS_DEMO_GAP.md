# SAHABAT vs Campus UEU demo — honest gap assessment

**Date:** 2026-08-21  
**Audience:** Daniel (product owner)  
**Sources:** `~/Downloads/SIMRS SAHABAT.pdf` (2-page Chrome print of vendor Data Pasien); live demo `https://simrs-campus-ueu-demo.vercel.app`; repo Phase 3 outpatient slice; DEC-013 / `phase-1/UI_DIRECTION.md` / `phase-3/README.md`

**Status update (same day):** Product owner accepted **DEC-014** — SAHABAT Data Pasien desk density is the **minimum** bar. Evidence assessed from `_evidence/sahabat-page-1.png` / `sahabat-page-2.png` and live REG captures under `docs/legacy-visual-field-capture/screenshots/menus/REG/`. Pendaftaran RJ on `feature/pendaftaran-sahabat-desk` was rebuilt toward that bar (`PENDAFTARAN_SAHABAT_FIELD_MAP.md`). This document remains the **why-we-were-thin** record; do not use the “~7 fields” section as the current target. Pemeriksaan/RM density catch-up is still open.

Interactive summary: open the Cursor canvas beside chat — `sahabat-vs-demo-gap.canvas.tsx` in the workspace canvases folder.

---

## Direct verdict

Yes — the visual and functional density gap versus SAHABAT is real and embarrassing. The demo is a working outpatient *backend* vertical slice wrapped in thin forms and a shell full of Soon placeholders; next to SAHABAT’s registration desk it looks like a stub we shipped and called SIMRS.

That reaction is fair. Own it.

---

## What the PDF shows (SAHABAT / vendor Data Pasien)

The PDF is a 2-page print of the vendor **Data Pasien** registration screen (Pendaftaran), not a brochure. Density and workflow cues:

| Zone | Contents |
|---|---|
| Top actions | Riwayat, EMR, Ambil RegOn, Cari Pasien, Approval SEP, Data Kunjungan |
| Left — Data Pribadi | No. RM, NIK, Nama, JK, Tempat/Tgl lahir, Agama, Pendidikan, Pekerjaan, full wilayah cascade (Provinsi→…→Kelurahan), Dusun/Jalan, Domisili, Telepon, Email, Suku/Bahasa, Catatan |
| Center — PJ + Kunjungan | Penanggung jawab (Auto/Edit), Kode booking, Tgl kunjungan + **Baru** chip, Poliklinik → Dokter → Jadwal, Cara masuk, Cara bayar, No asuransi + Cek/FR/FP, Catatan kunjungan |
| Right — print/queue panel | No. Antrian, SEP, Gelang Pasien, Kartu Pasien, General Consent, Fast Track; **Cetak** + **Simpan** |

Rough field count: **~25+** inputs/selects plus action chrome. Three-column hospital desk layout. This is the DEC-013 “familiar” bar for Pendaftaran.

Rendered evidence: `docs/new-simrs-rebuild/_evidence/sahabat-page-1.png`, `sahabat-page-2.png`.

---

## What ships today (clean-slate demo)

| Surface | Reality |
|---|---|
| **Pendaftaran RJ** (`resources/js/pages/pendaftaran/rawat-jalan.tsx`) | Search → ~**7** fields (nama, DOB, sex, optional MRN, **free-text clinic**, payer select, keluhan) → “Pendaftaran hari ini” table |
| **Pemeriksaan RJ** | Simple worklist + encounter note entry |
| **RM RJ** | READY_FOR_RM list + complete → CLOSED |
| **Nav** (`simrs-modules.ts` + `app-header.tsx`) | 13 vendor-inspired categories; only Pendaftaran / Pemeriksaan / RM live; **10** → `/modul/{slug}` placeholders (Soon / “segera”) |
| **Beranda** | Live counts + quick links |

Phase 3 docs already label this **“MVP delivered (teaching-demo quality)”** — accurate engineering language that does not survive a side-by-side with SAHABAT.

---

## Top 5 concrete gaps

1. **Layout density** — stacked card sections vs SAHABAT’s three-column registration desk (first-impression failure).
2. **Field coverage** — ~7 demo fields vs ~25+ SAHABAT (NIK, wilayah, PJ, poli/dokter/jadwal, asuransi action row missing).
3. **Action chrome** — no Riwayat/EMR/RegOn/SEP strip; no Cetak / antrian / gelang / kartu side panel.
4. **Nav honesty** — ten Soon modules still navigate to empty placeholders; shell *looks* like a full SIMRS and *isn’t*.
5. **Master data** — `clinic_name` free text; no dokter/jadwal cascade — workflow cannot match SAHABAT even if we restyle.

---

## Why the gap exists (in-project terms — not excuses)

| Factor | Effect |
|---|---|
| **Phase 3 scope** | Clean-slate vertical slice: prove register → exam → RM + auth/audit/synthetic safety — not full visual parity. |
| **DEC-013 vs delivery** | Vendor named as UI reference, but `UI_DIRECTION.md` deferred the full visual/IA assessment (“later assess everything”). Reference locked; density never scheduled into Phase 3. |
| **Intentional placeholders** | Non-outpatient categories exist so IA looks vendor-shaped; on a public demo URL they read as unfinished product. |
| **Stack-first sequencing** | Auth, Gates, schema, audit, tests landed before registration-desk UI (see tool/ADR path). Correct for foundation; wrong for first demo impression. |
| **Anti-Antrean** | Failed MVP correctly not restored — but SAHABAT-grade Pendaftaran chrome was not built either. Vacuum. |

None of that makes the live page look less thin. It explains *how we got here*.

---

## What is NOT a joke (already real)

- Server-side auth and capability Gates (`patient.search`, `patient.register`, `encounter.open`, `rmik.review`, …).
- Domain path that works: `patients` → `encounters` → `clinical_entries` with statuses **REGISTERED → IN_EXAMINATION → READY_FOR_RM → CLOSED**.
- Audit on register / clinical write / RM complete; synthetic-only teaching enforcement.
- Feature tests (`OutpatientFlowTest`, etc.) and Phase 3 route map.

The backend slice is real. The **packaging** next to SAHABAT is what looks like a joke.

---

## PDF promise vs shipped today

| Promise (SAHABAT / DEC-013 familiarity) | Shipped |
|---|---|
| Dense Pendaftaran desk | Thin form + list |
| Linked poli / dokter / jadwal | Free-text klinik |
| Identity + wilayah + PJ | Minimal identity only |
| Queue/print/SEP action panel | Absent (correctly no prod BPJS; incorrectly no teaching stubs) |
| Full module shell | Shell labels present; 10 modules empty |

---

## Prioritized catch-up (Pendaftaran first — no Antrean revival)

**Goal (1 week):** one Pendaftaran screen a registrar recognizes as SAHABAT-family, teaching-safe, without restoring Antrean MVP or production BPJS.

| Priority | Work | Done when |
|---:|---|---|
| P0 | Rebuild Pendaftaran RJ as **3-column desk** + top action strip (Cari Pasien live; Riwayat/EMR/SEP/RegOn **disabled-honest**, not fake-live) | Silhouette matches SAHABAT at ~1280px |
| P0 | Expand teaching-safe fields: NIK, tempat lahir, telepon, simplified alamat/wilayah, PJ name; **seeded** poli/dokter/jadwal selects (kill clinic free-text) | ≥ ~18 fields; masters drive selects |
| P1 | Status chips + denser today’s list; queue stub #; Cetak stub (teaching blank/PDF) + sticky Simpan | Seeded register no longer looks barren |
| P1 | Nav honesty: non-live modules disabled or collapsed (“Modul berikutnya”) — no click → empty surprise | Trust restored |
| P2 | Carry density tokens into Pemeriksaan worklist + RM list (chips, compact rows) | RJ path feels one product |

**Explicitly out of next week:** production SEP/BPJS, FR/FP biometrics, real EMR deep-link, Antrean kerja, copying vendor schema/secrets.

---

## Related project docs

- DEC-013 — `docs/new-simrs-rebuild/phase-0/DECISION_LOG.md`
- UI direction — `docs/new-simrs-rebuild/phase-1/UI_DIRECTION.md`
- Phase 3 slice — `docs/new-simrs-rebuild/phase-3/README.md`
- Parity pack — `docs/new-simrs-rebuild/phase-1/requirements/PAR-REG-003-rawat-jalan-registration.md`
