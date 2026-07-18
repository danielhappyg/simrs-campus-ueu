# ADR-010: Unsaved Clinical Draft Guard

- **Status:** Implemented working reference; manual browser validation pending
- **Date:** 2026-07-18
- **Final decision authority:** Daniel Happy Putra, project manager/PIC
- **Implementation authority:** delegated autonomous product and engineering work under DEC-008
- **Scope:** `UX-04` protection for versioned nursing, medical, and encounter-closure authoring

## Context

The interaction contract requires a long clinical form to warn before navigation and to keep a local unsaved delta from silently crossing patient or encounter context. The versioned backend already preserves every accepted draft and submitted version, but the React authoring pages had no dirty-state navigation boundary. A learner could therefore click the work queue or encounter summary and lose unsaved local edits without an explicit choice.

Persisting the draft automatically in browser storage would create a different risk: clinical text could outlive the page, survive account/context changes, or be restored into the wrong encounter. The reference MVP must instead distinguish the last server-saved version from temporary local edits.

## Decision

1. Add one reusable `UnsavedChangesGuard` that is mounted only while an editable versioned form differs from its last successful server baseline.
2. Apply it to nursing intake, medical assessment, and encounter closure, the three long authoring forms that already support an explicit server-side `SAVE_DRAFT` intent.
3. Show a persistent `Perubahan belum disimpan` status without copying clinical content into the warning.
4. Intercept ordinary Inertia `GET` navigation and offer exactly:
   - `Tetap di halaman`;
   - `Simpan draf lalu keluar`; or
   - `Keluar tanpa perubahan lokal`.
5. Do not intercept the form's own non-GET submission or link prefetch.
6. On save-and-leave, submit the existing `SAVE_DRAFT` contract and replay the deferred destination only from the successful response callback. Validation or server failure keeps the user on the form.
7. On explicit discard, abandon only the local unsaved delta. Never delete or overwrite the last server-saved immutable version.
8. Register the native `beforeunload` boundary only while dirty, covering refresh, tab close, and external navigation. Remove both browser and Inertia listeners on clean-state unmount.
9. Store no clinical draft in local storage, session storage, URL state, or warning-dialog state. The dialog holds only the deferred visit metadata and a generic form label.
10. Keep stale-session recovery and browser-history traversal as explicit manual-validation items; do not claim cross-encounter recovery until a separately threat-modeled design exists.

## Consequences

- Users receive an early visible dirty-state signal and must make a deliberate choice before ordinary in-app navigation.
- The existing versioned server remains the only durable draft store.
- Save-and-leave may create an additional immutable draft version, which is truthful and attributable.
- Forms without a safe server-side draft contract do not receive a fake save option; they require a separate workflow decision if later classified as long-form authoring.
- Native browser unload text is controlled by the browser and cannot use the custom Indonesian copy.

## Verification

The focused React/axe suite passes 4 tests covering the visible status, accessible modal, all three choices, save-before-navigation ordering, explicit discard, ignored `POST`/prefetch visits, native unload prevention, and listener cleanup. The complete React suite passes 22 files and 51 tests. TypeScript, ESLint, Prettier, and the production build verify all three form integrations. Bounded browser validation remains required before the reference gate is marked complete.

## Related records

- [Outpatient Interaction Specifications](../design/OUTPATIENT_INTERACTION_SPECIFICATIONS.md)
- [Outpatient Acceptance Scenarios](../product/OUTPATIENT_ACCEPTANCE_SCENARIOS.md)
- [Outpatient Traceability Matrix](../product/OUTPATIENT_TRACEABILITY_MATRIX.md)
- [Checkpoint 2 UAT Guide](../operations/OUTPATIENT_CHECKPOINT_2_UAT_GUIDE.md)
