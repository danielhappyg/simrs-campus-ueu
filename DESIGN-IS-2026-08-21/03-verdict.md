# Design Is — Verdict

**Total:** 16/30 (`02-scorecard.md`)  
**Rule:** total &lt; 20 → **REDESIGN** (no principle scored 0).

## Verdict

**REDESIGN** the authenticated operate-mode shell chrome: the campus SI brand and top-nav information architecture are worth keeping, but the current header/breadcrumb stack fails aesthetic, thoroughness, unobtrusiveness, and restraint badly enough (clipped logo subtitle, cramped rhythm, abrupt navy→white seam, Soon-badge noise) that iterating paint on the broken geometry would preserve the failure.

## Highest-leverage moves

1. **#3 Aesthetic / #8 Thorough — Fix logo geometry and vertical rhythm.** Make the logo a true horizontal flex row with adequate header height (or single-line brand treatment) so “Universitas Esa Unggul” never clips into the breadcrumb bar. Evidence: `app-logo.tsx:4–15`, `app-header.tsx:82`, `app-header-layout.tsx:15`, user clipping report in `00-scope.md`.

2. **#5 Unobtrusive / #3 Aesthetic — Soften header→content transition and breadcrumb alignment.** Replace the abrupt blue→white hard cut with a coherent seam (shared horizontal padding, quieter breadcrumb strip, optional soft divider) so Beranda’s first crumb is not jammed. Evidence: `app-header-layout.tsx:14–18`, `home.tsx:15–20`.

3. **#5 Unobtrusive / #10 As little design — Quiet Soon / placeholder nav.** Keep placeholders discoverable but reduce badge visual weight (Indonesian “Segera” or muted-only, smaller density) so live modules scan first. Evidence: `app-header.tsx:57–70`, 10 Soon badges in `01-evidence.md` Weight.

4. **#8 Thorough / #2 Useful — Craft details.** Focus rings, hover, flash banner spacing under shell, footnote contrast (`#94a3b8` fail), consider skip-link. Evidence: `01-evidence.md` A11y; `home.tsx:62–77`, `:133–135`.

5. **#6 Honest (adjacent, do not expand scope into RBAC) —** Do not “fix” honesty by touching Gate; leave Pendaftaran 403 to the other agent. Optional later: disable or `aria` Soon destinations — out of shell geometry pass if it risks route behavior.

## Preserve

- UEU campus SI tokens and fonts
- Vendor top-nav operate mode (not sidebar revival)
- Live outpatient routes: Pendaftaran / Pemeriksaan / RM
- Indonesian primary labels (Beranda, Pengaturan, Keluar)

## Discard (shell chrome patterns)

- Fragment logo without flex row inside `h-14`
- Hard white `h-11` breadcrumb bar that clips and jams “Beranda”
- High-contrast “Soon” chip row dominating the nav scan path
