# SIMRS Campus Universitas Esa Unggul

This workspace is the planning and future implementation home for the next-generation campus hospital information system.

## Current status

Discovery and master planning are complete. Implementation has deliberately not started.

The legacy application was inspected as a product reference, not adopted as the new foundation. The recommended direction is a greenfield, teaching-first system that follows real hospital workflows, uses synthetic patient data by default, and makes learner supervision explicit.

## Planning documents

- [Legacy assessment](docs/LEGACY_ASSESSMENT.md)
- [Campus SIMRS master plan](docs/SIMRS_CAMPUS_MASTER_PLAN.md)
- [ADR-001: Rebuild architecture and delivery model](docs/adr/ADR-001-REBUILD-ARCHITECTURE.md)
- [Project start checklist](docs/PROJECT_START_CHECKLIST.md)

## Repository safety rules

- This repository is private during discovery and early development.
- Synthetic patients only; no real patient or student-assessment data.
- No credentials, private keys, environment files, or production integration configuration.
- Changes reach `main` through reviewed pull requests and automated checks.
- The legacy application is reference material and is not copied into this repository.

## Decision gate before implementation

The cross-program steering group should approve these four decisions first:

1. The initial product is a **teaching/simulation platform**, not a production electronic medical record for real patients.
2. The first complete learning journey is **outpatient care**, from registration through coding and record-quality review.
3. Students create drafts; authorized instructors or clinical supervisors approve/sign them.
4. Hostinger is the initial deployment target, with a documented migration trigger to a VPS or managed platform if integration, concurrency, or availability requirements outgrow shared hosting.

No real patient data should be loaded until a separate production-readiness and legal/compliance review is completed.
