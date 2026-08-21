# Design Is — /make-plan Handoff

````
/make-plan Redesign SIMRS Campus UEU authenticated operate-mode app shell (header + breadcrumb + Beranda chrome). Current design failed audit at 16/30 with critical gaps in principles #3 aesthetic, #5 unobtrusive, #8 thorough, #9 environmentally friendly, #10 as little design as possible (scores 1 each).

Verdict paragraph (quoted from 03-verdict.md):
> REDESIGN the authenticated operate-mode shell chrome: the campus SI brand and top-nav information architecture are worth keeping, but the current header/breadcrumb stack fails aesthetic, thoroughness, unobtrusiveness, and restraint badly enough (clipped logo subtitle, cramped rhythm, abrupt navy→white seam, Soon-badge noise) that iterating paint on the broken geometry would preserve the failure.

Why redesign and not refine: Total 16/30 is below the REFINE threshold (≥20 with no zeros); load-bearing geometry of the header/breadcrumb stack is the failure, not a single pixel tweak.

Preserve from current design (MUST keep):
- UEU campus SI tokens and fonts: Plus Jakarta Sans + IBM Plex Mono; ueu-blue #1b75bc, ueu-orange #f26a1b accent only, ueu-navy #123b63 / #0d2b4a, ink #0f172a, muted #64748b, line #e2e8f0, surface #f1f5f9 (`resources/css/app.css`)
- Vendor top-nav operate-mode shell (not sidebar revival) — `resources/js/components/app-header.tsx`, `resources/js/layouts/app/app-header-layout.tsx`
- Live outpatient module routes only: Pendaftaran / Pemeriksaan / RM via `DEDICATED_HREFS` in `resources/js/lib/simrs-modules.ts`
- Indonesian primary labels: Beranda, Pengaturan, Keluar, module category names

Discard (structural patterns causing failures):
- AppLogo Fragment without horizontal flex row inside fixed h-14 header — clips “Universitas Esa Unggul” into the white breadcrumb bar. Evidence: `app-logo.tsx:4–15`, `app-header.tsx:82`, `app-header-layout.tsx:15`. Caused failure on principle #3 / #8.
- Hard navy→white breadcrumb strip (h-11, jammed single “Beranda” crumb) with abrupt seam. Evidence: `app-header-layout.tsx:14–18`, `home.tsx:15–20`. Caused failure on #3 / #5.
- High-visual-weight English “Soon” chip row (10 badges) dominating nav scan. Evidence: `app-header.tsx:57–70`. Caused failure on #5 / #10.

Top 3–5 moves from the audit (verbatim):
1. Principle #3 Aesthetic / #8 Thorough: Fix logo geometry and vertical rhythm — true horizontal flex row + adequate header height (or single-line brand) so subtitle never clips. Evidence: `app-logo.tsx:4–15`, `app-header.tsx:82`, `app-header-layout.tsx:15`.
2. Principle #5 Unobtrusive / #3 Aesthetic: Soften header→content transition and breadcrumb alignment — shared padding, quieter strip, less jammed Beranda crumb. Evidence: `app-header-layout.tsx:14–18`.
3. Principle #5 Unobtrusive / #10 As little design: Quiet Soon / placeholder nav — muted-only or Indonesian “Segera”, lower density so live modules scan first. Evidence: `app-header.tsx:57–70`.
4. Principle #8 Thorough / #2 Useful: Craft details — focus rings, hover, flash banner spacing, footnote contrast; consider skip-link. Evidence: `01-evidence.md` A11y; `home.tsx:62–77`, `:133–135`.
5. Principle #6 Honest (do not expand): Leave Gate/RBAC/Pendaftaran 403 to the parallel agent; do not change auth.

Redesign principles in priority order:
1. #3 Aesthetic — logo and header/breadcrumb stack read as one composed shell with no clipped text
2. #8 Thorough — spacing, focus, hover, contrast, empty edges considered on chrome
3. #5 Unobtrusive — chrome recedes; live modules and page content are figure
4. #10 As little design as possible — remove redundant single-crumb jam and badge noise without removing discoverability of future modules

Deliverables for the plan:
- New shell geometry (header height, logo flex, breadcrumb strip) — not a re-skin of the broken stack
- Low-fi before/after of header+breadcrumb vs current clipping
- States checklist for shell (focus, hover, muted Soon, flash under shell)
- Migration: same routes and module IA; no outpatient route breakage
- Cutover: ship on branch `fix/ui-shell-impeccable`; do not fight the 403 agent on main deploy

Anti-patterns to guard against (specific to REDESIGN):
- Porting old Fragment+h-14 stack under new colors
- Keeping both shells behind a flag indefinitely
- Redesigning toward purple/glow trends — stay on campus SI tokens
- Treating Preserve list as optional — brand tokens + top-nav IA + live RJ routes must survive
- Touching Gate/RBAC/auth or reviving Antrean MVP
````
