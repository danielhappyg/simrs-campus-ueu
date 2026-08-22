# System tester notes — 2026-08-22

**Source:** WhatsApp from system tester (timestamp ~17.46)  
**Captured by:** product owner → engineering  
**Status:** Accepted as **teaching-product requirements** for the clean-slate SIMRS (not optional nice-to-haves).

## Tester wording

1. Variabel dan meta data sesuai  
2. Bisa cetak bukti dan SEP  
3. Bisa rekap pendaftaran online  

## Comparison to existing plans

| Tester note | Already in plan? | Where | Current demo reality | Required teaching outcome |
|---|---|---|---|---|
| **Variabel dan meta data sesuai** | Partially | Field maps (`PENDAFTARAN_SAHABAT_FIELD_MAP.md`), capture dictionaries (`docs/legacy-visual-field-capture/`), PAR-REG-* required fields, DEC-014 desk bar | Desk fields expanded; wilayah was stubby (in-progress cascade); not every SAHABAT/classic variable is live | Registration + downstream screens use **consistent labels, codes, and stored metadata** (patient/encounter/wilayah/payer/queue) aligned to SAHABAT/legacy dictionaries — no orphan free-text where masters exist |
| **Bisa cetak bukti dan SEP** | Named, but as **stub / non-production** | DEC-014; SAHABAT gap P1 “Cetak stub”; field map “SEP / Cetak UI stubs only”; PAR-REG “SEP sandbox or offline”; explicit **no production BPJS send** | Right panel has SEP checkbox + **disabled Cetak** button | Must be able to **print teaching bukti registrasi** and a **teaching SEP document** from saved encounter data (PDF/print preview). Still **no live VClaim/BPJS** — synthetic/sandbox payload only |
| **Bisa rekap pendaftaran online** | **Weak / not explicit** as a first-class module | SAHABAT top strip “Data Kunjungan” / RegOn stubs; today’s encounter list on Pendaftaran; RPT menus in legacy capture not rebuilt | “Pendaftaran hari ini” list only; no dedicated online-registration recap report | Need a **rekap pendaftaran** surface (filter by date/poli/cara bayar/asal online vs walk-in) with export/print for teaching — covers “pendaftaran online” intake recap |

## Decision (locked by this note)

These three items are **in scope for the new system** as teaching-safe features:

1. **Metadata fidelity** — continue closing SAHABAT/legacy field + master-data gaps (wilayah codes, payer, queue, visit metadata).  
2. **Cetak bukti + SEP** — upgrade from disabled stub to **working teaching print**; keep production bridging forbidden.  
3. **Rekap pendaftaran online** — add an explicit recap/report slice (not only the today-list on the desk).

They do **not** authorize:

- production BPJS/VClaim/SEP issuance  
- real patient data  
- restoring Antrean kerja MVP  

## Suggested build order (after current wilayah + census ship)

1. Finish wilayah cascade + synthetic census (in progress on `feature/wilayah-and-demo-census`).  
2. **Cetak bukti registrasi** (PDF) from encounter.  
3. **Cetak SEP teaching** (sandbox template; labeled SIMULASI).  
4. **Rekap pendaftaran** page/report (incl. online/RegOn origin when that intake exists).  
5. Metadata audit pass vs `FIELD_DICTIONARY` / SAHABAT map.

## Traceability

- Tester message image: workspace assets / chat attachment 2026-08-22  
- Related: DEC-013, DEC-014, `SAHABAT_VS_DEMO_GAP.md`, `PENDAFTARAN_SAHABAT_FIELD_MAP.md`, PAR-REG-002/003  
