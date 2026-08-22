# Teaching UAT — Lab order + result slice (2026-08-22)

Agent-operated and facilitator-ready UAT for PR #48 lab flow on the live demo. No passwords or tokens in this file.

## Run 3 — Lab slice on production

| Field | Value |
| --- | --- |
| Record ID | `UAT-TEACH-20260822-03` |
| Local time | 2026-08-22 ~19:30 Asia/Jakarta |
| Environment | `https://simrs-campus-ueu-demo.vercel.app` |
| Deployment | Production promote after merge #48 (commit `807bbf2`) |
| Mode | `SIMULATION` / synthetic-only |
| Prior runs | [Run 1–2 — Pendaftaran cetak/rekap](TEACHING_UAT_CETAK_REKAP_METADATA_2026-08-22.md) |

### Verdict — **PASS (infrastructure + automated backend)**

Production hosts the lab slice; Supabase schema and nurse RBAC are applied. Route smoke and feature tests pass. Facilitators should run the **UI confirmation** in [TEACHING_RJ_FACILITATOR_RUNBOOK_2026-08-22.md](TEACHING_RJ_FACILITATOR_RUNBOOK_2026-08-22.md) § Lab handoff before class.

### Automated / agent checks (2026-08-22)

| # | Check | Status | Notes |
| --- | --- | --- | --- |
| 1 | `GET /up` | **PASS** | HTTP 200 on production |
| 2 | `GET /pemeriksaan/laboratorium` (unauthenticated) | **PASS** | Redirects to `/login` (route registered, not 404) |
| 3 | Supabase `laravel.lab_service_requests` + `lab_diagnostic_results` | **PASS** | Tables present post-migration |
| 4 | RBAC `clinical.lab.result.write` on nurse role | **PASS** | 19 permissions on hosted DB |
| 5 | `OutpatientLabFlowTest` (local, `807bbf2`) | **PASS** | 3/3 — order, result, encounter props |
| 6 | Full browser lab UI on production | **PENDING facilitator** | Use runbook § Lab handoff; record encounter/order IDs below |

### Facilitator UI confirmation (fill when run)

| Step | Role | Action | Pass? | Notes |
| --- | --- | --- | --- | --- |
| A | Physician (or `mahasiswa.rmik@example.invalid`) | Pemeriksaan RJ → encounter → **Order Lab** → HB → Simpan | ☐ | |
| B | Nurse (or same multi-role account) | **Pemeriksaan → Laboratorium** → Hasil → enter synthetic Hb | ☐ | |
| C | Any clinical role | Reopen encounter → **Order Lab** tab shows FINAL result | ☐ | |
| D | RMIK | RM RJ → complete encounter (optional close-loop) | ☐ | |

### Synthetic artifacts (fill after facilitator run)

| Artifact | Value |
| --- | --- |
| Encounter `public_id` | |
| Lab order `public_id` | |
| Result text (synthetic) | e.g. `Hb 12.8 g/dL` |
| Patient name | |

### Out of scope (unchanged)

- LIS / instrument integration
- Tarif, charges, specimen tracking
- PA / mikro sub-desks
- Klaim / BPJS / Apotek nav (**Soon**)

### Evidence links

- PR: https://github.com/danielhappyg/simrs-campus-ueu/pull/48
- Feature tests: `tests/Feature/Outpatient/OutpatientLabFlowTest.php`
- Facilitator script: [TEACHING_RJ_FACILITATOR_RUNBOOK_2026-08-22.md](TEACHING_RJ_FACILITATOR_RUNBOOK_2026-08-22.md)
