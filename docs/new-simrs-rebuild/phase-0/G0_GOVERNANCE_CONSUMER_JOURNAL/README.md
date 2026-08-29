# Governance consumer journal

This directory is reserved for immutable, append-only journal records written by the canonical selector during a separately authorized operation. This README is documentation only; it is not a journal record and has no authority effect.

A journal records transaction history and recovery facts but never grants approval, activates a candidate, authorizes a capability, or closes G0 or G3. Journal entries must not be created or edited manually, and file presence cannot substitute for a valid operation decision, selection, or pointer.

Because the external product-owner and independent-review attestation trust store is not provisioned, canonical Gate-B activation is blocked and no operation journal record is authorized.
