# Proposal Keputusan G0 Batch E — Farmasi dan Gudang

Tanggal: 25 Agustus 2026
Status: proposal, seluruh keputusan dan otorisasi belum disetujui
Batas data: sintetis saja; Apotek dan GF tetap **Soon**

Dokumen ini menjelaskan struktur keputusan untuk 48 requirement Batch E. Dokumen ini bukan berita acara persetujuan, bukan bukti bahwa fungsi lama bekerja, dan bukan izin aktivasi integrasi atau stok. Register JSON menjadi sumber yang diperiksa mesin; proposal ini membantu review manusia.

## Pembagian keluarga

| Keluarga | Ruang lingkup | Jumlah | Lead authority yang diusulkan |
|---|---|---:|---|
| E1 | master/config farmasi dan obat | 6 | `pharmacy_master_data` |
| E2 | resep, dispensing, dan riwayat | 7 | `pharmacy` |
| E3 | pengeluaran, distribusi, dan retur depot | 6 | `pharmacy_operations` |
| E4 | tampilan stok, stock opname, dan laporan | 7 | `pharmacy_inventory_control` |
| E5 | pengadaan, penerimaan, dan retur pemasok | 5 | `procurement` |
| E6 | replenishment, buffer, dan kedaluwarsa | 3 | `warehouse_stock` |
| E7 | distribusi gudang/depot dan retur unit | 4 | `warehouse_stock` |
| E8 | stocktake, koreksi, dan immutable ledger | 9 | `inventory_control` |
| E9 | pengecualian blood stock | 1 | `blood_bank` |

Setiap lead di atas masih merupakan kebijakan otoritas yang diusulkan, bukan penunjukan orang. Identitas accountable owner, co-owner, dan approver tetap `pending` sampai ada artefak bertanda tangan dan ditinjau independen.

## Aturan keputusan minimum

1. Satu kejadian bisnis menghasilkan satu gerakan ledger dan satu efek biaya yang dapat direkonsiliasi; retry harus idempotent.
2. Transfer wajib memiliki pasangan sumber–tujuan. Lot, expiry, FEFO, valuation, period, dan reconciliation diterapkan sesuai kontrak keluarga.
3. Stok tidak boleh negatif; lot expired/quarantined tidak boleh dikeluarkan; gerakan atau biaya duplikat harus ditolak.
4. Riwayat tidak boleh diedit atau dihapus. Koreksi selalu append-only melalui reversal/compensating event dengan actor, alasan, periode, dan tautan ke kejadian asal.
5. Control total lintas ledger sumber/tujuan, finance, dan reporting harus memiliki receipt sintetis dengan selisih nol. Capture menu atau struktur tidak cukup.
6. Gate Batch C untuk konteks medication/order, Batch D untuk antarmuka ORP yang relevan, serta dampak Batch F dan G harus dicatat terstruktur. Gate F/G yang belum tersedia hanya dapat didefer oleh otoritas yang tepat dengan seluruh lingkup dikecualikan eksplisit.

## Kandidat konsolidasi yang belum diputuskan

- `PHA-001..004`: satu workflow dispensing yang diparameterkan berdasarkan setting.
- `PHA-011..013` dan `PHA-019`: satu issue ledger beserta view konteksnya.
- `PHA-005`, `PWH-003`, `PWH-016`: satu lifecycle transfer.
- `PHA-014`, `PWH-006`: satu lifecycle unit return.
- `PHA-006`, `PWH-004`, `PWH-023`: satu domain count dengan mode INITIAL/PERIODIC.
- `PHA-007/PWH-007`, `PHA-008/PWH-008`, dan `PHA-009/PWH-009`: pasangan shared projection yang berbeda.
- `PHA-020`, `PWH-010`, `PWH-011`, `PWH-019`, `PWH-020`: satu immutable movement ledger beserta view.
- `PWH-002` versus `PWH-012`: membutuhkan keputusan ekuivalensi eksplisit.
- `PWH-014`: hanya kandidat koreksi/reversal append-only, bukan mutasi transaksi lama.

Semua kandidat mempertahankan setiap ID dan dampak anggotanya sampai ada artefak mapping lengkap. `ADM-029` dan trolley tetap bersemantik belum diketahui. `PWH-022` tetap terpisah sebagai blood-stock exception dengan clinical compatibility/custody; tidak boleh disubstitusi menjadi stok obat.

## Batas integrasi

Seluruh skenario menggunakan endpoint `null`, tanpa kredensial, tanpa jaringan keluar, dan `NOT_SENT`. Tidak ada pengiriman BPJS, SATUSEHAT, eRx, pemasok, AP, accounting, device, atau printer. Tidak ada data pasien nyata dan tidak ada klaim kesiapan deployment.
