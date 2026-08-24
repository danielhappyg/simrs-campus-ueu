# Design handoff — Dokumentasi RJ terstruktur + kelengkapan RM

- Status: **Implementasi engineering v1 terbatas diotorisasi; acceptance Clinical/RMIK masih pending**
- Tanggal: 2026-08-24
- Slice: PAR-CLN-004 + PAR-RMIK-001
- Pemilik keputusan: Clinical owner (belum dinamai) + RMIK Department
- Dasar UI: implementasi clean-slate saat ini, `UI_DIRECTION.md`, DEC-013, DEC-014, DEC-015, dan bukti struktural PAR-CLN-004/PAR-RMIK-001
- Keputusan implementasi: [`STRUCTURED_RJ_DOCUMENTATION_RM_COMPLETENESS_V1_IMPLEMENTATION_DECISION.md`](STRUCTURED_RJ_DOCUMENTATION_RM_COMPLETENESS_V1_IMPLEMENTATION_DECISION.md)

## 1. Batas bukti dan tujuan

Dokumen ini adalah handoff desain yang dapat diimplementasikan **setelah** pemilik klinis dan RMIK menetapkan isi formulir, aturan wajib, serta syarat sign-off. Wireframe menunjukkan posisi, komponen, state, dan interaksi. Ia tidak membuktikan bahwa kolom, checklist, atau workflow yang diusulkan identik dengan SIMRS Sahabat.

Semua baris bertanda **`[KEPUTUSAN PEMILIK]`** adalah kandidat untuk dibahas, bukan fakta klinis, aturan RMIK, atau parity yang telah disetujui. Implementasi tidak boleh mengubah kandidat tersebut menjadi validasi wajib sebelum keputusan tertulis dicatat.

Tujuan slice ini:

- mengganti satu textarea asesmen dengan dokumentasi rawat jalan yang lebih terstruktur tanpa menghilangkan konteks kunjungan;
- memberi perawat dan dokter ruang kerja berbeda di halaman encounter yang sama;
- memberi RMIK tampilan keterbacaan dan checklist kelengkapan yang dapat ditelusuri;
- mempertahankan status, author, waktu, dan audit yang jujur;
- menjaga alur existing registration → examination → lab lifecycle → RM closure.

## 2. Guardrails dan ruang lingkup

### Wajib dipertahankan

- `APP_MODE=SIMULATION` dan backend synthetic-only tetap menjadi enforcement boundary.
- Shell selalu menampilkan **`SIMULASI — DATA SINTETIS`**, non-dismissible, pada semua role dan viewport.
- Gunakan layout encounter existing (`max-w-[1400px]`, ringkasan pasien, tab klinis, dua panel) dan pola filter/list RM existing.
- Authorization selalu diperiksa server-side; disabled button hanya petunjuk UI.
- Semua penyimpanan harus terikat pada patient + encounter yang sedang dibuka dan mencatat actor/waktu.
- Active lab order tetap memblokir penutupan menurut DEC-016 yang masih Proposed; jangan mengubah kontrak itu melalui desain ini.

### Di luar scope

- ICD/diagnosis coding dan suggestion engine;
- resep/obat, farmasi, charge/kasir, klaim;
- Order Rad/radiologi;
- amendment/correction, pembukaan kembali encounter, dan penghapusan versi;
- live BPJS/VClaim/SATUSEHAT/LIS/PACS atau integrasi klinis lain;
- pemulihan Antrean/work-queue MVP DEC-013.

`SOAP`, `Diagnosa`, `Tindakan`, `Resep`, dan `Order Rad` tetap honest disabled stubs bila belum dibangun. Slice ini tidak boleh menyalakan tab dengan data palsu.

## 3. Anchor yang dipakai dari produk sekarang

| Anchor existing | Penggunaan pada slice ini |
|---|---|
| Header encounter | Pertahankan nama pasien, status encounter, nomor antrean, MRN/NIK, demografi ringkas, klinik/dokter, jadwal/penjamin, keluhan utama. |
| Tab bar klinis | Pertahankan posisi dan pola active/disabled. `Asesmen` dan `Riwayat` tetap live; `Order Lab` tetap mengikuti implementasi existing. |
| Dua panel pada desktop | Kiri untuk ringkasan/riwayat terstruktur; kanan untuk editor role yang sedang aktif. |
| Kartu entry existing | Ubah body tunggal menjadi read-only summary terstruktur dengan author, waktu, role, dan status yang terlihat. |
| Filter + tabel RM existing | Tambahkan indikator kelengkapan dan tindakan `Tinjau RM`, bukan dashboard tugas personal. |
| `Button`, `Input`, `Label`, `InputError`, Inertia `useForm` | Reuse. Tambahkan primitive hanya bila tidak ada padanan accessible untuk textarea, radio group, checkbox, dialog, dan status badge. |

Kata “worklist” pada breadcrumb existing berarti daftar operasional modul. Jangan mengubahnya menjadi antrean tugas personal, skor peserta, atau chrome MVP lama.

## 4. Model informasi layar

```text
Shared authenticated shell
└── SIMULASI — DATA SINTETIS (selalu terlihat)
    ├── Pemeriksaan / Rawat Jalan / Encounter
    │   ├── Ringkasan pasien + kunjungan (read-only)
    │   ├── Tab klinis
    │   └── Asesmen
    │       ├── Riwayat entry terstruktur (read-only)
    │       └── Editor sesuai capability (perawat atau dokter)
    └── RM / Rawat Jalan
        ├── Filter + daftar operasional
        └── Tinjau RM
            ├── Ringkasan encounter dan sumber klinis (read-only)
            ├── Checklist kelengkapan [KEPUTUSAN PEMILIK]
            └── Sign-off / close [KEPUTUSAN PEMILIK]
```

Perawat dan dokter menggunakan route encounter yang sama dengan proyeksi/capability berbeda. RMIK menggunakan route review tersendiri, tetapi membaca sumber klinis yang sama; tidak ada salinan bebas yang dapat drift.

## 5. Wireframe — perawat

```text
┌ SIMULASI — DATA SINTETIS ──────────────────────────────────────────────┐
│ ← Kembali ke daftar pemeriksaan rawat jalan                            │
├─────────────────────────────────────────────────────────────────────────┤
│ NAMA PASIEN      [Dalam pemeriksaan] [Antrian 001]                      │
│ RM-... · NIK ...                                                         │
│ Tgl lahir/JK | Klinik/Dokter | Jadwal/Penjamin | Keluhan utama          │
├ Asesmen ─ SOAP(stub) ─ Diagnosa(stub) ─ ... ─ Order Lab ─ Riwayat ─────┤
│                                                                         │
│ ┌ RIWAYAT ASESMEN (read-only) ─────┐ ┌ ASESMEN KEPERAWATAN ─────────┐ │
│ │ [Keperawatan] [Draf/Final?]*      │ │ Kesadaran*                    │ │
│ │ Perawat Sinta · 24 Agu 09.10      │ │ Tanda vital*                  │ │
│ │ Ringkasan field terisi            │ │ Keluhan/riwayat singkat*      │ │
│ │ [Lihat detail]                    │ │ Risiko/alergi/catatan*        │ │
│ └───────────────────────────────────┘ │                              │ │
│                                       │ [Simpan draf]* [Finalkan]*    │ │
│                                       └──────────────────────────────┘ │
└─────────────────────────────────────────────────────────────────────────┘
* [KEPUTUSAN PEMILIK]
```

### Perilaku role

- Perawat hanya melihat editor jenis `NURSING_INTAKE`; pilihan jenis entry tidak ditampilkan bila hanya satu jenis yang diizinkan.
- Sumber medis dapat dibaca bila capability mengizinkan, tetapi tidak dapat diedit melalui form keperawatan.
- Setelah submit sukses, fokus berpindah ke pesan sukses dan entry terbaru tampil di urutan pertama.
- Encounter `CLOSED`: semua sumber tetap read-only; editor diganti panel informasi “Kunjungan sudah ditutup — dokumentasi tidak dapat ditambah.”

## 6. Wireframe — dokter

```text
┌ SIMULASI — DATA SINTETIS ──────────────────────────────────────────────┐
│ ← Kembali ke daftar pemeriksaan rawat jalan                            │
├─────────────────────────────────────────────────────────────────────────┤
│ NAMA PASIEN       [Dalam pemeriksaan/Siap RM] [Antrian 001]             │
│ RM-... · NIK ...                                                         │
│ Tgl lahir/JK | Klinik/Dokter | Jadwal/Penjamin | Keluhan utama          │
├ Asesmen ─ SOAP(stub) ─ Diagnosa(stub) ─ ... ─ Order Lab ─ Riwayat ─────┤
│                                                                         │
│ ┌ SUMBER KLINIS (read-only) ────────┐ ┌ ASESMEN MEDIS ──────────────┐ │
│ │ Asesmen keperawatan terbaru       │ │ Anamnesis*                  │ │
│ │ author · waktu · status*          │ │ Pemeriksaan fisik*          │ │
│ │ [Lihat detail]                    │ │ Asesmen klinis*             │ │
│ │                                   │ │ Rencana*                     │ │
│ │ Asesmen medis sebelumnya          │ │ Catatan tambahan*            │ │
│ │ author · waktu · status*          │ │                              │ │
│ └───────────────────────────────────┘ │ [Simpan draf]* [Finalkan]*    │ │
│                                       └──────────────────────────────┘ │
└─────────────────────────────────────────────────────────────────────────┘
* [KEPUTUSAN PEMILIK]
```

### Perilaku role

- Dokter hanya melihat editor `MEDICAL_ASSESSMENT` dan tidak dapat mengubah sumber keperawatan.
- Tombol menuju `Order Lab` tetap mengikuti capability dan lifecycle existing.
- Menyelesaikan dokumentasi tidak boleh otomatis melakukan coding, resep, charge, klaim, atau close RM.
- Jika pemilik memilih model draf/final, perubahan status harus eksplisit dan attributable; tidak ada silent overwrite. Bentuk versioning/finalization tetap **`[KEPUTUSAN PEMILIK]`**.

## 7. Wireframe — RMIK

### 7.1 Daftar operasional RM

```text
┌ SIMULASI — DATA SINTETIS ──────────────────────────────────────────────┐
│ RM · Rawat Jalan                                                        │
│ [No.RM/Nama] [Klinik] [Cara bayar] [Dari] [Sampai] [Tampilkan]         │
├────┬──────────┬────────────┬──────────┬─────────┬────────────┬──────────┤
│ No │ No. RM   │ Nama       │ Klinik   │ Dokter  │ Kelengkapan*│ Aksi    │
├────┼──────────┼────────────┼──────────┼─────────┼────────────┼──────────┤
│001 │ RM-...   │ SIM ...    │ Poli ... │ dr. ... │ Perlu tinjau│[Tinjau]│
└────┴──────────┴────────────┴──────────┴─────────┴────────────┴──────────┘
* Label/status kelengkapan [KEPUTUSAN PEMILIK]
```

Daftar tetap filter → tabel → detail. Jangan menambahkan kartu “tugas saya”, gamification, prioritas otomatis, atau queue state dari MVP lama.

### 7.2 Detail tinjau RM

```text
┌ SIMULASI — DATA SINTETIS ──────────────────────────────────────────────┐
│ ← Kembali ke RM Rawat Jalan                                             │
├─────────────────────────────────────────────────────────────────────────┤
│ NAMA PASIEN     [Siap RM]   RM-... · Klinik · Dokter · Tgl kunjungan    │
│ [Peringatan: 1 order lab aktif — RM belum dapat ditutup] (bila ada)     │
├───────────────────────────────────┬─────────────────────────────────────┤
│ DOKUMEN SUMBER (read-only)        │ CHECKLIST KELENGKAPAN*              │
│ ▾ Asesmen keperawatan             │ [ ] Identitas/konteks kunjungan*   │
│   author · waktu · status*         │ [ ] Dokumentasi keperawatan*       │
│   ringkasan nilai                  │ [ ] Dokumentasi medis*             │
│ ▾ Asesmen medis                   │ [ ] Hasil penunjang relevan*       │
│   author · waktu · status*         │ [ ] Autentikasi/atribusi*          │
│ ▾ Order/hasil lab existing        │ [ ] Item tambahan hasil keputusan* │
│                                   │                                     │
│                                   │ Catatan temuan* [______________]    │
│                                   │ [Simpan tinjauan]* [Selesai RM]*    │
└───────────────────────────────────┴─────────────────────────────────────┘
* [KEPUTUSAN PEMILIK]
```

RMIK hanya merekam hasil tinjau/checklist; RMIK tidak mengedit isi asesmen klinis. Jika ada kekurangan, desain hanya dapat menampilkan temuan dan status yang diputuskan pemilik. Correction/task-to-author dan reopen berada di luar scope.

## 8. Kandidat field klinis — belum disetujui

Semua field di bawah adalah **`[KEPUTUSAN PEMILIK]`**. Clinical owner harus menetapkan label, tipe, opsi, wajib/opsional, batas karakter, unit, rentang, dan siapa yang boleh finalisasi. Sampai itu terjadi, wireframe ini hanya boleh dipakai untuk review keputusan; engineering tidak boleh menambahkan persistensi, validasi klinis, atau schema field kandidat.

### 8.1 Asesmen keperawatan

| ID desain | Label Indonesia kandidat | Kontrol kandidat | Keputusan yang wajib dicatat |
|---|---|---|---|
| `nursing.consciousness` | Kesadaran | Select/radio | Opsi resmi; required atau tidak. |
| `nursing.systolic_bp` | Tekanan darah sistolik | Numeric + unit read-only | Unit, rentang, integer/decimal, required. |
| `nursing.diastolic_bp` | Tekanan darah diastolik | Numeric + unit read-only | Unit, rentang, relasi validasi sistolik. |
| `nursing.pulse` | Nadi | Numeric + unit read-only | Unit dan rentang. |
| `nursing.respiratory_rate` | Frekuensi napas | Numeric + unit read-only | Unit dan rentang. |
| `nursing.temperature` | Suhu | Decimal + unit read-only | Unit, precision, rentang. |
| `nursing.oxygen_saturation` | Saturasi oksigen | Numeric + unit read-only | Unit, rentang, konteks alat/oksigen. |
| `nursing.weight` | Berat badan | Decimal + unit read-only | Unit, precision, rentang. |
| `nursing.height` | Tinggi badan | Decimal + unit read-only | Unit, precision, rentang. |
| `nursing.complaint_history` | Keluhan dan riwayat singkat | Textarea | Required, batas karakter, relasi ke keluhan utama registrasi. |
| `nursing.allergy_statement` | Informasi alergi | Radio/select + conditional text | Opsi “tidak diketahui/tidak ada/ada”, wording, required. |
| `nursing.risk_note` | Risiko/catatan keperawatan | Textarea | Kategori risiko, required, escalation behavior. |

### 8.2 Asesmen medis

| ID desain | Label Indonesia kandidat | Kontrol kandidat | Keputusan yang wajib dicatat |
|---|---|---|---|
| `medical.anamnesis` | Anamnesis | Textarea | Struktur, required, batas karakter. |
| `medical.physical_exam` | Pemeriksaan fisik | Textarea/structured groups | Organ/system sections dan requiredness. |
| `medical.clinical_assessment` | Asesmen klinis | Textarea | Relasi dengan diagnosis/coding yang masih out of scope. |
| `medical.plan` | Rencana | Textarea | Requiredness; batas agar tidak menjadi order/resep palsu. |
| `medical.additional_note` | Catatan tambahan | Textarea | Optional/required dan batas karakter. |

### 8.3 Aturan presentasi field

- Label selalu terlihat; placeholder bukan pengganti label.
- Unit ditampilkan sebagai suffix non-editable dan dibaca screen reader, bukan dimasukkan pengguna ke numeric input.
- Required menggunakan teks “Wajib” atau `(wajib)` dan `aria-required`; jangan mengandalkan warna/asterisk saja.
- Nilai yang tidak tersedia ditampilkan `—`; jangan mengubah blank menjadi nilai normal/default klinis.
- Tidak ada default klinis yang tampak nyata. Semua contoh/seed wajib eksplisit sintetis.
- Ringkasan read-only menampilkan label + nilai, bukan JSON blob atau gabungan body tanpa struktur.

## 9. Kandidat checklist RMIK — belum disetujui

Semua item berikut adalah **`[KEPUTUSAN PEMILIK]`** dan harus diputuskan oleh pemilik yang ditetapkan dalam FR pack: RMIK untuk proses tinjau, serta Clinical + RMIK bersama-sama untuk item yang menilai dokumentasi klinis atau memblokir penutupan. Item tidak boleh digunakan untuk auto-close atau memblokir encounter selain blocker active-lab existing yang masih berstatus Proposed dalam DEC-016 sebelum keputusan pemilik dicatat.

| ID desain | Label Indonesia kandidat | Sumber penilaian kandidat | Keputusan yang wajib dicatat |
|---|---|---|---|
| `rm.identification_context` | Identitas dan konteks kunjungan tersedia | Encounter/registration projection | Elemen identitas minimum; auto-evaluate atau manual. |
| `rm.nursing_documentation` | Dokumentasi keperawatan tersedia | Entry keperawatan | Status yang diterima; required per jenis kunjungan atau tidak. |
| `rm.medical_documentation` | Dokumentasi medis tersedia | Entry medis | Status yang diterima; siapa boleh menandatangani. |
| `rm.supporting_results` | Hasil penunjang relevan terselesaikan | Lab lifecycle existing | Arti “relevan”; handling encounter tanpa order. |
| `rm.attribution` | Author dan waktu dokumentasi dapat ditelusuri | Audit/source metadata | Minimum attribution dan exception. |
| `rm.additional_owner_item` | Item tambahan hasil keputusan RMIK | TBD | Label, sumber, rule, dan urutan. |
| `rm.finding_note` | Catatan temuan | Textarea | Required saat incomplete, batas karakter, follow-up yang diizinkan. |

Pilihan state kandidat seperti `Belum ditinjau`, `Lengkap`, atau `Belum lengkap` juga **`[KEPUTUSAN PEMILIK]`**. Jangan menggunakan persentase kelengkapan: bobot item belum disepakati dan angka dapat memberi kesan validitas palsu.

### 9.1 Prompt discovery dari manual vendor 2018

Manual historis vendor pada `docs/vendor-simrs-assessment-2026-08-21/SIMRS_Manual_vendor.txt` menyebut “Kelengkapan Berkas” dengan item **Anamnesa, Diagnosa, Nama dokter, dan Ttd dokter**. Bukti ini hanya menjelaskan dokumentasi manual lama; ia tidak membuktikan aturan backend, requiredness, wording saat ini, atau acceptance SIMRS Sahabat.

| Prompt dari manual 2018 | Perlakuan dalam desain ini |
|---|---|
| Anamnesa | **Usulan berbasis manual 2018 — menunggu konfirmasi.** Dapat dipetakan ke `medical.anamnesis` hanya setelah Clinical owner dan RMIK menyetujui sumber/status yang dianggap lengkap. |
| Diagnosa | **Usulan berbasis manual 2018 — menunggu konfirmasi.** Diagnosis/ICD tetap di luar scope; tampilkan sebagai gap owner-decision, bukan checkbox aktif. |
| Nama dokter | **Usulan berbasis manual 2018 — menunggu konfirmasi.** Candidate check terhadap attribution/provider source, bukan field bebas yang diketik ulang oleh RMIK. |
| Ttd dokter | **Usulan berbasis manual 2018 — menunggu konfirmasi.** Arti tanda tangan elektronik, actor, dan final state belum disetujui; jangan menyimulasikan gambar tanda tangan atau checkbox manual sebagai pengganti autentikasi. |

Manual yang sama juga memperlihatkan permukaan Dokter, Kelanjutan, Kasus, Kecelakaan, ICD-10, dan ICD-9. Untuk slice ini, Dokter hanya dibaca dari encounter existing. Kelanjutan/Kasus/Kecelakaan tidak ditambahkan tanpa keputusan scope dan bukti yang sesuai care setting; ICD-10/ICD-9 secara eksplisit tetap di luar scope. Tidak satu pun menjadi active control dari bukti manual tersebut saja.

## 10. Layout dan responsive behavior

Gunakan breakpoint Tailwind existing, bukan breakpoint baru tanpa kebutuhan.

| Viewport | Layout |
|---|---|
| Desktop `lg` dan lebih besar | Container `max-w-[1400px]`; header ringkasan hingga 4 kolom; content dua panel `minmax(0,1.1fr) / minmax(0,0.9fr)`. Editor kanan tetap natural-height, tidak sticky agar shell/banner tidak tertutup. |
| Tablet `md`–`lg` | Header 2 kolom; panel ditumpuk: sumber/riwayat dahulu, editor/checklist sesudahnya. Tab dapat horizontal scroll tanpa memotong label. |
| Mobile `<md` | Padding shell `px-3 py-4`; header 1 kolom; action menjadi full-width bila perlu; setiap kontrol minimal 44 px sesuai aturan coarse pointer existing. Tabel RM tetap horizontal scroll dengan kolom nama/MRN/aksi tidak dipaksa mengecil. Detail RM menjadi satu kolom. |

Untuk form tanda vital desktop, gunakan grid 2–3 kolom bila field telah disetujui; pada mobile satu kolom. Textarea selalu full-width. Jangan mengecilkan teks input di bawah `text-sm`.

## 11. Token dan spesifikasi visual

Gunakan token CSS existing; hex di bawah hanya dokumentasi nilai saat ini, bukan alasan membuat token duplikat.

| Token/utility | Nilai saat ini | Penggunaan |
|---|---:|---|
| `background` | `#f1f5f9` | Latar aplikasi. |
| `card` | `#ffffff` | Header, panel sumber, editor, checklist. |
| `foreground` | `#0f172a` | Teks utama. |
| `muted-foreground` | `#64748b` | Metadata, helper, empty state. |
| `primary` / `ring` | `#1b75bc` | CTA, link, active tab, focus. |
| `secondary` | `#e8f1f8` | Badge/status informatif. |
| `success` | `#059669` | Sukses yang juga memiliki label teks. |
| `warning` | `#d97706` | Warning incomplete/blocker yang juga memiliki label teks. |
| `destructive` | `#dc2626` | Validation/server error; bukan indikator satu-satunya. |
| `border` | `#e2e8f0` | Card/table/divider. |
| `radius-lg/md` | berdasar `--radius: 0.75rem` | Card dan kontrol existing. |
| `font-sans/display` | Plus Jakarta Sans | UI dan heading. |
| `font-mono` | IBM Plex Mono | MRN, NIK, queue, ID teknis yang memang perlu terlihat. |

Spacing mengikuti utility existing: `gap-1/1.5/2/3`, card `p-3 md:p-4`, page `px-3 py-4 md:px-5 md:py-5`. Jangan membuat shadow berat; gunakan border existing atau `clinical-shadow` hanya bila hierarchy memerlukannya.

## 12. Komponen dan props minimum

Nama berikut adalah kontrak desain, bukan kewajiban nama file.

| Komponen | Props minimum | State/notes |
|---|---|---|
| `EncounterSummaryHeader` | `encounter`, `patient`, `statusLabel`, `queueNumber` | Reuse current projection; read-only; no fetch kedua. |
| `ClinicalTabList` | `activeTab`, `availableTabs`, `onChange` | Native button semantics atau ARIA tabs lengkap; stubs disabled + title/helper. |
| `StructuredEntryCard` | `entryType`, `fieldValues`, `author`, `recordedAt`, `documentState?` | State label hanya setelah owner decision; detail expand/collapse accessible. |
| `NursingAssessmentForm` | `initialValues`, `fieldErrors`, `processing`, `capabilities` | Render field hanya dari approved schema; no clinical defaults. |
| `MedicalAssessmentForm` | sama | Tidak menampilkan nursing write controls. |
| `RmCompletenessChecklist` | `items`, `findingNote`, `processing`, `canSignoff` | Item manual/auto harus dibedakan visual dan untuk screen reader. |
| `LifecycleBlockerAlert` | `reason`, `activeLabOrderCount?` | Existing `active_lab_orders` copy; focusable alert for submit response. |
| `StatusBadge` | `label`, `tone` | Selalu teks + tone; jangan color-only. |
| `FormActions` | `canSaveDraft?`, `canFinalize?`, `processing`, `dirty` | Kedua action hanya ada jika workflow disetujui. |

## 13. State dan interaksi

| Elemen | State | Perilaku/copy Indonesia |
|---|---|---|
| Editor | pristine | Primary action disabled bila tidak ada perubahan yang valid. |
| Editor | dirty | Action aktif; navigasi keluar mengikuti dirty-form guard existing bila tersedia. Jangan invent auto-save. |
| Editor | saving | Tombol disabled, spinner + “Menyimpan…”, submit ganda dicegah. |
| Editor | success | Inline `role=status`/toast “Asesmen berhasil disimpan.”; entry terbaru tampil tanpa reload penuh. |
| Field | validation error | Border destructive, `aria-invalid`, pesan spesifik di bawah field melalui `InputError`; fokus menuju error pertama setelah submit. |
| Form | stale/concurrent | Fail closed; tampil “Data telah berubah. Muat ulang sebelum menyimpan.” Tidak melakukan merge diam-diam. Kontrak teknis concurrency masih perlu diputuskan. |
| Encounter | closed | Semua editor diganti read-only notice; POST tetap ditolak server-side. |
| RMIK checklist | incomplete | Copy/status dan sign-off behavior **`[KEPUTUSAN PEMILIK]`**; jangan auto-close. |
| RMIK close | active lab order | Tombol `Selesai RM` disabled; helper “Belum dapat ditutup: masih ada {n} order laboratorium aktif.” Server tetap authoritative. |
| RMIK close | eligible | Confirmation dialog yang menyebut patient display name/MRN dan konsekuensi closed; action tetap capability-gated. Checklist gating **`[KEPUTUSAN PEMILIK]`**. |
| Unauthorized | any protected action | 403/shared unauthorized UI; jangan membocorkan state klinis atau checklist. |

Tidak ada animasi dekoratif. Hover/focus memakai transition existing pada color/box-shadow (sekitar perilaku primitive `Button`); honor `prefers-reduced-motion` bila spinner/transisi baru ditambah.

## 14. Loading, empty, dan error behavior

### Loading

- Navigasi Inertia mempertahankan struktur page; disable action yang sedang dikirim.
- Jika skeleton diperlukan, gunakan blok muted yang mengikuti tinggi header/card; jangan menampilkan nilai pasien lama sebagai nilai baru.
- Screen reader mendapat live message “Memuat dokumentasi…” satu kali, bukan per field.

### Empty

- Belum ada entry: “Belum ada asesmen keperawatan/medis untuk kunjungan ini.”
- Belum ada item checklist approved: “Checklist kelengkapan belum ditetapkan pemilik RMIK.” dan tidak menampilkan tombol sign-off.
- Tidak ada encounter pada filter RM: pertahankan “Tidak ada kunjungan siap RM untuk filter ini.”
- Nilai optional kosong: `—`; jangan tampil “normal”, `0`, atau checked.

### Error

- 422: inline field errors + summary di atas form bila lebih dari satu field gagal.
- 403: shared unauthorized page, tanpa detail record.
- 409/stale: blocking alert dan reload action; input lokal tidak dibuang sampai pengguna memilih reload.
- 5xx/network: “Data belum tersimpan. Periksa koneksi lalu coba lagi.”; tidak mengklaim sukses dan tidak clear form.
- Missing/invalid encounter: shared 404, tanpa fallback ke encounter pertama.

## 15. Content rules

- Semua UI label Indonesia; identifier/code internal tidak menjadi label utama.
- Nama/MRN tidak ditruncate pada detail. Pada tabel, nama boleh wrap dua baris; MRN tetap utuh. Tooltip bukan satu-satunya akses ke isi.
- Batas karakter belum boleh di-hard-code sebagai aturan klinis sebelum owner memutuskan. UI harus menampilkan counter bila batas kemudian disetujui.
- Timestamp menggunakan locale `id-ID` dan menampilkan timezone yang konsisten; stored timestamp tetap server-authoritative.
- Jangan menggunakan “terverifikasi”, “lengkap”, “final”, atau “disetujui” kecuali backend state dan keputusan pemilik mendukung label itu.

## 16. Accessibility contract

- Urutan fokus: breadcrumb → header actions → tabs → source/history → editor fields → form actions; RMIK: filters → table → detail source → checklist → sign-off.
- Setiap input memiliki `<label for>` unik; grouping tanda vital/checklist memakai `fieldset` + `legend` bila sesuai.
- Tab keyboard: panah kiri/kanan bila memakai ARIA `tablist`; jika tetap button navigation biasa, gunakan semantics button konsisten dan jangan memberi role tab parsial.
- Expand/collapse entry menggunakan `<button aria-expanded aria-controls>`; tidak memakai card clickable tanpa semantics.
- Error summary menghubungkan ke field; success/error menggunakan `role=status` atau `role=alert` secara tepat.
- Warna tidak menjadi satu-satunya pembeda status. Badge dan blocker selalu memiliki teks/icon berlabel.
- Target minimum 44 × 44 px pada mobile/coarse pointer mengikuti aturan CSS existing.
- Kontras harus memenuhi WCAG AA terhadap token background yang dipakai; periksa lagi jika tone badge baru dibuat.
- Confirmation dialog mengunci fokus, memiliki judul, deskripsi konsekuensi, `Batal` sebagai safe default, dan mengembalikan fokus ke trigger.

## 17. Capability dan audit handoff

| Actor | Read | Write | Tidak boleh |
|---|---|---|---|
| Perawat | Encounter + sumber yang diizinkan | Asesmen keperawatan sesuai capability | Mengubah entry medis; RM sign-off. |
| Dokter | Encounter + sumber yang diizinkan | Asesmen medis dan order lab existing sesuai capability | Mengubah entry keperawatan; RM sign-off. |
| RMIK | Encounter + sumber klinis read-only | Checklist/temuan/sign-off sesuai capability dan keputusan owner | Mengedit fakta klinis. |
| Mahasiswa | **`[KEPUTUSAN PEMILIK]`** | Draf saja atau submit-for-review **`[KEPUTUSAN PEMILIK]`** | Tidak boleh dianggap final tanpa policy yang disetujui. |
| Supervisor | **`[KEPUTUSAN PEMILIK]`** | Review/approve/reject **`[KEPUTUSAN PEMILIK]`** | Tidak boleh memakai UI untuk silent overwrite. |

Audit minimum untuk write yang akhirnya disetujui harus mencatat actor, capability/role context, patient/encounter resource, action, outcome, timestamp, dan stable denial reason. Nama action untuk draft/final/checklist/sign-off harus disepakati bersama kontrak backend; dokumen desain ini tidak menetapkannya.

## 18. Keputusan yang harus tersedia sebelum coding persistensi

Clinical owner harus menjawab:

1. Field keperawatan dan medis mana yang dipakai, tipe/opsi/unit/rentang, serta wajib/opsional.
2. Apakah ada draf/final, siapa yang boleh finalisasi, dan bagaimana learner/supervisor bekerja.
3. Apakah lebih dari satu entry per jenis diizinkan sebelum close, dan bagaimana entry aktif dipilih.
4. Label state yang aman dan arti status klinis terhadap `IN_EXAMINATION`/`READY_FOR_RM`.

RMIK Department harus menjawab:

1. Item checklist final dan sumber tiap item (otomatis/manual).
2. Apakah incomplete memblokir sign-off, perlu alasan, atau hanya menghasilkan status review.
3. Siapa yang boleh menyimpan review dan siapa yang boleh melakukan `Selesai RM`.
4. Apakah draf/final klinis tertentu menjadi syarat RM, termasuk encounter tanpa order lab.

Keduanya harus meninjau DEC-016. Bila aturan active order/late result berubah, buat keputusan pengganti dan revisi contract, code, serta UAT bersama-sama.

## 19. Urutan implementasi setelah keputusan owner

1. Kunci decision record dan field/checklist schema version.
2. Tambahkan persistence + authorization + audit dengan migration PostgreSQL schema `laravel`; jangan memakai schema-qualified string pada `Rule::exists`.
3. Tambahkan projection read-only agar source klinis yang sama dipakai clinician dan RMIK.
4. Implement form role-specific di encounter page, lalu detail review RM; pertahankan operational RM list existing.
5. Uji unit/feature untuk validation, wrong role, closed encounter, stale submit, active lab blocker, attribution, dan audit reason.
6. Uji PostgreSQL CI serta local supported database.
7. Jalankan continuous synthetic UAT registration → nursing → physician → lab (bila dipakai) → RMIK completeness → close.

## 20. Visual/accessibility acceptance checklist

- [ ] Simulation banner terlihat pada nurse, physician, RMIK, mobile, dan dialog.
- [ ] Encounter header dan patient identity tidak berubah saat berganti tab.
- [ ] Nurse tidak melihat write controls medis; physician tidak melihat write controls keperawatan; RMIK sources read-only.
- [ ] Semua field/checklist yang diimplementasikan terhubung ke keputusan owner dan schema version.
- [ ] Tidak ada clinical default palsu, nilai blank-as-zero, atau klaim SAHABAT parity.
- [ ] Disabled stubs tetap jujur; ICD, resep, charge, radiologi, amendment/reopen tidak menyelinap ke slice.
- [ ] Loading/empty/422/403/stale/network/closed states dirender dan dapat dinavigasi keyboard.
- [ ] Mobile target ≥44 px, tabs dapat diakses, table scroll tidak menyembunyikan aksi.
- [ ] Active lab order blocker dan closed-encounter server guard tetap lolos.
- [ ] Screenshot QA desktop/tablet/mobile serta keyboard/screen-reader smoke test dicatat sebelum merge.

## 21. Hasil yang boleh diklaim

Setelah owner decision, implementation, tests, dan UAT lulus, klaim yang aman adalah: **“structured outpatient documentation and RM completeness teaching slice available for synthetic UEU simulation.”**

Klaim yang belum boleh dibuat: full SIMRS Sahabat parity, clinical production readiness, real-patient suitability, approved hospital medical-record policy, atau live integration readiness.
