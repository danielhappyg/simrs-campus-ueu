# Structured Outpatient Documentation and RM Completeness v1 — implementation decision

- Status: **Bounded engineering implementation authorized; Clinical and RMIK acceptance pending**
- Date: 2026-08-24
- Product owner: Daniel
- Scope: SIMRS Campus UEU synthetic outpatient teaching workflow
- Related parity capabilities: PAR-CLN-004 and PAR-RMIK-001

## Naming clarification

This work is named **Structured Outpatient Documentation and RM Completeness v1**. It is not an official clinical standard, regulatory profile, or external SIMRS specification. Any temporary label implying that it is a formal “RJ standard” is retired and must not appear in code, database values, UI, tests, or project claims.

## Authorized engineering boundary

The product owner authorized implementation of realistic record mechanics without representing unresolved clinical content as an approved hospital rule.

### Documentation

- Preserve legacy outpatient free-text entries as read-only history; do not backfill or relabel them as structured Final records.
- Add versioned nursing and medical outpatient documents with explicit **Draf** and **Final** states.
- Every Draft save creates an attributable immutable version.
- Finalisation is explicit, records author/finaliser/time, and makes the document read-only in v1.
- A stale version, wrong role, wrong author, or closed encounter fails without overwriting the authoritative record.
- First structured documentation moves a registered encounter into examination; only medical Final makes it ready for RM.

### Bounded v1 field schema

| Document | Fields | Final requirement |
|---|---|---|
| Nursing assessment | `nursing_assessment`; optional `additional_notes` | Nursing assessment must be present |
| Medical assessment | `anamnesis`, `objective_examination`, `clinical_assessment`, `care_plan`; optional `additional_notes` | The four primary fields must be present |

The v1 schema deliberately excludes vital-sign ranges, allergy classifications, diagnosis codes, prescriptions, disposition values, clinical alerts, default normal values, and legal electronic-signature claims. Those require separate Clinical/RMIK evidence and approval.

### RM completeness

- Replace direct RM closure with a dedicated read-only clinical-source review.
- Persist an attributable, versioned completeness snapshot before sign-off.
- Automatically evaluate encounter linkage, Final nursing documentation and provenance, Final medical documentation and required fields, medical provenance, and active laboratory orders.
- Sign-off rechecks the current source fingerprint and blockers transactionally; only a complete current review may close the encounter.
- One user holding the existing `rmik.completeness.signoff` capability may sign off in v1. This is an implementation rule for the synthetic teaching slice, not RMIK professional acceptance or a claim that second-person review is unnecessary in real care.

## Preserved boundaries

- `APP_MODE=SIMULATION` and synthetic-only enforcement remain mandatory.
- DEC-016 remains **Proposed NEW**: the existing active-order closure guard, closed-encounter late-result denial, and immutable FINAL lab result behavior are retained without claiming SAHABAT or owner acceptance.
- No live BPJS, VClaim, SATUSEHAT, LIS, PACS, prescription, pharmacy, charge, claim, coding, amendment, reopen, deletion, learner/supervisor, or real-patient workflow is introduced.
- Vercel does not run the Supabase `laravel` schema migration automatically; deployment must migrate explicitly before application verification.
- Database validation must use model classes or `SchemaAwareRules`, never schema-qualified strings in `Rule::exists`.

## Acceptance boundary

Engineering completion may be claimed only after migration, automated tests, and a continuous synthetic role-switched UAT pass. That claim is limited to:

> Structured outpatient documentation and RM completeness v1 is available for synthetic UEU simulation.

It does not make PAR-CLN-004 or PAR-RMIK-001 parity-accepted and does not establish clinical production readiness.
