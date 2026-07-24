# Outpatient Web Session Monitor Validation — 24 July 2026

- **Implementation commit:** `5397e63` (`Add facilitator web session monitor`)
- **Branch:** `agent/outpatient-domain-spine`
- **Candidate base:** `3965271`
- **Evidence type:** developer-operated clean-candidate verification
- **Scope:** main synthetic outpatient system only; E-Klaim excluded
- **Decision boundary:** does not replace participant observation, faculty acceptance, or Daniel's authorization

## Purpose

The command-line session monitor provided a bounded, identity-minimized source of session progress, but required terminal access for routine observation. This increment exposes the same read-only projection in the selected facilitator work queue so the laboratory operator can see the phase, operational counts, attention signals, and next program/role handoff without querying the database.

The browser receives the projection only when the authenticated user's active assignment for the exact selected session has `session.facilitate`. A non-facilitator receives `sessionMonitor: null`. The monitor does not expose patient names, record identifiers, account emails, passwords, internal database IDs, clinical text, scores, percentages, or acceptance decisions.

## Clean candidate

A fresh candidate was exported from commit `3965271` to an isolated temporary directory. Only the seven files in implementation commit `5397e63` were overlaid. Dependencies were reused read-only; an isolated SQLite database was migrated and seeded with the synthetic reference fixture. The retained source was cloned to disposable session `LAB-WEB-BROWSER-001`.

The clean candidate excludes the unrelated dirty worktree and all untracked E-Klaim files. No real patient data or external clinical service was used.

## Automated verification

The complete clean-candidate gate passed:

- PHP: **262 tests, 3,287 assertions**;
- React/Vitest: **28 files, 74 tests**;
- PHPStan: zero errors;
- Pint: passed;
- TypeScript: passed;
- ESLint: passed;
- Prettier: passed;
- production Vite build: passed;
- Composer locked audit: no security vulnerability advisories;
- npm audit at high severity: zero vulnerabilities.

Focused coverage proves:

1. the console and web surfaces use the same monitor service;
2. the facilitator receives the exact disposable-session projection;
3. a non-facilitator does not receive the session-wide projection;
4. the pristine clone reports `READY_TO_START`, ten assignments, four tasks, three open tasks, and one ready registration handoff;
5. corrupt clone structure fails closed;
6. the projection excludes fixture identity and credential values;
7. the accessible ready and blocked UI states render without automated axe violations; and
8. the operational counts are explicitly described as neither percentages, grades, nor pilot acceptance.

## Browser verification

The clean local candidate was exercised through the real sign-in and work-queue routes with the synthetic facilitator account:

1. sign-in completed and routed to `/work`;
2. `/work?session=LAB-WEB-BROWSER-001` selected the exact disposable session;
3. **Jalur serah terima laboratorium** displayed:
   - phase **Siap dimulai**;
   - ten active assignments;
   - four total tasks;
   - three open tasks;
   - one ready handoff; and
   - **Registrasi · RMIK · Petugas registrasi · P1**;
4. the desktop layout remained within a 1,440-pixel viewport;
5. the monitor remained within a 390-pixel viewport with no horizontal overflow;
6. headings, region labels, terms/definitions, session selection, and stop-state semantics remained exposed to assistive technology; and
7. the retained browser warning/error log was empty.

## Safety and interpretation

- The web monitor is read-only.
- The command-line monitor remains the independent setup and disputed-handoff check.
- A blocked projection instructs the facilitator to stop and use the documented setup path; it does not offer direct database repair.
- Counts describe attributable workflow state only. They are not a completion percentage, learner performance measure, grade, clinical decision, or pilot authorization.
- The monitor is for synthetic supervised teaching sessions only and is prohibited as evidence of suitability for real-patient care.

## Remaining gate

This increment removes terminal dependence for routine facilitator observation, but does not complete Checkpoint 2. A supervised small-group dry run still needs to record participant-observed workflow, wording, accessibility, and teaching-design findings; classify any stop-session or must-fix issue; and obtain Daniel's explicit decision.

Current recommendation: the outpatient MVP remains technically suitable for the next small supervised dry-run gate. Faculty-pilot authorization is **NOT DECIDED**.
