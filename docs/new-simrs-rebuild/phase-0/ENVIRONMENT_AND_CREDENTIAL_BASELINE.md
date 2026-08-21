# Environment and credential baseline

Status: Phase 0 checklist (working)  
Date: 2026-08-21  
Related: DEC-006, DEC-007, `SECURITY_PRIVACY_AND_AUDIT.md`, `OPERATIONS_AND_RELIABILITY.md`

## Environment map (current understanding)

| Environment | Purpose | Data | External connectivity | Notes |
|---|---|---|---|---|
| Local developer | implementation + automated tests | generated synthetic / sqlite or local DB | blocked / stubs by default | `.env` local only; never commit secrets |
| CI | repeatable checks | seeded synthetic | stubs | GitHub Actions |
| Vercel + Supabase demo | disposable review/demo | synthetic | sandbox/demo only | existing demo path; push/deploy needs explicit auth |
| Campus staging/production | TBD | TBD | TBD | hosting decision open; not implied by demo |

## Non-negotiable controls

- [x] Planning default: synthetic-only teaching release (DEC-006)
- [x] Local example config documents `APP_MODE=SIMULATION` and `APP_SYNTHETIC_ONLY=true`
- [ ] Formal scan of local `.env`, `.env.vercel.local`, CI secrets, and fixtures for vendor/production credentials (operator task; do not paste secrets into docs)
- [ ] Outbound allowlist documented for any enabled adapter (deny-by-default)
- [ ] Production BPJS / SATUSEHAT / E-Klaim / LIS / PACS / payment / WhatsApp endpoints remain disabled for ordinary development and teaching
- [ ] Sandbox adapters visibly labelled `SIMULATION` / `SANDBOX` and incapable of fall-through to production
- [ ] No copy of old vendor passwords, API keys, tokens, certificates, cookies, or hashes into this repo

## Credential handling rules

1. Secrets live only in environment secret stores or ignored local files.
2. Requirements, issues, chat and commits must never contain secret values.
3. Rotate any credential that may have been exposed in the vendor assessment surface (institutional IT action; out of band).
4. Demo account passwords for seeders are local opt-in only (`DEMO_SEED_ENABLED`).

## Evidence gap (Unknown)

Physical production topology, backup restore proof for campus target, and final campus hosting owner remain **Unknown** until operations owner is appointed and evidence is attached to `RELEASE_EVIDENCE_INDEX.md`.
