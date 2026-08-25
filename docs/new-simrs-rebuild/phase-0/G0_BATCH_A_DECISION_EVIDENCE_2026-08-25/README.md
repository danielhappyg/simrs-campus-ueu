# G0 Batch A decision-evidence directory

This directory is reserved for the closed-schema JSON artifacts referenced by `G0_BATCH_A_DECISION_REGISTER_2026-08-25.json`.

It is empty by design. No Batch A evidence, accountable-owner appointment, co-owner appointment or owner approval has been recorded yet. Do not create an artifact from a candidate name, proposal, source-code path, test result or assumed authority.

When a real record is available:

1. retain the original human or institutional evidence outside this JSON summary where applicable;
2. create one JSON attestation with the exact schema enforced by `scripts/validate-parity-governance.rb`;
3. bind it to the register ID, PAR ID, subject, identity, authority/scope, date and decision/evidence values;
4. record an independent reviewer, verification method and verification reference;
5. calculate the JSON file's SHA-256 and copy that digest into the matching register record; and
6. run both integrity and G0 validation before claiming a row is resolved.

The validator accepts only regular, non-symlink `.json` files inside this directory. File presence and hashing protect binding and change detection; they do not replace human verification of the underlying appointment, evidence or approval.

