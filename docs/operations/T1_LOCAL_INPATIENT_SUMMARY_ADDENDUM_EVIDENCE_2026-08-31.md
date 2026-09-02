# T1 Local Inpatient Summary Addendum Portability Evidence

Status: **PASS — LOCAL DISPOSABLE EXACT-ENGINE EVIDENCE**

This document is a placeholder for bounded local evidence. It is not deployment, hosted migration, UAT, owner acceptance, production readiness, or G0/G3 closure evidence.

## Authorized local claim

The intended claim is limited to the post-closure inpatient discharge-summary addendum node on disposable local PostgreSQL 17 and MySQL 8.4 databases:

- a physician submits a correction request for a `CLOSED` inpatient episode;
- a different physician approves it;
- the original requester writes an immutable Draft-to-Final summary addendum;
- RMIK saves a zero-blocker Draft review and signs it off;
- the encounter remains `CLOSED` and the original discharge, summary, physician coding source, final RMIK coding, signed baseline review, bed identifiers, and location history remain unchanged;
- exact idempotent replay succeeds and changed-payload key reuse is denied;
- append-only evidence and legal head-transition guards refuse prohibited SQL mutations;
- reset removes the bounded synthetic business chain while retaining addendum and reset audits;
- rollback refuses retained correlated audit evidence.

## Evidence-producing command

After backend stability is confirmed, run separately for each disposable engine:

```text
SIMRS_INPATIENT_SUMMARY_ADDENDUM_REHEARSAL_CONFIRM=YES_DISPOSABLE_LOCAL_INPATIENT_SUMMARY_ADDENDUM_PORTABILITY \
  ruby scripts/rehearse-local-inpatient-summary-addendum-portability.rb postgresql17

SIMRS_INPATIENT_SUMMARY_ADDENDUM_REHEARSAL_CONFIRM=YES_DISPOSABLE_LOCAL_INPATIENT_SUMMARY_ADDENDUM_PORTABILITY \
  ruby scripts/rehearse-local-inpatient-summary-addendum-portability.rb mysql8411
```

Expected scenario count per engine: **13**.

## Accepted exact artifacts

- PostgreSQL 17 artifact: `storage/app/portability-rehearsals/20260831T153830Z-postgresql17-inpatient-summary-addendum-9356db622f23.json`
- PostgreSQL artifact SHA-256: `e2add0fd1c2f6896ed118cb87cc6fe500648262aae934b7a2adbc59b1f9f6b8a`
- PostgreSQL version: `170010` (major `17`)
- MySQL 8.4 artifact: `storage/app/portability-rehearsals/20260831T153856Z-mysql8411-inpatient-summary-addendum-74073bc3e527.json`
- MySQL artifact SHA-256: `eb7c914b95608690217fe19065bb90957fd40a74c6f052538ca0338ea5517325`
- MySQL version: `8.4.11`, InnoDB

Both accepted artifacts report `PASS` for all 13 scenarios, carry the same source/worker/scenario catalogue bindings, include non-empty scenario and worker attribution, refuse all five explicit forbidden runtime writes, and confirm complete disposable cleanup.

## Superseded pre-review artifact

The first PostgreSQL rehearsal artifact is explicitly **REJECTED / SUPERSEDED** and must not be used as acceptance evidence:

- path: `storage/app/portability-rehearsals/20260831T151226Z-postgresql17-inpatient-summary-addendum-4f47add49048.json`
- SHA-256: `65134539252d31415e1e5a66d81e21b9d3d4dbdb48c669565a596f3104953ffa`
- reason: its runtime identity had broader write privileges than the `least-privilege-runtime` claim described. PostgreSQL completed the functional scenarios, but the evidence claim was not honest enough to accept.

## Required acceptance readback

Before changing this status from Pending, verify:

1. both artifacts report `status: PASS` and the exact expected engine identity;
2. all 13 scenarios are present and pass;
3. source, worker, scenario-catalogue, command-catalogue, and result-catalogue digests are recorded;
4. the reduced runtime has `SELECT` only on closed prerequisite tables, `SELECT, INSERT, UPDATE` without `DELETE` on this node's two mutable heads, and `SELECT, INSERT` only on immutable history and audit evidence;
5. the reset identity is distinct from the runtime identity;
6. disposable databases, roles/users, temporary worker, and temporary server state were removed;
7. no live BPJS, VClaim, SATUSEHAT, deployment, or hosted database action occurred.

## Open boundaries

- The two artifacts under **Accepted exact artifacts** are the only accepted exact-engine evidence for this node; the pre-review PostgreSQL run above remains superseded and cannot support a claim.
- The harness proves sequential replay and changed-payload conflict behavior; simultaneous-worker race behavior remains explicitly unclaimed by this artifact.
- No production or hosted Supabase migration is authorized by this document.
- No frontend acceptance or facilitator UAT is claimed.
- The addendum is an appended correction record; it does not rewrite the original discharge summary, coding source, coding, or closure evidence.
