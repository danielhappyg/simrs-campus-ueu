# Dependency Update Candidates — 20 August 2026

> **DECISION SUPPORT ONLY — NOT AN AUTO-APPROVED UPGRADE PLAN OR RELEASE AUTHORIZATION.**

- **Record ID:** `DEV-DEP-CANDIDATES-20260820-01`
- **Decision authority:** Daniel Happy Putra
- **Inputs:** `composer outdated --direct`, `npm outdated`, current README/runbook quality-gate policy
- **Purpose:** separate low-risk maintenance candidates from updates that need their own review branch or broader regression scope

## 1. Recommended first maintenance batch

These are the most plausible low-risk updates for a dedicated maintenance PR after changelog review:

### Composer

- `laravel/framework` `13.20.0 -> 13.26.1`
- `laravel/fortify` `1.37.2 -> 1.38.0`
- `laravel/wayfinder` `0.1.20 -> 0.1.21`
- `laravel/pao` `1.1.2 -> 1.1.4`
- `laravel/pint` `1.29.3 -> 1.30.5`
- `mockery/mockery` `1.6.12 -> 1.6.15`
- `nunomaduro/collision` `8.9.4 -> 8.9.5`
- `phpunit/phpunit` `12.5.31 -> 12.5.33`

### npm

- `@inertiajs/react` `3.6.1 -> 3.7.0`
- `@inertiajs/vite` `3.6.1 -> 3.7.0`
- `@tailwindcss/vite` `4.3.2 -> 4.3.3`
- `tailwindcss` `4.3.2 -> 4.3.3`
- `vite` `8.1.4 -> 8.2.2`
- `vitest` `4.1.10 -> 4.1.11`
- `@vitest/coverage-v8` `4.1.10 -> 4.1.11`
- `react` `19.2.7 -> 19.2.8`
- `react-dom` `19.2.7 -> 19.2.8`
- `@types/react` `19.2.17 -> 19.2.18`
- `@types/react-dom` `19.2.3 -> 19.2.4`
- `@testing-library/user-event` `14.6.1 -> 14.6.5`
- `prettier` `3.9.5 -> 3.9.6`
- `typescript-eslint` `8.64.0 -> 8.67.0`
- `sonner` `2.0.7 -> 2.0.8`

## 2. Candidates that should stay out of the first batch

These updates are more likely to need dedicated review, additional browser testing, or release-note reading beyond a routine patch sweep:

- `@laravel/passkeys` `0.2.0 -> 0.4.0`
- `@vitejs/plugin-react` `5.2.0 -> 6.1.0`
- `@testing-library/jest-dom` `6.9.1 -> 7.0.1`
- `lucide-react` `0.475.0 -> 1.33.0`
- `concurrently` `9.2.4 -> 10.0.5`
- `globals` `15.15.0 -> 17.11.0`
- `typescript` `5.9.3 -> 7.0.2`
- `eslint` `9.39.5 -> 10.8.1`
- `@eslint/js` `9.39.5 -> 10.0.1`
- `jsdom` `29.1.1 -> 30.0.1`
- `prettier-plugin-tailwindcss` `0.6.14 -> 0.8.1`

Reasons to defer from the first batch include:

- major-version jumps;
- pre-1.0 feature-surface changes;
- tooling changes that can cascade into lint/build/test configuration churn; and
- packages that are deeply tied to authentication, rendering, or browser-test behavior.

## 3. Cross-platform optional packages

`npm outdated` reported several Linux- and Windows-specific optional packages as `MISSING` on the current macOS checkout. Treat these as expected platform variance unless the CI or hosted build starts failing:

- `@rollup/rollup-linux-x64-gnu`
- `@rollup/rollup-win32-x64-msvc`
- `@tailwindcss/oxide-linux-x64-gnu`
- `@tailwindcss/oxide-win32-x64-msvc`
- `lightningcss-linux-x64-gnu`
- `lightningcss-win32-x64-msvc`

## 4. Suggested branch strategy

1. Keep this current branch docs-only.
2. If Daniel approves maintenance work before the next rehearsal, open one separate branch for the first low-risk batch only.
3. Re-run:

```bash
composer audit
composer outdated --direct
npm audit --omit=dev
npm outdated
php artisan test
npm run test:unit
npm run build
```

4. Leave deferred packages for later dedicated review branches if they remain desirable after UAT.

## 5. Decision prompt for Daniel

The project can reasonably choose one of three paths:

1. **Docs-only now, no package upgrades before the next rehearsal.**
2. **One low-risk maintenance PR before the next rehearsal.**
3. **Defer all dependency upgrades until after Checkpoint 2 faculty UAT.**

This record supports that decision but does not make it automatically.
