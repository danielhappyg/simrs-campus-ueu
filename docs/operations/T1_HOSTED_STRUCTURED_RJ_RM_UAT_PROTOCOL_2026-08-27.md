# T1 hosted Structured RJ/RM v1 UAT protocol — 2026-08-27

**Status:** `BLOCKED_ACCESS_LIFECYCLE_NOT_DEPLOYED`
**Execution:** `NOT_RUN`
**Boundary:** synthetic teaching demo only; no real patient data and no live integration
**Template:** `T1_HOSTED_STRUCTURED_RJ_RM_UAT_TEMPLATE_2026-08-27.json`
**Access runbook:** `T1_TEACHING_ROLE_ACCESS_RUNBOOK_2026-08-27.md`
**Current-reference capture:** `2026-08-27T11:17:24+07:00`; refresh again immediately before execution

This protocol closes the focused hosted-evidence gap identified in the current-truth baseline. It does not authorize a migration, Vercel promotion, owner acceptance, clinical production use, or any BPJS/VClaim/SATUSEHAT/LIS/PACS/payment transmission.

Continuous RJ lifecycle Run 4 proves the older hosted note/laboratory/closure journey. It does not prove the Structured Outpatient Documentation and RM Completeness v1 Draft/Final screens, immutable version rows, completeness fingerprint, stored checklist versions, attributable sign-off, or closed archive retrieval. This run must therefore use one fresh synthetic encounter and the four dedicated single-role accounts.

Lab is intentionally excluded from this focused journey: its hosted order/result behavior already has evidence, while DEC-016 owner acceptance remains open. The completeness check must still prove that the fresh encounter has zero active lab orders.

## Current release boundary

| Item | Current verified value | Execution rule |
| --- | --- | --- |
| Public Production alias | `https://simrs-campus-ueu-demo.vercel.app` | Refresh immediately before UAT |
| Public Production deployment | `dpl_4uGZACFzKsHMnNUy6YshiJjeQN1Q` | Current reference only |
| Public Production SHA | `42ab482de577fe38cef539a74f0b749d64485b19` | Current reference only |
| Latest `main` Preview SHA | `b3f9eb48d195fa1b2be02170b2dbd33eee20ae76` | Not Production |
| Structured-v1 schema | Four required tables present | Recheck before UAT |
| Dedicated role accounts | Four accounts disabled; zero retained authentication artifacts | Recheck before each activation |
| Narrow account lifecycle command | Locally implemented and reviewed; not deployed | Hard blocker |

Do not run this UAT until a reviewed, exact-engine-tested, exact-roster `teaching:role-access` command is deployed. Direct SQL, Tinker, `DemoActorsSeeder`, `simulation:reset`, and the deleted/stale `simulation:lab-access` command are not substitutes.

## Entry gates

Record a new evidence artifact and stop unless every gate passes:

1. Production alias, deployment ID and full source SHA resolve to the approved candidate.
2. `/up` and `/login` return HTTP 200.
3. Login and authenticated desks show **SIMULASI — DATA SINTETIS**.
4. Runtime reports `APP_MODE=SIMULATION` and `APP_SYNTHETIC_ONLY=true` without exposing environment secrets.
5. BPJS, VClaim, SATUSEHAT, LIS, PACS, payment and other live integrations remain disabled.
6. The four structured-v1 tables and required migration ledger entry exist.
7. All four dedicated accounts have their exact one role, are non-admin, disabled and have zero sessions/passkeys/reset tokens/remembered login/two-factor state.
8. Baseline counts for the fresh encounter are zero documents, zero versions, zero reviews and zero checklist items.
9. The deployed release includes the reviewed expiring lease table, release/environment/deployment/canonical-host binding, request-host enforcement, access epoch checks and alternate-authentication-path fencing.
10. A newly generated access value for the current role is available only from the approved secure channel and is absent from commands, chat, screenshots and evidence. It has never been used in an earlier role window.

Only one dedicated account may be active at any time. Revoke and verify the current account before activating the next account.

## Exact journey

### 1. Registrar — register and prove denial

Account: `registrar.demo@example.invalid`

1. Activate only `registrar` through the deployed `teaching:role-access` control, with an expiry no longer than the planned registrar step and the exact Production environment/source binding.
2. Register one fresh synthetic RJ patient and encounter.
3. Record the MRN, patient and encounter public IDs, queue, clinic, visit date, resulting `REGISTERED` state and `patient.register` audit ID.
4. Enter `/rm/rawat-jalan` directly, without relying on hidden navigation.
5. Require HTTP 403 and an attributable `authorization.denied` event with reason `authorization_check_failed`, resource type `http_route` and resource ID `rm.rawat-jalan.index`.
6. Prove the denial created no document, version, review, checklist or encounter-state mutation.
7. Log out, revoke `registrar`, prove password login fails and require zero retained authentication artifacts.

### 2. Nurse — attributable Draft and Final

Account: `nurse.demo@example.invalid`

1. Activate only `nurse` with a new access value and bounded expiry, then open the same encounter through Pemeriksaan → Rawat Jalan.
2. Confirm nursing is editable and medical is read-only.
3. Enter a synthetic nursing assessment and select **Simpan draf**.
4. Record Draft v1 public IDs, actor, timestamp and `clinical.nursing.draft.save` audit ID.
5. Select **Finalisasi versi** and verify immutable Draft v1 plus Final v2 in **Riwayat**.
6. Require document type `NURSING_ASSESSMENT`, definition `OUTPATIENT_DOCUMENTATION_V1`, final head `FINAL`/version 2, exactly two version rows, encounter `IN_EXAMINATION`, and a `clinical.nursing.finalize` audit.
7. Confirm the finalized nursing document is read-only.
8. Log out, revoke `nurse`, prove password login fails and require zero retained authentication artifacts.

### 3. Physician — required medical Draft and Final

Account: `physician.demo@example.invalid`

1. Activate only `physician` with a new access value and bounded expiry, then open the same encounter.
2. Confirm nursing is read-only and medical is editable.
3. Enter synthetic values for Anamnesis, Pemeriksaan objektif, Asesmen klinis and Rencana pelayanan.
4. Save Draft v1, then finalize v2; record public IDs, actors, timestamps and both audit IDs.
5. Require document type `MEDICAL_ASSESSMENT`, definition `OUTPATIENT_DOCUMENTATION_V1`, final head `FINAL`/version 2 and exactly two medical version rows.
6. Require encounter `READY_FOR_RM` only after medical Final and confirm the finalized document is read-only.
7. Log out, revoke `physician`, prove password login fails and require zero retained authentication artifacts.

### 4. RMIK — versioned completeness and closed archive

Account: `rmik.demo@example.invalid`

1. Activate only `rmik` with a new access value and bounded expiry; open RM → Rawat Jalan → **Tinjau RM** for the same encounter.
2. Require two source documents, four immutable version rows, a 64-character source fingerprint, blocker count zero and all seven checklist codes passing:
   - `IDENTITY_LINKED`
   - `NURSING_FINAL`
   - `NURSING_PROVENANCE`
   - `MEDICAL_FINAL`
   - `MEDICAL_REQUIRED_FIELDS`
   - `MEDICAL_PROVENANCE`
   - `NO_ACTIVE_LAB_ORDERS`
3. Select **Simpan hasil review**. Record review v1 public ID, `DRAFT` state, fingerprint, reviewer/time, seven persisted item states and `rmik.completeness.review.save` audit ID. Encounter must remain `READY_FOR_RM`.
4. Select **Sign-off dan tutup kunjungan**, confirm **Ya, sign-off dan tutup**, and record review v2 public ID, `SIGNED_OFF` state, unchanged fingerprint, signer/time, seven persisted item states and `rmik.completeness.signoff` audit ID.
5. Require encounter `CLOSED`. Return to the RM list and reopen it through **Lihat RM**.
6. Require an archive/read-only presentation with review/save and sign-off actions unavailable.
7. Log out, revoke `rmik`, prove password login fails and require zero retained authentication artifacts.

## Required evidence and counts

The completed evidence must include only synthetic public identifiers and minimized operational facts:

| Evidence | Required result |
| --- | --- |
| Encounter state sequence | `REGISTERED → IN_EXAMINATION → READY_FOR_RM → CLOSED` |
| Document heads | `2` |
| Document versions | `4` |
| Completeness reviews | `2` |
| Completeness checklist items | `14` |
| Active lab orders | `0` |
| Denial mutation delta | `0` across documents, versions, reviews, items and encounter state |
| Role access closeout | All four `DISABLED`; zero sessions/passkeys/reset tokens/remembered login/two-factor state |
| Audit trail | Registration, denial, two nursing, two medical, review-save and sign-off audit IDs |

Retain the fresh synthetic encounter and its audit chain. Do not use a global reset.

## Stop rules

Stop and record `NO_GO` without workaround if any of the following occurs:

- the deployment, SHA, schema or simulation boundary differs from the approved candidate;
- any real or plausibly real patient data appears;
- any live integration is enabled or contacted;
- more than one dedicated role account is active;
- an account has role/admin drift or retained authentication artifacts;
- activation or revocation lacks an attributable audit event or zero-artifact readback;
- a reused access value, expired lease, release/environment/deployment/canonical-host mismatch, request-host mismatch or stale access epoch is accepted;
- a wrong-role action mutates clinical/RM state;
- a Draft/Final/version/checklist/fingerprint invariant differs from this contract;
- credentials, cookies, tokens, password hashes or database connection material enter evidence;
- direct SQL/Tinker or a broad seeder/reset would be required.

## Acceptance boundary

A passing engineering UAT proves the deployed Structured RJ/RM v1 behavior for this bounded synthetic journey. It does not itself grant Clinical or RMIK owner acceptance, complete any of the 268 capability dispositions, resolve DEC-016, authorize radiology/pharmacy/claims work, or make the system suitable for real-patient hospital operation.

The exact activation, revocation, failure and rollback procedure is governed by `T1_TEACHING_ROLE_ACCESS_RUNBOOK_2026-08-27.md`.
