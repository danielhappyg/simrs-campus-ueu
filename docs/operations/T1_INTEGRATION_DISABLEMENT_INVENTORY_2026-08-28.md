# T1 integration disablement inventory — 2026-08-28

**Verdict:** `LOCAL TECHNICAL PASS / PRODUCTION-EFFECTIVE RECHECK PENDING`

**Boundary:** SIMRS Campus UEU synthetic teaching simulation only. This inventory does not authorize or claim a live clinical, payer, pharmacy, imaging, laboratory, payment or messaging integration.

## Reviewed release identity

- Application security commit: `e81bf19fb1cc9918d103743edd2e1f5b48893880`.
- Deployable release carrier: `c63c017a1e9dc2bd2922ce9fc9235b7cc90703db`.
- Exact Git-backed Preview: `dpl_4UH2VjQwwo2ccUxHoauhFv1Qp1vt`.
- Review surface: `app/`, `config/`, `routes/`, `composer.json`, `composer.lock`, `resources/js/pages/modules/placeholder.tsx`, `resources/js/components/app-header.tsx`, `resources/js/lib/simrs-modules.ts`, `tests/Feature/Simulation/SimulationEgressSafetyTest.php`, and non-secret Vercel environment-variable key/type/target metadata.

## Clinical and operational integration boundary

| Integration family | Client / endpoint / credential / actionable route | Current behavior | Verdict |
| --- | --- | --- | --- |
| BPJS / VClaim | None found | `BPJS` is a payer vocabulary value and a module label only | Disabled |
| SATUSEHAT | None found | No client, endpoint, credential key, webhook or route | Disabled |
| LIS | None found | Laboratory remains an internal synthetic teaching workflow | Disabled |
| PACS / imaging | None found | No client, endpoint, credential key, webhook or route | Disabled |
| Payment gateway | None found | No payment client, credential key, webhook or actionable route | Disabled |
| Klaim | None found | Authenticated honest placeholder through `/modul/{category}` | Soon / disabled |
| Apotek | None found | Authenticated honest placeholder through `/modul/{category}` | Soon / disabled |

The category registry exposes Indonesian navigation labels for `Klaim`, `BPJS`, and `Apotek`, but only Pendaftaran, Pemeriksaan, and RM have dedicated operational links. Every other registered category resolves to the placeholder controller. The outpatient print audit contract also requires `teaching_only=true` and `live_bpjs=false`.

## Non-clinical outbound-traffic containment

The review found two framework-level outbound paths that were not clinical integrations but still violated the no-unapproved-egress boundary:

1. Laravel's production password rule could use the external compromised-password verifier.
2. The default mail transport could be selected from network-capable environment configuration, including legacy `mail.driver` precedence.

Commit `e81bf19…3880` retains the local 12-character, mixed-case, letter, number and symbol rules while omitting the external compromised-password lookup in `SIMULATION`. It also normalizes both `mail.default` and legacy `mail.driver` to `array` or `log` in `SIMULATION`. Production metadata separately records `MAIL_MAILER=log` as a non-secret Production-only control for the next Production build.

Independent security re-review returned `GO`. The full local suite passed 506 tests with 6,415 assertions and three conditional skips.

## Environment isolation

- `DB_URL` is a sensitive Production-only record for new deployments.
- Production and Preview have separate sensitive `APP_KEY` records.
- `DEMO_ACCOUNT_PASSWORD`, `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `MAIL_USERNAME`, `MAIL_PASSWORD`, and `REDIS_PASSWORD` are sensitive Production-only records.
- Environment-variable values were not read, exported or retained for this inventory.
- Vercel environment changes do not alter immutable historical deployments. The historical Supabase credential must still be rotated or revoked under shared maintenance, and older Preview authentication must be proven denied before promotion or migration.

## Action-time stop conditions

Before promotion or migration, stop unless all of the following are independently rechecked:

1. Exact public Production and candidate deployment/source identities match the cutover packet.
2. `APP_MODE=SIMULATION`, `APP_SYNTHETIC_ONLY=true`, `DEMO_SEED_ENABLED=false`, and `MAIL_MAILER=log` are effective in the new Production build.
3. No BPJS/VClaim, SATUSEHAT, LIS, PACS, payment, Klaim or Apotek endpoint or credential has appeared.
4. Historical database credentials are revoked and older immutable Previews cannot authenticate.
5. Hosted runtime evidence shows no external compromised-password request and no network mail delivery.

Any mismatch, new client, endpoint, credential, webhook, actionable integration route or unapproved outbound request is `NO-GO`.
