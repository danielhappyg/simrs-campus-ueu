# SIMRS Campus Universitas Esa Unggul

This workspace is the planning and future implementation home for the next-generation campus hospital information system.

## Current status

Discovery and master planning are complete. The source-grounded outpatient reference baseline is now being prepared before application scaffolding begins.

The legacy application was inspected as a product reference, not adopted as the new foundation. The recommended direction is a greenfield, teaching-first system that follows real hospital workflows, uses synthetic patient data by default, and makes learner supervision explicit.

## Planning documents

- [Legacy assessment](docs/LEGACY_ASSESSMENT.md)
- [Campus SIMRS master plan](docs/SIMRS_CAMPUS_MASTER_PLAN.md)
- [ADR-001: Rebuild architecture and delivery model](docs/adr/ADR-001-REBUILD-ARCHITECTURE.md)
- [Approved project charter](docs/PROJECT_CHARTER.md)
- [Project start checklist](docs/PROJECT_START_CHECKLIST.md)
- [Outpatient evidence register](docs/research/OUTPATIENT_EVIDENCE_REGISTER.md)
- [Outpatient service blueprint](docs/product/OUTPATIENT_SERVICE_BLUEPRINT.md)
- [Outpatient role and permission matrix](docs/product/OUTPATIENT_ROLE_MATRIX.md)
- [Outpatient data dictionary](docs/product/OUTPATIENT_DATA_DICTIONARY.md)
- [Assumption and validation register](docs/product/ASSUMPTION_AND_VALIDATION_REGISTER.md)
- [Outpatient acceptance scenarios](docs/product/OUTPATIENT_ACCEPTANCE_SCENARIOS.md)
- [Outpatient traceability matrix](docs/product/OUTPATIENT_TRACEABILITY_MATRIX.md)

## Repository safety rules

- This repository is private during discovery and early development.
- Synthetic patients only; no real patient or student-assessment data.
- No credentials, private keys, environment files, or production integration configuration.
- Changes reach `main` through reviewed pull requests and automated checks.
- The legacy application is reference material and is not copied into this repository.

## Delivery authority and validation gates

Daniel Happy Putra is the sole project manager/PIC and final authority for scope, priority, acceptance, and releases during the reference-build phase. Codex is delegated to research, design, implement, test, and prepare GitHub changes autonomously within the approved charter.

Stakeholder input is concentrated at three checkpoints after a concrete model exists:

1. workflow-baseline validation;
2. end-to-end user acceptance using one shared synthetic case; and
3. faculty-pilot readiness after security, accessibility, and deployment evidence is available.

These checkpoints validate and improve the model; they do not transfer Daniel's final product authority. No real patient data should be loaded until a separate production-readiness and institutional legal/privacy/clinical review is completed.
