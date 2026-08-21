# Checkpoint 2 Facilitator Quickstart — 20 August 2026

> **OPERATIONAL CHEAT SHEET — USE WITH THE FULL UAT GUIDE, NOT AS A REPLACEMENT.**

- **Primary contract:** [Outpatient Checkpoint 2 UAT Facilitator Guide](OUTPATIENT_CHECKPOINT_2_UAT_GUIDE.md)
- **Run record:** [Outpatient Checkpoint 2 UAT Record — 20 August 2026 Draft](OUTPATIENT_CHECKPOINT_2_UAT_RECORD_2026-08-20_DRAFT.md)
- **Entry gate evidence:** [Outpatient Checkpoint 2 Entry Gate Validation — 20 August 2026](OUTPATIENT_CHECKPOINT_2_ENTRY_GATE_VALIDATION_2026-08-20.md)
- **Optional hosted demo target:** https://simrs-campus-ueu-demo.vercel.app (synthetic tip `ef45c80` on `codex/vercel-supabase-demo`; campus production hosting TBD)

## Before participants join

1. Confirm the environment is still synthetic-only:
   - `APP_MODE=SIMULATION`
   - `APP_SYNTHETIC_ONLY=true`
   - no production SATUSEHAT/BPJS/email/messaging credentials

2. Confirm the candidate branch/commit and evidence set:
   - exact commit identified (for the published demo tip, start from `5f8baf2` unless a newer tip is intentional)
   - dependency-hygiene record linked
   - dated UAT record copy ready
   - if using the hosted demo, confirm https://simrs-campus-ueu-demo.vercel.app `/login` loads and the permanent simulation banner remains visible after sign-in

3. Run the gate commands (local fixture **or** against the hosted Supabase session from a trusted checkout with demo env loaded locally — never commit secrets):

```bash
php artisan simulation:lab-access enable --confirm=ENABLE-RESERVED-DEMO-ACCESS
php artisan simulation:lab-preflight
php artisan simulation:clone-reference-session UAT-MAIN-001 --duration=480
php artisan simulation:lab-session-status UAT-MAIN-001
```

4. Record in the UAT draft:
   - session code
   - encounter identifier
   - terminology release/checksum suffixes
   - environment/hosting topology (`local` vs `vercel+supabase` tip SHA)

## Before the first task

1. Open the exact queue path, for example:

```text
/work?session=UAT-MAIN-001
```

2. Confirm:
   - `SIMULASI — DATA SINTETIS` is visible
   - the selected session code matches the recorded run
   - the facilitator sees the expected ready registration handoff
   - no unrelated case or role data is visible

3. Brief participants:
   - synthetic-only data
   - no real names or identifiers
   - stop immediately for wrong-role access, missing simulation boundary, overwrite risk, or production/integration leakage

## During the run

For each step:

1. acting participant states their role and intended action
2. facilitator records visible pre-state
3. participant performs the action without database intervention
4. next role confirms what became available
5. facilitator records pass/fail evidence and any issue ID

Use `php artisan simulation:lab-session-status <SESSION> --json` after disputed handoffs, unexpected task state, stop-session events, and closeout.

## After the run

1. Complete the UAT draft outcome fields.
2. Classify every issue for Daniel review.
3. Disable the reserved roster:

```bash
php artisan simulation:lab-access disable --confirm=DISABLE-RESERVED-DEMO-ACCESS
```

4. Record `DISABLED` or `UNCHANGED` as access-closeout evidence.
5. Do not interpret this run as merge approval, deployment approval, or faculty-pilot approval.

## Related Daniel packet

Before inviting faculty participants, Daniel should review [Checkpoint 2 Daniel Decision Packet — 20 August 2026](CHECKPOINT_2_DANIEL_DECISION_PACKET_2026-08-20.md) for post-merge status, proposed issue classifications, and non-authorizations. Merged faculty-correction PRs and demo publish do **not** replace PASS/VAL marks.
