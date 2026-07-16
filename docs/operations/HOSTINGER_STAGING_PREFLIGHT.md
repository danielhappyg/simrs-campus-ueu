# Hostinger Staging Preflight

- **Boundary:** read-only capability and safety assessment
- **Target:** a separate Hostinger staging environment for synthetic simulation data
- **Authorization:** this procedure does not deploy, merge, create infrastructure, or authorize a faculty pilot
- **Current state:** command implemented; actual account evidence and `OPS-02` deployment/rollback rehearsal pending

## Purpose

`php artisan ops:hosting-preflight` converts the architecture's Hostinger gate into a repeatable, fail-closed report. It inspects the application runtime without writing application or account state, then combines those automated results with a sanitized operator evidence file.

The command has three outcomes:

| Status       | Meaning                                                                                                       | Exit code |
| ------------ | ------------------------------------------------------------------------------------------------------------- | --------: |
| `READY`      | Every automated check passes and every required manual item has explicit `PASS` evidence.                     |         0 |
| `INCOMPLETE` | Automated checks pass, but at least one required account or operational item is still `PENDING` or absent.    |         1 |
| `BLOCKED`    | An automated check fails, evidence is malformed/unreadable, or at least one manual item is explicitly `FAIL`. |         1 |

`READY` means ready to consider a harmless staging deployment rehearsal. It does **not** mean deployed, production-ready, clinically validated, or compliant.

## 1. Run the automated preflight

Run this from the exact built staging release directory with its staging environment loaded:

```bash
php artisan ops:hosting-preflight --json
```

Before evidence is supplied, the expected safe result is `INCOMPLETE` if the runtime is suitable, or `BLOCKED` if it is not. The JSON report is intended for retention as CI or release evidence. It contains check identifiers and safe summaries, not configuration values or credentials.

Automated checks cover:

- PHP 8.3 or newer and Laravel's required PHP extensions;
- `APP_ENV` limited to `staging` or `production`, `APP_DEBUG=false`, HTTPS `APP_URL`, and a configured application key;
- mandatory `SIMULATION` and synthetic-only boundaries;
- encrypted, HTTPS-only sessions;
- MySQL as the hosted database and a successful connection;
- database-backed queue selection;
- private local storage outside `public/`;
- writable private storage, `storage/`, and `bootstrap/cache`;
- the Laravel `/up` route; and
- a built Vite manifest at `public/build/manifest.json`.

The database check opens the configured connection but issues no application query or mutation. The command does not print the key, URL, database coordinates, filesystem path supplied to `--evidence`, or evidence text.

## 2. Collect manual evidence

Copy [the sanitized evidence example](hostinger-staging-evidence.example.json) to a protected location outside the repository. Replace `checkedAt`, each status, and each evidence summary only after direct verification. Never place usernames, account IDs, hostnames that must remain private, passwords, API tokens, private keys, database credentials, recovery codes, or raw backup contents in this file.

Required evidence:

| Check ID                    | Minimum proof                                                                                                           |
| --------------------------- | ----------------------------------------------------------------------------------------------------------------------- |
| `account.plan_version`      | Exact product/plan version and purchase-era limits recorded from the account.                                           |
| `ssh.access_restrictions`   | SSH enabled; home-directory, command, and connection restrictions observed.                                             |
| `build.composer_strategy`   | Production Composer dependencies can be built without secrets entering the artifact.                                    |
| `build.node_strategy`       | Frontend assets are built once in CI; Node is not required on the web host.                                              |
| `cron.scheduler`            | One-minute Laravel scheduler cron succeeds and leaves a retained record.                                                 |
| `queue.execution`           | Database-queue execution and restart behavior works within the exact plan limits.                                       |
| `storage.private_isolation` | Private files remain outside the public document root and survive release switches.                                     |
| `backup.retention_restore`  | Actual retention/frequency recorded and an isolated file-plus-database restore completed.                               |
| `release.atomic_switch`     | A release directory can be activated reversibly using an atomic switch or a proven equivalent.                          |
| `tls.dns_staging`           | Separate staging hostname and valid TLS exist without reusing production credentials.                                   |
| `logs.retention`            | Application/web log locations, access, retention, and redaction boundaries recorded.                                    |
| `resources.limits`          | CPU, memory, process, disk, inode, database, and cron limits recorded for the exact plan.                                |
| `github.environment_gate`   | Available private-repository environment/secrets approval mechanism or the approved manual fallback recorded.           |
| `ssh.host_key_pin`          | SSH host-key fingerprint independently verified before any later automation.                                            |

Allowed statuses are `PASS`, `FAIL`, and `PENDING`. Every supplied entry needs a short, non-secret evidence summary. Missing entries remain `PENDING`; unknown identifiers or malformed entries block the report. Any file containing a conclusive `PASS` or `FAIL` must have been collected within the last 30 days and cannot be future-dated. The all-`PENDING` example remains reusable because it makes no capability claim.

## 3. Evaluate the combined evidence

```bash
php artisan ops:hosting-preflight \
  --json \
  --evidence=/protected/path/hostinger-staging-evidence.json
```

Retain the JSON output with the commit, migration set, reviewer, and collection date. A failed command must stop the release decision. Do not edit a failed result to green; correct the environment or record a hosting migration decision.

## 4. Separate `OPS-02` rehearsal

Even a `READY` preflight does not satisfy [OPS-02](../product/OUTPATIENT_ACCEPTANCE_SCENARIOS.md#ops-02--deploy-and-roll-back-a-release-artifact). That later, separately authorized rehearsal must still prove all of the following on staging:

1. CI produced the exact tested release artifact;
2. a staging backup exists and its restore path is known;
3. the deployed commit and migration set are identifiable;
4. a failed health or smoke check prevents promotion; and
5. rollback or recovery restores the preceding healthy state.

No GitHub deployment workflow should be enabled until this preflight is complete and Daniel separately authorizes the staging deployment design.

## Official operational references

- [Laravel 13 deployment requirements and optimization](https://laravel.com/docs/13.x/deployment)
- [Laravel task scheduling](https://laravel.com/docs/13.x/scheduling)
- [Laravel queues](https://laravel.com/docs/13.x/queues)
- [Hostinger SSH access](https://www.hostinger.com/support/1583245-how-to-connect-to-a-hosting-plan-via-ssh-in-hostinger/)
- [Hostinger PHP extensions and options](https://www.hostinger.com/support/4667515-how-to-manage-php-extensions-and-options-in-hostinger/)
- [Hostinger backup downloads](https://www.hostinger.com/support/5981435-how-to-download-backups-at-hostinger/)
- [Hostinger backup restoration](https://support.hostinger.com/en/articles/4283700-how-to-restore-backups-at-hostinger)
- [Hostinger plan-version limits](https://www.hostinger.com/support/10717644-new-web-and-cloud-hosting-limits-at-hostinger/)
- [GitHub deployment environments](https://docs.github.com/en/actions/reference/workflows-and-actions/deployments-and-environments)
- [GitHub Actions secure-use guidance](https://docs.github.com/en/actions/reference/security/secure-use)
