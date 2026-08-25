# G0 Batch B decision-evidence directory

This directory is reserved for the closed-schema JSON artifacts referenced by `G0_BATCH_B_DECISION_REGISTER_2026-08-25.json`.

It is empty by design. No Batch B evidence, accountable-owner appointment, co-owner appointment or owner approval has been recorded yet. Existing matrix dispositions, source-code paths, automated tests, hosted-UAT notes and candidate names do not become authoritative evidence or decisions merely by being cited.

When a real record is available:

1. retain the underlying human or institutional evidence outside this JSON attestation where applicable;
2. create one JSON artifact using the shared exact schema enforced by `scripts/validate-parity-governance.rb`;
3. bind it to `G0-BATCH-B-2026-08-25`, the exact PAR ID, subject, identity, authority/scope, date and decision/evidence values;
4. record an independent reviewer, allowed verification method and verification reference;
5. calculate the artifact's SHA-256 and copy it into the matching Batch B register record; and
6. run integrity and G0 validation before claiming the row is resolved.

The validator accepts only regular, non-symlink `.json` files within this directory. File presence and hashing protect binding and change detection; they do not replace human verification of the underlying appointment, evidence or approval.
