# ADR-010: Unsaved Clinical Draft Guard

- **Status:** Implemented working reference with in-session history protection; manual browser validation pending
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
4. Intercept ordinary Inertia `GET` navigation and marked in-session browser Back/Forward traversal, and offer exactly:
   - `Tetap di halaman`;
   - `Simpan draf lalu keluar`; or
   - `Keluar tanpa perubahan lokal`.
5. Do not intercept the form's own non-GET submission or link prefetch.
6. On save-and-leave, keep the dialog open in a disabled `Menyimpan draf…` state, submit the existing `SAVE_DRAFT` contract, and replay the deferred destination only from the successful response callback. Validation, HTTP, network, or cancelled-request failure keeps the user on the form, announces `Draf belum tersimpan`, and restores the same retry action without exposing field content.
7. On explicit discard, abandon only the local unsaved delta. Never delete or overwrite the last server-saved immutable version.
8. Mark each in-session history entry with a non-clinical integer position. On dirty Back/Forward, stop the event before Inertia swaps the page, restore the current entry, and replay the exact target only after successful draft save or explicit discard. Staying clears the held traversal without changing pages.
9. Register the native `beforeunload` boundary only while dirty, covering refresh, tab close, and external navigation. Remove the dirty history guard, browser listener, and Inertia listener on clean-state unmount.
10. Store no clinical draft in local storage, session storage, URL state, history state, or warning-dialog state. History state receives only the integer navigation position; the dialog holds only deferred navigation metadata and a generic form label.
11. Keep stale-session reauthentication/revalidation as an explicit manual-validation item; do not claim cross-encounter recovery until a separately threat-modeled design exists.

## Consequences

- Users receive an early visible dirty-state signal and must make a deliberate choice before ordinary in-app navigation.
- The existing versioned server remains the only durable draft store.
- A failed draft request remains visibly recoverable in the same encounter and cannot accidentally trigger discard or navigation while it is in flight.
- Browser Back and Forward now use the same deliberate choice boundary as visible Inertia links instead of bypassing it through Inertia's non-cancellable `popstate` path.
- Save-and-leave may create an additional immutable draft version, which is truthful and attributable.
- Forms without a safe server-side draft contract do not receive a fake save option; they require a separate workflow decision if later classified as long-form authoring.
- Native browser unload text is controlled by the browser and cannot use the custom Indonesian copy.

## Verification

The focused history/React/axe suite passes 11 tests covering position-only history metadata, intercepted Back and Forward restoration, explicit stay/replay, the visible status, accessible modal, all three choices, save-before-navigation ordering, in-flight action locking, generic failure announcement, retry, explicit discard, ignored `POST`/prefetch visits, native unload prevention, and listener cleanup. The complete frontend suite passes 23 files and 58 tests; TypeScript, ESLint, Prettier, and the production build verify the boot-time integration before Inertia initializes. The unchanged backend also passes 231 tests and 2,731 assertions plus Pint and PHPStan. Bounded live browser validation remains required before this follow-up evidence is closed.

## Related records

- [Outpatient Interaction Specifications](../design/OUTPATIENT_INTERACTION_SPECIFICATIONS.md)
- [Outpatient Acceptance Scenarios](../product/OUTPATIENT_ACCEPTANCE_SCENARIOS.md)
- [Outpatient Traceability Matrix](../product/OUTPATIENT_TRACEABILITY_MATRIX.md)
- [Checkpoint 2 UAT Guide](../operations/OUTPATIENT_CHECKPOINT_2_UAT_GUIDE.md)
