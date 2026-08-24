# Teaching facilitator runbook — Rawat jalan end-to-end (2026-08-22)

Single script for synthetic RJ demo on the hosted teaching environment. Use with [VERCEL_SUPABASE_DEMO.md](VERCEL_SUPABASE_DEMO.md).

**Program handoff (whole rebuild story, PRs, traps, next work):** [HANDOFF_SIMRS_TEACHING_REBUILD_2026-08-23.md](HANDOFF_SIMRS_TEACHING_REBUILD_2026-08-23.md)

**Demo URL:** https://simrs-campus-ueu-demo.vercel.app  
**Code baseline:** lifecycle-contract release (record the deployed SHA before UAT; historical lab origin is `807bbf2` / PR #48)
**Boundary:** SIMULATION only — no real patient data, no production BPJS/VClaim/SATUSEHAT.

**Evidence boundary:** this runbook exercises an available teaching slice. Passing it does not by itself grant SAHABAT parity acceptance or clinical production readiness.

---

## Before you start

1. Confirm the permanent indicator **SIMULASI — DATA SINTETIS** appears after login and remains visible on each teaching desk. If it is absent, stop the rehearsal and record a teaching-readiness defect (DEC-015).
2. Use demo passwords from your secure store (`DEMO_ACCOUNT_PASSWORD` on Vercel — never paste in slides or chat).
3. Prefer **role-specific accounts** for class demos; use **one multi-role account** for solo rehearsal.

### Demo accounts (`example.invalid`)

| Role | Email | Use for |
| --- | --- | --- |
| Registrar | `registrar.demo@example.invalid` | Pendaftaran |
| Nurse | `nurse.demo@example.invalid` | Asesmen keperawatan, lab result |
| Physician | `physician.demo@example.invalid` | Asesmen medis, order lab |
| RMIK | `rmik.demo@example.invalid` | RM review / close |
| Solo walkthrough | `mahasiswa.rmik@example.invalid` | All steps in one login (rehearsal only) |
| Facilitator (registrar) | `fasilitator.simulasi@example.invalid` | Pendaftaran UAT (Run 2) |

---

## Full teaching arc (~25–35 min)

For acceptance evidence, use the role-specific accounts below and keep one encounter identifier through every step. The solo multi-role account is for facilitator practice only.

### 1. Pendaftaran rawat jalan — Registrar

**Path:** Beranda → **Pendaftaran** → Rawat Jalan

1. Fill **Data Pasien** (synthetic): name, DOB, sex, NIK pattern, Kawin, Islam, suku/bahasa, DKI cascade if demonstrating wilayah.
2. Select **Poli → Dokter → Jadwal**, visit date = today.
3. Optional: booking code (e.g. `RGN-DEMO-001`) for rekap **Online**.
4. Click **Simpan** — expect green success flash and row on today’s list.
5. Optional: **Cetak** bukti + antrian + SEP on the new encounter (teaching PDF; no VClaim).

**Expected:** Encounter status **Terdaftar** (`REGISTERED`), queue number assigned.

**Tip (Run 2 UAT):** If Simpan appears dead, refresh once; React controlled fields must be filled via the desk UI before submit.

---

### 2. Pemeriksaan — Nurse

**Path:** **Pemeriksaan** → Rawat Jalan → open encounter from worklist

1. In **Asesmen keperawatan**, enter a short synthetic assessment.
2. Select **Simpan draf**; confirm a new attributable version appears.
3. Select **Finalisasi versi** only after the saved draft is correct.

**Expected:** Status **Dalam pemeriksaan** (`IN_EXAMINATION`); the nursing document is read-only after Final.

---

### 3. Pemeriksaan — Physician

Same encounter (log in as physician or continue solo account).

1. Complete the required synthetic **Anamnesis**, **Pemeriksaan objektif**, **Asesmen klinis**, and **Rencana pelayanan** fields.
2. Select **Simpan draf**; confirm a new attributable version appears.
3. Select **Finalisasi versi** only after the saved draft is correct.

**Expected:** Status **Siap RM** (`READY_FOR_RM`) after medical Final; the document is then read-only.

---

### 4. Lab handoff — Physician then Nurse

#### 4a. Order lab (Physician)

1. Tab **Order Lab** (live on RJ; not stub).
2. Select **Hemoglobin (HB)** (or GDS / Urinalisis).
3. Optional clinical question: e.g. *evaluasi anemia*.
4. **Simpan order lab** → success flash.
5. Link **Buka meja lab →** opens Laboratorium worklist.

#### 4b. Enter result (Nurse)

**Path:** **Pemeriksaan** → **Laboratorium**

1. Find the active order for your patient.
2. Click **Hasil** → enter synthetic text, e.g. `Hb 12.8 g/dL`.
3. Confirm status **Final** → **Simpan hasil lab**. Preliminary results are not available in this slice.

**Expected:** Order leaves active worklist; its one final result is immutable; audit events `clinical.lab.order.create` and `clinical.lab.result.write` exist.

#### 4c. Verify on encounter

Reopen **Pemeriksaan → Rawat Jalan → encounter → Order Lab**.

**Expected:** Order status **Selesai**, green result panel with entered text.

---

### 5. RM rawat jalan — RMIK

**Path:** **RM** → Rawat Jalan

1. Encounter appears in the RM worklist; select **Tinjau RM**.
2. Confirm the read-only nursing/medical version history, automatic checklist, source fingerprint, and **Order lab aktif = 0**.
3. Select **Simpan hasil review**.
4. When the current review is complete with no blockers, select **Sign-off dan tutup kunjungan** and confirm.

**Expected:** Status **Ditutup** (`CLOSED`); the row remains available as **Lihat RM**, showing the signed review and version history read-only; no new clinical writes.

If an active lab order remains, the close action must be unavailable and the server must reject a stale/direct request with reason `active_lab_orders`. Resolve it with its one FINAL result; do not cancel or edit rows directly.

---

### 6. Rekap (optional) — Registrar

**Path:** **Pendaftaran** → **Rekap**

- Filter date = today, channel **Online** if booking was used.
- Confirm new row and counts.

---

### 7. Acceptance checks and cleanup — Facilitator/Admin

These checks are required for the next continuous UAT record even though the current Runs 1–3 were captured as separate slices:

1. Attempt one protected clinical or RM action with an unauthorized role and record the denied result without bypassing RBAC.
2. On a disposable synthetic encounter, attempt RM closure while an order is ACTIVE; expect rejection and an unchanged encounter.
3. Complete that order with one FINAL result, then close the encounter; expect `CLOSED`.
4. Confirm a late-result or duplicate-final attempt is rejected without mutation. Do not attempt amendment/reopen because those workflows are not built.
5. Record the encounter, order/result, denial reason, audit-event and deployed-commit identifiers used in the journey.
6. Run only the documented synthetic session/reset procedure for the rehearsal data; never delete hosted rows manually during class.
7. Confirm the reset/cleanup scope did not affect another teaching session.

If the safe session-scoped reset procedure is not yet available, mark cleanup **blocked** and preserve the synthetic evidence for an authorized operator. Do not improvise destructive SQL.

---

## Quick reference — status spine

```
REGISTERED → IN_EXAMINATION → READY_FOR_RM → CLOSED
     ↑              ↑                ↑
  Pendaftaran Nursing document Medical FINAL
                                 (+ lab order/result parallel;
                                  incomplete checklist or ACTIVE order blocks sign-off)
                                       FINAL → COMPLETED → RM sign-off
```

Lab orders do **not** change encounter status; structured documents drive the spine. An `ACTIVE` lab order is nevertheless a sign-off guard.

---

## Still stubbed (say honestly in class)

| Area | UI |
| --- | --- |
| Pemeriksaan tabs | Diagnosa, Tindakan, **Order Rad**, Resep (structured documents and Order Lab are live) |
| Header actions | Cetak / Riwayat EMR / Order / Resep on exam desk |
| Nav modules | Klaim, BPJS, Apotek → **Soon** |
| Charges / LIS | Not implemented |
| Lab lifecycle extensions | Preliminary, amendment/correction, cancellation and encounter reopen are not implemented |

---

## Troubleshooting

| Symptom | Likely cause | Action |
| --- | --- | --- |
| Simpan 500 on Pendaftaran | Old deploy pre-#47 | Confirm production commit ≥ `9fc0b3b` |
| Order Lab tab missing | Old deploy pre-#48 | Confirm production commit ≥ `807bbf2` |
| Nurse cannot enter lab result | RBAC not seeded | Re-run RBAC sync on Supabase (see PR #48 ops notes) |
| Laboratorium 404 | Migration not applied | Apply `2026_08_22_001100_create_lab_service_tables` |
| Structured document or RM review fails because tables are missing | New migration not applied | Apply `2026_08_24_000100_create_outpatient_documentation_tables` from the deployed commit |
| Empty lab worklist | No ACTIVE orders | Create order from RJ encounter first |
| RM sign-off disabled/rejected | Review is stale/incomplete or encounter still has an ACTIVE lab order | Save a current review, resolve checklist blockers, and enter the lab order's one FINAL result; never edit/cancel hosted rows directly |

---

## Related evidence

- Pendaftaran UAT Runs 1–2: [TEACHING_UAT_CETAK_REKAP_METADATA_2026-08-22.md](TEACHING_UAT_CETAK_REKAP_METADATA_2026-08-22.md)
- Lab UAT Run 3: [TEACHING_UAT_LAB_SLICE_2026-08-22.md](TEACHING_UAT_LAB_SLICE_2026-08-22.md)
- Proposed NEW lifecycle policy: [OUTPATIENT_ORDER_RESULT_CLOSURE_CONTRACT.md](../new-simrs-rebuild/phase-1/OUTPATIENT_ORDER_RESULT_CLOSURE_CONTRACT.md)
- Hosted ops: [VERCEL_SUPABASE_DEMO.md](VERCEL_SUPABASE_DEMO.md)

Passing this script does not convert DEC-016 or lifecycle parity to Accepted. Clinical/Laboratory and RMIK owner review remains required.
