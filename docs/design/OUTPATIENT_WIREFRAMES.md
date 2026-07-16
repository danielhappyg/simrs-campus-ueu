# Outpatient Critical-Path Wireframes

- **Version:** 1.1 low-fidelity reference
- **Viewport:** 1440 px desktop baseline; tablet/mobile adaptations described per pattern
- **Content:** synthetic Indonesian UI examples only
- **Purpose:** establish hierarchy and interaction before visual implementation

## 1. Shared application shell

```text
┌──────────────────────────────────────────────────────────────────────────────┐
│ 🧪 SIMULASI — DATA SINTETIS • Tidak untuk pelayanan pasien nyata • SIM-001 │
├──────────────────────────────────────────────────────────────────────────────┤
│ UEU Clinical   Sesi: OPD-REF-001   [⌘ Cari tugas/pasien...]  Bantuan  DHP ▾ │
├─────────────────┬────────────────────────────────────────────────────────────┤
│ Pekerjaan Saya │ Breadcrumb / Page title                         [Actions]  │
│ Pasien          ├────────────────────────────────────────────────────────────┤
│ Pelayanan       │                                                            │
│ Pesanan & Hasil │ Main page canvas                                           │
│ Obat            │                                                            │
│ Rekam Kesehatan │                                                            │
│ Pusat Belajar   │                                                            │
│                 │                                                            │
│ Administrasi*   │                                                            │
├─────────────────┴────────────────────────────────────────────────────────────┤
│ v0.x • Staging/Local • Bantuan • Pernyataan batas penggunaan                │
└──────────────────────────────────────────────────────────────────────────────┘
```

`Administrasi` is capability-controlled. The flask/shield is an icon in the implementation, not emoji. Banner, header, and navigation remain stable; patient pages insert the context bar below the page header.

## 2. Pekerjaan Saya

```text
┌──────────────────────────────────────────────────────────────────────────────┐
│ Pekerjaan Saya                                      Peran: Mahasiswa Perawat │
│ Sesi OPD-REF-001 • Aktif sampai 12.00          [Lihat tujuan pembelajaran]   │
├──────────────────────────────────────────────────────────────────────────────┤
│ [Tugas Saya 4] [Perlu Ditinjau 0] [Serah Terima 1] [Selesai Hari Ini 3]    │
│                                                                              │
│ Cari tugas [_________________] Status [Siap ▾] Klinik [Semua ▾] [Atur kolom]│
│ 4 siap • 1 menunggu • filter: sesi aktif                        [Segarkan]   │
├─────┬─────────────────────┬──────────────────────┬───────────┬────────┬───────┤
│     │ Pasien / Kunjungan  │ Tugas                │ Dari      │ Status │ Aksi  │
├─────┼─────────────────────┼──────────────────────┼───────────┼────────┼───────┤
│ ●   │ Sinta Simulasi      │ Asesmen Awal &       │ Registrasi│ SIAP   │ Mulai │
│     │ MRN SIM-00001       │ Skrining Keselamatan │ 8 mnt     │        │       │
├─────┼─────────────────────┼──────────────────────┼───────────┼────────┼───────┤
│ !   │ Budi Contoh         │ Perbaiki tanda vital │ Supervisor│ PERLU  │ Buka  │
│     │ MRN SIM-00002       │ versi 1              │ 3 mnt     │ REVISI │       │
├─────┼─────────────────────┼──────────────────────┼───────────┼────────┼───────┤
│ ◷   │ Rani Latihan       │ Menunggu registrasi  │ —         │ TAHAN  │ Detail│
└─────┴─────────────────────┴──────────────────────┴───────────┴────────┴───────┘
│ Tugas dipilih: Sinta Simulasi                                             │
│ Klinik Umum • ENC-SYN-001 • Registrasi lengkap • Alergi belum dikaji      │
│ Tujuan: catat asesmen awal, keputusan skrining, lalu serahkan ke medis     │
│                                               [Buka rekam] [Mulai asesmen]  │
└──────────────────────────────────────────────────────────────────────────────┘
```

Annotations:

1. no invented KPI cards; counts describe actual task queues;
2. status has icon, text, and source/wait time;
3. first action opens the exact assigned encounter and keeps queue filters;
4. preview exposes required source facts without turning the whole row into an ambiguous button;
5. empty state explains whether the session has no assignments, is paused, or is complete.

Tablet: navigation collapses; queue becomes a two-line list with fixed patient/task/status/action fields. Column control hides source/time before core context.

## 3. Search and registration

```text
┌──────────────────────────────────────────────────────────────────────────────┐
│ Registrasi Pasien Sintetis                       Langkah 1 dari 3             │
│ Cari sebelum membuat pasien baru                                            │
├──────────────────────────────────────────────────────────────────────────────┤
│ Cari berdasarkan MRN / identitas sintetis / nama + tanggal lahir            │
│ [________________________________________________________] [Cari]            │
│                                                                              │
│ 1 calon kecocokan                                                           │
│ ┌──────────────────────────────────────────────────────────────────────────┐ │
│ │ Sinta Simulasi • 34 tahun • MRN SIM-00001 • lahir 12 Feb 1992          │ │
│ │ Identitas sintetis • terakhir pada Sesi SIM-000                         │ │
│ │ [Bandingkan]                                      [Gunakan pasien ini]  │ │
│ └──────────────────────────────────────────────────────────────────────────┘ │
│                                                                              │
│ Tidak ada yang cocok? [Buat pasien sintetis baru]                            │
└──────────────────────────────────────────────────────────────────────────────┘

Step 2: Confirm synthetic identity
┌──────────────────────────────────────┬───────────────────────────────────────┐
│ Identitas                            │ Sosial & kontak sintetis              │
│ Nama lengkap* [__________________]   │ Agama [Pilih ▾]                       │
│ Tanggal lahir* [____/____/______]    │ Pekerjaan [Pilih ▾]                   │
│ Jenis kelamin adm.* [__________]     │ Pendidikan [Pilih ▾]                  │
│ ID sintetis* [auto/generated]        │ Status pernikahan [Pilih ▾]           │
│ MRN [diterbitkan saat disimpan]      │ Telepon uji [____________________]    │
├──────────────────────────────────────┴───────────────────────────────────────┤
│ ☑ Saya mengonfirmasi bahwa seluruh data pada formulir ini bersifat sintetis │
│ [Batal]                                             [Simpan & lanjutkan]     │
└──────────────────────────────────────────────────────────────────────────────┘

Step 3: Visit and check-in
┌──────────────────────────────────────────────────────────────────────────────┐
│ Sinta Simulasi • SIM-00001                                                   │
│ Klinik* [Klinik Umum ▾]  Janji [OPD-REF-001 / 09.00]  Penjamin [SIMULASI]   │
│ Alasan kunjungan* [Keluhan sesuai skenario OPD-REF-001____________________]  │
│ Persetujuan pembelajaran v1.0 [Tercatat 08.52 oleh Registrasi Learner]       │
│ [Kembali]                                           [Check-in & buat antrean]│
└──────────────────────────────────────────────────────────────────────────────┘
```

Possible duplicate creation requires a reason and logs the choice. The form never asks a learner to invent a valid real NIK or real contact.

## 4. Patient context and workflow rail

```text
┌──────────────────────────────────────────────────────────────────────────────┐
│ Sinta Simulasi              MRN SIM-00001 • ID sintetis ****0042            │
│ 34 th • Perempuan           ENC-SYN-001 • Klinik Umum • 15 Jul 2026 09.00   │
│ ALERGI: BELUM DIKAJI        Status: Dalam Asesmen Awal                       │
│ Peran Anda: Mahasiswa Keperawatan • Supervisor: dr/Ns. Pengajar Sintetis    │
│                                                        [Ganti pasien] [•••]  │
├──────────────────────────────────────────────────────────────────────────────┤
│ ✓ Registrasi ── ● Asesmen Awal ── ○ Medis ── ○ Farmasi ── ○ Penutupan ── ○ RMIK │
└──────────────────────────────────────────────────────────────────────────────┘
```

The patient switch control is deliberate and warns about unsaved data. `ALERGI: BELUM DIKAJI` is not rendered as `Tidak ada alergi`.

## 5. Nursing intake and safety screen

```text
┌──────────────────────────────────────────────────────────────────────────────┐
│ Asesmen Awal dan Skrining Keselamatan   [DRAF]     Tersimpan 09.07          │
│ [Riwayat versi] [Buka sumber registrasi]             [Tinjauan supervisor]  │
├───────────────┬──────────────────────────────────────────────────────────────┤
│ Bagian        │ Sumber registrasi                                            │
│ ● Keluhan     │ Identitas diverifikasi • Klinik Umum • Alasan kunjungan...   │
│ ○ Alergi/obat ├──────────────────────────────────────────────────────────────┤
│ ○ Kesadaran   │ Keluhan utama*                                               │
│ ○ Tanda vital │ [__________________________________________________________] │
│ ○ Skrining    │ Awal/durasi [________________]  Sumber informasi [Pasien ▾] │
│ ○ Serah terima├──────────────────────────────────────────────────────────────┤
│               │ Status alergi*                                               │
│               │ ( ) Alergi diketahui  ( ) Tidak ada alergi yang dilaporkan  │
│               │ ( ) Belum dapat dikaji                                      │
│               │ [Jika alergi: zat, reaksi, tingkat keparahan...]             │
│               │ Obat saat ini: ( ) Ada  ( ) Tidak ada dilaporkan  ( ) Tidak tahu │
│               ├──────────────────────────────────────────────────────────────┤
│               │ Kesadaran/status mental* [Pilih/uraikan____________________] │
│               ├──────────────────────────────────────────────────────────────┤
│               │ Tanda vital • waktu pengukuran 09.05                         │
│               │ Suhu [____] °C  Nadi [____] /min  Napas [____] /min          │
│               │ TD [____]/[____] mm[Hg]  SpO₂ [____] %                       │
│               │ ⚠ Nilai/format tidak lengkap: isi unit dan waktu pengukuran  │
│               ├──────────────────────────────────────────────────────────────┤
│               │ Skrining keselamatan • ruleset OPD-SAFE-v1 (belum divalidasi)│
│               │ [Pertanyaan skenario dan jawaban...]                         │
│               │ Keputusan peserta*                                           │
│               │ ( ) Alur rutin  ( ) Perlu tinjauan  ( ) Eskalasi supervisor │
│               ├──────────────────────────────────────────────────────────────┤
│               │ Ringkasan serah terima* [_________________________________] │
├───────────────┴──────────────────────────────────────────────────────────────┤
│ Belum disimpan: 0  •  1 kesalahan harus diperbaiki                          │
│ [Simpan draf]                               [Ajukan untuk ditinjau →]        │
└──────────────────────────────────────────────────────────────────────────────┘
```

Annotations:

- prior-role data is read-only source material, not a disabled form;
- the unvalidated question set carries a visible ruleset/version label in development;
- units remain adjacent and programmatically associated;
- the interface reports data completeness/format, not a diagnosis;
- escalation opens a focused reason/notify flow and moves the encounter to `ESCALATED` after server confirmation.

## 6. Medical assessment

```text
┌──────────────────────────────────────────────────────────────────────────────┐
│ Asesmen Medis Rawat Jalan                    [DRAF]  Tersimpan 09.24         │
│ Asesmen awal: Disetujui simulasi oleh Supervisor Keperawatan • v1 [Buka]    │
├───────────────┬─────────────────────────────────────────────┬────────────────┤
│ Bagian        │ Formulir medis                              │ Sumber ringkas │
│ ● Anamnesis   │ Keluhan utama (bersumber)                   │ Tanda vital    │
│ ○ Pemeriksaan │ [Sinta reports ...] [Lihat sumber]          │ 09.05          │
│ ○ Asesmen     │                                             │ values + units │
│ ○ Pesanan     │ Riwayat penyakit sekarang*                  │                │
│ ○ Resep       │ [_________________________________________] │ Alergi         │
│ ○ Rencana     │                                             │ Belum dikaji ! │
│               │ Pemeriksaan fisik                           │                │
│               │ [structured sections...]                    │ Feedback       │
│               │                                             │ Belum ada      │
│               │ Asesmen/masalah                             │                │
│               │ Diagnosis klinis* [Cari istilah/kode...]    │                │
│               │ Peran [Kerja ▾]  Catatan [____________]    │                │
│               │                                             │                │
│               │ Rencana                                     │                │
│               │ [+ Pesanan] [+ Resep] [Edukasi]             │                │
├───────────────┴─────────────────────────────────────────────┴────────────────┤
│ 2 bagian belum lengkap • Resep tidak dapat diajukan: status alergi belum jelas│
│ [Simpan draf]                             [Ajukan asesmen untuk ditinjau →]  │
└──────────────────────────────────────────────────────────────────────────────┘
```

The right source rail summarizes but links to exact source versions. A configured missing-data rule may block submission; it is labeled as completeness/supervision policy, not automatic clinical judgment.

## 7. Pharmacy prescription review and intervention

```text
┌──────────────────────────────────────────────────────────────────────────────┐
│ Telaah Resep                           RX-SYN-001 • Menunggu telaah           │
│ Prescriber: Mahasiswa Kedokteran • Disetujui supervisor • 09.41             │
├───────────────────────────────────────┬──────────────────────────────────────┤
│ Resep dan sumber                      │ Hasil telaah                          │
│ Pasien / encounter / clinic / date ✓  │ [1 Administratif] [2 Farmasetik] [3 Klinis]│
│ Alergi: Tidak ada alergi dilaporkan    │                                      │
│ Diagnosis sumber: ... [buka v1]        │ Administratif                        │
│                                       │ Pasien         (✓) Lengkap ( ) Temuan│
│ 1. Obat Simulasi A                    │ Prescriber     (✓) Lengkap ( ) Temuan│
│    bentuk/kekuatan                     │ Tanggal/unit   (✓) Lengkap ( ) Temuan│
│    dosis/rute/frekuensi/durasi         │ Catatan [__________________________]  │
│    instruksi penggunaan                │                                      │
│                                       │ Farmasetik [...]                     │
│ [Lihat asesmen] [Lihat riwayat resep] │ Klinis [...]                         │
│                                       │                                      │
│                                       │ Pernyataan: sistem tidak menetapkan  │
│                                       │ kelayakan klinis secara otomatis.    │
│                                       │                                      │
│                                       │ Hasil keseluruhan*                    │
│                                       │ ( ) Terima  ( ) Perlu klarifikasi    │
│                                       │ ( ) Rekomendasi batal                │
├───────────────────────────────────────┴──────────────────────────────────────┤
│ [Simpan draf]     [Buat intervensi]      [Ajukan telaah untuk ditinjau →]   │
└──────────────────────────────────────────────────────────────────────────────┘
```

Intervention drawer:

```text
┌────────────────────────────────────────────┐
│ Intervensi Farmasi • Obat Simulasi A       │
│ Kategori* [Pilih ▾]                        │
│ Pertanyaan/rekomendasi*                    │
│ [________________________________________] │
│ Sumber: RX-SYN-001 v1 • tidak dapat diubah │
│ [Batal]                    [Kirim ke medis]│
├────────────────────────────────────────────┤
│ Riwayat                                    │
│ 09.48 Farmasi: ...                         │
│ 09.52 Medis: ...                           │
└────────────────────────────────────────────┘
```

The intervention retains all messages and affected versions. Dispensing stays blocked until the current request is reviewed/accepted.

## 8. Supervisor review

```text
┌──────────────────────────────────────────────────────────────────────────────┐
│ Tinjauan Supervisor • Asesmen Awal v1                [Diajukan 09.15]        │
│ Penulis: Mahasiswa Keperawatan A • waktu klinis 09.05 • tercatat 09.15      │
├────────────────────────────────────────────────────┬─────────────────────────┤
│ Versi yang diajukan                                │ Daftar tinjauan         │
│ Keluhan utama ...                                  │ ✓ Keluhan               │
│ Alergi ...                                         │ ✓ Alergi/obat           │
│ Tanda vital ...                                    │ ! Tanda vital           │
│ Keputusan skrining ...                             │ ○ Serah terima          │
│                                                    │                         │
│ [Sorot perubahan dari versi sebelumnya]            │ Temuan                   │
│                                                    │ Jenis [Kelengkapan ▾]   │
│                                                    │ Bagian [Tanda vital ▾]  │
│                                                    │ Komentar* [___________] │
│                                                    │ [+ Tambah temuan]       │
├────────────────────────────────────────────────────┴─────────────────────────┤
│ Versi/hash: v1 • abc…123 • versi baru tersedia: tidak                       │
│ [Minta perbaikan]                         [Setujui untuk simulasi]           │
└──────────────────────────────────────────────────────────────────────────────┘
```

Approval requires an exact-version confirmation. If a new version exists, the action is blocked and the current content is refreshed without silently discarding review comments.

## 9. RMIK completeness and coding workbench

```text
┌──────────────────────────────────────────────────────────────────────────────┐
│ Kelengkapan & Koding • ENC-SYN-001             Status: TELAAH REKAM          │
├──────────────────────────┬─────────────────────────────────┬─────────────────┤
│ Susunan rekam            │ Dokumen/sumber terpilih         │ Temuan & koding │
│ ✓ Registrasi             │ Asesmen Medis v2                │ Kelengkapan     │
│ ✓ Asesmen awal v2        │ Penulis/supervisor/time/status  │ [✓ author]      │
│ ✓ Asesmen medis v2       │                                 │ [✓ time]        │
│ ✓ Resep v1               │ Diagnosis klinis sumber         │ [! disposition] │
│ ✓ Telaah farmasi v1      │ “...”                           │                 │
│ ✓ Penyerahan obat        │ Kode bukan sumber klinis        │ [Minta koreksi] │
│ ! Penutupan v1           │                                 │                 │
│                          │ [Buka versi/linimasa]            │ Koding          │
│ Checklist: OPD-COMP-v1   │                                 │ ICD-10 2010     │
│ 6/7 lengkap              │                                 │ [Buat saran]    │
│                          │                                 │ Wajib ditinjau  │
│                          │                                 │ A00.9  EXACT    │
│                          │                                 │ alasan cocok…   │
│                          │                                 │ [Gunakan draf]  │
│                          │                                 │ [Cari kode lain]│
│                          │                                 │ [Tolak saran]   │
│                          │                                 │ Peran [Utama ▾] │
├──────────────────────────┴─────────────────────────────────┴─────────────────┤
│ 1 temuan pemblokir • Finalisasi tidak tersedia                              │
│ [Simpan telaah]                          [Ajukan telaah RMIK →]              │
└──────────────────────────────────────────────────────────────────────────────┘
```

The coder selects a source diagnosis/version before search or suggestion becomes active. A candidate is visibly labelled `Wajib ditinjau koder` and becomes only a draft after explicit acceptance. `Minta koreksi` routes to the author/supervisor and cannot edit the source document.

## 10. Longitudinal timeline and debrief

```text
┌──────────────────────────────────────────────────────────────────────────────┐
│ Linimasa Rekam & Debrief                                                     │
│ Filter [Semua profesi ▾] [Semua jenis ▾] [Tampilkan audit pembelajaran ☑]   │
├──────────┬───────────────────────────────────────────────────────────────────┤
│ 08.52    │ REGISTRASI • Registrasi Learner                                  │
│          │ Identitas diverifikasi; encounter dibuat                         │
│ 09.05    │ KEPERAWATAN • Asesmen Awal v1 • Diajukan 09.15                  │
│ 09.18    │ SUPERVISOR • Meminta perbaikan pada tanda vital                  │
│ 09.22    │ KEPERAWATAN • Asesmen Awal v2 • disetujui simulasi 09.24        │
│ 09.30    │ KEDOKTERAN • Asesmen Medis v1                                   │
│ 09.48    │ FARMASI • Intervensi pada RX-SYN-001 v1                         │
│ 09.52    │ KEDOKTERAN • Resep diganti menjadi v2                           │
│ 10.05    │ FARMASI • Obat diserahkan • konseling dicatat                   │
│ 10.20    │ RMIK • Temuan kelengkapan → diperbaiki                          │
│ 10.35    │ FINALISASI SIMULASI                                              │
├──────────┴───────────────────────────────────────────────────────────────────┤
│ Tujuan pembelajaran • Handoff • Koreksi • Keputusan versi                    │
│ [Buka refleksi fasilitator] [Ekspor laporan simulasi]                        │
└──────────────────────────────────────────────────────────────────────────────┘
```

Clinical occurrence time and recorded time are both shown when materially different. Filters do not alter record order or hide that a correction occurred.

## 11. Blocking and correction states

### Escalated outpatient state

```text
┌──────────────────────────────────────────────────────────────────────────────┐
│ ⚠ ALUR RUTIN DIJEDA — Menunggu keputusan supervisor                         │
│ Dicatat oleh Mahasiswa Keperawatan A pada 09.11                             │
│ Alasan peserta: [authored reason]                                            │
│ Sistem tidak memberikan diagnosis atau rekomendasi tata laksana.             │
│ [Buka detail]                          [Supervisor: catat disposisi]          │
└──────────────────────────────────────────────────────────────────────────────┘
```

### Changes requested state

```text
┌──────────────────────────────────────────────────────────────────────────────┐
│ PERLU PERBAIKAN • Asesmen Awal v1                                            │
│ Supervisor Keperawatan • 09.18                                               │
│ 1. Tanda vital: lengkapi satuan dan waktu pengukuran                         │
│ Versi v1 terkunci dan tetap tersimpan.                                       │
│ [Bandingkan versi]                              [Buat versi perbaikan →]     │
└──────────────────────────────────────────────────────────────────────────────┘
```

### Finalized state

```text
┌──────────────────────────────────────────────────────────────────────────────┐
│ ✓ Rekam simulasi difinalisasi • 10.35                                        │
│ Perubahan biasa dinonaktifkan. Koreksi hanya melalui amendemen terkontrol.    │
│ [Lihat linimasa] [Buka debrief] [Ajukan amendemen*]                          │
└──────────────────────────────────────────────────────────────────────────────┘
```

## 12. Responsive and print rules

- patient banner becomes two rows but retains name + second identifier, encounter, allergies, status, role, and simulation mode;
- left section navigation becomes a horizontal/overflowing step control or drawer; current section remains announced;
- three-pane pharmacy/RMIK layouts become source → work → findings tabs with persistent context, never three unrelated pages;
- sticky footers do not obscure inputs at 200% zoom and can fall back to normal document flow;
- tables retain essential columns and expose a meaningful stacked row pattern below tablet width;
- print/export removes navigation but retains logo/product name, simulation watermark, patient/encounter cues, source versions, page numbers, and generated time;
- no print/export presents an internal simulation approval as a legal signature.

## 13. Wireframe acceptance checks

- each screen names the user role, session, patient, encounter, and current record state when applicable;
- every forward action shows the resulting handoff/state;
- every long form protects unsaved work and exposes a deterministic submit/review step;
- prior-profession information is visually read-only with source provenance;
- correction, escalation, clarification, partial dispense, and finalization have defined layouts;
- color is never the only status signal;
- workflow can be completed by keyboard at desktop/tablet width;
- mobile adaptations retain safety/context rather than hiding it.

## Related documents

- [UEU Clinical Design System](UEU_CLINICAL_DESIGN_SYSTEM.md)
- [Information Architecture](INFORMATION_ARCHITECTURE.md)
- [Interaction Specifications](OUTPATIENT_INTERACTION_SPECIFICATIONS.md)
- [Outpatient Acceptance Scenarios](../product/OUTPATIENT_ACCEPTANCE_SCENARIOS.md)
