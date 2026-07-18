# Unsaved Clinical Draft Guard Implementation Plan

**Goal:** close the `UX-04` gap without persisting clinical text outside the versioned server record.

## Boundaries

- Apply only to editable long forms with an existing `SAVE_DRAFT` endpoint: nursing intake, medical assessment, and encounter closure.
- Do not use local storage, session storage, URL payloads, cross-encounter recovery, or silent autosave.
- Do not intercept the form's own submission or prefetch traffic.
- Do not merge or deploy.

## Tasks

1. Implement one accessible shared guard with a persistent dirty-state marker, three explicit choices, and a generic same-encounter failure/retry state.
2. Defer ordinary Inertia navigation and marked in-session Back/Forward traversal, then replay the exact destination only after successful save or explicit local discard.
3. Add position-only history metadata plus native unload protection while dirty, and remove dirty listeners on unmount without storing clinical content in browser state.
4. Integrate the guard with the existing typed Inertia forms and draft intents.
5. Add React/axe coverage for interaction, ordering, unload, and cleanup.
6. Update ADR, interaction, traceability, UAT, validation, and README evidence.
7. Run complete frontend/backend/static/build/database/documentation gates and a bounded local browser rehearsal.
8. Commit and push only to the existing private draft PR; do not merge or deploy.
