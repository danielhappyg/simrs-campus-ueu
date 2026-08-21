# Full SIMRS Operating and Workflow Model

Assessment target: **SIMRS 3.0 — RS UEU**  
Assessment date: **21 August 2026**  
Scope: **the complete visible hospital information system, not only registration or RMIK**

This document converts the full 268-item authenticated menu inventory, the live evidence register, and the linked 2018 vendor manual into one hospital-wide operating model. It is a functional model, not a claim that every visible menu is configured, used, correctly authorized, or working.

## 1. Evidence legend and interpretation rules

- **[O] Observed:** directly visible in the authorized live interface or captured in the evidence register.
- **[M] Manual-documented:** described in the linked 2018 vendor manual. The manual is a historical baseline and is not current product documentation.
- **[I] Inferred:** the most likely role of a menu or handoff based on its name and normal hospital operations; it still needs demonstration.
- **[U] Unknown:** important behavior, state, rule, ownership, or integration evidence has not been obtained.

Rules used throughout:

1. A visible menu proves menu assignment only; it does not prove successful execution or server-side permission.
2. A manual description proves that a workflow was documented in 2018, not that the current version implements it identically.
3. Similar names, such as `Rawat Jalan`, are kept separate when they occur under different domains because each represents a different work queue or responsibility.
4. Newer menu generations (`v2`, `v3`, EMR IPP, iDRG, SatuSehat, TTE, IoT) are not backfilled with behavior from the old manual.
5. No clinical, financial, stock, claim, user, role, or configuration transaction was submitted during this assessment.

## 2. System-wide view

### 2.1 Visible functional footprint

| Domain | Visible items | System responsibility |
|---|---:|---|
| Pendaftaran | 5 | Patient identity, encounter creation, admission, queue and payer/referral initiation |
| Pemeriksaan | 20 | Clinical, nursing, diagnostic, allied-health, theatre, mortuary and ambulance delivery |
| RM | 7 | Longitudinal record, coding, completeness, filing, claim monitoring and national-health exchange |
| Klaim | 6 | Claim preparation, grouping, plafond estimation and claim monitoring |
| Laporan | 117 | Operational, clinical, quality, epidemiological, regulatory and management reporting |
| BPJS | 2 visible | BPJS outpatient and inpatient workflows; role editor exposes additional VClaim/LUPIS/SIPP nodes |
| Apotek | 20 | Prescription fulfillment, dispensing-depot stock, returns and pharmacy reporting |
| GF | 23 | Central pharmaceutical warehouse, purchasing, receipt, distribution, stock control and supplier returns |
| Kasir | 19 | Patient billing, collections, receivables, cashier settlement, revenue and service-fee reporting |
| Manajemen Data | 46 | Roles, users, hospital master data, tariffs, service catalogs, document templates, logs and integrations |
| IoT | 1 | Temperature monitoring surface |
| Farmasi IBS | 1 | Theatre/IBS pharmacy surface |
| Help | 1 | Manual Book |
| **Total** | **268** | Full hospital operating platform |

### 2.2 High-level hospital workflow

```text
Public information / online queue / referral / walk-in
                         |
                         v
Patient search or creation -> Encounter registration -> payer/referral/SEP context
                         |
          +--------------+----------------+
          |              |                |
          v              v                v
         IGD        Rawat Jalan       Rawat Inap
          |              |                |
          +-------> Clinical assessment, orders, diagnoses, procedures <------+
                         |
          +--------------+-------------------------------+
          |              |               |               |
          v              v               v               v
       Lab/PA/        Radiology       Rehab/allied     Surgery/IBS
       Micro/Blood                      health
          |              |               |               |
          +--------------+------- results/services ------+
                         |
        Prescription -> Apotek/depo -> dispense/return -> inventory movement
                         |
     Clinical completion -> RM coding/completeness/filing -> claim preparation
                         |
        Charges + pharmacy + room + procedures -> cashier/revenue cycle
                         |
       Discharge/referral/death/closure -> reports and external submissions

Supporting every stage:
master data | role/user administration | tariffs | logs | BPJS | SatuSehat |
E-Klaim/iDRG | LIS/PACS | queues | printers | signatures/TTE | IoT | reporting
```

### 2.3 Core records and states

The following is the minimum conceptual data model required to make the visible workflow coherent. It is **inferred**, not a confirmed database schema.

| Record/entity | Created or governed by | Consumed downstream | Key states that require confirmation |
|---|---|---|---|
| Patient/master identity | Pendaftaran; Manajemen Data > Pasien | Every clinical, financial, RM and claim module | new/existing, active/blocked, duplicate/merged, identity verified |
| Encounter/registration | Pendaftaran IGD/RJ/RI/v2 | Clinical queues, RM, pharmacy, cashier, reports, claims | booked, arrived, registered, in service, completed, cancelled |
| Admission/bed episode | Pendaftaran RI; Bangsal/Kelas | Inpatient care, pharmacy, cashier, reports | waiting, admitted, transferred, discharged, deceased |
| Clinical episode | Pemeriksaan | RM, claims, reports, billing | assessed, ordered, performed, verified, completed, amended |
| Order/request | Clinical services | Lab, radiology, rehab, theatre, pharmacy, dietetics | ordered, accepted, collected/scheduled, resulted/dispensed, cancelled |
| Result/report | Diagnostic/allied service | Clinician, RM, patient summary, claim | draft, verified, corrected, released |
| Medication prescription | Clinician | Apotek/depo, billing, stock ledger | prescribed, screened, prepared, dispensed, returned/cancelled |
| Stock transaction | GF/Apotek/Farmasi IBS | Pharmacy availability, costing, reports | ordered, received, distributed, adjusted, returned, expired |
| Charge/bill item | Registration, clinical, diagnostics, pharmacy, room | Kasir and claims | pending, posted, corrected, discounted, paid/guaranteed/receivable |
| Medical record/coding | RM and clinical services | Claim, SatuSehat, reporting, filing | incomplete, complete, coded, validated, locked, amended |
| Claim | Klaim/RM/BPJS | Payer, finance, monitoring | prepared, grouped, submitted, pending, disputed, approved, rejected, paid |
| Cashier receipt/settlement | Kasir | Finance/revenue reports | unpaid, partially paid, paid, reversed, receivable, deposited |
| Audit/integration event | Platform/log modules | Admin, compliance, support | queued, sent, acknowledged, failed, retried, reconciled |

**[U]** The live database model, primary keys, encounter-number rules, correction/amendment mechanics, locking rules, deletion policy, and cross-module transaction boundaries remain unknown.

## 3. End-to-end patient and service workflows

### 3.1 Pre-arrival and public-information flow

1. **[O]** The public entry surface offers bed availability, admitted-patient information, surgery queue, BPJS Antrol monitoring, inpatient pharmacy queue and inpatient admission information.
2. **[O]** Bed information can be navigated by class, ward, bed map, room/class and BPJS Aplicares.
3. **[M]** The older manual documents room availability and online-registration retrieval from the registration form.
4. **[I]** A pre-arrival record may originate from online registration, BPJS Antrol, referral/VClaim or manual booking and is then taken into a registration queue.
5. **[U]** There is no verified source-to-display reconciliation, refresh interval, consent model, public-data minimization rule or operational owner.

Expected handoff: external booking/referral -> registration work queue -> identity matching -> encounter creation -> queue/admission placement.

### 3.2 Registration and encounter initiation

The complete front-office scope consists of `Rawat Inap`, `IGD`, `Rawat Jalan`, `Display Admisi`, and `Rawat Jalan v2`.

1. **Identity and search. [O][M]** Search an existing patient or initiate a new patient record. The observed outpatient form contains identity, NIK, SatuSehat identity, demographics, address, contact, communication/accessibility, consent and privacy fields.
2. **Encounter context. [O][M]** Select service type, date, clinic/ward, clinician, schedule, admission/visit details, case, arrival method and accident context.
3. **Payer/referral context. [O][M]** Select payment method, insurer/BPJS details, referral and SEP-related fields. The observed outpatient form reported VClaim bridging inactive.
4. **Queue and artifacts. [O][M]** The form can generate or expose queue, label, patient card, bracelet, SEP, tracer and service-proof functions. Actual printer routing and template correctness are unverified.
5. **Encounter creation. [M]** Saving registration makes the patient available to the relevant examination queue.
6. **Admission. [I]** `Rawat Inap` should assign or reserve class/ward/bed and create an inpatient episode; exact bed-locking and transfer mechanics are unknown.
7. **External display. [O]** `Display Admisi` links to an external cleartext HTTP origin. Its ownership, data flow and continued need are unknown.
8. **Version coexistence. [O][U]** `Rawat Jalan` and `Rawat Jalan v2` coexist. Their functional difference, migration plan and source-of-truth status are unknown.

Control points requiring evidence: duplicate-patient prevention, NIK validation, newborn/unknown identity rules, registration cancellation, encounter merge, consent capture, patient blocking, bed concurrency, waitlist rules, queue sequencing, payer eligibility and audit trail.

### 3.3 Triage and initial assessment

Menus: `Assesmen`, `Triage`, and clinical `IGD`.

1. **[O]** These are distinct examination menus, suggesting assessment/triage can precede or supplement the ED examination record.
2. **[M]** The ED clinical form carries start/end time, clinician, case, accident, tariffs, disposition, actions, other charges, depot medication and medical examination content.
3. **[I]** Expected triage flow is arrival -> acuity/risk screening -> queue priority -> initial assessment -> clinician episode.
4. **[U]** No triage scale, reassessment timer, deterioration alert, vital-sign validation, sepsis/early-warning rule or override/audit behavior has been demonstrated.

### 3.4 Emergency department workflow

1. Registration places the patient in the ED queue. **[M]**
2. The ED team selects the patient and records clinician/time/case/accident context. **[M]**
3. The team records anamnesis, diagnosis, procedures, other actions and depot medication; selected services create charges. **[M]**
4. Internal referral can hand off to another clinic or laboratory. **[M]**
5. Disposition includes discharge, inpatient admission, external referral, return to originating facility, death in ED or DOA. **[M]**
6. Downstream handoffs are RM coding/completeness, pharmacy, billing/cashier, claim, mortality/referral reports and possibly ambulance/mortuary. **[I]**

**[U]** ED physician/nurse co-signing, medication administration, observation status, trauma workflow, police/medico-legal record, transfer-of-care acceptance and emergency override controls.

### 3.5 Outpatient workflow

1. Registration creates the clinic/doctor queue. **[O][M]** The sampled examination queue exposed identifiers, risk markers, assessment status, clinician, payer, start/end time and disposition but contained no patient rows.
2. Clinical assessment documents history, diagnosis/ICD, procedures and temporary cost summary. **[M]**
3. Clinician actions can include prescriptions, internal referral, lab/radiology or other service requests and infection/HAI documentation. **[M][I]**
4. Ancillary services return results or completed-service status. **[I]**
5. The episode is clinically completed and sent to RM, claim and billing paths. **[I]**
6. `Rawat Jalan v2` and `EMR IPP RAWAT JALAN` indicate newer outpatient generations. **[O]** Their relationship to the legacy outpatient forms is unknown.

**[U]** Appointment rescheduling, no-show handling, clinical-note locking, order acknowledgment, result notification, referral-loop closure, e-prescription clinical decision support and version migration.

### 3.6 Inpatient admission, treatment, transfer and discharge

1. Registration establishes the admission and room/class/bed context. **[M][I]**
2. The inpatient queue can be filtered by room, payer, medical-record number and date. **[M]**
3. Inpatient documentation includes room and ongoing cost, DPJP/clinical examination, visits, doctor actions, nursing actions, other services, depot medication, HAI/INOS and diet. **[M]**
4. Internal referrals/orders connect the episode to lab, radiology, surgery, rehabilitation, allied health, blood bank and other units. **[M][I]**
5. Medication requests are fulfilled by inpatient pharmacy/depot; room, actions, diagnostics and medication should accumulate on the bill. **[M][I]**
6. Transfer between rooms/classes should update bed state, service responsibility and charges. **[I]** `Px Pindah Ranap`, ward/bed and room-cost reporting imply this lifecycle, but the live transfer transaction was not demonstrated.
7. Discharge disposition includes improved/doctor-approved discharge, leaving against advice, referral, death under 48 hours and death over 48 hours. **[M]**
8. Closure should trigger record-completeness review, discharge summary/coding, claim preparation, final bill, bed release and regulatory/management reports. **[I]**
9. `Rawat Inap v2` and `EMR IPP RAWAT INAP` indicate newer inpatient generations. **[O]** Their coexistence and migration status remain unknown.

**[U]** Admission authorization, bed reservation locks, nurse handover, medication administration record, care plan, CPPT semantics, clinical pathway enforcement, transfer acceptance, discharge medication, discharge-summary signature, final-bill gate and bed-release transactionality.

### 3.7 Diagnostics and clinical-support workflow

#### Laboratory, pathology and microbiology

- Menus: `Laboratorium`, `Lab PA`, `Lab Mikro`, master `Pemeriksaan Lab`, and `Specimen`.
- **[M]** The historical laboratory workflow selects a registered patient, selects a laboratory service and automatically adds its charge.
- **[I]** The current complete workflow should be order -> specimen definition -> collection/accession -> processing -> result entry -> verification -> release -> clinician acknowledgment -> claim/billing/reporting.
- **[U]** Order entry source, specimen labels, rejection/recollection, reference ranges, critical-result alerts, analyzer/LIS integration, result amendment and microbiology susceptibility workflow.

#### Radiology

- Menus: `Radiologi` and master `Pemeriksaan Radiologi`.
- **[M]** The historical workflow selects the patient and radiology service and posts the charge.
- **[I]** Expected current flow is order -> scheduling -> acquisition -> interpretation -> verified report/image link -> clinician acknowledgment -> claim/billing.
- **[U]** Modality worklist, DICOM/PACS, report signing, critical findings, image retention and external-image import.

#### Blood bank

- Menus: `Bank Darah` and `GF > Blood Stocks`.
- **[I]** Likely covers blood request, compatibility, allocation, issue/return and stock position.
- **[U]** Donor management, crossmatch, transfusion consent, bedside verification, traceability, adverse-event reporting and whether `Blood Stocks` is integrated or only a stock table.

#### Nutrition and dietetics

- Menu: `Gizi`; inpatient historical tab: `Diit`.
- **[M]** Dietetics selects an inpatient and records/provides diet.
- **[I]** Expected handoff is clinical diet order -> nutrition assessment -> meal/diet production list -> delivery/change/stop -> charge and outcome documentation.
- **[U]** Allergy interaction, diet versioning, kitchen production, nutrition screening and delivery confirmation.

#### Rehabilitation and allied health

- Menus: `Rehabilitasi Medis`, `Instalasi Rehabilitasi Medik 2`, `Terapi Wicara`, `Okupasi Terapi`.
- **[I]** Likely flow is referral -> assessment -> plan -> scheduled sessions -> delivered interventions -> progress/outcome -> charges -> clinical/RM closure.
- **[U]** Difference between rehabilitation generations, session authorization, package limits, outcome instruments, therapist signatures and claim rules.

#### Ambulance, mortuary and medical devices

- Menus: `Ambulance`, `Jenazah`, and master `Alat Medis`.
- **[I]** Ambulance likely supports transport request/assignment/completion and charge; mortuary likely supports deceased-patient custody/release; device master likely identifies equipment available to workflows.
- **[U]** Dispatch tracking, crew/vehicle, handover, transport consent, chain of custody, release authorization, medico-legal flags, maintenance/calibration and device-to-patient linkage.

### 3.8 Surgery and IBS workflow

Menus: `Operasi`, `Operasi v3`, `Group IBS`, `Farmasi IBS > IBS`, `Register IBS`, and `Blood Stocks`.

1. **[M]** The historical operation form selects an outpatient/inpatient, procedure and charge.
2. **[I]** A complete theatre path should include request -> clinical indication -> scheduling -> pre-operative assessment/consent -> theatre/resource/team allocation -> medication and implant/BMHP issue -> procedure/anaesthesia record -> recovery -> result/specimen -> post-operative orders -> charge/coding.
3. **[O]** A newer `Operasi v3`, theatre group management and dedicated IBS pharmacy are visible, indicating a broader present-day workflow than the manual describes.
4. **[U]** Theatre calendar, cancellation reasons, surgical safety checklist, anaesthesia, implants/lot traceability, instrument count, specimen-to-PA linkage, blood issue, recovery scoring, surgeon signature and stock reconciliation.

### 3.9 Pharmacy fulfillment workflow

Menus cover `Apotek IGD`, `Apotek Rawat Jalan`, `Apotek Rawat Inap`, `Apotek Pasien Luar`, `Trolley RJ`, `Trolley RI`, issue/return/history and stock reporting.

1. Prescription/order is created by ED, outpatient, inpatient or an external prescriber. **[M]**
2. Pharmacy searches/selects the patient or starts an outside-patient prescription. **[M]**
3. Drug/BMHP items and quantity are entered; the transaction is saved and a label can be printed. **[M]**
4. Stock should be decremented from the selected depot and charges posted to patient billing. **[I]**
5. `Trolley RJ`/`Trolley RI` likely stage or batch medication movement; exact semantics are unknown. **[I][U]**
6. Returns from patients/units (`Laporan Retur Px`, `Retur Bagian`) should reverse or adjust stock and financial effects. **[M][I]**
7. `Riwayat Resep`, `Pengeluaran Rajal/Luar/Ranap`, `Pengeluaran`, `Kartu Stock` and stock/distribution/usage reports provide traceability. **[O][M]**

**[U]** Pharmacist verification, allergy/interaction/dose checking, formulary restriction, substitution, partial fill, controlled-drug controls, batch/lot/expiry at dispensing, e-prescription signature, administration linkage and charge reversal.

### 3.10 Procurement, warehouse and inventory workflow

The GF domain covers medication/BMHP master, purchasing, receipt, central stock, distribution, depot transfer, returns, stocktake, expiry and reorder/buffer management.

```text
Supplier/master + demand/buffer level
                -> PO/Pemesanan Obat
                -> receipt (Obat Masuk / Obat Masuk 2)
                -> central GF stock/perpetual ledger
                -> distribution to depot/unit or inter-depot transfer
                -> clinical dispense/consumption
                -> return from patient/unit or to supplier
                -> stock opname adjustment
                -> stock, expiry, buffer and perpetual reports
```

- **[M]** The old manual documents drug master, incoming purchases/factures, distribution, stock opname, supplier return, unit return, stock-position, distribution, incoming-drug, perpetual and stock-card functions.
- **[O]** Current menus extend this with second-generation receipt, expiry, editable transaction, inter-depot transfer, purchase order, buffer-stock controls, per-drug perpetual, blood stocks and initial stock opname.
- **[U]** Approval hierarchy, quotation/vendor selection, three-way match, receiving variance, purchase budget, lot/batch/expiry enforcement, FEFO, negative-stock prevention, costing method, closed-period controls and accounting posting.

### 3.11 Medical record, coding, filing and health-information workflow

1. Clinical services create source documentation and diagnoses/procedures. **[M]**
2. RM outpatient/inpatient work queues review episode content and assign/validate ICD-10 and ICD-9/ICD-9-CM style procedure codes. **[M]**
3. Record completeness checks cover history, diagnosis, clinician identity/signature and other required elements. **[M]**
4. Filing tracks visit archives; registration can generate tracers. **[M]**
5. Claim monitor links service documentation/coding readiness to payer verification. **[M]**
6. `EMR IPP RAWAT JALAN` and `EMR IPP RAWAT INAP` indicate electronic-record document flows that are absent from the manual. **[O][U]**
7. `SatuSehat RJ` suggests outpatient interoperability processing, while `Logsatset` and `logsatsetri` provide outpatient/inpatient transaction logs or debug surfaces. **[O][I]**

**[U]** Record assembly, deficiency assignment/escalation, coding validation, episode locking, correction addenda, filing location/pull/return, retention/destruction, legal hold, document versioning, electronic signature validity and provenance.

### 3.12 Claims and BPJS workflow

Current menus separate `Klaim` from `BPJS` and expose outpatient/inpatient, iDRG, plafond estimate and monitor functions.

Expected claim path **[I]**:

```text
Eligibility/referral/SEP
 -> complete clinical documentation
 -> diagnosis/procedure coding
 -> charge and medication reconciliation
 -> grouping (legacy/E-Klaim and/or iDRG)
 -> clinical/administrative verification
 -> submission
 -> response/rejection/dispute
 -> correction/resubmission
 -> approval/payment reconciliation
```

- **[M]** Historical `Monitor Klaim` records service type and verification progress.
- **[O]** Current claim menus include RJ/RI, RJ/RI iDRG, `Perk Plafon RI` and `Monitor Klaim`.
- **[O]** BPJS shell menus are `Rawat Jalan` and `Rawat Inap`; the role editor additionally exposes `VClaim`, `LUPIS` and `SIPP` permission nodes.
- **[O]** The sampled bridging log contains BPJS Antrol targets, while outpatient registration reported VClaim inactive.
- **[U]** Licensed grouping engine, production/sandbox endpoints, claim bundle contents, validation edits, digital signature, submission/retry/reconciliation, denial workflow, payment posting and whether iDRG is production-ready.

### 3.13 Billing, cashier and revenue-cycle workflow

1. Registration, room stay, procedures, diagnostics, pharmacy and other services create billable items. **[M][I]**
2. Cashier outpatient/inpatient views identify unpaid encounters and allow cash/non-cash settlement and receipt/detail printing. **[M]**
3. `Transaksi Lain` records non-patient income or direct purchases. **[M]**
4. `Piutang` tracks unpaid patient/payer balances by payment method. **[M]**
5. `Setoran` and `TERIMA SETORAN` represent cashier-to-finance/bank handoff and receipt. **[M][I]**
6. Detail, revenue, unit, service/procedure and medical-fee reports support reconciliation and professional-fee calculation. **[M][I]**
7. `Jurnal Otomatis` indicates an accounting posting step, but the ledger/ERP target and posting controls are unknown. **[O][U]**
8. `Laporan Tagihan` and `Tagihan 2` provide receivable/billing views; version differences are unknown. **[O][U]**

**[U]** Price calculation priority, coverage split, discounts/authorization, deposits, partial payment, refunds, void/reversal, credit notes, cashier close, bank reconciliation, tax, general-ledger mapping, locked periods and segregation of duties.

### 3.14 Reporting and management-control workflow

The 117 report menus are not one workflow. They are the output layer of all upstream activity and divide into these operational families:

| Report family | Visible coverage | Intended management use | Evidence status |
|---|---|---|---|
| Casemix and service profile | Top diseases/actions, disease/action/doctor indexes, payment-method data | Morbidity, workload, case and payer mix | [O][M] |
| Patient/visit operations | Patient, visit, last visit, registration and service registers | Census, queue/service volume and traceability | [O][M] |
| Inpatient movement | admissions, transfers, discharges, still admitted, status, room/class/specialty | Bed management and patient-flow control | [O][M] |
| Quality and record completeness | quantitative analyses, delays, nurse/ASKEP/RM completeness, eKin CPPT | Documentation and quality monitoring | [O][M/I] |
| Safety/infection | HAI registers/recaps, incident register, communicable/non-communicable disease, ISPA | Infection prevention, safety and surveillance | [O][M/I] |
| Referrals/immunization/mortality | referrers, referred patients, immunization, death and external death | Continuity, public health and outcome review | [O][M] |
| Regulatory RL/STP | RL 3.x, 4.x, 5.x, 4A/4B causes, STP-RS | Mandatory hospital reporting | [O][M] |
| Pharmacy | prescription monitoring and recap | Medication service monitoring | [O][I] |
| Outpatient/inpatient recap | day/month/clinic/doctor/room/class/specialty/disposition | Operational management | [O][M] |

Every report must be treated as a derived data product. **[U]** Source table, calculation specification, inclusion/exclusion rules, late corrections, refresh time, reconciliation, export permissions and statutory-form version are not documented.

## 4. Complete menu-to-operating-model traceability

The canonical item-by-item list is in `MODULE_INVENTORY.md`. The mapping below accounts for every visible menu and states its place in the hospital model.

### 4.1 Pendaftaran — all 5 menus

| Menu | Operating role | Status |
|---|---|---|
| Rawat Inap | Creates/manages inpatient admission and bed context | [O][M/I] |
| IGD | Creates ED encounter and queue context | [O][M] |
| Rawat Jalan | Creates outpatient encounter and queue context | [O][M] |
| Display Admisi | External/public admission-display handoff | [O][U] |
| Rawat Jalan v2 | Newer outpatient registration generation | [O][U] |

### 4.2 Pemeriksaan — all 20 menus

| Menu(s) | Operating role | Status |
|---|---|---|
| Assesmen; Triage | Initial clinical/risk assessment and prioritization | [O][I/U] |
| IGD | ED clinical documentation, actions and disposition | [O][M] |
| Rawat Jalan | Outpatient consultation, diagnosis, action, prescription and referral | [O][M] |
| Rawat Inap; Rawat Inap v2 | Inpatient care, visits, nursing/doctor actions and discharge; newer generation unknown | [O][M/U] |
| Laboratorium | General laboratory work queue and results/services | [O][M] |
| Radiologi | Imaging work queue and results/services | [O][M] |
| Gizi | Inpatient nutrition/diet service | [O][M] |
| Operasi; Operasi v3 | Legacy/current theatre workflows | [O][M/U] |
| Rehabilitasi Medis; Instalasi Rehabilitasi Medik 2 | Rehabilitation service generations | [O][I/U] |
| Terapi Wicara; Okupasi Terapi | Allied-health therapy episodes | [O][I/U] |
| Lab PA; Lab Mikro | Anatomical pathology and microbiology | [O][I/U] |
| Bank Darah | Blood-bank request/issue/traceability surface | [O][I/U] |
| Jenazah | Mortuary/deceased-patient workflow | [O][I/U] |
| Ambulance | Patient-transport/ambulance workflow | [O][I/U] |

### 4.3 RM — all 7 menus

| Menu | Operating role | Status |
|---|---|---|
| Rawat Jalan; Rawat Inap | Coding, completeness and episode record review | [O][M] |
| Monitor Klaim | Documentation/claim-readiness monitoring | [O][M] |
| Filing | Medical-record archive/location and visit-file reporting | [O][M] |
| SatuSehat RJ | Outpatient national-health exchange processing | [O][I/U] |
| EMR IPP RAWAT JALAN; EMR IPP RAWAT INAP | New electronic-record document workflows | [O][U] |

### 4.4 Klaim — all 6 menus

| Menu | Operating role | Status |
|---|---|---|
| Rawat Jalan; Rawat Inap | Claim preparation/verification by care setting | [O][I] |
| Rawat Jalan iDRG; Rawat Inap iDRG | iDRG grouping/preparation by care setting | [O][U] |
| Perk Plafon RI | Inpatient coverage/plafond estimation | [O][I/U] |
| Monitor Klaim | Claim status and verification monitoring | [O][M] |

### 4.5 Laporan — all 117 menus

The following groups preserve every visible report name while mapping it to its data-product role.

- **Casemix/statistics:** `10 Besar Penyakit Rajal`, `10 Besar Penyakit Ranap`, `10 Tindakan Rajal`, `10 Tindakan Ranap`, `Pasien`, `Kunjungan RI`, `Kunjungan RJ`, `Trend`, `Waktu`, `Kunjungan Terakhir`.
- **Service and registration registers:** `Register Lab`, `Register IGD`, `Register Radiologi`, `Register Cancer`, `Register Rawat Jalan`, `Register Rawat Inap`, `Register IBS`, `Register Pendaftaran`, `Rekap JHP`, `W2`.
- **Inpatient movement and record quality:** `A. Kuantitatif Ranap`, `Px Masuk Ranap`, `Px Pindah Ranap`, `Px Pulang`, `PTM RAJAL`, `PTM RAJAL V2`, `PTM RANAP`, `PTM RANAP V2`, `A. Kuantitatif Rajal`, `Rekap A.K. Rajal`, `Rekap A.K. Ranap`, `Evaluasi Keterlambatan`, `Rekap Evaluasi Keterlambatan`, `K. Catatan Perawat`, `K. Resume ASKEP`, `K. Rekam Medis`.
- **Referral, public health, safety and outcomes:** `Perujuk`, `Imunisasi`, `Kematian`, `Kematian ASKES`, `Masih Dirawat`, `Kematian External`, `Imunisasi Ranap`, `DATA CARA BAYAR`, `Rekap HAI's`, `Rekap HAI's 2`, `Register Insiden`, `Register HAI's`, `Sebaran Pasien`, `Px Dirujuk`, `Rekap Dirujuk`, `Register Filing`, `Rekap ISPA`, `eKin CPPT`.
- **Indexes:** `Penyakit Rawat Jalan`, `Dokter RJ`, `Penyakit Rawat Inap`, `Tindakan RJ`, `Dokter RI`, `Tindakan RI`.
- **Regulatory RL/STP:** `RL 3.1`, `RL 3.2`, `RL 3.3`, `RL 3.4`, `RL 3.5`, `RL 3.6`, `RL 3.7`, `RL 3.8`, `RL 3.9`, `RL 3.11`, `RL 3.10`, `RL 3.12`, `RL 3.13`, `RL 3.14`, `RL 3.15`, `RL 3.16`, `RL 3.17`, `RL 3.18`, `RL 3.19`, `RL 4.1`, `RL 4.2`, `RL 4.3`, `RL 5.1`, `RL 5.2`, `RL 5.3`, `RL 4A`, `RL 4B`, `RL4A Sebab`, `RL4B Sebab`, `RL 5.4`, `STP-RS-RJ`, `STP-RS-RI`, `STPRS-RI2`, `STPRS-RJ2`.
- **Outpatient recap:** `Per Hari`, `Per Bulan`, `Per Poliklinik`, `Per Dokter`, `Per Dokter Baru-Lama`, `Pasien DOA-DOS`, `Pasien Dirujuk Keluar`, `Lakalantas`, `Per Dokter Umum`, `Per Dokter IGD`.
- **Inpatient recap:** second `Per Bulan`, `Per Ruang`, `Per Kelas`, `Per Spesialis`, second `Per Dokter`, `Dokter APS`, `Indikasi Pulang Per Kelas`, `Indikasi Pulang Per Ruang`, `Sebab Pulang APS`, second `Kematian`, `Status Pasien Dirawat`.
- **Pharmacy monitoring:** `Monitor Resep`, `Rekap Monitor Resep`.

All report menus are **[O]**. The 2018 manual documents many, but not all, historical functions **[M]**. Current formulas, data provenance, accuracy and regulatory currency are **[U]**.

### 4.6 BPJS — all visible menus and permission nodes

| Menu/node | Operating role | Status |
|---|---|---|
| Rawat Jalan; Rawat Inap | BPJS workflows by care setting | [O][I/U] |
| VClaim | Eligibility/referral/SEP and claim-service integration permission | [O permission node][U] |
| LUPIS | BPJS supporting-program integration permission | [O permission node][U] |
| SIPP | BPJS information/complaint workflow permission | [O permission node][U] |
| Additional hierarchy node | Role-tree structure; business function not identified | [O][U] |

### 4.7 Apotek — all 20 menus

| Menu group | Included menus | Operating role | Status |
|---|---|---|---|
| Dispensing | `Apotek IGD`, `Apotek Rawat Jalan`, `Apotek Rawat Inap`, `Apotek Pasien Luar` | Prescription fulfillment by care setting | [O][M/I] |
| Internal movement | `Distribusi Obat`, `Retur Bagian`, `Trolley RJ`, `Trolley RI` | Depot/unit movement, returns and staging | [O][M/I/U] |
| Stock control | `Stock Opname`, `Kartu Stock` | Physical-to-system adjustment and item ledger | [O][M] |
| Stock and movement reporting | `Laporan Posisi Stock`, `Laporan Distribusi Obat`, `Laporan Obat Masuk`, `Laporan Pemakaian Obat` | Inventory and utilization control | [O][M] |
| Issue/dispense traceability | `Pengeluaran Rajal`, `Pengeluaran Luar`, `Pengeluaran Ranap`, `Pengeluaran` | Medication issue details by setting | [O][M/I] |
| Prescription/return history | `Riwayat Resep`, `Laporan Retur Px` | Prescription and patient-return history | [O][I/U] |

### 4.8 GF — all 23 menus

| Menu group | Included menus | Operating role | Status |
|---|---|---|---|
| Catalog and receipt | `Obat`, `Obat Masuk`, `Obat Masuk 2` | Product/BMHP master and purchase receipt | [O][M/U] |
| Purchasing | `Po/Pemesanan Obat`, `Buffer Stock Obat`, `Laporan Buffer Stok Obat` | Reorder planning and purchase initiation | [O][I/U] |
| Distribution | `Distribusi Obat`, `Distribusi Antar Depo`, `Laporan Distribusi Obat` | Central-to-unit and depot-to-depot movement | [O][M/I] |
| Stocktake and opening | `Stock Opname`, `Stock Opname Awal`, `Laporan StockOpname` | Initial and recurring physical reconciliation | [O][M/I] |
| Returns and corrections | `Retur Supplier`, `Retur Bagian`, `Edit Transaksi` | Reverse/correct supply transactions | [O][M/U] |
| Position/ledger | `Laporan Posisi Stock`, `Laporan Perpetual`, `Kartu Stock`, `Laporan Perpetual Per Obat`, `Perpetual Per Obat` | Stock balance and movement ledger | [O][M/I] |
| Receipt/expiry surveillance | `Laporan Obat Masuk`, `Obat ED` | Incoming-stock and expiry monitoring | [O][M/I] |
| Specialized stock | `Blood Stocks` | Blood-stock view/control | [O][I/U] |

### 4.9 Kasir — all 19 menus

| Menu group | Included menus | Operating role | Status |
|---|---|---|---|
| Patient settlement | `Rawat Jalan`, `Rawat Inap`, `Transaksi Lain` | Patient and non-patient payments | [O][M] |
| Revenue detail | `Detail Rajal`, `Detail Ranap`, `Pendapatan Tindakan` | Charge/revenue details and allocations | [O][M/I] |
| Revenue reports | `Pendapatan`, `Pendapatan Ranap`, `Pendapatan Rajal`, `Pendapatan IGD`, `Pendapatan Lain`, `PENDAPATAN UNIT` | Revenue by setting/source/unit | [O][M/I] |
| Professional fees | `Jasa Medis` | Clinician service-fee calculation/reporting | [O][M] |
| Receivables and deposits | `Piutang`, `Setoran`, `TERIMA SETORAN` | Unpaid balances and cashier-to-finance settlement | [O][M/I] |
| Accounting/billing | `Jurnal Otomatis`, `Laporan Tagihan`, `Tagihan 2` | Journal handoff and bill/receivable reporting | [O][I/U] |

### 4.10 Manajemen Data — all 46 menus

| Governance area | Included menus | Operating role | Status |
|---|---|---|---|
| Identity/access/UI | `Group`, `Pengguna`, `Menu` | Role, user and menu permission administration | [O][M] |
| Global configuration | `Settings`, `Printer & Service` | Organization, integration, clinical, financial, print and service configuration | [O][U for architecture] |
| Workforce and signatures | `Staff Medis`, `Template Tanda Tangan` | Clinician master and signature templates | [O][M] |
| Clinical content/templates | `Ref Clinical Pathway`, `Instrumen`, `DOC EMR`, `DOC EMR 2`, `Diag Keperawatan`, `Odontogram`, `Kelengkapan RI` | Structured documentation and completeness reference content | [O][I/U] |
| Facilities/services | `Bangsal`, `Unit & Poliklinik`, `Kelas`, `Depo`, `Group IBS`, `Alat Medis` | Ward, clinic, class, depot, theatre-group and device masters | [O][M/I] |
| Payer/pricing/finance | `Data Cara Bayar`, `Tarif`, `Bank`, `Group Komponen Biaya`, `Komponen Biaya`, `Diskon Apotek`, `Target Pendapatan` | Coverage, price, charge, discount, bank and revenue-target masters | [O][M/I] |
| Diagnostics | `Pemeriksaan Lab`, `Pemeriksaan Radiologi`, `Specimen` | Diagnostic catalogs and specimen master | [O][M/I] |
| Supply chain | `Supplier`, `Paket Obat`, `DATA MONITOR FARMASI` | Supplier, medication-package and pharmacy monitoring masters | [O][M/I/U] |
| Patient/demographic reference | `Pekerjaan`, `Pendidikan`, `Suku`, `Bahasa`, `Pasien` | Patient identity and demographic reference data | [O][M/I] |
| Surveillance/reference | `Penyakit Menular`, `W2` | Communicable-disease and reporting references | [O][I/U] |
| Targets | `Target Rajal` | Outpatient performance/volume target | [O][I/U] |
| Audit/integration logs | `Log Activity`, `LOG ESIGN`, `Log Bridging`, `Logsatset`, `logsatsetri` | User, e-signature and external-message evidence | [O][U for completeness/tamper controls] |

The global Settings page visibly spans BPJS, Aplicares, Antrol, EMR, E-Klaim, queues, online registration, RS Online, LIS, PACS, SatuSehat, PDF/FTP, TTE, accounting/COA, WhatsApp, AI/OpenAI and mapping configuration. **[O]** Presence of a field is not proof of a working integration.

### 4.11 IoT, Farmasi IBS and Help — all 3 menus

| Domain/menu | Operating role | Status |
|---|---|---|
| `IoT > Temperature` | Environmental/cold-chain temperature monitoring | [O][U] |
| `Farmasi IBS > IBS` | Operating-theatre medication/BMHP issue and reconciliation | [O][I/U] |
| `Help > Manual Book` | User guidance; currently links to the dated 2018 manual | [O][M historical only] |

## 5. Cross-cutting integration model

| Integration family | Visible evidence | Expected business handoff | Remaining unknown |
|---|---|---|---|
| BPJS VClaim/Antrol/Aplicares | Menus/settings/log targets/public views; VClaim shown inactive in one form | eligibility, referral, SEP, queue and bed reporting | environment, credentials, enabled state, retries, reconciliation, SLA |
| SatuSehat | RJ work surface, settings, outpatient/inpatient log menus | national patient/encounter/clinical-resource exchange | resource coverage, mapping, consent/legal basis, success rate, corrections |
| E-Klaim/iDRG | settings and claim menus | casemix grouping and claim bundle | engine/version, production readiness, mapping and submission process |
| LIS | settings and lab menus | orders/specimens/results | vendor, interface protocol, identifiers, critical-result workflow |
| PACS | settings and radiology menu | imaging order/report/image access | DICOM/worklist, storage, viewer, retention, report signature |
| Online registration/appointment | registration function, localhost appointment-service failures in sampled logs | booking to registration queue | active route, ownership, retry and patient matching |
| TTE/e-signature | profile/settings and e-sign log | signed clinical/administrative documents | trust provider, key custody, signature validation, audit coverage |
| Printing/PDF/FTP | registration artifacts, Printer & Service, settings | labels, wristbands, forms, reports and file exchange | print server, fallback, confidentiality, encryption and delivery proof |
| WhatsApp | settings | patient notification/queue communication | consent, templates, message content, vendor/subprocessor and retention |
| Accounting/ERP | COA/ERP-like settings and Jurnal Otomatis | charge/receipt/deposit journal posting | target ledger, posting granularity, rejection and reconciliation |
| AI/OpenAI | settings fields | possible claim/assistant function | exact use case, data sent, legal basis, retention, redaction and human review |
| IoT | temperature menu/settings surface | cold-chain/environment alerts | devices, calibration, thresholds, alert routing and evidence retention |

Architecture remains **[I/U]** beyond the observed Apache/PHP and legacy jQuery presentation layer. Database engine, application framework/version, service topology, tenant isolation, job scheduler, object/file storage, network zones, deployment process, monitoring and recovery design are not confirmed.

## 6. Organization and responsibility model

This is the minimum responsibility structure implied by the menus. It must be reconciled to UEU teaching roles and vendor support roles.

| Operational owner | Primary modules | Critical handoffs |
|---|---|---|
| Registration/admission | Pendaftaran | clinical queue, bed, payer, RM identity |
| ED/outpatient/inpatient teams | Pemeriksaan | diagnostics, pharmacy, RM, billing, disposition |
| Diagnostic/allied services | Lab/radiology/PA/micro/blood/rehab/gizi | result/service completion, charge, RM |
| IBS/theatre | Operasi, Group IBS, Farmasi IBS | clinical outcome, stock, charge, coding |
| Pharmacy | Apotek | dispense, return, charge, depot stock |
| Warehouse/procurement | GF | supplier, receipt, distribution, stock ledger |
| RMIK/coding | RM, report quality, claim monitor | completeness, coding, filing, claim readiness |
| Claims/BPJS team | Klaim, BPJS | payer submission, rejection, reconciliation |
| Cashier/finance | Kasir | payment, receivable, deposit, journal/revenue |
| Quality/management/regulatory | Laporan | validated indicators and statutory submission |
| Lecturer-administrators | Manajemen Data and all teaching workflows | safe configuration, cohorts, access and exercises |
| Technical/vendor admin | Settings, integrations, patching, backup, logs | platform availability, security, support and recovery |

The broad access of the combined lecturer-administrator account is owner-confirmed as intentional. This does not remove the need for named accounts, student isolation, separation of technical secrets, auditable changes and a clearly defined vendor/UEU responsibility matrix.

## 7. System controls required across every workflow

These controls are not assumed to exist merely because the business functions are visible.

1. **Identity and access:** named accounts, least privilege for students, server-side authorization, privileged-role approval, session controls and periodic recertification.
2. **Patient safety:** unambiguous patient/encounter context, allergies and risk flags, order/result acknowledgment, critical alerts, medication verification and controlled correction.
3. **Clinical integrity:** author/time/signature provenance, versioned amendments, episode locking, co-signing and complete handover.
4. **Financial integrity:** approved tariffs, immutable posted transactions, controlled void/refund/discount, payer split, close periods and reconciled journals.
5. **Inventory integrity:** batch/lot/expiry, negative-stock prevention, FEFO, approved adjustments, perpetual ledger and stocktake reconciliation.
6. **Claim integrity:** documentation/coding validation, submission evidence, retry/rejection workflow and payment reconciliation.
7. **Interoperability integrity:** message identifiers, idempotency, queued delivery, retry, reconciliation, redacted logs and environment separation.
8. **Privacy:** data minimization, public-display restrictions, synthetic teaching data, controlled export/printing, retention and third-party processing controls.
9. **Audit:** actor, timestamp, patient/episode, before/after, reason, source device/session and tamper-resistant retention for high-impact actions.
10. **Operations:** version inventory, supported patch process, backup, restore tests, disaster recovery, monitoring, incident response and support SLA.

## 8. What the current evidence does and does not establish

### Established with high confidence

- The system is a broad SIMRS with 268 visible menu items across the complete hospital lifecycle. **[O]**
- It includes front-office, clinical, diagnostic, allied-health, theatre, medical-record, claim, BPJS, pharmacy, warehouse, cashier, reporting and administrative surfaces. **[O]**
- The older manual documents a connected registration -> clinical service -> record -> pharmacy -> cashier/report workflow, including automatic charge creation in several clinical service forms. **[M]**
- The live version adds major domains not covered by the manual, including SatuSehat, iDRG, EMR IPP, IoT, TTE/e-sign, newer module generations, advanced warehouse controls and AI-related settings. **[O]**

### Not yet established

- Which modules are licensed, configured, placeholder, deprecated or actively used. **[U]**
- Whether similarly named legacy/v2/v3 modules share data and which is authoritative. **[U]**
- Complete server-side workflows, validations, approval states and error handling. **[U]**
- Database architecture, interface specifications, data dictionary and transaction boundaries. **[U]**
- Accuracy and regulatory currency of the 117 reports. **[U]**
- Backup/restore implementation, recovery objectives and successful restore evidence. The manual mentions Backup & Restore, but no corresponding visible current menu was inventoried. **[M/O/U]**
- Audit-log completeness, retention and tamper protection. Sampled activity/e-sign surfaces were empty. **[O/U]**
- Production versus sandbox status and whether records are fully synthetic. **[U]**

## 9. Required vendor demonstrations to complete this model

The next evidence phase should use synthetic patients and controlled, reversible transactions in an isolated training window.

1. Demonstrate one complete ED case from arrival through disposition, coding, bill, claim and report.
2. Demonstrate one outpatient case with order/result, prescription/dispense, internal referral and payment/claim.
3. Demonstrate one inpatient case with bed assignment, transfer, doctor/nurse documentation, diagnostics, medication, theatre or allied-health handoff, discharge, final bill and claim.
4. Demonstrate the complete lab specimen/result and radiology image/report lifecycles.
5. Demonstrate surgery scheduling, safety documentation, IBS pharmacy/stock and post-operative closure.
6. Demonstrate purchase order, receipt, batch/expiry, central-to-depot distribution, dispense, return, stocktake and financial reconciliation.
7. Demonstrate cash/non-cash payment, payer split, receivable, void/refund, cashier close, deposit and automatic-journal reconciliation.
8. Demonstrate coding/completeness, filing/tracer, amendment, e-signature and claim-monitor workflows.
9. Demonstrate BPJS, SatuSehat, E-Klaim/iDRG, LIS, PACS, queue and other integrations with redacted request/response and reconciliation evidence.
10. Demonstrate report lineage and reconcile representative operational, clinical, pharmacy, finance and RL/STP reports to source transactions.
11. Demonstrate user lifecycle, student permissions, privileged-role approval, settings change and end-to-end audit events.
12. Provide architecture diagram, current data dictionary, interface catalog, SBOM, release notes, backup/restore evidence, DR test, monitoring and SLA/escalation records.

## 10. Bottom-line operating interpretation

The assessed product is structurally a **full hospital enterprise system** rather than an RMIK teaching application with a few adjacent screens. Registration supplies encounter context; clinical and ancillary services produce care records and charges; pharmacy and warehouse move medication/BMHP and stock value; RM validates and codes the episode; claims/BPJS convert the episode into payer submissions; cashier and finance settle revenue; reporting converts all upstream transactions into operational and regulatory outputs; and Manajemen Data configures the shared vocabulary, prices, users, integrations and audit surfaces.

That integrated shape is directly supported by the visible menu breadth and historically supported by the manual. The exact current execution paths, controls, data model and integration reliability remain to be demonstrated. This workflow model should therefore be used as the **complete assessment map and vendor-demonstration checklist**, not as proof that every module is production-ready.
