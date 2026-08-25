# Proposal Tata Kelola G0 Batch F — Keuangan, Klaim, dan Batas Integrasi

Tanggal: 25 Agustus 2026  
Status: proposal/pending  
Batas data: hanya data sintetis; tidak ada data pasien, klaim, pembayaran, atau bank nyata

## Tujuan

Batch F memuat tepat 34 capability PAR untuk master keuangan, tagihan/pendapatan, piutang-setoran-jurnal, klaim/casemix, simulasi BPJS, dan sandbox SatuSehat. Dokumen ini membekukan kebijakan yang dapat diverifikasi mesin, bukan keputusan bisnis, penunjukan pejabat, persetujuan, atau klaim kesiapan.

Seluruh baris tetap `Soon`, bukti `P`, keputusan `pending`, pemilik belum ditunjuk, dependency belum diselesaikan, persetujuan belum direkam, dan pengiriman selalu `NOT_SENT`.

## Partisi keluarga yang dibekukan

| Keluarga | Cakupan | Lead policy yang diusulkan | Batas utama |
|---|---:|---|---|
| F1 | 5 | `finance_master` | master tarif/bank/komponen/target harus versioned dan effective-dated; target bukan pendapatan aktual |
| F2 | 15 | `cashier_revenue` | charge, bill version, alokasi jasa, dan proyeksi pendapatan tidak boleh tumpang tindih atau menulis balik ledger |
| F3 | 4 | `finance_accounting` | piutang, handoff setoran, penerimaan treasury, serta jurnal memakai event append-only dan reversal |
| F4 | 7 | `rmik_coding` | claim snapshot mengikat encounter final, coding disetujui, bill immutable, dan versi grouping; monitor bukan sumber kebenaran finansial |
| F5 | 3 | `claims_simulation` | BPJS/VClaim dan SatuSehat hanya deterministic local fixture; endpoint null, credential absent, outbound false |

Label authority di atas adalah domain kebijakan yang diusulkan. Label tersebut bukan bukti bahwa seseorang telah ditunjuk. Identitas dan seluruh appointment tetap `null` sampai ada artefak bertanda tangan yang sah dan diverifikasi independen.

## Ledger dan koreksi

Satu writer dibekukan untuk masing-masing `finance_master_ledger`, `charge_ledger`, `bill_version_ledger`, `receivable_ledger`, `payment_settlement_ledger`, `claim_version_ledger`, `medical_fee_allocation_ledger`, `reversal_adjustment_ledger`, `journal_ledger`, dan `integration_simulation_ledger`. `reporting_projection` selalu read-only.

Riwayat tidak boleh diedit, dihapus, ditimpa, dibackdate, atau membuka ulang periode tertutup. Koreksi memakai event reversal/adjustment kompensasi yang mengikat event asal, aktor, alasan, periode terbuka, dan idempotency key. Kegagalan parsial harus terlihat dan tidak boleh meninggalkan charge, receipt, claim, journal, atau pengiriman setengah jadi.

Persamaan IDR minor-unit hanya diwajibkan sesuai profil capability. Kebijakan mencakup bill dari gross charge/diskon/pajak-fee/debit-credit/reversal; AR dari opening, billed, payment, remittance, writeoff, dan adjustment; settlement dari receipt/refund/reversal hingga accepted deposit; claim snapshot dan cohort amount/count; jurnal debit=credit=source subledger; alokasi jasa tidak melampaui basis; dan pendapatan total sama dengan partisi yang saling lepas. Receipt ledger, cutoff Asia/Jakarta, positive event count, digest, dan semua selisih nol wajib sebelum approval.

## Dependency graph

Dependency upstream A–E berlaku per profil, bukan dipaksakan ke baris yang tidak relevan. Setiap gate menyebut daftar PAR sumber yang dibekukan. Resolusi hanya sah bila register sumber `complete`, seluruh baris sumber terminal approve/defer, dan setiap PAR yang dirujuk memiliki keputusan `approve` dengan disposition `reproduce` atau `replace`, appointed owner, serta approval reference/SHA yang cocok.

Gate A–D yang berlaku tidak boleh didefer oleh baris Batch F. Karena Apotek/GF tetap `Soon`, gate E yang berlaku boleh didefer secara sangat terbatas hanya bila authority `pharmacy_gf` dan `finance_accounting` telah ditunjuk, lalu masing-masing merekam persetujuan deferral bertanda tangan yang mengikat scope, resolution, exclusions, dan SHA artefak. Deferral tersebut wajib mengecualikan readiness medication charge-credit, stock valuation, pharmacy claim completeness, dan live delivery; target keputusan Batch F wajib mengulang pengecualian itu dan tidak boleh menyatakan tagihan obat lengkap.

Dependency intra-F dibekukan dari master ke bill, bill ke AR/settlement/journal, bill ke claim snapshot, dan claim snapshot ke simulasi batas nasional. FIN-015 bergantung pada FIN-012; FIN-017 bergantung pada source subledger; BPJS-001/002 bergantung pada claim RJ/RI. Graph harus acyclic. Gate G hanya dapat didefer secara eksplisit oleh authority reporting yang ditunjuk dengan pengecualian no-readiness, no-export, no-financial-truth, dan no-live-delivery. Capability proyeksi/monitor tidak boleh berstatus approve selama G didefer; keputusan harus `defer/exclude` dan mengulang tepat seluruh pengecualian G.

## Skenario sintetis wajib

Setiap ID memiliki lifecycle pre-state, transition, post-state, dan assertion yang berbeda serta empat skenario eksak:

1. normal: satu event idempotent, ledger yang berlaku direkonsiliasi;
2. denial: unauthorized/invalid/duplicate/stale ditolak sebelum partial state;
3. correction/amendment: reversal atau adjustment append-only menjaga versi lama immutable;
4. dependency outage: timeout atau ambiguous acknowledgement tetap `NOT_SENT` dan retry tidak menduplikasi event.

Hazard lintas keluarga wajib mencakup duplikasi charge akibat dispense/return Batch E, reversal akibat koreksi prosedur Batch D, perubahan payer/class Batch B setelah bill snapshot, perubahan coding Batch C setelah submit, monitor dianggap sumber finansial, double-count report, duplicate journal, reversal pembayaran tanpa membuka kembali AR, dan mutasi periode tertutup.

## Kandidat konsolidasi—belum diputuskan

Kandidat audit adalah ADM-018+019; FIN-001+002; FIN-004+005; FIN-006+007+008+009+013+014+016; FIN-018+019; FIN-012+015; CLM-001+003; CLM-002+004; RMIK-003+CLM-006; serta BPJS-001+002. Tidak satu pun telah disetujui.

FIN-012 Setoran dan FIN-015 TERIMA SETORAN mempertahankan dua peran lifecycle yang berbeda (handoff cashier dan receipt treasury). FIN-018/019 memiliki perbedaan versi yang belum diketahui. RMIK-003 dan CLM-006 adalah surface monitor yang dipindah/duplikasi, tetapi kesetaraan belum terbukti. Konsolidasi kelak memerlukan satu target terminal berstatus approve dengan disposition reproduce/replace, appointed owner dan approval SHA yang terikat, satu artefak bersama, seluruh ID dan dampak tetap dipertahankan, mapping fields/states/control totals/lineage/authorities lengkap, dan graph tanpa siklus.

## Larangan integrasi

Tidak ada endpoint, credential, outbound network, atau pengiriman ke BPJS, VClaim, Antrol, Aplicares, E-Klaim, iDRG, SATUSEHAT, payment/bank, accounting ERP, device, atau printer. FIN-017 hanya simulasi jurnal seimbang dan tidak mengirim ke accounting. RMIK-005 hanya sandbox lokal dan tidak membuktikan kesiapan SatuSehat.

G0 Batch F tetap terbuka sampai seluruh bukti perilaku, appointment, dependency, reconciliation, mapping bila relevan, dan approval memenuhi schema tertutup serta lolos verifikasi independen.
