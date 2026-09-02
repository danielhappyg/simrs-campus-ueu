# UAT-20260820-002 / 003 remediation — superseded medical draft orders

> **WORKING NOTE — pairs with Checkpoint 2 rehearsal issues; not a PASS mark or pilot approval.**

## Findings

During local Checkpoint 2 rehearsal on `LAB-REHEARSAL-001`, saving a medical assessment draft that included service/medication requests, then submitting a successor version, left orphan `DRAFT` operational requests tied to the earlier version.

Those orphans:

- blocked `PharmacyWorkflowService::advanceWhenMedicationWorkComplete` (`UAT-20260820-002`); and
- failed closure readiness `CURRENT_RESULTS_ACKNOWLEDGED` (`UAT-20260820-003`).

## Fix (this branch)

`ClinicalDocumentationService` now cancels encounter-scoped `DRAFT` service and medication requests whose `source_entry_version_id` is not the current medical version:

1. after a successor medical version persists its own operational requests; and
2. again immediately before activating orders on medical supervisor approval.

Cancellation is append-only (`CANCELLED` status), audited as `clinical.superseded_draft_orders_cancelled`, and covered by:

`tests/Feature/MedicalAssessmentWorkflowTest.php::test_successor_medical_version_cancels_orphan_draft_orders_from_earlier_versions`

## Out of scope here

- `UAT-20260820-001` (prescription authored text vs seeded stock name) — seed/scenario alignment
- `UAT-20260820-004` (Indonesian diagnosis text → `NO_RELIABLE_CANDIDATE`) — terminology/teaching decision (`VAL-A16`)
- Faculty PASS marks and dependency package upgrades
