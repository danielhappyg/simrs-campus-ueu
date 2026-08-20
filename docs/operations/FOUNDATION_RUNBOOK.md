# Platform Foundation Runbook

## Purpose and boundary

This runbook covers local development and evidence collection for the simulation-only platform foundation. It is not a campus deployment procedure and does not authorize real patient data. For the current hosted demo, see [Current hosting posture](CURRENT_HOSTING_POSTURE.md) and [Vercel + Supabase synthetic demo](VERCEL_SUPABASE_DEMO.md).

## Environment contract

Required safety values:

```dotenv
APP_MODE=SIMULATION
APP_SYNTHETIC_ONLY=true
```

The application returns HTTP 503 from protected simulation routes if either value is unsafe. Each deployed page must continue to show `SIMULASI — DATA SINTETIS` and `Tidak untuk pelayanan pasien nyata`.

`APP_KEY`, database credentials, passkey secrets, mail credentials, and deployment keys belong in the local or protected deployment environment. They must never be committed.

## Local initialization

```bash
composer install
npm ci
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
npm run build
```

Run the application with `composer dev`, or serve the production build locally with `php artisan serve`.

## Synthetic fixture procedure

Fixtures are disabled by default. To create the reference learner session, set a local password of at least 12 characters and enable the demo seeder in `.env`, then run `php artisan migrate:fresh --seed` against a disposable database.

Expected evidence:

- learner: `mahasiswa.keperawatan@example.invalid`;
- scenario: `OPD-REF-001` version 1;
- session: `SIM-RJ-UEU-001` in `ACTIVE` status;
- one ready and one changes-requested nursing task;
- `fixture_spec.synthetic_only` is true.

Never enable demo seeding simply to repair an existing database. Seeders are reference-data tools, not migrations.

After both verified terminology releases are active, a fresh isolated fixture can be advanced through the full reference journey or prepared at one correction gate:

```bash
php artisan simulation:complete-reference-journey
php artisan simulation:prepare-reference-correction diagnosis
php artisan simulation:prepare-reference-correction procedure
```

Run only one of these paths per disposable fixture. Correction preparation uses the same guarded clinical, closure, RMIK, suggestion, and coding services as the browser workflow. It does not mutate finalized or partially progressed cases, and it never grants the coder permission to edit clinical documentation.

To retain prior evidence while preparing another isolated branch, clone only the still-pristine reference graph:

```bash
php artisan simulation:clone-reference-session UAT-MAIN-001 --duration=480
php artisan simulation:clone-reference-session UAT-NO-SHOW-001 --duration=480 --appointment-offset=-5
```

The command requires the same explicit demo-fixture and synthetic-only opt-in, refuses production and progressed/malformed sources, generates new public/case/identifier/stock-lot values, remaps all assignment and task references, records `source_session_id` plus minimized audit provenance, and copies no clinical state. Every failure rolls back the full target graph. A negative appointment offset exists only to make the isolated no-show branch immediately executable; it is not a policy decision about session duration or scheduling. Use a unique uppercase code per run and never use this command as a substitute for retention governance.

## Terminology-release import

The computer-assisted coding workspace requires an active ICD-10 release. ICD-9-CM remains a separate procedure reference. Checkpoint 2 `simulation:lab-preflight` reports `READY` only when both exact checksummed development releases are active. Raw workbooks stay outside Git and are imported only after an operator verifies the SHA-256 values in the [Coding Reference Register](../research/CODING_REFERENCE_REGISTER.md):

```bash
php artisan terminology:import ICD_10 /absolute/path/to/icd10.xlsx --sha256=3c22aa15012dd2e15576657e49001291fd21a5b30ce797998a495aac548c5f4e
php artisan terminology:import ICD_9_CM /absolute/path/to/icd9cm.xlsx --sha256=c13d074be8fb271fccddfce4825ff56b6c958e48360ac148f7768c2de59e9697
```

The synthetic facilitator assignment owns `terminology.manage` in the reference fixture. A hosted environment must assign this capability deliberately; a coder cannot import or activate catalogs. Re-importing an identical checksummed source is idempotent. Activating a different valid source supersedes the prior active release without rewriting historical records.

## Verification

Run the repository quality gates from the README. Additional operational checks:

```bash
php artisan about
php artisan migrate:status
php artisan route:list
php artisan config:show simulation
```

Confirm that:

- no `register` route exists;
- `/work` requires authentication and verified email;
- the health endpoint `/up` responds successfully;
- a user cannot see another user's assignment or task;
- a revoked assignment disappears from the work queue;
- each work-queue view creates a correlated audit event;
- the browser console is clear at desktop and narrow viewports.

## Dependency hygiene

Treat dependency hygiene as a separate release-readiness input, not as an automatic mass-upgrade task. From a clean checkout, capture:

```bash
composer audit
composer outdated --direct
npm audit --omit=dev
npm outdated
```

Interpretation rules:

- `audit` findings are blocking security inputs until classified and resolved or explicitly accepted by Daniel;
- `outdated` results are maintenance signals and should be reviewed for scope, changelog risk, and compatibility before changing versions;
- major-version jumps are not bundled into unrelated validation or documentation pull requests; and
- environment warnings from package-manager configuration should be recorded when they affect future reproducibility, even if the current install still succeeds.

The dated [Dependency Hygiene Baseline — 20 August 2026](DEPENDENCY_HYGIENE_BASELINE_2026-08-20.md) records the first post-merge review after PR #25 and should be superseded by later dated records rather than silently edited.

## Recovery and rollback boundary

Before patient or encounter data exists, a local foundation database can be recreated from migrations and synthetic fixtures. Do not use destructive migration commands on shared, staging, or production-like environments.

For a hosted release, rollback must restore a previously built application artifact while preserving a compatible database. Database rollback is permitted only when the migration has an explicitly reviewed reverse path and no retained record would be lost. Otherwise deploy a forward corrective migration.

The SSH or platform-native deployment workflow remains blocked until the project records evidence for:

1. separate staging and production databases, keys, storage, and hostnames;
2. backup creation and test restoration;
3. PHP extensions and writable/private storage;
4. release-directory or equivalent atomic switching;
5. queue/cron behavior and log retention;
6. maintenance-mode and health-check behavior; and
7. a tested code and database recovery exercise.

The [Local MySQL and Recovery Validation](LOCAL_MYSQL_RECOVERY_VALIDATION.md) proves local migration portability plus a full finalized synthetic reference-journey backup/restore, relationship comparison, completed-state no-op check, and restored `/up` response. It does not satisfy hosted backup governance, campus-host isolation, or release-artifact deployment/rollback requirements above.

The [Hostinger Staging Preflight](HOSTINGER_STAGING_PREFLIGHT.md) provides an optional read-only `ops:hosting-preflight` command and sanitized evidence schema for a future shared PHP host. See [Current hosting posture](CURRENT_HOSTING_POSTURE.md). It is not required for the active Vercel + Supabase demo and does not satisfy `OPS-02`.

The [Release Candidate Artifact](RELEASE_CANDIDATE_ARTIFACT.md) defines the manifest-bound runtime allowlist and the CI job that builds an immutable short-lived tar plus SHA-256 sidecar after application and MySQL checks, then verifies the completed archive fail-closed before upload. The [Local Release Control Validation](LOCAL_RELEASE_CONTROL_VALIDATION.md) records tamper/forbidden-content rejection and a disposable filesystem contract in which failed health blocks the pointer switch and the preceding release can be restored. These advance the tested-artifact and release-control prerequisites without enabling a deployment environment, SSH transfer, migration, hosted switch, or rollback claim.

The [GitHub Publication Checklist](GITHUB_PUBLICATION_CHECKLIST.md) records the private-repository boundary, selective staging rule, untracked presentation-artifact exclusion, current plan's branch-protection limitation, manual PR-only fallback, and the evidence required before Daniel authorizes the first outpatient feature push.
