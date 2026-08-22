# SAHABAT → Campus UEU field map (Pendaftaran RJ)

**Branch:** `feature/pendaftaran-metadata-fidelity`  
**Date:** 2026-08-22  
**Scope:** teaching-safe outpatient registration desk. Vendor UI is visual/workflow reference only (DEC-013). No production BPJS send.  
**Bar:** DEC-014 — assessed Data Pasien desk + live REG captures. Tester note 2026-08-22: variabel/metadata sesuai.

Canonical codes and Indonesian labels live in `App\Support\TeachingVocabulary`. Desk selects, cetak, and rekap must use that class — do not invent parallel maps in React.

## DEC-014 compliance (desk silhouette)

| Requirement | Status |
|---|---|
| Three-column desk (Data pribadi / PJ+Kunjungan / Cetak) | Met |
| Top strip: Riwayat, EMR, RegOn, Cari Pasien, Approval SEP, Data Kunjungan | Met (stubs + live Cari + live Data Kunjungan/Rekap) |
| Data pribadi through wilayah, Domisili Auto, telepon, email, suku/bahasa, catatan | Met |
| JK → Tempat lahir → Tanggal lahir order (SAHABAT) | Met |
| Status pernikahan (CAP-REG-002/003 `marital_status_id`) | Met (teaching codes) |
| PJ Auto + Edit | Met |
| Poli → Dokter → Jadwal masters | Met |
| Cara masuk / bayar / asuransi + Cek/FR/FP stubs | Met |
| No. Antrian persist + teaching Cetak | Met |
| Production BPJS/SEP/SatuSehat send | Correctly absent |

## Field map

| SAHABAT zone / field | Our column / surface | Persist? | Notes |
|---|---|---|---|
| Top: Riwayat, EMR, Ambil RegOn, Approval SEP | Action strip stubs | No | Disabled + “stub” |
| Top: Data Kunjungan | `/pendaftaran/rekap` | Yes (read) | Live recap |
| Top: Cari Pasien | `GET ?q=` on patients | Yes (read) | Search by nama / No. RM / NIK |
| No. Rekam Medis | `patients.medical_record_number` | Yes | Auto `RM-…` if blank |
| NIK | `patients.nik` | Yes | Teaching/synthetic only |
| Nama Pasien | `patients.full_name` | Yes | Locked when returning patient selected |
| Jenis Kelamin | `patients.sex` | Yes | `LAKI_LAKI` / `PEREMPUAN` / `TIDAK_DIKETAHUI` |
| Tempat Lahir | `patients.place_of_birth` | Yes | |
| Tanggal Lahir | `patients.date_of_birth` | Yes | |
| Status Pernikahan | `patients.marital_status` | Yes | `BELUM_KAWIN` / `KAWIN` / `CERAI_HIDUP` / `CERAI_MATI` |
| Agama | `patients.religion` | Yes | Teaching enum |
| Pendidikan | `patients.education` | Yes | Teaching enum |
| Pekerjaan | `patients.occupation` | Yes | Teaching enum |
| Provinsi→…→Kelurahan | `patients.province_code` + nama denorm | Yes | Lazy BPS cascade (`/wilayah/*`); names resolved server-side |
| Dusun/Jalan | `patients.address_line` | Yes | |
| Domisili | `patients.domicile` | Yes | UI “Auto” concatenates wilayah |
| Telepon | `patients.phone` | Yes | |
| Email | `patients.email` | Yes | |
| Suku / Bahasa | `patients.ethnicity` / `patients.language` | Yes | Teaching selects; free-text rejected |
| Catatan pasien | `patients.notes` | Yes | |
| PJ Nama (+ Auto / Edit) | `patients.responsible_party_name` | Yes | Auto copies patient name; Edit focuses field |
| Kode Booking | `encounters.booking_code` | Yes | Optional; non-empty = asal ONLINE on rekap |
| Tgl Kunjungan + Baru chip | `encounters.visit_date` | Yes | Chip is UI-only |
| Poliklinik | `clinics` + `encounters.clinic_id` + denorm `clinic_name` | Yes | Seeded masters |
| Dokter | `doctors` + `encounters.doctor_id` + denorm `doctor_name` | Yes | Cascade from poli |
| Jadwal | `clinic_schedules` + `encounters.clinic_schedule_id` + denorm `schedule_label` | Yes | Cascade from dokter |
| Cara Masuk | `encounters.admission_mode` | Yes | Datang sendiri / Rujukan / Dari IGD (same labels on IGD minus Dari IGD) |
| Cara Bayar | `encounters.payer_type` | Yes | UMUM / BPJS / LAINNYA — label **BPJS** (not a live claim) |
| No Asuransi + Cek/FR/FP | `encounters.insurance_number` | Number yes; buttons no | Cek/FR/FP disabled stubs |
| Catatan Kunjungan | `encounters.chief_complaint` | Yes | |
| No. Antrian | `encounters.queue_number` | Yes | Auto-increment per day |
| SEP / Gelang / Kartu / Consent | Right panel + teaching print | Yes (print) | HTML/PDF pengajaran; Fast track still stub |
| Cetak | `/pendaftaran/kunjungan/{id}/cetak` | Yes (read) | Bukti uses the same labels/codes as the desk |
| Simpan | `POST /pendaftaran/rawat-jalan` | Yes | Creates/updates patient + encounter |

## Masters (synthetic seed)

`OutpatientMastersSeeder`: Poli Umum, Gigi, Anak, Jantung — each with dokter + jadwal labels. Auto-seeded on first Pendaftaran page load if empty; also called from `DatabaseSeeder`.

Wilayah: `wilayah_provinces|regencies|districts|villages` (BPS codes). Hosted demo is partial vs national kelurahan catalogue.

## Explicitly not in this pass

- Production SEP/BPJS bridging, biometrics, real EMR deep-link
- Antrean kerja / work-queue MVP revival
- SatuSehat Id, hambatan komunikasi, penerjemah (CAP-REG-003 extras)
- Pemeriksaan order sets (lab/rad)
