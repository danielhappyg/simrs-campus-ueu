# Full Menu Taxonomy and Workflow Map

## Scope and reading guide

This is a complete classification of the 268 submenu items visible to the authorized combined lecturer-administrator account on 21 August 2026. It is an information architecture and workflow map, not proof that every function is operational, correctly authorized, or currently used.

Status values:

- **Menu** — the item was visible in the authenticated menu only.
- **Screen** — a non-mutating screen, grid, filter, or form was directly viewed.
- **Form** — a non-mutating data-entry form was directly viewed; nothing was saved.
- **Manual** — the linked vendor manual was obtained and reviewed.

For concise dependencies, `M` means master/reference data, `E` encounter/registration, `C` clinical documentation, `I` inventory/dispensing, `F` finance/claim, and `R` reports/audit. “Actor” identifies the likely primary operating role inferred from the label; it is not an RBAC finding.

## Count reconciliation

| Top-level domain | Items classified | Inventory count |
| --- | ---: | ---: |
| Pendaftaran | 5 | 5 |
| Pemeriksaan | 20 | 20 |
| Rekam Medis (RM) | 7 | 7 |
| Klaim | 6 | 6 |
| Laporan | 117 | 117 |
| BPJS | 2 | 2 |
| Apotek | 20 | 20 |
| Gudang Farmasi (GF) | 23 | 23 |
| Kasir | 19 | 19 |
| Manajemen Data | 46 | 46 |
| IoT | 1 | 1 |
| Farmasi IBS | 1 | 1 |
| Help | 1 | 1 |
| **Total** | **268** | **268** |

## 1. Pendaftaran — patient access and encounter creation (5)

| Menu item | Likely purpose | Workflow stage | Primary actor | Upstream → downstream | Status |
| --- | --- | --- | --- | --- | --- |
| Rawat Inap | Create/admit an inpatient episode | Admission | Admission officer | M, patient → E, bed, C, F, R | Menu |
| IGD | Register an emergency-department episode | Arrival/triage handoff | Admission/ED officer | M, patient → E, triage, C, F, R | Menu |
| Rawat Jalan | Register outpatient visit, payer, referral and queue data | Arrival/registration | Admission officer | M, patient → E, queue, C, F, BPJS, R | Form |
| Display Admisi | Show admission/bed or arrival information to a public/internal display | Arrival visibility | Admission officer/display operator | E, bed → public display/R | Menu |
| Rawat Jalan v2 | Alternate/newer outpatient-registration workflow | Arrival/registration | Admission officer | M, patient → E, queue, C, F, BPJS, R | Menu |

## 2. Pemeriksaan — clinical service, diagnostics and care delivery (20)

| Menu item | Likely purpose | Workflow stage | Primary actor | Upstream → downstream | Status |
| --- | --- | --- | --- | --- | --- |
| Assesmen | Capture general initial or continuing assessment | Clinical assessment | Clinician/nurse | E, M → C, orders, F, R | Menu |
| Triage | Acuity/risk assessment for urgent arrival | Initial assessment | Triage nurse | E → C, IGD care, R | Menu |
| IGD | Document emergency examination and services | Emergency care | ED clinician/nurse | E, triage → C, orders, F, claim, R | Menu |
| Rawat Jalan | Document outpatient examination and service completion | Ambulatory care | Clinician/nurse | E → C, Rx/orders, F, claim, R | Screen |
| Rawat Inap | Document inpatient examination and ward care | Inpatient care | Ward clinician/nurse | E, bed → C, Rx/orders, discharge, F, R | Menu |
| Laboratorium | Request, process or record laboratory services/results | Diagnostics | Lab staff/clinician | E, order → result, C, F, R | Menu |
| Radiologi | Request, process or record imaging services/results | Diagnostics | Radiology staff/clinician | E, order → result, C, F, R | Menu |
| Gizi | Record nutrition assessment and interventions | Ancillary clinical care | Dietitian | E, C → diet plan, F, R | Menu |
| Operasi | Manage operative clinical workflow | Procedure | Surgeon/OR staff | E, assessment → operative C, inventory, F, R | Menu |
| Operasi v3 | Alternate/newer operative workflow | Procedure | Surgeon/OR staff | E, assessment → operative C, inventory, F, R | Menu |
| Rehabilitasi Medis | Manage rehabilitation service documentation | Ancillary clinical care | Rehabilitation clinician | E, referral → C, F, R | Menu |
| Instalasi Rehabilitasi Medik 2 | Alternate/specialised rehabilitation workflow | Ancillary clinical care | Rehabilitation clinician | E, referral → C, F, R | Menu |
| Terapi Wicara | Record speech-therapy care | Ancillary clinical care | Speech therapist | E, referral → C, F, R | Menu |
| Okupasi Terapi | Record occupational-therapy care | Ancillary clinical care | Occupational therapist | E, referral → C, F, R | Menu |
| Lab PA | Manage pathology-anatomy testing | Diagnostics | Pathology staff | E, specimen/order → result, C, F, R | Menu |
| Lab Mikro | Manage microbiology testing | Diagnostics | Microbiology staff | E, specimen/order → result, C, F, R | Menu |
| Bank Darah | Manage blood bank service/issue records | Diagnostics/support | Blood-bank staff | E, order → blood issue, C, I, F, R | Menu |
| Jenazah | Record mortuary/deceased-patient handling | End-of-episode support | Mortuary/administration staff | E, discharge/death → R, F | Menu |
| Rawat Inap v2 | Alternate/newer inpatient clinical workflow | Inpatient care | Ward clinician/nurse | E, bed → C, Rx/orders, discharge, F, R | Menu |
| Ambulance | Record ambulance/transport services | Transport/support | Ambulance coordinator | E/referral → transport record, F, R | Menu |

## 3. Rekam Medis (RM) — record completion, coding, filing and exchange (7)

| Menu item | Likely purpose | Workflow stage | Primary actor | Upstream → downstream | Status |
| --- | --- | --- | --- | --- | --- |
| Rawat Jalan | Review/complete outpatient record, coding or release process | Record completion | Medical-record officer | E, C → coding, claim, R | Menu |
| Rawat Inap | Review/complete inpatient record, coding or release process | Record completion/discharge | Medical-record officer | E, C, discharge → coding, claim, R | Menu |
| Monitor Klaim | Monitor record/claim readiness or status | Revenue-cycle control | Medical-record/claim officer | C, coding, F → claim follow-up, R | Menu |
| Filing | Track physical/digital record filing and retrieval | Record custody | Filing officer | patient/E → record location, R | Menu |
| SatuSehat RJ | Prepare or monitor outpatient interoperability submission | Interoperability | Medical-record/interoperability officer | E, C, M → SatuSehat exchange, R | Screen |
| EMR IPP RAWAT JALAN | Ambulatory integrated patient-progress EMR workflow | Clinical documentation | Outpatient clinician/nurse | E → C, orders, coding, F, exchange | Menu |
| EMR IPP RAWAT INAP | Inpatient integrated patient-progress EMR workflow | Clinical documentation | Ward clinician/nurse | E, bed → C, orders, discharge, coding, F, exchange | Menu |

## 4. Klaim — payer grouping, validation and submission (6)

| Menu item | Likely purpose | Workflow stage | Primary actor | Upstream → downstream | Status |
| --- | --- | --- | --- | --- | --- |
| Rawat Jalan | Prepare/validate outpatient payer claim | Claim preparation | Claim officer/coder | E, C, tariff → F/claim, R | Menu |
| Rawat Inap | Prepare/validate inpatient payer claim | Claim preparation | Claim officer/coder | E, C, discharge, tariff → F/claim, R | Menu |
| Rawat Jalan iDRG | Apply/monitor outpatient iDRG grouping | Coding/grouping | Coder/claim officer | C, coding → claim valuation, F, R | Menu |
| Rawat Inap iDRG | Apply/monitor inpatient iDRG grouping | Coding/grouping | Coder/claim officer | C, coding, discharge → claim valuation, F, R | Menu |
| Perk Plafon RI | Estimate/check inpatient payer ceiling | Financial control | Claim/cashier officer | E, payer, tariff → authorisation/claim, F | Menu |
| Monitor Klaim | Consolidated claim-status monitoring | Claim follow-up | Claim officer | claims/F → correction, submission, R | Menu |

## 5. Laporan — operational, clinical, statutory and management reporting (117)

### 5.1 Clinical, service and registration analytics (1–20)

| # | Menu item | Likely purpose | Workflow stage | Primary actor | Upstream → downstream | Status |
| ---: | --- | --- | --- | --- | --- | --- |
| 1 | 10 Besar Penyakit Rajal | Top outpatient diagnoses | Analysis/reporting | Medical-record/management | C, coding → R, management | Menu |
| 2 | 10 Besar Penyakit Ranap | Top inpatient diagnoses | Analysis/reporting | Medical-record/management | C, coding → R, management | Menu |
| 3 | 10 Tindakan Rajal | Top outpatient procedures | Analysis/reporting | Medical-record/management | C, tariff → R, management | Menu |
| 4 | 10 Tindakan Ranap | Top inpatient procedures | Analysis/reporting | Medical-record/management | C, tariff → R, management | Menu |
| 5 | Pasien | Patient registry/list analysis | Analysis/reporting | Registration/RM | patient, E → R | Menu |
| 6 | Kunjungan RI | Inpatient visit/census report | Analysis/reporting | Registration/RM | E, bed → R | Menu |
| 7 | Kunjungan RJ | Outpatient visit report | Analysis/reporting | Registration/RM | E → R | Menu |
| 8 | Trend | Time-series utilisation/trend report | Analysis/reporting | Management/RM | E, C, F → R | Menu |
| 9 | Monitor Resep | Prescription completion/queue monitoring | Operational control | Pharmacy/clinical manager | C, Rx → I, R | Menu |
| 10 | Waktu | Time-based operational report | Analysis/reporting | Management/RM | E, C → R | Menu |
| 11 | Kunjungan Terakhir | Most-recent patient visit report | Continuity/reporting | RM/clinic staff | E, C → R | Menu |
| 12 | Register Lab | Laboratory register | Department register | Lab/RM | lab order/result → R | Menu |
| 13 | Register IGD | Emergency-department register | Department register | ED/RM | E, triage, C → R | Menu |
| 14 | Register Radiologi | Imaging register | Department register | Radiology/RM | imaging order/result → R | Menu |
| 15 | Register Cancer | Cancer registry | Disease registry | RM/oncology | C, coding → R/external registry | Menu |
| 16 | Register Rawat Jalan | Outpatient register | Department register | Registration/RM | E → R | Menu |
| 17 | Register Rawat Inap | Inpatient register | Department register | Registration/RM | E, bed, discharge → R | Menu |
| 18 | Register IBS | Surgical-installation register | Department register | OR/RM | procedure C → R | Menu |
| 19 | Register Pendaftaran | Registration activity register | Department register | Registration/RM | E → R | Menu |
| 20 | Rekap JHP | Recap of JHP-labelled metric | Management reporting | Management/RM | E/C/F as configured → R | Menu |

### 5.2 Record quality, patient movement, public-health and quality reporting (21–61)

| # | Menu item | Likely purpose | Workflow stage | Primary actor | Upstream → downstream | Status |
| ---: | --- | --- | --- | --- | --- | --- |
| 21 | W2 | W2 surveillance/reporting form | Public-health reporting | RM/public-health officer | diagnosis/E → R/external submission | Menu |
| 22 | A. Kuantitatif Ranap | Inpatient quantitative record-completeness audit | Record-quality audit | RM quality officer | inpatient C → R/remediation | Menu |
| 23 | Px Masuk Ranap | Inpatient admissions report | Census/reporting | RM/registration | E → R | Menu |
| 24 | Px Pindah Ranap | Inpatient transfer report | Census/reporting | RM/ward admin | bed/transfer → R | Menu |
| 25 | Px Pulang | Discharge report | Census/reporting | RM/ward admin | discharge → R | Menu |
| 26 | PTM RAJAL | Outpatient non-communicable-disease report | Public-health reporting | Clinic/RM | C, coding → R/external submission | Menu |
| 27 | PTM RAJAL V2 | Alternate/newer outpatient PTM report | Public-health reporting | Clinic/RM | C, coding → R/external submission | Menu |
| 28 | PTM RANAP | Inpatient non-communicable-disease report | Public-health reporting | Ward/RM | C, coding → R/external submission | Menu |
| 29 | PTM RANAP V2 | Alternate/newer inpatient PTM report | Public-health reporting | Ward/RM | C, coding → R/external submission | Menu |
| 30 | A. Kuantitatif Rajal | Outpatient quantitative record-completeness audit | Record-quality audit | RM quality officer | outpatient C → R/remediation | Menu |
| 31 | Rekap A.K. Rajal | Recap outpatient quantitative audit | Record-quality audit | RM quality officer | audit results → R/remediation | Menu |
| 32 | Rekap A.K. Ranap | Recap inpatient quantitative audit | Record-quality audit | RM quality officer | audit results → R/remediation | Menu |
| 33 | Evaluasi Keterlambatan | Assess late documentation/processing | Record-quality audit | RM quality officer | timestamps/C → R/remediation | Menu |
| 34 | Rekap Evaluasi Keterlambatan | Recap late-documentation assessment | Record-quality audit | RM quality officer | evaluation → R/remediation | Menu |
| 35 | K. Catatan Perawat | Completeness/quality of nursing notes | Record-quality audit | Nursing/RM quality | nursing C → R/remediation | Menu |
| 36 | K. Resume ASKEP | Completeness/quality of nursing-care summary | Record-quality audit | Nursing/RM quality | nursing C/discharge → R | Menu |
| 37 | K. Rekam Medis | Overall medical-record completeness | Record-quality audit | RM quality officer | C/discharge → R/remediation | Menu |
| 38 | Perujuk | Referrer-source report | Referral analysis | Registration/RM | referral/E → R | Menu |
| 39 | Imunisasi | Immunisation report/register | Public-health reporting | Clinic/RM | C, immunisation → R/external submission | Menu |
| 40 | Kematian | Death report | Outcome reporting | RM/quality officer | C, discharge/death → R | Menu |
| 41 | Kematian ASKES | ASKES-payer death report | Payer/outcome reporting | RM/claim officer | payer, death → R | Menu |
| 42 | Masih Dirawat | Current inpatients census | Operational control | Ward/RM | E, bed → R | Menu |
| 43 | Kematian External | Externally classified death report | Outcome/public-health reporting | RM/quality officer | C, death → R/external submission | Menu |
| 44 | Imunisasi Ranap | Inpatient immunisation report | Public-health reporting | Ward/RM | C, immunisation → R | Menu |
| 45 | DATA CARA BAYAR | Payer/payment-method analysis | Finance/reporting | Registration/finance | E, payer → R | Menu |
| 46 | Rekap HAI's | Recap healthcare-associated infections | Infection-control reporting | IPC team | C, surveillance → R/remediation | Menu |
| 47 | Rekap HAI's 2 | Alternate/second HAI recap | Infection-control reporting | IPC team | C, surveillance → R/remediation | Menu |
| 48 | Register Insiden | Incident register | Safety/quality reporting | Quality/safety officer | incident C/data → R/remediation | Menu |
| 49 | Register HAI's | HAI case register | Infection-control reporting | IPC team | C, surveillance → R | Menu |
| 50 | Sebaran Pasien | Patient distribution by unit/attribute | Capacity analysis | Management/RM | E, bed → R | Menu |
| 51 | Px Dirujuk | Referred-patient report | Referral reporting | Registration/RM | referral/E → R | Menu |
| 52 | Rekap Dirujuk | Recap referrals | Referral reporting | Registration/RM | referral/E → R | Menu |
| 53 | Register Filing | Record filing register | Record-custody reporting | Filing/RM | filing events → R | Menu |
| 54 | Rekap ISPA | Acute-respiratory-infection recap | Public-health reporting | RM/public-health officer | C, coding → R/external submission | Menu |
| 55 | eKin CPPT | Electronic clinical-progress-note reporting | Clinical quality reporting | Clinical/RM quality | CPPT C → R/remediation | Menu |
| 56 | Penyakit Rawat Jalan | Outpatient disease report | Morbidity reporting | RM | C, coding → R | Menu |
| 57 | Dokter RJ | Outpatient physician activity report | Provider performance | Management/RM | E, C → R | Menu |
| 58 | Penyakit Rawat Inap | Inpatient disease report | Morbidity reporting | RM | C, coding → R | Menu |
| 59 | Tindakan RJ | Outpatient procedure report | Service reporting | RM/management | C, tariff → R | Menu |
| 60 | Dokter RI | Inpatient physician activity report | Provider performance | Management/RM | E, C → R | Menu |
| 61 | Tindakan RI | Inpatient procedure report | Service reporting | RM/management | C, tariff → R | Menu |

### 5.3 Statutory RL reports (62–91)

| # | Menu item | Likely purpose | Workflow stage | Primary actor | Upstream → downstream | Status |
| ---: | --- | --- | --- | --- | --- | --- |
| 62 | RL 3.1 | RL statutory report component | Regulatory reporting | RM/reporting officer | E, C, M → R/regulator | Menu |
| 63 | RL 3.2 | RL statutory report component | Regulatory reporting | RM/reporting officer | E, C, M → R/regulator | Menu |
| 64 | RL 3.3 | RL statutory report component | Regulatory reporting | RM/reporting officer | E, C, M → R/regulator | Menu |
| 65 | RL 3.4 | RL statutory report component | Regulatory reporting | RM/reporting officer | E, C, M → R/regulator | Menu |
| 66 | RL 3.5 | RL statutory report component | Regulatory reporting | RM/reporting officer | E, C, M → R/regulator | Menu |
| 67 | RL 3.6 | RL statutory report component | Regulatory reporting | RM/reporting officer | E, C, M → R/regulator | Menu |
| 68 | RL 3.7 | RL statutory report component | Regulatory reporting | RM/reporting officer | E, C, M → R/regulator | Menu |
| 69 | RL 3.8 | RL statutory report component | Regulatory reporting | RM/reporting officer | E, C, M → R/regulator | Menu |
| 70 | RL 3.9 | RL statutory report component | Regulatory reporting | RM/reporting officer | E, C, M → R/regulator | Menu |
| 71 | RL 3.11 | RL statutory report component | Regulatory reporting | RM/reporting officer | E, C, M → R/regulator | Menu |
| 72 | RL 3.10 | RL statutory report component | Regulatory reporting | RM/reporting officer | E, C, M → R/regulator | Menu |
| 73 | RL 3.12 | RL statutory report component | Regulatory reporting | RM/reporting officer | E, C, M → R/regulator | Menu |
| 74 | RL 3.13 | RL statutory report component | Regulatory reporting | RM/reporting officer | E, C, M → R/regulator | Menu |
| 75 | RL 3.14 | RL statutory report component | Regulatory reporting | RM/reporting officer | E, C, M → R/regulator | Menu |
| 76 | RL 3.15 | RL statutory report component | Regulatory reporting | RM/reporting officer | E, C, M → R/regulator | Menu |
| 77 | RL 3.16 | RL statutory report component | Regulatory reporting | RM/reporting officer | E, C, M → R/regulator | Menu |
| 78 | RL 3.17 | RL statutory report component | Regulatory reporting | RM/reporting officer | E, C, M → R/regulator | Menu |
| 79 | RL 3.18 | RL statutory report component | Regulatory reporting | RM/reporting officer | E, C, M → R/regulator | Menu |
| 80 | RL 3.19 | RL statutory report component | Regulatory reporting | RM/reporting officer | E, C, M → R/regulator | Menu |
| 81 | RL 4.1 | RL statutory report component | Regulatory reporting | RM/reporting officer | E, C, M → R/regulator | Menu |
| 82 | RL 4.2 | RL statutory report component | Regulatory reporting | RM/reporting officer | E, C, M → R/regulator | Menu |
| 83 | RL 4.3 | RL statutory report component | Regulatory reporting | RM/reporting officer | E, C, M → R/regulator | Menu |
| 84 | RL 5.1 | RL statutory report component | Regulatory reporting | RM/reporting officer | E, C, M → R/regulator | Menu |
| 85 | RL 5.2 | RL statutory report component | Regulatory reporting | RM/reporting officer | E, C, M → R/regulator | Menu |
| 86 | RL 5.3 | RL statutory report component | Regulatory reporting | RM/reporting officer | E, C, M → R/regulator | Menu |
| 87 | RL 4A | RL statutory report component | Regulatory reporting | RM/reporting officer | E, C, M → R/regulator | Menu |
| 88 | RL 4B | RL statutory report component | Regulatory reporting | RM/reporting officer | E, C, M → R/regulator | Menu |
| 89 | RL4A Sebab | Cause/detail companion for RL 4A | Regulatory reporting | RM/reporting officer | C, coding → R/regulator | Menu |
| 90 | RL4B Sebab | Cause/detail companion for RL 4B | Regulatory reporting | RM/reporting officer | C, coding → R/regulator | Menu |
| 91 | RL 5.4 | RL statutory report component | Regulatory reporting | RM/reporting officer | E, C, M → R/regulator | Menu |

### 5.4 Daily, monthly, provider and utilisation reports (92–117)

| # | Menu item | Likely purpose | Workflow stage | Primary actor | Upstream → downstream | Status |
| ---: | --- | --- | --- | --- | --- | --- |
| 92 | STP-RS-RJ | Outpatient service-statistics report | Management/regulatory reporting | RM/reporting officer | E, C → R | Menu |
| 93 | STP-RS-RI | Inpatient service-statistics report | Management/regulatory reporting | RM/reporting officer | E, C, discharge → R | Menu |
| 94 | STPRS-RI2 | Alternate/second inpatient STPRS report | Management/regulatory reporting | RM/reporting officer | E, C, discharge → R | Menu |
| 95 | STPRS-RJ2 | Alternate/second outpatient STPRS report | Management/regulatory reporting | RM/reporting officer | E, C → R | Menu |
| 96 | Per Hari | Daily activity/utilisation report | Operational reporting | Unit manager/RM | E, C, F → R | Menu |
| 97 | Per Bulan | Monthly activity/utilisation report | Management reporting | Unit manager/RM | E, C, F → R | Menu |
| 98 | Per Poliklinik | Activity by clinic | Operational reporting | Clinic manager/RM | E, C → R | Menu |
| 99 | Per Dokter | Activity by physician | Provider reporting | Clinic manager/RM | E, C → R | Menu |
| 100 | Per Dokter Baru-Lama | Physician activity split new/returning patient | Provider reporting | Clinic manager/RM | E, C → R | Menu |
| 101 | Pasien DOA-DOS | Dead-on-arrival/dead-on-scene patient report | Outcome reporting | ED/RM quality | triage/C → R | Menu |
| 102 | Pasien Dirujuk Keluar | Outgoing-referral report | Referral reporting | Registration/RM | referral/E → R | Menu |
| 103 | Lakalantas | Traffic-accident case report | Public-health/claim reporting | ED/RM/claim officer | E, C, payer → R | Menu |
| 104 | Per Dokter Umum | General-practitioner activity report | Provider reporting | Management/RM | E, C → R | Menu |
| 105 | Per Dokter IGD | Emergency physician activity report | Provider reporting | ED manager/RM | E, C → R | Menu |
| 106 | Per Bulan | Second monthly report (distinct route or scope unknown) | Management reporting | Unit manager/RM | E, C, F → R | Menu |
| 107 | Per Ruang | Activity/census by ward | Capacity reporting | Ward manager/RM | E, bed, C → R | Menu |
| 108 | Per Kelas | Activity/census by bed class | Capacity reporting | Ward manager/RM | E, bed, payer → R | Menu |
| 109 | Per Spesialis | Activity by specialty | Provider reporting | Management/RM | E, C → R | Menu |
| 110 | Per Dokter | Second physician report (distinct route or scope unknown) | Provider reporting | Management/RM | E, C → R | Menu |
| 111 | Dokter APS | Physician data for APS discharge context | Quality/outcome reporting | RM/quality officer | C, discharge → R | Menu |
| 112 | Indikasi Pulang Per Kelas | Discharge indication by bed class | Outcome reporting | Ward manager/RM | discharge, bed → R | Menu |
| 113 | Indikasi Pulang Per Ruang | Discharge indication by ward | Outcome reporting | Ward manager/RM | discharge, bed → R | Menu |
| 114 | Sebab Pulang APS | Against-medical-advice discharge reasons | Quality/outcome reporting | RM/quality officer | discharge → R/remediation | Menu |
| 115 | Kematian | Second death report (distinct route or scope unknown) | Outcome reporting | RM/quality officer | C, discharge/death → R | Menu |
| 116 | Status Pasien Dirawat | Current inpatient status/census | Operational control | Ward manager/RM | E, bed → R | Menu |
| 117 | Rekap Monitor Resep | Recap prescription-monitoring report | Pharmacy control | Pharmacy manager | C, Rx, I → R | Menu |

## 6. BPJS — payer-specific clinical and administrative processing (2)

| Menu item | Likely purpose | Workflow stage | Primary actor | Upstream → downstream | Status |
| --- | --- | --- | --- | --- | --- |
| Rawat Jalan | BPJS outpatient eligibility/referral/SEP or service processing | Payer administration | BPJS/registration officer | E, payer, C → claim, R | Menu |
| Rawat Inap | BPJS inpatient eligibility/SEP or service processing | Payer administration | BPJS/registration officer | E, payer, C, discharge → claim, R | Menu |

The role editor also showed BPJS permission nodes for VClaim, LUPIS and SIPP, which are not separate visible shell submenu items and are therefore not added to the 268 total.

## 7. Apotek — patient-facing dispensing and pharmacy reporting (20)

| Menu item | Likely purpose | Workflow stage | Primary actor | Upstream → downstream | Status |
| --- | --- | --- | --- | --- | --- |
| Apotek IGD | Dispense ED prescriptions | Dispensing | Pharmacy staff | E, C/Rx, stock → I, F, R | Menu |
| Apotek Rawat Jalan | Dispense outpatient prescriptions | Dispensing | Pharmacy staff | E, C/Rx, stock → I, F, R | Menu |
| Apotek Rawat Inap | Dispense inpatient prescriptions | Dispensing | Pharmacy staff | E, C/Rx, stock → I, F, R | Menu |
| Apotek Pasien Luar | Dispense external/non-encounter prescriptions | Dispensing | Pharmacy staff | patient/Rx, stock → I, F, R | Menu |
| Distribusi Obat | Distribute drugs to units/depot | Inventory movement | Pharmacy staff | stock → unit inventory, R | Menu |
| Stock Opname | Count/reconcile pharmacy stock | Inventory control | Pharmacy staff | stock movements → adjustment, R | Menu |
| Laporan Posisi Stock | Stock-on-hand report | Inventory reporting | Pharmacy manager | stock movements → R | Menu |
| Laporan Distribusi Obat | Drug-distribution report | Inventory reporting | Pharmacy manager | distributions → R | Menu |
| Laporan Obat Masuk | Drug-receipt report | Inventory reporting | Pharmacy manager | receiving → R | Menu |
| Laporan Pemakaian Obat | Drug-consumption report | Inventory reporting | Pharmacy manager | dispensing/usage → R | Menu |
| Pengeluaran Rajal | Outpatient stock issue | Inventory issue | Pharmacy staff | Rx, stock → F, R | Menu |
| Pengeluaran Luar | External stock issue | Inventory issue | Pharmacy staff | request, stock → F, R | Menu |
| Pengeluaran Ranap | Inpatient stock issue | Inventory issue | Pharmacy staff | Rx, stock → F, R | Menu |
| Retur Bagian | Return stock from a unit | Inventory return | Pharmacy staff | unit inventory → stock, R | Menu |
| Riwayat Resep | Prescription history | Continuity/audit | Pharmacy staff | Rx, dispense → R/audit | Menu |
| Trolley RJ | Manage outpatient dispensing trolley/queue | Dispensing control | Pharmacy staff | Rx → dispense, R | Menu |
| Trolley RI | Manage inpatient dispensing trolley/queue | Dispensing control | Pharmacy staff | Rx → dispense, R | Menu |
| Laporan Retur Px | Patient-return report | Inventory/finance reporting | Pharmacy manager | returns → R | Menu |
| Pengeluaran | General stock issue | Inventory issue | Pharmacy staff | stock/request → F, R | Menu |
| Kartu Stock | Stock-card/movement ledger | Inventory audit | Pharmacy manager | all stock movements → R/audit | Menu |

## 8. Gudang Farmasi (GF) — central procurement, warehouse and stock control (23)

| Menu item | Likely purpose | Workflow stage | Primary actor | Upstream → downstream | Status |
| --- | --- | --- | --- | --- | --- |
| Obat | Drug/item master and warehouse view | Master/inventory | Warehouse pharmacist | M → procurement, I, R | Menu |
| Obat Masuk | Receive drugs into central warehouse | Procurement/receipt | Warehouse staff | supplier/PO → stock, F, R | Menu |
| Distribusi Obat | Issue/distribute warehouse stock | Inventory movement | Warehouse staff | stock → depot/unit, R | Menu |
| Stock Opname | Count/reconcile warehouse stock | Inventory control | Warehouse staff | stock movements → adjustment, R | Menu |
| Retur Supplier | Return goods to supplier | Procurement return | Warehouse staff | receipt/stock → supplier credit, F, R | Menu |
| Retur Bagian | Receive return from receiving unit | Inventory return | Warehouse staff | depot/unit → stock, R | Menu |
| Laporan Posisi Stock | Warehouse stock-on-hand report | Inventory reporting | Warehouse manager | stock movements → R | Menu |
| Laporan Distribusi Obat | Warehouse-distribution report | Inventory reporting | Warehouse manager | distributions → R | Menu |
| Laporan Obat Masuk | Warehouse receipts report | Inventory reporting | Warehouse manager | receiving → R | Menu |
| Laporan Perpetual | Perpetual-inventory report | Inventory reporting | Warehouse manager | stock ledger → R/audit | Menu |
| Kartu Stock | Warehouse stock-card ledger | Inventory audit | Warehouse manager | movements → R/audit | Menu |
| Obat Masuk 2 | Alternate/newer receipt workflow | Procurement/receipt | Warehouse staff | supplier/PO → stock, F, R | Menu |
| Obat ED | Near-expiry/expired-drug management | Inventory safety | Warehouse pharmacist | expiry/master, stock → disposition, R | Menu |
| Edit Transaksi | Correct inventory transaction | Inventory control | Authorised warehouse supervisor | prior transaction → adjusted stock, R/audit | Menu |
| Laporan StockOpname | Stock-count report | Inventory reporting | Warehouse manager | stock count → R | Menu |
| Distribusi Antar Depo | Transfer stock between depots | Inventory movement | Warehouse/depot staff | source stock → destination stock, R | Menu |
| Po/Pemesanan Obat | Purchase-order/drug requisition workflow | Procurement | Warehouse/procurement staff | master, demand → supplier/receipt, F, R | Menu |
| Laporan Buffer Stok Obat | Reorder/buffer-stock report | Inventory planning | Warehouse manager | stock, thresholds → PO decision, R | Menu |
| Laporan Perpetual Per Obat | Per-item perpetual ledger report | Inventory audit | Warehouse manager | item movements → R/audit | Menu |
| Perpetual Per Obat | Per-item perpetual view | Inventory audit | Warehouse staff | item movements → R/audit | Menu |
| Buffer Stock Obat | Configure/review buffer-stock thresholds | Inventory planning | Warehouse pharmacist | M, demand → reorder controls, R | Menu |
| Blood Stocks | Blood-stock management | Special inventory | Blood-bank/warehouse staff | blood receipt/use → C, R | Menu |
| Stock Opname Awal | Opening stock-count setup | Inventory initialization | Warehouse supervisor | M, opening balance → stock ledger, R | Menu |

## 9. Kasir — charges, receipts, receivables and accounting (19)

| Menu item | Likely purpose | Workflow stage | Primary actor | Upstream → downstream | Status |
| --- | --- | --- | --- | --- | --- |
| Rawat Jalan | Bill/collect outpatient charges | Point of payment | Cashier | E, C, tariff, I → receipt, F, R | Menu |
| Rawat Inap | Bill/collect inpatient charges | Discharge/payment | Cashier | E, C, tariff, I, discharge → receipt, F, R | Menu |
| Transaksi Lain | Record miscellaneous transactions | General finance | Cashier | M/request → F, R | Menu |
| Detail Rajal | Review outpatient charge detail | Billing review | Cashier/finance | E, C, tariff → F, R | Menu |
| Detail Ranap | Review inpatient charge detail | Billing review | Cashier/finance | E, C, tariff, I → F, R | Menu |
| Pendapatan | Overall revenue report | Finance reporting | Finance manager | receipts/claims → R | Menu |
| Pendapatan Ranap | Inpatient revenue report | Finance reporting | Finance manager | inpatient charges/receipts → R | Menu |
| Pendapatan Rajal | Outpatient revenue report | Finance reporting | Finance manager | outpatient charges/receipts → R | Menu |
| Pendapatan IGD | ED revenue report | Finance reporting | Finance manager | ED charges/receipts → R | Menu |
| Jasa Medis | Calculate/report professional medical fees | Revenue allocation | Finance/HR finance | service charges → allocation, F, R | Menu |
| Piutang | Manage receivables | Credit control | Finance officer | payer/invoice → collection, R | Menu |
| Setoran | Record cashier deposit/remittance | Cash management | Cashier/finance | receipts → deposit, R/audit | Menu |
| Pendapatan Lain | Record/report other income | Finance reporting | Finance officer | transaction → F, R | Menu |
| PENDAPATAN UNIT | Unit-level revenue report | Finance reporting | Unit/finance manager | charges/receipts → R | Menu |
| TERIMA SETORAN | Receive/confirm cashier remittance | Cash management | Finance officer | setoran → ledger, R/audit | Menu |
| Pendapatan Tindakan | Procedure-revenue report | Finance reporting | Finance manager | procedures/tariff → R | Menu |
| Jurnal Otomatis | Generate accounting journals from source transactions | Accounting close | Finance/accounting | receipts/charges → ledger, R/audit | Menu |
| Laporan Tagihan | Invoice/billing report | Finance reporting | Finance officer | charges/claims → R | Menu |
| Tagihan 2 | Alternate/second billing workflow/report | Billing | Cashier/finance | charges/claims → F, R | Menu |

## 10. Manajemen Data — access, configuration, masters, audit and integration administration (46)

| Menu item | Likely purpose | Workflow stage | Primary actor | Upstream → downstream | Status |
| --- | --- | --- | --- | --- | --- |
| Group | Define access groups and menu permissions | Access administration | System administrator | users, menus → RBAC/audit | Screen |
| Pengguna | Create/manage user accounts | Access administration | System administrator | group, staff → RBAC/audit | Menu |
| Settings | Global system, integration and operational configuration | System configuration | System administrator | M/config → all modules/integrations | Screen |
| Ref Clinical Pathway | Maintain clinical-pathway references | Clinical master data | Clinical governance/admin | M → C, quality, R | Menu |
| Menu | Maintain menu definitions/navigation | System configuration | System administrator | M → RBAC/UI | Menu |
| Staff Medis | Maintain clinician/staff master | Master data | HR/medical admin | M → scheduling, E, C, F, R | Menu |
| Target Rajal | Configure outpatient targets | Planning/performance | Management admin | targets, E → R | Menu |
| Template Tanda Tangan | Maintain signature templates | Document configuration | System administrator | M → C, documents, TTE | Menu |
| Bangsal | Maintain ward/bed-area master | Facility master data | Admin/bed manager | M → admission, C, R | Menu |
| Data Cara Bayar | Maintain payment/payer master | Finance master data | Finance admin | M → E, F, claims, R | Menu |
| Tarif | Maintain service tariff master | Finance master data | Finance admin | M → F, claims, R | Menu |
| Printer & Service | Configure print/service endpoints | Operations configuration | System administrator | M/config → documents, operations | Menu |
| Unit & Poliklinik | Maintain units and clinics | Facility master data | Admin/medical admin | M → E, C, schedules, R | Menu |
| Pemeriksaan Lab | Maintain lab-examination master | Diagnostic master data | Lab admin | M → orders, C, F, R | Menu |
| Pemeriksaan Radiologi | Maintain imaging-examination master | Diagnostic master data | Radiology admin | M → orders, C, F, R | Menu |
| Bank | Maintain bank/payment destination master | Finance master data | Finance admin | M → cash/deposit, R | Menu |
| Supplier | Maintain supplier master | Procurement master data | Procurement admin | M → PO, receipt, return, F | Menu |
| Group Komponen Biaya | Define charge-component groups | Finance master data | Finance admin | M → tariff, billing, R | Menu |
| Komponen Biaya | Define individual charge components | Finance master data | Finance admin | M → tariff, billing, R | Menu |
| Diskon Apotek | Maintain pharmacy-discount rules | Pricing control | Pharmacy/finance admin | M → dispensing, F, R | Menu |
| Kelengkapan RI | Define inpatient completeness criteria | Record-quality config | RM quality admin | M → C audit, R | Menu |
| Pekerjaan | Maintain occupation master | Demographic master data | Registration admin | M → patient/E, R | Menu |
| Pendidikan | Maintain education master | Demographic master data | Registration admin | M → patient/E, R | Menu |
| Suku | Maintain ethnicity master | Demographic master data | Registration admin | M → patient/E, R | Menu |
| Bahasa | Maintain language master | Demographic master data | Registration admin | M → patient/E, accessibility, R | Menu |
| Instrumen | Maintain assessment/instrument definitions | Clinical configuration | Clinical governance/admin | M → C, quality, R | Menu |
| DOC EMR | Maintain EMR document definitions/templates | EMR configuration | Clinical/system admin | M → C, documents, R | Menu |
| DOC EMR 2 | Alternate/newer EMR-document configuration | EMR configuration | Clinical/system admin | M → C, documents, R | Menu |
| DATA MONITOR FARMASI | Configure pharmacy monitoring data | Pharmacy configuration | Pharmacy admin | M/config → I, R | Menu |
| Depo | Maintain pharmacy/depot locations | Inventory master data | Pharmacy admin | M → inventory movement, R | Menu |
| Penyakit Menular | Maintain communicable-disease reference | Public-health master data | RM/public-health admin | M → C, surveillance, R | Menu |
| Pasien | Patient-master management/search | Master data stewardship | Registration/RM admin | patient → E, C, F, R | Menu |
| Kelas | Maintain bed/service class master | Facility/payer master data | Admin/finance | M → admission, tariff, R | Menu |
| Diag Keperawatan | Maintain nursing-diagnosis reference | Clinical master data | Nursing governance/admin | M → nursing C, R | Menu |
| W2 | Configure W2 reference/process | Public-health configuration | RM/public-health admin | M → W2 reporting | Menu |
| Odontogram | Configure/review dental-chart component | Clinical documentation | Dental/system admin | M → C, F, R | Menu |
| Log Activity | Review application activity log | Audit/monitoring | System administrator/auditor | all actions → audit/R | Screen |
| LOG ESIGN | Review electronic-signature log | Audit/monitoring | System administrator/auditor | documents/TTE → audit/R | Screen |
| Target Pendapatan | Configure revenue targets | Planning/performance | Finance/management admin | targets, F → R | Menu |
| Log Bridging | Review integration/bridging transactions | Integration monitoring | System/integration admin | BPJS/SatuSehat/etc. → audit/remediation | Screen |
| Group IBS | Maintain operating-theatre group master | Procedure configuration | OR administrator | M → Operasi/IBS, R | Menu |
| Paket Obat | Maintain drug packages/bundles | Pharmacy configuration | Pharmacy admin | M → Rx/dispensing, F, R | Menu |
| Specimen | Maintain laboratory specimen master | Diagnostic master data | Lab admin | M → lab order/result, R | Menu |
| Logsatset | Review SatuSehat integration log | Integration monitoring | System/interoperability admin | SatuSehat exchange → audit/remediation | Screen |
| logsatsetri | Review inpatient SatuSehat integration log | Integration monitoring | System/interoperability admin | inpatient SatuSehat exchange → audit/remediation | Menu |
| Alat Medis | Maintain medical-equipment master | Facility/clinical master data | Biomedical/admin staff | M → C/service, R | Menu |

## 11. IoT — connected-device monitoring (1)

| Menu item | Likely purpose | Workflow stage | Primary actor | Upstream → downstream | Status |
| --- | --- | --- | --- | --- | --- |
| Temperature | Monitor/record connected temperature readings or exceptions | Facility monitoring | Facilities/biomedical staff | IoT devices → alerts/logs, R | Screen |

## 12. Farmasi IBS — operating-theatre pharmacy (1)

| Menu item | Likely purpose | Workflow stage | Primary actor | Upstream → downstream | Status |
| --- | --- | --- | --- | --- | --- |
| IBS | Manage medicines/supplies used by the surgical installation | Procedure inventory | OR pharmacy/IBS staff | OR case, stock → I, F, R | Menu |

## 13. Help — user documentation (1)

| Menu item | Likely purpose | Workflow stage | Primary actor | Upstream → downstream | Status |
| --- | --- | --- | --- | --- | --- |
| Manual Book | Provide vendor/user documentation | Enablement/support | All authorised users | documented workflows → training/support | Manual |

## End-to-end workflow interpretation

The menu is consistent with a broad hospital-information-system operating model:

`Masters/configuration → patient registration and encounter → triage/clinical/diagnostic/procedure documentation → pharmacy/inventory and charges → cashier/claim → medical-record completion/coding/interoperability → operational/statutory reporting and audit.`

This should be treated as a working map for subsequent screen-by-screen validation. Alternate labels such as `v2`, `v3`, duplicate `Per Bulan`, duplicate `Per Dokter`, `Obat Masuk 2`, `DOC EMR 2`, and second HAI/Tagihan reports indicate parallel or successor workflows but do not, from menu visibility alone, establish which path is canonical.
