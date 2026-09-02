# T1 Local Medication Replenishment and Warehouse-to-Depot Custody Evidence Template

Status: **READY / NOT RUN**
Scope: `MEDICATION_REPLENISHMENT_AND_WAREHOUSE_DEPOT_CUSTODY_V1` on disposable local PostgreSQL 17.10 and MySQL 8.4.11 only.

This template is not execution evidence. It is a bounded evidence contract for a future exact-engine rehearsal. It may be completed only after both engines run the same unchanged source aggregate, all 29 scenarios below pass with proof metadata, and strict cleanup is verified. No value in this template is evidence that a rehearsal has run.

The warehouse migration, service/model, authorization, guard, audit, and focused SQLite test sources now exist in the local checkout. They are deliberately not frozen into this template or the readiness scaffold while active security correction continues. Their presence does not change this template from `READY / NOT RUN`, does not prove PostgreSQL/MySQL behavior, and does not authorize schema activation, routes, hosted migration, or hosted capability. The single-checkpoint release keeps `WAREHOUSE_SCHEMA_MIGRATION_ENABLED=false` and excludes both `2026_09_03` warehouse migrations from its hosted migration allowlist.

Release/cutover status for `2026_09_03_000200_create_medication_replenishment_warehouse_custody_tables` is **SHIPPED / PENDING — NOT APPLIED** on PostgreSQL/MySQL. Ordinary exact-engine migrations leave it absent from the ledger. A future governed run may apply it only with `WAREHOUSE_SCHEMA_MIGRATION_ENABLED=true` and `DB_CONNECTION=warehouse_migrator`, after the separate identities, grants, security-definer routines, and this portability harness are ready. SQLite still applies it as the fast local gate. Recovery evidence accepts this migration alone as absent or applied; no other ledger drift is permitted.

## Immutable paired artifacts

The future harness must create one new regular JSON artifact per engine beneath `storage/app/portability-rehearsals/`. The directory must be mode `0700`, each artifact mode `0600`, and each artifact must be immutable after its SHA-256 is recorded here. The two artifact paths must be distinct.

| Engine | Exact version readback | Immutable local artifact path | Artifact SHA-256 | Recorded at UTC | Result | File mode |
|---|---|---|---|---|---|---|
| PostgreSQL | `17.10` | `NOT RUN` | `NOT RUN` | `NOT RUN` | `NOT RUN` | `NOT RUN` |
| MySQL | `8.4.11` | `NOT RUN` | `NOT RUN` | `NOT RUN` | `NOT RUN` | `NOT RUN` |

Never replace, edit, or reuse a recorded artifact. Verify every hash from disk after strict cleanup. An unexpected engine version, missing proof, changed source, or incomplete cleanup makes the pair `BLOCKED`, not partially passing.

## Authorization, harness, and source bindings

The source aggregate must be computed from the exact files used by both engine runs. The future harness must bind every implementation, migration, guard, projection, reset/recovery component, focused test, authorization, template, and contract listed below. Paths marked as placeholders are intentionally unresolved until the active security correction is frozen and the exact-engine harness binds the final file inventory; they must not be silently omitted.

| Binding | Required value |
|---|---|
| Authorization | `docs/new-simrs-rebuild/phase-1/MEDICATION_REPLENISHMENT_AND_WAREHOUSE_DEPOT_CUSTODY_V1_LOCAL_ENGINEERING_AUTHORIZATION_2026-09-02.md` |
| Future harness | `scripts/rehearse-local-medication-replenishment-warehouse-depot-custody-portability.rb` |
| Evidence template | `docs/operations/T1_LOCAL_MEDICATION_REPLENISHMENT_WAREHOUSE_DEPOT_CUSTODY_EVIDENCE_TEMPLATE_2026-09-03.md` |
| Template contract | `tests/Documentation/LocalMedicationReplenishmentWarehouseDepotCustodyEvidenceTemplateTest.rb` |
| Future harness contract | `tests/Documentation/LocalMedicationReplenishmentWarehouseDepotCustodyPortabilityHarnessContractTest.rb` |
| Authorization contract | `tests/Documentation/MedicationReplenishmentAndWarehouseDepotCustodyV1LocalEngineeringAuthorizationTest.rb` |
| Migration(s) | `<IMPLEMENTED LOCALLY; TO BE FROZEN AND BOUND BY FUTURE HARNESS — exact paths and digests required>` |
| Domain implementation | `<IMPLEMENTED LOCALLY; TO BE FROZEN AND BOUND BY FUTURE HARNESS — exact service/model/policy/guard paths and digests required>` |
| Focused feature/unit tests | `<PRESENT LOCALLY; TO BE FROZEN AND BOUND BY FUTURE HARNESS — exact paths and filtered test names required>` |

All three execution groups remain `TO BE BOUND BY FUTURE HARNESS` before an exact-engine run can begin.

The future harness `SOURCE_PATHS` list must be closed, duplicate-free, and include the authorization, future harness, both documentation contracts, every implementation/migration/guard/projection/reset/recovery file exercised, and every focused test actually used. It must not use a broad directory glob or silently bind an unlisted file.

Authorization bindings carried by this template are primary `PAR-ADM-017`, `PAR-ADM-030`, `PAR-PWH-002`, `PAR-PWH-003`, `PAR-PWH-005`, `PAR-PWH-006`, `PAR-PWH-011`, `PAR-PWH-012`, `PAR-PWH-014`, `PAR-PWH-016`, and `PAR-PWH-017`; prerequisite `PAR-PWH-001`, `PAR-PHA-001`, `PAR-PHA-002`, `PAR-PHA-003`, `PAR-PHA-015`, and `PAR-PHA-020`; and journey handoffs `E2E-11`, `E2E-10`, `E2E-02`, `E2E-03`, `E2E-04`, `E2E-14`, `E2E-16`, and later prerequisite `E2E-09`.

Record these values independently in each artifact:

| Shared binding | PostgreSQL artifact | MySQL artifact | Required relation |
|---|---|---|---|
| `aggregate_sha256` / `application_source_sha256` | `NOT RUN` | `NOT RUN` | identical |
| `worker_source_sha256` | `NOT RUN` | `NOT RUN` | identical |
| `scenario_catalog_sha256` | `NOT RUN` | `NOT RUN` | identical |
| `runtime_grant_catalog_sha256` | `NOT RUN` | `NOT RUN` | identical |
| `sqlite_gate_catalog_sha256` | `NOT RUN` | `NOT RUN` | identical |
| `command_catalog_sha256` | `NOT RUN` | `NOT RUN` | identical |

Engine-specific result hashes are recorded independently and are not required to match because they include backend identity, generated public identifiers, timing/wait observations, and serialized outcome details:

| Engine-specific binding | PostgreSQL artifact | MySQL artifact |
|---|---|---|
| `sqlite_gate.result_catalog_sha256` | `NOT RUN` | `NOT RUN` |
| `source_bindings.result_catalog_sha256` | `NOT RUN` | `NOT RUN` |

Any source change after the first engine run invalidates both artifacts and requires a fresh pair. A prior green run against another source aggregate is not acceptable evidence.

## Authorization and separation readback

The future harness must bind focused checks for the exact roles `procurement_officer`, `procurement_approver`, `warehouse_receiver`, `warehouse_inventory_controller`, `warehouse_inventory_supervisor`, `pharmacy_inventory_controller`, `pharmacist`, `pharmacy_technician`, and `admin`. It must verify the capability boundaries for purchase-order create/submit/review, receipt record, transfer dispatch/accept, supplier/unit return, correction request/review, stock-card view, and supplier/master management.

Purchase-order creator and approver, source dispatcher and destination acceptor, and correction requester and approver must be different actors. Authorization is checked before route-resource lookup and again in the domain service; unauthorized users receive no resource-existence disclosure. Administrator/reset authority is not a procurement, receiving, warehouse, pharmacy, supervisor, or runtime reset-bypass authority.

## Authorized local commands (NOT RUN)

The following commands are a future rehearsal contract, not an instruction to run now. The harness must refuse inherited database URLs, passwords, tokens, cookies, hosted configuration, and other external overrides before creating any state.

```sh
SIMRS_MEDICATION_REPLENISHMENT_WAREHOUSE_DEPOT_PORTABILITY_CONFIRM=YES_DISPOSABLE_LOCAL_MEDICATION_REPLENISHMENT_WAREHOUSE_DEPOT \
  ruby scripts/rehearse-local-medication-replenishment-warehouse-depot-custody-portability.rb postgresql17

SIMRS_MEDICATION_REPLENISHMENT_WAREHOUSE_DEPOT_PORTABILITY_CONFIRM=YES_DISPOSABLE_LOCAL_MEDICATION_REPLENISHMENT_WAREHOUSE_DEPOT \
  ruby scripts/rehearse-local-medication-replenishment-warehouse-depot-custody-portability.rb mysql8411
```

Only the named disposable local database/cluster, temporary server, reduced runtime identity, temporary worker, and temporary credentials may be created. SQLite is a fast application gate and does not substitute for either exact engine.

## Closed 29-scenario PostgreSQL/MySQL catalogue

Each engine artifact must contain this exact inventory in this order. Every row begins `NOT RUN` in this template and must later carry `PASS` plus non-empty sanitized proof metadata. No scenario may be added, removed, renamed, reordered, or marked `PASS` by assertion.

| # | Scenario | PostgreSQL 17.10 | MySQL 8.4.11 | Required proof binding |
|---:|---|---|---|---|
| 1 | `fresh-migration` | `NOT RUN` | `NOT RUN` | migration result, schema inventory, digest |
| 2 | `failed-install-guard-reapply` | `NOT RUN` | `NOT RUN` | forced failed install and guard readback |
| 3 | `empty-down-reapply` | `NOT RUN` | `NOT RUN` | empty rollback/reapply result |
| 4 | `constraints-and-append-only-triggers` | `NOT RUN` | `NOT RUN` | native constraint/trigger refusal fingerprints |
| 5 | `shortened-cross-engine-identifier-inventory` | `NOT RUN` | `NOT RUN` | identifier inventory and collision check |
| 6 | `exact-least-privilege-runtime-grants` | `NOT RUN` | `NOT RUN` | grant readback and denied mutation probes |
| 7 | `runtime-reset-bypass-denial` | `NOT RUN` | `NOT RUN` | runtime identity bypass refusal |
| 8 | `supplier-medicine-depot-version-binding-and-retirement` | `NOT RUN` | `NOT RUN` | version snapshots and retirement refusal |
| 9 | `purchase-order-lifecycle-and-independent-approval` | `NOT RUN` | `NOT RUN` | PO state transitions and decision evidence |
| 10 | `purchase-order-role-separation-and-route-denial` | `NOT RUN` | `NOT RUN` | creator/approver and route-resource denial |
| 11 | `approved-po-exact-receipt-binding` | `NOT RUN` | `NOT RUN` | approved PO fingerprint and receipt binding |
| 12 | `partial-full-receipt-and-variance-quarantine` | `NOT RUN` | `NOT RUN` | accepted/quarantined/rejected quantities |
| 13 | `duplicate-receipt-reference-and-over-receipt-refusal` | `NOT RUN` | `NOT RUN` | duplicate and excess quantity refusal |
| 14 | `paired-fefo-dispatch-and-transit-conservation` | `NOT RUN` | `NOT RUN` | FEFO selection and paired source/transit movement |
| 15 | `destination-accept-reject-full-transfer` | `NOT RUN` | `NOT RUN` | full accept/reject pair and reason |
| 16 | `existing-pharmacy-consumption-after-accepted-stock` | `NOT RUN` | `NOT RUN` | accepted lot reused by existing pharmacy flow |
| 17 | `supplier-return-linked-retained-receipt` | `NOT RUN` | `NOT RUN` | independent approval and negative movement |
| 18 | `unit-return-linked-accepted-transfer` | `NOT RUN` | `NOT RUN` | destination proposal and paired return legs |
| 19 | `append-only-correction-independent-review` | `NOT RUN` | `NOT RUN` | immutable request, decision, compensation |
| 20 | `stock-card-and-custody-conservation-reconciliation` | `NOT RUN` | `NOT RUN` | control totals, no negatives, no unexplained loss |
| 21 | `idempotency-replay-and-conflict` | `NOT RUN` | `NOT RUN` | same-key replay and changed-payload refusal |
| 22 | `competing-receipts-real-database-wait` | `NOT RUN` | `NOT RUN` | two processes, lock trace, observed wait |
| 23 | `dispatch-versus-pharmacy-handover-real-database-wait` | `NOT RUN` | `NOT RUN` | two processes, lock order, serial durable result |
| 24 | `accept-versus-reject-real-race` | `NOT RUN` | `NOT RUN` | one winning full decision and loser outcome |
| 25 | `third-connection-custody-control-total-readback` | `NOT RUN` | `NOT RUN` | independent custody, stock-card, audit reconciliation |
| 26 | `audit-failure-atomic-rollback` | `NOT RUN` | `NOT RUN` | zero orphan rows and unchanged balances |
| 27 | `tamper-recovery-and-bounded-reset` | `NOT RUN` | `NOT RUN` | corruption refusal, recovery zero mismatches, audit retained |
| 28 | `populated-migration-rollback-refusal` | `NOT RUN` | `NOT RUN` | rollback refusal with retained business/audit rows |
| 29 | `strict-cleanup` | `NOT RUN` | `NOT RUN` | process, database, identity, worker, credential absence |

The real-wait scenarios must use two independent application processes and prove backend connection identities, lock acquisition order, observed database waiting, and the final durable outcome. Sequential simulation is not acceptable. The third-connection scenario must independently reconcile supplier/PO/receipt quantities, central available and quarantined custody, transit, destination available and quarantined custody, pharmacy consumption, returns/corrections where exercised, stock cards, operation receipts, event digests, and required audit evidence.

The continuous journey must cover `E2E-11` supplier/PO/receipt/central custody/transfer/depot acceptance and reuse accepted synthetic stock through existing `E2E-10` pharmacy flow for at least one synthetic `E2E-02`, `E2E-03`, or `E2E-04` encounter, while retaining the `E2E-14` and `E2E-16` regression handoff bindings and the later prerequisite-only `E2E-09` boundary. It must not create an AP, accounting, bank, treasury, or patient-charge event.

## Shared application gates

Run these only after both exact-engine artifacts exist and their shared source aggregate remains current. Record command catalogue and result catalogue hashes.

| Gate | Required recorded result |
|---|---|
| Full backend suite | total tests, passed tests, intentional skips, assertions, duration, `PASS` |
| Focused authorization/documentation contracts | test and assertion counts, `PASS` |
| Focused warehouse/pharmacy feature and unit tests | exact filters, test and assertion counts, `PASS` |
| PHPStan | configured scope, error count `0`, `PASS` |
| Pint / formatting | checked scope and `PASS` |
| Diff check | checked paths and `PASS` |
| Frontend tests | file count, test count, `PASS` |
| Frontend lint | `PASS` |
| Frontend format check | `PASS` |
| Frontend type check | `PASS` |
| Frontend production build | `PASS` |

Focused UI evidence must cover Indonesian labels/worklists, semantic tables, keyboard-complete controls, visible focus, error-summary focus, committed-state announcements, textual states without color dependence, and minimum 44-pixel controls.

## Strict cleanup, secrecy, and integration boundary

Before an artifact can be recorded as `PASS`, the harness must verify and record:

- disposable database/cluster, generated runtime role, temporary server, temporary worker, temporary credentials, and all child processes were removed after success and failure paths;
- no generated database, process, socket, role, credential, or artifact outside the retained mode-`0600` record matches the rehearsal pattern;
- the artifact contains no password, token, API key, connection string, cookie, secret, raw SQL text, query text, or environment dump;
- `APP_MODE=SIMULATION` and synthetic-only supplier, staff, medicine, lot, warehouse, depot, PO, receipt, transfer, and encounter data were used;
- BPJS, VClaim, E-Klaim, SATUSEHAT, LIS, PACS, banking, treasury, supplier, payment, mail, and every other live/outbound integration remained disabled;
- no hosted Supabase/Vercel database, hosted migration, production alias, deployment service, real patient, real staff, real supplier, real stock, real price, or real payment was accessed;
- runtime reset bypass was refused, bounded reset preserved required audit evidence, populated rollback was refused, and recovery mismatch counts were zero.

Strict cleanup must be completed after success and failure paths before any artifact is eligible for `PASS`.

Any secret exposure, external access, hosted configuration, unexpected engine, source mismatch, missing proof, retained temporary state, or incomplete cleanup makes the pair `BLOCKED`.

## Honest non-claims

This is an exact-engine evidence template only, for future local engineering portability evidence only; it is not completed evidence. Even after a future paired `PASS`, it is not product-owner, procurement, pharmacy, warehouse, clinical, finance-accounting, security/privacy, operations/recovery, domain-owner, or facility acceptance; not legacy or SIMRS Sahabat parity acceptance; not G0 or G3 closure; not deploy/deployment, hosted migration, hosted UAT, production readiness, or live stock verification.

It does not prove AP/accounts-payable liability, accounting journal or ledger posting, invoice, tax, credit note, payment, bank or treasury reconciliation, revenue recognition, claim processing, BPJS/VClaim/E-Klaim/iDRG/SATUSEHAT integration, patient charge, sale price, real supplier delivery, or any real patient/staff/supplier/stock data. It does not authorize commit, push, deployment, production access, outbound delivery, or live integrations.

Final paired status: **READY / NOT RUN**
