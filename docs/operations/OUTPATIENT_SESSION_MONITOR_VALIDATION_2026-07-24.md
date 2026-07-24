# Outpatient Laboratory Session Monitor Validation — 24 July 2026

## Record status

- Evidence type: developer-operated, synthetic-only operational-control validation
- Candidate code commit: `908b48d` (`Add outpatient laboratory session monitor`)
- Source branch: `agent/outpatient-domain-spine`
- Deployment status: **NOT DEPLOYED**
- Merge status: **NOT MERGED**
- Product-owner decision: **NOT DECIDED**
- Scope: outpatient reference MVP only
- Exclusions: Claims, E-Klaim, real patient data, production integrations, grading, and automated acceptance

This record validates the new read-only command used to observe one disposable laboratory session. It does not replace participant observation, faculty acceptance, or Daniel's decision.

## Operational gap addressed

The facilitator runbook already provided a preflight command, disposable-session cloning, role-specific work queues, and a UAT record. It did not provide a bounded way to answer these questions during a small-group rehearsal without direct database inspection:

- Is this exact disposable session structurally safe to use?
- Is the case ready, in progress, paused, ended, or finalized?
- Which task types and program/role combinations are ready?
- Are blocked, correction, or supervisor-review task states present?

`php artisan simulation:lab-session-status <SESSION>` now provides that evidence without changing application state.

## Safety and data contract

The command:

- refuses the production application environment;
- requires `APP_MODE=SIMULATION`;
- requires `APP_SYNTHETIC_ONLY=true`;
- rejects malformed and missing selectors without echoing them;
- rejects the retained pristine source because the monitor accepts only disposable clones;
- requires one internally scoped synthetic patient, appointment, and encounter;
- requires all ten active reference-role assignments;
- requires the retained work-task provenance graph;
- emits no patient name, MRN, national-identifier fixture, account email, password, user ID, assignment ID, public ULID, or internal database ID;
- performs read-only queries and leaves application-table counts unchanged.

`BLOCKED` is a structural or synthetic-boundary result. The command does not diagnose a clinical problem or decide whether a scenario passes.

## Isolated rehearsal

The candidate was exercised in a separate runtime created from an archive of base commit `55eee99`, with only the candidate command and test overlaid before execution. The environment used:

- a separate SQLite database created through `migrate:fresh --seed`;
- Composer dependencies synchronized to the tracked lockfile;
- separately compiled production frontend assets;
- simulation mode and synthetic-only enforcement;
- no production clinical integration endpoints or credentials.

The supplied terminology workbooks were imported as coding references:

| System | Release | Concepts | Verified checksum suffix |
| --- | --- | ---: | --- |
| ICD-10 | `ICD10_2010` | 18,543 | `5aac548c5f4e` |
| ICD-9-CM | `ICD9CM_2010` | 4,626 | `7a52e08f8d78` |

No Claims or E-Klaim workflow was loaded into the isolated candidate.

## Observed session states

The retained source preflight reported `READY` with 16 passes and zero failures before and after the disposable journey.

Disposable session `LAB-MONITOR-20260724-01` produced the following start-state report:

- command status: `OK`;
- phase: `READY_TO_START`;
- encounter status: `PLANNED`;
- active assignments: 10;
- total tasks: 4;
- open tasks: 3;
- ready task: `REGISTRATION` / `RMIK` / `REGISTRAR`;
- attention flags: none.

After the guarded reference journey reached `FINALIZED`, the same read-only monitor reported:

- command status: `OK`;
- phase: `FINALIZED`;
- total tasks: 27;
- open tasks: 10;
- ready tasks: ten role-specific `DEBRIEF` tasks;
- attention flags: none.

The open-task count correctly reflects all role-specific debrief tasks. It is not a completion percentage, learner score, grade, or acceptance result.

## Automated evidence

Focused coverage passed:

- six monitor feature tests;
- 52 monitor assertions;
- Pint;
- PHPStan with zero errors.

The isolated candidate also passed:

- 260 of 260 PHP tests;
- 3,231 PHP assertions;
- 72 of 72 frontend tests;
- ESLint;
- Prettier check;
- TypeScript check;
- production frontend build;
- locked Composer audit with zero advisories;
- npm audit with zero vulnerabilities.

The monitor tests cover registration, pristine read-only output, finalized output, identity minimization, unsafe configuration, invalid selector, missing selector, retained-source refusal, and corrupt-clone refusal.

## Documentation integration

The following operational documents now require a start-state monitor report and describe handoff/closeout use:

- [Outpatient Laboratory Pilot Runbook](OUTPATIENT_LAB_PILOT_RUNBOOK.md)
- [Checkpoint 2 UAT Facilitator Guide](OUTPATIENT_CHECKPOINT_2_UAT_GUIDE.md)
- [Checkpoint 2 UAT Record Template](OUTPATIENT_CHECKPOINT_2_UAT_RECORD_TEMPLATE.md)

They explicitly prohibit converting task counts into percentages, grades, or automatic acceptance.

## Remaining gate

The command makes the next dry run observable; it does not perform that run. Remaining evidence still requires a supervised small group to:

1. use the exact disposable session and role sequence;
2. capture start, disputed-handoff, stop-event, and closeout monitor references when applicable;
3. record participant-observed workflow and teaching issues;
4. classify any stop-session or must-fix finding; and
5. obtain Daniel's explicit Checkpoint 2 decision.

Current recommendation: retain this control for the planned supervised dry run. Faculty-pilot authorization remains **NOT DECIDED**.
