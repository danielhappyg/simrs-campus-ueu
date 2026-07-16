# GitHub Publication Checklist — Outpatient Reference MVP

- **Status:** Pre-publication plan; no current feature changes have been staged, committed, pushed, or opened as a pull request
- **Date inspected:** 2026-07-16
- **Repository:** private `danielhappyg/simrs-campus-ueu`
- **Default branch:** `main`
- **Current local branch:** `agent/outpatient-domain-spine`
- **Decision authority:** Daniel Happy Putra, project manager/PIC

## 1. Verified repository state

| Check                       | Result                                                                                                              |
| --------------------------- | ------------------------------------------------------------------------------------------------------------------- |
| Remote repository           | Exists and remains private                                                                                          |
| Local base                  | Current branch and local `main` both start from `origin/main` commit `893da9f`                                      |
| Feature branch publication  | No remote `agent/outpatient-domain-spine` branch                                                                    |
| Feature pull request        | None open or closed for the current branch                                                                          |
| Repository permission       | Daniel has administrator permission                                                                                 |
| Branch protection           | Unavailable for this private repository on the current GitHub plan; the API returned the plan limitation explicitly |
| GitHub Actions              | Existing foundation checks have run on prior pull requests; the expanded MySQL 8.4 backend job has not run remotely |
| Dependency pull requests    | Five separate Dependabot pull requests are open; they are outside the outpatient feature scope                      |
| Repository license metadata | No repository license file is detected although `composer.json` declares MIT                                        |

The repository must remain private during reference development. Resolve the missing-license-file/declaration mismatch before any future public-release decision; it does not block private development.

## 2. Publication authorization gate

Do not stage, commit, push, or create the pull request until Daniel explicitly authorizes GitHub publication of the current outpatient reference slice.

Authorization covers only:

- creating commits on the existing feature branch;
- pushing that branch to the existing private repository; and
- opening a draft pull request against `main`.

It does not authorize merging, making the repository public, changing billing/plan settings, merging Dependabot updates, enabling deployment, or sending repository content elsewhere.

## 3. Selective inclusion boundary

Include the application reference slice only:

- `app/` domain models, services, controllers, requests, middleware, enums, and console commands;
- `database/migrations/` and the deterministic opt-in demo seeder;
- `resources/css/`, authored React/TypeScript pages/components/types/tests, report templates, and the small synthetic coding gold-set definition;
- `routes/`, relevant bootstrap/foundation changes, and tests;
- `.github/` CI/PR workflow changes;
- `README.md`; and
- source, design, product, ADR, validation, recovery, and UAT documentation under `docs/`.

Explicitly exclude:

- the entire untracked `deliverables/` presentation workspace unless Daniel makes a separate artifact-publication decision;
- the supplied ICD-10 and ICD-9-CM `.xlsx` workbooks in Downloads or any copied raw terminology workbook;
- `.env`, credentials, tokens, keys, session cookies, database dumps, SQLite files, logs, caches, and local hosting configuration;
- `vendor/`, `node_modules/`, `public/build/`, generated Wayfinder bindings, coverage, and rendered QA output; and
- any real or plausibly real patient/person data.

The final terminology evidence in Git is limited to approved source URLs, version labels, checksums, import code/tests, and synthetic evaluation cases. It does not redistribute the workbooks.

## 4. Current artifact boundary

The user-owned `deliverables/simrs-north-star/` folder is intentionally preserved and untracked. It currently contains final PDF/PPTX discussion artifacts plus a working deck source area. Existing ignore rules exclude its rendered QA folders and dependency cache, but not every final/working file.

Therefore:

- never use an indiscriminate `git add -A` or `git add .` for this publication;
- stage explicit application paths only;
- inspect `git diff --cached --name-status` before every commit; and
- confirm `git diff --cached --name-only` contains no `deliverables/` path.

Do not delete, move, rewrite, or newly ignore the presentation workspace merely to make staging easier.

## 5. Commit and pull-request structure

The accumulated reference slice is vertically integrated across shared routes, assignments, fixtures, and state transitions. Splitting it into stacked independent pull requests now would require risky history surgery and could produce commits that do not run independently. Use one draft pull request with a small logical commit sequence:

1. `feat: complete synthetic outpatient reference journey`
    - integrated patient/encounter, nursing, medicine, results, pharmacy, closure, RMIK, coding/corrections, debrief, reporting, UI, migrations, fixtures, and automated tests;
2. `docs: record outpatient validation and Checkpoint 2 contracts`
    - ADRs, evidence/traceability, recovery/browser validation, UAT guide, master-plan and README updates; and
3. `ci: run backend suite on MySQL 8.4`
    - expanded MySQL job plus the semantic JSON-order portability assertion.

If selective staging cannot keep each commit internally coherent, prefer one coherent feature commit plus one documentation/CI commit over artificially broken history. Future increments should return to smaller feature branches and pull requests.

## 6. Manual branch-governance fallback

Because protected-branch enforcement is unavailable on the current private-repository plan:

1. never push the outpatient work directly to `main`;
2. push only the named feature branch;
3. open the pull request as a draft;
4. require all application and documentation checks to pass, including MySQL 8.4;
5. keep Daniel as CODEOWNER/final reviewer;
6. resolve or explicitly record every failed check before marking ready;
7. do not merge unrelated Dependabot pull requests into the feature branch;
8. inspect the final changed-file list and dependency lockfiles before merge; and
9. require a separate explicit merge decision from Daniel.

Daniel can later choose GitHub Pro or an organization policy with branch protection. Making the repository public solely to unlock protection is not an acceptable workaround during private reference development.

## 7. Required pre-push evidence

- [ ] `git status --short` reviewed; user-owned unrelated files identified
- [ ] staged path list contains no `deliverables/` file
- [ ] no `.env`, key, token, password, database, dump, log, raw ICD workbook, or real-person data is staged
- [ ] Composer manifest validates
- [ ] PHP formatting and PHPStan pass
- [ ] full backend suite passes on SQLite
- [ ] full backend suite passes on disposable real MySQL
- [ ] frontend formatting, ESLint, TypeScript, React/axe tests, and production build pass
- [ ] Markdown link/fence validation and `git diff --check` pass
- [ ] npm high-severity audit passes
- [ ] Composer advisory check succeeds or its external outage is recorded without claiming a pass
- [ ] current migrations and rollback/recovery notes are included
- [ ] screenshots, if attached to the PR, contain synthetic data only

## 8. Draft pull-request contract

Proposed title:

> Complete the synthetic outpatient reference journey

The draft description must summarize:

- the user-visible outpatient journey and role handoffs;
- the simulation-only and human-decision boundaries;
- ICD workbook checksum/reference handling without redistribution;
- schema/migration range through `001400`;
- authorization, audit, correction, debrief, and report behavior;
- automated SQLite/MySQL/frontend/security evidence;
- browser/manual evidence and the remaining native keyboard, correction-console, and print-preview gaps;
- recovery/deployment boundaries; and
- Daniel's remaining Checkpoint 2 and release decisions.

## 9. Post-push validation

After the branch is pushed and the draft PR exists:

1. verify the remote diff matches the approved staged files;
2. inspect application, documentation, and MySQL 8.4 job results;
3. diagnose any failing job from its actual log;
4. confirm no deployment workflow ran;
5. keep the PR draft until the outstanding manual-evidence record is understood; and
6. report the PR URL and exact remaining decisions to Daniel.

Publishing a draft PR is not merge approval, UAT acceptance, pilot approval, or deployment authorization.
