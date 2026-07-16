# Release Candidate Artifact

- **Boundary:** build and retain an identifiable runtime candidate; do not deploy it
- **Environment:** GitHub Actions and optional local structural validation
- **Current state:** manifest/assembly commands and CI job implemented; first remote artifact run pending publication
- **OPS-02 status:** partially advanced, not complete

## Purpose

The release-candidate job implements pipeline steps 1–5 from the master plan without enabling steps 6–12. It runs only after the application/security and MySQL 8.4 jobs pass, installs production PHP dependencies from `composer.lock`, builds the frontend once from `package-lock.json`, assembles an allowlisted runtime tree, creates a permission-preserving tar file and SHA-256 sidecar, and uploads both as a short-lived GitHub workflow artifact.

It has no GitHub environment, SSH key, Hostinger hostname, deployment secret, transfer step, database migration step, active-release switch, or production approval. Its embedded manifest permanently states `NOT_DEPLOYED`.

## Artifact contract

Generate the manifest only after dependencies and production assets are built:

```bash
php artisan ops:release-manifest release-manifest.json --commit=<checked-out-40-character-sha>
```

The JSON records:

- exact Git commit, tree, and commit time;
- SHA-256 for `composer.lock`, `package-lock.json`, and `public/build/manifest.json`;
- every migration filename and SHA-256 in stable filename order;
- permanent `SIMULATION` and synthetic-only boundaries; and
- `NOT_DEPLOYED`, `/up`, deploy-time optimization, and separate-promotion-approval markers.

It intentionally records no build-machine path, environment value, credential, key, account identifier, database coordinate, or evidence text. The output path must remain repository-relative, outside `public/`, and free of path traversal. A supplied commit must match `HEAD`.

Assemble the runtime tree into a new empty directory:

```bash
php artisan ops:assemble-release release-manifest.json storage/app/release-candidate
```

The assembler copies only:

- Git-tracked runtime paths under `app`, `bootstrap`, `config`, selected `database` paths, `public`, `resources`, `routes`, and tracked storage placeholders;
- `artisan`, `composer.json`, and `composer.lock`;
- the installed `vendor` tree;
- the compiled `public/build` tree; and
- the validated release manifest.

It refuses tracked paths outside its runtime allowlist and refuses to merge into a non-empty output directory. Because the tracked list comes from Git, untracked `deliverables/`, runtime logs, local databases, and developer files cannot enter through a broad workspace copy.

## Explicit exclusions

The assembled candidate must not contain:

- `.env` or any deployment/account secret;
- `.git` or `.github`;
- tests or documentation;
- `node_modules` or frontend toolchain configuration;
- local SQLite/database/dump files;
- raw ICD workbooks;
- runtime logs, sessions, caches, or user uploads; or
- user-owned `deliverables/` content.

Shared private/public storage remains a deployment-time concern. Laravel optimization is also deferred until the target environment is loaded; caching configuration during CI would bind the wrong environment. Laravel's deployment guidance places `php artisan optimize`, writable `storage`/`bootstrap/cache`, `/up`, and long-running service reloads in the deployment process, not in this environment-neutral archive.

## CI packaging sequence

The non-deploying `release-candidate` job:

1. waits for both application/security and MySQL 8.4 jobs;
2. checks out the exact tested revision;
3. installs `composer --no-dev` dependencies and Node dependencies from lockfiles;
4. builds assets and fails if tracked source changes;
5. generates and assembles the manifest-bound runtime tree;
6. asserts the required files and high-risk exclusions;
7. creates a name-sorted tar with commit-time timestamps and normalized numeric ownership;
8. writes a SHA-256 sidecar; and
9. uploads an immutable artifact named with the complete checked-out commit for 14 days.

GitHub's artifact action reports its own artifact ID, URL, and SHA-256 digest. The tar wrapper is retained because GitHub notes that direct artifact upload does not preserve original file permissions; the tar retains the executable mode needed by `artisan`.

## Local structural validation

On 16 July 2026, the commands assembled the then-current committed HEAD locally using the existing development dependency tree:

| Check | Result |
| --- | --- |
| Manifest | Passed: exact commit/tree, three input hashes, 19 migration hashes, simulation boundary, `NOT_DEPLOYED` |
| Runtime assembly | Passed: 396 tracked files plus 10,637 generated dependency/asset files |
| Candidate size | 120 MB with development dependencies; not representative of CI `--no-dev` size |
| Tar creation | Passed: 129 MB local structural tar with SHA-256 sidecar calculation |
| Forbidden content scan | No `.env`, local database, raw workbook, key file, tests, or `node_modules` found |

This proves the local allowlist and archive structure, not production dependency composition, GitHub retention, Hostinger compatibility, deployment, health promotion, or rollback. The first successful remote job is required before calling the CI artifact path verified.

## Remaining `OPS-02` evidence

After actual Hostinger preflight evidence and separate authorization, staging must still prove:

1. the downloaded tar and sidecar match the workflow artifact and embedded manifest;
2. the exact commit and migration set are recorded before deployment;
3. a backup exists and its restore procedure is available;
4. shared environment/storage paths are connected safely;
5. environment-specific optimization and migrations succeed;
6. failed `/up` or smoke checks prevent the active-release switch;
7. the preceding healthy artifact can be restored; and
8. database recovery follows the reviewed migration policy.

No item above is satisfied merely because the candidate was built.

## Official references

- [Laravel 13 deployment requirements, optimization, permissions, reload, and health route](https://laravel.com/docs/13.x/deployment)
- [GitHub workflow artifacts](https://docs.github.com/en/actions/tutorials/store-and-share-data)
- [GitHub `upload-artifact` inputs, immutable artifacts, digests, retention, and permission behavior](https://github.com/actions/upload-artifact)
- [GitHub artifact retention](https://docs.github.com/en/actions/how-tos/manage-workflow-runs/remove-workflow-artifacts)
