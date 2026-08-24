---
parity_requirement_id: PAR-XXX-000
decision: Accepted
business_owner: REPLACE_WITH_NAMED_OWNER
domain_owner: REPLACE_WITH_NAMED_DOMAIN_OWNER
decision_date: YYYY-MM-DD
release_evidence_id: REL-YYYYMMDD-NN
---

# Parity acceptance decision: PAR-XXX-000

Use one copy of this file for one accepted parity requirement. Keep the six frontmatter keys flat and scalar so `scripts/validate-parity-governance.rb` can verify them deterministically. Do not add a second copy of a key.

## Decision

- Decision: Accepted
- Canonical capability:
- Scope accepted:
- Explicit exclusions or separately governed integrations:

## Owner approval

| Authority | Name or accountable organization | Decision | Date | Evidence |
|---|---|---|---|---|
| Product / business owner |  |  |  |  |
| Affected domain owner |  |  |  |  |

## Acceptance evidence

- Detailed FR/BR/NFR/RPT/INT artifact:
- Synthetic happy and failure paths:
- Authorization denial and audit evidence:
- Cancellation, correction, reversal, retry and reconciliation evidence:
- Hosted role-based UAT:
- Accessibility and performance evidence where applicable:
- User and operational documentation:

## Release evidence contract

The matrix row may move to `Accepted` only when:

1. its Detailed requirement cell links an existing local specification artifact;
2. its Acceptance test cell links this decision artifact and contains exactly one matching `REL-YYYYMMDD-NN`;
3. the frontmatter contains the same PAR and REL IDs, `decision: Accepted`, named business and domain owners, and a real ISO decision date; and
4. the matching release-register row records a concrete commit SHA, pushed remote ref, deployment environment/URL or ID, a linked local authorization/audit/reconciliation artifact, `Gx PASS`, and a concrete rollback or restore path.

An automated test, deployed page, or product-owner statement alone is not parity acceptance.

## Residual risk and follow-up

- Accepted residual risks:
- Deferred capabilities with separate PAR/decision IDs:
- Monitoring or rehearsal follow-up:
