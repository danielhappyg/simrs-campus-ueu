# Teaching UAT — Pendaftaran metadata, cetak, rekap (2026-08-22)

Agent-operated synthetic UAT on the live demo. No passwords, tokens, or real patient data.

| Field | Value |
| --- | --- |
| Record ID | `UAT-TEACH-20260822-01` |
| Local time | 2026-08-22 ~11:40–12:05 Asia/Jakarta |
| Environment | `https://simrs-campus-ueu-demo.vercel.app` (Vercel production) |
| Deployment | `dpl_4PKT5F2u2owUgsTS1yVnJcJjkLDe` |
| Candidate commit | `80d6d802a32b9b131d06663d1f8006fb140240c8` (`Align Pendaftaran metadata codes and labels (#46)`) |
| Mode | `SIMULATION` / synthetic-only |
| Actor (UI) | Fasilitator Simulasi UEU (`fasilitator.simulasi@example.invalid`) |
| Browser | Chrome via browser-harness (attached to existing session) |
| Recording dir | `/Users/danielhappyg/.config/browser-harness/agent-workspace/recordings/uat-cetak-rekap-metadata` |

## Verdict

**Partial pass.** Cetak pengajaran + rekap asal (walk-in vs online) work on census encounters. **Simpan pendaftaran baru is blocked by HTTP 500** on production (reproduced with minimal payload). Metadata UI after hard refresh looks correct but could not be persisted end-to-end.

## Scenarios

| # | Scenario | Status | Notes |
| --- | --- | --- | --- |
| 1 | Hard refresh loads new assets + marital/suku/bahasa selects | **PASS** | Stale SPA first; after `location.reload(true)` assets `app-DDtLwWZU.js` / `rawat-jalan-Cfr6Wd0j.js`; `marital_status` select with Kawin; ethnicity/language selects |
| 2 | Fill RJ with Kawin + DKI wilayah cascade + booking `RGN-UAT-2208` | **PASS (UI only)** | Provinsi `31` → Jakarta Selatan → Jagakarsa → Ciganjur; clinic Umum / dr. Budi / Selasa; SEP + No. Antrian checked |
| 3 | Simpan new synthetic encounter | **FAIL** | `POST /pendaftaran/rawat-jalan` → Inertia 500 overlay. Minimal payload (name/DOB/sex/clinic/doctor/schedule/UMUM) also 500. No new `laravel.patients` row for the UAT name/NIK. Vercel runtime log confirms 500 on `dpl_4PKT5F2u2owUgsTS1yVnJcJjkLDe`; exception message truncated in log UI (`APP_DEBUG` off → `{"message":"Server Error"}`) |
| 4 | Cetak bukti + antrian + SEP simulasi (census row) | **PASS** | Opened `/pendaftaran/kunjungan/01M0K7KSFQYMF635Y3DNXK1DHB/cetak?docs=bukti,antrian,sep`. Banner: `DOKUMEN PENGAJARAN · BUKAN KLAIM / SEP BPJS ASLI · DATA SINTETIS`. SEP: `SIM-SEP-260822-016`, text `Tidak dikirim ke VClaim`. No `Bearer`. Sex/agama/wilayah shown as labels; marital blank (`—`) on this census patient |
| 5 | Rekap walk-in vs online/booking | **PASS** | `/pendaftaran/rekap`: origin filter `ONLINE` → Total 4 / Online 4 / Walk-in 0; `WALK_IN` → Total 0. Teaching copy present |
| 6 | Klaim / BPJS / Apotek remain Soon | **PASS** | Top nav still shows Soon for those modules |

## Recording note

Harness recording path above. Trace only has **2 frames** (start accidentally on an unrelated Gmail tab when recording began; stop on rekap). Most UAT steps used DOM/JS fills + coordinate clicks, so the raw trace is weak for video. Prefer this markdown + live demo re-run for evidence; do not treat `0001.jpg` as SIMRS evidence.

## Open blocker (Simpan 500)

- **Symptom:** any new RJ `POST` returns 500; form does not reset; no patient insert.
- **Ruled out so far:** missing `marital_status` column (present); missing clinic/schedule rows (present); DB reject of patient row shape (manual SQL insert of a probe patient succeeded, then deleted).
- **Still unknown:** exact exception class/message (production debug off; Vercel log body truncated to mid-stack middleware frames).
- **Likely next fix:** surface `[simrs]` exception class/message in synthetic flash or temporarily enable debug on demo; also schema-qualify `AuditEvent` (currently no `UsesSchemaQualifiedTable`) and re-test whether failure is post-commit audit vs pre-insert validation/`firstOrFail`.

## Synthetic artifacts used

- Attempted patient: Siti Metadata UAT / NIK `3174092201900002` / booking `RGN-UAT-2208` — **not persisted**
- Cetak/rekap used census encounter `01M0K7KSFQYMF635Y3DNXK1DHB` (Putra Wijaya Sintetis / SYNTH-CENSUS-016)
- SQL probe patient `01TESTPROBEUAT00000000000000` created then deleted during diagnosis
