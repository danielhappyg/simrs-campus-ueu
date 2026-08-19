# ADR-013: E-Klaim Educational Adapter and Never-Sent Simulation

**Status:** Accepted for the campus simulation increment  
**Date:** 2026-07-21  
**Deciders:** Product owner and SIMRS Campus UEU engineering maintainers

## Context

Indonesian hospitals that cooperate with BPJS Kesehatan use E-Klaim as part of the claim workflow. A campus laboratory cannot assume that it is entitled to operate a hospital installation, use a production grouper, or send claims. Students nevertheless need to understand how a finalized SIMRS encounter, approved ICD coding, billing data, E-Klaim, and the wider BPJS/SATUSEHAT flow connect.

The supplied E-Klaim directory provides useful compatibility evidence:

- `VERSION5.TXT` reports version `5.8.8`, build `202406270756`;
- `ws.php` and `api.php` expose the expected local web-service boundary;
- application PHP is protected with ionCube;
- Windows grouper executables and DLLs are present; and
- the EULA states that the application and intellectual property belong to the Ministry of Health and forbids unauthorized reproduction or distribution.

The `ws.php` filesystem modification time is later than the version file. The exact patch state therefore cannot be established from `VERSION5.TXT` alone.

Current Ministry of Health documentation also describes a developing BPJS-claim flow on SATUSEHAT. It places E-Klaim between facility-submitted encounter/clinical/billing data and the BPJS claim/verification exchange, while stating that the module remains sandbox-only.

## Decision

Implement a clean-room, replaceable E-Klaim gateway boundary with only a deterministic local simulator bound in this increment.

The simulator preserves the educational order:

1. `new_claim`
2. `set_claim_data`
3. `grouper`
4. `claim_final`
5. `SIMULATE_SEND_CLAIM`

The fifth method is deliberately not the real E-Klaim `send_claim` method. It records what a submission checkpoint means without creating an executable production-send path.

Every case must be a finalized synthetic encounter with approved human ICD-10 and ICD-9-CM assignments. Every transition is capability-gated, ordered, idempotent, transactional, audited, and recorded with immutable request/response hashes. Configuration is hard-coded to `SIMULATION_ONLY`, `outbound_enabled=false`, `endpoint=null`.

No E-Klaim binary or encoded PHP is executed, decrypted, modified, copied into this repository, or redistributed.

## Options Considered

### Option A: Decrypt or clone the installed application

| Dimension            | Assessment   |
| -------------------- | ------------ |
| Legal/licensing risk | Unacceptable |
| Security risk        | High         |
| Maintainability      | Very low     |
| Educational control  | Low          |

**Rejected.** This conflicts with the observed EULA boundary, creates an unnecessary dependence on protected internals, and still would not confer hospital or BPJS authorization.

### Option B: Integrate directly with the E-Klaim database

| Dimension           | Assessment |
| ------------------- | ---------- |
| Coupling            | Very high  |
| Version resilience  | Very low   |
| Data integrity risk | High       |
| Auditability        | Low        |

**Rejected.** Direct database writes bypass the supported service boundary and could corrupt application state when schema or grouper behavior changes.

### Option C: Documented web-service adapter plus deterministic simulator

| Dimension             | Assessment                   |
| --------------------- | ---------------------------- |
| Licensing posture     | Low-risk clean-room boundary |
| Maintainability       | High                         |
| Educational value     | High                         |
| Future replaceability | High                         |

**Accepted.** It teaches the real separation of responsibilities and keeps a future authorized adapter behind the same application port.

### Option D: Static slides only

| Dimension              | Assessment |
| ---------------------- | ---------- |
| Implementation cost    | Low        |
| Workflow fidelity      | Low        |
| Failure/retry teaching | Low        |
| Evidence quality       | Low        |

**Rejected as the primary approach.** Static material remains useful, but it cannot demonstrate state ordering, idempotency, audit evidence, or mapping from finalized SIMRS records.

## Consequences

- Students can inspect a logical SIMRS-to-E-Klaim sequence with synthetic values.
- The interface can later accept an authorized sandbox adapter without changing the claim-domain workflow.
- The simulated group and tariff are unmistakable placeholders and cannot validate INA-CBG/IDRG accuracy.
- No automated finalization or transmission is permitted. Human coding and claim-review gates remain visible.
- A future live adapter requires a separate ADR, current official interface specification, organizational authorization, security review, sandbox contract tests, reconciliation design, and explicit deployment approval.

## Action Items

1. [x] Add the never-sent E-Klaim simulation workflow and UI.
2. [x] Persist immutable request/response evidence and audit metadata.
3. [x] Add backend, authorization, ordering, idempotency, and UI tests.
4. [ ] Obtain the current official Kemenkes web-service manual through an authorized institutional channel before implementing a network client.
5. [ ] Validate the teaching flow with RMIK/casemix lecturers and, separately, hospital claim staff.
6. [ ] Design SATUSEHAT `Coverage`, `Account`, `ChargeItem`, `Invoice`, `Claim`, and `ClaimResponse` sandbox mappings as a later increment.
