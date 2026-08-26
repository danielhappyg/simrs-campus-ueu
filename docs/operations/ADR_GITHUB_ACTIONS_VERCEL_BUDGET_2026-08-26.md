# ADR — Milestone-only GitHub CI and Vercel deployment budget

**Status:** PROPOSED for repository automation changes; ACTIVE as a working-process constraint
**Date:** 2026-08-26
**Deciders:** Daniel; Product/Delivery; Operations; Security
**Boundary:** local planning only — this ADR does not change a workflow, push code, dispatch CI, deploy Vercel, or authorize Production

## Context

Daniel has directed that incremental work must not be pushed immediately because available GitHub capacity is limited. The project also requires exact-SHA tests, migration evidence, release artifacts, and separate deployment authorization. Cost reduction therefore cannot weaken the final release gate.

Current repository behavior:

- `.github/workflows/application-checks.yml` runs for every pull-request update and every push to `main` or `rebuild/clean-slate`;
- one application event runs SQLite/PHP/React/security, MySQL 8.4, PostgreSQL 17 `laravel`-schema, and release-candidate jobs;
- `.github/workflows/documentation-checks.yml` also runs for every pull-request update and every push to those branches;
- the documentation workflow performs the complete Markdown scan, 194-test governance suite with RSA-3072 fixtures, integrity validation, and credential-file check;
- application concurrency cancels older same-ref runs only after they have started; documentation CI has no concurrency cancellation or explicit timeout;
- no path-scoping gate exists, so a documentation-only push still starts the application matrix; and
- Git-connected Vercel builds can create another Preview or Production deployment for the same Git event.

The release-candidate contract in `RELEASE_CANDIDATE_ARTIFACT.md` requires a fresh artifact for the resulting approved `main` commit. A pull-request merge-ref artifact is evidence for the candidate path, not an authorized deployable artifact.

## Decision

### Active process constraint

Effective immediately:

1. Work remains local while a milestone is being developed and reviewed.
2. Local commits may preserve a coherent batch, but no push, PR update, workflow dispatch, Vercel action, migration, or deployment occurs without Daniel's explicit milestone approval.
3. Before requesting push approval, the batch must have a scope summary, changed-file list, local test evidence, open-risk list, and confirmation that protected untracked paths were untouched.
4. Related corrections are accumulated into the same local milestone instead of being pushed as fixup commits.
5. Production and live-integration actions remain separately authorized even after a milestone push.

### Proposed repository automation changes

Do not implement these until Daniel approves the automation milestone and required-check behavior has been inspected:

1. Add concurrency cancellation and a bounded timeout to documentation CI.
2. Introduce a deterministic change-scope gate rather than applying `paths-ignore` blindly; required checks must resolve explicitly instead of remaining pending.
3. Separate routine PR validation from a manually approved exact-40-character-SHA release workflow. The release workflow must check out the requested SHA, confirm it equals the approved `main` commit, rerun every release-required database/governance gate, and then build the immutable `NOT_DEPLOYED` artifact.
4. Retain automatic `main` verification until the manual exact-SHA workflow has proved fail-closed behavior and the deployment runbook requires its artifact.
5. Consider disabling Git-triggered Vercel deployments only after an explicit manual Preview/Production procedure is verified. This ADR does not itself modify `vercel.json` or project settings.
6. Consider grouping or limiting Dependabot updates separately; grouping reduces run count but increases the review blast radius.

## Options considered

| Option | GitHub/Vercel cost | Release assurance | Operational risk | Assessment |
| --- | --- | --- | --- | --- |
| Keep pushing each incremental fix | Highest | Strong per push | Exhausts capacity and creates noisy artifacts/deployments | Rejected |
| Local milestone batching with current remote gates | Lower; one PR sequence per milestone | Preserves all current gates | Still repeats PR and resulting-`main` work | Active now |
| Add broad path filters | Lower for docs-only work | Can be safe with a deterministic scope gate | Required checks may remain pending or relevant jobs may be skipped | Proposed only |
| Manual exact-SHA release workflow | Lowest routine release-artifact cost | Strong if SHA and all gates are fail-closed | Human dispatch can be forgotten or mis-targeted | Proposed after proof |
| Disable Git-triggered Vercel deployments | Removes automatic Preview/Production builds | Strengthens separate deploy authorization | Loses automatic Preview feedback and requires a manual runbook | Proposed after proof |

## Consequences

- GitHub and Vercel usage falls immediately because local iterations no longer generate external events.
- Feedback from hosted runners arrives later, so local gates must be proportional to the milestone and cross-database checks remain mandatory before release.
- A milestone push can still expose runner-specific behavior; fixes remain local until the batch is ready for one explicitly approved follow-up push.
- No release or deployment claim may rely solely on local evidence.
- Automation savings must never remove PostgreSQL `laravel`-schema validation, MySQL strict-mode coverage, governance integrity, secret rejection, exact-SHA artifact verification, or separate deployment approval from the final gate.

## Approval gate

Repository automation remains unchanged until all boxes are completed:

- [ ] Daniel approves an automation-change milestone.
- [ ] Required branch-check names and pending-check behavior are recorded.
- [ ] Proposed PR, `main`, and manual-dispatch event graphs are documented.
- [ ] Exact-SHA release inputs, authorization, artifact retention, and failure behavior are tested locally where possible.
- [ ] Manual Vercel Preview/Production and rollback procedures are documented before Git auto-deploy is disabled.
- [ ] The change is reviewed as one bounded batch and pushed only after explicit approval.
