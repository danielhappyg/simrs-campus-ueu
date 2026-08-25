# Proposal Tata Kelola G0 Batch G — Pelaporan, Mutu, Statutoris, dan Manajemen

Status dokumen ini adalah **proposal**, bukan keputusan atau persetujuan. Seluruh 120 capability Batch G masih `pending`, bukti `P`, appointment belum ada, approval belum ada, ketersediaan `Soon`, dan batas data `synthetic_only`/`NOT_SENT`.

## Ruang lingkup yang dibekukan

Batch G mencakup tepat 120 ID: `PAR-ADM-007`, `PAR-ADM-031`, `PAR-ADM-035`, dan `PAR-RPT-001` sampai `PAR-RPT-117`. Partisi risikonya adalah:

- G1 — 33 laporan operasional, akses pasien, encounter, sensus, dan aliran layanan;
- G2 — 36 laporan klinis, RMIK, keperawatan, dan mutu;
- G3 — 40 master/laporan statutoris, kesehatan publik, dan outcome khusus;
- G4 — 11 master target serta laporan manajemen, keuangan, payer, dan outcome.

Tiga capability ADM tidak diperlakukan sebagai laporan biasa dan tidak dipaksa membuat rekonsiliasi output aktual. `PAR-ADM-007` adalah master target efektif-bertanggal—target tidak pernah menjadi actual. `PAR-ADM-031` adalah master referensi penyakit menular. `PAR-ADM-035` adalah referensi/form W2 berversi yang definisinya masih belum diselesaikan pemilik otoritatif. Semua `PAR-RPT-*` adalah proyeksi baca-saja yang dilarang menulis fakta sumber.

## Prasyarat keputusan

Setiap baris memiliki daftar sumber A–F yang spesifik, domain pemilik sumber, entitas sumber, lead non-product, seluruh co-owner, dan DAG intra-G. Keputusan `approve` hanya dapat ditutup bila setiap register sumber terkait lengkap dan setiap requirement sumber yang dipakai telah `approve` dengan disposisi `reproduce` atau `replace`, pemiliknya ditunjuk, serta approval-nya terikat SHA. Keputusan sumber `defer`/`exclude` tidak dapat dipakai untuk menyatakan laporan siap.

Jika sumber atau definisi belum siap, pilihan aman adalah `defer` dengan disposisi `exclude` dan exclusion yang menyebut semua scope hilang, `not_report_ready`, `not_exportable`, `not_current_compliance`, dan `not_transmitted`. Tidak ada penghilangan sumber secara diam-diam atau konversi `NULL` menjadi nol.

## Kontrak definisi dan output

Setiap ID dibekukan melalui 53 kelompok mesin semantik substantif, peta exact 120-ID, dan override dimensi/nilai tetap per-ID. Exactness tidak boleh berasal dari menambahkan PAR ID ke template generik: setelah `requirement_id` dan ID kontrak dikeluarkan, fokus capability, nilai parameter, grain/key, formula, source role/entity, eligibility, serta time/close semantics setiap baris tetap membedakannya. Kontrak mencakup status semantik, fokus menu, care setting, dimensi, purpose, pengguna, sensitivitas, kelas, grain, distinct key, numerator/denominator, inclusion/exclusion, parameter dan nilai tetap, field serta peran sumber, transformasi, versi terminologi, event/effective/recorded time, zona `Asia/Jakarta (+07:00)`, cutoff, period close, freshness, NULL policy, suppression/masking, layout/export, retensi, versi definisi, join contract, snapshot, koreksi/restatement, perilaku sumber tidak lengkap, dan write semantics.

Peta ini membedakan antara lain Register Lab (`RPT-012`, hanya sumber laboratorium D), Register Radiologi (`RPT-014`, hanya sumber radiologi D), Register Cancer (`RPT-015`, encounter B dan diagnosis kanker berkode C dengan cancer cohort/code-set version), Register IBS (`RPT-018`, sumber surgery/theatre D), perpindahan ranap (`RPT-024`, grain transfer-event dengan source ward, target ward, dan transfer state), audit kelengkapan RM (`RPT-022` dan `RPT-030/031/032`, item checklist dan eligible-record denominator), kematian ASKES (`RPT-041`, death episode/disposition dengan sumber serta filter payer ASKES dan definisi statutoris), rekap kasus ISPA berkode (`RPT-054`, encounter B dan diagnosis C tanpa sumber lab D), aktivitas dokter RJ/RI (`RPT-057/060`, encounter dan provider assignment), rujukan keluar (`RPT-102`, referral/encounter dan disposition), sensus ruang (`RPT-107`, grain ward-room dengan parameter ward dan room), dimensi APS/pulang (`RPT-111..114`, discharge/bed/class/provider/reason), mortality (`RPT-040/041/043/101`, discharge/IGD dan death disposition), current inpatient census (`RPT-042/116`, active admission/bed as-of), dan data cara bayar (`RPT-045`, hitungan encounter menurut payer class; sumber F baru dapat ditambahkan bila definisi berotoritas memang meminta nilai keuangan). Label `Trend`, `JHP`, `Sebaran Pasien`, metrik harian/bulanan yang belum jelas, aturan pasien baru-lama, varian/route duplikat (`RPT-026/027`, `028/029`, `046/047`, `097/106`, `099/110`, `040/115`), serta indikator statutoris yang belum terdefinisi tetap memblokir reproduce/replace.

Untuk HAI `RPT-046` dan `RPT-049`, grain kasus dan numerator hitungan sudah dibekukan, tetapi denominator populasi pajanan tetap secara eksplisit belum terselesaikan. Status `defined_count_denominator_unresolved` bukan fallback generik dan tidak boleh ditutup sebagai reproduce/replace sampai denominator/rate disetujui otoritas.

Join hanya boleh `one_to_one`, `one_to_many`, atau `many_to_one`. Anak harus diagregasi ke grain sebelum join; bridge key harus unik; `many_to_many` tidak terikat ditolak. Hitungan utama menggunakan distinct authoritative key, sedangkan source-line count dicatat terpisah. Satu encounter dengan dua diagnosis dan tiga prosedur tidak boleh berubah menjadi enam encounter.

Output mengikat SHA definisi, artefak parameter kanonik dan hash yang dapat dihitung ulang, root ekspor sumber, snapshot ID, cutoff, status periode, dan SHA output. Rerun deterministik harus merupakan eksekusi kedua dengan receipt terpisah, bukan pernyataan bahwa digest pertama sama dengan dirinya sendiri. Kebijakan akses/RBAC dan event audit `view/run/export` juga mempunyai receipt tertutup yang mengikat aktor, peran, cohort, output digest, watermark, alasan ekspor, masking, dan retensi. Koreksi, perubahan definisi, atau late event menambah restatement yang tertaut ke output sebelumnya, tidak menimpa versi tertutup.

## Rekonsiliasi dan verifikasi independen

Output lengkap wajib mempunyai ekspor sumber sintetis kanonik yang root-nya dapat dihitung ulang, output kanonik yang digest-nya dapat dihitung ulang, sampel lineage positif, dan verifier yang berbeda dari penulis laporan serta setiap penulis sumber. `unavailable`, `unknown`, `not_collected`, `not_applicable`, atau `suppressed` tetap dihitung sebagai sumber/NULL tidak lengkap meskipun nilai numeriknya nol; keadaan itu memaksa output blocked/partial dan non-exportable. Persamaan wajib adalah:

`report total - authoritative source total + documented exclusions - approved adjustments = 0`

Dengan demikian `report total = authoritative source total - documented exclusions + approved adjustments`. Join ganda dan sumber hilang harus nol. Perbedaan `unknown`, `not_collected`, `not_applicable`, `unavailable`, `suppressed`, dan `numeric_zero` harus dipertahankan.

## Definisi lama dan otoritas

Label RL, W2, STPRS, ASKES, SIRS, dan label kesehatan publik lain tetap `legacy_simulation_only`. Reproduce/replace untuk G3 yang semantiknya sudah dibekukan memerlukan standard identifier, versi, tanggal efektif, sumber definisi, artefak definisi laporan bertanda tangan yang mengikat formula, numerator/denominator, grain, field, source binding, inclusion/exclusion, periode, dan approval terpisah serta SHA-bound dari reporting/public-health, sponsor statutoris, security/privacy, product/business decision, dan setiap domain sumber. Indikator statutoris atau varian legacy yang semantiknya masih `unresolved` tetap tidak dapat ditutup hanya dengan metadata standar. Approval keputusan akhir setiap baris juga harus mengikat SHA definisi, seluruh resolusi sumber/intra-G, receipt rekonsiliasi, boundary/output-access-export, dan definisi statutoris bila berlaku; setiap domain terdampak menandatangani control manifest yang sama dengan identitas appointment-nya sendiri. Appointment bukan approval, dan product delivery wajib berpartisipasi sebagai otoritas keputusan bisnis tetapi tidak boleh menggantikan otoritas domain lain.

Kandidat konsolidasi dibatasi secara konservatif pada pasangan yang memang berlabel dekat: `RPT-001/002`, `RPT-003/004`, `RPT-026/027`, `RPT-028/029`, `RPT-033/034`, dan `RPT-046/047`. Kandidat bukan keputusan ekuivalensi. Kandidat hanya dapat dipakai setelah satu terminal target `approve` dengan disposisi `reproduce`/`replace` dan satu artefak pemetaan bersama mempertahankan seluruh ID, definisi, sumber, control total, authority, serta approval setiap member. Perbedaan harus menjadi parameter tertutup dengan nilai enumerasi; khusus `RPT-001/002` dan `RPT-003/004`, `care_setting=outpatient|inpatient` wajib dipertahankan, bukan disamarkan sebagai daftar field generik. Form RL yang berbeda, variasi dimensi manajemen, serta detail-versus-rekap tidak diasumsikan identik. Hubungan master `ADM-031/035` ke `RPT-021` adalah dependensi, bukan konsolidasi.

## Batas integrasi

Tidak ada endpoint, kredensial, outbound network, atau pengiriman. Tidak ada BPJS/VClaim/SATUSEHAT/E-Klaim/iDRG/LIS/PACS/ERP/SIRS/statutory/public-health live. Ekspor lokal hanya untuk data sintetis, harus watermarked, role/cohort scoped, minimum necessary, masked/suppressed bila perlu, beralasan, berdigest, berretensi, dan diaudit.

Proposal ini hanya membuat keputusan masa depan dapat diverifikasi mesin. Dokumen ini tidak mengangkat readiness, kepatuhan, implementasi, UAT, atau transmisi apa pun.
