# UEU Clinical Design System

- **Version:** 1.0 reference specification
- **Status:** Ready to encode in the application foundation
- **Design name:** UEU Clinical
- **Primary use:** desktop/tablet hospital-workflow teaching application
- **Accessibility target:** WCAG 2.2 AA for the critical path
- **Brand posture:** derived from the supplied UEU logo as visual reference; formal brand approval remains VAL-U01

## 1. Design intent

UEU Clinical should feel calm, trustworthy, instructional, and operationally serious. It is not a marketing site and not a collection of colorful module cards. The interface should help a learner answer five questions immediately:

1. Which simulation session, patient, and encounter am I in?
2. What is my current role and assignment?
3. What work is ready, blocked, or awaiting review?
4. Which information is source truth, draft, approved, corrected, or simulated?
5. What action safely moves the case to the next profession?

The visual language uses the supplied logo's blue as the structural color and orange as a restrained accent. Clinical and workflow statuses use semantic colors independently from the brand palette and always include text/icon cues.

## 2. Principles

| Principle | Product behavior |
|---|---|
| Context before content | Patient, encounter, simulation mode, location, assignment, and allergies remain visible on patient-work screens. |
| Work before modules | `Pekerjaan Saya` presents actionable queues; navigation does not recreate the legacy launcher. |
| Calm density | Dense tables/forms use spacing, grouping, sticky anchors, and typography instead of excessive cards or decorative color. |
| Status is explicit | Draft, submitted, approved, corrected, blocked, and simulation states use text labels, icons, and consistent placement. |
| Provenance is visible | Authorship, role, clinical time, recorded time, version, and review state are inspectable without opening an audit database. |
| Teaching is first-class | Learner role, supervisor, learning objective, feedback, and debrief are integrated with the clinical workflow. |
| Safety without false intelligence | The UI identifies missing/invalid data and routes human review; it never presents an autonomous diagnosis or treatment decision. |
| Accessibility is structural | Keyboard navigation, focus, contrast, names, errors, and non-color cues are part of component contracts. |

## 3. Foundations

### 3.1 Color tokens

The approximate logo reference colors are blue `#0070B8` and orange `#F05828`. Application colors are adjusted into a functional scale. White text on primary blue `#00639F` has an approximate contrast ratio of 6.39:1. Logo orange is not used as a white-text button background because its approximate contrast is only 3.42:1.

#### Brand

| Token | Value | Use |
|---|---|---|
| `brand-blue-50` | `#F0F8FC` | Selected/hovered light surface |
| `brand-blue-100` | `#DCEFF8` | Informational tint |
| `brand-blue-200` | `#B9DFF1` | Decorative/divider accent |
| `brand-blue-300` | `#86C7E5` | Charts/non-text accent |
| `brand-blue-400` | `#48A8D2` | Icon/accent on dark surface |
| `brand-blue-500` | `#1287BF` | Non-text accent |
| `brand-blue-600` | `#0070B8` | Focus/brand reference; white text permitted at normal-text AA |
| `brand-blue-700` | `#00639F` | Primary action and active navigation |
| `brand-blue-800` | `#005A91` | Primary hover/pressed |
| `brand-blue-900` | `#0A496F` | Dark brand text/surface |
| `brand-orange-50` | `#FFF7ED` | Simulation/accent surface |
| `brand-orange-200` | `#FED7AA` | Accent border |
| `brand-orange-500` | `#F05828` | Logo accent, indicator, illustration; not normal white text |
| `brand-orange-700` | `#B73B12` | Accessible accent text on white |
| `brand-orange-900` | `#7C2D12` | Simulation banner text |

#### Neutral

| Token | Value | Use |
|---|---|---|
| `neutral-0` | `#FFFFFF` | Primary surface |
| `neutral-25` | `#FCFDFE` | Canvas lift |
| `neutral-50` | `#F8FAFC` | App canvas/subtle section |
| `neutral-100` | `#F1F5F9` | Muted surface/hover |
| `neutral-200` | `#E2E8F0` | Default border/divider |
| `neutral-300` | `#CBD5E1` | Strong border/disabled control |
| `neutral-400` | `#94A3B8` | Placeholder/non-essential icon |
| `neutral-500` | `#64748B` | Secondary text; 4.76:1 on white |
| `neutral-600` | `#475569` | Muted body text |
| `neutral-700` | `#334155` | Body text |
| `neutral-800` | `#1E293B` | Strong text |
| `neutral-900` | `#0F172A` | Heading/navigation text |

#### Semantic

| State | Background | Border/icon | Text | Required cue |
|---|---|---|---|---|
| Information | `#EFF6FF` | `#60A5FA` | `#1E40AF` | info icon + label |
| Success/complete | `#F0FDF4` | `#4ADE80` | `#166534` | check icon + label |
| Warning/review | `#FFFBEB` | `#FBBF24` | `#92400E` | warning icon + label |
| Danger/blocking | `#FEF2F2` | `#F87171` | `#991B1B` | alert icon + label |
| Neutral/pending | `#F8FAFC` | `#CBD5E1` | `#475569` | clock/dot + label |
| Simulation | `#FFF7ED` | `#F05828` | `#7C2D12` | flask/shield icon + `SIMULASI` |

Never use semantic color as the only indicator. A red outline without text is not a complete error state.

Reference contrast calculations using the WCAG relative-luminance formula:

| Pair | Approximate ratio |
|---|---:|
| primary `#00639F` / white | 6.39:1 |
| information text / information background | 8.01:1 |
| success text / success background | 6.81:1 |
| warning text / warning background | 6.84:1 |
| danger text / danger background | 7.60:1 |
| neutral body text / neutral surface | 7.24:1 |
| simulation text / simulation background | 8.83:1 |
| accessible orange text `#B73B12` / white | 5.76:1 |

These token-level checks do not replace rendered component testing, including hover, disabled, focus, overlay, and browser high-contrast states.

### 3.2 CSS custom-property contract

```css
:root {
  --color-canvas: #f8fafc;
  --color-surface: #ffffff;
  --color-surface-subtle: #f1f5f9;
  --color-text: #0f172a;
  --color-text-muted: #475569;
  --color-border: #e2e8f0;
  --color-border-strong: #cbd5e1;

  --color-primary: #00639f;
  --color-primary-hover: #005a91;
  --color-primary-soft: #f0f8fc;
  --color-accent: #f05828;
  --color-accent-text: #b73b12;
  --color-focus: #0070b8;

  --color-success-bg: #f0fdf4;
  --color-success-text: #166534;
  --color-warning-bg: #fffbeb;
  --color-warning-text: #92400e;
  --color-danger-bg: #fef2f2;
  --color-danger-text: #991b1b;
  --color-simulation-bg: #fff7ed;
  --color-simulation-text: #7c2d12;
}
```

Component code consumes semantic custom properties; raw hex values are limited to the token definition layer.

### 3.3 Typography

Use a local/system stack to avoid blocking clinical screens on a font CDN:

```css
font-family: Inter, ui-sans-serif, -apple-system, BlinkMacSystemFont,
  "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
font-variant-numeric: tabular-nums;
```

`Inter` is optional when locally bundled/licensed; the system stack must remain visually valid.

| Token | Size/line | Weight | Use |
|---|---:|---:|---|
| `display-sm` | 30/38 px | 700 | Rare section landing title |
| `heading-xl` | 24/32 px | 700 | Page title |
| `heading-lg` | 20/28 px | 650–700 | Major clinical section |
| `heading-md` | 18/26 px | 600 | Card/panel title |
| `body-lg` | 16/24 px | 400 | Patient-facing or important narrative |
| `body-md` | 14/21 px | 400 | Default application/table body |
| `label-md` | 14/20 px | 600 | Field labels/actions |
| `body-sm` | 12/18 px | 400 | Metadata/provenance; not essential instructions |
| `label-sm` | 12/16 px | 600 | Status badge/table header |

No essential clinical content is smaller than 12 px. Long narrative content is constrained to approximately 75 characters per line when space allows.

### 3.4 Spacing and sizing

Use a 4 px base scale:

| Token | Value | Typical use |
|---|---:|---|
| `space-0` | 0 | Reset |
| `space-1` | 4 px | Icon/label micro-gap |
| `space-2` | 8 px | Inline group, compact cell |
| `space-3` | 12 px | Control internal spacing |
| `space-4` | 16 px | Default component gap |
| `space-5` | 20 px | Compact section inset |
| `space-6` | 24 px | Standard panel inset |
| `space-8` | 32 px | Page-section gap |
| `space-10` | 40 px | Major separation |
| `space-12` | 48 px | Large layout gap |

Control heights: `32 px` compact non-critical table action, `40 px` default desktop control, and `44 px` touch/primary clinical action. Do not reduce click targets below `24 x 24 px`; primary and frequently used controls target at least `40 x 40 px` with sufficient spacing.

### 3.5 Radius, border, shadow, and elevation

| Token | Value | Use |
|---|---|---|
| `radius-sm` | 4 px | Badge, compact input |
| `radius-md` | 8 px | Input, button, small panel |
| `radius-lg` | 12 px | Main panel/dialog |
| `radius-full` | 9999 px | Status pills/avatar only |
| `border-default` | 1 px solid `neutral-200` | Standard structure |
| `border-strong` | 1 px solid `neutral-300` | Active/important boundary |
| `shadow-1` | `0 1px 2px rgb(15 23 42 / 0.06)` | Sticky/table header lift |
| `shadow-2` | `0 8px 24px rgb(15 23 42 / 0.10)` | Popover/dialog |

Avoid heavy card shadows. Structure comes from surface, border, and spacing.

### 3.6 Motion

| Token | Value | Use |
|---|---:|---|
| `motion-fast` | 100 ms | Hover/focus color |
| `motion-base` | 160 ms | Drawer/popover transition |
| `motion-slow` | 240 ms | Rare page-level transition |
| `ease-standard` | `cubic-bezier(.2, 0, 0, 1)` | Standard easing |

Respect `prefers-reduced-motion: reduce`. Do not animate clinical status changes in a way that delays perception. Loading indicators include text and do not rely on infinite decorative motion alone.

### 3.7 Icons

- one consistent outlined icon family, initially Lucide or an equivalent tree-shakeable set;
- 16 px in compact controls, 20 px default, 24 px for section cues;
- icon-only actions require accessible names and tooltips where meaning is not universal;
- do not use emoji as workflow/status icons;
- distinguish warning, error, lock, draft, review, approval, and simulation with both icon and label.

## 4. Layout system

### 4.1 Desktop shell

| Region | Default size/behavior |
|---|---|
| Simulation banner | 32 px, full width, always visible in patient/session mode |
| Global header | 56 px; product identity, current session, global search/command, help, account |
| Navigation rail | 248 px expanded; 72 px collapsed; role-aware destinations |
| Main canvas | Fluid with 24 px gutter; max readable narrative width within fluid clinical workspace |
| Context bar | 88–112 px sticky below header on patient-work screens |
| Right review rail | 360–420 px optional drawer for tasks, feedback, provenance, and review |

### 4.2 Responsive behavior

| Width | Behavior |
|---|---|
| ≥1440 px | Expanded navigation, main workspace, optional persistent review rail. |
| 1024–1439 px | Collapsible navigation; review rail becomes overlay drawer. |
| 768–1023 px | Compact navigation; patient banner wraps into two rows; dense tables gain column controls/horizontal containment. |
| <768 px | Basic queue/read/review support; complex clinical authoring uses stacked sections. Mobile is not the pilot's primary workstation. |

Responsive design never hides identity, simulation mode, blocking alerts, or unsaved-state protection.

## 5. Core components

### 5.1 `SimulationBanner`

**Problem:** users must never mistake a teaching record or integration simulation for real clinical operation.

| Property | Type | Default | Description |
|---|---|---|---|
| `mode` | `simulation | sandbox` | required | Environment truth. |
| `sessionCode` | string | required | Active session reference. |
| `synthetic` | boolean | `true` | Must be true in reference MVP. |
| `compact` | boolean | false | Print/table layout variant without losing wording. |

Visual: orange left stripe/flask-shield icon, pale orange background, dark orange text: `SIMULASI — DATA SINTETIS • Tidak untuk pelayanan pasien nyata`. It is not dismissible.

Accessibility: landmark/visible text; no live-region repetition on every navigation. Mode change is announced once.

### 5.2 `PatientContextBar`

**Purpose:** prevent wrong-patient/wrong-encounter work and keep critical context visible.

Required zones:

- synthetic patient name, MRN, second identifier, age/administrative sex;
- encounter number, clinic, date/time, status;
- allergies/critical alerts with explicit assessed/unknown state;
- current learner role, supervisor, task/review state;
- explicit patient-switch action.

States: loading skeleton with no stale prior-patient content, complete, incomplete identity warning, escalation, closed/finalized, and access-limited.

The banner uses a white surface and strong bottom border; danger color is reserved for actual blocking alerts, not the whole banner.

### 5.3 `WorkQueue`

**Purpose:** answer “what should I do next?” without a module launcher.

| Variant | Use |
|---|---|
| `assigned` | Current learner tasks/patients. |
| `review` | Supervisor submissions and correction requests. |
| `handoff` | Interprofessional queue. |
| `record-quality` | RMIK completeness/coding work. |

Minimum columns: patient cue, encounter/location, task, source profession, priority as scenario data, waiting/received time, status, assignee, next action. Columns can be shown/hidden, but patient/encounter/task/status remain.

Keyboard: semantic table/grid chosen according to interactivity; sortable headers are buttons with announced direction; row action is reachable without making the entire row an ambiguous click target.

### 5.4 `StatusBadge`

Variants: `draft`, `submitted`, `changes-requested`, `approved-simulation`, `amended`, `blocked`, `waiting`, `complete`, `cancelled`, `simulation`.

Every badge includes text and optional icon. Badge color communicates category but never carries meaning alone. Status labels use centralized Indonesian terminology.

### 5.5 `Button`

| Variant | Use |
|---|---|
| `primary` | One main forward action in a region, blue 700 with white text. |
| `secondary` | Supporting action, white surface with strong border. |
| `tertiary` | Low-emphasis inline action. |
| `danger` | Destructive/cancellation action requiring clear consequence. |
| `link` | Navigation in prose/table. |

States: default, hover, active, focus-visible, disabled, and loading. Loading preserves width and exposes `aria-busy`; disabled action has nearby explanation when the reason is not obvious. There is no orange primary-button variant.

### 5.6 `Field`

Supports text, textarea, date/time, numeric quantity/unit, search/combobox, coded concept, radio, checkbox, segmented decision, and read-only source fact.

Anatomy: label, required/optional indicator, control, unit/suffix, description, validation/status message, and provenance when sourced. Placeholder never replaces the label.

Validation appears after meaningful interaction or submit, focuses a summary on failed submission, links summary items to fields, and preserves user values.

### 5.7 `ClinicalSection`

Combines section heading, completion state, source/review metadata, fields, and optional history. Variants:

- editable current draft;
- read-only prior profession source;
- submitted/locked;
- changes requested;
- approved simulation;
- amended/superseded comparison.

Do not put every field in an individual card. Use one section container with groups and clear subheadings.

### 5.8 `WorkflowRail`

Displays encounter stages: registration, intake, consultation, result, pharmacy, closure, RMIK, finalization. States are complete/current/waiting/blocked/not-applicable. It is a progress/navigation aid, not a permission bypass. Screen readers receive an ordered list with current step.

### 5.9 `ReviewPanel`

Shows exact version, author, source time, changed sections, checklist/findings, comments, and approve/request-change actions. Review actions name the result explicitly: `Setujui untuk simulasi` and `Minta perbaikan`.

Approval dialog summarizes the version/hash and warns if a newer version exists. The API performs the final stale-version check.

### 5.10 `RecordTimeline`

Chronological, filterable event history with clinical time and recorded time when they differ. Each event shows profession, actor, state/version, source resource, and review/amendment relationship. Color is secondary to icon/label. Filters never alter the underlying record.

### 5.11 `PrescriptionReviewPanel`

Three visible domains:

1. administratif;
2. farmasetik;
3. klinis.

Each domain shows source facts, learner-authored review outcome, comment, and missing-data warnings. Overall acceptance cannot be selected while a required domain is incomplete. The panel states that the application has not automatically cleared clinical appropriateness.

### 5.12 `DataTable`

Use for comparable records, not for form layout. Features: sticky header, density options (`comfortable`, `compact`), column control, sort, filter summary, pagination, empty/error/loading states, row selection only when a batch action exists, and explicit mobile fallback.

Default row height is 44 px. Numeric values use tabular figures and align consistently. Action menus have visible labels/tooltips and remain keyboard reachable.

### 5.13 Feedback components

| Component | Use | Rule |
|---|---|---|
| Inline message | Field/section issue | Closest to source; persistent until resolved. |
| Alert banner | Page-level blocking/warning context | Has heading, icon, action, and announced semantics. |
| Toast | Confirmation of non-critical completed action | Never the only record of an error; 6–8 s or user-dismissible. |
| Dialog | Destructive/irreversible or focused confirmation | Focus trapped; title/description; escape behavior explicit. |
| Drawer | Review/history/reference without losing workspace | Returns focus to trigger; deep-linkable state where useful. |
| Empty state | No data/task | Explains why and next authorized action; no decorative illustration required. |

## 6. Navigation pattern

Primary destinations for the MVP:

1. `Pekerjaan Saya`
2. `Pasien`
3. `Pelayanan`
4. `Pesanan & Hasil`
5. `Obat`
6. `Rekam Kesehatan`
7. `Pusat Pembelajaran`
8. `Administrasi` when authorized

Only relevant destinations render, but routes remain server-protected. Patient-context subnavigation is task-oriented: ringkasan, asesmen, pesanan/hasil, obat, penutupan, kelengkapan/koding, and linimasa.

## 7. Form and save behavior

- use clear section-level save state: `Belum disimpan`, `Menyimpan…`, `Tersimpan 10.42`, `Gagal menyimpan`;
- autosave may preserve current drafts, but submission is always explicit;
- never autosubmit or autoapprove clinical/teaching content;
- a validation error returns focus to the error summary and retains all entered values;
- leaving with unsaved changes prompts; switching patient cannot carry a draft across contexts;
- stale-version conflict shows both versions and requires a conscious resolution route;
- primary action stays at page end and may also appear in a sticky footer for long forms, with one accessible name/action source;
- read-only source content is visually distinct from disabled fields; disabled controls are not used to display historical data.

## 8. Indonesian status and action vocabulary

| Internal concept | Preferred UI label |
|---|---|
| My Work | Pekerjaan Saya |
| Simulation | Simulasi |
| Synthetic data | Data sintetis |
| Draft | Draf |
| Submitted | Diajukan untuk ditinjau |
| Changes requested | Perlu perbaikan |
| Approved simulation | Disetujui untuk simulasi |
| Amended | Dikoreksi / Amendemen |
| Nursing intake and safety screen | Asesmen Awal dan Skrining Keselamatan |
| Medical assessment | Asesmen Medis Rawat Jalan |
| Prescription review | Telaah Resep |
| Dispensed | Diserahkan |
| Completeness review | Telaah Kelengkapan |
| Coding review | Telaah Koding |
| Encounter closure | Penutupan Kunjungan |
| Longitudinal record | Linimasa Rekam |
| Supervisor review | Tinjauan Supervisor |

Clinical code displays remain those of the governed terminology dataset; translation is not substituted for the code-system source.

## 9. Accessibility contract

All production components must meet these minimums:

- semantic native elements before ARIA;
- exactly one application `main` landmark per rendered page;
- visible `:focus-visible` ring with at least 2 px outline and sufficient contrast;
- full critical-path keyboard operation with logical tab order;
- skip link to main content and stable heading hierarchy;
- form labels, descriptions, required state, errors, and units programmatically associated;
- page/section error summary linked to invalid controls;
- live regions limited to meaningful save/status updates;
- dialogs/drawers manage focus and return it to the trigger;
- contrast at least 4.5:1 for normal text, 3:1 for large text and meaningful UI boundaries;
- primary form controls, buttons, icon actions, and sidebar actions use a 44 CSS-pixel minimum target on narrow or coarse-pointer devices;
- no information encoded by color, position, or animation alone;
- zoom/reflow support at 200% for core tasks and robust browser text scaling;
- reduced-motion support;
- accessible table headers/captions and sortable-control announcements;
- charts, if introduced later, include a table/text equivalent.

Automated checks are necessary but not sufficient. Keyboard, screen-reader spot checks, zoom/reflow, and error-recovery review are required before the faculty pilot.

## 10. Component delivery order

1. tokens, reset, typography, focus, and layout primitives;
2. Button, Field, StatusBadge, Alert, Dialog/Drawer, and DataTable;
3. SimulationBanner, AppShell, navigation, and PatientContextBar;
4. WorkQueue, WorkflowRail, ClinicalSection, save/error patterns;
5. ReviewPanel, RecordTimeline, PrescriptionReviewPanel;
6. visual-regression, accessibility, and interaction tests for all critical states.

## 11. Do and do not

| Do | Do not |
|---|---|
| Use blue for structure and primary action. | Turn every module into a brightly colored tile. |
| Reserve orange for brand/simulation emphasis. | Use logo orange as a normal white-text button. |
| Show patient, encounter, role, and simulation context. | Rely on browser back or memory for context. |
| Show source status and provenance. | Make copied text look like verified source truth. |
| Group dense forms into meaningful sections. | Put every field in a separate card. |
| Use explicit Indonesian status wording. | Use icon/color alone or ambiguous `Selesai`. |
| Keep one dominant action per region. | Present multiple equal blue primary buttons. |
| Preserve drafts and errors. | Clear fields after a server validation failure. |
| Show honest simulator/sandbox states. | Animate a fake “connected” status. |

## 12. Design validation checklist

- [ ] Daniel accepts the overall visual direction and logo-derived palette.
- [ ] Critical screens render all required workflow states, not only the happy path.
- [ ] Primary button, body text, status text, and focus indicators meet contrast targets.
- [ ] Keyboard-only flow reaches every critical action.
- [ ] Patient/encounter context survives navigation and responsive layouts.
- [ ] Simulation labeling appears on screens and exports.
- [ ] Outpatient intake wording does not imply emergency acuity scoring.
- [ ] Clinical warnings make no diagnosis/treatment claim.
- [ ] Submitted/approved versions look immutable and correction paths remain visible.
- [ ] Dense clinical content remains readable at 200% zoom.

## Related documents

- [Information Architecture](INFORMATION_ARCHITECTURE.md)
- [Outpatient Wireframes](OUTPATIENT_WIREFRAMES.md)
- [Interaction Specifications](OUTPATIENT_INTERACTION_SPECIFICATIONS.md)
- [Outpatient Service Blueprint](../product/OUTPATIENT_SERVICE_BLUEPRINT.md)
- [Role and Permission Matrix](../product/OUTPATIENT_ROLE_MATRIX.md)
