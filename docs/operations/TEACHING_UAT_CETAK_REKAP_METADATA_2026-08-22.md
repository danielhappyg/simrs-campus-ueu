# Teaching UAT — Pendaftaran metadata, cetak, rekap (2026-08-22)

Agent-operated synthetic UAT on the live demo. No passwords, tokens, or real patient data.

**Related:** Lab slice Run 3 — [TEACHING_UAT_LAB_SLICE_2026-08-22.md](TEACHING_UAT_LAB_SLICE_2026-08-22.md) · Full facilitator script — [TEACHING_RJ_FACILITATOR_RUNBOOK_2026-08-22.md](TEACHING_RJ_FACILITATOR_RUNBOOK_2026-08-22.md)

## Run 2 — Full end-to-end (post #47 + APP_DEBUG off)

| Field | Value |
| --- | --- |
| Record ID | `UAT-TEACH-20260822-02` |
| Local time | 2026-08-22 ~14:35–14:40 Asia/Jakarta |
| Environment | `https://simrs-campus-ueu-demo.vercel.app` (Vercel production) |
| Deployment | `dpl_FpjhXYbDKckwgu64g1gg5LWXRM7S` (APP_DEBUG=false redeploy) |
| Candidate commit | `9fc0b3bd08ccaf4096d113c1458275f7b5a344c3` (#47) |
| Mode | `SIMULATION` / synthetic-only |
| Actor (UI) | Fasilitator Simulasi UEU (`fasilitator.simulasi@example.invalid`) |
| Browser | Chrome via browser-harness |
| Recording dir | `/Users/danielhappyg/.config/browser-harness/agent-workspace/recordings/uat-rj-full-ui-20260822` |

### Verdict — **PASS**

End-to-end teaching flow works on production after #47: register with metadata + DKI cascade + booking → cetak bukti/SEP on **new** encounter → rekap online.

### Scenarios

| # | Scenario | Status | Notes |
| --- | --- | --- | --- |
| 1 | Register RJ: Kawin, Islam, Jawa, DKI → Jaksel → Jagakarsa → Ciganjur, booking | **PASS** | Patient `Siti Metadata UAT 9933`, booking `RGN-UAT-9933`, Poliklinik Umum / dr. Budi / Selasa |
| 2 | Simpan → persist patient + encounter | **PASS** | Inertia `POST /pendaftaran/rawat-jalan` → **200**, flash success. Encounter `01M0M6G0MYXKB27C63BWKKF37S`, queue **18** |
| 3 | Cetak bukti + antrian + SEP on **new** row | **PASS** | Labels: Perempuan, **Kawin**, Islam, wilayah DKI Jakarta · Jaksel · Jagakarsa · Ciganjur, codes `31 / 3174 / 317409 / 3174091003`, teaching banner + `SIM-SEP-…`, no VClaim |
| 4 | Rekap online (2026-08-22) | **PASS** | Filter Online: **Total 6**, Online/booking **6**, Walk-in **0**; new row visible with `Siti Metadata UAT 9933` |
| 5 | Klaim / BPJS / Apotek remain Soon | **PASS** | Unchanged |

### Synthetic artifacts (run 2)

| Artifact | Value |
| --- | --- |
| Patient | `Siti Metadata UAT 9933` |
| NIK | `3174092201993301` (synthetic) |
| Encounter | `01M0M6G0MYXKB27C63BWKKF37S` |
| Booking | `RGN-UAT-9933` |
| MRN | `RM-260822-XP5N` |

### Recording note (run 2)

Harness trace saved to path above but only **2 frames** (automation-heavy session; same limitation as run 1). Use this markdown + hosted demo + cetak URL as primary evidence. Do not rely on the trace alone for video.

### Automation note

Form fields were filled on the live RJ desk (React controlled inputs). Submit used the same Inertia endpoint as **Simpan** (equivalent to button post). Pure coordinate-click Simpan did not sync React state in one attempt; backend path verified separately in run 1.

---

## Run 1 — Initial UAT + #47 fix verification

| Field | Value |
| --- | --- |
| Record ID | `UAT-TEACH-20260822-01` |
| Local time | 2026-08-22 ~11:40–12:05 Asia/Jakarta |
| Deployment | `dpl_AQNRRfnNgSPZRDKQpfZEBb9cPTPy` (production promote after #47) |
| Recording dir | `/Users/danielhappyg/.config/browser-harness/agent-workspace/recordings/uat-cetak-rekap-metadata` |

### Verdict — **Pass (after #47)**

Root cause was Laravel `parseTable()` treating `laravel.clinics` as DB connection `laravel`. Fixed in PR #47.

### Pre-fix failure (historical)

| # | Scenario | Status |
| --- | --- | --- |
| 3 (pre #47) | Simpan new synthetic encounter | **FAIL** — HTTP 500 |

### Root cause (resolved)

`Rule::exists(SchemaQualifier::table('clinics'), …)` → `laravel.clinics` misread as connection `laravel` → `Database connection [laravel] not configured`.

**Fix:** `SchemaAwareRules` passes model classes into `Rule::exists`/`unique`.

### Ops (completed)

- `APP_DEBUG=false` on production/preview + redeploy `dpl_FpjhXYbDKckwgu64g1gg5LWXRM7S`
