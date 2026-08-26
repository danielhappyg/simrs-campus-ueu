# T1 IGD/RI Native-Browser Rehearsal — 2026-08-27

**Status:** `LOCAL / PASS FOR BOUNDED REHEARSAL`
**Published base:** `1e188215ff4b39af547cda75a1904cfe1d062f75`
**Data boundary:** disposable SQLite with generated synthetic census only; `APP_MODE=SIMULATION`, `APP_SYNTHETIC_ONLY=true`, `BREAK_GLASS_MODE=off`
**Publication boundary:** no commit, push, deployment, hosted migration, hosted UAT, or owner acceptance

## Environment and cleanup

The current working tree was copied into a sanitized temporary directory without Git metadata, repository environment files, storage, documentation, protected paths, or credentials. Existing local dependency directories were mounted read-only by reference. A temporary strong demo password was generated without being printed, the application was freshly migrated and seeded into disposable SQLite, and Laravel was served on `127.0.0.1:8766` only. The server, browser tab, password, database, and temporary copy were removed after each rehearsal.

## Role and route results

Synthetic registrar, nurse, physician, and RMIK accounts authenticated successfully. The following routes rendered without browser console warnings or errors:

- `/pendaftaran/igd`
- `/pendaftaran/rawat-inap`
- `/pemeriksaan/triage`
- `/pemeriksaan/igd` and one synthetic IGD detail
- `/pemeriksaan/rawat-inap` and one synthetic RI detail

Every checked route exposed the simulation banner, an appropriate heading, labels, table captions, breadcrumbs, and route links. Registrar registration controls were present; nurse and physician saw their permitted clinical forms; RMIK could view worklists/details but had no registration or clinical-write form. The triage page remained visibly identified as a stub and linked to IGD detail; no triage-write capability was inferred.

## Bounded synthetic journeys

- IGD `SYNTH-ENC-IGD-025`: a nurse submitted one synthetic nursing note, a physician submitted one synthetic medical note, and the displayed status advanced from `Dalam pemeriksaan` to `Siap RM`.
- RI `SYNTH-ENC-RI-030`: the admission retained ward/class/bed/payer/origin context; a nurse note retained `Dalam pemeriksaan`, then a physician note advanced the displayed status to `Siap RM`.
- Successful note feedback rendered as a polite status announcement. Wrong-role mutation and attributable audit behavior remain primarily automated-test evidence, not a browser claim from this rehearsal.

## Responsive and keyboard observations

At `320x720` and a `640x720` 200%-equivalent viewport proxy, `body.scrollWidth === body.clientWidth` on all five route surfaces. Only the intentional table wrappers scrolled horizontally. Visible focus outlines were observed in a bounded IGD-worklist keyboard sample. Native date controls produced repeated focus identifiers during automated sampling, so this is not complete keyboard-order evidence. Form controls were generally 44 pixels high; native checkboxes were approximately 13 pixels and remain a touch-target gap.

## Validation defect and verified correction

The first empty IGD registration attempt was intercepted by native browser constraint validation before Laravel/Inertia could render its existing accessible error contract. The shared RJ/IGD registration form now uses `noValidate`, allowing server validation to remain authoritative. The repeated disposable-browser check proved:

- a named, focusable `role="alert"` summary appears and receives focus;
- invalid controls expose `aria-invalid="true"` and stable `aria-describedby` references;
- a summary link focuses `#full_name`;
- a repeated failed submission restores focus to the summary; and
- no browser console errors occur.

The corresponding frontend regression suite passes 45 tests across 12 files, including the IGD multi-error, repeated-focus, linked-field, and axe state.

## Explicitly unproven

- native screen-reader announcement and name/role/state verification;
- true browser zoom or text-only resize at 200%;
- complete keyboard traversal, contrast, focus-visibility, and touch-target audits;
- hosted Vercel/Supabase behavior or role-based hosted UAT;
- triage facts, diagnostics, medications, disposition, transfer/discharge, RMIK handoff, reconciliation, or owner acceptance.

This record supports a bounded local browser claim only. It does not make E2E-02 complete or close G3.
