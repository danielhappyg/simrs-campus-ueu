# Design Is — Scope Lock

**Date:** 2026-08-21  
**Auditor:** Orchestrator (design-is)  
**Branch:** `fix/ui-shell-impeccable` (from `main`)

## What is being audited

- **Live URL:** https://simrs-campus-ueu-demo.vercel.app (authenticated app shell after login; Beranda first viewport)
- **Repo path:** `resources/js/layouts/app/app-header-layout.tsx`, `resources/js/components/app-header.tsx`, `resources/js/components/app-logo.tsx`, `resources/js/components/breadcrumbs.tsx`, related shell (`app-shell.tsx`, `app-content.tsx`, `app-user-menu.tsx`), Beranda page under rebuild/home
- **Screens:** Top vendor nav (logo + module nav + user menu), white breadcrumb bar, Beranda first viewport (flash/banner if present)

## Primary user & task

- **Primary user:** Teaching-hospital staff / RMIK learners operating SIMRS Campus UEU (operate mode)
- **Primary task:** Orient within the authenticated shell, identify current module location via breadcrumb, and navigate to live modules (Pendaftaran, Pemeriksaan, RM) without chrome fighting content

## Constraints

- **Brand:** UEU campus SI tokens (Plus Jakarta Sans + IBM Plex Mono; ueu-blue `#1b75bc`, ueu-orange `#f26a1b` accent only, ueu-navy `#123b63` / `#0d2b4a`, ink `#0f172a`, muted `#64748b`, line `#e2e8f0`, surface `#f1f5f9`) — not generic AI purple
- **Shell pattern:** Vendor top-nav (operate mode) — scanability, consistency
- **Stack:** Laravel + Inertia + React + Tailwind
- **Out of scope for this audit/fix:** Gate/RBAC/auth (403 owned by another agent), Antrean MVP revival, outpatient route logic changes, `.vercel/`, `deliverables/`

## User evidence (known defect)

- Header/logo area: “Universitas Esa Unggul” subtitle is **clipped** by the white breadcrumb bar
- Header feels cramped; Beranda breadcrumb jammed
- Abrupt blue→white transition between header and breadcrumb bar

## Reference

- Aesthetic source of truth: si-ueu-2026 (`apps/dashboard-web`) campus SI shell
- Daniel’s operate-mode brand rules (user rules)
