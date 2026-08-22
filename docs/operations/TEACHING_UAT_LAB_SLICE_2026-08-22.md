# Teaching UAT — Lab order + result slice (2026-08-22)

Agent-operated and facilitator-ready UAT for PR #48 lab flow on the live demo. No passwords or tokens in this file.

## Run 3 — Lab slice on production

| Field | Value |
| --- | --- |
| Record ID | `UAT-TEACH-20260822-03` |
| Local time | 2026-08-22 ~19:30 Asia/Jakarta |
| Environment | `https://simrs-campus-ueu-demo.vercel.app` |
| Deployment | `dpl_4BJScnJZXo98j2AF1h7YydJhL3vx` (production promote of #48 / `807bbf2`) |
| Mode | `SIMULATION` / synthetic-only |
| Prior runs | [Run 1–2 — Pendaftaran cetak/rekap](TEACHING_UAT_CETAK_REKAP_METADATA_2026-08-22.md) |

### Verdict — **PASS**

Production lab slice works end-to-end: order HB → enter FINAL result → result visible on encounter. Infra + automated HTTP rehearsal both green. Optional classroom UI walkthrough still uses [TEACHING_RJ_FACILITATOR_RUNBOOK_2026-08-22.md](TEACHING_RJ_FACILITATOR_RUNBOOK_2026-08-22.md).

### Automated / agent checks

| # | Check | Status | Notes |
| --- | --- | --- | --- |
| 1 | `GET /up` | **PASS** | HTTP 200 on production |
| 2 | `GET /pemeriksaan/laboratorium` (unauthenticated) | **PASS** | Redirects to `/login` (route registered, not 404) |
| 3 | Supabase `laravel.lab_service_requests` + `lab_diagnostic_results` | **PASS** | Tables present post-migration |
| 4 | RBAC `clinical.lab.result.write` on nurse role | **PASS** | 19 permissions on hosted DB |
| 5 | `OutpatientLabFlowTest` (local, `807bbf2`) | **PASS** | 3/3 — order, result, encounter props |
| 6 | Authenticated lab order + result on production | **PASS** | 2026-08-23 ~03:55 Asia/Jakarta; same Inertia endpoints as UI |

### Facilitator / agent confirmation

| Step | Role | Action | Pass? | Notes |
| --- | --- | --- | --- | --- |
| A | `mahasiswa.rmik@example.invalid` (physician caps) | POST lab-order HB on IN_EXAMINATION encounter | **PASS** | Order `01M0NMMPEA5GWBQVZP6H1N5KGH` |
| B | Same account (nurse caps) | POST laboratorium result FINAL | **PASS** | `Hb 12.8 g/dL (sintetis UAT Run 3)` |
| C | Same account | GET encounter show → `lab_orders[].result` present | **PASS** | Status `COMPLETED` |
| D | RMIK | RM RJ → complete encounter (optional close-loop) | ☐ | Not required for lab slice pass |

### Synthetic artifacts (Run 3)

| Artifact | Value |
| --- | --- |
| Actor | `mahasiswa.rmik@example.invalid` |
| Patient name | `Joko Widodo Sintetis` |
| Encounter `public_id` | `01M0K7KSFKCTXSKM0005YMS6C8` |
| Lab order `public_id` | `01M0NMMPEA5GWBQVZP6H1N5KGH` |
| Result text (synthetic) | `Hb 12.8 g/dL (sintetis UAT Run 3)` |
| Order status after result | `COMPLETED` |

### Out of scope (unchanged)

- LIS / instrument integration
- Tarif, charges, specimen tracking
- PA / mikro sub-desks
- Klaim / BPJS / Apotek nav (**Soon**)

### Evidence links

- PR: https://github.com/danielhappyg/simrs-campus-ueu/pull/48
- Feature tests: `tests/Feature/Outpatient/OutpatientLabFlowTest.php`
- Facilitator script: [TEACHING_RJ_FACILITATOR_RUNBOOK_2026-08-22.md](TEACHING_RJ_FACILITATOR_RUNBOOK_2026-08-22.md)
