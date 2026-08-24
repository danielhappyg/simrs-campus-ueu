# Structured RJ documentation + RM completeness — functional-requirements and owner-decision pack

- Status: **Bounded v1 engineering authorized; Clinical and RMIK acceptance pending**
- Date: 2026-08-24
- Primary parity capabilities: **PAR-CLN-004** and **PAR-RMIK-001**
- Related decision: **DEC-016 remains Proposed**
- Environment boundary: **SIMULATION / synthetic-only teaching rebuild**
- Implementation decision: [`STRUCTURED_RJ_DOCUMENTATION_RM_COMPLETENESS_V1_IMPLEMENTATION_DECISION.md`](STRUCTURED_RJ_DOCUMENTATION_RM_COMPLETENESS_V1_IMPLEMENTATION_DECISION.md)

## 1. Purpose and decision gate

This pack defines the next bounded outpatient slice: structured nursing and medical documentation on the existing encounter, followed by an explicit RMIK completeness review. It converts known evidence and open questions into decisions that Clinical and RMIK owners can approve, reject or revise before implementation.

It does **not** claim complete SIMRS Sahabat parity, clinical production readiness, or domain acceptance. Run 4 proves that the current synthetic note-to-lab-to-RM-close journey works; it does not prove that the current free-text notes, a proposed structured form, or any completeness checklist matches SAHABAT or professional policy.

The product owner authorized the deliberately narrow v1 engineering boundary recorded in the implementation decision above. That authorization does not resolve the broader **Build gate** questions in sections 7 and 9–11, the owner questions in section 17, or the Clinical/RMIK decision record in section 18. Work outside the recorded v1 fields and mechanics remains blocked. DEC-016 remains **Proposed** and still requires its own Clinical/Laboratory and RMIK decision.

## 2. Evidence vocabulary

The labels below follow `REQUIREMENTS_GOVERNANCE.md` and remain visible throughout this pack.

| Label | Meaning in this pack | Requirement effect |
|---|---|---|
| **Observed** | Directly visible in an authorised captured interface, route or hosted rebuild run. | Proves only what was visible or exercised; not an unobserved rule. |
| **Manual-documented / historic** | Described in the 2018 vendor manual or its assessed map. | Candidate parity evidence requiring current domain-owner confirmation. |
| **Inferred** | A plausible workflow interpretation that has not been demonstrated. | Cannot become committed parity behavior without discovery and decision. |
| **Unknown** | Material behavior, field rule or owner policy has not been established. | Must stay open; cannot be silently implemented as a clinical rule. |
| **Proposed NEW** | Deliberate teaching-rebuild behavior, not claimed as SAHABAT behavior. | Requires the applicable owner approval and traceable acceptance evidence. |

Where multiple labels apply, each source contributes only its own level of evidence.

## 3. Control information

| Field | Entry |
|---|---|
| Working requirement IDs | `FR-CLN-RJ-STRUCT-001` through `FR-CLN-RJ-STRUCT-004`; `FR-RMIK-RJ-COMP-001` through `FR-RMIK-RJ-COMP-004` |
| Type / priority | Functional requirements and cross-domain controls / proposed P1 next slice |
| Clinical owner | Clinical SME **TBD**; Daniel remains interim product owner, not substitute clinical acceptance |
| RMIK owner | RMIK Department |
| Teaching-system owner | Product owner / UEU facilitator |
| Technical and security consultation | Required for authorization, audit, synthetic-data and deployment controls |
| Affected actors | Nurse, physician, RMIK officer, RMIK student, clinical learner, supervisor/facilitator, system administrator |
| Primary trigger | An authorised actor opens an eligible outpatient encounter |
| Current lifecycle | `REGISTERED` → `IN_EXAMINATION` → `READY_FOR_RM` → `CLOSED` in the teaching rebuild; this is implementation evidence, not established SAHABAT parity |
| Current parity status | PAR-CLN-004 and PAR-RMIK-001 remain **Specified / not accepted** |

## 4. Evidence baseline and honest boundary

| Evidence | Classification | What it establishes | What it does not establish |
|---|---|---|---|
| Vendor route `/pemeriksaan/rawatjalan`, recorded in PAR-CLN-004 | **Observed** | A Rawat Jalan clinical desk/route exists. | Exact populated form, required fields, validation, role rules, save semantics or current clinical correctness. |
| Vendor route `/rm/rawatjalan`, recorded in PAR-RMIK-001 | **Observed** | An RM Rawat Jalan menu/route exists. | Exact completeness checklist, close conditions, coding timing or sign-off semantics. |
| 2018 vendor manual and tracked manual assessment | **Manual-documented / historic** | Outpatient work includes anamnesis, diagnosis/ICD, actions and continuation. The RM form describes doctor, continuation, case/accident context and a **Kelengkapan Berkas** checklist with Anamnesa, Diagnosa, Nama dokter and Ttd dokter. | That every historic field remains current, is required for every RJ encounter, or is acceptable for this rebuild. ICD and disposition remain outside this slice unless separately approved. |
| Copy-forward semantics recorded as open in PAR-CLN-004 | **Unknown** | Copy-forward is a known discovery question. | Safe copy behavior, source attribution or permission rules. Copy-forward remains outside this slice. |
| PAR-CLN-004 and PAR-RMIK-001 canonical requirements | **Proposed NEW** plus **Unknown** | Encounter binding, attributable entries, server-side capability checks and completeness sign-off are intended controls; exact form/checklist remains open. | Domain acceptance or SAHABAT equivalence. |
| Hosted Run 4, `UAT-TEACH-20260824-04` | **Observed** (rebuild behavior) | Nurse and physician notes persisted with audit; RMIK closure was visibly blocked by an active lab order, then succeeded after a FINAL result; wrong-role and late/duplicate writes were denied. | Structured-field fitness, checklist adequacy, student supervision, or full server-denial evidence for the active-order close attempt. |
| DEC-014 SAHABAT desk-density direction | **Observed** source + accepted product decision | Clinical desks replacing SAHABAT surfaces must reach the familiar three-column information density and must not regress to a thin card or the failed Antrean/work-queue shell. | Exact clinical fields or clinical rules; the assessed primary visual oracle is the Data Pasien desk. |
| DEC-016 lifecycle contract | **Proposed NEW** | Current teaching guard: active lab orders block closure, closed encounters reject late results, one immutable FINAL result completes an active order. | Clinical/Laboratory or RMIK acceptance; DEC-016 remains Proposed. |

## 5. Scope and non-scope

### 5.1 In scope for owner decision and the later bounded build

- One SAHABAT-density outpatient encounter desk with Indonesian UI labels and UEU design tokens.
- Read-only patient, visit, clinic, physician and encounter context.
- Structured **Catatan Keperawatan** and **Catatan Medis** field families whose exact required/optional rules are owner-approved.
- Attributable draft/final status and supervision status, if approved.
- RMIK worklist readiness indicators and one explicit **Pemeriksaan Kelengkapan RM** checklist.
- Server-side capability, encounter-state, concurrency and synthetic-data controls.
- Attributable success and denial audit events.
- Synthetic acceptance tests and a continuous role-switched hosted UAT.

### 5.2 Explicitly out of scope

- ICD-10/ICD-9 coding, coding suggestions and coding finalisation.
- Prescriptions, dispensing, Apotek workflow or medication inventory.
- Tariffs, charges, cashier, payer adjudication and claims.
- Radiology, PACS, specimen workflow, LIS and a generalized order engine.
- Amendment/correction, copy-forward, cancellation, encounter reopen, late-addendum or deletion workflows.
- Preliminary lab results and changes to the FINAL-only lab contract.
- Live BPJS, VClaim, SATUSEHAT, LIS, PACS or other production integrations.
- Real patient data, production clinical use, or a production medical-record policy.
- Restoration of the retired Antrean/work-queue MVP.

If a final note is found to need correction during this slice, the UI must state that correction is unavailable and direct the teaching facilitator to stop the scenario. Direct database edits, overwrite buttons and encounter reopen are not acceptable substitutes.

## 6. Business outcomes

| ID | Proposed outcome | Evidence class | Accountable decision |
|---|---|---|---|
| `FR-CLN-RJ-STRUCT-001` | An authorised nurse records an attributable, structured nursing note against the correct outpatient encounter. | **Proposed NEW**, informed by **Observed** current note save | Clinical owner |
| `FR-CLN-RJ-STRUCT-002` | An authorised physician records an attributable, structured medical note against the correct outpatient encounter. | **Proposed NEW**, informed by **Observed** and **Manual-documented / historic** evidence | Clinical owner |
| `FR-CLN-RJ-STRUCT-003` | The desk exposes enough patient/visit/document context to work safely without recreating a thin MVP. | **Proposed NEW** under DEC-014; exact clinical density **Unknown** | Product + Clinical + RMIK |
| `FR-CLN-RJ-STRUCT-004` | Draft/final and supervision status are explicit; the system never represents an unreviewed learner draft as a final professional record. | **Proposed NEW**; policy details **Unknown** | Clinical + teaching-system owner |
| `FR-RMIK-RJ-COMP-001` | RMIK reviews checklist items without editing the underlying clinical documentation. | **Proposed NEW**; checklist contents **Unknown** | RMIK + Clinical |
| `FR-RMIK-RJ-COMP-002` | Closure succeeds only when all owner-approved blocking items pass and applicable lifecycle guards permit it. | **Proposed NEW** | RMIK + affected domain owners |
| `FR-RMIK-RJ-COMP-003` | An incomplete review identifies each failed item and its reason without changing clinical facts. | **Proposed NEW** | RMIK |
| `FR-RMIK-RJ-COMP-004` | Every authoring, finalisation, review, denial and closure action is attributable and auditable. | **Proposed NEW** control | Clinical + RMIK + security/technical |

## 7. Actors, capabilities and decision points

Existing capability names are implementation facts; proposed subdivisions are design candidates and must not be treated as approved roles.

| Actor | Current capability evidence | Proposed bounded behavior | Owner decision required |
|---|---|---|---|
| Nurse | `clinical.nursing.write` exists; Run 4 save passed (**Observed** rebuild) | Create/save nursing draft; finalise only if the approved policy allows. | May a nurse finalise independently? Which professional/student distinctions matter? **Build gate** |
| Physician | `clinical.medical.write` exists; Run 4 save passed (**Observed** rebuild) | Create/save medical draft; finalise only if the approved policy allows. | May a physician learner finalise, or is supervisor co-sign required? **Build gate** |
| Clinical learner/student | No distinct proven capability policy (**Unknown**) | Draft-only by default until owner decision. | Is learner status represented by role, assignment, session or user attribute? **Build gate** |
| Clinical supervisor/facilitator | Supervision behavior not proven (**Unknown**) | Review/return/finalise or co-sign only if explicitly approved. | Which action and audit meaning: review, co-sign, approve, or finalise? **Build gate** |
| RMIK officer | `rmik.review` and `rmik.completeness.signoff` exist (**Observed** rebuild configuration) | Review checklist and close when eligible; cannot alter clinical text. | Can the same actor review and close? Is a second-person sign-off required? **Build gate** |
| RMIK student | Distinct student policy **Unknown** | Draft checklist only by default; no close. | Is supervisor approval mandatory and how is assignment scoped? **Build gate** |
| RMIK supervisor | Exact workflow **Unknown** | Confirm checklist/close if a learner prepared it, if approved. | Required actions and separation of duties. **Build gate** |
| Administrator | Break-glass system administrator exists (**Observed** rebuild configuration) | Configure users/masters; no routine authorship or RM sign-off in this slice. | Confirm whether break-glass clinical/RMIK writes are prohibited or specially audited. |
| Registrar/other role | Wrong-role RMIK route denial passed in Run 4 (**Observed**) | No clinical authoring or RMIK sign-off; minimum patient context only under existing view permission. | Confirm any read restrictions beyond the current role matrix. |

### Proposed capability split for owner review

| Candidate capability | Purpose | Status |
|---|---|---|
| `clinical.nursing.draft` | Save nursing work in progress | **Proposed NEW** |
| `clinical.nursing.finalize` | Finalise a nursing note | **Proposed NEW** |
| `clinical.medical.draft` | Save medical work in progress | **Proposed NEW** |
| `clinical.medical.finalize` | Finalise a medical note | **Proposed NEW** |
| `clinical.note.supervise` | Return or co-sign a learner-authored note | **Proposed NEW**; semantics **Unknown** |
| `rmik.completeness.review` | Save checklist assessment without closing | **Proposed NEW** refinement of current `rmik.review` |
| `rmik.completeness.signoff` | Finalise completeness and close when every guard passes | Existing name; exact policy **Unknown** |

**Decision CLN-AUTH-01:** owners must approve the smallest role/capability model before build. A user-interface role label alone is never authorization; every write is checked server-side.

## 8. Proposed desk composition

The following is a **Proposed NEW** information architecture applying DEC-014 familiarity. It uses the three-column silhouette as a density and orientation bar; it does not claim that the exact clinical composition was observed in SAHABAT.

```text
┌──────────────────────────────────────────────────────────────────────────────┐
│ SIMULASI — DATA SINTETIS | Rawat Jalan | Riwayat (read-only) | Status RM    │
├──────────────────────┬────────────────────────────────┬──────────────────────┤
│ KONTEKS PASIEN       │ DOKUMENTASI KLINIS             │ KESIAPAN RM           │
│ No. RM / Nama / JK   │ [Catatan Keperawatan]          │ Status catatan        │
│ Tgl lahir / alergi*  │ [Catatan Medis]                │ Pemeriksaan checklist │
│ Poli / dokter / waktu│ field families, draft/final    │ Order aktif           │
│ Cara masuk/bayar**   │ author + supervisor status     │ Alasan belum lengkap  │
│ Encounter / antrian  │ Simpan Draf / Finalisasi***    │ Tinjau / Selesai RM   │
└──────────────────────┴────────────────────────────────┴──────────────────────┘
* display policy and source must be approved; ** context only, no charge/claim logic;
*** finalisation and supervision controls depend on owner decisions.
```

UI requirements:

- Indonesian labels are primary: **Konteks Pasien**, **Catatan Keperawatan**, **Catatan Medis**, **Simpan Draf**, **Finalisasi**, **Kelengkapan RM**, **Belum Lengkap**, **Lengkap**, **Tidak Berlaku**, **Alasan**, **Peninjau**, **Selesai RM**.
- UEU tokens and shared shell are reused; `SIMULASI — DATA SINTETIS` is always visible and non-dismissible.
- Patient and visit identity stays visible while authoring/reviewing; sensitive context is minimized to the task.
- The right column shows source-derived status. It must not imply “Lengkap” merely because a tab exists or a free-text note is non-empty.
- Disabled actions include a visible Indonesian reason; server enforcement remains authoritative.
- The layout must remain usable at supported narrower widths through ordered stacking and preserve keyboard focus, labels, errors and touch targets.
- Klaim, BPJS and Apotek remain **Soon**. No fake-live integration or clinical action is presented.

## 9. Structured field-family decision matrix

This matrix intentionally stops at **field families**. It does not define clinical content, mandatory values, normal ranges, scoring thresholds, terminology or conditional logic. Owners must decide those items using approved policy and curriculum evidence.

### 9.1 Shared context and provenance

| ID | Proposed Indonesian family/label | Evidence | Proposed handling | Owner decision |
|---|---|---|---|---|
| `FF-SH-01` | **Identitas Pasien** | Encounter binding is **Proposed NEW**; patient context visible in current rebuild (**Observed**) | Read-only patient ID/public encounter link, No. RM, name and limited demographics. | Exact minimum and masking by role. **Build gate** |
| `FF-SH-02` | **Informasi Kunjungan** | Clinic/doctor/time context **Observed** structurally | Read-only visit date/time, clinic, assigned doctor, queue/encounter state. | Which fields are clinically necessary versus distracting. |
| `FF-SH-03` | **Penulis dan Waktu** | Doctor name/signature are **Manual-documented / historic**; attribution is **Proposed NEW** | Server-derived author, role and timestamp; never user-typed identity. | Meaning of electronic sign/finalisation in a teaching system. **Build gate** |
| `FF-SH-04` | **Status Catatan** | **Proposed NEW** | Explicit Draft/Final and, if approved, Returned/Co-signed state. | Select state model in section 10. **Build gate** |

### 9.2 Catatan Keperawatan

| ID | Candidate field family / Indonesian label | Evidence | Default specification status | Clinical owner decision |
|---|---|---|---|---|
| `FF-NUR-01` | **Keluhan dan Riwayat Singkat** | Anamnesis is **Manual-documented / historic**; nurse-specific ownership **Unknown** | Candidate family only; requiredness **Unknown** | Include? Who owns shared history? Required/conditional/optional? |
| `FF-NUR-02` | **Tanda Vital** | Plausible outpatient assessment need (**Inferred**); exact fields/rules **Unknown** | Do not assume measurements, units, ranges or alert thresholds. | Which measurements, units and conditional validations? **Build gate if included** |
| `FF-NUR-03` | **Alergi / Risiko Keselamatan** | Structured allergy/risk documentation is **Unknown** in the current rebuild and available vendor evidence | Candidate display/documentation family; no default “tidak ada”. | Source, values, requiredness and escalation behavior. **Build gate if included** |
| `FF-NUR-04` | **Pengkajian Keperawatan** | Current rebuild nurse-note save is **Observed**; exact vendor/SAHABAT assessment is **Unknown** | Structured sections may contain bounded narrative/selects after approval. | Minimum subfields, terminology and supporting policy. **Build gate** |
| `FF-NUR-05` | **Rencana / Tindakan Keperawatan** | Nursing-specific structure is **Inferred**; current free-text nurse note is **Observed** only in the rebuild | Candidate family; no billing/tariff effect in this slice. | Include, defer or exclude; if included, separate plan/performed action and requiredness need evidence. |
| `FF-NUR-06` | **Edukasi dan Tindak Lanjut** | **Inferred** | Excluded unless owner promotes with evidence. | Include now, defer, or exclude. |
| `FF-NUR-07` | **Catatan Tambahan** | Existing free-text note save is **Observed** rebuild behavior | Optional bounded narrative, not a substitute for required structured families. | Allow? Maximum length and appropriate use. |

### 9.3 Catatan Medis

| ID | Candidate field family / Indonesian label | Evidence | Default specification status | Clinical owner decision |
|---|---|---|---|---|
| `FF-MED-01` | **Anamnesis / Riwayat Penyakit** | **Manual-documented / historic** | Candidate family; requiredness and subdivisions **Unknown** | Minimum subfields and whether physician may reference nursing history. **Build gate** |
| `FF-MED-02` | **Pemeriksaan Objektif** | Medical examination is **Manual-documented / historic** | Candidate family; no invented body-system template or normal defaults. | Minimum subfields and whether vital signs are referenced or re-entered. **Build gate** |
| `FF-MED-03` | **Asesmen Klinis** | Diagnosis is **Manual-documented / historic** | Narrative/problem statement only in this slice; ICD coding remains out of scope. | Label, requiredness and structured-vs-narrative form. **Build gate** |
| `FF-MED-04` | **Rencana Pelayanan** | Actions/continuation are **Manual-documented / historic** | Candidate bounded narrative/select family; no prescription, charge or integration effects. | Minimum plan categories and requiredness. **Build gate** |
| `FF-MED-05` | **Tindak Lanjut / Disposisi** | Continuation values are **Manual-documented / historic**; current exact values **Unknown** | Do not implement a disposition list until approved. | Approved teaching values and any conditional documentation. **Build gate if included** |
| `FF-MED-06` | **Catatan Tambahan** | Existing free-text note save is **Observed** rebuild behavior | Optional bounded narrative, not a replacement for approved structured families. | Allow? Maximum length and appropriate use. |

### 9.4 Minimum field-family decisions — owner response sheet

For every included family, the Clinical owner must complete this table before build. “Required” must mean required for a defined action, not merely visually present.

| Family ID | Include / defer / exclude | Required for save draft? | Required for finalise? | Conditional rule and source | Allowed value type / terminology | Clinical owner initials/date |
|---|---|---|---|---|---|---|
| `FF-NUR-01` |  |  |  |  |  |  |
| `FF-NUR-02` |  |  |  |  |  |  |
| `FF-NUR-03` |  |  |  |  |  |  |
| `FF-NUR-04` |  |  |  |  |  |  |
| `FF-NUR-05` |  |  |  |  |  |  |
| `FF-NUR-06` |  |  |  |  |  |  |
| `FF-NUR-07` |  |  |  |  |  |  |
| `FF-MED-01` |  |  |  |  |  |  |
| `FF-MED-02` |  |  |  |  |  |  |
| `FF-MED-03` |  |  |  |  |  |  |
| `FF-MED-04` |  |  |  |  |  |  |
| `FF-MED-05` |  |  |  |  |  |  |
| `FF-MED-06` |  |  |  |  |  |  |

## 10. Draft, final and supervision state proposal

The following state model is **Proposed NEW**, not observed SAHABAT behavior. Finalisation must be a separate explicit action; saving data must not silently finalise it.

```text
No note -> DRAFT -> FINAL
              |
              +-> RETURNED -> DRAFT       (only if supervision is approved)

Learner path candidate:
DRAFT -> AWAITING_REVIEW -> FINAL          (supervisor action required)
```

| Decision | Option A | Option B | Required owner decision |
|---|---|---|---|
| Professional authoring | Draft and finalise by same authorised professional | Second-person review required | Clinical owner selects by note type. **Build gate** |
| Learner authoring | Draft-only; supervisor finalises/co-signs | Learner finalises under assigned session policy | Clinical + teaching owner. **Build gate** |
| Return for revision | Allow `RETURNED` before final only, with reason | No return state; supervisor edits? | Recommended boundary is return-before-final; direct supervisor editing of another author’s content needs explicit approval. |
| Final mutability | Final is read-only in this slice | Permit overwrite | Only read-only is eligible for this bounded slice; overwrite conflicts with attribution. Amendment remains out of scope. |
| RMIK effect | Only approved final notes can satisfy checklist presence | Draft may satisfy presence | RMIK + Clinical decide. **Build gate** |

Proposed state controls:

- Drafts retain author and latest-save time and are visibly labelled **Draf — belum final**.
- Finalisation rechecks authoritative encounter state, permissions, required fields and supervision assignment inside the transaction.
- A final note stores finalising actor/time and, if applicable, original author and supervisor separately.
- Final notes are read-only. This pack provides no correction, amendment, delete or reopen action.
- RMIK cannot change note state or clinical content.
- The system must not infer a signature from login alone; owners must define what the teaching label **Finalisasi** or **Tanda tangan elektronik simulasi** means.

## 11. RM completeness checklist decision matrix

### 11.1 Checklist model

The exact checklist remains **Unknown** under BR-RM-003. The items below are candidates for owner disposition, not approved clinical or regulatory rules.

Each approved item should have one of three review outcomes: **Lengkap**, **Belum Lengkap**, or **Tidak Berlaku**. `Tidak Berlaku` requires an approved applicability rule; **Belum Lengkap** requires a bounded reason. Checklist state must be computed/reviewed against the current source records and must not copy clinical content into an editable RMIK field.

| ID | Candidate item / Indonesian label | Evidence class | Proposed source | Blocking proposal | Owner decision |
|---|---|---|---|---|---|
| `CHK-RJ-01` | **Identitas pasien dan kunjungan terhubung** | Encounter binding **Proposed NEW** | Patient/encounter foreign keys and visit context | Block if missing/mismatched | Clinical + RMIK approve technical identity rule. **Build gate** |
| `CHK-RJ-02` | **Catatan keperawatan tersedia dan final** | **Proposed NEW**; nursing note exists in current rebuild (**Observed**) | Current nursing-note state | **Unknown** | Is it required for every RJ encounter or conditional? **Build gate** |
| `CHK-RJ-03` | **Penulis dan waktu catatan keperawatan tercatat** | Attribution **Proposed NEW** | Server-derived provenance | Block if CHK-RJ-02 applies and provenance absent | Clinical + RMIK confirmation. |
| `CHK-RJ-04` | **Anamnesis dan asesmen/diagnosis naratif tersedia dalam catatan medis final** | Historic **Kelengkapan Berkas** names Anamnesa and Diagnosa (**Manual-documented / historic**) | Current final medical-note state and approved field families; no ICD requirement | Proposed block, subject to current owner confirmation | Confirm whether each historic item is still applicable and what counts as complete. **Build gate** |
| `CHK-RJ-05` | **Dokter dan waktu finalisasi tercatat** | Historic **Kelengkapan Berkas** names Nama dokter and Ttd dokter (**Manual-documented / historic**); electronic attribution is **Proposed NEW** | Server-derived provenance | Proposed block when CHK-RJ-04 applies | Define teaching signature/finalisation meaning; do not reproduce a paper checkbox blindly. **Build gate** |
| `CHK-RJ-06` | **Keluarga field wajib catatan keperawatan terisi** | Exact fields **Unknown** | Owner-approved nursing schema validation | **Unknown** | Decide included families and blocking conditions from section 9. |
| `CHK-RJ-07` | **Keluarga field wajib catatan medis terisi** | Exact fields **Unknown** | Owner-approved medical schema validation | **Unknown** | Decide included families and blocking conditions from section 9. |
| `CHK-RJ-08` | **Supervisi catatan selesai** | Teaching supervision **Proposed NEW** | Assignment/review/finalisation records | Conditional block | Define when supervision applies. **Build gate if learner path enabled** |
| `CHK-RJ-09` | **Tidak ada order laboratorium aktif** | DEC-016 **Proposed NEW**; Run 4 UI behavior **Observed** rebuild | Authoritative lab-order state | Current proposed block | Decide only through DEC-016 review; remains Proposed. |
| `CHK-RJ-10` | **Alasan ketidaklengkapan tercatat** | **Proposed NEW** | Checklist review entry | Require when any manually reviewed item is Belum Lengkap | RMIK approves reason categories/free text policy. |
| `CHK-RJ-11` | **Pemeriksa kelengkapan dan waktu tercatat** | **Proposed NEW** | Server-derived RMIK reviewer/time | Block sign-off if absent | RMIK confirmation. |

### 11.2 Checklist owner response sheet

| Checklist ID | Include / defer / exclude | Automatic / manual / hybrid | Applies when | Blocks RM close? | Evidence/policy source | Clinical initials/date | RMIK initials/date |
|---|---|---|---|---|---|---|---|
| `CHK-RJ-01` |  |  |  |  |  |  |  |
| `CHK-RJ-02` |  |  |  |  |  |  |  |
| `CHK-RJ-03` |  |  |  |  |  |  |  |
| `CHK-RJ-04` |  |  |  |  |  |  |  |
| `CHK-RJ-05` |  |  |  |  |  |  |  |
| `CHK-RJ-06` |  |  |  |  |  |  |  |
| `CHK-RJ-07` |  |  |  |  |  |  |  |
| `CHK-RJ-08` |  |  |  |  |  |  |  |
| `CHK-RJ-09` |  |  |  |  |  |  |  |
| `CHK-RJ-10` |  |  |  |  |  |  |  |
| `CHK-RJ-11` |  |  |  |  |  |  |  |

### 11.3 Proposed completeness workflow

```text
Eligible encounter -> RMIK opens Kelengkapan RM
                   -> system shows source-derived indicators
                   -> reviewer records applicable manual outcomes/reasons
                   -> save review as DRAFT
                   -> sign-off rechecks current source versions + lifecycle guards
                        -> PASS: record attributable sign-off and close
                        -> FAIL: no close; show item-specific Indonesian reasons
```

The signed review must bind to the source note IDs/versions or an equivalent deterministic source fingerprint. A later source change would make the review stale, but source amendment is outside this slice; the exact stale-review workflow is therefore **Unknown** and cannot be implemented until amendment/reopen policy exists.

## 12. Functional rules and validations

| Rule ID | Rule | Evidence class | Required behavior / Indonesian feedback |
|---|---|---|---|
| `BR-RJ-DOC-001` | Every note is bound to exactly one patient and encounter by server-derived identifiers. | **Proposed NEW** | Reject orphan/mismatched write without mutation; do not trust hidden client identity fields. |
| `BR-RJ-DOC-002` | Authoring requires the exact note-type capability and an eligible encounter. | **Proposed NEW** | Wrong role receives generic unauthorized response; protected state is not disclosed. |
| `BR-RJ-DOC-003` | Draft saves validate data types, safe length bounds and allowlisted field keys. | **Proposed NEW** | Field-level Indonesian errors; exact lengths/types come from approved schema, not this pack. |
| `BR-RJ-DOC-004` | Finalisation additionally validates every owner-approved required/conditional family. | **Proposed NEW** | Keep note Draft and show item-specific errors; no partial finalisation. |
| `BR-RJ-DOC-005` | No clinical note can be created or finalised after encounter closure. | **Proposed NEW** teaching safety | Reject without mutation; **“Kunjungan sudah ditutup. Catatan tidak dapat disimpan.”** |
| `BR-RJ-DOC-006` | Final notes are immutable in this bounded slice. | **Proposed NEW** | No edit/delete UI or API; explain that correction is not available in this teaching slice. |
| `BR-RJ-DOC-007` | Concurrent/stale writes fail closed. | **Proposed NEW** | No last-write-wins overwrite; **“Catatan telah berubah. Muat ulang sebelum melanjutkan.”** |
| `BR-RJ-DOC-008` | Numeric/clinical plausibility alerts are not invented. | **Unknown** | Only validate approved types/ranges; no unapproved “normal” defaults or diagnostic advice. |
| `BR-RJ-DOC-009` | Empty/default UI values never imply clinical negatives. | **Proposed NEW** safety | Do not prefill “normal”, “tidak ada alergi”, normal vital signs or diagnosis. |
| `BR-RJ-RM-001` | RMIK review is read-only over clinical sources. | **Proposed NEW** | Reviewer records checklist outcomes/reasons only; cannot repair clinical facts. |
| `BR-RJ-RM-002` | Sign-off rechecks capabilities, encounter state, source versions, approved checklist and lifecycle blockers in one transaction. | **Proposed NEW** | Any failure leaves encounter/review source unchanged and shows the applicable reason. |
| `BR-RJ-RM-003` | Only approved blocking checklist items prevent close. | **Unknown** until matrix approval | Never convert an unapproved candidate item into a hidden blocker. |
| `BR-RJ-RM-004` | Active lab orders block close under DEC-016. | **Proposed NEW**, DEC-016 | Keep existing Indonesian blocker and stable reason `active_lab_orders`; DEC-016 remains Proposed. |
| `BR-RJ-RM-005` | `Tidak Berlaku` cannot be used as a generic bypass. | **Proposed NEW** | Accept only for approved applicability rules and audit the selection/reason. |
| `BR-RJ-RM-006` | A successful mutation and its audit event commit together. | **Proposed NEW** control | Roll back both on failure. |

No `Rule::exists` or equivalent validation may use a schema-qualified string such as `laravel.table`; implementations must use `SchemaAwareRules` or model classes as required by the project constraint.

## 13. Audit and record evidence

Proposed audit events must be attributable to actor, role/capability context, patient-safe resource identifiers, encounter, timestamp, outcome and stable denial reason. Audit metadata must exclude note bodies, credentials, session tokens and unnecessary patient demographics.

| Proposed action | When recorded | Minimum safe metadata | Example denial reasons to approve |
|---|---|---|---|
| `clinical.nursing.draft.save` | Nursing draft created/updated | note ID, encounter ID, version, author ID, outcome | `authorization_check_failed`, `encounter_closed`, `stale_version`, `validation_failed` |
| `clinical.nursing.finalize` | Nursing note finalised/denied | note ID/version, author/finaliser IDs, supervision status | same plus `supervision_required` |
| `clinical.medical.draft.save` | Medical draft created/updated | note ID, encounter ID, version, author ID, outcome | same as nursing |
| `clinical.medical.finalize` | Medical note finalised/denied | note ID/version, author/finaliser IDs, supervision status | same plus `supervision_required` |
| `clinical.note.supervision.record` | Returned/co-signed if approved | note ID/version, learner/supervisor IDs, action, bounded reason code | `assignment_mismatch`, `note_not_reviewable`, `stale_version` |
| `rmik.completeness.review.save` | Checklist draft saved | review ID/version, encounter ID, checklist definition/version, reviewer ID | `authorization_check_failed`, `source_stale`, `encounter_not_reviewable` |
| `rmik.completeness.signoff` | Completeness sign-off/closure succeeds or is denied | review ID/version, source fingerprint, failed item IDs only, actor ID | `checklist_incomplete`, `active_lab_orders`, `source_stale`, `encounter_not_ready` |

Stable reason names are **Proposed NEW** and require technical review. User-facing Indonesian messages may be clearer than the machine reason but must not disclose protected state to an unauthorized actor.

## 14. Privacy, simulation and operational controls

- `APP_MODE=SIMULATION` and backend synthetic-only enforcement are mandatory.
- Only clearly synthetic identities and clinical narratives may be used in development, tests, screenshots and hosted UAT.
- The permanent **SIMULASI — DATA SINTETIS** indicator appears on authentication and every authenticated desk.
- Do not place clinical note text in URL query strings, browser storage, audit metadata, logs, exception telemetry or test names.
- Apply least privilege and encounter/session assignment checks approved for the teaching scenario; a broad role alone must not silently expand access.
- Server responses and UI projections disclose only the minimum patient context required for the actor’s task.
- Database writes target the configured `laravel` schema. Vercel does not auto-migrate; every hosted release requires an explicit, evidenced Supabase migration step.
- No secrets, `.env` content or `DEMO_ACCOUNT_PASSWORD` are committed or copied into evidence.
- No live BPJS/VClaim/SATUSEHAT/LIS/PACS connection is introduced. Klaim, BPJS and Apotek remain **Soon**.
- Reset/cleanup must be scoped to the synthetic teaching scenario; global destructive reset is not an acceptance shortcut.

## 15. Acceptance scenarios

All scenarios use one or more clearly synthetic outpatient encounters and named disposable teaching actors. Acceptance requires UI evidence, authoritative database state where appropriate, and attributable audit evidence. A page render alone is insufficient.

| ID | Scenario | Expected result | Owner dependency |
|---|---|---|---|
| `UAT-RJ-DOC-01` | Authorised nurse saves a partially completed nursing draft. | Draft persists only approved fields, retains attribution/version and remains visibly **Draf**. | Approved nursing schema and draft rule. |
| `UAT-RJ-DOC-02` | Nurse attempts finalisation with a required family missing. | Finalisation denied without mutation; Indonesian field/checklist reason; audit denial. | Clinical requiredness decisions. **Build gate** |
| `UAT-RJ-DOC-03` | Authorised nurse finalises a complete note. | One immutable Final version with author/finaliser/time and success audit. | Clinical finalisation policy. |
| `UAT-RJ-DOC-04` | Authorised physician saves and finalises the approved medical families. | Final note attributable; ICD, prescription and charge effects absent. | Approved medical schema. **Build gate** |
| `UAT-RJ-DOC-05` | Clinical learner submits under the approved supervision path. | Cannot bypass required review; learner and supervisor provenance remain distinct. | Supervision decision. **Build gate if learner path included** |
| `UAT-RJ-DOC-06` | Wrong-role actor attempts clinical draft/final write. | Authorization denied before protected business-state disclosure; no note mutation; audit denial. | Capability model. |
| `UAT-RJ-DOC-07` | Author attempts stale concurrent save/finalise. | Stale request denied; prior version retained; audit reason stable. | Version/concurrency design. |
| `UAT-RJ-DOC-08` | Author attempts note write after encounter close. | Denied without mutation; closed state retained; audit denial. | Existing proposed lifecycle rule. |
| `UAT-RJ-DOC-09` | Actor attempts to edit/delete a Final note. | No supported action; direct request denied; Final content unchanged. | Bounded immutable-final policy. |
| `UAT-RJ-RM-01` | RMIK opens a READY_FOR_RM encounter. | Sees source-derived note status, approved checklist and active-order indicator; cannot edit clinical content. | Approved checklist. **Build gate** |
| `UAT-RJ-RM-02` | RMIK saves a checklist draft with one **Belum Lengkap** item. | Review persists with item/reason/actor/time; encounter remains open. | Reason policy. |
| `UAT-RJ-RM-03` | RMIK attempts sign-off with a blocking item incomplete. | Close denied without mutation; failed item IDs shown; denial audited. | Blocking matrix. **Build gate** |
| `UAT-RJ-RM-04` | RMIK uses **Tidak Berlaku** where applicability rule does not allow it. | Sign-off denied; no bypass or closure. | Applicability matrix. |
| `UAT-RJ-RM-05` | RMIK attempts close while an ACTIVE lab order exists. | UI blocker and server denial; encounter remains open; `active_lab_orders` audit. | DEC-016 remains Proposed; owner review required. |
| `UAT-RJ-RM-06` | All approved checklist items pass and lifecycle blockers are absent. | Review sign-off and encounter close commit together with attributable audit. | Clinical + RMIK checklist approval. |
| `UAT-RJ-RM-07` | Non-RMIK or draft-only RMIK student attempts sign-off. | Authorization/supervision denial, no state change, generic protected response. | RMIK teaching-role policy. |
| `UAT-RJ-RM-08` | Source version changes between review and sign-off in a test fixture. | Sign-off fails closed as stale; no closure. This test does not create an amendment UI. | Source-binding design. |
| `UAT-RJ-E2E-01` | Continuous role-switched hosted journey: registration → structured nursing → structured medical → RMIK checklist → close. | Same synthetic encounter and source versions reconcile across UI, DB and audit; no excluded module becomes live. | All build-gate decisions approved. |
| `UAT-RJ-E2E-02` | PostgreSQL `laravel` schema suite and supported local test database execute the normal and denial paths. | Both pass; explicit migration evidence attached before Vercel verification. | Technical release gate. |

## 16. Traceability

| Requirement / decision | Evidence and upstream trace | Acceptance trace | Status after this pack |
|---|---|---|---|
| `FR-CLN-RJ-STRUCT-*` | PAR-CLN-004; recorded Observed route; tracked 2018 manual/manual map; Run 4 R4-02/R4-03 | `UAT-RJ-DOC-01`–`09`, `UAT-RJ-E2E-01`–`02` | Draft; owner decisions open |
| `FR-RMIK-RJ-COMP-*` | PAR-RMIK-001; recorded Observed route; historic **Kelengkapan Berkas**; BR-RM-003 Unknown; Run 4 R4-05/R4-09 | `UAT-RJ-RM-01`–`08`, `UAT-RJ-E2E-01`–`02` | Draft; owner decisions open |
| `BR-RJ-RM-004` | DEC-016; order/result/closure contract; Run 4 | `UAT-RJ-RM-05` | **Proposed; not owner accepted** |
| Desk composition | DEC-014; `CURRENT_BASELINE.md`; SAHABAT evidence PNGs | Visual/accessibility review plus `UAT-RJ-E2E-01` | Proposed clinical application of accepted density direction |
| Authorization | Phase 2 RBAC matrix; Run 4 wrong-role denial | `UAT-RJ-DOC-06`, `UAT-RJ-RM-07` | Existing baseline plus proposed refinements |
| Audit/privacy/synthetic | `SECURITY_PRIVACY_AND_AUDIT.md`; DEC-015; Run 4 | Every success/denial scenario; release evidence | Mandatory control boundary |

Source references:

- `docs/new-simrs-rebuild/REQUIREMENTS_GOVERNANCE.md`
- `docs/new-simrs-rebuild/phase-1/requirements/PAR-CLN-004-rawat-jalan-examination.md`
- `docs/new-simrs-rebuild/phase-1/requirements/PAR-RMIK-001-rawat-jalan-rm.md`
- `docs/new-simrs-rebuild/phase-1/OUTPATIENT_ORDER_RESULT_CLOSURE_CONTRACT.md`
- `docs/new-simrs-rebuild/phase-0/DECISION_LOG.md` (DEC-014, DEC-015, DEC-016)
- `docs/new-simrs-rebuild/phase-0/CURRENT_BASELINE.md`
- `docs/vendor-simrs-assessment-2026-08-21/MANUAL_COMPLETE_MAP.md`
- `docs/vendor-simrs-assessment-2026-08-21/SIMRS_Manual_vendor.txt` (RM Rawat Jalan/Rawat Inap form and historic **Kelengkapan Berkas** description)
- `docs/operations/TEACHING_UAT_CONTINUOUS_RJ_LIFECYCLE_2026-08-24.md`

## 17. Open owner questions

### Clinical owner — required before build

1. Which nursing and medical field families in section 9 are included, deferred or excluded?
2. For each included family, what is required for Draft versus Final, and what policy/curriculum source supports it?
3. What does Final mean in this synthetic teaching system, and may the original professional author finalise alone?
4. What supervision path applies to clinical learners, and how is supervisor assignment established?
5. Which data types, units, value sets, conditional rules and safe length limits apply? No physiologic range or “normal” default will be assumed.
6. Does every outpatient encounter require both a nursing and a medical note, or are there approved applicability conditions?
7. Which limited patient/risk context should remain continuously visible, and which should be masked by role?

### RMIK owner — required before build

1. Which candidate items in section 11 form the minimum outpatient completeness checklist?
2. Which items are automatic, manual or hybrid, and which block closure?
3. When may **Tidak Berlaku** be used, and what reason/evidence is required?
4. May one RMIK professional review and close, or is a second-person sign-off required?
5. What may an RMIK student prepare, and what must a supervisor approve?
6. Must only Final/co-signed notes count as complete?
7. What are the approved incompleteness reason categories and follow-up behavior, given that clinical correction/reopen is outside this slice?

### Joint Clinical/Laboratory + RMIK question kept separate

8. Approve, revise or reject DEC-016’s active-order closure, FINAL-only and late-result rules. This pack neither approves DEC-016 nor makes it SAHABAT-observed.

## 18. Decision and signature record

Each accountable owner should choose **Approve as written**, **Approve with recorded revisions**, **Reject**, or **Defer**. A signature here approves only this bounded requirements pack and the explicitly recorded decisions; it does not grant clinical production use or complete parity acceptance.

| Decision area | Required owner | Decision | Revisions / conditions / source | Name | Signature or recorded approval reference | Date |
|---|---|---|---|---|---|---|
| Nursing field families and finalisation | Clinical owner |  |  |  |  |  |
| Medical field families and finalisation | Clinical owner |  |  |  |  |  |
| Clinical learner/supervisor workflow | Clinical + teaching-system owner |  |  |  |  |  |
| RM checklist items/applicability/blocking | RMIK + Clinical owners |  |  |  |  |  |
| RMIK student/supervisor and separation of duties | RMIK owner + teaching-system owner |  |  |  |  |  |
| Authorization, audit and synthetic controls | Security/technical + affected owners |  |  |  |  |  |
| Bounded scope and implementation priority | Product owner |  |  |  |  |  |
| DEC-016 lifecycle contract | Clinical/Laboratory + RMIK owners | **Proposed — unresolved** | Record separately in DEC-016 or a superseding DEC |  |  |  |

## 19. Build-entry and exit gates

### Ready for build only when

- Clinical owner completed the field-family response sheet and Draft/Final/supervision decisions.
- RMIK and Clinical owners completed the checklist response sheet, including applicability and closure blockers.
- Teaching student/supervisor roles and assignments are explicit.
- Exact data schema, validation types and safe bounds reflect approved decisions without invented clinical rules.
- Authorization and audit event design has technical/security review.
- DEC-016 remains visibly Proposed unless its separate owner review has occurred; implementation can retain the existing fail-closed guard without relabelling it as accepted parity.
- Acceptance scenarios and synthetic fixtures are reviewed and testable.

### Done only when

- Normal, incomplete, wrong-role, stale/concurrent, closed-encounter and supervision scenarios pass.
- PostgreSQL schema `laravel` and the supported local test database pass the same core behavior.
- A production-target Vercel demo migration is run explicitly and evidenced before hosted UAT.
- One continuous synthetic hosted UAT reconciles UI, database state and audit events.
- Clinical and RMIK owners review the evidence and record acceptance or remaining gaps.
- PAR-CLN-004 and PAR-RMIK-001 statuses are updated only to the level actually earned; a successful build or UAT alone does not make them parity accepted.
