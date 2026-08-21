# Design Is — Scorecard

**Surface:** Authenticated app shell (header + breadcrumb) + Beranda first viewport  
**Evidence:** `01-evidence.md`  
**Tie-breaker:** when uncertain, lower score. Score worst instance, not mean.

---

1. Good design is innovative — Score: **2/3**  
   Evidence: Campus SI vendor top-nav with UEU tokens (Plus Jakarta / navy `#0d2b4a`) refreshes a teaching-hospital SIMRS shell rather than inventing a new paradigm (`01-evidence.md` Visual / Structural).  
   Justification: Clear improvement over generic AI purple admin chrome, but still a familiar top-nav pattern — not a novel restrained interaction.

2. Good design is useful — Score: **2/3**  
   Evidence: Live modules reachable in one click; Beranda stats/alur link to RJ flows; no skip-link forces full chrome tab-through; 10 Soon items add adjacent noise (`01-evidence.md` Structural / A11y).  
   Justification: Primary navigate/orient task works, but adjacent surface (Soon density + no skip) adds steps.

3. Good design is aesthetic — Score: **1/3**  
   Evidence: Logo subtitle clipped by white breadcrumb bar; cramped `h-14` + Fragment logo without flex row; abrupt navy→white seam (`00-scope.md` user evidence; `app-logo.tsx`; `app-header-layout.tsx:15`).  
   Justification: One jarring violation (clipping) plus cramped rhythm — matches “one jarring violation” anchor for 1.

4. Good design is understandable — Score: **2/3**  
   Evidence: Indonesian labels mostly clear; English `Soon`/`Help`; acronyms `RM`/`GF` without expansion; single-item Beranda breadcrumb duplicates page `h1` (`01-evidence.md` Copy / A11y).  
   Justification: One control cluster (Soon/jargon) needs clarification — not primary-action opaque.

5. Good design is unobtrusive — Score: **1/3**  
   Evidence: Ten “Soon” badges compete with live nav; sticky dual chrome (navy header + white breadcrumb) with hard seam; clipping pulls eye to defect (`01-evidence.md` Weight / Structural).  
   Justification: Decoration (Soon row) and broken chrome compete with content — worse than “visible but quiet”.

6. Good design is honest — Score: **2/3**  
   Evidence: Soon modules still navigate to placeholders; two different Beranda stats share one unfiltered href; “Pasien baru” vs `is_synthetic` (`01-evidence.md` Copy).  
   Justification: ≤1–2 mild honesty gaps, no deceptive forced-continuity dark pattern — scores 2 not 1 (not 2+ marketing inflations).

7. Good design is long-lasting — Score: **3/3**  
   Evidence: Plus Jakarta + IBM Plex Mono; campus SI blues/navy/orange accent; no purple-glow fad (`app.css` tokens).  
   Justification: Visual language would read as current campus SI three years out; no dated trend markers on shell.

8. Good design is thorough down to the last detail — Score: **1/3**  
   Evidence: Clipping/truncate conflict; empty/loading absent in shell; footnote contrast fail; no skip-link; breadcrumb jammed on single crumb (`01-evidence.md` Visual / A11y).  
   Justification: 2–3 missing or rough details (empty, loading, edge clipping) → 1.

9. Good design is environmentally friendly — Score: **1/3**  
   Evidence: Initial Beranda JS ~520 KB; EST 24–28 requests; no `prefers-reduced-motion`; 10 Soon badges on idle (`01-evidence.md` Weight).  
   Justification: 500KB–2MB band with ungated motion preference absence → 1.

10. Good design is as little design as possible — Score: **1/3**  
    Evidence: 10 removable Soon badges; single-item breadcrumb bar that only restates “Beranda”; dual product naming (logo + page lead) (`01-evidence.md` Structural / Copy).  
    Justification: 3–5 removable / redundant chrome elements → 1.

---

## Total: **16 / 30**
