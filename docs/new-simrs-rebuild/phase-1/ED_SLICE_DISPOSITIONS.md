# Emergency / triage slice — Phase 1 dispositions

Status: working dispositions (product owner draft)  
Date: 2026-08-21  
Owners: Daniel Happy Putra (product); Clinical SME TBD (ED/triage); RMIK Department (RM/coding handoff)  
Evidence sources: vendor assessment 2026-08-21 (Observed menu/route); 2018 manual (Manual-documented, historic); workflow model (Inferred/Unknown); rebuild docs (Proposed NEW controls)

## Slice outcome

A registrar can create/find a synthetic ED patient and open an emergency encounter; triage can record acuity/risk context; ED clinical roles can document assessment/orders and record disposition; pharmacy, diagnostics, RM, and cashier receive coherent downstream effects **as specified**. Exact triage scales, mandatory fields, and posting rules remain **Unknown** until walkthroughs — do not invent them in build.

## Menu dispositions (ED / triage-related)

| PAR ID | Legacy menu | Disposition | Evidence | Owner | Rationale |
|---|---|---|---|---|---|
| PAR-REG-002 | Pendaftaran / IGD | **Reproduce** (canonical) | Observed route `/pendaftaran/ugd` (143 inputs; SEP history posts); Manual-documented Pendaftaran IGD | Daniel (interim registration) | Primary ED registration surface observed |
| PAR-CLN-002 | Pemeriksaan / Triage | **Reproduce** (pre-ED acuity) | Observed route `/pemeriksaan/triage`; distinct from Assesmen and IGD menus (**Observed**); scale/rules **Unknown** | Clinical TBD; interim Daniel | Keep as separate capability until evidence shows merge is safe |
| PAR-CLN-003 | Pemeriksaan / IGD | **Reproduce** (canonical ED exam) | Observed route `/pemeriksaan/ugd`; Manual-documented Form Input Data IGD (tabs, kelanjutan, kasus, kecelakaan) | Clinical TBD; interim Daniel | Canonical ED clinical documentation |
| PAR-CLN-001 | Pemeriksaan / Assesmen | **Consolidate** → outpatient/ED assessment capability as needed | Menu observed; outpatient pack already consolidates toward PAR-CLN-004 | Clinical TBD | Shared assessment surface; not ED-specific product forever — do not fork Assesmen for ED alone without evidence |
| PAR-PHA-001 | Apotek / Apotek IGD | Pending evidence (downstream P1) | Observed route `/apotek-igd/rawatjalan`; Manual-documented apotek by care setting | Pharmacy TBD | ED Rx fulfillment after clinical orders exist |
| PAR-RPT-013 | Laporan / Register IGD | Pending evidence (reporting) | Observed route `/laporan/registerigd` (filters include triage) | Reporting TBD | Register depends on ED encounter + triage facts |
| PAR-FIN-009 | Kasir / Pendapatan IGD | Pending evidence | Menu observed | Finance TBD | Revenue by ED setting — after charges exist |
| PAR-BPJS-* (ED-relevant) | BPJS visible menus | Pending evidence / Replace sandbox | Menu observed | Claims TBD | Eligibility/SEP for ED later; blocked from production |

## Related triage / disposition vocabulary (do not treat as verified current rules)

| Topic | Label | Note |
|---|---|---|
| Distinct menus: Assesmen, Triage, IGD | Observed | Route audit shows three separate pemeriksaan surfaces |
| ED disposition options (Pulang, Rawat Inap, Dirujuk, return to origin/Puskesmas/Faskes, Mati di IGD, DOA) | Manual-documented | 2018 manual — confirm before coding as current |
| Kasus (Bedah / Non Bedah), Kecelakaan flag | Manual-documented | Historic form labels |
| Triage acuity scale, reassessment timers, early-warning rules | Unknown | Workflow model: not demonstrated |
| Whether triage is mandatory before IGD exam | Unknown | Inferred possible precedence only |
| Exact charge/SEP posting on ED register | Unknown | Observed SEP-related posts on registration; semantics unproven |

## Canonical ED workflow (specified at outcome level)

```text
Identity search/create (synthetic)
  -> ED registration + payer/arrival/accident context (Unknown details)
  -> Triage acuity/risk (Unknown scale)
  -> ED examination & orders (Unknown field set)
  -> Disposition (discharge / admit / refer / death — confirm vocabulary)
  -> Pharmacy / diagnostics handoffs as ordered
  -> RM completeness + coding (when ED record in RM scope)
  -> Claim prep (sandbox) + billing handoff
  -> Closure / Register IGD effects
```

States and posting rules: **Unknown** until synthetic walkthrough with owners — tracked as discovery tasks, not guessed FR rows.

## NEW controls (not legacy parity)

| ID | Control | Label |
|---|---|---|
| NFR-AUTH-01 | Server-side action authorization; no student over-permission | Proposed / Approved for build (security baseline) |
| NFR-AUD-01 | Append-only audit for privileged actions | Proposed / Approved for build |
| NFR-SYN-01 | Synthetic-only teaching data; backend enforce (UI chrome banner removed by product decision) | Proposed / Approved for build |
| NFR-INT-01 | Sandbox adapters cannot fall through to production | Proposed / Approved for build |

## Detailed requirement packs

| Spec | Covers |
|---|---|
| `requirements/PAR-REG-002-igd-registration.md` | Canonical ED registration |
| `requirements/PAR-CLN-003-igd-examination.md` | Canonical ED examination (+ triage handoff to PAR-CLN-002) |

Triage (PAR-CLN-002) is dispositioned here; detailed FR pack follows after acuity-scale discovery unless pulled forward with clinical owner.

## Open discovery (do not build as guessed rules)

1. Exact required fields and validation for ED registration (Observed large form only).
2. Triage scale, mandatory vitals, reassessment, and override audit.
3. Whether Assesmen is used in ED path or only Triage + IGD.
4. Disposition → inpatient admission atomicity (bed reserve, charges, encounter link).
5. Cancellation/correction effects on triage queue, charges, SEP context.
6. Register IGD report formulas among Laporan rows.

## Relationship to outpatient / inpatient packs

- Outpatient: `OUTPATIENT_SLICE_DISPOSITIONS.md` (done).
- Inpatient admission from ED disposition: hand off to `INPATIENT_SLICE_DISPOSITIONS.md` (PAR-REG-001 / PAR-CLN-005); do not invent bed rules here.
