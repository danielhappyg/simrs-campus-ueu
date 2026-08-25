# Direktori Bukti Keputusan G0 Batch E

Direktori ini hanya menerima artefak JSON terstruktur untuk register `G0-BATCH-E-2026-08-25`. Keberadaan menu, tangkapan layar, route, atau teks bebas tidak membuktikan perilaku farmasi, gudang, mutasi stok, biaya, atau laporan. Register awal sengaja menyimpan seluruh bukti pada kelas `P` dan seluruh keputusan, penunjukan, dependensi, rekonsiliasi, serta persetujuan pada status `pending`.

## Batas keselamatan

- Hanya data sintetis lokal. Apotek dan GF tetap `Soon`.
- `endpoint` harus `null`, kredensial harus `absent`, jaringan keluar harus `false`, dan status pengiriman harus `NOT_SENT`.
- Dilarang mengirim ke BPJS, SATUSEHAT, eRx, pemasok, AP, akuntansi, alat, atau printer.
- Jangan simpan data pasien nyata, rahasia, token, kata sandi, endpoint aktif, atau bukti yang berasal dari mutasi produksi.

## Kontrak artefak

Setiap referensi harus menunjuk berkas JSON reguler di dalam direktori ini dan mencantumkan SHA-256 yang tepat. Objek harus memakai skema tertutup, mengikat `register_id`, requirement/family/candidate yang sesuai, identitas subjek, tanggal, dan peninjau independen. Peninjau harus berbeda dari subjek serta memiliki metode dan referensi verifikasi.

Jenis artefak yang diizinkan oleh validator:

- `g0_decision_evidence`: mengikat kelas bukti O/M/I/U, basis bukti, sumber, referensi, interpreter, keyakinan, dan peninjau.
- `g0_governance_attestation`: mengikat penunjukan pemilik, penunjukan co-owner, atau persetujuan akhir pada identitas dan domain otoritas yang dibekukan.
- `g0_batch_e_gate_resolution`: mengikat gate C/D/F/G, arah, lingkup, sumber tata kelola, digest, keputusan resolusi/defer, otoritas yang sudah ditunjuk, persetujuan upstream bila berlaku, eksklusi, dan peninjau.
- `g0_batch_e_ledger_receipt`: receipt sintetis per ledger sumber, ledger tujuan, finance, dan projection reporting; mengikat periode, cutoff, event count, control values, digest ledger, dan kunci idempotensi.
- `g0_batch_e_reconciliation`: mengikat empat receipt di atas, mengevaluasi persamaan keluarga atas integer quantity/minor-unit, dan mensyaratkan hasil perbedaan nol.
- `g0_batch_e_consolidation_mapping`: pemetaan ekuivalensi kandidat yang mempertahankan setiap PAR anggota dan dampaknya. Artefak ini tidak boleh dipakai untuk menghapus ID warisan.

Artefak rekonsiliasi tidak boleh sekadar capture struktur. Riwayat stok harus immutable; koreksi dilakukan dengan peristiwa kompensasi/reversal yang dapat diaudit. `PAR-PWH-014` tidak pernah mengotorisasi edit/delete/overwrite. `PAR-PWH-022` tetap pengecualian blood stock di bawah otoritas blood bank/transfusion, bukan stok obat biasa.
