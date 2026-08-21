# Inpatient / bed slice — Phase 1 dispositions

Status: working dispositions (product owner draft)  
Date: 2026-08-21  
Owners: Daniel Happy Putra (product); Clinical SME TBD (ward); RMIK Department (RM RI); bed/facility master TBD  
Evidence sources: vendor assessment 2026-08-21 (Observed menu/route); 2018 manual (Manual-documented, historic); workflow model (Inferred/Unknown); rebuild docs (Proposed NEW controls)

## Slice outcome

A registrar can create/find a synthetic inpatient and open an admission with ward/class/bed context; ward clinical roles can document care and orders; transfers and discharge update bed and episode state **as specified**; RM completes coding/quality handoff; pharmacy and cashier receive coherent downstream effects. Exact bed-locking, transfer acceptance, and charge rules remain **Unknown** until walkthroughs — do not invent them in build.

## Menu dispositions (inpatient / bed-related)

| PAR ID | Legacy menu | Disposition | Evidence | Owner | Rationale |
|---|---|---|---|---|---|
| PAR-REG-001 | Pendaftaran / Rawat Inap | **Reproduce** (canonical admission) | Observed route `/pendaftaran/rawatinap` (processForm/grid; fields include clinic_id, payment_type_id, continue_id); Manual-documented Pendaftaran Rawat Inap | Daniel (interim registration) | Primary inpatient registration surface observed |
| PAR-CLN-005 | Pemeriksaan / Rawat Inap | **Reproduce** (canonical ward exam) | Observed route `/pemeriksaan/rawatinap` (71 inputs; riwayat + lab posts); Manual-documented Form Input Data Rawat Inap | Clinical TBD; interim Daniel | Canonical inpatient clinical documentation |
| PAR-CLN-019 | Pemeriksaan / Rawat Inap v2 | **Consolidate** → PAR-CLN-005 (pending confirmation) | Observed route `/pemeriksaanv3/rawatinap` (list headers include Jatuh, Alergi, PPI, C19, Triage) | Clinical TBD | Newer generation coexistence **Unknown**; prefer one ward clinical path after confirmation |
| PAR-RMIK-002 | RM / Rawat Inap | **Reproduce** | Observed route `/rm/rawatinap` (bangsal, inpatient_clinic_id, status_klaim, bpjs_naik_kelas filters) | **RMIK Department** | Inpatient record completion/coding handoff |
| PAR-RMIK-007 | RM / EMR IPP RAWAT INAP | **Consolidate** → PAR-RMIK-002 / PAR-CLN-005 | Observed `/rm-ipp/rawatinap` reuses same process_form posts as classic RI (**Observed** structural) | RMIK Department | Prefer one EMR/documentation path; retire parallel IPP label after confirmation |
| PAR-ADM-009 | Manajemen Data / Bangsal | **Reproduce** (master for bed/ward) | Observed route `/manajemendata/tt`; taxonomy: ward/bed-area master | Facility/admin TBD; interim Daniel | Required master for admission/bed context — not clinical workflow itself |
| PAR-CLM-002 | Klaim / Rawat Inap | Pending evidence (downstream) | Menu observed | RMIK Department + finance TBD | Claim prep for RI; no production BPJS send |
| PAR-CLM-004 | Klaim / Rawat Inap iDRG | **Consolidate** → PAR-CLM-002 with grouping mode (proposed) | Menu observed | RMIK Department | Same claim episode; iDRG as mode — confirm before build |
| PAR-PHA-003 | Apotek / Apotek Rawat Inap | Pending evidence (downstream P1) | Observed route `/apotek/rawatinap` | Pharmacy TBD | Ward Rx after clinical orders |
| PAR-FIN-002 | Kasir / Rawat Inap | Pending evidence | Observed route `/keuangan/rawatinap` | Finance TBD | Inpatient settlement after charges |
| PAR-RPT-017 | Laporan / Register Rawat Inap | Pending evidence | Observed `/laporan/registerranap` | Reporting TBD | Depends on admission/discharge facts |
| PAR-BPJS-002 | BPJS / Rawat Inap | Pending evidence / Replace sandbox | Menu observed | Claims TBD | Blocked from production |

## Bed / admission vocabulary (do not treat as verified current rules)

| Topic | Label | Note |
|---|---|---|
| Admission assigns class/ward/bed context | Manual-documented / Inferred | Live bed-locking transaction not demonstrated (**Unknown**) |
| Transfer updates bed, service responsibility, charges | Inferred | Reports (`Px Pindah Ranap`, status pasien dirawat) imply lifecycle; transaction **Unknown** |
| Discharge releases bed; triggers RM/bill/claim | Inferred | Exact gate order **Unknown** |
| Public bed availability / Info Kamar Kosong | Observed (external/public surfaces) / Manual-documented | Teaching rebuild: safe synthetic display only — never copy insecure cleartext public patterns (see PAR-REG-004 discipline) |
| Kelas / bangsal master fields | Observed structural | Exact occupancy concurrency rules **Unknown** |
| Gelang pasien print on RI | Manual-documented | Historic print options — confirm necessity for teaching |

## Canonical inpatient workflow (specified at outcome level)

```text
Identity search/create (synthetic)
  -> Inpatient registration + payer/referral/continue context (Unknown details)
  -> Ward/class/bed placement (Unknown locking rules)
  -> Ward nursing/medical documentation & orders (Unknown field set)
  -> Optional transfer (Unknown atomicity)
  -> Pharmacy / diagnostics / allied handoffs as ordered
  -> Discharge disposition
  -> RM completeness + coding
  -> Claim prep (sandbox) + final bill handoff
  -> Bed release + Register RI / occupancy effects
```

Entry from ED: ED disposition “Rawat Inap” (Manual-documented) should create or continue into this admission path — **Unknown** whether one shared encounter or linked episodes.

States and posting rules: **Unknown** until synthetic walkthrough with owners — tracked as discovery tasks, not guessed FR rows.

## NEW controls (not legacy parity)

| ID | Control | Label |
|---|---|---|
| NFR-AUTH-01 | Server-side action authorization; no student over-permission | Proposed / Approved for build (security baseline) |
| NFR-AUD-01 | Append-only audit for privileged actions | Proposed / Approved for build |
| NFR-SYN-01 | Synthetic-only teaching data; backend enforce (UI chrome banner removed by product decision) | Proposed / Approved for build |
| NFR-INT-01 | Sandbox adapters cannot fall through to production | Proposed / Approved for build |
| NFR-BED-01 | Bed occupancy consistency (no silent double-book in teaching scenarios) | Proposed NEW — exact concurrency TBD after discovery |

## Detailed requirement packs

| Spec | Covers |
|---|---|
| `requirements/PAR-REG-001-rawat-inap-registration.md` | Canonical RI admission (+ bed context) |
| `requirements/PAR-CLN-005-rawat-inap-examination.md` | Canonical RI examination |

RM RI (PAR-RMIK-002) and Bangsal master (PAR-ADM-009) are dispositioned here; detailed FR packs follow with RMIK/facility owners unless pulled forward.

## Open discovery (do not build as guessed rules)

1. Exact required fields and validation for RI registration (Observed form structure only).
2. Bed reservation locks, waitlist, and concurrent assignment behaviour.
3. Transfer acceptance, class upgrade (`bpjs_naik_kelas` filter Observed — semantics **Unknown**).
4. Whether Rawat Inap v2 fields are supersets or alternate product.
5. EMR IPP vs classic RM semantic differences for RI.
6. Discharge summary signature / final-bill gate order.
7. Report formulas for Register Rawat Inap and occupancy reports among 117 Laporan rows.

## Relationship to other Phase 1 packs

- Outpatient: `OUTPATIENT_SLICE_DISPOSITIONS.md` (done).
- ED: `ED_SLICE_DISPOSITIONS.md` — ED→RI disposition is the clinical bridge; bed rules live here.
