# Complete Menu Route Audit

Date: 2026-08-21

Scope: every menu visible to the authorized combined lecturer–administrator account. This is a structural, read-only inventory. No search was submitted, no record was changed, and no table cell or field value was retained.

## Reconciliation

- Visible menu items: **268** across **13** categories.
- Same-origin destinations loaded structurally: **265**.
- External destination deliberately not opened: **1** (Display Admisi).
- Direct-navigation exceptions: **2**. Log Activity had already been observed as an empty dialog from the application shell; Manual Book is the separately downloaded 103-page PDF.
- Every visible menu item is accounted for below.

## Evidence meaning

- **Loaded** means the destination rendered sufficiently to inspect route, form, and table structure; it does not prove a complete or working transaction.
- **Menu-only / empty structure** means the function is present but requires controlled synthetic transactions or vendor evidence to prove behavior.
- Field names and table headings are retained; field values and data rows are not.

## pendaftaran (5)

| Menu | Route | Status | Forms / actions | Structural indicators |
|---|---|---|---|---|
| Rawat Inap | /pendaftaran/rawatinap | loaded | GET /pendaftaran/rawatinap; POST /pendaftaran/rawatinap/processForm; POST /pendaftaran/rawatinap/grid | 14 inputs; 0 tables; fields: asal, q, clinic_id, payment_type_id, continue_id, date_start, date_end, address, province_id, district_id |
| IGD | /pendaftaran/ugd | loaded | POST /pendaftaran/ugd; POST /pendaftaran/ugd/riwayatsep; POST /pendaftaran/ugd/riwayatgrid | 143 inputs; 10 tables; fields: penanggungjawab_name, penanggungjawab_identity_number, penanggungjawab_telp, penanggungjawab_address, family_relationship_id, husband, wife, father, mother, nik |
| Rawat Jalan | /pendaftaran/rawatjalan | loaded | GET /pendaftaran/rawatjalan; POST /pendaftaran/rawatjalan; POST /pendaftaran/rawatjalan/riwayatsep | 224 inputs; 15 tables; fields: schedule_id, penanggungjawab_name, penanggungjawab_identity_number, penanggungjawab_telp, penanggungjawab_address, family_relationship_id, husband, wife, father, mother; headers: No, TTD, Ambil, Insurance No, Nama, Tgl SEP, Jenis Pelayanan, Jenis Pengajuan |
| Display Admisi | /simrs/information/rawatinap | external-not-opened | None visible | No form/table structure visible |
| Rawat Jalan v2 | /pendaftaranv2/rawatjalanv2 | loaded | GET /pendaftaranv2/rawatjalanv2; GET /pendaftaranv2/rawatjalanv2 | 136 inputs; 0 tables; fields: agama, pendidikan, pekerjaan, provinsi, kabupaten, kecamatan, kelurahan |

## pemeriksaan (20)

| Menu | Route | Status | Forms / actions | Structural indicators |
|---|---|---|---|---|
| Assesmen | /pemeriksaan/assesmen | loaded | POST /pemeriksaan/assesmen/processForm; POST /pemeriksaan/assesmen/riwayatGrid; POST /pemeriksaan/assesmen/tindakanDokterGrid | 33 inputs; 1 tables; fields: patient_id, clinic_id, submit, tab, komponen_biaya_group_id, kelas_id, tarif_id, q, package, bagian_id |
| Triage | /pemeriksaan/triage | loaded | POST /pemeriksaan/triage/processForm; POST /pemeriksaan/triage/riwayatgrid; POST /pemeriksaan/triage/visitegrid | 36 inputs; 0 tables; fields: patient_id, clinic_id, submit, tab, komponen_biaya_group_id, kelas_id, tarif_id, q, date_start, date_end |
| IGD | /pemeriksaan/ugd | loaded | POST /pemeriksaan/ugd/processForm; POST /pemeriksaan/ugd/getRiwayatGrid; POST /pemeriksaan/ugd/visitegrid | 31 inputs; 0 tables; fields: patient_id, clinic_id, submit, tab, komponen_biaya_group_id, kelas_id, tarif_id, q, payment_type_id, date_start |
| Rawat Jalan | /pemeriksaan/rawatjalan | loaded | POST /pemeriksaan/rawatjalan/processForm; POST /pemeriksaan/rawatjalan/riwayatgrid; POST /pemeriksaan/rawatjalan/riwayatlaboratorium | 52 inputs; 0 tables; fields: enable_copy, patient_id, clinic_id, submit, start_date, end_date, tab, komponen_biaya_group_id, kelas_id, tarif_id |
| Rawat Inap | /pemeriksaan/rawatinap | loaded | POST /pemeriksaan/rawatinap/processForm; POST /pemeriksaan/rawatinap/riwayatgrid; POST /pemeriksaan/rawatinap/riwayatlaboratorium | 71 inputs; 0 tables; fields: patient_id, clinic_id, submit, start_date, end_date, tab, komponen_biaya_group_id, kelas_id, tarif_id, q |
| Laboratorium | /pemeriksaan/laboratorium | loaded | POST /pemeriksaan/laboratorium/processForm; POST /pemeriksaan/laboratorium/lainlaingrid; POST /pemeriksaan/laboratorium/DoAddLainlain | 29 inputs; 0 tables; fields: tab, q, komponen_biaya_group_id, kelas_id, tarif_id, submit, rujukan_dari_clinic_id, clinic_id, status_periksa_laboratorium, filter_by_date |
| Radiologi | /pemeriksaan/radiologi | loaded | POST /pemeriksaan/radiologi/processForm; POST /pemeriksaan/radiologi/lainlainGrid; POST /pemeriksaan/radiologi/DoAddPrescription | 28 inputs; 0 tables; fields: tab, komponen_biaya_group_id, kelas_id, tarif_id, q, submit, patient_id, clinic_id, rujukan_dari_clinic_id, status_periksa_radiologi |
| Gizi | /pemeriksaan/gizi | loaded | POST /pemeriksaan/gizi/processForm; POST /pemeriksaan/gizi/lainLainGrid; POST /pemeriksaan/gizi/doAddLainlain | 11 inputs; 0 tables; fields: tab, komponen_biaya_group_id, kelas_id, tarif_id, q, submit, bangsal, payment_type_id, date |
| Operasi | /pemeriksaan/operasi | loaded | POST /pemeriksaan/operasi/processForm; POST /pemeriksaan/operasi/lainlaingrid; POST /pemeriksaan/operasi/DoAddLainlain | 16 inputs; 0 tables; fields: tab, komponen_biaya_group_id, kelas_id, tarif_id, q, submit, patient_id, clinic_id, ruang_name, date_start |
| Operasi v3 | /pemeriksaanv3/operasi | loaded | GET /pemeriksaanv3/operasi | 70 inputs; 3 tables; headers: No., Ruang, No. RM, Nama, Sex, Tgl, Operator, Anesthesist |
| Rehabilitasi Medis | /pemeriksaan/fisioterapi | loaded | POST /pemeriksaan/fisioterapi/processForm; POST /pemeriksaan/fisioterapi/riwayatgrid; POST /pemeriksaan/fisioterapi/tindakandoktergrid | 30 inputs; 0 tables; fields: patient_id, clinic_id, submit, tab, komponen_biaya_group_id, kelas_id, tarif_id, q, date_start, date_end |
| Instalasi Rehabilitasi Medik 2 | /pemeriksaan/fisioterapi2 | loaded | POST /pemeriksaan/fisioterapi2/processForm; POST /pemeriksaan/fisioterapi2/riwayatgrid; POST /pemeriksaan/fisioterapi2/tindakandoktergrid | 33 inputs; 0 tables; fields: patient_id, clinic_id, submit, tab, komponen_biaya_group_id, kelas_id, tarif_id, q, date_start, date_end |
| Terapi Wicara | /pemeriksaan/terapiwicara | loaded | POST /pemeriksaan/terapiwicara/processForm; POST /pemeriksaan/terapiwicara/riwayatgrid; POST /pemeriksaan/terapiwicara/tindakandoktergrid | 30 inputs; 0 tables; fields: patient_id, clinic_id, submit, tab, komponen_biaya_group_id, kelas_id, tarif_id, q, date_start, date_end |
| Okupasi Terapi | /pemeriksaan/okupasiterapi | loaded | POST /pemeriksaan/okupasiterapi/processForm; POST /pemeriksaan/okupasiterapi/riwayatgrid; POST /pemeriksaan/okupasiterapi/tindakandoktergrid | 30 inputs; 0 tables; fields: patient_id, clinic_id, submit, tab, komponen_biaya_group_id, kelas_id, tarif_id, q, date_start, date_end |
| Lab PA | /pemeriksaan/laboratoriumpa | loaded | POST /pemeriksaan/laboratoriumpa/processForm; POST /pemeriksaan/laboratoriumpa/lainlaingrid; POST /pemeriksaan/laboratoriumpa/DoAddLainlain | 22 inputs; 0 tables; fields: do_print, tab, q, komponen_biaya_group_id, kelas_id, tarif_id, submit, clinic_id, date_start, date_end |
| Lab Mikro | /pemeriksaan/laboratoriummikro | loaded | POST /pemeriksaan/laboratoriummikro/processForm; POST /pemeriksaan/laboratoriummikro/lainlaingrid; POST /pemeriksaan/laboratoriummikro/DoAddLainlain | 21 inputs; 0 tables; fields: tab, q, komponen_biaya_group_id, kelas_id, tarif_id, submit, clinic_id, date_start, date_end |
| Bank Darah | /pemeriksaan/bankdarah | loaded | POST /pemeriksaan/bankdarah/processForm; POST /pemeriksaan/bankdarah/lainlainGrid; POST /pemeriksaan/bankdarah/DoAddLainlain | 22 inputs; 0 tables; fields: tab, q, komponen_biaya_group_id, kelas_id, tarif_id, submit, clinic_id, date_start, date_end, batal |
| Jenazah | /pemeriksaan/jenazah | loaded | POST /pemeriksaan/jenazah/processForm; POST /pemeriksaan/jenazah/lainlainGrid; POST /pemeriksaan/jenazah/DoAddLainlain | 23 inputs; 0 tables; fields: tab, q, komponen_biaya_group_id, kelas_id, tarif_id, submit, clinic_id, date_start, date_end, batal |
| Rawat Inap v2 | /pemeriksaanv3/rawatinap | loaded | GET /pemeriksaanv3/rawatinap | 65 inputs; 1 tables; headers: REG, No. RM, Nama, Jatuh, Alergi, PPI, C19, Triage |
| Ambulance | /pemeriksaan/ambulance | loaded | POST /pemeriksaan/ambulance/processForm; POST /pemeriksaan/ambulance/lainlainGrid; POST /pemeriksaan/ambulance/DoAddLainlain | 23 inputs; 0 tables; fields: tab, q, komponen_biaya_group_id, kelas_id, tarif_id, submit, clinic_id, date_start, date_end, batal |

## rm (7)

| Menu | Route | Status | Forms / actions | Structural indicators |
|---|---|---|---|---|
| Rawat Jalan | /rm/rawatjalan | loaded | POST /rm/rawatjalan/process_form; POST /rm/rawatjalan/grid; POST /rm/rawatjalan/riwayatgrid | 15 inputs; 0 tables; fields: q, clinic_id, admission_type_id, payment_type_id, continue_id, status_klaim, limit, jenis_tanggal, date_start, date_end |
| Rawat Inap | /rm/rawatinap | loaded | POST /rm/rawatinap/process_form; POST /rm/rawatinap/process_form_rawatjalan; POST /rm/rawatinap/grid | 11 inputs; 0 tables; fields: q, bangsal, inpatient_clinic_id, payment_type_id, status_klaim, bpjs_naik_kelas, limit, jenis_tanggal, date_start, date_end |
| Monitor Klaim | /rm/monitorklaim | loaded | POST /rm/monitorklaim/grid | 4 inputs; 0 tables; fields: date, jenis_pelayanan, status_klaim |
| Filing | /rm/filing | loaded | POST /rm/filing/grid; POST /rm/filing/kunjunganterakhirgrid | 9 inputs; 0 tables; fields: q, date_start, date_end, inout, submit, patient_id, hari_awal, hari_akhir |
| SatuSehat RJ | /rm/satusehatrajal | loaded | POST /rm/satusehatrajal/process_form; POST /rm/satusehatrajal/grid | 6 inputs; 0 tables; fields: q, clinic_id, payment_type_id, date_start, date_end, submit |
| EMR IPP RAWAT JALAN | /rm-ipp/rawatjalan | loaded | POST /rm/rawatjalan/process_form; POST /rm/rawatjalan/grid; POST /rm/rawatjalan/riwayatgrid | 15 inputs; 0 tables; fields: q, clinic_id, admission_type_id, payment_type_id, continue_id, status_klaim, limit, jenis_tanggal, date_start, date_end |
| EMR IPP RAWAT INAP | /rm-ipp/rawatinap | loaded | POST /rm/rawatinap/process_form; POST /rm/rawatinap/process_form_rawatjalan; POST /rm/rawatinap/grid | 11 inputs; 0 tables; fields: q, bangsal, inpatient_clinic_id, payment_type_id, status_klaim, bpjs_naik_kelas, limit, jenis_tanggal, date_start, date_end |

## klaim (6)

| Menu | Route | Status | Forms / actions | Structural indicators |
|---|---|---|---|---|
| Rawat Jalan | /klaim/rawatjalan | loaded | POST /klaim/rawatjalan/processCopyEmrGrid; POST /klaim/rawatjalan/processForm; POST /klaim/rawatjalan/grid | 18 inputs; 0 tables; fields: q, nomor_sep, clinic_id, admission_type_id, paramedic_id, payment_type_id, continue_id, untung_rugi, ordering, limit |
| Rawat Inap | /klaim/rawatinap | loaded | POST /klaim/rawatjalan/processCopyEmrGrid; POST /klaim/rawatinap/processForm; POST /klaim/rawatinap/processFormRawatjalan | 17 inputs; 1 tables; fields: q, bangsal, inpatient_clinic_id, payment_type_id, status_klaim, untung_rugi, bpjs_naik_kelas, limit, nomor_sep, continue_id |
| Rawat Jalan iDRG | /klaim/rawatjalanidrg | loaded | POST /klaim/rawatjalanidrg/processCopyEmrGrid; POST /klaim/rawatjalanidrg/processForm; POST /klaim/rawatjalanidrg/grid | 19 inputs; 0 tables; fields: q, nomor_sep, clinic_id, admission_type_id, paramedic_id, payment_type_id, continue_id, status_klaim, untung_rugi, ordering |
| Rawat Inap iDRG | /klaim/rawatinapidrg | loaded | POST /klaim/rawatinapidrg/processCopyEmrGrid; POST /klaim/rawatinapidrg/processForm; POST /klaim/rawatinapidrg/processFormRawatjalan | 16 inputs; 1 tables; fields: q, bangsal, inpatient_clinic_id, payment_type_id, status_klaim, untung_rugi, bpjs_naik_kelas, limit, nomor_sep, jenis_tanggal |
| Perk Plafon RI | /klaim/rawatinapplafon | loaded | POST /klaim/rawatinapplafon/processCopyEmrGrid; POST /klaim/rawatinapplafon/processForm; POST /klaim/rawatinapplafon/processFormRawatjalan | 17 inputs; 1 tables; fields: q, status_pulang, bangsal, inpatient_clinic_id, payment_type_id, status_klaim, untung_rugi, bpjs_naik_kelas, limit, nomor_sep |
| Monitor Klaim | /klaim/monitorklaim | loaded | POST /klaim/monitorklaim/grid | 5 inputs; 0 tables; fields: date_start, date_stop, jenis_pelayanan, status_klaim |

## laporan (117)

| Menu | Route | Status | Forms / actions | Structural indicators |
|---|---|---|---|---|
| 10 Besar Penyakit Rajal | /statistik/sepuluhbesarrajal | loaded | POST /statistik/sepuluhbesarrajal/grid | 17 inputs; 0 tables; fields: clinic_id, jenis, case, mati, payment_type_id, continue_id, sex, kelompok_umur, limit, exclude |
| 10 Besar Penyakit Ranap | /statistik/sepuluhbesarranap | loaded | POST /statistik/sepuluhbesarranap/grid | 19 inputs; 0 tables; fields: clinic_id, kelas_id, jenis, case, mati, payment_type_id, inpatient_exit_condition_id, sex, kelompok_umur, kelompok_umur_6 |
| 10 Tindakan Rajal | /statistik/sepuluhbesartindakanrajal | loaded | POST /statistik/sepuluhbesartindakanrajal/grid | 14 inputs; 0 tables; fields: data_from, clinic_id, doctor_id, payment_type_id, sex, kelompok_umur, limit, periode, bulan_start, bulan_end |
| 10 Tindakan Ranap | /statistik/sepuluhbesartindakanranap | loaded | POST /statistik/sepuluhbesartindakanranap/grid | 13 inputs; 0 tables; fields: data_from, payment_type_id, doctor_id, sex, kelompok_umur, limit, periode, bulan_start, bulan_end, tahun |
| Pasien | /statistik/pasien | loaded | POST /statistik/pasien/grid | 3 inputs; 0 tables; fields: jenis, chart_type |
| Kunjungan RI | /statistik/kunjunganri | loaded | POST /statistik/kunjunganri/grid | 14 inputs; 0 tables; fields: jenis, doctor_dpjp_id, show_gender, clinic_id, jenis_kunjungan, chart_type, jenis_tanggal, periode, bulan_start, bulan_end |
| Kunjungan RJ | /statistik/kunjungan | loaded | POST /statistik/kunjungan/grid | 26 inputs; 0 tables; fields: jenis, show_gender, sex, marital_status_id, agama_id, education_id, job_id, kewarganegaraan, province_id, district_id |
| Trend | /statistik/trend | loaded | POST /statistik/trend/grid | 14 inputs; 0 tables; fields: jenis, clinic_id, chart_type, periode, minggu_start, minggu_end, bulan_start, bulan_end, tahun_start, tahun_end |
| Monitor Resep | /statistik/monitoringresep | loaded | POST /statistik/monitoringresep/grid | 10 inputs; 0 tables; fields: clinic_id, doctor_id, chart_type, periode, bulan_start, bulan_end, tahun, date_start, date_end |
| Waktu | /statistik/waktu | loaded | POST /statistik/waktu/grid | 15 inputs; 0 tables; fields: jenis, clinic_id, doctor_id, chart_type, periode, minggu_start, minggu_end, bulan_start, bulan_end, tahun_start |
| Kunjungan Terakhir | /laporan/kunjunganterakhir | loaded | POST /laporan/kunjunganterakhir/grid | 10 inputs; 0 tables; fields: patient_id, clinic_id, doctor_id, payment_type_id, periode, bulan, tahun, hari_awal, hari_akhir |
| Register Lab | /laporan/registerlab | loaded | POST /laporan/registerlab/grid | 20 inputs; 0 tables; fields: patient_id, jenis_kunjungan, clinic_id, rujukan_dari_clinic_id, paramedic_sender_id, doctor_id, payment_type_id, sex, admission_type_id, covid19_status |
| Register IGD | /laporan/registerigd | loaded | POST /laporan/registerigd/grid | 13 inputs; 0 tables; fields: jenis_kunjungan, payment_type_id, sex, admission_type_id, continue_id, triage, periode, bulan_awal, bulan_akhir, tahun |
| Register Radiologi | /laporan/registerradiologi | loaded | POST /laporan/registerradiologi/grid | 19 inputs; 0 tables; fields: patient_id, jenis_kunjungan, clinic_id, paramedic_sender_id, doctor_id, payment_type_id, sex, admission_type_id, covid19_status, periode |
| Register Cancer | /laporan/registercancer | loaded | POST /laporan/registercancer/grid | 14 inputs; 0 tables; fields: patient_id, jenis_kunjungan, sex, doctor_id, payment_type_id, admission_type_id, continue_id, periode, bulan, tahun |
| Register Rawat Jalan | /laporan/registerrajal | loaded | POST /laporan/registerrajal/grid | 37 inputs; 0 tables; fields: patient_id, jenis_kunjungan, clinic_id, doctor_id, payment_type_id, bridging_antrol_task_id, filter_waktu_tunggu_layan, sex, cara_daftar, admission_type_id |
| Register Rawat Inap | /laporan/registerranap | loaded | POST /laporan/registerranap/grid | 12 inputs; 0 tables; fields: clinic_id, icd_code, icd_name, source_diagnoses, doctor_id, doctor_dpjp_id, payment_type_id, exit_condition_id, jenis_tanggal, date_start |
| Register IBS | /laporan/registeroperasi | loaded | POST /laporan/registeroperasi/grid | 24 inputs; 0 tables; fields: patient_id, jenis_kunjungan, doctor_id, doctor_anesthetist_id, payment_type_id, sex, admission_type_id, covid19_status, ugd_kasus, ugd_kecelakaan |
| Register Pendaftaran | /laporan/registerpendaftaran | loaded | POST /laporan/registerpendaftaran/grid | 15 inputs; 0 tables; fields: shift, sex, jenis_kunjungan, cara_daftar, clinic_id, doctor_id, payment_type_id, periode, bulan, tahun |
| Rekap JHP | /laporan/rekapjhp | loaded | POST /laporan/rekapjhp/grid | 5 inputs; 0 tables; fields: tanggal_awal, tanggal_akhir, bulan, tahun |
| W2 | /laporan/w2 | loaded | POST /laporan/w2/grid | 7 inputs; 0 tables; fields: periode, minggu, bulan, tahun, hari_awal, hari_akhir |
| A. Kuantitatif Ranap | /laporan/analisiskuantitatifranap | loaded | POST /laporan/analisiskuantitatifranap/grid | 10 inputs; 0 tables; fields: bangsal, doctor_id, jenis_tanggal, periode, bulan, tahun, hari_awal, hari_akhir, order_by |
| Px Masuk Ranap | /laporan/masukranap | loaded | POST /laporan/masukranap/grid | 7 inputs; 0 tables; fields: shift, payment_type_id, hari_awal, jam_awal, hari_akhir, jam_akhir |
| Px Pindah Ranap | /laporan/pindahranap | loaded | POST /laporan/pindahranap/grid | 3 inputs; 0 tables; fields: date_start, date_end |
| Px Pulang | /laporan/pulangranap | loaded | POST /laporan/pulangranap/grid | 6 inputs; 0 tables; fields: admission_type_id, payment_type_id, inpatient_exit_condition_id, date_start, date_end |
| PTM RAJAL | /laporan/ptm | loaded | POST /laporan/ptm/grid | 9 inputs; 0 tables; fields: kelompok_umur, case, periode, bulan_awal, bulan_akhir, tahun, hari_awal, hari_akhir |
| PTM RAJAL V2 | /laporan/Ptmv2 | loaded | POST /laporan/Ptmv2/grid | 9 inputs; 0 tables; fields: kelompok_umur, case, periode, bulan_awal, bulan_akhir, tahun, hari_awal, hari_akhir |
| PTM RANAP | /laporan/ptmranap | loaded | POST /laporan/ptmranap/grid | 7 inputs; 0 tables; fields: periode, bulan_awal, bulan_akhir, tahun, hari_awal, hari_akhir |
| PTM RANAP V2 | /laporan/Ptmranapv2 | loaded | POST /laporan/Ptmranapv2/grid | 8 inputs; 0 tables; fields: kelompok_umur, periode, bulan_awal, bulan_akhir, tahun, hari_awal, hari_akhir |
| A. Kuantitatif Rajal | /laporan/analisiskuantitatifrajal | loaded | POST /laporan/analisiskuantitatifrajal/grid | 8 inputs; 0 tables; fields: clinic_id, doctor_id, periode, bulan, tahun, hari_awal, hari_akhir |
| Rekap A.K. Rajal | /laporan/analisiskuantitatifrajalrekap | loaded | POST /laporan/analisiskuantitatifrajalrekap/grid | 4 inputs; 0 tables; fields: bulan_awal, bulan_akhir, tahun |
| Rekap A.K. Ranap | /laporan/analisiskuantitatifranaprekap | loaded | POST /laporan/analisiskuantitatifranaprekap/grid | 8 inputs; 0 tables; fields: jenis_tanggal, periode, hari_awal, hari_akhir, bulan_awal, bulan_akhir, tahun |
| Evaluasi Keterlambatan | /laporan/evaluasiketerlambatan | loaded | POST /laporan/evaluasiketerlambatan/grid | 4 inputs; 0 tables; fields: bulan_awal, bulan_akhir, tahun |
| Rekap Evaluasi Keterlambatan | /laporan/rekapevaluasiketerlambatan | loaded | POST /laporan/rekapevaluasiketerlambatan/grid | 4 inputs; 0 tables; fields: bulan_awal, bulan_akhir, tahun |
| K. Catatan Perawat | /laporan/kelengkapancatatanperawat | loaded | POST /laporan/kelengkapancatatanperawat/grid | 4 inputs; 0 tables; fields: bulan_awal, bulan_akhir, tahun |
| K. Resume ASKEP | /laporan/kelengkapanresumeaskep | loaded | POST /laporan/kelengkapanresumeaskep/grid | 4 inputs; 0 tables; fields: bulan_awal, bulan_akhir, tahun |
| K. Rekam Medis | /laporan/kelengkapanrekammedis | loaded | POST /laporan/kelengkapanrekammedis/grid | 4 inputs; 0 tables; fields: bulan_awal, bulan_akhir, tahun |
| Perujuk | /laporan/perujuk | loaded | POST /laporan/perujuk/grid | 6 inputs; 0 tables; fields: periode, bulan, tahun, hari_awal, hari_akhir |
| Imunisasi | /laporan/imunisasi | loaded | POST /laporan/imunisasi/grid | 6 inputs; 0 tables; fields: periode, bulan, tahun, hari_awal, hari_akhir |
| Kematian | /laporan/kematian | loaded | POST /laporan/kematian/grid | 8 inputs; 0 tables; fields: periode, bulan_awal, bulan_akhir, tahun, hari_awal, hari_akhir, bangsal |
| Kematian ASKES | /laporan/kematianaskes | loaded | POST /laporan/kematianaskes/grid | 4 inputs; 0 tables; fields: bulan_awal, bulan_akhir, tahun |
| Masih Dirawat | /laporan/masihdirawat | loaded | POST /laporan/masihdirawat/grid | 5 inputs; 0 tables; fields: date, clinic_id, payment_type_id, agama_id |
| Kematian External | /laporan/kematianexternal | loaded | POST /laporan/kematianexternal/grid | 4 inputs; 0 tables; fields: bulan_awal, bulan_akhir, tahun |
| Imunisasi Ranap | /laporan/imunisasiranap | loaded | POST /laporan/imunisasiranap/grid | 6 inputs; 0 tables; fields: periode, bulan, tahun, hari_awal, hari_akhir |
| DATA CARA BAYAR | /laporan/datacarabayar | loaded | POST /laporan/datacarabayar/grid | 12 inputs; 0 tables; fields: periode, hari_awal, hari_akhir, bulan_awal, bulan_akhir, triwulan_awal, triwulan_akhir, tahun, bangsal_id, kelas_id |
| Rekap HAI's | /laporan/rekaphais | loaded | POST /laporan/rekaphais/grid | 5 inputs; 0 tables; fields: clinic_id, bulan_start, bulan_stop, tahun |
| Rekap HAI's 2 | /laporan/rekaphais2 | loaded | POST /laporan/rekaphais2/grid | 5 inputs; 0 tables; fields: clinic_id, bulan_start, bulan_stop, tahun |
| Register Insiden | /laporan/registerinsiden | loaded | POST /laporan/registerinsiden/grid | 6 inputs; 0 tables; fields: periode, bulan, tahun, hari_awal, hari_akhir |
| Register HAI's | /laporan/registerhais | loaded | POST /laporan/registerhais/grid | 7 inputs; 0 tables; fields: bangsal, periode, bulan, tahun, hari_awal, hari_akhir |
| Sebaran Pasien | /laporan/sebaranpasien | loaded | POST /laporan/sebaranpasien/grid | 15 inputs; 0 tables; fields: rajal_ranap, jenis_kunjungan, payment_type_id, chart_type, limit, periode, bulan_awal, bulan_akhir, tahun, hari_awal |
| Px Dirujuk | /laporan/pulangranapdirujuk | loaded | POST /laporan/pulangranapdirujuk/grid | 4 inputs; 0 tables; fields: paramedic_type_id, date_start, date_end |
| Rekap Dirujuk | /laporan/rekapdirujuk | loaded | POST /laporan/Rekapdirujuk/grid | 6 inputs; 0 tables; fields: periode, bulan, tahun, hari_awal, hari_akhir |
| Register Filing | /laporan/registerfiling | loaded | POST /laporan/registerfiling/grid | 10 inputs; 0 tables; fields: patient_id, inout, keperluan, periode, bulan, tahun, hari_awal, hari_akhir, sort_by |
| Rekap ISPA | /laporan/rekapispa | loaded | POST /laporan/rekapispa/grid | 6 inputs; 0 tables; fields: periode, bulan, tahun, hari_awal, hari_akhir |
| eKin CPPT | /laporan/ekincppt | loaded | POST /laporan/Ekincppt/grid | 8 inputs; 0 tables; fields: id_user, name_user, periode, bulan, tahun, hari_awal, hari_akhir |
| Penyakit Rawat Jalan | /indeks/penyakitrajal | loaded | POST /indeks/penyakitrajal/grid | 11 inputs; 0 tables; fields: icd_code, icd_name, case, periode, bulan, tahun, hari_awal, jam_awal, hari_akhir, jam_akhir |
| Dokter RJ | /indeks/dokterrajal | loaded | POST /indeks/dokterrajal/grid | 5 inputs; 0 tables; fields: doctor_id, bulan_awal, bulan_akhir, tahun |
| Penyakit Rawat Inap | /indeks/penyakitranap | loaded | POST /indeks/penyakitranap/grid | 6 inputs; 0 tables; fields: icd_code, icd_name, bulan_awal, bulan_akhir, tahun |
| Tindakan RJ | /indeks/tindakanrawatjalan | loaded | POST /indeks/tindakanrawatjalan/grid | 6 inputs; 0 tables; fields: tindakanrawatjalan_id, tindakanrawatjalan_name, bulan_awal, bulan_akhir, tahun |
| Dokter RI | /indeks/dokterranap | loaded | POST /indeks/dokterranap/grid | 5 inputs; 0 tables; fields: doctor_id, bulan_awal, bulan_akhir, tahun |
| Tindakan RI | /indeks/tindakanrawatinap | loaded | POST /indeks/tindakanrawatinap/grid | 6 inputs; 0 tables; fields: tindakanrawatinap_id, tindakanrawatinap_name, bulan_awal, bulan_akhir, tahun |
| RL 3.1 | /rl/rl31 | loaded | POST /rl/rl31/grid | 6 inputs; 0 tables; fields: periode, bulan, tahun, hari_awal, hari_akhir |
| RL 3.2 | /rl/rl32 | loaded | POST /rl/rl32/grid | 4 inputs; 0 tables; fields: periode, bulan, tahun |
| RL 3.3 | /rl/rl33 | loaded | POST /rl/rl33/grid | 6 inputs; 0 tables; fields: periode, bulan, tahun, hari_awal, hari_akhir |
| RL 3.4 | /rl/rl34 | loaded | POST /rl/rl34/grid | 6 inputs; 0 tables; fields: periode, bulan, tahun, hari_awal, hari_akhir |
| RL 3.5 | /rl/rl35 | loaded | POST /rl/rl35/grid | 6 inputs; 0 tables; fields: periode, bulan, tahun, hari_awal, hari_akhir |
| RL 3.6 | /rl/rl36 | loaded | POST /rl/rl36/grid | 6 inputs; 0 tables; fields: periode, bulan, tahun, hari_awal, hari_akhir |
| RL 3.7 | /rl/rl37 | loaded | POST /rl/rl37/grid | 6 inputs; 0 tables; fields: periode, bulan, tahun, hari_awal, hari_akhir |
| RL 3.8 | /rl/rl38 | loaded | POST /rl/rl38/grid | 6 inputs; 0 tables; fields: periode, bulan, tahun, hari_awal, hari_akhir |
| RL 3.9 | /rl/rl39 | loaded | POST /rl/rl39/grid | 6 inputs; 0 tables; fields: periode, bulan, tahun, hari_awal, hari_akhir |
| RL 3.11 | /rl/rl311 | loaded | POST /rl/rl311/grid | 6 inputs; 0 tables; fields: periode, bulan, tahun, hari_awal, hari_akhir |
| RL 3.10 | /rl/rl310 | loaded | POST /rl/rl310/grid | 6 inputs; 0 tables; fields: periode, bulan, tahun, hari_awal, hari_akhir |
| RL 3.12 | /rl/rl312 | loaded | POST /rl/rl312/grid | 6 inputs; 0 tables; fields: periode, bulan, tahun, hari_awal, hari_akhir |
| RL 3.13 | /rl/rl313 | loaded | POST /rl/rl313/grid | 6 inputs; 0 tables; fields: periode, bulan, tahun, hari_awal, hari_akhir |
| RL 3.14 | /rl/rl314 | loaded | POST /rl/rl314/grid | 6 inputs; 0 tables; fields: periode, bulan, tahun, hari_awal, hari_akhir |
| RL 3.15 | /rl/rl315 | loaded | POST /rl/rl315/grid | 6 inputs; 0 tables; fields: periode, bulan, tahun, hari_awal, hari_akhir |
| RL 3.16 | /rl/rl316 | loaded | POST /rl/rl316/grid | 6 inputs; 0 tables; fields: periode, bulan, tahun, hari_awal, hari_akhir |
| RL 3.17 | /rl/rl317 | loaded | POST /rl/rl317/grid | 4 inputs; 0 tables; fields: periode, tahun, form_rs |
| RL 3.18 | /rl/rl318 | loaded | POST /rl/rl318/grid | 7 inputs; 0 tables; fields: periode, bulan, tahun, hari_awal, hari_akhir, form_rs |
| RL 3.19 | /rl/rl319 | loaded | POST /rl/rl319/grid | 6 inputs; 0 tables; fields: periode, bulan, tahun, hari_awal, hari_akhir |
| RL 4.1 | /rl/rl41 | loaded | POST /rl/rl41/grid | 6 inputs; 0 tables; fields: periode, bulan, tahun, hari_awal, hari_akhir |
| RL 4.2 | /rl/rl42 | loaded | POST /rl/rl42/grid | 6 inputs; 0 tables; fields: periode, bulan, tahun, hari_awal, hari_akhir |
| RL 4.3 | /rl/rl43 | loaded | POST /rl/rl43/grid | 6 inputs; 0 tables; fields: periode, bulan, tahun, hari_awal, hari_akhir |
| RL 5.1 | /rl/rl51 | loaded | POST /rl/rl51/grid | 6 inputs; 0 tables; fields: periode, bulan, tahun, hari_awal, hari_akhir |
| RL 5.2 | /rl/rl52 | loaded | POST /rl/rl52/grid | 6 inputs; 0 tables; fields: periode, bulan, tahun, hari_awal, hari_akhir |
| RL 5.3 | /rl/rl53 | loaded | POST /rl/rl53/grid | 6 inputs; 0 tables; fields: periode, bulan, tahun, hari_awal, hari_akhir |
| RL 4A | /rl/rl4a | loaded | POST /rl/rl4a/grid | 6 inputs; 0 tables; fields: periode, bulan, tahun, hari_awal, hari_akhir |
| RL 4B | /rl/rl4b | loaded | POST /rl/rl4b/grid | 6 inputs; 0 tables; fields: periode, bulan, tahun, hari_awal, hari_akhir |
| RL4A Sebab | /rl/rl4asebab | loaded | POST /rl/rl4asebab/grid | 6 inputs; 0 tables; fields: periode, bulan, tahun, hari_awal, hari_akhir |
| RL4B Sebab | /rl/rl4bsebab | loaded | POST /rl/rl4bsebab/grid | 6 inputs; 0 tables; fields: periode, bulan, tahun, hari_awal, hari_akhir |
| RL 5.4 | /rl/rl54 | loaded | POST /rl/rl54/grid | 8 inputs; 0 tables; fields: exclude, periode, bulan, tahun, hari_awal, hari_akhir, limit |
| STP-RS-RJ | /rl/stprsrj | loaded | POST /rl/stprsrj/grid | 6 inputs; 0 tables; fields: periode, bulan, tahun, hari_awal, hari_akhir |
| STP-RS-RI | /rl/stprsri | loaded | POST /rl/stprsri/grid | 6 inputs; 0 tables; fields: periode, bulan, tahun, hari_awal, hari_akhir |
| STPRS-RI2 | /rl/stprsri2 | loaded | POST /rl/stprsri2/grid | 7 inputs; 0 tables; fields: kelompok_umur, periode, bulan, tahun, hari_awal, hari_akhir |
| STPRS-RJ2 | /rl/stprsrj2 | loaded | POST /rl/stprsrj2/grid | 7 inputs; 0 tables; fields: kelompok_umur, periode, bulan, tahun, hari_awal, hari_akhir |
| Per Hari | /rekapitulasirawatjalan/perhari | loaded | POST /rekapitulasirawatjalan/perhari/grid | 6 inputs; 0 tables; fields: clinic, payment_type_id, rujuk_internal, bulan, tahun |
| Per Bulan | /rekapitulasirawatjalan/perbulan | loaded | POST /rekapitulasirawatjalan/perbulan/grid | 7 inputs; 0 tables; fields: clinic, payment_type_id, rujuk_internal, bulan_awal, bulan_akhir, tahun |
| Per Poliklinik | /rekapitulasirawatjalan/perpoli | loaded | POST /rekapitulasirawatjalan/perpoli/grid | 10 inputs; 0 tables; fields: payment_type, admission_type_id, continue_id, periode, bulan_awal, bulan_akhir, tahun, hari_awal, hari_akhir |
| Per Dokter | /rekapitulasirawatjalan/perdokter | loaded | POST /rekapitulasirawatjalan/perdokter/grid | 7 inputs; 0 tables; fields: payment_type_id, doctor_id, clinic_id, bulan_awal, bulan_akhir, tahun |
| Per Dokter Baru-Lama | /rekapitulasirawatjalan/perdokterbarulama | loaded | POST /rekapitulasirawatjalan/perdokterbarulama/grid | 4 inputs; 0 tables; fields: bulan_awal, bulan_akhir, tahun |
| Pasien DOA-DOS | /rekapitulasirawatjalan/doados | loaded | POST /rekapitulasirawatjalan/doados/grid | 4 inputs; 0 tables; fields: bulan_awal, bulan_akhir, tahun |
| Pasien Dirujuk Keluar | /rekapitulasirawatjalan/rujukkeluar | loaded | POST /rekapitulasirawatjalan/rujukkeluar/grid | 5 inputs; 0 tables; fields: paramedic_type_id, bulan_awal, bulan_akhir, tahun |
| Lakalantas | /rekapitulasirawatjalan/lakalantas | loaded | POST /rekapitulasirawatjalan/lakalantas/grid | 4 inputs; 0 tables; fields: bulan_awal, bulan_akhir, tahun |
| Per Dokter Umum | /rekapitulasirawatjalan/perdokterbarulamaumum | loaded | POST /rekapitulasirawatjalan/perdokterbarulamaumum/grid | 4 inputs; 0 tables; fields: bulan_awal, bulan_akhir, tahun |
| Per Dokter IGD | /rekapitulasirawatjalan/perdokterbarulamaigd | loaded | POST /rekapitulasirawatjalan/perdokterbarulamaigd/grid | 4 inputs; 0 tables; fields: bulan_awal, bulan_akhir, tahun |
| Per Bulan | /rekapitulasirawatinap/perbulan | loaded | POST /rekapitulasirawatinap/perbulan/grid | 12 inputs; 0 tables; fields: payment_type_id, clinic_id, kelas_id, jenis_kunjungan, sex, periode, hari_awal, hari_akhir, bulan_awal, bulan_akhir |
| Per Ruang | /rekapitulasirawatinap/perruang | loaded | POST /rekapitulasirawatinap/perruang/grid | 10 inputs; 0 tables; fields: payment_type, jenis_kunjungan, groupingnya, periode, bulan_awal, bulan_akhir, tahun, hari_awal, hari_akhir |
| Per Kelas | /rekapitulasirawatinap/perkelas | loaded | POST /rekapitulasirawatinap/perkelas/grid | 4 inputs; 0 tables; fields: bulan_awal, bulan_akhir, tahun |
| Per Spesialis | /rekapitulasirawatinap/perspesialis | loaded | POST /rekapitulasirawatinap/perspesialis/grid | 4 inputs; 0 tables; fields: bulan_awal, bulan_akhir, tahun |
| Per Dokter | /rekapitulasirawatinap/perdokter | loaded | POST /rekapitulasirawatinap/perdokter/grid | 5 inputs; 0 tables; fields: jenis, bulan_awal, bulan_akhir, tahun |
| Dokter APS | /rekapitulasirawatinap/dokterpulangaps | loaded | POST /rekapitulasirawatinap/dokterpulangaps/grid | 4 inputs; 0 tables; fields: bulan_awal, bulan_akhir, tahun |
| Indikasi Pulang Per Kelas | /rekapitulasirawatinap/indikasipulang | loaded | POST /rekapitulasirawatinap/indikasipulang/grid | 4 inputs; 0 tables; fields: bulan_awal, bulan_akhir, tahun |
| Indikasi Pulang Per Ruang | /rekapitulasirawatinap/indikasipulangperruang | loaded | POST /rekapitulasirawatinap/indikasipulangperruang/grid | 4 inputs; 0 tables; fields: bulan_awal, bulan_akhir, tahun |
| Sebab Pulang APS | /rekapitulasirawatinap/sebabaps | loaded | POST /rekapitulasirawatinap/sebabaps/grid | 4 inputs; 0 tables; fields: bulan_awal, bulan_akhir, tahun |
| Kematian | /rekapitulasirawatinap/kematian | loaded | POST /rekapitulasirawatinap/kematian/grid | 4 inputs; 0 tables; fields: bulan_awal, bulan_akhir, tahun |
| Status Pasien Dirawat | /rekapitulasirawatinap/statuspasiendirawat | loaded | POST /rekapitulasirawatinap/statuspasiendirawat/grid | 9 inputs; 1 tables; fields: bulan, tahun, status_kunjungan, bangsal_awal, bangsal_akhir, sex, keadaan, validasi; headers: Catatan Penting Keterangan, Tanggal keluar RS belum diisi, Tanggal keluar Ruang belum diisi, DPJP 1 Tidak terisi, Tanggal & Waktu Keluar RS < Ruang, TT tidak diisi |
| Rekap Monitor Resep | /laporanfarmasi/rekapmonitor | loaded | POST /laporanfarmasi/rekapmonitor/grid | 7 inputs; 0 tables; fields: periode, bulan_awal, bulan_akhir, tahun, hari_awal, hari_akhir |

## bpjs (2)

| Menu | Route | Status | Forms / actions | Structural indicators |
|---|---|---|---|---|
| Rawat Jalan | /bpjs/rawatjalan | loaded | POST /bpjs/rawatjalan/process_form; POST /bpjs/rawatjalan/grid; POST /bpjs/rawatjalan/riwayatgrid | 9 inputs; 0 tables; fields: clinic_id, payment_type_id, q, date_start, date_end, submit, patient_id |
| Rawat Inap | /bpjs/rawatinap | loaded | POST /bpjs/rawatinap/process_form; POST /bpjs/rawatinap/process_form_rawatjalan; POST /bpjs/rawatinap/grid | 8 inputs; 0 tables; fields: q, bangsal, inpatient_clinic_id, payment_type_id, jenis_tanggal, date_start, date_end, submit |

## apotek (20)

| Menu | Route | Status | Forms / actions | Structural indicators |
|---|---|---|---|---|
| Apotek IGD | /apotek-igd/rawatjalan | loaded | POST /apotek/rawatjalan/gridPrescription; POST /apotek/rawatjalan/gridPrescriptionTemp; POST /apotek/rawatjalan/processFormMonitoring | 23 inputs; 0 tables; fields: q, apotek_queue_number, clinic_id, payment_type_id, filter_sort, sort, date_start, date_end, batal, submit |
| Apotek Rawat Jalan | /apotek/rawatjalan | loaded | POST /apotek/rawatjalan/gridPrescription; POST /apotek/rawatjalan/gridPrescriptionTemp; POST /apotek/rawatjalan/processFormMonitoring | 23 inputs; 0 tables; fields: q, apotek_queue_number, clinic_id, payment_type_id, filter_sort, sort, date_start, date_end, batal, submit |
| Apotek Rawat Inap | /apotek/rawatinap | loaded | POST /apotek/rawatinap/gridprescription; POST /apotek/rawatinap/gridprescriptiontemp; POST /apotek/rawatinap/processFormMonitoring | 16 inputs; 0 tables; fields: q, bangsal_id, inpatient_clinic_id, filter_sort, date_start, date_end, submit, tab, komponen_biaya_group_id, kelas_id |
| Apotek Pasien Luar | /apotek/antrian | loaded | POST /apotek/antrian/processForm; POST /apotek/antrian/grid; POST /apotek/antrian/lainlaingrid | 10 inputs; 0 tables; fields: q, date_start, date_end, submit, tab, komponen_biaya_group_id, kelas_id, tarif_id |
| Distribusi Obat | /apotek/distribusiobat | loaded | POST /apotek/distribusiobat/processForm; POST /apotek/distribusiobat/grid | 4 inputs; 0 tables; fields: q, depo_asal_id, depo_tujuan_id |
| Stock Opname | /apotek/stockopname | loaded | POST /apotek/stockopname/processForm; POST /apotek/stockopname/grid | 3 inputs; 0 tables; fields: q, bagian_id |
| Laporan Posisi Stock | /apotek/laporanposisistock | loaded | POST /apotek/laporanposisistock/grid | 4 inputs; 0 tables; fields: bagian_id, group_by, kode_obat |
| Laporan Distribusi Obat | /apotek/laporandistribusi | loaded | POST /apotek/laporandistribusi/grid | 13 inputs; 0 tables; fields: code, name, from_bagian_id, to_bagian_id, group_by, group_by2, group_by3, periode, bulan, tahun |
| Laporan Obat Masuk | /apotek/laporanobatmasuk | loaded | POST /apotek/laporanobatmasuk/grid | 7 inputs; 0 tables; fields: group_by, periode, bulan, tahun, hari_awal, hari_akhir |
| Laporan Pemakaian Obat | /apotek/laporanpemakaian | loaded | POST /apotek/laporanpemakaian/grid | 17 inputs; 0 tables; fields: bagian_id, jenis, jenis_resep, type, drug_golongan_id, drug_jenis_id, drug_group_id, cara_pakai, formularium, high_alert_medication |
| Pengeluaran Rajal | /apotek/laporanpengeluaranrajal | loaded | POST /apotek/laporanpengeluaranrajal/grid | 15 inputs; 0 tables; fields: clinic_id, doctor_id, payment_type_id, jenis_kwitansi, type, drug_group_id, drug_jenis_id, prb, drug_golongan_id, code |
| Pengeluaran Luar | /apotek/laporanpengeluaranluar | loaded | POST /apotek/laporanpengeluaranluar/grid | 8 inputs; 0 tables; fields: code, name, drug_group_id, drug_jenis_id, drug_golongan_id, date_start, date_end |
| Pengeluaran Ranap | /apotek/laporanpengeluaranranap | loaded | POST /apotek/laporanpengeluaranranap/grid | 18 inputs; 0 tables; fields: payment_type_id, clinic_id, doctor_id, type, drug_group_id, drug_jenis_id, drug_golongan_id, cara_pakai, formularium, high_alert_medication |
| Retur Bagian | /apotek/returbagian | loaded | POST /apotek/returbagian/processForm; POST /apotek/returbagian/grid | 2 inputs; 0 tables; fields: q |
| Riwayat Resep | /apotek/riwayatresep | loaded | POST /apotek/riwayatresep/grid | 9 inputs; 0 tables; fields: patient_id, patient_name, code, name, date_start, date_end, order_by, respnol |
| Trolley RJ | /apotek/trolirj | loaded | POST /apotek/trolirj/gridprescription; POST /apotek/trolirj/gridprescriptiontemp; POST /apotek/trolirj/processFormMonitoring | 13 inputs; 0 tables; fields: q, clinic_id, payment_type_id, filter_sort, date_start, date_end, submit, tab, komponen_biaya_group_id, kelas_id |
| Trolley RI | /apotek/troliri | loaded | POST /apotek/troliri/gridprescription; POST /apotek/troliri/gridprescriptiontemp; POST /apotek/troliri/processForm | 11 inputs; 0 tables; fields: q, inpatient_clinic_id, date_start, date_end, submit, tab, komponen_biaya_group_id, kelas_id, tarif_id |
| Laporan Retur Px | /apotek/laporanretur | loaded | POST /apotek/laporanretur/grid | 7 inputs; 0 tables; fields: bagian_id, jenis, drug_golongan_id, periode, bulan, tahun |
| Pengeluaran | /apotek/laporanpengeluaran | loaded | POST /apotek/laporanpengeluaran/grid | 19 inputs; 0 tables; fields: bagian_id, payment_type_id, doctor_id, type, drug_golongan_id, drug_jenis_id, drug_group_id, cara_pakai, formularium, high_alert_medication |
| Kartu Stock | /apotek/kartustock | loaded | POST /apotek/kartustock/grid | 7 inputs; 0 tables; fields: bagian_id, code, name, batch_number, hari_awal, hari_akhir |

## gf (23)

| Menu | Route | Status | Forms / actions | Structural indicators |
|---|---|---|---|---|
| Obat | /gudangfarmasi/obat | loaded | POST /gudangfarmasi/obat/process_form; POST /gudangfarmasi/obat/grid | 4 inputs; 0 tables; fields: q, active, group_by |
| Obat Masuk | /gudangfarmasi/obatmasuk | loaded | POST /gudangfarmasi/obatmasuk/process_form; POST /gudangfarmasi/obatmasuk/grid | 2 inputs; 0 tables; fields: q |
| Distribusi Obat | /gudangfarmasi/distribusiobat | loaded | POST /gudangfarmasi/distribusiobat/process_form; POST /gudangfarmasi/distribusiobat/grid | 2 inputs; 0 tables; fields: q |
| Stock Opname | /gudangfarmasi/stockopname | loaded | POST /gudangfarmasi/stockopname/process_form; POST /gudangfarmasi/stockopname/grid | 3 inputs; 0 tables; fields: q, bagian_id |
| Retur Supplier | /gudangfarmasi/retursupplier | loaded | POST /gudangfarmasi/retursupplier/process_form; POST /gudangfarmasi/retursupplier/grid | 2 inputs; 0 tables; fields: q |
| Retur Bagian | /gudangfarmasi/returbagian | loaded | POST /gudangfarmasi/returbagian/process_form; POST /gudangfarmasi/returbagian/grid | 2 inputs; 0 tables; fields: q |
| Laporan Posisi Stock | /gudangfarmasi/laporanposisistock | loaded | POST /gudangfarmasi/laporanposisistock/grid | 17 inputs; 0 tables; fields: bagian_id[], stock_tanggal, type, drug_golongan_id, sediaan, drug_group_id, drug_jenis_id, group_by, periode, bulan |
| Laporan Distribusi Obat | /gudangfarmasi/laporandistribusi | loaded | POST /gudangfarmasi/laporandistribusi/grid | 10 inputs; 0 tables; fields: code, name, bagian_id, group_by, periode, bulan, tahun, hari_awal, hari_akhir |
| Laporan Obat Masuk | /gudangfarmasi/laporanobatmasuk | loaded | POST /gudangfarmasi/laporanobatmasuk/grid | 11 inputs; 0 tables; fields: code, name, supplier_id, formularium, type, drug_golongan_id, jenis_tanggal, tgl_start, tgl_end, order_by |
| Laporan Perpetual | /gudangfarmasi/laporanperpetual | loaded | POST /gudangfarmasi/laporanperpetual/grid | 8 inputs; 1 tables; fields: bagian_id, type, hari_awal, jam_awal, hari_akhir, jam_akhir, nm_item, batch_number; headers: No, Tgl Generate, Periode, Depo, Parameter |
| Kartu Stock | /gudangfarmasi/kartustock | loaded | POST /gudangfarmasi/kartustock/grid | 7 inputs; 0 tables; fields: bagian_id, code, name, batch_number, hari_awal, hari_akhir |
| Obat Masuk 2 | /gudangfarmasi/obatmasuk2 | loaded | POST /gudangfarmasi/obatmasuk2/grid | 3 inputs; 0 tables; fields: q, status_bayar |
| Obat ED | /gudangfarmasi/laporanobated | loaded | POST /gudangfarmasi/laporanobated/grid | 7 inputs; 0 tables; fields: bagian_id, stock_tanggal, type, group_by, expired_in, show_stock |
| Edit Transaksi | /gudangfarmasi/stockedit | loaded | POST /gudangfarmasi/stockedit/grid | 7 inputs; 0 tables; fields: bagian_id, code, name, batch_number, hari_awal, hari_akhir |
| Laporan StockOpname | /gudangfarmasi/laporanstockopname | loaded | POST /gudangfarmasi/laporanstockopname/grid | 7 inputs; 0 tables; fields: code, name, bagian_id, tgl_start, tgl_end, order_by |
| Distribusi Antar Depo | /gudangfarmasi/distribusiobatantardepo | loaded | POST /gudangfarmasi/distribusiobatantardepo/process_form; POST /gudangfarmasi/distribusiobatantardepo/grid | 2 inputs; 0 tables; fields: q |
| Po/Pemesanan Obat | /gudangfarmasi/pemesananobat | loaded | POST /gudangfarmasi/pemesananobat/grid | 3 inputs; 0 tables; fields: supplier_id, q |
| Laporan Buffer Stok Obat | /gudangfarmasi/bufferstockobat/laporan | loaded | POST /gudangfarmasi/bufferstockobat/gridLaporan | 5 inputs; 0 tables; fields: bagian_id, depo, q, tipe_buffer |
| Laporan Perpetual Per Obat | /gudangfarmasi/laporanperpetualperobat | loaded | POST /gudangfarmasi/laporanperpetualperobat/grid | 8 inputs; 0 tables; fields: bagian_id, type, hari_awal, jam_awal, hari_akhir, jam_akhir, nm_item, semua_obat |
| Perpetual Per Obat | /gudangfarmasi/laporanperpetualperobat | loaded | POST /gudangfarmasi/laporanperpetualperobat/grid | 8 inputs; 0 tables; fields: bagian_id, type, hari_awal, jam_awal, hari_akhir, jam_akhir, nm_item, semua_obat |
| Buffer Stock Obat | /gudangfarmasi/bufferstockobat | loaded | POST /gudangfarmasi/bufferstockobat/grid | 3 inputs; 0 tables; fields: bagian_id, q |
| Blood Stocks | /gudangfarmasi/bloodstocks | loaded | POST /gudangfarmasi/bloodstocks/grid | 2 inputs; 0 tables; fields: rhesus |
| Stock Opname Awal | /gudangfarmasi/initiatestockopname | loaded | POST /gudangfarmasi/initiatestockopname/process_form; POST /gudangfarmasi/initiatestockopname/process_form_import; POST /gudangfarmasi/initiatestockopname/grid | 3 inputs; 0 tables; fields: q, bagian_id |

## kasir (19)

| Menu | Route | Status | Forms / actions | Structural indicators |
|---|---|---|---|---|
| Rawat Jalan | /keuangan/rawatjalan | loaded | POST /keuangan/rawatjalan/processForm; POST /keuangan/rawatjalan/tindakandoktergrid; POST /keuangan/rawatjalan/DoAddTindakandokter | 30 inputs; 1 tables; fields: clinic_id, tab, komponen_biaya_group_id, kelas_id, tarif_id, q, submit, alamat, payment_type_id, filter_sort |
| Rawat Inap | /keuangan/rawatinap | loaded | POST /keuangan/rawatinap/processForm; POST /keuangan/rawatinap/mergekwitansi; POST /keuangan/rawatinap/mergeregister | 40 inputs; 6 tables; fields: submit, tab, komponen_biaya_group_id, kelas_id, tarif_id, q, bangsal, inpatient_clinic_id, payment_type_id, jenis_tanggal; headers: No Reg, No RM, Nama, Action, No Asli |
| Transaksi Lain | /keuangan/lain | loaded | POST /keuangan/lain/processForm; POST /keuangan/lain/lainlaingrid; POST /keuangan/lain/DoAddLainlain | 10 inputs; 0 tables; fields: tab, komponen_biaya_group_id, kelas_id, tarif_id, q, submit, date_start, date_end, keterangan |
| Detail Rajal | /keuangan/laporandetailrajal | loaded | POST /keuangan/laporandetailrajal/grid | 9 inputs; 0 tables; fields: status, clinic_id, payment_type_id, periode, bulan, tahun, hari_awal, hari_akhir |
| Detail Ranap | /keuangan/laporandetailranap | loaded | POST /keuangan/laporandetailranap/grid | 8 inputs; 0 tables; fields: status, payment_type_id, periode, bulan, tahun, hari_awal, hari_akhir |
| Pendapatan | /keuangan/pendapatan | loaded | POST /keuangan/pendapatan/grid | 9 inputs; 0 tables; fields: shift, setoran_kwitansi_id, payment_type_id, periode, bulan, tahun, hari_awal, hari_akhir |
| Pendapatan Ranap | /keuangan/pendapatanranap | loaded | POST /keuangan/pendapatanranap/grid | 8 inputs; 0 tables; fields: shift, payment_type_id, periode, bulan, tahun, hari_awal, hari_akhir |
| Pendapatan Rajal | /keuangan/pendapatanrajal | loaded | POST /keuangan/pendapatanrajal/grid | 11 inputs; 0 tables; fields: shift, clinic_id, payment_type_id, status, jenis_tanggal, periode, bulan, tahun, hari_awal, hari_akhir |
| Pendapatan IGD | /keuangan/pendapatanigd | loaded | POST /keuangan/pendapatanigd/grid | 8 inputs; 0 tables; fields: shift, payment_type_id, periode, bulan, tahun, hari_awal, hari_akhir |
| Jasa Medis | /keuangan/jm | loaded | POST /keuangan/jm/grid | 13 inputs; 0 tables; fields: status, doctor_id, jenis, komponen_biaya_group_id, komponen_biaya_item_id, payment_type_id, periode, filter_tanggal, bulan, tahun |
| Piutang | /keuangan/piutang | loaded | POST /keuangan/piutang/grid | 16 inputs; 0 tables; fields: jenis_rawat, clinic_id, payment_type_id, doctor_id, status, bpjs_status_klaim, jenis_tanggal, periode, bulan, tahun |
| Setoran | /keuangan/setoran | loaded | POST /keuangan/setoran/processForm; POST /keuangan/setoran/gridpendapatan; POST /keuangan/setoran/grid | 22 inputs; 0 tables; fields: unique_id, shift, jenis, payment_type_id, periode, bulan, tahun, hari_awal, hari_akhir, ordering |
| Pendapatan Lain | /keuangan/pendapatanlain | loaded | POST /keuangan/pendapatanlain/grid | 9 inputs; 0 tables; fields: shift, status, jenis_tgl, periode, bulan, tahun, hari_awal, hari_akhir |
| PENDAPATAN UNIT | /keuangan/pendapatanunit | loaded | POST /keuangan/pendapatanunit/grid | 8 inputs; 0 tables; fields: payment_type_id, status, periode, bulan, tahun, hari_awal, hari_akhir |
| TERIMA SETORAN | /keuangan/setoranterima | loaded | POST /keuangan/setoranterima/grid | 2 inputs; 0 tables; fields: q |
| Pendapatan Tindakan | /keuangan/pendapatantindakan | loaded | POST /keuangan/Pendapatantindakan/grid | 11 inputs; 0 tables; fields: clinic_id, komponen_biaya_group_id, komponen_biaya_item_id, payment_type_id, status, periode, bulan, tahun, hari_awal, hari_akhir |
| Jurnal Otomatis | /keuangan/jurnal | loaded | None visible | 0 inputs; 0 tables |
| Laporan Tagihan | /keuangan/tagihan | loaded | POST /keuangan/tagihan/grid | 16 inputs; 0 tables; fields: jenis_rawat, clinic_id, payment_type_id, doctor_id, status, bpjs_status_klaim, jenis_tanggal, periode, bulan, tahun |
| Tagihan 2 | /keuangan/tagihan/tagihan2 | loaded | POST /keuangan/tagihan/grid2 | 16 inputs; 0 tables; fields: jenis_rawat, clinic_id, payment_type_id, doctor_id, status, bpjs_status_klaim, jenis_tanggal, periode, bulan, tahun |

## manajemendata (46)

| Menu | Route | Status | Forms / actions | Structural indicators |
|---|---|---|---|---|
| Group | /manajemendata/group | loaded | POST /manajemendata/group/grid | 2 inputs; 0 tables; fields: q |
| Pengguna | /manajemendata/pengguna | loaded | POST /manajemendata/pengguna/grid | 2 inputs; 0 tables; fields: q |
| Settings | /manajemendata/profile | loaded | POST /manajemendata/profile/process_form | 347 inputs; 60 tables; fields: kode_rs, report_header_1, report_header_2, province_id, district_id, sub_district_id, village_id, new_patient_id_type, latitude, longitude |
| Ref Clinical Pathway | /manajemendata/clinicalpathway | loaded | POST /manajemendata/clinicalpathway/grid | 2 inputs; 0 tables; fields: q |
| Menu | /manajemendata/menu | loaded | POST /manajemendata/menu/process_form; POST /manajemendata/menu/grid | 2 inputs; 0 tables; fields: q |
| Staff Medis | /manajemendata/paramedic | loaded | POST /manajemendata/paramedic/grid | 4 inputs; 0 tables; fields: q, jenis, clinic_id |
| Target Rajal | /manajemendata/targetrajal | loaded | POST /manajemendata/targetrajal/process_form; POST /manajemendata/targetrajal/grid | 2 inputs; 0 tables; fields: q |
| Template Tanda Tangan | /manajemendata/tandatangan | loaded | POST /manajemendata/tandatangan/process_form; POST /manajemendata/tandatangan/grid | 2 inputs; 0 tables; fields: q |
| Bangsal | /manajemendata/tt | loaded | POST /manajemendata/tt/grid | 2 inputs; 0 tables; fields: q |
| Data Cara Bayar | /manajemendata/paymenttypes | loaded | POST /manajemendata/paymenttypes/move; POST /manajemendata/paymenttypes/grid | 2 inputs; 1 tables; fields: q; headers: KODE, Nama, Jml Kunjungan, Dipindah ke |
| Tarif | /manajemendata/tarif | loaded | POST /manajemendata/tarif/grid | 2 inputs; 0 tables; fields: q |
| Printer & Service | /manajemendata/printer | loaded | POST /manajemendata/printer/process_form; POST /manajemendata/printer/grid | 3 inputs; 0 tables; fields: q, ip |
| Unit & Poliklinik | /manajemendata/clinic | loaded | POST /manajemendata/clinic/grid | 3 inputs; 0 tables; fields: q, type |
| Pemeriksaan Lab | /manajemendata/pemeriksaanlab | loaded | POST /manajemendata/pemeriksaanlab/move; POST /manajemendata/pemeriksaanlab/grid | 2 inputs; 1 tables; fields: q; headers: KODE, Nama, Jml Kunjungan, Dipindah ke |
| Pemeriksaan Radiologi | /manajemendata/pemeriksaanradiologi | loaded | POST /manajemendata/pemeriksaanradiologi/move; POST /manajemendata/pemeriksaanradiologi/grid | 2 inputs; 1 tables; fields: q; headers: KODE, Nama, Jml Kunjungan, Dipindah ke |
| Bank | /manajemendata/bank | loaded | POST /manajemendata/bank/grid | 2 inputs; 0 tables; fields: q |
| Supplier | /manajemendata/supplier | loaded | POST /manajemendata/supplier/process_form; POST /manajemendata/supplier/grid | 2 inputs; 0 tables; fields: q |
| Group Komponen Biaya | /manajemendata/komponenbiayagroup | loaded | POST /manajemendata/komponenbiayagroup/upload; POST /manajemendata/komponenbiayagroup/grid | 3 inputs; 0 tables; fields: file, q |
| Komponen Biaya | /manajemendata/komponenbiaya | loaded | POST /manajemendata/komponenbiaya/DoAddOneItem; POST /manajemendata/komponenbiaya/upload; POST /manajemendata/komponenbiaya/Download | 36 inputs; 3 tables; fields: komponen_biaya_group_id, id, name, snomedct_id, snomedct_code, snomedct_name, loinc_code, loinc_name, icd_code, icd_name |
| Diskon Apotek | /manajemendata/diskonapotek | loaded | POST /manajemendata/diskonapotek/grid | 2 inputs; 0 tables; fields: q |
| Kelengkapan RI | /manajemendata/kelengkapanranap | loaded | POST /manajemendata/kelengkapanranap/process_form; POST /manajemendata/kelengkapanranap/grid | 2 inputs; 0 tables; fields: q |
| Pekerjaan | /manajemendata/job | loaded | POST /manajemendata/job/move; POST /manajemendata/job/grid | 2 inputs; 1 tables; fields: q; headers: KODE, Nama, Jml Pasien, Dipindah ke |
| Pendidikan | /manajemendata/education | loaded | POST /manajemendata/education/move; POST /manajemendata/education/grid | 2 inputs; 1 tables; fields: q; headers: KODE, Nama, Jml Pasien, Dipindah ke |
| Suku | /manajemendata/suku | loaded | POST /manajemendata/suku/move; POST /manajemendata/suku/grid | 2 inputs; 1 tables; fields: q; headers: KODE, Nama, Jml Pasien, Dipindah ke |
| Bahasa | /manajemendata/bahasa | loaded | POST /manajemendata/bahasa/move; POST /manajemendata/bahasa/grid | 2 inputs; 1 tables; fields: q; headers: KODE, Nama, Jml Pasien, Dipindah ke |
| Instrumen | /manajemendata/instrumen | loaded | POST /manajemendata/instrumen/grid | 3 inputs; 0 tables; fields: q, rumpun |
| DOC EMR | /manajemendata/emrdocument | loaded | POST /manajemendata/emrdocument/grid | 3 inputs; 0 tables; fields: q, sorting_klaim |
| DOC EMR 2 | /manajemendata/emrdocument2 | loaded | POST /manajemendata/emrdocument2/grid | 2 inputs; 0 tables; fields: q |
| DATA MONITOR FARMASI | /manajemendata/monitoringresep | loaded | POST /manajemendata/monitoringresep/move; POST /manajemendata/monitoringresep/grid | 2 inputs; 1 tables; fields: q; headers: KODE, Nama, Jml, Dipindah ke |
| Depo | /manajemendata/depo | loaded | POST /manajemendata/depo/move; POST /manajemendata/depo/grid | 2 inputs; 1 tables; fields: q; headers: KODE, Nama, Jml Transaksi, Dipindah ke |
| Penyakit Menular | /manajemendata/penyakitmenular | loaded | POST /manajemendata/penyakitmenular/move; POST /manajemendata/penyakitmenular/grid | 2 inputs; 1 tables; fields: q; headers: KODE, Nama, Jml Pasien, Dipindah ke |
| Pasien | /manajemendata/patient | loaded | POST /manajemendata/patient/move; POST /manajemendata/patient/grid | 11 inputs; 1 tables; fields: id, nik, name, birth_date, address, province_id, district_id, sub_district_id, village_id, order_by; headers: No. RM, Nama, Jml Kunjungan, Aksi, No RM Pengganti |
| Kelas | /manajemendata/kelas | loaded | POST /manajemendata/kelas/grid | 2 inputs; 0 tables; fields: q |
| Diag Keperawatan | /manajemendata/diagnosiskeperawatan | loaded | POST /manajemendata/diagnosiskeperawatan/grid | 2 inputs; 0 tables; fields: q |
| W2 | /manajemendata/W2 | loaded | POST /manajemendata/w2/grid | 2 inputs; 0 tables; fields: q |
| Odontogram | /manajemendata/Odontogram | loaded | POST /manajemendata/Odontogram/move; POST /manajemendata/odontogram/grid | 2 inputs; 1 tables; fields: q; headers: KODE, Nama, Jml Pasien, Dipindah ke |
| Log Activity | /manajemendata/logsystem | error | None visible | No form/table structure visible |
| LOG ESIGN | /manajemendata/logesign | loaded | POST /manajemendata/logesign/grid | 2 inputs; 0 tables; fields: q |
| Target Pendapatan | /manajemendata/targetpendapatan | loaded | POST /manajemendata/targetpendapatan/grid | 2 inputs; 0 tables; fields: q |
| Log Bridging | /manajemendata/logbridging | loaded | POST /manajemendata/logbridging/grid | 4 inputs; 0 tables; fields: target, request, response |
| Group IBS | /manajemendata/paramedicibs | loaded | POST /manajemendata/Paramedicibs/move; POST /manajemendata/paramedicibs/grid | 2 inputs; 1 tables; fields: q; headers: KODE, Nama, Jml Pasien, Dipindah ke |
| Paket Obat | /manajemendata/paketobat | loaded | POST /manajemendata/paketobat/processForm; POST /manajemendata/paketobat/gridCopy; POST /manajemendata/paketobat/grid | 7 inputs; 0 tables; fields: q, submit, depo, clinic, spesialis |
| Specimen | /manajemendata/specimen | loaded | POST /manajemendata/specimen/grid | 2 inputs; 0 tables; fields: q |
| Logsatset | /manajemendata/logsatusehatdebug | loaded | None visible | 0 inputs; 0 tables |
| logsatsetri | /manajemendata/logsatusehatridebug | loaded | None visible | 0 inputs; 0 tables |
| Alat Medis | /manajemendata/alatmedis | loaded | POST /manajemendata/alatmedis/process_form; POST /manajemendata/alatmedis/grid | 2 inputs; 0 tables; fields: q |

## iot (1)

| Menu | Route | Status | Forms / actions | Structural indicators |
|---|---|---|---|---|
| Temperature | /iot/temperature | loaded | None visible | 0 inputs; 0 tables |

## farmasiibs (1)

| Menu | Route | Status | Forms / actions | Structural indicators |
|---|---|---|---|---|
| IBS | /farmasiibs/rawatjalan | loaded | None visible | 0 inputs; 0 tables |

## help (1)

| Menu | Route | Status | Forms / actions | Structural indicators |
|---|---|---|---|---|
| Manual Book | /webroot/help/manual.pdf | error | None visible | No form/table structure visible |


