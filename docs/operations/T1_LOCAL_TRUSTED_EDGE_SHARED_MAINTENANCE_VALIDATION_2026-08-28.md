# T1 Local Trusted-Edge and Shared-Maintenance Validation — 28 August 2026

## Status

**LOCAL SOURCE/INTERFACE PASS / SQLITE INDEPENDENT-PROCESS PASS / POSTGRESQL AND HOSTED PROOF PENDING / NOT PUSHED / NOT DEPLOYED**

This evidence closes the source-level forwarding-header weakness and proves that Laravel's database maintenance marker crosses independently booted PHP processes. It does not prove Vercel edge sanitization, Supabase PostgreSQL `search_path`, hosted database-cache atomicity, direct-origin exclusion, or action-time maintenance behavior.

No hosted environment, secret, account, deployment, or database was accessed. The protected paths `deliverables/`, `docs/legacy-visual-field-capture/`, `docs/operations/UAT_20260820_002_003_DRAFT_ORDER_REMEDIATION.md`, and `lang/` were excluded and untouched.

## Fixed validation rubric

The following criteria were fixed before candidate changes were validated:

| Criterion | Local result | Boundary |
| --- | --- | --- |
| A direct caller cannot choose the host, URL prefix, or client identity used by authentication controls | PASS | Direct `public/index.php` path trusts no forwarding headers; exact assigned-host patterns reject an unassigned host |
| The legitimate deployment edge retains HTTPS URLs and secure-cookie behavior | PASS at interface level | Vercel trusts only overwritten client IP plus protocol; Render trusts protocol only; hosted observation remains pending |
| Maintenance state crosses independently booted application processes through the database store | PASS on disposable SQLite | `down`, two fresh probes, and `up` ran in separate PHP processes against one database cache table |
| Maintenance keeps `/up` healthy while application reads/writes return `503` without side effects | PASS in the Laravel feature boundary | `/login=503`, registration POST `=503`, `/up=200`, audit/session row counts unchanged |
| Configuration or store failure cannot silently open traffic, and local evidence is not mislabeled as hosted proof | PARTIAL | Invalid driver fails closed; PostgreSQL store/search-path and hosted multi-instance failure behavior remain pending |

## Threat trace and precondition analysis

At the previous local HEAD, `bootstrap/app.php` trusted `REMOTE_ADDR` with Laravel's complete default forwarding-header mask. A direct caller modeled as the immediate peer could therefore supply:

- `X-Forwarded-For`, consumed by login, passkey and password-reset rate limits and by audit IP commitments;
- `X-Forwarded-Host`, consumed by canonical-host teaching-role checks and request-root URL generation;
- `X-Forwarded-Proto`, port and prefix, consumed by absolute URL generation.

A local framework reproduction observed a hostile forwarded host, prefix and client IP becoming the effective request values under that broad mask. Hosted impact still required a provider path that preserved those values or exposed a direct origin. Current Vercel documentation states that its edge overwrites `X-Forwarded-For` to prevent spoofing and supplies the forwarded protocol. Current Render documentation states that TLS terminates at its edge; the source policy deliberately does not trust Render's forwarded client-IP chain.

Security-sensitive sinks reviewed in this repository were:

- Fortify login and passkey throttles;
- password-reset email/IP and IP request budgets;
- HMAC audit IP attribution;
- canonical-host teaching-role login, session and mutation fences;
- request-root URL generation used by authentication notifications.

## Candidate controls

1. `api/index.php` defines `SIMRS_VERCEL_EDGE_ENTRYPOINT` only on the route target selected by `vercel.json`.
2. Vercel trusts only `HEADER_X_FORWARDED_FOR | HEADER_X_FORWARDED_PROTO` from the immediate edge. Forwarded host, port, prefix and RFC `Forwarded` are ignored.
3. Render is identified by its platform `RENDER=true` runtime marker and trusts only `HEADER_X_FORWARDED_PROTO`. Its forwarded client identity is intentionally not accepted.
4. Direct application entry uses Laravel's empty trusted-proxy policy.
5. `DeploymentHostBoundary` builds exact, non-subdomain host patterns from `APP_URL` and the current Vercel/Render assigned-host variables. An empty/invalid candidate set produces a never-match pattern.
6. Laravel's maintenance driver remains `cache`, with the `database` cache store in the Vercel entrypoint. No bypass secret is supported.

Provider references:

- Vercel request-header contract: <https://vercel.com/docs/headers/request-headers>
- Vercel system environment variables: <https://vercel.com/docs/environment-variables/system-environment-variables>
- Render web-service TLS boundary: <https://render.com/docs/web-services>

## Dynamic validation

Focused validation after remediation:

```text
TrustedEdgeForwardingBoundaryTest
TrustedHostStagingProcessTest
VercelSharedMaintenanceModeTest
SharedMaintenanceIndependentProcessTest

10 tests passed
44 assertions
```

The staging bootstrap test starts two fresh PHP processes with `APP_ENV=staging`: the assigned canonical host reaches `/up=200`, while an unassigned host is rejected with `400`. This exercises Laravel's real host middleware, which Laravel deliberately bypasses during ordinary in-process unit tests.

The independent-process maintenance test creates a private random directory under the operating-system temporary directory, uses an isolated SQLite database and runtime-cache paths, runs migrations, activates maintenance in one PHP process, observes `active=true` in a second process, removes maintenance in a third process, observes `active=false` in a fourth process, and then deletes only the validated owned temporary directory.

Additional candidate checks:

```text
Pint (affected files): PASS
PHPStan (affected files, 512 MB): PASS, 0 errors
Full composer ci:check: PASS
Frontend: 12 files, 45 tests passed
PHPUnit: 553 tests, 549 passed, 4 skipped, 6,884 assertions
```

## Proof gaps and action-time acceptance

This local batch does not close the remaining release gate. Before G3 acceptance and traffic reopening, the exact candidate must still prove all of the following:

1. Vercel receives a harmless canary request and demonstrates that hostile forwarded host/port/prefix values do not affect the application, client IP is platform-derived, HTTPS remains effective, and no direct origin bypass exists.
2. Production-like PostgreSQL 17 with private schema `laravel` proves the unqualified framework `cache`, `cache_locks`, and `sessions` tables resolve through the intended `search_path` in at least two fresh application processes.
3. Two independently observed hosted application invocations share one maintenance marker, rate-limit counter and cache lock with identical `APP_KEY`, `APP_NAME`, `CACHE_PREFIX`, database and schema bindings.
4. During the controlled maintenance window, `/up=200`, `/login=503`, a write path `=503`, no application writer bypasses the marker, and the old-writer drain interval completes before Backup B or migration.
5. Divergent prefix/key/schema and unavailable-store rehearsals produce the reviewed fail-closed or stop-the-cutover outcome; no ambiguity authorizes `up` or migration.

## Safety and release boundary

All validation used `APP_MODE=SIMULATION` and synthetic-only configuration. No real patient data or live BPJS/VClaim/SATUSEHAT/payment/messaging integration was used. This record does not authorize push, deployment, environment edits, maintenance activation, migration, account activation, credential rotation, or traffic reopening.
