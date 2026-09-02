# Cross-setting pre-clinical encounter cancellation — FR and owner-decision pack

**Status:** **LOCAL IMPLEMENTATION PRESENT — focused integration verification passing; domain/parity acceptance remains open**<br>
**Date:** 2026-08-27<br>
**Environment:** `APP_MODE=SIMULATION`; synthetic data only<br>
**Primary parity capabilities:** `PAR-REG-001`, `PAR-REG-002`, `PAR-REG-003`<br>
**Affected workflows:** `E2E-01`, `E2E-02`, `E2E-03`, `E2E-04`, `E2E-12`, `E2E-15`, `E2E-16`<br>
**Reporting dependencies:** `PAR-RPT-007`, `PAR-RPT-013`, `PAR-RPT-016`, `PAR-RPT-017`, `PAR-RPT-019`<br>
**Companion ADR:** [ADR_CROSS_SETTING_PRECLINICAL_ENCOUNTER_CANCELLATION_2026-08-27.md](../../operations/ADR_CROSS_SETTING_PRECLINICAL_ENCOUNTER_CANCELLATION_2026-08-27.md)

## 1. Decision request

Approve, revise, reject, or defer one shared teaching rule for cancelling a synthetic RJ, IGD, or RI encounter **before care begins**.

The recommended boundary is:

```text
REGISTERED + synthetic + no dependent fact
  -> registrar confirms an approved reason
  -> immutable cancellation fact and success audit commit atomically
  -> encounter becomes CANCELLED
  -> active worklists exclude it; historical registers retain it
```

The product owner subsequently authorized continued local implementation within the synthetic-only, no-secret, no-live-integration, and no-premature-push/deploy boundary. That permits the bounded teaching implementation and local verification now present; it does not fill blank domain-owner rows, confer clinical/RMIK acceptance, establish Sahabat parity, close G0/G3, or authorize hosted migration/deployment. Blank owner rows are not consent.

## 2. Current evidence and unknown boundary

| Current evidence | Proven | Not proven |
| --- | --- | --- |
| `Capability::ENCOUNTER_CANCEL` and role matrix | Registrar/admin are presently assigned a capability named `encounter.cancel`. | Eligibility, state transition, reason, separation of duty, or downstream effects. |
| Current `Encounter` model | RJ/IGD/RI share active states and now expose terminal `CANCELLED` with explicit active, terminal and bed-occupancy semantics. | Vendor state vocabulary or Sahabat equivalence. |
| Cancellation implementation | One shared synthetic route, immutable cancellation fact, locked dependency check, idempotency and registered success/denial audit are locally implemented. | Domain/parity acceptance, hosted behavior or any external-system cancellation. |
| Examination and RM code | Clinical worklists use active status sets; RJ has documents/orders/reviews; IGD/RI have clinical entries. | A complete cross-domain “care started” policy. |
| RI registration | A bed is considered occupied by any RI encounter not `CLOSED`. | Approved release/history semantics for cancellation. |
| Canonical registration requirements | Cancellation must retain audit; queue/bed/charge effects are explicitly unknown. | Any owner-approved cancellation rule. |
| Vendor assessment | RJ, IGD, RI and reporting desks are visible. | Backend cancellation rules, data model, accounting, or audit behavior. |

The proposal is a clean-slate teaching safety rule, not a claim about Sahabat internals.

## 3. Owner choices

Record one choice in every row. “Approve recommended” means the exact recommended option and exclusions; revisions must provide replacement text.

| ID | Decision | Recommended option | Alternatives / consequence | Required authority | Recorded choice |
| --- | --- | --- | --- | --- | --- |
| CAN-01 | Eligible state | Only `REGISTERED`. | Broader states require clinical/RMIK correction rules. | Registration + Clinical + RMIK |  |
| CAN-02 | Care-start blocker | Block if **any** dependent fact exists, regardless of encounter status. | Status-only checks can miss a racing or malformed dependent fact. | Clinical + RMIK |  |
| CAN-03 | Reason catalogue | Required code plus optional Indonesian note, max 500 characters. | Free text only weakens reporting; mandatory narrative increases copied sensitive text risk. | Registration |  |
| CAN-04 | Permitted reason codes | `SALAH_PENDAFTARAN`, `DUPLIKAT_KUNJUNGAN`, `PASIEN_TIDAK_MELANJUTKAN`, `PERUBAHAN_RENCANA_SEBELUM_PELAYANAN`. | Owners may narrow/rename; no catch-all without a reporting rule. | Registration + ED + Bed Management |  |
| CAN-05 | Actor | Holder of `encounter.cancel`; registrar for routine use, admin only for governed correction. | Self-service patient/student cancellation is excluded. | Registration + Product |  |
| CAN-06 | Queue effect | Preserve `queue_date`/`queue_number`; never decrement or reuse. | Reuse creates duplicate historical tickets and race ambiguity. | Registration + Reporting |  |
| CAN-07 | Active worklists | Exclude `CANCELLED` from registration-active, triage, examination, lab, RM, and dashboard active counts. | Displaying as active can lead to accidental care. | Registration + Clinical + RMIK |  |
| CAN-08 | Historical registers | Retain row with status **“Dibatalkan”**, actor/time/reason code; provide include/exclude filter and separate total. | Removing it destroys reconciliation; counting as completed visit inflates activity. | Reporting + Registration |  |
| CAN-09 | RI bed effect | `CANCELLED` no longer occupies the bed; original ward/class/bed remain immutable history. | Clearing bed fields erases provenance; retaining occupancy blocks reuse. | Bed Management + Registration |  |
| CAN-10 | Retry behavior | Same idempotency key + same actor/payload returns prior success; changed payload conflicts. | Blind repeat can duplicate audit or obscure a changed reason. | Technical/security |  |
| CAN-11 | Repeated cancellation | Same completed operation is an idempotent replay; a second semantic cancellation is rejected. | Multiple cancellation facts create ambiguous current truth. | Registration + Audit |  |
| CAN-12 | Downstream facts added later | Hard-block v1 and route to a separately approved reversal/correction workflow. | Automatic charge/stock/claim reversal would invent rules. | Finance + Pharmacy + Inventory + Claims |  |
| CAN-13 | Patient/MRN effect | Retain the synthetic patient and MRN; cancel only the encounter. | Patient deletion or silent duplicate merge is a separate identity-governance workflow. | Patient Identity + RMIK |  |
| CAN-14 | Schedule capacity | Make no clinic-capacity restoration claim because no authoritative capacity ledger exists. | Updating display-only schedule metadata would imply a reservation was released. | Scheduling + Registration |  |
| CAN-15 | Printing | Block ordinary active queue/SEP/gelang output after cancellation; optionally add a separately approved **“Bukti Pembatalan — SIMULASI”**. | Current unmarked output could appear valid after cancellation. | Registration + RMIK + Reporting |  |
| CAN-16 | Related encounters | Never cascade automatically to an RJ/IGD/RI encounter referenced by `continue_from` or future episode links. | Cross-episode cancellation requires its own coordinated decision. | Registration + Clinical + RMIK |  |
| CAN-17 | Terminal state | `CANCELLED` is terminal; later service requires a new encounter and new queue number. | Reactivation/reopen would require a separate correction policy. | Product + Registration + RMIK |  |
| CAN-18 | External systems | Record only local synthetic cancellation; make no BPJS/VClaim/SATUSEHAT/Aplicares or other integration call. | A local state must never imply external cancellation succeeded. | Product + Integration/Security |  |
| CAN-19 | Reset/recovery | Cancellation belongs to the synthetic patient graph and may be removed by named synthetic reset; ordinary audit/security evidence remains preserved. | Reset is not ordinary cancellation and cannot erase audit evidence. | Teaching Data + Security/Operations |  |
| CAN-20 | Bed-concurrency claim | Release only the current app-level RI occupancy; do not claim full reservation, waitlist, transfer, or physical-bed concurrency parity. | The present sequential occupancy check is not a complete bed-management ledger. | Bed Management + Technical |  |

## 4. Proposed functional requirements

| Requirement | Proposed contract | Acceptance boundary |
| --- | --- | --- |
| `FR-CAN-001` | Resolve only a synthetic route-bound encounter and authorize `encounter.cancel` before exposing business state. | Wrong role receives the shared 403 denial without reason/state disclosure. |
| `FR-CAN-002` | Lock the encounter, re-read current state, and require `REGISTERED`. | Stale browser cannot cancel an encounter after care starts. |
| `FR-CAN-003` | Require zero dependent facts across the approved dependency registry. | Any dependency yields a stable denial and no mutation. |
| `FR-CAN-004` | Store one immutable cancellation fact with public ID, encounter, actor, reason code, optional bounded note, idempotency key/digest, correlation, and database time. | No update/delete path; unique encounter and actor/key constraints. |
| `FR-CAN-005` | Transition the encounter to `CANCELLED` in the same transaction as cancellation fact and success audit. | Audit failure rolls back both fact and state. |
| `FR-CAN-006` | Preserve registration, patient link, queue, payer, clinic/ward/bed, and original registration audit. | No destructive delete, nulling, or queue reuse. |
| `FR-CAN-007` | Remove cancelled encounters from all active queues/worklists and active bed occupancy. | Historical views still show cancelled status. |
| `FR-CAN-008` | Project cancelled rows and separate control totals in applicable registers/reports. | Cancelled is not counted as completed service. |
| `FR-CAN-009` | Make transport retries deterministic and conflicting replays explicit. | One durable cancellation and one success audit. |
| `FR-CAN-010` | Record business denials with stable allowlisted reasons after authorization passes. | No free-text note, patient identity, clinical content, or secret in telemetry/audit metadata. |
| `FR-CAN-011` | Make every direct note/document/order/result/RM mutation recheck a locked encounter and reject `CANCELLED`. | Worklist exclusion alone cannot protect a forged or stale direct request. |
| `FR-CAN-012` | Block ordinary active-looking prints after cancellation and preserve the patient/MRN and related episodes unchanged. | No deletion, merge, schedule restoration, or cascade side effect. |

## 5. Dependency registry proposed for v1

Cancellation is allowed only if every implemented dependency count is zero. Future domain tables must join this registry before their feature is released.

| Dependency | Current setting | Proposed result when present |
| --- | --- | --- |
| `clinical_entries` | All settings (normally IGD/RI today; also guard malformed/legacy RJ rows) | Deny `clinical_activity_exists`. |
| `outpatient_clinical_documents` and versions | RJ | Deny `clinical_activity_exists`, including Draft. |
| `lab_service_requests` and results | RJ | Deny `diagnostic_activity_exists`, regardless of order state. |
| `outpatient_rm_completeness_reviews` and items | RJ | Deny `rm_activity_exists`. |
| Future diagnoses/procedures/radiology/referrals | All | Deny until a separate reversal/correction policy is approved. |
| Future prescriptions/dispensing/MAR | All | Deny; pharmacy reversal is not part of v1. |
| Future charges/payments/receivables | All | Deny; finance reversal is not part of v1. |
| Future stock/lot movements | All | Deny; inventory correction is not part of v1. |
| Future claims/external postings | All | Deny; no BPJS/VClaim/SATUSEHAT or other live integration. |

An encounter status alone is insufficient evidence. Implementation must query the approved dependency registry while holding the encounter lock, and clinical/domain mutations must use the same encounter-first lock order so cancellation cannot race a new dependent fact.

Current IGD/RI note writes check status before their transaction and do not lock the encounter row. The implementation milestone must move those checks inside an encounter-first locked transaction (or a shared locked clinical-entry service). Outpatient services already provide stronger patterns, but each direct mutation must explicitly deny `CANCELLED`.

## 6. Proposed state, denial, and audit contract

### State

```text
REGISTERED -> CANCELLED

No transition from CANCELLED in v1.
No cancellation from IN_EXAMINATION, READY_FOR_RM, or CLOSED.
```

### Stable business denials

| Reason | Meaning | HTTP class |
| --- | --- | --- |
| `encounter_not_registered` | Current state is not eligible. | 409 |
| `clinical_activity_exists` | Clinical entry/document exists. | 409 |
| `diagnostic_activity_exists` | Order/result exists. | 409 |
| `rm_activity_exists` | RMIK review/sign-off evidence exists. | 409 |
| `downstream_activity_exists` | A future charge/pharmacy/stock/claim/integration dependency exists. | 409 |
| `idempotency_key_conflict` | Same actor/key carries a different canonical payload. | 409 |
| `already_cancelled` | A second semantic cancellation was attempted without matching replay receipt. | 409 |

The existing shared `authorization.denied` contract remains the wrong-role path. Validation errors use 422 and must not create cancellation/audit success.

### Success audit proposal

- action: `encounter.cancel`
- outcome: `SUCCESS`
- resource: encounter public ID
- safe metadata: care setting, cancellation public ID, reason code, prior/new status, queue date/number, affected active-worklist flags, RI bed-release boolean
- excluded: patient name/MRN/NIK, free-text note, clinical content, credentials, session/cookie values

Business denial audit uses the same action with `DENIED`, encounter public ID, an allowlisted reason, and care setting only.

Request correlation remains the established top-level `request_correlation_id`; it is not duplicated inside audit metadata. The cancellation fact may retain its bounded request identifier for idempotency/reconciliation, but telemetry and audit use only registered fields.

## 7. Architecture and portability acceptance contract

The local implementation uses these seams; their presence is engineering evidence, not domain/parity acceptance:

- `Encounter::STATUS_CANCELLED` and explicit active, terminal, examination, and bed-occupying status helpers;
- immutable `EncounterCancellation` model/table with one-to-one encounter constraint;
- `EncounterCancellationService` as the only state-changing seam;
- one semantic POST command at `/pendaftaran/kunjungan/{encounter}/batalkan`, shared by RJ, IGD, and RI registration pages rather than three implementations;
- `AuditEventSchemaRegistry` success and denial tuples;
- report/worklist projections that choose active versus historical status explicitly; and
- a single dependency registry, not controller-specific `exists()` fragments.

Required implementation invariants:

1. transaction lock order starts with encounter;
2. database constraints enforce one cancellation per encounter and one canonical receipt per actor/key;
3. route binding remains synthetic-only;
4. audit and mutation commit atomically;
5. supported PostgreSQL and MySQL constraints/index names remain portable and schema-aware;
6. no schema-qualified string is passed to `Rule::exists`/`Rule::unique`; and
7. migration rollback refuses to erase populated evidence during ordinary operation.

“Immutable cancellation fact” means no ordinary application update/delete path. It remains patient-domain evidence that the named synthetic reset may remove through an explicit FK cascade while append-only audit/security evidence is preserved. Do not add unconditional database update/delete triggers that would break the governed reset path unless a cross-engine reset-bypass design is separately reviewed.

Implementation remains backend-first: migration/model, dependency registry, service, audit schemas, and RJ/IGD/RI direct-writer locking/denial checks precede or fail closed beneath UI exposure. Registration UI, print, dashboard, recap, export, reset and direct-writer safeguards require focused tests before this local slice is called verified.

## 8. Indonesian UI and accessibility proposal

| Surface | Required behavior |
| --- | --- |
| Registration row | Show **“Batalkan Kunjungan”** only when the capability is present; disabled state explains why current facts block cancellation. |
| Confirmation dialog | Title **“Batalkan kunjungan sebelum pelayanan?”**; show encounter identity, care setting, queue/bed consequence, reason selector, optional **“Catatan pembatalan”**, safe default **“Kembali”**, destructive action **“Batalkan Kunjungan”**. |
| Completed state | Persistent status **“Dibatalkan”**, actor/time/reason code, queue retained, and RI message **“Tempat tidur tersedia kembali; riwayat penempatan tetap disimpan.”** |
| Historical register | Filter **“Status kunjungan”**, option **“Dibatalkan”**, and separate total. CSV exports the user-facing **“Status kunjungan”** value `Dibatalkan` plus the stable machine field **“Kode status kunjungan”** value `CANCELLED`. |
| Print | Ordinary queue/SEP/gelang actions are unavailable after cancellation. Any approved cancellation receipt must be visibly watermarked **“DIBATALKAN — SIMULASI”** and cannot resemble an active service document. |

The dialog must trap focus, be keyboard operable, restore focus to its trigger, expose a programmatic name/description, associate errors, announce success/failure, avoid colour-only status, preserve the simulation banner, and reflow at 320 CSS pixels. Free-text notes never enter URLs, toast titles, telemetry, or audit metadata.

## 9. Synthetic acceptance and UAT matrix

Each scenario uses a separate synthetic fixture and records before/after control totals.

| Scenario | Required proof |
| --- | --- |
| Normal RJ | Registrar cancels a `REGISTERED` encounter with no dependencies; queue number retained; removed from exam/RM active worklists; historical register shows cancellation. |
| Normal IGD | Cancel before triage/clinical entry; removed from triage and IGD examination worklists; Register IGD retains cancelled row. |
| Normal RI | Cancel before clinical entry; original ward/class/bed retained in history; another synthetic encounter can use that bed. |
| Authorization denial | Nurse/physician/RMIK without capability receives 403 before business-state details; shared denial audit exists. |
| Lifecycle denial | `IN_EXAMINATION`, `READY_FOR_RM`, and `CLOSED` are rejected with no state/fact change. |
| Dependency denial | One case for each implemented dependency family; all reject atomically with stable reason. |
| Concurrency | Cancellation racing first clinical write yields exactly one winner and no cancelled encounter with a dependent fact. |
| Idempotency | Same key/payload replays prior result; changed payload conflicts; no duplicate fact/audit. |
| Audit outage | Force success-audit failure; cancellation fact and state transition roll back. |
| Reporting reconciliation | active + cancelled controls reconcile to registrations; cancelled excluded from completed-service count; CSV/print representation matches UI. |
| Print and episode isolation | Ordinary active print is blocked; synthetic patient/MRN and any related encounter remain unchanged; no clinic-capacity restoration is claimed. |
| Reset/recovery | Synthetic reset removes patient-domain cancellation facts but preserves audit/security evidence per current reset contract; restore re-establishes fact/state/audit consistency. |
| Accessibility | Keyboard/dialog/focus/error/announcement, 320 px reflow, contrast, screen-reader labels, and hosted role-based acceptance. |

### Minimum automated evidence after approval

- focused feature tests for all scenarios above;
- migration/FK/check/unique/immutability tests;
- SQLite full suite;
- PostgreSQL 17 concurrency, migration, query-plan, rollback/reapply, backup/restore rehearsal;
- MySQL 8.4 migration, full suite, concurrency-sensitive cases, rollback/reapply;
- PHPStan, Pint, TypeScript, ESLint, Prettier, Vitest, accessibility automation;
- exact-SHA manifest and no-secret scan; and
- hosted synthetic role-based UAT only after a separate publication/deployment approval.

## 10. Reconciliation controls

For each cancelled encounter, prove:

1. one encounter, one immutable cancellation fact, one attributable success audit;
2. prior state `REGISTERED`, current state `CANCELLED`, and no later state transition;
3. queue date/number and registration audit unchanged;
4. zero dependent facts at cancellation commit;
5. active worklists and active bed occupancy exclude it;
6. historical registers include it exactly once with the same status/reason code, and dashboard totals label gross versus active explicitly;
7. patient/MRN, clinic schedule metadata, and related encounters remain unchanged;
8. ordinary active prints are blocked and no completed-service, charge, stock, claim, capacity, or external-integration effect was fabricated; and
9. retries and recovery do not duplicate or erase evidence.

## 11. Owner sign-off record

No blank row is approval. “Approved” must identify the authority, date, evidence reviewed, and any conditions. A revision changes this pack and ADR before implementation.

| Domain / decision responsibility | Authorized owner | Approve / revise / reject / defer | Conditions or replacement wording | Evidence reviewed | Date |
| --- | --- | --- | --- | --- | --- |
| Product scope and teaching outcome |  |  |  |  |  |
| Registration / front office |  |  | CAN-01, CAN-03–08, CAN-11 |  |  |
| Patient identity / master patient index |  |  | CAN-13; no patient deletion or merge |  |  |
| Outpatient scheduling |  |  | CAN-14; no capacity restoration claim |  |  |
| Emergency / IGD |  |  | IGD care-start and worklist boundary |  |  |
| Inpatient / bed management |  |  | CAN-09 and RI occupancy/history |  |  |
| Clinical |  |  | CAN-01–02 and dependency registry |  |  |
| RMIK |  |  | RM blocker and retention/report meaning |  |  |
| Reporting / management information |  |  | CAN-06–08, CAN-15 and control totals |  |  |
| Finance, pharmacy, inventory, claims |  |  | CAN-12 hard-block boundary; no reversal implied |  |  |
| Technical/security/operations |  |  | concurrency, idempotency, audit, migration, recovery, accessibility |  |  |
| Teaching data / reset authority |  |  | CAN-19; patient-domain fact versus preserved audit evidence |  |  |

## 12. Exact approval statement requested

If every required authority accepts the recommended boundary without revision, record:

> We approve the bounded synthetic-only cross-setting pre-clinical encounter-cancellation contract in `CROSS_SETTING_PRECLINICAL_ENCOUNTER_CANCELLATION_FR_PACK_2026-08-27.md` and its companion ADR for local implementation and verification. Approval is limited to `REGISTERED` RJ/IGD/RI encounters with no dependent fact; preserves encounter, patient/MRN, registration, queue, placement and related-episode history; restores no unproven schedule capacity; blocks ordinary active prints; introduces no clinical, diagnostic, RM, pharmacy, inventory, finance, claim, BPJS/VClaim/SATUSEHAT, or other live-integration reversal; and does not authorize publication, deployment, hosted migration, or production use.

Reject/defer leaves cancellation unavailable. Approve-with-revision requires the changed pack to be reviewed again before code.
