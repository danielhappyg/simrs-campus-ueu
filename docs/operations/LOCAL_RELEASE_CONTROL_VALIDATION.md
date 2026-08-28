# Local Release Control Validation

- **Date:** 16 July 2026
- **Environment:** disposable local filesystem fixtures and the application test runner
- **Data boundary:** no patient data; no environment file; no account credential
- **Network boundary:** no host, SSH, HTTP endpoint, or GitHub environment contacted
- **OPS-02 status:** development control evidence only; hosted staging deployment and rollback remain open

## Purpose

This record covers two release controls that can be proven safely before Hostinger access is available:

1. the exact tar produced by CI is structurally checked against its sidecar and embedded manifest before upload, without claiming independent promotion provenance; and
2. a release pointer cannot change until a supplied health probe passes, while a preceding release can be selected again through the same checked switch.

The implementation deliberately separates artifact verification from deployment. `ops:verify-release` reads an archive and sidecar but never extracts, transfers, migrates, optimizes, switches, or contacts a runtime. `ReleaseSwitchController` is a tested filesystem primitive, not a registered deployment command or GitHub deployment job.

## Automated evidence

Focused verification passed **10 tests / 29 assertions**:

| Control | Evidence |
| --- | --- |
| Valid artifact | Sidecar, required archive root/files, simulation-only manifest, commit-derived release ID, Composer/asset hashes, and migration hashes accepted |
| Tampered archive | Sidecar mismatch rejected before archive inspection |
| Forbidden content | Embedded `.env` rejected even when a fresh matching sidecar exists |
| Manifest-bound input | Modified Composer hash rejected |
| Safety boundary | Non-`SIMULATION` manifest rejected |
| Command boundary | Repository-relative non-public paths accepted; traversal paths rejected |
| Failed promotion | False health probe raises an error and leaves `current` pointing to the preceding release |
| Promotion | Healthy candidate becomes the relative `current` symlink through a temporary atomic rename |
| Rollback | The same checked switch restores the preceding release |
| Release identity | Directory/manifest release-ID mismatch rejected before the health probe or switch |

Run the focused suite with:

```bash
php artisan test \
  tests/Unit/ReleaseArtifactVerifierTest.php \
  tests/Unit/ReleaseSwitchControllerTest.php \
  tests/Feature/VerifyReleaseArtifactCommandTest.php
```

## Fail-closed sequence

The intended later staging sequence is:

1. select a separately approved artifact from a successful `main` workflow and record its trusted SHA-256 from independently approved artifact metadata or a promotion record;
2. verify the downloaded tar and sidecar with `ops:verify-release --expect-archive-sha256=<trusted-digest>`, never deriving that trusted value from the downloaded tar or sidecar;
3. extract into a new versioned release directory using a separately reviewed staging procedure;
4. connect only approved shared environment/storage paths;
5. apply reviewed optimization and migration steps;
6. probe the candidate's real `/up` route and approved smoke flows;
7. switch the active pointer only if all probes pass; and
8. use the same checked switch to restore the preceding compatible release when a rollback trigger fires.

Steps 3–8 are not automated by this increment. The controller proves the switch invariant, but actual extraction safety, Hostinger symlink behavior, shared-path permissions, HTTP health behavior, database compatibility, and recovery time remain account-specific evidence.

## Rollback triggers retained for staging

- `/up` is not HTTP 200;
- a required smoke flow fails;
- the deployed commit or migration set differs from the selected manifest;
- the application cannot read its approved environment or write required cache/storage paths;
- a new critical/high security or integrity failure appears; or
- migration compatibility requires the preceding application to remain inactive and a forward correction is safer.

No failed probe may update the active release pointer. Database rollback is not implied by an application-pointer rollback; the reviewed migration/recovery policy remains authoritative.

## Remaining evidence

This record does not satisfy OPS-02. Completion still requires actual Hostinger preflight evidence, Daniel's separate staging authorization, an approved `main` artifact, a staging backup, real `/up` and smoke checks, a recorded active-release switch, restoration of the preceding healthy release, and database recovery evidence where applicable.

## Official references

- [Laravel 13 deployment requirements and health route](https://laravel.com/docs/13.x/deployment)
- [GitHub workflow artifacts](https://docs.github.com/en/actions/tutorials/store-and-share-data)
