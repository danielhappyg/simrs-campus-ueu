# T1 Local Accessibility Engineering Evidence — 2026-08-27

## Decision summary

**Engineering conclusion: `OPEN / PARTIAL`.** This is local automated and bounded native-browser accessibility evidence for current unpublished T1 operational states. It is not a WCAG conformance statement, a complete keyboard or screen-reader review, hosted UAT, owner acceptance, or G3 closure.

The evidence covers synthetic teaching data only on base commit `d04b35f1f85ab0e6e56818d08c374f3a5bb3cb88` plus the current local worktree. No commit, push, pull request, GitHub Actions run, deployment, hosted migration, credential use, or hosted mutation occurred.

```yaml
standard: WCAG_2_1_AA
classification: LOCAL_NOT_DEPLOYED
data_boundary: synthetic_only
engineering_conclusion: OPEN_PARTIAL
owner_accessibility_acceptance: NOT_ACCEPTED
owner_evidence_reference: null
hosted_uat: NOT_RUN
```

## Scope

The automated page gate renders representative states for:

- outpatient registration with patient search, registration form, one synthetic search result, one daily encounter, and pagination;
- outpatient examination with populated filters, one synthetic encounter, and pagination;
- laboratory worklist with one active synthetic order, pagination, and the result form expanded; and
- registration recap with filters, totals, one synthetic row, and pagination.

The shared pagination gate separately covers first-page, middle-page, last-page, empty, and unavailable-direction states. The locally mapped workflow references are E2E-01, E2E-03, E2E-05, and E2E-15 only.

## Automated evidence

| Modality                    | Status         | Exact result and boundary                                                                                                                                                                                                                                                                                       |
| --------------------------- | -------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Page-level axe              | `PARTIAL_PASS` | Four representative page states and one multi-error registration validation state passed with zero detected violations for axe tags `wcag2a`, `wcag2aa`, `wcag21a`, and `wcag21aa`.                                                                                                                             |
| Pagination axe              | `PARTIAL_PASS` | First, middle, and last pagination states passed with zero detected violations.                                                                                                                                                                                                                                 |
| Semantic DOM                | `PARTIAL_PASS` | Examination filters have programmatic labels; registration search has an accessible name; tables have captions and named action columns; laboratory feedback/result disclosure has status and expanded semantics; registration errors have stable IDs, invalid state, descriptions, and a linked named summary. |
| Automated focus behavior    | `PARTIAL_PASS` | jsdom/user-event confirms pagination-link Tab order. A stateful Inertia mock confirms registration summary focus after a failed submit and refocus after a repeated failure with the same error count. This is not native-browser keyboard acceptance.                                                          |
| Focused accessibility tests | `PASS`         | 3 files, 12 tests: operational page gate 4/4, registration validation state 1/1, and shared pagination 7/7.                                                                                                                                                                                                     |
| Complete frontend suite     | `PASS`         | 10 files, 32 tests. TypeScript, ESLint, Prettier, and production build also passed.                                                                                                                                                                                                                             |
| Native browser              | `PARTIAL_PASS` | Four T1 routes rendered with named controls/captions and no console errors; a real Laravel validation response focused the named error summary and linked invalid controls; three contrast samples passed; and a found 320-pixel page-overflow defect was fixed and retested.                                     |

Tool versions used by the repository lock state:

- axe-core 4.12.1;
- Vitest 4.1.10;
- jsdom 29.1.1; and
- Testing Library user-event 14.6.1.

`color-contrast` is deliberately disabled in the jsdom axe runs because jsdom has no layout/rendering engine and cannot produce trustworthy computed contrast. The test wraps each page body in `main` because the real Inertia application layout owns the single main landmark outside the page component.

Primary automated evidence:

- `resources/js/test/t1-operational-pages-accessibility.test.tsx`
- `resources/js/test/registration-validation-accessibility.test.tsx`
- `resources/js/components/operational-pagination.test.tsx`
- `resources/js/test/accessibility-guardrails.test.tsx`
- `docs/operations/T1_LOCAL_NATIVE_BROWSER_ACCESSIBILITY_REHEARSAL_2026-08-27.md`

## Manual and hosted evidence still required

| Modality                                 | Status    | Required retained evidence                                                                                             |
| ---------------------------------------- | --------- | ---------------------------------------------------------------------------------------------------------------------- |
| Native keyboard traversal and activation | `NOT_RUN` | Browser, OS, complete Tab order, Enter/Space activation, pagination navigation, and focus restoration                  |
| Screen reader                            | `NOT_RUN` | Named assistive technology/browser/OS plus announced name, role, value, table context, errors, and live-region changes |
| Contrast                                 | `PARTIAL_PASS` | Three rendered registration samples passed; complete route/state coverage remains required                            |
| Text resize at 200%                      | `NOT_RUN` | A 640-pixel viewport-equivalent proxy passed, but native 200% zoom/text resize was not activated                       |
| Reflow at 320 CSS pixels or 400%         | `PARTIAL_PASS` | Four routes passed after a discovered page-overflow defect was fixed; broader application coverage remains required   |
| Rendered focus visibility                | `PARTIAL_PASS` | Summary focus and linked invalid-input focus ring were observed; every critical control remains unreviewed            |
| Touch-target geometry                    | `NOT_RUN` | Computed rendered dimensions; utility-class presence alone is not acceptance evidence                                  |
| Hosted role-based accessibility UAT      | `NOT_RUN` | Exact deployed SHA, authenticated role/state, browser evidence, defects, and owner decision                            |

## Open accessibility gaps and limitations

- The native-browser rehearsal now proves one real Laravel validation-error round trip, native required-field focus, summary focus, linked invalid-control focus, and bounded reflow/contrast behavior. It does not prove all error, denial, correction, or pagination focus states.
- Several compact operational controls require rendered touch-target measurement; this record does not infer 44-by-44-pixel geometry from source classes.
- Automated axe does not cover all pages, roles, responsive states, print views, modal/focus-trap paths, or every clinical error/denial/failure state.
- No VoiceOver, NVDA, TalkBack, complete native keyboard sequence, native 200% zoom, or complete contrast session was performed. The retained 320-pixel native-browser reflow pass is bounded to the four named T1 routes.
- Engineering evidence does not substitute for accountable UX/accessibility, product, Clinical, RMIK, Laboratory, or other affected owner acceptance.

Therefore the aggregate accessibility gate remains open, all existing workflow `owner_acceptance` values remain unchanged, and this evidence must not be used to claim complete WCAG 2.1 AA conformity or formal G3 acceptance.
