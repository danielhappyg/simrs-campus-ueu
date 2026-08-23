# Outpatient slice — Phase 1 dispositions

Status: working dispositions (product owner draft)  
Date: 2026-08-21  
Owners: Daniel Happy Putra (product); RMIK Department (RM/coding acceptance)  
Evidence sources: vendor assessment 2026-08-21 (Observed menu/route); 2018 manual (Manual-documented, historic); rebuild docs (Proposed NEW controls)

## Slice outcome

A registrar can create/find a synthetic outpatient patient, open an encounter, place them in clinic queue context; clinical roles can document assessment/orders; RM can complete coding/quality handoff; pharmacy and cashier receive coherent downstream effects **as specified**. Exact field/posting rules remain Unknown until walkthroughs — do not invent them in build.

## Menu dispositions (outpatient-related)

| PAR ID | Legacy menu | Disposition | Evidence | Owner | Rationale |
|---|---|---|---|---|---|
| PAR-REG-003 | Pendaftaran / Rawat Jalan | **Reproduce** (canonical) | Observed form + route `/pendaftaran/rawatjalan` | Daniel (interim registration) | Primary outpatient registration surface observed |
| PAR-REG-005 | Pendaftaran / Rawat Jalan v2 | **Consolidate** → PAR-REG-003 | Observed route `/pendaftaranv2/rawatjalanv2` | Daniel | Duplicate generation; one canonical RJ registration |
| PAR-REG-004 | Display Admisi | **Replace** (safe teaching display) | Observed external HTTP route — **not opened**; PROHIBITED cleartext pattern | Daniel + security interim | Reproduce *business need* for queue/admission visibility only with HTTPS, minimization, synthetic-only; never copy insecure public route |
| PAR-REG-001 | Pendaftaran / Rawat Inap | Pending evidence | Menu observed | Daniel | Inpatient slice — not outpatient P0 build yet |
| PAR-REG-002 | Pendaftaran / IGD | Pending evidence | Menu observed | Daniel | ED slice — not outpatient P0 build yet |
| PAR-CLN-004 | Pemeriksaan / Rawat Jalan | **Reproduce** (canonical) | Observed screen | Clinical owner TBD; interim Daniel | Outpatient examination queue |
| PAR-CLN-001 | Assesmen | **Consolidate** into encounter assessment capability | Menu observed | Clinical TBD | Shared assessment; not a separate product forever |
| PAR-CLN-019 | Rawat Inap v2 | Pending evidence | Menu observed | Clinical TBD | Inpatient |
| PAR-RMIK-001 | RM / Rawat Jalan | **Reproduce** | Menu observed | **RMIK Department** | Outpatient record completion/coding handoff |
| PAR-RMIK-006 | EMR IPP RAWAT JALAN | **Consolidate** → PAR-RMIK-001 / PAR-CLN-004 | Menu observed; same structural RM posts noted in route audit | RMIK Department | Prefer one EMR/documentation path; retire parallel IPP label after confirmation |
| PAR-RMIK-005 | SatuSehat RJ | **Replace** (sandbox adapter only) | Screen observed; no production enablement | RMIK + integration interim | Boundary only; SIMULATION/SANDBOX labelled; no production endpoint |
| PAR-CLM-001 | Klaim / Rawat Jalan | **Reproduce** (educational/sandbox) | Menu observed | RMIK Department + finance TBD | Claim prep for RJ; no production BPJS send |
| PAR-CLM-003 | Klaim / Rawat Jalan iDRG | **Consolidate** → PAR-CLM-001 with grouping mode | Menu observed | RMIK Department | Same claim episode; iDRG as mode/version not fork |
| PAR-BPJS-* | BPJS visible menus | Pending evidence / Replace sandbox | Menu observed | Claims TBD | Outpatient eligibility later; blocked from production |

## Canonical outpatient workflow (specified at outcome level)

```text
Identity search/create (synthetic)
  -> Outpatient registration + payer/referral context (Unknown details)
  -> Queue / clinic routing
  -> Nursing/medical assessment & orders (Unknown field set)
  -> Pharmacy / diagnostics handoffs as ordered
  -> RM completeness + coding
  -> Claim prep (sandbox) + billing handoff
  -> Closure / reporting effects
```

States and posting rules: **Unknown** until synthetic walkthrough with owners — tracked as discovery tasks, not guessed FR rows.

## NEW controls (not legacy parity)

| ID | Control | Label |
|---|---|---|
| NFR-AUTH-01 | Server-side action authorization; no student over-permission | Proposed / Approved for build (security baseline) |
| NFR-AUD-01 | Append-only audit for privileged actions | Proposed / Approved for build |
| NFR-SYN-01 | Synthetic-only teaching data; backend enforcement plus the permanent application-shell indicator `SIMULASI — DATA SINTETIS` on authentication and authenticated screens (DEC-015) | Implemented: local model scopes isolate patient graphs; route binding rejects non-synthetic encounters/orders; reset and teaching census fail closed; negative feature tests verify counts, worklists, writes, reset, and collision handling |
| NFR-INT-01 | Sandbox adapters cannot fall through to production | Proposed / Approved for build |

## Detailed requirement packs

| Spec | Covers |
|---|---|
| `requirements/PAR-REG-003-rawat-jalan-registration.md` | Canonical RJ registration |
| `requirements/PAR-CLN-004-rawat-jalan-examination.md` | Canonical RJ examination |
| `requirements/PAR-RMIK-001-rawat-jalan-rm.md` | RM outpatient completion |

## Open discovery (do not build as guessed rules)

1. Exact required fields and validation for RJ registration (Observed form structure only).
2. Cancellation/correction effects on queue, charges, SEP context.
3. Whether v2 fields are supersets or alternate product.
4. EMR IPP vs classic RM semantic differences.
5. Report formulas for RJ registers among 117 Laporan rows.

## Next Phase 1 step after this pack

~~Disposition ED (PAR-REG-002, PAR-CLN-002/003) and inpatient admission (PAR-REG-001, PAR-CLN-005) at the same evidence discipline.~~ **Done** — see `ED_SLICE_DISPOSITIONS.md` and `INPATIENT_SLICE_DISPOSITIONS.md`. Next: Phase 2 foundation; residual Phase 1 domain dispositions in parallel.
