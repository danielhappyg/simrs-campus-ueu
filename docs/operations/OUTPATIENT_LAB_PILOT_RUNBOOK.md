# Outpatient Laboratory Pilot Runbook

- **Status:** Development rehearsal runbook; Daniel's approval is required before a faculty pilot
- **Scope:** one synthetic outpatient reference journey in SIMRS Campus UEU
- **Owner and final decision authority:** Daniel Happy Putra, project manager/PIC
- **Exclusions:** real patient data, clinical use, production integrations, and deployment authorization

## 1. Intended use

Use this runbook when the tested outpatient release candidate is ready to move from developer verification into a controlled university-laboratory rehearsal. It provides the short operational path; the detailed acceptance steps and evidence rules remain in the [Checkpoint 2 UAT Facilitator Guide](OUTPATIENT_CHECKPOINT_2_UAT_GUIDE.md) and its [record template](OUTPATIENT_CHECKPOINT_2_UAT_RECORD_TEMPLATE.md).

The first laboratory run is a guided product-validation exercise, not an ordinary class and not evidence that the system is safe for hospital care. Every case, identifier, narrative, result, medication, and code used in the run must be synthetic.

## 2. People and minimum setup

Required roles:

- one facilitator with terminal access to the isolated environment;
- ten demo-role seats listed in the facilitator guide (one rehearsal operator may temporarily cover several roles);
- one observer who records issues without entering sensitive participant details; and
- Daniel, who reviews evidence and decides whether to retain, revise, defer, or reject each material finding.

Required technical conditions:

- a non-production environment dedicated to the rehearsal;
- an identifiable tested commit or release candidate;
- a separate application key, database, private storage, and access boundary;
- `APP_MODE=SIMULATION`, `APP_SYNTHETIC_ONLY=true`, and `DEMO_SEED_ENABLED=true`;
- the pristine `SIM-RJ-UEU-001` reference fixture;
- exactly one validated active development release each for ICD-10 `ICD10_2010` and ICD-9-CM `ICD9CM_2010`;
- compiled frontend assets;
- ten active, verified demo accounts whose temporary password is distributed out-of-band; and
- a dated copy of the UAT record stored in an institution-approved restricted evidence location.

Follow the [Foundation Runbook](FOUNDATION_RUNBOOK.md) for first installation. Never use the demo seeder, `migrate:fresh`, direct database editing, or a restored production database to repair a retained rehearsal environment.

## 3. Mandatory entry gate

From the exact candidate checkout and environment that will be used for the rehearsal, run:

```bash
php artisan simulation:lab-preflight
```

Machine-readable output is available when needed:

```bash
php artisan simulation:lab-preflight --json
```

Proceed only when the command exits successfully and reports `READY` with zero failed checks. The report is read-only and identity-minimized; it checks the synthetic runtime boundary, database access, compiled assets, absence of known production clinical integration configuration, reference fixture, pristine case graph, ten-account roster, assignments, initial work queue, and active terminology releases.

`READY` proves only that the source environment can be cloned for a rehearsal. It does not approve real data, clinical use, a release merge, a deployment, or a faculty pilot.

If the command reports `BLOCKED`:

1. do not invite or log in participants;
2. retain the output as sanitized troubleshooting evidence;
3. resolve the named configuration or fixture failure through the documented setup path;
4. do not patch records directly or reset a progressed session; and
5. rerun the complete preflight until every check passes.

## 4. Stage A — facilitator rehearsal

Run this stage before a multi-person session.

1. Record the candidate commit, environment identifier, browser/version, and preflight result in a dated UAT record.
2. Create a disposable session with a unique uppercase code:

   ```bash
   php artisan simulation:clone-reference-session LAB-REHEARSAL-001 --duration=480
   ```

3. Record the generated session and encounter identifiers. Do not record passwords, cookies, keys, raw audit payloads, or participant contact details.
4. Starting at `/work?session=LAB-REHEARSAL-001`, complete the main journey in the exact role order in the facilitator guide.
5. Confirm that each role sees only the selected session and permitted task, source/version handoffs remain visible, and the encounter reaches the expected finalized/debrief state.
6. Record observable defects and decisions using issue IDs. Do not redesign unrelated modules during the run.

Recommended reservation: **150–180 minutes** for the main journey plus **30 minutes** for setup and evidence review. Run cancellation, no-show, safety escalation, early departure, and source-correction branches in separate disposable sessions; do not compress every branch into the first participant pilot.

Stage A may advance only when the planned main journey is reproducible without database intervention and no stop-session issue remains unresolved.

## 5. Stage B — guided participant pilot

1. Rerun `php artisan simulation:lab-preflight` immediately before preparing participant sessions.
2. Create a new disposable session; never reuse the facilitator-rehearsal session:

   ```bash
   php artisan simulation:clone-reference-session LAB-PILOT-001 --duration=480
   ```

3. Brief participants on the permanent synthetic-data boundary, assigned roles, stop rules, evidence capture, and known limitations.
4. Provide the temporary password separately from the repository and UAT record.
5. Require every participant to confirm the page context and task-row session code before acting.
6. Facilitate UAT-00 through UAT-10 in the detailed guide, recording expected versus actual behavior after each step.
7. Use fresh separately named sessions for any negative or correction branch selected for this run.
8. Stop introducing new branches when insufficient time would weaken evidence quality. Mark unrun scenarios `NOT RUN`; never infer a pass.

The initial guided pilot should use a small controlled group. It is intended to expose workflow, wording, authorization, accessibility, and teaching-design problems before broader program consultation.

## 6. Stop and recovery rules

Stop the current session immediately if any of these occur:

- real or plausibly identifiable patient data is entered;
- the synthetic banner or selected-session context is missing or ambiguous;
- a user can access another role's task or another session's case;
- a completed record is silently overwritten, deleted, or loses actor/source/version provenance;
- the software appears to make an autonomous diagnosis, treatment, safety, coding, or competency decision;
- a production hospital, SATUSEHAT, BPJS, email, messaging, or other clinical endpoint/credential is discovered;
- the application requires direct database editing to continue; or
- the evidence needed to explain the failure cannot be preserved safely.

For recoverable user-interface or workflow failures:

1. record the exact role, step, visible state, expected result, actual result, and sanitized evidence ID;
2. keep the failed session unchanged;
3. classify the issue provisionally without guessing its root cause;
4. create a new disposable clone only after the source preflight passes again; and
5. rerun the affected scenario from its defined starting state.

Never reset or delete a progressed session to make a rerun appear clean. A new clone is cheaper and preserves the failed run as evidence.

## 7. Stage C — evidence review and closeout

After the session:

1. confirm every planned scenario is `PASS`, `FAIL`, `DECISION REQUIRED`, or honestly `NOT RUN`;
2. classify every issue and identify any unresolved stop-session or must-fix item;
3. link accepted findings to requirements, tests, and a target checkpoint;
4. rotate or disable the temporary demo password and restrict environment access;
5. retain the dated UAT record and synthetic-only evidence under the approved retention location;
6. stop a local development server when it is no longer supervised; and
7. leave all rehearsal sessions immutable—do not delete them as cleanup.

Daniel then records one Checkpoint 2 outcome: `ACCEPTED`, `CONDITIONALLY ACCEPTED`, or `REQUIRES ANOTHER RUN`. That decision may authorize the next development increment, but it does not by itself authorize merge, deployment, real-data use, or a faculty pilot.

## 8. Pilot-readiness exit criteria

The outpatient MVP is ready for Daniel to consider a controlled faculty pilot only when:

- the exact candidate passes automated tests and `simulation:lab-preflight`;
- the main journey has a completed dated UAT record;
- no unresolved stop-session or must-fix-before-pilot issue remains;
- all known limitations have been disclosed;
- role staffing, terminology sources, teaching forms, and scenario wording have an explicit Daniel decision;
- recovery and access-removal steps have been rehearsed; and
- Daniel provides a separate written faculty-pilot authorization.

Until then, describe the system as a **synthetic outpatient reference MVP under guided laboratory validation**.
