# Proposal Keputusan G0 Batch D — Diagnostik, Prosedur, dan Layanan Penunjang

Tanggal: 25 Agustus 2026
Status: proposal, bukan keputusan atau persetujuan

## Tujuan

Dokumen ini membantu pemilik klinis dan operasional menilai 19 requirement Batch D yang dipatok oleh manifest. Register pendamping tetap fail-closed: seluruh bukti masih kelas `P`; keputusan, appointment, resolusi dependensi, lifecycle/mapping, dan approval masih `pending`.

## Batas keselamatan

- Hanya data sintetis. Tidak ada data pasien nyata.
- Integrasi eksternal dinonaktifkan. Mode yang diperbolehkan hanya `none`, `non_transmitting_simulation`, atau `simulated_adapter`, dengan outbound, endpoint hidup, dan kredensial selalu `false`.
- Klaim/BPJS dan Apotek tetap **Soon**. Tidak ada transaksi VClaim/SATUSEHAT, LIS/RIS/PACS hidup, perangkat, kendaraan, atau stok nyata.
- Route, menu, atau UAT pengajaran tidak membuktikan lifecycle klinis, koreksi/amendment, pembatalan, charge, ataupun ekuivalensi.

## Paket keputusan yang harus ditutup per requirement

1. Tetapkan accountable owner dari domain lead non-product yang dibekukan di register dan appointment untuk setiap co-owner persis satu kali.
2. Lampirkan bukti JSON tertutup, SHA-bound, dan direview pihak independen. Untuk approval diagnostik/prosedur, bukti harus kelas `O`, `M`, atau `I` dan tidak boleh hanya `route_or_menu`.
3. Putuskan `approve`, `revise`, `defer`, atau `reject`, lalu tetapkan disposition kanonik dan target/pengecualian.
4. Jalankan empat skenario sintetis terpisah: normal, denial, correction/amendment, dan dependency outage.
5. Selesaikan atau authority-defer setiap `upstream_requirement_dependencies`; uraian downstream bebas tidak menggantikan gate terstruktur.
6. Untuk keputusan approve dan seluruh konsolidasi, lengkapi artefak lifecycle serta mapping. Konsolidasi Batch D hanya dapat dipertimbangkan untuk `PAR-CLN-010 → PAR-CLN-009` dan `PAR-CLN-012 → PAR-CLN-011`, dengan target terminal sudah mempunyai keputusan non-konsolidasi yang selesai.

## Fokus rapat

- Master diagnostik `PAR-ADM-014`, `PAR-ADM-015`, dan specimen `PAR-ADM-043`: definisi pemeriksaan, order, specimen, result, koreksi/amendment, pembatalan, serta mapping charge tanpa transmisi klaim.
- IBS/operasi `PAR-ADM-041`, `PAR-CLN-009`, dan `PAR-CLN-010`: schedule, safety gate, procedure record, cancellation, addendum, serta batas stok/farmasi.
- Rehabilitasi `PAR-CLN-011`–`014`: referral, appointment, session, outcome, correction, dan charge boundary.
- PA, mikrobiologi, dan bank darah `PAR-CLN-015`–`017`: custody, result authority, amendment, compatibility/traceability, dan larangan klaim stok nyata.
- Jenazah dan ambulans `PAR-CLN-018`/`020`: identity, custody/handoff, release/cancellation, audit, dan privacy.
- Farmasi IBS `PAR-ORP-001`: jangan menebak equivalence Batch E. `batch_gate: E` tetap pending untuk stock/issue/return/lot/charge, atau harus ditunda eksplisit oleh otoritas `pharmacy` dan seluruh semantik itu masuk pengecualian keputusan.

## Hasil saat ini

Belum ada keputusan bisnis atau persetujuan. Dokumen ini tidak mengubah matriks, tidak membuka G0, dan tidak menyatakan kesiapan produksi, Klaim/BPJS, Apotek, stok, atau integrasi apa pun.
