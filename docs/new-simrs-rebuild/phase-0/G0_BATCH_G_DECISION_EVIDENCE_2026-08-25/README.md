# Kontrak Bukti Keputusan G0 Batch G

Direktori ini adalah akar bukti tertutup untuk 120 keputusan Batch G. Saat proposal ini dibuat, direktori belum berisi bukti resolusi: seluruh baris masih kelas bukti `P`, keputusan `pending`, pemilik belum ditunjuk, skenario masih `draft`, dan output tetap `Soon` serta `NOT_SENT`.

Label lama seperti RL, W2, STPRS, ASKES, SIRS, atau nama integrasi nasional hanya jejak menu yang perlu diputuskan. Label tersebut bukan bukti definisi terkini, kepatuhan, pengiriman, penerimaan, atau kesiapan operasional.

## Batas keselamatan

- Hanya data sintetis beridentitas `SYN-*` yang boleh dipakai.
- Endpoint harus `null`, kredensial `absent`, jaringan keluar `false`, dan status pengiriman `NOT_SENT`.
- Tidak boleh ada transmisi BPJS, VClaim, SATUSEHAT, E-Klaim, iDRG, LIS, PACS, ERP akuntansi, SIRS, kesehatan publik, atau pelaporan statutoris.
- Proyeksi laporan tidak boleh menulis balik fakta sumber. Master target hanya menyimpan target berversi dan tidak pernah dianggap sebagai realisasi.
- Ekspor lokal harus dibatasi oleh peran/cohort, diberi watermark sintetis, alasan, digest, retensi, dan audit `view/run/export/denial`.

## Artefak yang dapat menutup sebuah kontrol

Validator hanya menerima JSON dengan skema tertutup, SHA-256 yang cocok, dan reviewer independen. Semua path harus berada di direktori ini. Jenis artefak yang didukung adalah:

- kontrak definisi yang harus cocok dengan 53 kelompok semantik substantif, pemetaan exact 120-ID, dan nilai pembeda yang dibekukan; PAR ID atau hash template generik saja tidak membuktikan semantik;

- resolusi sumber A–F yang mengikat register sumber lengkap, requirement sumber yang `approve` dengan disposisi `reproduce`/`replace`, pemilik yang ditunjuk, dan approval yang tercatat;
- resolusi dependensi intra-G terhadap target yang sudah disetujui;
- ekspor sumber sintetis kanonik dan output laporan kanonik yang digest-nya dihitung ulang oleh validator;
- descriptor parameter kanonik dengan payload dan hash yang dapat dihitung ulang, receipt rerun dari eksekusi kedua, receipt kebijakan RBAC/export, serta receipt event audit `view/run/export` yang semuanya mengikat output yang sama;
- rekonsiliasi independen dengan persamaan `report_total - authoritative_source_total + documented_exclusion_total - approved_adjustment_total = 0`, sampel lineage, hitungan join ganda nol, sumber hilang/NULL-state nol, dan rerun deterministik;
- definisi statutoris/kesehatan publik terkini beserta artefak definisi laporan exact yang mengikat formula, grain, source binding, inclusion/exclusion, periode, standard version, dan approval terpisah dari reporting/public-health, sponsor, security/privacy, product/business decision, dan setiap domain sumber;
- approval control manifest keputusan yang mengikat SHA definisi, seluruh resolusi sumber dan intra-G, rekonsiliasi, boundary/access/export, definisi statutoris, serta tanda tangan terpisah setiap otoritas terdampak; product ikut menandatangani keputusan bisnis tetapi tidak dapat menggantikan otoritas domain;
- pemetaan konsolidasi bersama yang mempertahankan semua ID, parameter pembeda berenumerasi (termasuk `care_setting` RJ/RI), definisi, lineage, authority, approval member, dan control total.

Appointment bukan approval. Penulis artefak, reviewer, pemilik sumber, dan verifier harus memenuhi pemisahan identitas yang divalidasi. File bebas, screenshot struktur, string SHA tanpa JSON terikat, atau selisih nol yang dinyatakan sendiri tidak dapat menutup G0.

## Perilaku kegagalan

Sumber yang belum siap—termasuk baris yang berstatus `unavailable`, `unknown`, `not_collected`, `not_applicable`, atau `suppressed` walaupun nilainya nol—membuat output `blocked` atau `partial`, menyebut sumber yang hilang, dan tidak dapat diekspor. Definisi lama tidak boleh diberi label current/compliant/submitted/accepted. Koreksi sumber, amendemen definisi, dan late event membuat versi restatement tertaut; versi output tertutup tidak boleh ditimpa.
