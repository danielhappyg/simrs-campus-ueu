# Dependency Hygiene Baseline — 20 August 2026

> **DEVELOPMENT EVIDENCE ONLY — NOT AN AUTOMATIC UPGRADE MANDATE, FACULTY-UAT APPROVAL, OR DEPLOYMENT AUTHORIZATION.**

- **Record ID:** `DEV-DEP-HYGIENE-20260820-01`
- **Owner and final decision authority:** Daniel Happy Putra, project manager/PIC
- **Scope:** direct dependency review after PR #25 merged to `main`
- **Environment:** local checkout on branch `docs/uat-dependency-hygiene-2026-08-20`
- **Objective link:** staged validation support, doc/runbook alignment, and dependency-hygiene visibility

## 1. Commands executed

```bash
composer audit
composer outdated --direct
npm audit --omit=dev
npm outdated
```

## 2. Security advisory result

| Command | Result | Sanitized detail |
| --- | --- | --- |
| `composer audit` | PASS | No security vulnerability advisories found |
| `npm audit --omit=dev` | PASS | 0 production vulnerabilities |

As of this record, no direct release-blocking package advisory was reported by the default Composer or npm production advisory checks.

## 3. Direct Composer packages with patch/minor updates available

| Package | Current | Latest recommended in series | Update class |
| --- | --- | --- | --- |
| `inertiajs/inertia-laravel` | `3.1.1` | `3.3.1` | minor |
| `laravel/fortify` | `1.37.2` | `1.38.0` | patch/minor |
| `laravel/framework` | `13.20.0` | `13.26.1` | patch/minor |
| `laravel/pao` | `1.1.2` | `1.1.4` | patch |
| `laravel/pint` | `1.29.3` | `1.30.5` | patch/minor |
| `laravel/sail` | `1.63.0` | `1.67.0` | patch/minor |
| `laravel/wayfinder` | `0.1.20` | `0.1.21` | patch |
| `mockery/mockery` | `1.6.12` | `1.6.15` | patch |
| `nunomaduro/collision` | `8.9.4` | `8.9.5` | patch |
| `phpunit/phpunit` | `12.5.31` | `12.5.33` | patch |

## 4. Selected npm package review signals

The npm tree reports a wider maintenance backlog. Representative direct-package findings:

| Package | Current | Wanted | Latest | Update class |
| --- | --- | --- | --- | --- |
| `@inertiajs/react` | `3.6.1` | `3.7.0` | `3.7.0` | minor |
| `@inertiajs/vite` | `3.6.1` | `3.7.0` | `3.7.0` | minor |
| `@laravel/passkeys` | `0.2.0` | `0.2.0` | `0.4.0` | major-like pre-1.0 jump |
| `@tailwindcss/vite` | `4.3.2` | `4.3.3` | `4.3.3` | patch |
| `tailwindcss` | `4.3.2` | `4.3.3` | `4.3.3` | patch |
| `vite` | `8.1.4` | `8.2.2` | `8.2.2` | minor |
| `vitest` | `4.1.10` | `4.1.11` | `4.1.11` | patch |
| `typescript-eslint` | `8.64.0` | `8.67.0` | `8.67.0` | patch/minor |
| `react` | `19.2.7` | `19.2.8` | `19.2.8` | patch |
| `react-dom` | `19.2.7` | `19.2.8` | `19.2.8` | patch |

The full `npm outdated` output also includes optional platform-specific packages shown as `MISSING` on the current macOS checkout because they target Linux or Windows runtime/build environments.

## 5. Additional observation

Both `npm audit --omit=dev` and `npm outdated` emitted:

```text
npm warn Unknown env config "devdir". This will stop working in the next major version of npm.
```

No matching `devdir` setting was found in the repository contents. Treat this as an environment-level configuration warning to trace before the next major npm upgrade, not as proof of an application dependency defect.

## 6. Initial classification

1. **No immediate security blocker found** in the default Composer or npm production advisory checks.
2. **Routine maintenance backlog exists** across both Composer and npm direct dependencies.
3. **A future reproducibility warning exists** for npm environment configuration (`devdir`) and should be traced before upgrading npm major versions or formalizing a stricter CI/package-manager policy.
4. **Major or pre-1.0 jumps** such as `@laravel/passkeys` should be reviewed separately from patch/minor maintenance updates.

## 7. Suggested next work

1. Open a narrow maintenance branch for low-risk patch/minor updates only after reviewing changelogs for Laravel, Inertia, Vite, Tailwind, Vitest, and related tooling.
2. Keep any `@laravel/passkeys` upgrade out of the first maintenance batch unless its changelog confirms safe adoption for the current authentication flow.
3. Trace the external `npm` `devdir` warning source before npm 12+ becomes a project requirement.
4. Re-run this baseline after any dependency-update pull request and record a new dated file rather than mutating this evidence.

## Related records

- [Dependency Update Candidates — 20 August 2026](DEPENDENCY_UPDATE_CANDIDATES_2026-08-20.md)
- [Outpatient Checkpoint 2 UAT Facilitator Guide](OUTPATIENT_CHECKPOINT_2_UAT_GUIDE.md)
- [Outpatient Checkpoint 2 Entry Gate Validation — 20 August 2026](OUTPATIENT_CHECKPOINT_2_ENTRY_GATE_VALIDATION_2026-08-20.md)
