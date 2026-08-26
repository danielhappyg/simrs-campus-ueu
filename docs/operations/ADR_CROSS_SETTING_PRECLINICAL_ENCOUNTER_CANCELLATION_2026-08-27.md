# ADR: Cross-setting pre-clinical encounter cancellation

**Status:** **PROPOSED — no implementation approval**<br>
**Date:** 2026-08-27<br>
**Scope:** Synthetic teaching encounters in Rawat Jalan, IGD, and Rawat Inap<br>
**Decision pack:** [CROSS_SETTING_PRECLINICAL_ENCOUNTER_CANCELLATION_FR_PACK_2026-08-27.md](../new-simrs-rebuild/phase-1/CROSS_SETTING_PRECLINICAL_ENCOUNTER_CANCELLATION_FR_PACK_2026-08-27.md)

## Context

`encounter.cancel` is assigned to registrar and administrator roles, but no route consumes it. `Encounter` has no cancelled state and there is no immutable cancellation fact. The canonical RJ, IGD, and RI requirements all require an auditable cancellation path before acceptance, while explicitly marking queue, bed, charge, correction, and downstream effects unknown.

The current teaching system can safely define one narrower question: whether a registration created in error may be cancelled **before any clinical or downstream fact exists**. This does not establish SIMRS Sahabat behavior and does not authorize cancellation of care already started.

## Proposed decision

If the required owners approve, implement an append-only cancellation workflow for a synthetic encounter that is still `REGISTERED` and has no dependent fact:

```text
REGISTERED encounter + no dependent facts
  -> attributable cancellation request
  -> immutable cancellation record
  -> encounter status CANCELLED
  -> remove from active worklists; retain in register/report history
```

The encounter and original registration audit are never deleted or rewritten by ordinary workflow. The patient/MRN and any related encounter remain unchanged. Queue numbers are not recycled, no clinic-capacity restoration is claimed, and ordinary active-looking prints are blocked. An RI bed becomes available only because the encounter is no longer active; the original ward/class/bed assignment remains visible in history. The named synthetic reset may remove patient-domain cancellation facts while preserving append-only audit/security evidence; this exception must be explicit and cross-engine tested.

Any clinical entry/document/order, RM review, charge, payment, prescription, stock movement, claim, integration posting, or other dependent fact blocks this v1 operation. Those cases require separately approved reversal/correction workflows.

## Alternatives

| Alternative | Consequence | Disposition |
| --- | --- | --- |
| Keep cancellation unavailable | Preserves safety but leaves a dead capability and no correction path for mistaken registration. | Fallback if owners defer or reject. |
| Delete the encounter | Removes provenance and can silently reuse identifiers or queue position. | Rejected. |
| Permit cancellation after clinical/downstream activity | Requires clinical, RMIK, finance, pharmacy, inventory, claim, and integration reversals that are not defined. | Rejected for v1. |
| Append-only pre-clinical cancellation | Bounded correction path with retained registration history and no invented downstream reversal. | **Proposed.** |

## Consequences

Positive:

- activates an existing capability through a bounded, auditable workflow;
- creates one correction/reversal backbone shared by RJ, IGD, and RI;
- preserves queue high-water and original registration provenance; and
- avoids pretending that absent clinical, financial, pharmacy, stock, claim, or integration reversals exist.

Costs and risks:

- requires an immutable cancellation record, new encounter state, concurrency controls, audit schema, reporting semantics, Indonesian UI, and cross-engine tests;
- requires Registration, ED, Bed Management, Clinical, RMIK, Reporting, and technical/security review; and
- must remain unavailable until owner choices in the companion pack are recorded.

## Required gates before implementation

1. Named accountable authorities approve or revise CAN-01 through CAN-20: eligibility/state, reason catalogue, patient/queue/schedule/print/related-episode effects, RI bed boundary, reporting, reset/recovery, retry behavior, external isolation, and downstream blockers.
2. Clinical and RMIK owners confirm that the proposed dependent-fact set is sufficient to distinguish “care not started” from a case requiring correction/reversal.
3. Technical/security review accepts row-lock ordering, unique constraints, atomic audit, denial telemetry, retention, rollback, and recovery controls.
4. The approved design is reflected consistently in the three canonical registration requirements and the G0 decision/reference registers.
5. A separate implementation change passes SQLite, PostgreSQL 17, MySQL 8.4, adversarial concurrency, rollback/reapply, accessibility, and synthetic UAT before any hosted promotion.

## Explicit exclusions

This ADR does not approve cancellation after care begins; encounter reopen; clinical amendment; order/result cancellation; prescription/dispense/return; stock correction; charge/payment reversal; claim/BPJS/VClaim/E-Klaim/SATUSEHAT effects; real patient data; Antrean; Apotek; any live integration; deletion; queue-number reuse; or production use.

## Rollback and recovery

Before cancellation facts exist, an empty migration may be rolled back in a disposable environment. Once facts exist, disable routes/capabilities while retaining encounter, cancellation, and audit evidence. Rollback must not delete or rewrite populated cancellation history. Restore validation must reconcile each cancellation with its encounter state, actor, reason, correlation, and audit event before service resumes.

## References

- `docs/new-simrs-rebuild/phase-1/requirements/PAR-REG-001-rawat-inap-registration.md`
- `docs/new-simrs-rebuild/phase-1/requirements/PAR-REG-002-igd-registration.md`
- `docs/new-simrs-rebuild/phase-1/requirements/PAR-REG-003-rawat-jalan-registration.md`
- `docs/new-simrs-rebuild/DELIVERY_ROADMAP.md`
- `docs/new-simrs-rebuild/TESTING_AND_UAT_STRATEGY.md`
- `app/Support/Authorization/Capability.php`
- `app/Support/Authorization/RoleCapabilityMatrix.php`
