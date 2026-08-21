# Design Is — Evidence

**Date:** 2026-08-21  
**Surface:** Authenticated header shell + Beranda first viewport  
**Branch audited:** `fix/ui-shell-impeccable` @ `main` tip (`f134a74`)  
**Live URL:** https://simrs-campus-ueu-demo.vercel.app (unauthenticated → `/login`; authenticated shell inferred from source + build + user screenshot evidence)

Evidence subagents: Structural (`839f9d43`), Visual (`3cf1f08d`), Copy (`703ce51a` — **corrected** where it drifted to feature-branch strings), Weight (`e1d575cf`), A11y (`fe32cf54`).

---

## Structural

**Sources:** `app-header.tsx` 1–175; `app-header-layout.tsx` 1–24; `app-logo.tsx` 1–19; `breadcrumbs.tsx` 1–50; `app-shell.tsx` 1–21; `app-content.tsx` 1–32; `app-user-menu.tsx` 1–45; `home.tsx` 15–20, 50–60; `simrs-modules.ts` 12–40.

| Metric | Value | Citation |
|--------|------:|----------|
| Interactive elements (auth, both breakpoint trees mounted) | 33 | header logo/nav/user + sheet duplicates |
| Max nesting depth | 8 | AppHeaderLayout → … → ModuleNavLabel / UserMenu Link |
| Repeated-pattern count | 14 | Beranda + 13 modules × mobile+desktop |
| Dead unused breadcrumb symbols | 2 | `BreadcrumbEllipsis`, `MoreHorizontal` |

**Heights / clipping facts:**
- Header row: `h-14` (56px) — `app-header.tsx:82`
- Breadcrumb bar: `h-11` (44px), `bg-white`, hard `border-b` — `app-header-layout.tsx:15`
- Logo: Fragment root (no flex row); mark `size-9`; title `0.92rem` + subtitle `0.64rem` both `truncate` — `app-logo.tsx:4–15`
- Parent logo `Link` has no `inline-flex` / `items-center` — `app-header.tsx:137–143`
- Live modules on this branch: only `pendaftaran`, `pemeriksaan`, `rm` via `DEDICATED_HREFS` — `simrs-modules.ts:28–39`
- Klaim is **not** live (no dedicated href) → shows Soon — `simrs-modules.ts:16`

**#2 / #4 / #5 / #10 facts:** Primary nav destinations exist; sheet copy says “Pendaftaran, Pemeriksaan, RM”; sticky `z-40` + white breadcrumb chrome; dual nav trees + 10 Soon badges + single-item Beranda breadcrumb + page `h1` “Beranda”.

---

## Visual

**Sources:** shell files above; `resources/css/app.css` 11–16, 71–108, 134–158; `home.tsx` 52–136.

| Field | Value |
|-------|-------|
| Spacing scale (px, inferred Tailwind) | `[2, 4, 6, 8, 10, 12, 16, 20, 24, 32, 36, 40, 44, 56]` |
| Type scale (px) | `[10, 10.24, 14, 14.72, 16, 30, 36]` |
| Distinct colors in shell/CSS/page | 27 (26 hex + sky-100 oklch) |
| Lowest primary-ish contrast | **4.34:1** `#64748b` on `#f1f5f9` (Beranda lead) |
| Fonts live | Plus Jakarta Sans + IBM Plex Mono (`app.css`) |

**States:** focus **present** (white rings on navy + global `:focus-visible`); error/success **page flash only**; empty/loading **missing** in shell; disabled **partial** (breadcrumb page `aria-disabled` only).

**Clipping risk (code + user evidence):** two-line logo inside Fragment without horizontal flex, fixed `h-14`, white `h-11` bar immediately below — matches user report that “Universitas Esa Unggul” is clipped by the breadcrumb bar. Subtitle ~10.24px is below design-system 12px floor (`UEU_CLINICAL_DESIGN_SYSTEM.md`).

**#3 / #5 / #8 facts:** Campus SI navy `#0d2b4a` / blue `#1b75bc` / orange token present but unused in header; abrupt navy→white seam; focus present; empty/loading absent; truncate + height conflict.

---

## Copy & honesty (branch-corrected)

**User-facing shell/Beranda strings (main):** logo `SIMRS Campus UEU` / `Universitas Esa Unggul`; nav Beranda + 13 category labels; `Soon`; sheet “Modul aktif: Pendaftaran, Pemeriksaan, RM…”; Beranda h1 + “ringkasan operasional rawat jalan”; four stat labels; alur cepat three links; footnote that Klaim/BPJS/Apotek remain placeholders (`home.tsx:133–135`).

**Inflations:** none marketing-superlative.

**Dark-pattern-adjacent:** Soon modules remain full `NavLink`s to `/modul/{slug}` placeholders (`app-header.tsx` + `simrs-modules.ts:34–36`).

**Jargon:** `Soon` (EN), `RM`, `GF`, `IoT`, `Farmasi IBS`, `Help` (EN) without expansion.

**Label→behavior (this branch):**
- Soon items → clickable placeholders (not disabled)
- Two stats (“Kunjungan” / “Pasien baru”) share `/pendaftaran/rawat-jalan`
- “Pasien baru hari ini” count uses `is_synthetic` (controller) — honesty gap on Beranda cards (adjacent to shell)

*Note: Copy subagent cited IGD/Klaim-sandbox strings from a feature branch that is **not** on this checkout; discarded.*

---

## Weight & friction

| Metric | Value | Method |
|--------|------:|--------|
| Initial Beranda JS | **520,267** B | Vite `public/build` graph sum |
| Largest chunk | 316,974 B | `jsx-runtime-*.js` |
| Network requests | EST **24–28** | login HTML wiring + Beranda graph |
| TTI | EST **1200–2800** ms | transfer + hydrate estimate |
| Idle shell `transition-*` | **3** | header / user-menu / breadcrumb link |
| Soon badges on load | **10** | 13 − 3 live |
| Open modals on load | **0** | sheet closed |
| `prefers-reduced-motion` | **absent** | `app.css` |
| Dark shell tokens | unused | header hard-coded navy |

---

## Accessibility

| Field | Finding |
|-------|---------|
| Skip-link | **No** |
| Landmarks (Beranda) | 4: banner, nav modul, nav breadcrumb, main |
| Focus order | logo → modules → user → (non-focus crumb) → stats → alur |
| Keyboard | primary links/buttons yes; current breadcrumb no |
| Contrast fails | `#64748b`/`#f1f5f9` 4.34:1 AA normal; `#94a3b8` footnote ~2.5:1 |
| Contrast passes | white / sky-100 on `#0d2b4a` |

---

## Known gaps (orchestrator)

- Authenticated live screenshot not captured (demo redirects to login; browser MCP tab creation failed this session). User screenshot evidence accepted for clipping.
- Gzip/brotli transfer sizes not measured.
- Runtime focus-trap / axe not run.
