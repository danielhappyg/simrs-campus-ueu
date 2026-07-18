# Outpatient Interaction Specifications

- **Version:** 1.1 reference specification
- **Scope:** Critical outpatient MVP interactions and failure recovery
- **Rule:** UI state follows confirmed server/domain state; animation or local component state never invents completion

## 1. Interaction-state vocabulary

| UI term                    | Domain meaning                                         | User expectation                                      |
| -------------------------- | ------------------------------------------------------ | ----------------------------------------------------- |
| `Draf`                     | Editable current version owned by the assigned author. | May save/edit within active assignment.               |
| `Diajukan untuk ditinjau`  | Exact version frozen and routed to reviewer.           | Author cannot overwrite it.                           |
| `Perlu perbaikan`          | Reviewer created one or more attributable findings.    | Author creates a successor version.                   |
| `Disetujui untuk simulasi` | Authorized reviewer attested to exact version.         | Ordinary edit is locked; not a legal e-signature.     |
| `Dikoreksi/Amendemen`      | Approved successor linked to prior version.            | Both versions remain inspectable.                     |
| `Diblokir`                 | A named prerequisite prevents transition.              | UI explains the prerequisite and authorized resolver. |
| `Menunggu`                 | A real task/event is pending.                          | UI identifies what/whom it is waiting for.            |
| `Selesai`                  | Domain transaction confirmed by server.                | Not inferred from closing a page.                     |

## 2. Open an assigned task

### Preconditions

- authenticated account;
- active acting role/assignment;
- accessible session, patient, encounter, and task;
- route policy re-evaluated on server.

### Flow

1. User activates the explicit queue action.
2. Control enters loading state with its label retained (`Membuka asesmen…`).
3. Server verifies task, assignment, encounter state, and current version.
4. Route opens with simulation banner and an empty/skeleton patient context—never stale data from the prior patient.
5. Patient/encounter context and task content resolve together.
6. Focus moves to page heading; a concise status message may announce that the task opened.

### Failure

| Failure                    | Response                                                                               |
| -------------------------- | -------------------------------------------------------------------------------------- |
| Assignment expired/revoked | Stay/return to queue; explain role/session changed and refresh tasks.                  |
| Encounter moved state      | Open read-only current state or redirect to the legitimate next task with explanation. |
| Access denied              | Safe access-denied page; no sensitive payload.                                         |
| Network/server error       | Keep queue state and filter; offer retry with correlation reference when available.    |

## 3. Draft creation and save

### Behavior

- first meaningful input creates a server draft or obtains a draft identity before autosave claims success;
- autosave is debounced after user pause and on section transition, with explicit current state;
- manual `Simpan draf` remains available;
- saves use version/ETag or equivalent optimistic concurrency token;
- the client sends only the current document/section contract, never unrelated read-only source content;
- `Tersimpan HH.MM` reflects a successful server response and server time;
- closing/navigation guards remain until all acknowledged changes are stored.

### Save state presentation

| State             | Label                          | Action                                                                                        |
| ----------------- | ------------------------------ | --------------------------------------------------------------------------------------------- |
| Clean             | `Tersimpan 09.07`              | none                                                                                          |
| Dirty             | `Perubahan belum disimpan`     | save available                                                                                |
| Saving            | `Menyimpan…`                   | prevent duplicate save; other safe editing may continue                                       |
| Retryable failure | `Gagal menyimpan`              | persistent inline alert + `Coba lagi`; values retained                                        |
| Conflict          | `Versi berubah di tempat lain` | stop autosave; open comparison/reload path                                                    |
| Session expired   | `Sesi masuk berakhir`          | preserve local entered values in memory, reauthenticate, then revalidate context before retry |

No browser local storage persists sensitive clinical drafts by default. If a future recovery cache is introduced, it requires a threat/privacy review, encryption posture, expiry, and session isolation.

## 4. Submit for review

### Preconditions

- current user owns the draft and still has assignment/capability;
- required fields pass deterministic validation;
- blocking prerequisite tasks are satisfied;
- latest server version matches the displayed version;
- a supervisor/review route exists according to scenario rules.

### Flow

1. User activates `Ajukan untuk ditinjau`.
2. Failed client-known validation focuses an error-summary heading and lists linked fields.
3. Server performs authoritative validation and policy checks.
4. Confirmation dialog summarizes document, encounter, current version, unresolved non-blocking warnings, and resulting lock/handoff.
5. User confirms.
6. Server transaction freezes the version, records submit/review events, and creates reviewer task.
7. UI changes to read-only `Diajukan untuk ditinjau`, announces success, and exposes `Kembali ke Pekerjaan Saya`.

### Rules

- pressing Enter in a field never submits the whole clinical document unexpectedly;
- warning acknowledgement does not bypass a blocking error;
- a double click/request is idempotent;
- success is shown only after the server transaction confirms;
- the submitted version/hash is visible in history/review.

## 5. Supervisor review

### Open

Reviewer sees exact submitted content, author/assignment, clinical and recorded time, source versions, prior feedback, and checklist. If a newer version already exists, the prior task is marked stale/read-only.

### Request changes

1. Reviewer adds at least one finding with section/category and clear comment.
2. `Minta perbaikan` dialog states that the submitted version remains unchanged.
3. Server records findings/action and creates an author task transactionally.
4. Author sees `Perlu perbaikan` and can create a new version from the prior content.

### Approve for simulation

1. Reviewer activates `Setujui untuk simulasi`.
2. Dialog states exact version/hash and that this is an internal simulation attestation.
3. Server rechecks reviewer relationship, document state, version/hash, and self-approval prohibition.
4. Approval, audit, and downstream task/state transition commit together.
5. UI shows reviewer/time/version and next handoff.

If the check finds a newer version, approval fails safely, review notes remain, and the reviewer is offered the current version.

## 6. Create a correction or amendment

### Changes-requested version

- `Buat versi perbaikan` clones the last reviewed content into a new draft linked by `supersedes`;
- findings remain visible beside affected sections;
- resolved findings are proposed by the author but closed only through configured review;
- unchanged fields preserve their prior value/provenance; changed sections are highlighted in comparison.

### After clinical closure/finalization

1. Authorized user creates an amendment request with affected document/version and reason.
2. Policy routes it to the appropriate author/supervisor/RMIK path.
3. New content is an amendment version; the original remains approved/current-until-amendment-approved.
4. On approval, timeline marks the relationship and RMIK completeness/coding checks rerun where affected.
5. UI never presents a simple `Edit` button on finalized content.

## 7. Change patient or acting role

### Patient switch

1. User activates `Ganti pasien` from patient context.
2. If the current page has dirty state, dialog offers `Tetap di halaman`, `Simpan draf lalu keluar`, and `Keluar tanpa perubahan lokal` only when policy permits abandoning the local unsaved delta.
3. On confirmed exit, patient-specific query caches, source data, draft keys, and review drawers clear before the next context renders.
4. New patient banner and content load together; focus moves to new page heading.

The prior patient's name/alerts must not flash in the new patient context.

### Implemented unsaved-change boundary

The versioned nursing-intake, medical-assessment, and encounter-closure authoring forms mount one shared guard only after editable form data differs from its last successful server baseline. The guard:

- shows `Perubahan belum disimpan` without copying the clinical content into the warning;
- intercepts ordinary Inertia `GET` navigation but never the form's own `POST` submission or a link prefetch;
- intercepts marked in-session browser Back/Forward before Inertia replaces the page, restores the current history entry, and holds only the target history position until the user decides;
- exposes the three named choices above in an accessible modal;
- replays the deferred destination only after the draft save succeeds, or after the user explicitly discards only the local unsaved delta;
- keeps the last server-saved version unchanged when the user discards local changes;
- uses the browser's native unload boundary for refresh, tab close, or external navigation; and
- persists no clinical draft in local/session storage.

A draft save keeps the guard open in a disabled `Menyimpan draf…` state until the request resolves. Validation, HTTP, network, or cancelled-request failure leaves the user on the same encounter form, announces `Draf belum tersimpan` without copying field content, restores `Simpan draf lalu keluar` for retry, and does not continue navigation. In-session history entries carry only an integer position—never form content—and Back/Forward replays the exact held target after successful save or explicit discard. Stale-session reauthentication/revalidation and live browser rehearsal remain open before faculty pilot; no cross-encounter draft recovery is claimed.

### Acting-role change

- user selects from active assignments, not arbitrary professions;
- changing role clears patient/task caches and returns to role-aware `Pekerjaan Saya`;
- unsaved draft protection applies;
- the acting assignment is sent explicitly or derived securely server-side and logged for material actions;
- holding two supervisor capabilities does not permit learner self-approval.

## 8. Registration and duplicate decision

### Search first

- normalize search input for matching without altering displayed identity;
- show candidate matches with enough identity cues but no diagnosis;
- do not select a candidate automatically;
- `Gunakan pasien ini` opens a confirmation with source encounter/session context.

### Create despite candidate

If the user chooses new patient while candidates exist:

1. require a coded reason plus optional comment;
2. show that automatic merge will not occur;
3. server repeats duplicate check inside creation transaction;
4. if a new high-confidence candidate appeared, return to compare state;
5. otherwise create patient + identifier provenance and audit decision.

### Check-in

Check-in creates/updates appointment registration, encounter, queue event, assignment link, and audit record transactionally. Repeated requests are idempotent and return the existing encounter rather than creating a duplicate.

## 9. Nursing intake and escalation

### Data entry

- observation input pairs value with fixed/selected unit and occurrence time;
- changing unit never silently changes the number; conversion, if later supported, displays both and records provenance;
- allergy state requires an explicit option;
- warnings distinguish `format/data completeness` from `configured teaching alert`;
- every configured question displays ruleset/version/help source where appropriate.

### Escalation

1. User selects `Eskalasi supervisor` and records the learner-authored reason.
2. Confirmation explains that routine flow will pause and the application gives no clinical recommendation.
3. Server records assessment version/decision, transitions encounter to `ESCALATED`, creates supervisor/facilitator task, and audits atomically.
4. All relevant pages show a persistent blocked banner.
5. Authorized supervisor/facilitator records configured disposition and reason.
6. Server resumes `WAITING_CLINICIAN`, ends as `TRANSFERRED_SIMULATION`, or cancels according to permitted transition.

The UI never calculates or displays an emergency acuity category in this outpatient slice.

## 10. Order and result loop

### Create order

- medical learner selects a governed test/service concept, authored reason/question, and scenario priority;
- submission/approval policy determines when the order becomes `ACTIVE`;
- task is created for the facilitator/result operator only after active state.

### Release synthetic result

- result page identifies `HASIL SIMULASI` and source fixture/scenario;
- releasing requires status, effective/issued time, values/conclusion, and source order;
- transaction stores version, audit, and acknowledgement task;
- preliminary/final/corrected states are explicit.

### Correct result

1. Authorized actor selects `Koreksi hasil`, supplies reason, and creates a successor.
2. Original remains visible as superseded.
3. Any prior acknowledgement does not automatically acknowledge the correction.
4. New acknowledgement task blocks closure if configured.
5. Medical plan changes use their own amendment/version flow.

## 11. Prescription and pharmacy review

### Prescription handoff

- only the current appropriately reviewed medication request can enter pharmacy queue;
- pharmacy sees derived patient/prescriber/date/unit/allergy/diagnosis source facts with provenance;
- missing required administrative source data is an explicit blocking fact, not silently inferred.

### Three-domain review

- progress shows administrative, pharmaceutical, and clinical domains;
- each required row has `Lengkap/tidak ada temuan`, `Temuan`, or configured `Tidak berlaku` plus comment requirements;
- choosing overall `Terima` is disabled with an explanatory message until all required domains are complete;
- the product states that review outcomes are learner/supervisor-authored.

### Intervention

1. Pharmacy opens an intervention against an exact medication request/item/version.
2. Affected item moves/remaining in a held review state; current facts cannot be edited from the intervention.
3. Medical role receives a task and responds with explanation, replacement, or cancellation through its own capability.
4. Replacement creates a new medication-request version/reference.
5. Pharmacy explicitly re-reviews current request; prior review is not copied as current approval.

### Dispense

- preparation, final check, quantity/outcome, handoff, and counseling are separate recorded steps;
- partial/no dispense requires reason;
- final action commits dispense, simulated stock movement, audit, and downstream completion together;
- on transaction failure, UI shows no successful dispense and retains entered data for retry;
- inventory can never become negative unless an explicit scenario rule and test permit it.

## 12. Encounter closure

### Blocking-check presentation

Before closure, show a grouped list:

- required clinical documents/status;
- unacknowledged results;
- unresolved pharmacy interventions/items;
- disposition/follow-up/summary completeness;
- required supervisor approvals.

Each blocker links to the authorized resolution route. A user without resolution capability sees who/what is awaited.

### Closure flow

1. Medical learner completes disposition, follow-up, education, and summary draft.
2. Submit/review follows document workflow.
3. Authorized approval requests clinical closure.
4. Server re-evaluates all blockers and current versions.
5. Successful transition records `CLINICALLY_CLOSED` and creates RMIK task atomically.
6. New routine clinical authoring is denied; amendment path remains.

## 13. RMIK completeness and coding

### Completeness

- checklist version is visible;
- each item derives from exact document/state/field/source rules;
- manual findings identify document/version/section and severity;
- rerun preserves prior review and resolutions;
- blocking findings prevent finalization.

### Coding

1. Coder selects clinician-authored diagnosis/procedure source and exact version.
2. `Buat saran` runs only against the configured classification/version and shows a loading state tied to a real server request.
3. Candidate cards show system/version/code/display, rank, confidence band, matched phrase/rule, engine version, and specificity warning where relevant.
4. The coder accepts one candidate into a draft, searches for another code, rejects the suggestions, or requests source correction. There is no `Terima semua` action.
5. An honest no-candidate state keeps manual search available and never fabricates a match.
6. Selected code stores system/version/code/display, source link, and whether the decision came from a candidate or manual search.
7. Coder submits; supervisor review follows configured policy.
8. If source is amended, existing suggestions remain historical and the assignment becomes `REVIEW_REQUIRED` rather than silently following changed text.

The coding endpoint cannot create/edit the source diagnosis. Candidate confidence describes retrieval strength, not clinical truth or permission to finalize.

### Correction request

RMIK records finding and route; the clinical author/supervisor creates the amendment. RMIK closes the finding after the updated exact version satisfies the checklist. No direct cross-profession edit exists.

## 14. Timeline and debrief

- the reference-MVP release gate is the server-recorded `FINALIZED` encounter state; an assigned participant/instructor with `debrief.view` may read it while the session is active or completed;
- default order uses clinical occurrence time with clear recorded-time annotations; deterministic tie-breaking uses server time/event ID;
- filters update URL state and announce result count;
- event expansion shows source/version/review relationship without displaying raw audit internals or secrets;
- `Tampilkan perubahan` compares structured sections and never implies that unchanged hidden metadata was absent;
- debrief annotations are shared teaching records separate from clinical source content; creation/revision requires `debrief.write`, a finalized encounter, an active session, a simulation attestation, and a server-authorized matching assignment;
- the latest shared note shows its type, author, assignment, version, authored time, and revision history; a revision requires a reason and never overwrites the prior version;
- learners and read-only auditors can read shared notes but never receive authoring controls or write authority;
- rubric references show scenario-bound code/version/status/source and linked learning outcomes; `PENDING_PROGRAM_REVIEW` is visibly non-scoring and creates no grade or competence verdict;
- export generation is asynchronous only when necessary, shows real status, and remains watermarked simulation.

The first reference projection is deliberately curated: material registration, workflow, clinical-version, result, pharmacy, closure, record-quality, correction, and human-coding decisions are included. Page views, searches, authorization denials, raw reasons/metadata, request IDs, IP hashes, user-agent strings, and content hashes are excluded from the learner-facing payload. Shared notes remain a separate versioned read model rather than becoming timeline events. See [ADR-004](../adr/ADR-004-FINALIZED-DEBRIEF-PROJECTION.md) and [ADR-005](../adr/ADR-005-DEBRIEF-NOTES-AND-RUBRIC-REFERENCES.md).

## 15. Session timeout and authentication recovery

### Warning

Before authenticated session expiry, an accessible dialog warns the user and offers `Lanjutkan sesi` or `Keluar`. It never extends silently when policy requires user presence.

### Expired during edit

1. Preserve current in-memory form state without persisting to unprotected browser storage.
2. Block further network mutation and show reauthentication flow.
3. After successful login, re-evaluate assignment, patient, encounter, document state, and version.
4. If still valid, allow retry/merge with conflict protection.
5. If no longer valid, permit safe copy/export only if policy allows; otherwise explain and retain no unauthorized persistence.

## 16. Error language

Error messages answer: what happened, what was preserved, what the user can do, and when another role is required.

| Avoid                               | Use                                                                                                 |
| ----------------------------------- | --------------------------------------------------------------------------------------------------- |
| `Error 422`                         | `Asesmen belum dapat diajukan. Periksa 2 bidang yang ditandai; draf Anda tetap tersimpan.`          |
| `Unauthorized`                      | `Peran Mahasiswa Keperawatan tidak memiliki akses ke telaah farmasi pada sesi ini.`                 |
| `Something went wrong`              | `Draf gagal disimpan karena layanan tidak merespons. Data pada formulir masih tersedia. Coba lagi.` |
| `Invalid vitals`                    | `Satuan tekanan darah belum dipilih.`                                                               |
| `Patient not found` after denial    | `Anda tidak dapat membuka rekam ini dari penugasan saat ini.`                                       |
| `Prescription approved` by software | `Telaah diterima oleh [aktor] untuk simulasi pada [waktu].`                                         |

Do not include database IDs, stack traces, tokens, or sensitive payloads in user-facing errors.

## 17. Keyboard contract

| Interaction           | Keyboard behavior                                                                                                 |
| --------------------- | ----------------------------------------------------------------------------------------------------------------- |
| Global search/command | `Ctrl/Cmd + K`, Escape closes, focus returns to trigger.                                                          |
| Sidebar               | Normal Tab navigation; collapse button announces state. No custom arrow-key pattern unless true composite widget. |
| Tabs                  | Arrow keys within tablist; Tab moves to active panel according to ARIA tabs pattern.                              |
| Combobox/code search  | Standard combobox pattern: arrows navigate, Enter selects, Escape closes, typed text retained appropriately.      |
| Dialog                | Focus moves inside, is trapped, Escape closes when safe, return focus to trigger.                                 |
| Drawer                | Focus moves to heading/first task; Escape closes when safe; return focus.                                         |
| Data table sort       | Header button accessible by Tab/Enter; direction announced.                                                       |
| Error summary         | Receives focus after failed submit; links move to and identify the field.                                         |
| Workflow rail         | Ordered links/buttons only for accessible steps; current uses `aria-current=step`.                                |
| Form submit           | Explicit button; no accidental whole-form submit from multiline/combobox interaction.                             |

Avoid application-wide custom shortcuts that conflict with assistive technology. Every shortcut has a visible-menu equivalent.

## 18. Telemetry and audit distinction

- product analytics measures screen/task performance with minimized synthetic context and no free-text clinical payload;
- domain audit records accountable clinical/teaching actions;
- operational logs diagnose systems and exclude secrets/content where not required;
- a click event is not a domain completion event;
- the interface displays domain status from committed data, not analytics or front-end timers.

## 19. Interaction acceptance criteria

- success states occur only after confirmed server transactions;
- retry/idempotency prevents duplicate patient, encounter, submission, result, intervention, dispense, or finalization records;
- unsaved/failed input remains recoverable without crossing patient/session boundaries;
- every submitted/approved/corrected action names an exact version and actor;
- wrong-role, wrong-session, wrong-patient, stale-version, and finalized-state requests fail safely;
- escalation, result correction, pharmacy clarification, partial dispense, and RMIK correction have complete resolution loops;
- keyboard/focus behavior is deterministic for dialog, drawer, tabs, combobox, validation, and navigation;
- error messages distinguish data-quality facts from clinical judgments;
- all user-visible integration/simulation states reflect actual persisted status.

## Related documents

- [UEU Clinical Design System](UEU_CLINICAL_DESIGN_SYSTEM.md)
- [Information Architecture](INFORMATION_ARCHITECTURE.md)
- [Outpatient Wireframes](OUTPATIENT_WIREFRAMES.md)
- [Outpatient Acceptance Scenarios](../product/OUTPATIENT_ACCEPTANCE_SCENARIOS.md)
