# Prototype reuse map (Keep / Adapt / Retire / Isolate)

Status: refreshed after Phase 0 orientation + SAHABAT minimum bar (DEC-014)  
Date: 2026-08-21  
Scope: actual tree on `feature/pendaftaran-sahabat-desk` (and ancestors on `main` / `rebuild/clean-slate`)  
Rule: previous Antrean-kerja teaching MVP is **anti-reference** (DEC-013). Vendor SAHABAT is **UI/workflow minimum for desks**, not architecture to clone.

## Disposition legend

| Label | Meaning |
|---|---|
| **Keep** | Retain and maintain on the rebuild |
| **Adapt** | Keep pattern; change deliberately with tests + evidence |
| **Retire** | Do not expand; historical only |
| **Isolate** | Evidence, hosting, or stub — do not treat as completed parity |

## Platform and controls

| Area | Path / artifact | Disposition | Rationale |
|---|---|---|---|
| Laravel + Inertia/React | framework, ADR-015 | Keep | Ratified stack |
| Synthetic-only / simulation mode | `APP_MODE`, `APP_SYNTHETIC_ONLY`, middleware | Keep | Mandatory safety |
| Fortify auth / settings | auth stack | Keep / Adapt | Expand cohort/contextual auth later |
| RBAC roles/capabilities/Gates | `app/Support/Authorization`, seeders, denial tests | Keep / Adapt | Teaching-demo quality; fail-closed |
| Schema-qualified Postgres tables | `SchemaQualifier`, `UsesSchemaQualifiedTable` | Keep | Pooled search_path drift on demo host |
| Append-only audit | `audit_events`, `App\Support\Audit` | Keep / Adapt | Broaden coverage per NFR |
| CI workflows | `.github/workflows` | Keep | Extend with parity suites |
| Vercel + Supabase demo | `vercel.json`, hosting notes | Isolate | Synthetic demo only |
| Public self-registration | disabled | Keep | Provisioned teaching accounts |

## Rebuild domain already on this line of work

| Area | Path / artifact | Disposition | Rationale |
|---|---|---|---|
| Patient / encounter / clinical entry models | `app/Models/*`, migrations | Adapt | Outpatient spine; expand only with PAR + tests |
| Clinic / doctor / schedule masters | models + `OutpatientMastersSeeder` | Keep / Adapt | Required for SAHABAT poli→dokter→jadwal |
| Pendaftaran RJ SAHABAT desk UI | `resources/js/pages/pendaftaran/rawat-jalan.tsx` | Adapt | Must meet DEC-014 minimum; map in `PENDAFTARAN_SAHABAT_FIELD_MAP.md` |
| Pemeriksaan / RM RJ pages | `resources/js/pages/pemeriksaan|rm/*` | Adapt | Still below SAHABAT density — next density pass |
| Outpatient controllers + routes | `Outpatient*Controller`, `routes/web.php` | Adapt | Preserve schema-qualified validation hardenings |
| Module placeholder shell | `/modul/{category}`, nav | Adapt | Honest “segera” / disabled — no fake-full HIS |
| Demo actors / facilititor seed | seeders | Isolate | Synthetic accounts only |
| SAHABAT field map + gap docs | `PENDAFTARAN_SAHABAT_FIELD_MAP.md`, `SAHABAT_VS_DEMO_GAP.md` | Keep | Desk acceptance aid |
| SAHABAT PNG evidence | `_evidence/sahabat-page-*.png` | Isolate (evidence) | Visual oracle for DEC-014 |

## Evidence packs (not application code)

| Area | Path | Disposition | Rationale |
|---|---|---|---|
| Vendor assessment | `docs/vendor-simrs-assessment-2026-08-21/` | Isolate | Functional reference |
| Visual field capture | `docs/legacy-visual-field-capture/` | Isolate | Screenshots + catalogues; commit only after privacy review |
| North-star decks / deliverables | `deliverables/` | Isolate | Discussion artifacts; not runtime |
| Local Vercel link | `.vercel/` | Isolate | Do not commit secrets |

## Historical MVP (do not revive by default)

Lived on older `main` / agent branches; **Retire** as product direction (recover only via explicit DEC):

- Antrean kerja / outpatient work-queue UX
- Bulk `app/Modules/*` teaching MVP graph copied wholesale without PAR disposition
- Production-shaped integration “just to look complete”

## Explicit reuse policy

1. **Desk screens** that replace SAHABAT registration/examination must meet **DEC-014** (three-column Data Pasien minimum from assessed screenshots). Thin ~7-field cards are non-compliant.
2. Classic CAP-REG-003 density (**224** rendered controls) is a **discovery backlog**, not an instruction to ship every BPJS hidden field or live integration.
3. Prefer blueprint + `PARITY_REQUIREMENTS_MATRIX.md` + visual capture IDs over “it looks done on the demo.”
4. Do not bulk-copy vendor HTML/CSS/JS or schema. Re-implement with server-side authz, audit, and synthetic fixtures.
5. Teaching stubs (Cetak, SEP checkbox, Cek/FR/FP) must remain **visibly non-production**.

## Explicitly not present (must not be claimed done)

Full ED/inpatient beds; diagnostics LIS/PACS; surgery/IBS; GF ledger; full Kasir; 117 reports; production BPJS; IoT; mortuary/ambulance; full Manajemen Data; Pemeriksaan/RM SAHABAT-grade desks.

## Related decisions

- DEC-012 Option B clean-slate
- DEC-013 Vendor UI reference / Antrean anti-reference
- DEC-014 SAHABAT Data Pasien minimum desk density
- ADR-015 Stack; ADR-016 Clean-slate replace-in-place
