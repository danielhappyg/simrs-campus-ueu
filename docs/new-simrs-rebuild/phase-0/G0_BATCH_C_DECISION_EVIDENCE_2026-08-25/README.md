# G0 Batch C decision-evidence directory

Direktori ini disediakan hanya untuk artefak JSON terstruktur yang dirujuk oleh `G0_BATCH_C_DECISION_REGISTER_2026-08-25.json`.

Direktori sengaja kosong. Belum ada bukti Batch C, penetapan penanggung jawab, penetapan co-owner, atau persetujuan yang dicatat. Kandidat disposisi pada matriks, kode lokal, pengujian otomatis, dan catatan UAT historis bukan bukti otoritatif atau persetujuan dengan sendirinya.

Setiap artefak yang kelak ditambahkan wajib:

1. berupa berkas `.json` reguler dan bukan symlink di dalam direktori ini;
2. memakai skema tertutup yang ditegakkan `scripts/validate-parity-governance.rb`;
3. mengikat `register_id` `G0-BATCH-C-2026-08-25`, PAR ID, subjek, identitas, domain otoritas, lingkup, tanggal, dan nilai keputusan/bukti terkait;
4. memuat reviewer independen yang identitasnya berbeda dari subjek, metode verifikasi yang diizinkan, dan referensi verifikasi;
5. cocok dengan SHA-256 pada record register; dan
6. lulus mode `integrity` serta `g0` sebelum ada klaim bahwa baris telah ditutup.

Untuk accountable owner, `authority_domain` pada artefak harus sama dengan `lead_authority_domain` dan binding owner pada baris register. Approval Batch C wajib memakai identitas yang persis sama dengan accountable owner yang telah ditetapkan dan `authority_domain` yang persis sama dengan lead; artefak approval juga wajib mengikat keduanya. Untuk setiap co-owner, tepat satu dependency appointment dengan domain yang sama wajib tersedia. Kehadiran berkas dan hash hanya membuktikan binding serta deteksi perubahan; keduanya tidak menggantikan verifikasi manusia atas dokumen sumber.
