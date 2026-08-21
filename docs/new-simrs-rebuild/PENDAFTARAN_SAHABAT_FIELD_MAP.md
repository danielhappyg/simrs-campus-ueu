# SAHABAT → Campus UEU field map (Pendaftaran RJ)

**Branch:** `feature/pendaftaran-sahabat-desk`  
**Date:** 2026-08-21  
**Scope:** teaching-safe outpatient registration desk. Vendor UI is visual/workflow reference only (DEC-013). No production BPJS send.  
**Bar:** DEC-014 — assessed Data Pasien desk (`_evidence/sahabat-page-1.png`, `sahabat-page-2.png`) + live REG captures.

## DEC-014 compliance (desk silhouette)

| Requirement | Status |
|---|---|
| Three-column desk (Data pribadi / PJ+Kunjungan / Cetak) | Met |
| Top strip: Riwayat, EMR, RegOn, Cari Pasien, Approval SEP, Data Kunjungan | Met (stubs + live Cari) |
| Data pribadi through wilayah, Domisili Auto, telepon, email, suku/bahasa, catatan | Met |
| JK → Tempat lahir → Tanggal lahir order (SAHABAT) | Met |
| PJ Auto + Edit | Met |
| Poli → Dokter → Jadwal masters | Met |
| Cara masuk / bayar / asuransi + Cek/FR/FP stubs | Met |
| No. Antrian persist + print stubs + Cetak stub + Simpan | Met |
| Production BPJS/SEP/SatuSehat send | Correctly absent |

## Field map

| SAHABAT zone / field | Our column / surface | Persist? | Notes |
|---|---|---|---|
| Top: Riwayat, EMR, Ambil RegOn, Approval SEP, Data Kunjungan | Action strip stubs | No | Disabled + “stub”; honest, not fake-live |
| Top: Cari Pasien | `GET ?q=` on patients | Yes (read) | Search by nama / No. RM / NIK |
| No. Rekam Medis | `patients.medical_record_number` | Yes | Auto `RM-…` if blank |
| NIK | `patients.nik` | Yes | Teaching/synthetic only |
| Nama Pasien | `patients.full_name` | Yes | Locked when returning patient selected |
| Jenis Kelamin | `patients.sex` | Yes | `LAKI_LAKI` / `PEREMPUAN` / `TIDAK_DIKETAHUI` |
| Tempat Lahir | `patients.place_of_birth` | Yes | |
| Tanggal Lahir | `patients.date_of_birth` | Yes | |
| Agama | `patients.religion` | Yes | Enum teaching list |
| Pendidikan | `patients.education` | Yes | Enum teaching list |
| Pekerjaan | `patients.occupation` | Yes | Enum teaching list |
| Provinsi→…→Kelurahan | `patients.province/city/district/village` | Yes | Cascading **teaching stubs** (not live wilayah API) |
| Dusun/Jalan | `patients.address_line` | Yes | |
| Domisili | `patients.domicile` | Yes | UI “Auto” concatenates wilayah |
| Telepon | `patients.phone` | Yes | |
| Email | `patients.email` | Yes | |
| Suku / Bahasa | `patients.ethnicity` / `patients.language` | Yes | Teaching selects (DEC-014 silhouette) |
| Catatan pasien | `patients.notes` | Yes | |
| PJ Nama (+ Auto / Edit) | `patients.responsible_party_name` | Yes | Auto copies patient name; Edit focuses field |
| Kode Booking | `encounters.booking_code` | Yes | Optional |
| Tgl Kunjungan + Baru chip | `encounters.visit_date` | Yes | Chip is UI-only |
| Poliklinik | `clinics` + `encounters.clinic_id` + denorm `clinic_name` | Yes | Seeded masters; replaces free-text klinik |
| Dokter | `doctors` + `encounters.doctor_id` + denorm `doctor_name` | Yes | Cascade from poli |
| Jadwal | `clinic_schedules` + `encounters.clinic_schedule_id` + denorm `schedule_label` | Yes | Cascade from dokter |
| Cara Masuk | `encounters.admission_mode` | Yes | Datang sendiri / Rujukan / IGD |
| Cara Bayar | `encounters.payer_type` | Yes | UMUM / BPJS / LAINNYA (no real claim send) |
| No Asuransi + Cek/FR/FP | `encounters.insurance_number` | Number yes; buttons no | Cek/FR/FP disabled stubs |
| Catatan Kunjungan | `encounters.chief_complaint` | Yes | |
| No. Antrian | `encounters.queue_number` | Yes | Auto-increment per day |
| SEP / Gelang / Kartu / Consent / Fast Track | Right panel checkboxes | No | UI stubs only |
| Cetak | Button | No | Disabled stub |
| Simpan | `POST /pendaftaran/rawat-jalan` | Yes | Creates/updates patient + encounter |

## Masters (synthetic seed)

`OutpatientMastersSeeder`: Poli Umum, Gigi, Anak, Jantung — each with dokter + jadwal labels. Auto-seeded on first Pendaftaran page load if empty; also called from `DatabaseSeeder`.

## Explicitly not in this pass

- Production SEP/BPJS bridging, biometrics, real EMR deep-link
- Antrean kerja / work-queue MVP revival
- Classic CAP-REG-003 extra fields (status nikah, SatuSehat Id, hambatan, full Cetak set) — discovery backlog
- Pemeriksaan / RM desk density parity (next pass)
