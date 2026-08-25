# Bukti Keputusan G0 Batch D

Direktori ini hanya menerima artefak JSON terstruktur yang dirujuk oleh `G0_BATCH_D_DECISION_REGISTER_2026-08-25.json`. Saat ini belum ada bukti, appointment, pemetaan, lifecycle, atau persetujuan otoritatif; semua baris tetap `P` dan `pending`.

Validator menolak artefak di luar direktori ini, symlink/berkas nonreguler, digest SHA-256 yang tidak cocok, field tambahan, identitas atau domain yang tidak cocok dengan register, serta reviewer yang sama dengan subjek. Bukti Batch D non-pending juga wajib mengikat `evidence_basis`; observasi route/menu saja tidak cukup untuk persetujuan diagnostik atau prosedur.

Artefak lifecycle dan mapping harus memakai skema tertutup `g0_batch_d_lifecycle` dan `g0_batch_d_mapping`. Berkas tersebut baru boleh dibuat dari bukti sintetis yang dapat diaudit dan keputusan manusia yang benar-benar ditandatangani. Tidak boleh ada data pasien nyata, kredensial, endpoint hidup, transmisi BPJS/VClaim/SATUSEHAT/LIS/RIS/PACS, atau klaim kesiapan stok.

Khusus `PAR-ORP-001`, dependensi `batch_gate: E` mencakup `stock`, `issue`, `return`, `lot`, dan `charge`. Gate tersebut tetap menghalangi readiness sampai Batch E diselesaikan, atau seluruh semantik itu ditunda secara eksplisit oleh otoritas farmasi dengan pengecualian tertulis. Label menu tidak boleh dipakai untuk menebak kesetaraan ke PAR Batch E tertentu.
