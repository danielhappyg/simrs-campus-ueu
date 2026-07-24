# Outpatient Pharmacy Independent Check Validation — 2026-07-24

## Decision boundary

This record validates the development implementation for the synthetic outpatient reference MVP. It does not constitute pharmacy-program, clinical-governance, or laboratory-pilot approval. Daniel remains the final scope and release decision-maker.

E-Klaim and claims workflows are outside this increment.

## Implemented workflow

1. The pharmacy learner submits an immutable, versioned preparation record.
2. Submission does not create a medication dispense, decrement stock, or release encounter closure.
3. The linked pharmacy supervisor receives a dedicated `SUPERVISOR_REVIEW` task with a working route to the pharmacy workspace.
4. The supervisor decides against the exact preparation version and SHA-256 content hash.
5. `REQUEST_CHANGES` preserves the reviewed version and requires an attributed successor.
6. `APPROVE_SIMULATION` atomically creates the final dispense, records distinct preparer and checker identities, posts the synthetic stock movement, completes the medication request, and releases downstream closure work.
7. Preparation, review action, final dispense, and stock movement records are immutable and append-only.

## Automated evidence

Validated from a clean candidate created from `HEAD` plus only the outpatient pharmacy increment:

- PHP application suite: 270 tests passed, 3,595 assertions.
- Focused pharmacy, reference-journey, clone, and preflight suite: 27 tests passed, 527 assertions.
- Frontend unit suite: 80 tests passed.
- TypeScript: `tsc --noEmit` passed after clean Wayfinder generation.
- Production frontend build: passed.
- PHP formatting check and targeted frontend formatting check: passed.

Focused cases include:

- unauthorized actor rejection;
- learner self-check rejection;
- exact linked-supervisor enforcement;
- idempotent preparation and final-check requests;
- no stock or closure release before approval;
- requested-changes successor chain;
- insufficient-stock transactional rollback;
- immutable preparation, review, dispense, and stock ledger;
- reference-session clone and laboratory-preflight compatibility.

## Browser rehearsal

Environment: isolated local SQLite database, synthetic fixture only, port `8041`.

Observed sequence:

1. `mahasiswa.farmasi@example.invalid` opened the accepted medication request.
2. The learner submitted preparation version 1 for 6 tablets using synthetic lot `LOT-SIM-A-001`.
3. The workspace displayed the version as `Menunggu supervisor`; stock remained 100 tablets.
4. `supervisor.farmasi@example.invalid` received `Tinjau Penyiapan Obat v1` with a `Buka tugas` link.
5. The supervisor reviewed the preparation and approved it.
6. The final screen displayed:
   - preparer: `Mahasiswa Farmasi Demo`;
   - checker: `Supervisor Farmasi Demo`;
   - stock: `100.000 → 94.000`;
   - medication request: `COMPLETED`;
   - encounter: `Dalam konsultasi`;
   - preparation and final-dispense SHA-256 hashes.

## Remaining validation

- Pharmacy-program representatives should review the terminology, minimum final-check elements, and whether one or more teaching scenarios require a second pharmacist versus another authorized pharmacy role.
- A facilitated laboratory rehearsal should exercise both approve and request-changes branches with observers.
- Performance, concurrency, backup/restore, and deployment operations remain separate readiness gates.
