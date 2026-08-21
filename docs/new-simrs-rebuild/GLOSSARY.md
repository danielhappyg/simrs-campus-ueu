# Glossary and Evidence Language

| Term | Meaning in this program |
|---|---|
| Legacy system | The assessed vendor SIMRS 3.0 — RS UEU installation. |
| Parity | Approved ability to achieve the same business outcome and required output as the legacy system, not visual or code duplication. |
| Parity defect | New-system behavior that fails an approved parity requirement. |
| Improvement | A deliberate change beyond approved parity, normally evaluated after the parity gate. |
| Foundation improvement | Security, privacy, audit, architecture or reliability control implemented from the beginning even if absent from the legacy UI. |
| Vertical slice | A complete user journey crossing all required modules, postings, controls, reports and operations. |
| Patient | Person receiving a synthetic or approved care scenario. |
| Encounter | A bounded episode of interaction or service associated with patient, location, actors and payer context. |
| Admission | Inpatient episode including ward/class/bed and transfer/discharge state. |
| Order | Request for medication, diagnostic, procedure, therapy or supporting service. |
| Result | Verified output of a diagnostic or supporting-service order. |
| Medical record | Longitudinal clinical and administrative record, including authorship, time, amendments and coding. |
| Claim | Payer-facing representation of an encounter and its diagnoses, procedures, charges and supporting evidence. |
| Stock ledger | Immutable sequence of quantity/value movements for an item, batch, location and reason. |
| Master data | Governed reference records such as units, staff, tariffs, services, payers, drugs and codes. |
| Synthetic data | Artificial records that do not represent a real person and cannot be reversed to one. |
| Sandbox | External integration environment explicitly intended for testing with approved test identities/credentials. |
| RBAC | Role-based access control; roles are necessary but high-impact actions may also need contextual policy and approval. |
| Audit event | Tamper-resistant record of actor, action, subject, time, source, reason and relevant before/after state. |
| RPO | Maximum acceptable data-loss period after recovery. |
| RTO | Maximum acceptable service-restoration duration. |
| ADR | Architecture Decision Record describing context, decision, alternatives and consequences. |
| Definition of Done | Evidence required before a requirement, module or release can be considered complete. |

## Evidence labels

| Label | Meaning |
|---|---|
| Observed | Directly seen in the authorized old-system interface or response. |
| Owner-confirmed | Explicitly confirmed by UEU. |
| Manual-documented | Described in the 2018 vendor manual; may be stale. |
| Vendor-stated | Claimed by vendor material but not demonstrated. |
| Inferred | Plausible from structure/workflow but not yet verified. |
| Proposed | New-system design choice awaiting approval. |
| Approved | Formally accepted requirement or decision. |
| Verified | Demonstrated by test/evidence in a named environment and version. |
| Unknown | Requires further evidence; must not be silently guessed. |

