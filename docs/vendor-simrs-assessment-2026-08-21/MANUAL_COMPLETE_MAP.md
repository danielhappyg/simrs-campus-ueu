# Vendor Manual to Current System: Complete Coverage Map

Assessment date: 21 August 2026  
Source manual: `SIMRS_Manual_vendor.pdf` / `SIMRS_Manual_vendor.txt`  
Manual date shown in the document: 29 September 2018 (PDF metadata indicates 2018)  
Current menu source: `MODULE_INVENTORY.md`, captured from the authorized combined lecturer-administrator account.

## How to read this map

This is a reconciliation between a historical vendor guide and the complete visible menu inventory. It is not a claim that every visible link was executed, nor that a menu is operational merely because it is visible. A menu assignment proves that the account can see the link; it does not prove backend authorization, data quality, integration success, or that the feature is used at UEU.

Status terms:

- **Direct**: the current menu has the same function/name or an obvious current equivalent.
- **Expanded**: the manual function is present, but the current system adds variants, versions, units, or related screens.
- **Renamed/combined**: the current menu appears to represent the manual function under a different label or with a different split.
- **Current addition**: visible today but not described in the 2018 manual.
- **Not visible / needs confirmation**: described in the manual but not found as a current visible menu item; it may be hidden behind another screen or removed.

## Executive reconciliation

The manual describes a conventional 2018 SIMRS baseline: registration, clinical examination, laboratory/radiology/operations/nutrition, medical records, reports, pharmacy, cashier, and master-data/access administration. The current installation is materially broader. It exposes 13 categories and 268 submenu items, including current variants (`v2`, `v3`), SatuSehat, EMR IPP, iDRG and claim-plafon work, large reporting expansions, rehabilitation and blood-bank modules, pharmacy-IBS, IoT temperature, and extensive logs/master data.

At category level the manual provides a useful historical foundation for:

| Current category | Manual baseline | Current relationship |
|---|---|---|
| Pendaftaran | IGD, rawat jalan, rawat inap, registration outputs | Direct plus Display Admisi and Rawat Jalan v2 |
| Pemeriksaan | IGD, rawat jalan, rawat inap, laboratory, radiology, operation, gizi | Expanded with assessment/triage, v2/v3, rehabilitation, PA/microbiology, blood bank, jenazah, ambulance |
| RM | RJ/RI, Monitor Klaim, Filing, ICD-10/ICD-9 | Expanded with SatuSehat RJ and EMR IPP RJ/RI |
| Klaim | Only claim monitoring and SEP-related narrative | Current addition/expansion: RJ/RI claim screens, iDRG, plafon, monitor |
| Laporan | Statistics, reports, indices, RL, RJ/RI recaps | Expanded from a small historical list to 117 visible report links |
| BPJS | Insurance/SEP checks mentioned in registration | Current integration category; clinical RJ/RI links plus role-level VClaim/LUPIS/SIPP nodes |
| Apotek | RJ, RI, outside patient, distribution, stock, returns, reports | Expanded with IGD, trolley, prescription history and additional expenditure/return screens |
| GF | Drug master, receipts, distribution, stock, returns, reports | Expanded with PO, expiry, edit transaction, buffer stock, blood stock and variants |
| Kasir | RJ, IGD, RI, other transactions, revenue, receivables, deposit, medical services | Expanded with unit revenue, auto-journal, billing/tagihan variants |
| ManajemenData | groups, users, backup/restore, profile, menu, medical staff, tariffs, beds, payment, cost components, bank, supplier | Expanded with Settings, clinical pathway, printer, clinical masters, logs, EMR documents, pharmacy data and more |
| IoT | None | Current addition: Temperature |
| FarmasiIBS | None | Current addition: IBS |
| Help | None as a current category | Current addition: Manual Book |

The most important document-control conclusion is that the 2018 manual cannot be treated as the current system specification. It is a historical workflow baseline and must be supplemented by a current vendor-admin guide, data dictionary, integration catalogue, role matrix, release history, and backup/restore runbook.

## A. Definition and scope in the manual

The manual defines SIMRS as an integrated system covering registration, clinical service, investigations, medical records, pharmacy, finance, secretariat/personnel/accounting, reporting and management control. Its scope explicitly lists registration, medical, supporting services, cashier, pharmacy, medical record, bed information, access settings, and use by all hospital units.

This definition broadly matches the current product shape. The current menu demonstrates that the vendor installation is not merely an RMIK or registration application: it has clinical, diagnostic, pharmacy, inventory, finance, claims, reporting, administration, integration, IoT and teaching-relevant surfaces. However, the manual does not document the current campus teaching boundary or whether records are synthetic, historical, or live-like. That classification must be confirmed separately.

## B. Login and public information surfaces

### Manual description

The login section documents username, password and shift selection. It also describes public information for empty beds and admitted patients, full-screen/print controls, and the principle that each operator receives access according to their rights.

### Current mapping

| Manual function | Current evidence | Status and interpretation |
|---|---|---|
| Login with username/password/shift | Current login page accepts the same three concepts | Direct; the current account is deliberately a combined lecturer-administrator account, so broad administrator visibility is expected for teaching management |
| Empty-bed information by class/ward/Aplicares | Public bed information links and menu observed | Direct/expanded; current public surface includes bed class/ward/map/room-class/Aplicares views |
| Admitted-patient information | Public admitted-patient link and dashboard information | Direct; do not infer that exposed records are synthetic without confirmation |
| Full-screen and print escape behavior | Manual only | Not separately re-tested; browser behavior should be validated in controlled UAT |
| Per-user rights | Current Groups, Pengguna and role editor | Direct but incomplete; current group and permission governance requires a separate role matrix |

The historical rights statement should be read as a governance requirement, not proof of least privilege. The combined lecturer-administrator account is intentional; the review question is whether student and operational accounts are appropriately separated from that teaching-admin role and whether actions are auditable.

## C. Pendaftaran (registration)

### Manual workflow baseline

The manual documents:

1. IGD registration.
2. Rawat Jalan registration.
3. Rawat Inap registration.
4. Patient identity: new/old patient, RM number, NIK, name, sex, date of birth/age, religion, education, occupation, address and phone.
5. Responsible person name and phone.
6. Visit data: date/type, clinic, doctor, surgical/non-surgical case, accident flag, entry method, payment method, insurance number and referral.
7. Outputs: patient card, IGD sheet, clinic sheet, labels, inpatient wristband, SEP, queue number, control card, RM tracer and service-proof letter.
8. Lower buttons: patient search, visit data, save, online-registration retrieval, new registration and close.

### Current menu reconciliation

| Manual item | Current menu | Status |
|---|---|---|
| Pendaftaran IGD | `Pendaftaran > IGD` | Direct |
| Pendaftaran Rawat Jalan | `Pendaftaran > Rawat Jalan` | Direct |
| Pendaftaran Rawat Inap | `Pendaftaran > Rawat Inap` | Direct |
| Online registration retrieval | Registration form exposes online-registration functions/buttons | Direct/expanded, but successful end-to-end online flow needs UAT |
| Patient identity and visit data | Current Rawat Jalan form contains extensive identity, contact, demographic, guarantor, visit, referral and payment controls | Expanded; current form is substantially richer than the manual screenshots |
| SEP/insurance and referral | Registration form includes BPJS/SEP/referral controls; separate `BPJS > Rawat Jalan` and `Rawat Inap` | Expanded; actual bridging state must be verified per environment |
| Print outputs | Current registration form exposes print/queue/label/signature/general-consent controls | Expanded; print templates and printer routing need current documentation |
| Display/bed/admission information | `Pendaftaran > Display Admisi` plus public links | Current addition/expanded public-operational surface |
| Rawat Jalan v2 | `Pendaftaran > Rawat Jalan v2` | Current addition/variant not in manual |

The observed current form showed `Status Bridging Vclaim: Tidak Aktif` at the time of review. This means the screen is present, not that BPJS processing is available. The vendor must explain expected configuration for the UEU teaching installation.

## D. Pemeriksaan (clinical and supporting services)

### Manual workflow baseline

The manual describes selection of a registered patient followed by input of patient/visit context, time, clinician, tariff/class and continuation. It documents these service areas:

- IGD: cost summary, actions, other actions, depot medicines, medical examination, history and internal referral.
- Rawat Jalan: cost summary, actions, other actions, recipes, medical examination (anamnesis, diagnosis, ICD-10, action), HAI's, history and internal referral.
- Rawat Inap: room/cost, examination/DPJP, doctor visits, doctor actions, nurse actions, other actions, depot medicine, HAI's, INOS, diet, history and internal referral, discharge.
- Laboratory: selecting a cost/component and entering laboratory service.
- Radiology: selecting a cost/component and entering radiology service.
- Operation: selecting an operation/cost component and saving it.
- Gizi: inpatient nutrition/diet entry.

The manual describes continuation/discharge values including discharge, inpatient admission, referral, return to originating facility, death/DOA and other dispositions. It also uses some hospital-specific wording that needs clinical governance review before being reused for a campus exercise.

### Current menu reconciliation

| Manual service | Current menu | Status |
|---|---|---|
| IGD | `Pemeriksaan > IGD` | Direct |
| Rawat Jalan | `Pemeriksaan > Rawat Jalan` | Direct |
| Rawat Inap | `Pemeriksaan > Rawat Inap` | Direct |
| Laboratorium | `Pemeriksaan > Laboratorium` | Direct |
| Radiologi | `Pemeriksaan > Radiologi` | Direct |
| Operasi | `Pemeriksaan > Operasi` | Direct |
| Gizi | `Pemeriksaan > Gizi` | Direct |
| Assessment/triage | `Pemeriksaan > Assesmen`, `Triage` | Current addition |
| Rawat Inap v2 | `Pemeriksaan > Rawat Inap v2` | Current addition/variant |
| Operasi v3 | `Pemeriksaan > Operasi v3` | Current addition/variant |
| Rehabilitation | `Rehabilitasi Medis`, `Instalasi Rehabilitasi Medik 2`, `Terapi Wicara`, `Okupasi Terapi` | Current additions |
| Pathology/microbiology | `Lab PA`, `Lab Mikro` | Current additions |
| Blood bank | `Bank Darah` | Current addition |
| Mortuary | `Jenazah` | Current addition |
| Ambulance | `Ambulance` | Current addition |

The current menu is therefore a broader clinical platform than the manual. The manual does not explain how newer services integrate with registration, EMR, billing, pharmacy, claims or reports. Those cross-module transitions should be mapped in the current workflow specification.

## E. RM (medical record)

### Manual baseline

The manual covers Rawat Jalan/Rawat Inap record entry, ICD-10 and ICD-9 selection, continuation, accident/case fields and document completeness (anamnesis, diagnosis, doctor name and doctor signature). It also documents Monitor Klaim and Filing.

### Current menu reconciliation

| Manual item | Current menu | Status |
|---|---|---|
| Medical record Rawat Jalan | `RM > Rawat Jalan` | Direct |
| Medical record Rawat Inap | `RM > Rawat Inap` | Direct |
| Monitor Klaim | `RM > Monitor Klaim` and `Klaim > Monitor Klaim` | Expanded/duplicated surface; route ownership must be clarified |
| Filing | `RM > Filing` | Direct |
| ICD-10/ICD-9 and completeness | Current RM/clinical forms expose related concepts; a full field-level mapping was not established from the manual | Direct concept, current implementation needs field verification |
| SatuSehat RJ | `RM > SatuSehat RJ` | Current addition |
| EMR IPP RJ | `RM > EMR IPP RAWAT JALAN` | Current addition |
| EMR IPP RI | `RM > EMR IPP RAWAT INAP` | Current addition |

The current EMR IPP and SatuSehat surfaces are major functional additions and are not documented by the 2018 guide. Their identifiers, consent, provenance, retry/error handling, and teaching-data boundary require vendor documentation.

## F. Klaim and BPJS

### Manual baseline

Claims are only represented indirectly in the manual: insurance/SEP checks and printing during registration, Monitor Klaim in RM, and a general statement about claims/verification.

### Current menu reconciliation

| Current category/function | Manual coverage | Status |
|---|---|---|
| `Klaim > Rawat Jalan` | No dedicated manual section | Current addition |
| `Klaim > Rawat Inap` | No dedicated manual section | Current addition |
| `Klaim > Rawat Jalan iDRG` | No | Current addition |
| `Klaim > Rawat Inap iDRG` | No | Current addition |
| `Klaim > Perk Plafon RI` | No | Current addition |
| `Klaim > Monitor Klaim` | Claim monitoring appears under RM | Expanded/relocated |
| `BPJS > Rawat Jalan` and `Rawat Inap` | Insurance/SEP concepts only | Expanded current integration surface |
| Role-level VClaim/LUPIS/SIPP nodes | No | Current permission/integration additions |

The manual is not sufficient to explain claim lifecycle, coding, grouping, plafon checks, submission, verification, correction, rejection, resubmission or reconciliation. These are a separate end-to-end workflow domain.

## G. Laporan (reports)

The manual has five reporting families. The current installation exposes 117 report links. The report count is therefore an expansion, not a one-to-one version of the manual.

### G1. Manual Statistik

| Manual report | Current visible equivalent | Status |
|---|---|---|
| 10 Besar Penyakit Rajal | `Laporan > 10 Besar Penyakit Rajal` | Direct |
| 10 Besar Penyakit Ranap | `Laporan > 10 Besar Penyakit Ranap` | Direct |
| 10 Tindakan Rajal | `Laporan > 10 Tindakan Rajal` | Direct |
| 10 Tindakan Ranap | `Laporan > 10 Tindakan Ranap` | Direct |
| Pasien | `Laporan > Pasien` | Direct |
| Kunjungan | `Kunjungan RI` and `Kunjungan RJ` | Expanded/split |
| Trend | `Laporan > Trend` | Direct |
| HAI's | `Rekap HAI's`, `Rekap HAI's 2` | Expanded/renamed |

### G2. Manual Laporan

| Manual report | Current visible equivalent | Status |
|---|---|---|
| Register Pendaftaran | `Register Pendaftaran` | Direct |
| Kunjungan Terakhir | `Kunjungan Terakhir` | Direct |
| Register IGD/RJ/RI | `Register IGD`, `Register Rawat Jalan`, `Register Rawat Inap` | Expanded/split |
| Rekap JHP | `Rekap JHP` | Direct |
| W2 | `W2` | Direct |
| Analisis Kuantitatif | `A. Kuantitatif Ranap`, `A. Kuantitatif Rajal`, `Rekap A.K. Rajal`, `Rekap A.K. Ranap` | Expanded/renamed |
| Px Masuk Ranap | `Px Masuk Ranap` | Direct |
| Px Pindah Ranap | `Px Pindah Ranap` | Direct |
| Px Pulang | `Px Pulang` | Direct |
| PTM Rajal/Ranap | `PTM RAJAL`, `PTM RAJAL V2`, `PTM RANAP`, `PTM RANAP V2` | Expanded/variant |
| Evaluasi CMRJ/CMRI | `A. Kuantitatif` / current evaluation reports; no exact same label | Renamed/needs confirmation |
| Evaluasi Keterlambatan | `Evaluasi Keterlambatan` | Direct |
| Rekap Evaluasi Keterlambatan | `Rekap Evaluasi Keterlambatan` | Direct |
| K. Catatan Perawat | `K. Catatan Perawat` | Direct |
| K. Resume ASKEP | `K. Resume ASKEP` | Direct |
| K. Rekam Medis | `K. Rekam Medis` | Direct |
| Perujuk | `Perujuk` | Direct |
| Imunisasi | `Imunisasi` | Direct |
| Kematian | `Kematian` (appears in multiple report contexts) | Direct/duplicated context |
| Kematian ASKES | `Kematian ASKES` | Direct |
| Masih Dirawat | `Masih Dirawat` | Direct |
| Kematian External | `Kematian External` | Direct |
| Imunisasi Ranap | `Imunisasi Ranap` | Direct |
| Data Cara Bayar | `DATA CARA BAYAR` | Direct/case change |
| Px Dirujuk | `Px Dirujuk` | Direct |
| Sebaran Pasien | `Sebaran Pasien` | Direct |
| Register HAI's | `Register HAI's` | Direct |

The manual has numbering gaps in this report section. The gaps are evidence of incomplete documentation, not proof that the corresponding current reports do not exist.

### G3. Manual Indeks

| Manual index | Current visible equivalent | Status |
|---|---|---|
| Penyakit RJ/RI | `Penyakit Rawat Jalan`, `Penyakit Rawat Inap` | Expanded/split |
| Dokter | `Dokter RJ`, `Dokter RI` | Expanded/split |
| Operasi | No exact `Laporan > Operasi` label in the captured inventory | Not visible / needs confirmation |

### G4. Manual RL

| Manual RL/report | Current visible equivalent | Status |
|---|---|---|
| RL 3.2 | `RL 3.2` plus `RL 3.1` through `RL 3.19` | Expanded |
| RL 4A/4B | `RL 4.1`, `RL 4.2`, `RL 4.3`, `RL 4A`, `RL 4B` | Expanded/renamed |
| RL 5.1–5.4 | `RL 5.1` through `RL 5.4` | Direct |
| RL 4A/4B Sebab | `RL4A Sebab`, `RL4B Sebab` | Expanded/split |
| STP-RS-RI/RJ | `STP-RS-RJ`, `STP-RS-RI`, `STPRS-RI2`, `STPRS-RJ2` | Expanded/variant |

The current RL inventory is much larger than the manual and includes many RL 3.x and RL 4.x forms never described in 2018.

### G5. Manual Rekap Rajal/Ranap

| Manual recap | Current visible equivalent | Status |
|---|---|---|
| Rajal per bulan | `Per Bulan` (the first occurrence in the RJ/RI recap block) | Direct/ambiguous duplicate label |
| Rajal per hari | `Per Hari` | Direct |
| Per poliklinik | `Per Poliklinik` | Direct |
| Per dokter baru-lama | `Per Dokter Baru-Lama` | Direct |
| Pasien DOA-DOS | `Pasien DOA-DOS` | Direct |
| Pasien dirujuk keluar | `Pasien Dirujuk Keluar` | Direct |
| Lakalantas | `Lakalantas` | Direct |
| Per dokter umum | `Per Dokter Umum` | Direct |
| Per dokter IGD | `Per Dokter IGD` | Direct |
| Ranap per bulan | `Per Bulan` (the second occurrence in the Ranap block) | Direct/ambiguous duplicate label |
| Ranap per ruang | `Per Ruang` | Direct |
| Rekap per dokter | `Per Dokter` | Direct |
| Dokter APS | `Dokter APS` | Direct |
| Indikasi pulang per kelas | `Indikasi Pulang Per Kelas` | Direct |
| Indikasi pulang per ruang | `Indikasi Pulang Per Ruang` | Direct |
| Sebab pulang APS | `Sebab Pulang APS` | Direct |
| Rekap per kelas/spesialis | `Per Kelas`, `Per Spesialis` | Expanded/split |
| Kematian | `Kematian` | Direct |

The duplicate current labels `Per Bulan`, `Per Dokter`, and similar generic labels need route/title verification. A current report catalogue should include stable route names, report purpose, source tables, filters, and export behavior so that staff do not have to infer meaning from a generic label.

### G6. Current report additions not described in the manual

The following current report links are not present in the 2018 manual or are materially more specific than its sections:

- `Monitor Resep`, `Waktu`, `Register Lab`, `Register Radiologi`, `Register Cancer`, `Register IBS`.
- `PTM RAJAL V2`, `PTM RANAP V2`, `Rekap A.K. Rajal`, `Rekap A.K. Ranap`.
- `Rekap HAI's 2`, `Register Insiden`, `Rekap Dirujuk`, `Register Filing`, `Rekap ISPA`, `eKin CPPT`.
- Additional disease/action/doctor splits: `Penyakit Rawat Jalan`, `Dokter RJ`, `Penyakit Rawat Inap`, `Tindakan RJ`, `Dokter RI`, `Tindakan RI`.
- RL 3.1, RL 3.3–3.19 and RL 4.1–4.3, plus `RL4A Sebab` and `RL4B Sebab` as separate links.
- `STPRS-RI2`, `STPRS-RJ2`.
- `Per Kelas`, `Per Spesialis`, `Status Pasien Dirawat`, `Rekap Monitor Resep`.

## H. Apotek

### Manual baseline

The manual documents Apotek Rawat Jalan, Apotek Rawat Inap, Apotek Pasien Luar, Distribusi Obat, Stock Opname, Laporan Posisi Stock, Laporan Distribusi Obat, Laporan Obat Masuk, Laporan Pemakaian Obat, Pengeluaran Rajal, Pengeluaran Ranap and Retur Bagian. It describes prescription entry, labels, stock movement, distribution to units and returns from units.

### Current menu reconciliation

| Manual item | Current menu | Status |
|---|---|---|
| Apotek Rawat Jalan | `Apotek Rawat Jalan` | Direct |
| Apotek Rawat Inap | `Apotek Rawat Inap` | Direct |
| Apotek Pasien Luar | `Apotek Pasien Luar` | Direct |
| Distribution | `Distribusi Obat` | Direct |
| Stock opname | `Stock Opname` | Direct |
| Stock position/distribution/incoming/usage reports | Same named reports | Direct |
| Pengeluaran Rajal/Ranap | Same named reports | Direct |
| Retur Bagian | `Retur Bagian` | Direct |
| IGD pharmacy | `Apotek IGD` | Current addition |
| Outside expenditure | `Pengeluaran Luar` | Current addition |
| Prescription history | `Riwayat Resep` | Current addition |
| Trolley RJ/RI | `Trolley RJ`, `Trolley RI` | Current additions |
| Patient returns / generic expenditure | `Laporan Retur Px`, `Pengeluaran` | Current additions |

## I. GF (Gudang Farmasi)

### Manual baseline

The manual covers drug master, incoming purchases, distribution, stock opname, supplier returns, section returns, stock-position/distribution/incoming reports, perpetual reports and stock cards.

### Current menu reconciliation

The current inventory directly contains all 11 manual GF functions: `Obat`, `Obat Masuk`, `Distribusi Obat`, `Stock Opname`, `Retur Supplier`, `Retur Bagian`, `Laporan Posisi Stock`, `Laporan Distribusi Obat`, `Laporan Obat Masuk`, `Laporan Perpetual`, and `Kartu Stock`.

Current additions/variants are:

- `Obat Masuk 2`, `Obat ED`, `Edit Transaksi`.
- `Laporan StockOpname`, `Distribusi Antar Depo`.
- `Po/Pemesanan Obat`.
- `Laporan Buffer Stok Obat`, `Buffer Stock Obat`.
- `Laporan Perpetual Per Obat`, `Perpetual Per Obat`.
- `Blood Stocks`, `Stock Opname Awal`.

The manual does not explain batch/expiry controls, inter-depot movement, purchase-order lifecycle, blood-stock governance, adjustment approval, or the audit trail for edits.

## J. Kasir

### Manual baseline

The manual documents RJ, IGD and RI payment/billing, other transactions, revenue, revenue by service, receivables, deposits, detail Rajal/Ranap and medical services. It describes searching for a patient, selecting the transaction, changing unpaid to paid, choosing print outputs, saving and printing.

### Current menu reconciliation

All 12 named manual cashier functions are visible today: `Rawat Jalan`, `Rawat Inap`, `Transaksi Lain`, `Detail Rajal`, `Detail Ranap`, `Pendapatan`, `Pendapatan Ranap`, `Pendapatan Rajal`, `Pendapatan IGD`, `Jasa Medis`, `Piutang`, and `Setoran`.

Current additions are `Pendapatan Lain`, `PENDAPATAN UNIT`, `TERIMA SETORAN`, `Pendapatan Tindakan`, `Jurnal Otomatis`, `Laporan Tagihan`, and `Tagihan 2`.

The manual is silent on refunds, voids, reversals, approvals, cash reconciliation, journal posting, payer settlement, role separation between cashier and finance, and whether the teaching system allows safely simulated payments.

## K. ManajemenData and administration

### Manual baseline

The manual documents Group, Pengguna, Backup & Restore, Profile, Menu, Staff Medis, Target Rajal, Template Tanda Tangan, Tempat Tidur, Data Cara Bayar, Tarif, Poliklinik, Supplier, Group Komponen Biaya, Komponen Biaya, Bank and Diskon Apotek.

### Current mapping

| Manual administration function | Current menu | Status |
|---|---|---|
| Group | `Group` | Direct |
| Users | `Pengguna` | Direct |
| Backup & Restore | No `Backup & Restore` link in captured current menu | Not visible / needs vendor confirmation |
| Profile | `Settings` | Renamed/expanded; current Settings is much broader |
| Menu | `Menu` | Direct |
| Staff Medis | `Staff Medis` | Direct |
| Target Rajal | `Target Rajal` | Direct |
| Signature templates | `Template Tanda Tangan` | Direct |
| Beds/rooms | `Bangsal` (plus current public bed views) | Renamed/expanded; manual's `Tempat Tidur` label is not exact |
| Payment methods | `Data Cara Bayar` | Direct |
| Tariffs | `Tarif` | Direct |
| Polyclinics | `Unit & Poliklinik` | Renamed/expanded |
| Laboratory master | `Pemeriksaan Lab` | Current addition |
| Radiology master | `Pemeriksaan Radiologi` | Current addition |
| Printer/service | `Printer & Service` | Current addition |
| Supplier | `Supplier` | Direct |
| Cost-component groups | `Group Komponen Biaya` | Direct |
| Cost components | `Komponen Biaya` | Direct |
| Bank | `Bank` | Direct |
| Pharmacy discounts | `Diskon Apotek` | Direct |

Current administrative/master-data additions are:

- `Settings` (including integration and operational settings), `Ref Clinical Pathway`, `Kelengkapan RI`.
- `Pekerjaan`, `Pendidikan`, `Suku`, `Bahasa`, `Instrumen`.
- `DOC EMR`, `DOC EMR 2`, `DATA MONITOR FARMASI`, `Depo`.
- `Penyakit Menular`, `Pasien`, `Kelas`, `Diag Keperawatan`, `W2`, `Odontogram`.
- `Log Activity`, `LOG ESIGN`, `Target Pendapatan`, `Log Bridging`.
- `Group IBS`, `Paket Obat`, `Specimen`, `Logsatset`, `logsatsetri`, `Alat Medis`.

The current Settings form is a major expansion beyond the manual's profile screen. It includes integration, clinical, finance, PDF/FTP, TTE, SatuSehat, LIS/PACS, messaging and AI-related configuration surfaces. The 2018 manual has no field-level map, secret-handling guidance, change control, audit model, or recovery procedure for these settings.

The manual's Backup & Restore description is conceptual only: it says backup copies data and restore returns the system to a prior point. It does not specify backup location, encryption, retention, RPO/RTO, restore testing, responsible person, vendor access, or whether the current installation exposes the feature. This is a high-priority vendor documentation gap.

## L. Current categories with no manual baseline

These categories or functions are not meaningfully documented in the 2018 manual:

### L1. IoT

`IoT > Temperature` is current-only. The manual does not describe sensor registration, device identity, time-series retention, alert thresholds, integration failures, or calibration/audit requirements.

### L2. Farmasi IBS

`Farmasi IBS > IBS` is current-only. The manual does not describe operating-room pharmacy workflows, stock ownership, issue/return, or linkage to operation, billing and clinical records.

### L3. Help / current manual book

`Help > Manual Book` is a current documentation link. The downloaded manual is the linked historical guide captured during assessment. Its 2018 date and missing current modules make it insufficient as the sole current operating manual.

### L4. SatuSehat, EMR IPP, iDRG and newer integration surfaces

SatuSehat RJ, EMR IPP RJ/RI, iDRG RJ/RI, claim-plafon, logsatset, LOG ESIGN, Log Bridging, TTE/signature configuration, and the newer BPJS/integration surfaces are not explained by the manual. These require current workflow and technical documentation.

## M. End-to-end workflow coverage derived from the manual

The manual implies the following core transaction chain:

```text
Login/shift
  -> patient registration (IGD/RJ/RI)
  -> queue / visit / referral / payer / SEP
  -> clinical examination and actions
  -> diagnostics / operation / nutrition as applicable
  -> medical record completion and coding
  -> pharmacy and inventory movements
  -> billing / cashier / deposit / revenue
  -> claims and monitoring
  -> reporting / filing / management control
```

The current system adds branches and feedback loops:

```text
registration -> assessment/triage -> EMR IPP -> SatuSehat / bridging logs
clinical services -> iDRG / plafon / claims -> correction or resubmission
operation -> IBS pharmacy / blood stock / reports
pharmacy -> trolley / depot / stock / returns / perpetual / buffer
finance -> auto journal / unit revenue / tagihan / settlement
all modules -> activity, e-sign, bridging, IoT or other operational logs
```

The current application should therefore be documented as multiple linked workflows, not as isolated menu descriptions. At minimum, the vendor must provide field and state transitions for:

1. Patient master and duplicate/merge handling.
2. Registration, queue, appointment and encounter lifecycle.
3. IGD triage, assessment, clinical care and disposition.
4. RJ clinical encounter, referral and follow-up.
5. RI admission, room/bangsal, transfer, daily care and discharge.
6. Laboratory, radiology, pathology, microbiology, blood bank and operation.
7. Pharmacy prescription, dispensing, trolley/depot, distribution, returns and stock.
8. Medical record completion, filing and release/printing.
9. Billing, payment, receivables, deposits, revenue and automatic journals.
10. BPJS/VClaim/Antrol, SatuSehat, LIS/PACS, E-Klaim/iDRG and other bridges.
11. Reporting, RL, statutory outputs and data export.
12. Users/groups, master data, settings, audit, e-signature, backup/restore and vendor support.

## N. Documentation gaps requiring vendor confirmation

The current manual-to-menu reconciliation leaves these questions open:

- What is the current release/version and change history since 2018?
- Which of the 268 visible menu items are enabled, tested, synthetic-only, or not used at UEU?
- Which current routes are the supported replacements for manual screens and which are legacy duplicates?
- What is the authoritative role/permission matrix, including the intentional combined lecturer-administrator account and the student group?
- Which records are synthetic, seeded, historical, or real-like; what data may students view or alter?
- What is the canonical patient/encounter/medical-record/billing/claim data model and identifier crosswalk?
- What are the state machines for registration, examination, discharge, pharmacy, billing, claims and integrations?
- What are the API contracts, authentication model, retry/idempotency behavior and failure handling for BPJS, SatuSehat, Antrol, LIS, PACS, E-Klaim/iDRG, WhatsApp and TTE?
- Where are backups stored, how are they encrypted, how long are they retained, and when was a restore last tested?
- Which audit logs are immutable, who can view them, and do configuration edits, data changes and exports generate auditable events?
- How are secrets handled in Settings, and what is the rotation/incident procedure?
- What is the supported process for printer, PDF, TTE, queue display and public-display deployment?
- Which manual is current, and when will the Help > Manual Book be updated for v2/v3, EMR IPP, SatuSehat, iDRG, IoT and IBS?

## Bottom line

The vendor system is a full SIMRS platform in visible scope, not only an RMIK/registration application. The 2018 manual covers the historical core but under-documents the current platform by a wide margin. `MODULE_INVENTORY.md` should be treated as the current menu catalogue; this file supplies the historical workflow crosswalk and identifies the current-only domains. A complete technical assessment still requires route-level workflow tracing, data/integration evidence, current vendor documentation and controlled synthetic test cases for every module family.
