# Inpatient Post-Closure Summary Addendum V1

Status: **locally authorized for synthetic teaching implementation and engineering verification**
Date: 2026-08-31
Data boundary: `SIMULATION` mode with synthetic patients only

## Decision and scope

A closed inpatient episode may receive a governed discharge-summary addendum without reopening the encounter or changing any original Final evidence. This node is limited to physician correction request and decision, physician-authored structured addendum, and renewed RMIK review/signoff.

The exact physician role submits a request. A different exact physician approves or denies it. After approval, only the original requester may save Draft versions and finalize the addendum. The exact RMIK role saves the deterministic renewed review and signs it off. Administrator roles and system administrators are not substitutes for either operational role.

## Baseline and lifecycle contract

Every request binds the exact immutable inpatient discharge, Final discharge summary/version and content/provenance digests, Final discharge coding source/version and content/provenance digests, Final RMIK coding version/content digest, and original signed RMIK review/fingerprint.

One portable nullable `ACTIVE` slot permits at most one active request chain per encounter. Request versions are immutable:

- `SUBMITTED` becomes `APPROVED` or `DENIED` only through a different physician;
- `APPROVED` remains active while the addendum is authored and reviewed; and
- only renewed RMIK signoff changes `APPROVED` to `CONSUMED`.

Finalizing the addendum does not consume the request. A denied or consumed request is terminal. The encounter remains `CLOSED` through every transition.

## Addendum and renewed review evidence

The addendum definition is `INPATIENT_POST_CLOSURE_SUMMARY_ADDENDUM_V1`. It uses the same five structured narrative areas as the discharge summary: admission reason, significant findings, care and treatment summary, condition at discharge, and follow-up plan. At least one narrative must be nonblank.

Each save appends a complete immutable version. The mutable head may advance only from Draft to the next Draft or from Draft to the next Final version. Finalization copies identical Draft content, records the finalizer, and preserves a stable content digest. Final addenda cannot be edited or deleted by ordinary workflow.

The renewed RMIK snapshot is system-derived and read-only. It verifies the exact baseline closure, encounter still Closed, approved request, Final addendum, unchanged source/coding evidence, no active laboratory orders, and no new inpatient Draft documents. Signoff requires the latest saved Draft review to match the current zero-blocker fingerprint. In one transaction it appends the signed review/items, consumes the request, records audit and receipt evidence, and leaves the encounter and all baseline evidence unchanged.

## HTTP and projection contract

Physician actions are exposed under `/pemeriksaan/rawat-inap`; renewed RMIK review actions are under `/rm/rawat-inap`. The physician examination page exposes `inpatient_summary_addendum`; the RMIK detail includes the same chain under `inpatient_rm.summary_addendum` with the baseline coding evidence read-only.

Action URLs are nullable and appear only for the exact authorized actor and current state. Request and review mutations use optimistic `expected_version`, current deterministic fingerprints where applicable, and strict idempotency keys. Operational labels are hospital-like; the internal synthetic boundary is not presented as user-facing simulation wording.

## Concurrency, audit, guards, and recovery

Every operation writes an immutable receipt binding actor, operation, payload digest, request/version, baseline summary/source/coding/review foreign keys and digests, addendum/version/content digest when applicable, and renewed review result. Replay is resolved before terminal-state denial, again after mutable encounter/request locks, and after uniqueness races. Same-key/same-payload returns the original result; changed payload is denied and audited.

Success and denial audit are mandatory. An unavailable success audit rolls back the business mutation. Bound authorization and domain denials record their exact reason without disclosing an unknown route resource.

Database guards make request/addendum heads transition-only and make request versions, addendum versions, renewed reviews/items, and receipts append-only. PostgreSQL also refuses evidence-table truncation. Delete is available only through the bounded synthetic-reset flag. SQL write scopes are table-specific so one inpatient workflow cannot mutate another workflow's protected tables.

Synthetic reset removes the addendum graph before original discharge/RMIK/summary/source chains. Rollback refuses while any addendum business row or correlated clinical/RMIK audit evidence remains. MySQL down ordering drops CHECK constraints before guarded dependent tables; foreign-key, CHECK, trigger, and index names are distinct and portable.

## Explicit exclusions

- reopening a closed encounter;
- changing the original discharge, Final summary/source/coding, or original signed review;
- post-close coding addenda, generic amendment chains, or repeated downstream correction types;
- claims, pharmacy, finance, terminology lookup, or external integration;
- real patient data, deployment, production migration, or any external write.
