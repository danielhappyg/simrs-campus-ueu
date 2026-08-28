# T1 Local Trusted-Edge and Shared-Maintenance Validation — 28 August 2026

## Status

**LOCAL SOURCE/INTERFACE PASS / SQLITE AND POSTGRESQL 17.10 INDEPENDENT-PROCESS PASS / HOSTED PROOF PENDING / NOT PUSHED / NOT DEPLOYED**

This evidence closes the source-level forwarding-header weakness and proves that Laravel's database maintenance marker crosses independently booted PHP processes on disposable SQLite and exact PostgreSQL 17.10. The PostgreSQL rehearsal also proves private-schema framework-table resolution, an atomic shared database-cache counter, mutual exclusion and release for a database cache lock, encrypted database-session continuity, and the reviewed prefix/key/schema divergence behavior. It does not prove Vercel edge sanitization, Supabase/PgBouncer behavior, hosted multi-instance cache atomicity, direct-origin exclusion, or action-time maintenance behavior.

No hosted environment, secret, account, deployment, or database was accessed. The protected paths `deliverables/`, `docs/legacy-visual-field-capture/`, `docs/operations/UAT_20260820_002_003_DRAFT_ORDER_REMEDIATION.md`, and `lang/` were excluded and untouched.

## Fixed validation rubric

The following criteria were fixed before candidate changes were validated:

| Criterion | Local result | Boundary |
| --- | --- | --- |
| A direct caller cannot choose the host, URL prefix, or client identity used by authentication controls | PASS | Direct `public/index.php` path trusts no forwarding headers; exact assigned-host patterns reject an unassigned host |
| The legitimate deployment edge retains HTTPS URLs and secure-cookie behavior | PASS at interface level | Vercel trusts only overwritten client IP plus protocol; Render trusts protocol only; hosted observation remains pending |
| Maintenance state crosses independently booted application processes through the database store | PASS on disposable SQLite | `down`, two fresh probes, and `up` ran in separate PHP processes against one database cache table |
| Framework cache, lock and session state resolves in private `laravel` and crosses fresh PHP processes | PASS on disposable PostgreSQL 17.10 | Harness-owned Unix-socket cluster; no TCP; private framework tables only; independent database backends observed |
| Shared counter, lock and encrypted session behavior matches the reviewed binding contract | PASS on disposable PostgreSQL 17.10 | Counter values `1,2`; contender rejected while holder slept; lock row released; same-key session readable; divergent key unreadable |
| Maintenance keeps `/up` healthy while application reads/writes return `503` without side effects | PASS in the Laravel feature boundary | `/login=503`, registration POST `=503`, `/up=200`, audit/session row counts unchanged |
| Configuration or store failure cannot silently open traffic, and local evidence is not mislabeled as hosted proof | PARTIAL | Invalid driver and foreign-schema worker fail closed; unavailable hosted store and hosted multi-instance behavior remain pending |

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

### Disposable PostgreSQL 17.10 shared-state rehearsal

The PostgreSQL harness requires a separate explicit confirmation and refuses inherited database, PostgreSQL, Redis, Memcached, Supabase or executable overrides. It creates a mode-`0700` temporary root, initializes an exact PostgreSQL 17.10 cluster with local Unix-socket trust, rejects every host-authentication rule, disables TCP listening, creates one generated database and private `laravel` schema, runs the ordinary migrations, and redirects Laravel storage/cache paths into the same disposable root.

Fresh PHP processes then proved:

- `laravel.cache`, `laravel.cache_locks`, and `laravel.sessions` resolve through the active private schema and no same-named framework table exists in `public`;
- one process activates maintenance, another sees it active, a divergent `CACHE_PREFIX` process sees it inactive, and a fresh process sees it inactive after `up`;
- two concurrent increments use distinct PostgreSQL backends and produce the atomic sequence `1,2`, retained as one prefixed row;
- a contender is rejected while the holder remains in PostgreSQL `PgSleep`, exactly one live lock row is visible during the hold, and required owner release removes it;
- a session written through Laravel `EncryptedStore` is readable from a fresh same-key process, while a divergent key cannot read the marker and the database payload contains no plaintext marker; and
- a genuinely foreign schema binding emits the reviewed closed `BLOCKED` result. Synthetic-mode correction from `public` back to `laravel` is an intentional application safeguard and is therefore not used as the negative schema case.

Ordinary database-cache values, maintenance markers, and locks use database/schema/table plus `CACHE_PREFIX` namespaces; they are not encrypted by `APP_KEY`. A prefix is not hard isolation against Laravel's full cache-table `flush()`/`cache:clear`, so those operations remain controlled maintenance actions. Identical `APP_KEY` remains required for encrypted session continuity. The proof keeps those two contracts separate.

The harness removed the generated database and owned PostgreSQL cluster before writing aggregate evidence. The retained ignored record is mode `0600`, contains no database name, run token, process identifier, session/cache key, credential or raw command output, and explicitly sets both hosted and Supabase claims to `false`.

```text
Shared-state harness contract: 14 tests, 291 assertions, PASS
PostgreSQL version: 17.10 exact
Laravel framework: v13.20.0, dist reference b9d1bccad5fbc32578dca22566bb11e7c0e545d7
Installed Laravel runtime matches composer.lock: PASS
Independent-process rehearsal: PASS
Elapsed rehearsal time: 5,062 ms
Evidence: storage/app/shared-state-rehearsals/20260828T112614Z-c86f6fe8ad92.json
Evidence SHA-256: 93d5995eb8abfba5dfb7836251adb51a4e16ed42275952995e7794c8041b2f35
Source-contract SHA-256: 1b7deac6ab3086d3a5f3a87f7d62eb37eeac571784d6d49967d906d9d22b6e95
Migration-file-set SHA-256: 38e557db55eb5a39e04564a6a05a4409986e5a0a6af390dfb83ca38425701ef8
Pre/post source and dependency contract stable: PASS
Cleanup before evidence: PASS
```

The retained run was made from local base `fa7242e47f42c9d664ef2dfa5114aac84906c3d2` with the new harness/worker/contract bytes uncommitted. Matching pre/post hashes bind the application, migration, `composer.lock`, installed dependency inventory, and critical Laravel cache/maintenance/session/PostgreSQL connector bytes used during execution; the installed Laravel version/reference is also required to equal the lockfile identity. The final clean local carrier is recorded only after all post-run checks and review complete.

GitHub Documentation Checks validate the harness's safety and evidence contract without executing a second owned PostgreSQL cluster on every push. The exact PostgreSQL 17.10 behavioral proof is the retained local rehearsal above and must be rerun when its bound source/dependency contract changes. Hosted Supabase behavior remains a separate action-time gate. This boundary avoids representing source-shape CI as a live database proof and avoids unnecessary recurring GitHub Actions usage.

Additional candidate checks:

```text
Pint (affected files): PASS
PHPStan (affected files, 512 MB): PASS, 0 errors
Full composer ci:check: PASS
Frontend: 12 files, 45 tests passed
PHPUnit: 553 tests, 549 passed, 4 skipped, 6,907 assertions
```

## Proof gaps and action-time acceptance

This local batch does not close the remaining release gate. Before G3 acceptance and traffic reopening, the exact candidate must still prove all of the following:

1. Vercel receives a harmless canary request and demonstrates that hostile forwarded host/port/prefix values do not affect the application, client IP is platform-derived, HTTPS remains effective, and no direct origin bypass exists.
2. The exact hosted candidate proves Supabase/PgBouncer private-schema resolution for the unqualified framework `cache`, `cache_locks`, and `sessions` tables across fresh application invocations. The corresponding owned PostgreSQL 17.10 local proof is complete.
3. Two independently observed hosted application invocations share one maintenance marker, rate-limit counter and cache lock with identical `APP_NAME`, `CACHE_PREFIX`, database and schema bindings; identical `APP_KEY` is additionally required for encrypted session continuity.
4. During the controlled maintenance window, `/up=200`, `/login=503`, a write path `=503`, no application writer bypasses the marker, and the old-writer drain interval completes before Backup B or migration.
5. The unavailable hosted-store rehearsal produces the reviewed fail-closed or stop-the-cutover outcome. Local prefix, encrypted-session key, and foreign-schema divergence are complete; no ambiguity authorizes `up` or migration.

## Safety and release boundary

All validation used `APP_MODE=SIMULATION` and synthetic-only configuration. No real patient data or live BPJS/VClaim/SATUSEHAT/payment/messaging integration was used. This record does not authorize push, deployment, environment edits, maintenance activation, migration, account activation, credential rotation, or traffic reopening.
