# Governance-v2 retained candidates

This directory contains create-only, deterministic governance-v2 candidate evidence. A retained candidate is a pending projection of the adopted sources. It does not approve a capability, authorize implementation, select a governance consumer, mutate the canonical pointer, close G0 or G3, or authorize deployment, migration, real patient data, or a live integration.

Each candidate must be one real, non-symlink direct-child directory created by `scripts/generate-g0-proportional-governance-v2.rb --retained-name`. A complete candidate contains exactly the six generator-owned JSON files and no additional file. Existing candidates must never be overwritten, edited, renamed into an authority directory, or treated as an operation decision.

Read-only deterministic verification uses `--verify-retained` with the exact repository-relative candidate path. Verification proves only current byte equality, source integrity, and the pending/no-authority contract. It does not invoke the selector or create a selection, decision, journal, receipt, or pointer.

Canonical Gate-B activation remains blocked because the external product-owner and independent-review attestation trust store is not provisioned. Repository-authored identities, messages, hashes, reviews, or candidate files cannot substitute for those external attestations.
