# Usulan keputusan G0 Batch C — core care dan RMIK

**Status:** USULAN SAJA — BELUM DISETUJUI PEMILIK OTORITAS
**Dampak gate:** tidak ada; G0 tetap terbuka
**Batas data:** hanya data sintetis; integrasi eksternal dinonaktifkan
**Cakupan:** tepat 19 ID Batch C dalam [`G0_PARITY_BATCH_MANIFEST.json`](G0_PARITY_BATCH_MANIFEST.json)

Dokumen ini membantu rapat keputusan tanpa mengubah kandidat matriks menjadi keputusan. Semua bukti masih kelas `P`, semua keputusan dan appointment masih `pending`, dan tidak ada persetujuan yang dicatat dalam [`G0_BATCH_C_DECISION_REGISTER_2026-08-25.json`](G0_BATCH_C_DECISION_REGISTER_2026-08-25.json).

## Kelompok keputusan yang perlu diselesaikan

| Kelompok | PAR ID | Kandidat/kondisi matriks saat ini | Keputusan otoritatif yang masih diperlukan |
|---|---|---|---|
| Master tata kelola klinis | PAR-ADM-004, PAR-ADM-026, PAR-ADM-027, PAR-ADM-028 | Pending evidence | Tetapkan versioning, peran, validasi, finalisasi/amendment, dan apakah DOC EMR 2 berdiri sendiri atau dikonsolidasikan. |
| Master rawat inap dan profesi | PAR-ADM-021, PAR-ADM-034, PAR-ADM-036, PAR-ADM-046 | Pending evidence | Tetapkan kelengkapan RI, terminologi keperawatan, aturan odontogram, serta status dan jejak penggunaan alat medis. |
| Core clinical | PAR-CLN-001, PAR-CLN-002, PAR-CLN-003, PAR-CLN-004, PAR-CLN-005, PAR-CLN-019 | Kandidat consolidate/reproduce pada matriks | Setujui field, peran, state, skala triage, lifecycle dokumen, koreksi/amendment, dampak downstream, dan pemetaan penuh sebelum konsolidasi. |
| RMIK | PAR-RMIK-001, PAR-RMIK-002, PAR-RMIK-004, PAR-RMIK-006, PAR-RMIK-007 | Kandidat reproduce/pending/consolidate pada matriks | Setujui agregasi rekam medis, kelengkapan/sign-off, filing/custody, reopening/koreksi, serta batas target klinis-versus-RMIK untuk EMR IPP. |

## Kontrak penutupan yang diusulkan

- Daftar ID dan set otoritas per ID dikunci oleh validator. Perubahan co-owner atau dependency tidak boleh lolos secara diam-diam.
- Setiap baris memiliki satu `lead_authority_domain` non-produk. Accountable owner wajib berasal dari domain lead tersebut; approval wajib memakai identitas accountable owner yang telah ditetapkan dan domain lead yang sama. Persetujuan produk saja tidak dapat menutup baris.
- Semua co-owner wajib memiliki tepat satu dependency appointment dengan domain yang sama, tanpa domain hilang, tambahan, atau duplikat.
- Bukti non-pending, appointment, dan approval wajib memakai artefak JSON skema tertutup, terikat SHA-256, serta diverifikasi reviewer independen.
- Empat dimensi skenario sintetis wajib terpisah dan berstatus `ready` sebelum penutupan: normal, denial, correction/amendment, dan dependency outage/failure.
- Konsolidasi hanya boleh menuju PAR yang dikenal dan harus bebas siklus pada gabungan keputusan Batch A, B, C, serta target matriks.
- Tidak ada skenario yang mengizinkan data pasien nyata, Production, layanan BPJS/VClaim/SATUSEHAT live, atau mutasi hosted.

## Hasil yang dibutuhkan dari forum pemilik

1. keputusan `approve` atau `defer` per PAR beserta disposisi dan target/exclusion kanonik;
2. penetapan accountable owner pada domain lead dan seluruh co-owner dependency;
3. bukti yang dinormalisasi dan ditinjau independen;
4. persetujuan bertanggal dengan referensi serta kondisi; dan
5. empat skenario sintetis berstatus `ready` dengan hasil yang dapat diverifikasi.

Sampai kelima keluaran tersebut lengkap dan validator G0 lulus, seluruh Batch C tetap terbuka.
