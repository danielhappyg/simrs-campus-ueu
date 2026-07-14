# Legacy SIMRS RMIK Assessment

**Assessment date:** 15 July 2026  
**Legacy source:** `SIMRS RMIK` Google Drive folder  
**Live deployment reviewed:** <https://simrsrmikueu.quadrascience.id/>  
**Assessment posture:** Read-only; no source, database, or deployment changes were made

## Executive verdict

The existing application is useful as an interactive requirements prototype, but it is not a safe or maintainable foundation for the intended multidisciplinary campus system.

The recommended strategy is to keep the legacy build online only as a controlled reference, start the new system in this workspace, and selectively carry forward workflows, terminology, and visual cues—not the authentication model, API design, database schema, or navigation architecture.

This is not primarily a “beautification” project. The largest gaps are:

1. the product presents simulated integrations and statistics as real;
2. authorization is mostly client-side and several APIs are publicly readable without authentication;
3. most visible clinical modules are mock or browser-local state;
4. the information architecture follows screens rather than an encounter lifecycle;
5. it has no teaching-session, learner, supervisor, competency, or sign-off model;
6. its data model allows destructive deletion of clinical records; and
7. its forms and interaction patterns have substantial accessibility and workflow-density problems.

## What was inspected

- All source files under `src/`, `api/`, and `database/`
- Existing English and Indonesian PRDs under `docs/`
- Live login, dashboard, registration, outpatient examination, EMR, pharmacy, billing, BPJS, claims, inpatient, IGD, laboratory, radiology, queue display, launcher, administration, and help screens
- Public API response shape, authentication behavior, CORS headers, and site response headers
- The supplied 8000×4500 transparent UEU logo
- Static linting and PHP syntax checks

The repository contains approximately 12,115 lines across the application source, API scripts, styles, and schema.

## Current implementation

| Layer | As built |
|---|---|
| Front end | React 19, TypeScript, Vite 8, plain CSS, Lucide icons |
| Navigation | One `activeView` state and a large switch statement; no router or deep links |
| Back end | Plain PHP endpoint scripts using PDO |
| Database | MySQL/InnoDB; patient, visit, EMR, diagnosis, procedure, lab, prescription, billing, and claim tables |
| Authentication | Password verification returns a user object; no server session or access token |
| Authorization | LocalStorage permission groups and client-side menu hiding |
| Hosting | Hostinger/LiteSpeed/PHP 8.3 |
| Integration | BPJS and SATUSEHAT behavior is simulated in the front end |

### Honest persistence map

| Capability | Current state |
|---|---|
| Login password verification | Persisted, but no authenticated session follows it |
| Patient registration and visit creation | API/MySQL connected |
| User administration | API connected, but endpoint authorization is absent |
| EMR, laboratory, pharmacy, and dashboard APIs | Endpoint files exist; the main screens largely do not consume them |
| Master data and permission groups | Browser LocalStorage |
| Examination, archive, pharmacy, cashier, BPJS, claims | Demo/in-memory data |
| Inpatient, IGD, scheduling, queue display | Demo/in-memory data |
| SATUSEHAT/BPJS connectivity | Simulated only |

The broad visual scope therefore overstates the implemented clinical system. A reload can erase much of what appears to have been recorded.

## Critical findings

| Priority | Finding | Evidence | Required response |
|---|---|---|---|
| P0 | Database credentials are embedded in a deployed source configuration file. | `api/config.php` lines 7–10 | Rotate the credential, move secrets to environment configuration, and ensure they never enter the new repository. |
| P0 | API resources are not protected by an authenticated server session. | `api/auth.php` returns only a user object; other endpoint scripts have no auth middleware. A live unauthenticated GET returned the user directory. | Put the legacy deployment behind access control immediately; the new system must enforce authorization server-side for every action. |
| P0 | CORS accepts any origin and exposes write methods. | `api/config.php` lines 33–37; verified live `Access-Control-Allow-Origin: *` | Restrict the legacy origin and use a same-origin architecture for the rebuild. |
| P0 | Public default credentials and helper buttons are displayed on the live login page. | `src/views/Login.tsx` lines 305–334 | Remove public credentials; use instructor-created classroom access or seeded accounts only in isolated environments. |
| P0 | Simulated SATUSEHAT and BPJS actions claim successful real integration. | `Pendaftaran.tsx`, `Pemeriksaan.tsx`, `PenunjangMedis.tsx`, `Bpjs.tsx` | Add an unavoidable `SIMULATION` environment label; never render “connected”, “verified”, or “published” without a verified adapter response. |
| P1 | RBAC exists only in the browser and can be edited locally. | `src/AppShell.tsx` lines 43–210 | Replace with database-backed roles, capabilities, contextual policies, and server-side checks. |
| P1 | Clinical children are removed by cascade delete and patient deletion is exposed. | `database/schema.sql` lines 63, 86, 98, 109, 124, 148, 179, 192; `src/services/api.ts` lines 38–39 | Use archive/amendment semantics. Medical records must not be physically deleted through ordinary application flows. |
| P1 | Record updates replace diagnoses and procedures rather than preserving amendment history. | `api/rekam_medis.php` deletes then reinserts child records | Add versioning, provenance, amendment reason, author, timestamp, and signature state. |
| P1 | Two incompatible role vocabularies exist. | Database enum uses lower-case technical roles; `AppShell` uses localized group names in LocalStorage | Define one capability model, separate staff profession from application role, and include learner/supervisor context. |
| P1 | The system has no educational governance model. | No entities for course, cohort, scenario, learner attempt, supervisor, competency, or sign-off | Make the simulation and supervision layer a foundation capability, not a later add-on. |
| P1 | Forms and clickable queues are not reliably accessible. | Live DOM review found controls without programmatic names and clickable patient cards without button semantics | Adopt WCAG 2.2 AA acceptance criteria and automated accessibility checks. |
| P2 | Large components combine configuration, data, and presentation. | `ParamManager.tsx` is about 1,700 lines; `Pendaftaran.tsx` and `Pemeriksaan.tsx` each exceed 1,000 lines | Use feature modules, reusable form primitives, domain services, and testable state boundaries. |
| P2 | Navigation cannot be bookmarked or restored. | `AppShell.tsx` uses an `activeView` switch | Use real routes and stable patient/encounter URLs. |

## Hospital-service coverage gap

Permenkes 6/2026 now provides the current hospital framework and repeals Permenkes 82/2013. It identifies hospital services that include medical, intensive, surgical, nursing/midwifery, pharmacy, laboratory, radiology, blood, nutrition, mortuary, central sterilization, and infrastructure/equipment maintenance services. The legacy prototype covers only parts of that surface.

| Service area | Legacy coverage | Assessment |
|---|---|---|
| Medical/outpatient | Interactive prototype | Useful discovery reference; not a complete persisted encounter |
| Emergency | Interactive board | No structured triage record, orders, handoff, or persistence |
| Inpatient | Bed board prototype | No admission episode, nursing plan, medication administration, or discharge record |
| Nursing/midwifery | Mentioned in permissions | No discipline-specific assessment/care-plan workflow |
| Pharmacy | Queue prototype + unused API | No formulary, verification, stock ledger, FEFO, medication administration, or reconciliation |
| Laboratory | Queue prototype + partial API | No specimen identity chain, verifier provenance, critical-result acknowledgement, or terminology mapping |
| Radiology | Queue prototype | No order/result persistence, DICOM/PACS boundary, or verifier workflow |
| Nutrition | Permission/menu text only | Missing |
| Psychology | Missing | Missing; also requires stronger note segmentation and consent rules |
| Physiotherapy/rehabilitation | Permission/menu text only | Missing |
| Surgery/operating theatre | Login statistic only | Missing |
| Intensive care | Bed label only | Missing |
| Blood service | Permission/menu text only | Missing |
| CSSD, mortuary, maintenance | Permission/menu text only or absent | Missing |
| RMIK/coding | Illustrative ICD rules and claim checklist | Too narrow and potentially misleading; needs quality, amendments, disclosure, retention, reporting, and provenance |
| Education/research | Missing | Fundamental gap for a university platform |

## Design critique

### What works

- The UEU blue/orange association is immediately recognizable.
- The light clinical base is cleaner than many legacy hospital interfaces.
- Queues, workspaces, and status concepts are understandable at a glance.
- Bahasa Indonesia is used consistently enough to serve as the primary UI language.
- The prototypes are valuable conversation prompts for faculty workshops.

### What must change

| Dimension | Finding | Direction |
|---|---|---|
| First impression | The portal looks modern but overpromises “real-time” and “integrated” behavior. | Lead with `Campus Clinical Simulation`; show environment and scenario identity before marketing claims. |
| Navigation | Sidebar and launcher duplicate the same destination model. | Use one role-aware navigation plus `My Work`; keep a command/search palette, not a second menu universe. |
| Patient context | Users jump between disconnected screen demos. | Keep patient, encounter, location, allergies, and simulation state in a persistent context header. |
| Registration | One extremely long two-column form combines identity, guarantor, encounter, insurance, and print operations. | Use a task sequence: find/create patient → verify identity → create encounter → coverage → documents. Save drafts between steps. |
| Clinical workspace | Tabs overflow and compete with patient/queue panels. | Use a stable patient summary, vertical clinical sections, clear draft/sign states, and discipline-aware work queues. |
| Visual hierarchy | Many pale cards and badges carry similar weight; essential warnings do not dominate. | Reserve high-emphasis color for clinical risk, overdue work, and destructive actions. Brand color should not encode clinical severity. |
| Consistency | Some items are semantic buttons while other clickable cards are generic containers. | Standardize interaction primitives and keyboard behavior. |
| Accessibility | Inputs often lack programmatic labels; small text and pale states reduce usability. | WCAG 2.2 AA, visible focus, semantic controls, error summaries, target size, contrast, and keyboard-first operation. |
| Responsive behavior | Dense screens clip or become awkward around laptop/tablet widths. | Design for 1280 desktop and 1024 tablet clinical use; do not force full charting onto phones in the first release. |
| Branding | The live app adds a partner lockup that is not present in the supplied logo. | Use only approved institutional lockups. Treat the uploaded UEU logo as the reference until an official brand kit is supplied. |

### Proposed visual foundation

- **Primary:** UEU blue `#0F75BC`
- **Accent:** UEU orange `#F05A28`, used sparingly for brand emphasis and active navigation
- **Clinical surfaces:** white and slate neutrals with stronger borders than the legacy glassmorphism
- **Semantic colors:** independent success, warning, critical, and information tokens; never rely on color alone
- **Typography:** Inter/system sans with a minimum 14–16 px base depending on density mode
- **Density:** comfortable teaching mode and compact operations mode using the same components
- **Logo:** use the full lockup on login/landing, compact mark in the shell, and no low-contrast watermarking

## What to retain, rebuild, and retire

### Retain as requirements evidence

- Indonesian hospital terminology and queue concepts
- Patient/visit as the start of an encounter chain
- Work-queue patterns for examination, pharmacy, cashier, claims, lab, and radiology
- UEU blue/orange brand association
- The useful distinction between clinical services and administrative/reference configuration
- Existing PRDs as a historical inventory

### Rebuild from first principles

- Identity, authentication, authorization, audit, and session handling
- Patient identity and encounter model
- Clinical documentation, orders/results, medications, billing, and claims
- Education session, learner, supervisor, scenario, sign-off, and assessment model
- Routing, patient context, forms, tables, accessibility, and design tokens
- Database migrations, tests, deployment pipeline, backups, and observability

### Retire

- Public default credentials
- LocalStorage as a system of record
- Cosmetic “connected/verified/published” integration states
- Hard-coded dashboard metrics presented as live data
- Simplistic diagnosis-to-procedure claim validation presented as authoritative
- General-purpose physical deletion of patients/visits/records
- Duplicate launcher navigation
- Unapproved partner branding

## Immediate containment while the legacy site remains available

These are operational recommendations; they were not executed during this assessment.

1. Rotate the exposed database credential and remove it from all source copies and deployment history.
2. Restrict the site to authorized faculty/students or place it behind an additional access layer.
3. Remove public credentials from the login page and documentation.
4. Add a persistent `SIMULATION — SYNTHETIC DATA ONLY` banner.
5. Require authentication and server-side authorization before every API read or write; until then, disable mutation endpoints if feasible.
6. Restrict CORS to the exact application origin.
7. Confirm the database contains no real patient data; if it does, take the site offline and begin an incident/privacy review.
8. Create a backup and immutable snapshot before any remediation.

## Verification record

- `npm run lint`: passed with no reported findings
- `php -l api/*.php`: all endpoint files passed syntax validation
- Live unauthenticated API checks: dashboard and user-directory resources returned HTTP 200; patient and visit collections were empty at assessment time
- Live CORS check: arbitrary origin accepted
- Live browser review: authenticated screens and module flows inspected without saving or altering records
- A copied build check could not be completed because Google Drive did not hydrate copied dependency executables; this does not change the runtime/security findings

## Conclusion

The legacy application has served its purpose: it makes the original ambition visible. The next project should treat it as a clickable storyboard and build a new, honest, auditable teaching platform around complete patient journeys and supervised interprofessional learning.

The proposed target and incremental delivery plan are in [SIMRS_CAMPUS_MASTER_PLAN.md](SIMRS_CAMPUS_MASTER_PLAN.md).
