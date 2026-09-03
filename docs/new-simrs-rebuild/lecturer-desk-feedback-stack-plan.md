# Lecturer desk feedback stack plan

This stack changes three desk behaviors Fuad asked for.
New medical record numbers are six-digit characters that keep leading zeros.
The rawat jalan clinic list grows to the Type B outpatient set and drops IGD plus supporting units.
General consent is the RS ESA Unggul form, filled and signed at the desk, the same shape a real hospital uses.
The order is PR-A, then PR-B, then PR-C.
Patients stay synthetic. Do not call live BPJS. Do not claim certified TTE. Leave G0 parked.

## How to read this

One box is one unit of work. Every box names the evidence that checks it. A nested box is a sub-step of the box above it. Check a box only when its evidence exists, a file, a log line, a screenshot, a test run, or a SHA. The body is a how-to. The appendices explain and record.

The program runs `pstack/skills/poteto-mode/playbooks/autopilot-stack.md`. The operator lands PR-A, PR-B, and PR-C bottom-up after the root appends each verified link. Nothing auto-merges.

Tests alone are not sufficient verification. A PR is verified only when its unit, live, and perf boxes are all checked.

## Program checklist

### Arm the program

- [ ] State the protocol and this plan to the operator, then stop. Start execution only on her explicit go.
- [ ] On her go, arm a `/goal` with this exact text. "docs/new-simrs-rebuild/lecturer-desk-feedback-stack-plan.md. PR-A then PR-B then PR-C. Tests alone are not sufficient verification. A PR is verified only when its unit, live, and perf boxes are all checked. The operator lands the stack. Done when a new register shows a six-digit RM, the RJ clinic list is Type B outpatient without IGD, and General Consent opens the RS ESA Unggul form, accepts two signatures, and reprints those marks."
- [ ] Read these from trunk at program start. Re-read them at every tick.
  - [ ] `git show origin/main:pstack/skills/poteto-mode/playbooks/autopilot-stack.md`
  - [ ] `git show origin/main:pstack/skills/swarm/SKILL.md`
  - [ ] `git show origin/main:pstack/skills/control-ui/SKILL.md`
  - [ ] `git show origin/main:pstack/skills/poteto-mode/playbooks/opening-a-pr.md`
  - [ ] `git show origin/main:pstack/skills/how/SKILL.md`
  - [ ] `git show origin/main:pstack/skills/interrogate/SKILL.md`
  - [ ] `git show origin/main:pstack/skills/show-me-your-work/SKILL.md`
  - [ ] `git show origin/main:pstack/skills/unslop/SKILL.md`
  - [ ] `git show origin/main:pstack/skills/technical-writing/SKILL.md`
- [ ] Arm the 30-minute audit tick. In a local session, a real terminal `/loop`. In a cloud root, a cloud-sleeper wake chain. Never leave the cadence to memory.
- [ ] Use this tick prompt, verbatim. "Re-read the execution playbook from trunk and the armed /goal. Audit the operation against both and fix drift in this tick. Probe every active lane and judge progress by side effects only. Stand down a stuck lane and dispatch its replacement now. Then send the operator a status message, whether or not anything changed, with the queue table of PR, owner, state, and head SHA, the verdicts since the last tick, what merged, open operator gates, and blockers."
- [ ] On the operator's hold or stand-down, send every owner a zero-writes order at once.

### Spawn owners

- [ ] Spawn one owner per PR with the full lifecycle the execution playbook names.
- [ ] Follow this dependency graph. Start dependent work only after its parent merges, or base it on the parent branch when the execution playbook stacks.
  - [ ] PR-A is first. It branches from `main`.
  - [ ] PR-B after PR-A.
  - [ ] PR-C after PR-B.
- [ ] Hold the file boundaries. PR-A touches the allocator, the counter table, the three `generateMedicalRecordNumber` call sites, `TeachingCensusSeeder`, recap CSV quoting for digit-only RMs, and the matching tests. PR-B touches `Clinic` booking surface, `OutpatientMastersSeeder`, the RJ clinic query and store guard, the pemeriksaan and RM and rekap clinic filters, `ensureMastersSeeded`, and the matching tests. PR-C touches the General Consent form, signature capture, both Cetak buttons on `pendaftaran/rawat-jalan.tsx`, the consent print view, and `OutpatientPrintAndRecapTest`.
- [ ] Hold the review gate. PR-A, PR-B, and PR-C change an interaction. They wait for the operator's review in chat with screenshots and a video before merge.

### PR mechanics, for every PR

- [ ] Resolve the forge once. Default to `gh`; if `command -v origin` succeeds and Origin can resolve the repository, use `origin pr` for every PR operation. Record any fallback to `gh`. Never require `gt`.
- [ ] Open the PR ready, never draft, with `origin pr create --status open --base <base-branch>` or `gh pr create --base <base-branch>` according to the resolved forge. A stack child targets its parent branch.
- [ ] Run the repo's lint and typecheck once before the PR-facing push. Push with hooks on. Run `vendor/bin/pint --dirty`, `composer types:check`, `npm run types:check`, and `php artisan test` for the files this PR owns.
- [ ] Run `/deslop` before each commit and `/no-comments` before review.
- [ ] Triage every Bugbot and security-reviewer comment per `../references/bugbot-triage.md`.
- [ ] Rebase onto current trunk before babysit and again before the merge-ready report.

### Verdict and merge, for every PR

- [ ] At the merge-ready head SHA, run the swarm per `pstack/skills/swarm/SKILL.md`. One gates lane. The ten live lanes from the PR's **Verify, live** block. The perf lane from its **Verify, perf** block. One audit lane that reads the diff and the receipts and distrusts the PR body.
- [ ] Clean only when every lane is `PASS`. Findings go back to the owner. A new head gets a fresh swarm and a fresh verdict.
- [ ] The root appends the PR to the base-branch stack. The operator lands it bottom-up. No owner merges. After rebase, compare `git patch-id`. An unchanged patch-id keeps the code verdict. A changed patch re-runs the swarm.

### Boot recipe, for every live lane

Each live lane runs on its own cloud VM at the PR head. Drive through `control-ui` from `cursor-team-kit`.

- [ ] `git fetch origin <head-branch> && git checkout <head SHA>`.
- [ ] Copy `.env.example` to `.env`. Set `APP_MODE=SIMULATION`, `APP_SYNTHETIC_ONLY=true`, `DEMO_SEED_ENABLED=true`, and a local `DEMO_ACCOUNT_PASSWORD`. Run `composer install`, `npm ci`, `php artisan key:generate`, `touch database/database.sqlite`, `php artisan migrate`, `php artisan db:seed`, `npm run build`, then `composer dev`. Wait until `http://localhost:8000` serves the login page.
- [ ] Log in as `mahasiswa.rmik@example.invalid` with the local demo password (ACTIVE campus walkthrough account after seed). Do not expect `registrar.demo@example.invalid` to work until an operator runs `teaching:role-access activate` with trusted runtime bindings. Deliver input only through `control-ui` commands. Use accessibility roles and labels, not coordinates. Read-only diagnostics are the accessibility snapshot, the network log, and the console.
- [ ] Save every screenshot to `/tmp/swarm-<pr-id>/worker-<n>/<slug>.png` and return the paths with the report.

## Allocate a six-digit character MRN (PR-A)

**Depends on.** None.

**Files.**

- [ ] Create `app/Support/Registration/MedicalRecordNumber.php`.
- [ ] Create `app/Support/Registration/MedicalRecordNumberAllocator.php`.
- [ ] Create `app/Models/MedicalRecordNumberCounter.php`.
- [ ] Create `database/migrations/2026_09_03_100000_create_medical_record_number_counters.php`.
- [ ] Create `tests/Feature/Registration/MedicalRecordNumberAllocatorTest.php`.
- [ ] Create `tests/Unit/Registration/MedicalRecordNumberTest.php`.
- [ ] Edit `app/Http/Controllers/Outpatient/OutpatientRegistrationController.php`.
- [ ] Edit `app/Http/Controllers/Emergency/EmergencyRegistrationController.php`.
- [ ] Edit `app/Http/Controllers/Inpatient/InpatientRegistrationController.php`.
- [ ] Edit `database/seeders/TeachingCensusSeeder.php`.
- [ ] Edit `tests/Feature/Wilayah/TeachingCensusSeederTest.php`.
- [ ] Edit `app/Http/Controllers/Outpatient/OutpatientRecapController.php`.

**Build.**

- [ ] Add a `MedicalRecordNumber` value object with width 6, digits only, and `sprintf` padding. Copy the lock pattern from `DailyQueueAllocator`. Do not reuse `DailyQueueCounter`. Call `allocate` inside the existing registration transaction. Delete the three private `generateMedicalRecordNumber` methods. In `TeachingCensusSeeder`, migrate existing `SYNTH-CENSUS-*` synthetic rows to padded digits in the same run, then `ensureHighWatermark`. Do not `updateOrCreate` on a new MRN key while leaving the old census patients behind. Leave `patients.medical_record_number` as `string`. Quote digit-only RMs in recap CSV so Excel keeps zeros. Do not remint the hosted demo database in this PR. Do not rewrite hosted finance snapshots.

**You see.**

- [ ] After a blank-RM register on Pendaftaran Rawat Jalan, the encounter card shows a six-digit No. RM such as `000001`. Leading zeros stay visible. Census rows no longer show `SYNTH-CENSUS-002`.

**Verify, unit.** Tests alone are not sufficient verification. A PR is verified only when its unit, live, and perf boxes are all checked.

- [ ] `tests/Unit/Registration/MedicalRecordNumberTest.php` rejects a numeric-looking integer cast and accepts `000212`. Run `php artisan test --compact tests/Unit/Registration/MedicalRecordNumberTest.php`.
- [ ] `tests/Feature/Registration/MedicalRecordNumberAllocatorTest.php` proves one sequence across outpatient, emergency, and inpatient, and proves `lockForUpdate` uniqueness. Run `php artisan test --compact tests/Feature/Registration/MedicalRecordNumberAllocatorTest.php`.
- [ ] `tests/Feature/Wilayah/TeachingCensusSeederTest.php` asserts census MRNs match `^[0-9]{6}$` and that a second seed does not leave `SYNTH-CENSUS-*` orphans. Run `php artisan test --compact tests/Feature/Wilayah/TeachingCensusSeederTest.php`.
- [ ] `tests/Feature/Outpatient/OutpatientFlowTest.php`, `tests/Feature/Emergency/EmergencyFlowTest.php`, and `tests/Feature/Inpatient/InpatientFlowTest.php` still register with a blank RM. Run `php artisan test --compact tests/Feature/Outpatient/OutpatientFlowTest.php tests/Feature/Emergency/EmergencyFlowTest.php tests/Feature/Inpatient/InpatientFlowTest.php`.

**Verify, live.** Tests alone are not sufficient verification. A PR is verified only when its unit, live, and perf boxes are all checked. Ten lanes on `grok-4.6-fast-xhigh` at the PR head, per the boot recipe.

- [ ] Lane 1. Regression lane against trunk. Run a blank-RM outpatient register at trunk and head. If trunk lacks six-digit RMs, record that and gate the new RM on the encounter card plus the post-register end state. Save `pr-a-regression-rm.png`. Pass when head shows six digits and trunk is recorded as `RM-` or `SYNTH-CENSUS` format.
- [ ] Lane 2. Register a new outpatient with the RM field blank. Save `pr-a-rj-blank-rm.png`. Pass when No. RM is exactly six digit characters with leading zeros.
- [ ] Lane 3. Register a second new outpatient in the same session. Save `pr-a-rj-next-rm.png`. Pass when the second RM is the first RM plus one, still six digits.
- [ ] Lane 4. Open Pendaftaran IGD and register a new patient with a blank RM. Save `pr-a-igd-rm.png`. Pass when IGD uses the same six-digit sequence, not a private IGD format.
- [ ] Lane 5. Open Pendaftaran Rawat Inap and register a new patient with a blank RM. Save `pr-a-ri-rm.png`. Pass when RI uses the same six-digit sequence.
- [ ] Lane 6. Search today's list for `000001`. Save `pr-a-search-rm.png`. Pass when the census or today's row is found by the zero-padded string.
- [ ] Lane 7. Open a seeded census patient that used to be `SYNTH-CENSUS-001`. Save `pr-a-census-rm.png`. Pass when that row shows a six-digit RM after local seed.
- [ ] Lane 8. Print kartu for the new encounter. Save `pr-a-kartu-rm.png`. Pass when the print shows the same six-digit RM as the card.
- [ ] Lane 9. Reload the encounter after a full page refresh. Save `pr-a-refresh-rm.png`. Pass when zeros remain, proving the value is a string in HTML.
- [ ] Lane 10. Register with an explicit six-digit RM `000212` that is free. Save `pr-a-explicit-rm.png`. Pass when the stored RM is `000212` and not `212`.

**Verify, perf.** Tests alone are not sufficient verification. A PR is verified only when its unit, live, and perf boxes are all checked.

- [ ] Metric. Wall time from clicking Simpan on a blank-RM outpatient register to the encounter card showing No. RM, measured at trunk and head. Also isolate allocator `lockForUpdate` query time as the diff-added work.
- [ ] Probe. Drive the same register path with `control-ui` at trunk and at the head, interleaved, three samples each. Both sides must produce the wall time.
- [ ] Baseline. Record the trunk wall time first.
- [ ] Rule. Head wall time may not exceed trunk wall time plus 250 ms. Allocator lock work must stay under 50 ms absolute. Fail the PR if either budget breaks.

**Review gate.** The operator reviews before merge.

- [ ] Copy lane 2 screenshots into `/tmp/lecturer-stack-review/pr-a-review-rj-blank-rm.png`.
- [ ] Record a 30 to 60 second video of a blank-RM register on a lane VM. Save it as `/tmp/lecturer-stack-review/pr-a-review.mp4`.
- [ ] Post the screenshots and the video in chat. Stop at merge-ready. Wait for the operator's click.

**Merge.**

- [ ] Root's clean verdict at the exact head SHA.
- [ ] Bugbot triage done.
- [ ] Rebased onto current trunk after the verdict, patch-id unchanged.
- [ ] The root appends PR-A to the base-branch stack. The operator lands it bottom-up.

## Seed Type B clinics and hide supporting units from RJ (PR-B)

**Depends on.** PR-A.

**Files.**

- [ ] Create `app/Support/Registration/ClinicBookingSurface.php`.
- [ ] Create `database/migrations/2026_09_03_100100_add_booking_surface_to_clinics.php`.
- [ ] Create `tests/Feature/Outpatient/ClinicBookingSurfaceTest.php`.
- [ ] Edit `app/Models/Clinic.php`.
- [ ] Edit `database/seeders/OutpatientMastersSeeder.php`.
- [ ] Edit `app/Http/Controllers/Outpatient/OutpatientRegistrationController.php`.
- [ ] Edit `app/Http/Controllers/Outpatient/OutpatientExaminationController.php`.
- [ ] Edit `app/Http/Controllers/Outpatient/OutpatientRmController.php`.
- [ ] Edit `app/Http/Controllers/Outpatient/OutpatientRecapController.php`.
- [ ] Edit `tests/Feature/Outpatient/OutpatientFlowTest.php` only if a UMUM or GIGI assertion breaks.
- [ ] Edit `tests/Feature/Emergency/EmergencyFlowTest.php` only if the IGD page clinic count breaks.

**Build.**

- [ ] Add `booking_surface` on `clinics` with values `outpatient`, `emergency`, and `supporting`. Keep codes `UMUM`, `GIGI`, `ANAK`, `JANTUNG`, and `IGD`. Append new Type B rows after those codes so `OutpatientFlowTest::firstMasterChain` still lands on UMUM by `id`. Expand `OutpatientMastersSeeder` from the Type B workbook at `/Users/danielhappyg/Downloads/daftar_poliklinik_rs_tipe_b (1).xlsx` into a PHP array. Three fictional doctors per new clinic. Mark IGD as `emergency`. Mark radiologi, patologi klinik, and anestesi as `supporting`. Mark rehab medik as `outpatient`. Filter RJ index, store, pemeriksaan, RM, and rekap to `outpatient`. Reject an IGD clinic on the RJ store. Leave the IGD desk querying `code = IGD` so `clinics[0]` on the IGD variant stays IGD. Make `ensureMastersSeeded` run the seeder even when clinics already exist, because `updateOrCreate` on `code` is idempotent and empty-table-only seeding will skip Type B on existing DBs. Do not delete the IGD row.

**You see.**

- [ ] The Pendaftaran Rawat Jalan clinic list includes Penyakit Dalam, Bedah Umum, and the other Type B outpatient names. It does not include IGD, Radiologi, Patologi Klinik, or Anestesi. Pendaftaran IGD still loads.

**Verify, unit.** Tests alone are not sufficient verification. A PR is verified only when its unit, live, and perf boxes are all checked.

- [ ] `tests/Feature/Outpatient/ClinicBookingSurfaceTest.php` asserts the RJ Inertia `clinics` payload excludes `IGD` and supporting codes, and includes Type B outpatient codes. Run `php artisan test --compact tests/Feature/Outpatient/ClinicBookingSurfaceTest.php`.
- [ ] `tests/Feature/Outpatient/OutpatientFlowTest.php` still books `UMUM` and `GIGI`. Run `php artisan test --compact tests/Feature/Outpatient/OutpatientFlowTest.php`.
- [ ] `tests/Feature/Emergency/EmergencyFlowTest.php` still loads exactly one IGD clinic on the IGD desk. Run `php artisan test --compact tests/Feature/Emergency/EmergencyFlowTest.php`.

**Verify, live.** Tests alone are not sufficient verification. A PR is verified only when its unit, live, and perf boxes are all checked. Ten lanes on `grok-4.6-fast-xhigh` at the PR head, per the boot recipe.

- [ ] Lane 1. Regression lane against trunk. Open the RJ clinic dropdown at trunk and head. If trunk lacks Type B names, record that and gate the new outpatient names plus the absent IGD row. Save `pr-b-regression-clinics.png`. Pass when head shows Type B outpatient names and no IGD.
- [ ] Lane 2. Open Pendaftaran Rawat Jalan and expand the clinic list. Save `pr-b-rj-list.png`. Pass when IGD is absent.
- [ ] Lane 3. Find Penyakit Dalam in the RJ list and open its doctors. Save `pr-b-dalam-doctors.png`. Pass when three fictional doctors are listed.
- [ ] Lane 4. Find Bedah Mulut or another gigi spesialis in the RJ list. Save `pr-b-gigi-spesialis.png`. Pass when that clinic is bookable from RJ.
- [ ] Lane 5. Confirm Radiologi is absent from the RJ list. Save `pr-b-no-radiologi.png`. Pass when Radiologi is not a booking option.
- [ ] Lane 6. Confirm Anestesi is absent from the RJ list. Save `pr-b-no-anestesi.png`. Pass when Anestesi is not a booking option.
- [ ] Lane 7. Book UMUM end to end with an existing schedule. Save `pr-b-book-umum.png`. Pass when the encounter is created.
- [ ] Lane 8. Open Pendaftaran IGD. Save `pr-b-igd-desk.png`. Pass when the IGD desk still registers against clinic code `IGD` and the poliklinik select stays hidden.
- [ ] Lane 9. Open Rehab Medik from RJ if the workbook lists it. Save `pr-b-rehab.png`. Pass when rehab is present as outpatient booking.
- [ ] Lane 10. Scroll the full RJ clinic list. Save `pr-b-full-list.png`. Pass when supporting units are absent and Type B outpatient names are present.

**Verify, perf.** Tests alone are not sufficient verification. A PR is verified only when its unit, live, and perf boxes are all checked.

- [ ] Metric. Time to first Inertia render of `/pendaftaran/rawat-jalan` at trunk and head. Also isolate clinic query time as the diff-added work.
- [ ] Probe. Load the RJ page with `control-ui` at trunk and at the head, interleaved, three samples each. Both sides must produce the render time.
- [ ] Baseline. Record the trunk render time first.
- [ ] Rule. Head render time may not exceed trunk render time plus 300 ms. Clinic query work must stay under 100 ms absolute. Fail the PR if either budget breaks.

**Review gate.** The operator reviews before merge.

- [ ] Copy lane 2 screenshots into `/tmp/lecturer-stack-review/pr-b-review-rj-list.png`.
- [ ] Record a 30 to 60 second video of opening the RJ clinic list on a lane VM. Save it as `/tmp/lecturer-stack-review/pr-b-review.mp4`.
- [ ] Post the screenshots and the video in chat. Stop at merge-ready. Wait for the operator's click.

**Merge.**

- [ ] Root's clean verdict at the exact head SHA.
- [ ] Bugbot triage done.
- [ ] Rebased onto current trunk after the verdict, patch-id unchanged.
- [ ] The root appends PR-B onto PR-A. The operator lands it bottom-up.

## Ship the RS ESA Unggul general consent form (PR-C)

**Depends on.** PR-B.

**Files.**

- [ ] Create `resources/views/prints/partials/consent.blade.php`.
- [ ] Create `app/Support/Registration/EncounterConsent.php`.
- [ ] Create `app/Http/Controllers/Outpatient/OutpatientConsentController.php`.
- [ ] Create `database/migrations/2026_09_03_100200_create_encounter_consents.php`.
- [ ] Edit `resources/views/prints/encounter.blade.php`.
- [ ] Edit `tests/Feature/Outpatient/OutpatientPrintAndRecapTest.php`.
- [ ] Edit `resources/js/pages/pendaftaran/rawat-jalan.tsx` so General Consent opens the form, captures two signatures, and both Cetak buttons use `selectedPrintDocs`.
- [ ] Edit `routes/web.php`.

**Build.**

- [ ] Build the full RS ESA Unggul GENERAL CONSENT document from `/Users/danielhappyg/Downloads/General Consent Form (1).docx`. Patient name, RM, visit date, and the Word clauses are the form body. Clicking General Consent opens that form in the app, not a three-line stub. Capture ttd for yang menjelaskan and for pasien or penanggung jawab on a canvas pad. Persist both marks on the synthetic encounter with an audit event. Reprint shows the same marks. Keep the existing print chrome banner that already marks every cetak document as pengajaran with synthetic data. Do not add a second "this form is fake" paragraph in the body. Do not call BSrE or any certified TTE. Do not call VClaim. Keep the existing simulated SEP document. Point both Cetak buttons at `selectedPrintDocs`.

**You see.**

- [ ] A registrar opens General Consent and gets the campus hospital form. They sign both blocks. Cetak reprints the signed form. Students practice the real desk path.

**Verify, unit.** Tests alone are not sufficient verification. A PR is verified only when its unit, live, and perf boxes are all checked.

- [ ] `tests/Feature/Outpatient/OutpatientPrintAndRecapTest.php` requests `docs=consent` and asserts the GENERAL CONSENT heading, the Word clauses, and both signature slots. A follow-up test posts two marks and asserts reprint contains both images. Run `php artisan test --compact tests/Feature/Outpatient/OutpatientPrintAndRecapTest.php`.

**Verify, live.** Tests alone are not sufficient verification. A PR is verified only when its unit, live, and perf boxes are all checked. Ten lanes on `grok-4.6-fast-xhigh` at the PR head, per the boot recipe.

- [ ] Lane 1. Regression lane against trunk. Open General Consent at trunk and head. If trunk is the three-line stub, record that and gate the full form plus two captured signatures. Save `pr-c-regression-consent.png`. Pass when head shows the campus form and trunk is recorded as the stub.
- [ ] Lane 2. Register an encounter and click General Consent. Save `pr-c-open-consent.png`. Pass when the heading is GENERAL CONSENT and the Word clauses are on screen.
- [ ] Lane 3. Confirm the existing cetak chrome banner is still present. Save `pr-c-chrome-banner.png`. Pass when the system banner still says pengajaran and synthetic data, and the form body is the real clauses rather than a disclaimer essay.
- [ ] Lane 4. Sign both blocks on the pad and save. Save `pr-c-signed.png`. Pass when both marks render on the form.
- [ ] Lane 5. Reload the form. Save `pr-c-reload-signed.png`. Pass when both marks persist.
- [ ] Lane 6. Confirm a privacy or data-use clause from the Word file is present. Save `pr-c-privacy.png`. Pass when that clause is visible.
- [ ] Lane 7. Print SEP from the same Cetak panel. Save `pr-c-sep.png`. Pass when SEP still prints as the existing simulated document and no VClaim call is made.
- [ ] Lane 8. As a guest, open the print URL. Save `pr-c-guest-denied.png`. Pass when the guest is sent to login.
- [ ] Lane 9. Open consent on a cancelled encounter. Save `pr-c-cancelled.png`. Pass when the app refuses with the existing cancelled-print rule.
- [ ] Lane 10. Tick General Consent, then click table-row Cetak. Save `pr-c-row-cetak-consent.png`. Pass when the row button opens the signed consent as well as bukti, not only `bukti,antrian`.

**Verify, perf.** Tests alone are not sufficient verification. A PR is verified only when its unit, live, and perf boxes are all checked.

- [ ] Metric. Time to open the General Consent form and time to HTML for `GET /pendaftaran/kunjungan/{encounter}/cetak?docs=consent` at trunk and head. Also isolate Blade render of the signed form as the diff-added work.
- [ ] Probe. Open the form and hit the print URL with `control-ui` at trunk and at the head, interleaved, three samples each. Both sides must produce the HTML time.
- [ ] Baseline. Record the trunk HTML time first.
- [ ] Rule. Head HTML time may not exceed trunk HTML time plus 250 ms. Signed-form render work must stay under 120 ms absolute. Fail the PR if either budget breaks.

**Review gate.** The operator reviews before merge.

- [ ] Copy lane 2 and lane 4 screenshots into `/tmp/lecturer-stack-review/pr-c-review-open-consent.png` and `/tmp/lecturer-stack-review/pr-c-review-signed.png`.
- [ ] Record a 30 to 60 second video of opening General Consent, signing both blocks, and reprinting on a lane VM. Save it as `/tmp/lecturer-stack-review/pr-c-review.mp4`.
- [ ] Post the screenshots and the video in chat. Stop at merge-ready. Wait for the operator's click.

**Merge.**

- [ ] Root's clean verdict at the exact head SHA.
- [ ] Bugbot triage done.
- [ ] Rebased onto current trunk after the verdict, patch-id unchanged.
- [ ] The root appends PR-C onto PR-B. The operator lands it bottom-up.

## Close the program

- [ ] Every box above is checked with its evidence.
- [ ] Reply to the operator with the report the execution playbook names. Include the stack root and tip links, a one-line verdict per PR, and anything parked.

## Appendix A. Prototype evidence

No prototype branch was cut. Digit width is a product call. Fuad's example `000212` selected six. Campus policy may still be eight. That stays unproven.

An on-screen signature pad is in PR-C. The operator confirmed the desk must work like a real hospital, not a dummy form.

Code already showed `patients.medical_record_number` is a string, three controllers emit `RM-` plus random, census emits `SYNTH-CENSUS-%03d`, the RJ clinic query loads every active clinic including IGD, and consent is a three-line stub. Those facts did not need a sketch.

Later file hunts confirmed more. Census `updateOrCreate` keys on the MRN, so a new key orphans old rows. Finance snapshots freeze the old string. Recap CSV will let Excel eat leading zeros. `ensureMastersSeeded` no-ops once any clinic exists. RJ store does not reject IGD. Table-row Cetak ignores the consent checkbox. No `docs=consent` test exists yet.

## Appendix B. Alternatives rejected

An integer RM column. It drops leading zeros in PHP and in JS `Number`. Fuad's ask was the character `000212`.

Keep `RM-ymd-random`. Fuad already rejected that shape on the census screen.

Dump all 21 Type B rows into the RJ dropdown, including IGD, radiologi, and anestesi. That mixes emergency and supporting desks into outpatient booking.

A dummy three-line consent stub. Fuad asked for the hospital form. The operator requires the real-desk path.

Certified TTE or a live VClaim call. Patients are synthetic. The form and the ttd are real workflow. The legal certificate and the payer network stay off.

Dual-format RMs, old census kept as `SYNTH-CENSUS-*` beside new six-digit numbers. Students would still see the string Fuad flagged.

## Appendix C. Risks

PR-A. Hosted demo remint of existing patients is out of scope. A local `db:seed` remints synthetic census only. Do not run `DemoActorsSeeder` or unfiltered `migrate --force` on hosted Supabase.

PR-A. Changing the census MRN key without migrating the old row creates a second patient and leaves `SYNTH-CENSUS-*` in the list Fuad already flagged. Finance `medical_record_number_snapshot` stays on the old string unless this PR also rewrites hosted bills, which it must not.

PR-A. `PatientFactory` still emits `RM-` plus random for isolated tests. That is allowed. The HTTP allocate path is the six-digit sequence.

PR-A. Recap CSV of `000212` will become `212` in Excel unless `spreadsheetSafe` forces text.

PR-B. `pstack/` is not in this repository's `origin/main`. Owners must read the Cursor plugin copy of the playbooks. The `git show origin/main:pstack/...` boxes will fail until pstack is vendored. Treat that as a program risk, not a product risk.

PR-B. A long clinic list can hide names below the fold. Lane 10 must scroll.

PR-B. Prepending clinics in `$catalog` changes `OutpatientFlowTest::firstMasterChain` because it takes `orderBy('id')`. Append only.

PR-B. Pemeriksaan, RM, and rekap also load every active clinic. Filter those or IGD returns to the teaching bug on those desks.

PR-C. The form body is the campus hospital document. The existing cetak chrome banner is enough to mark synthetic pengajaran. Do not hollow out the clauses with extra fake-form copy.

PR-C. Captured ttd is an encounter artifact on a synthetic patient, with audit. It is not BSrE and not UU ITE certified TTE. Do not label the pad as sertifikat elektronik.

PR-C. Sidebar Cetak honors flags. Table-row Cetak does not, until this PR. Live lane 2 opens the form. Live lane 10 proves the row button.

PR-C. Control skill `control-ui` lives in `cursor-team-kit`, not in this repo. Live lanes still drive the browser with that skill.

## Appendix D. Links and reading list

Read `app/Support/Registration/DailyQueueAllocator.php` and `tests/Feature/Registration/DailyQueueAllocatorTest.php` before PR-A.

Read `database/seeders/OutpatientMastersSeeder.php`, `OutpatientRegistrationController::index` and `ensureMastersSeeded`, `EmergencyRegistrationController` `code = IGD`, `tests/Feature/Emergency/EmergencyFlowTest.php`, and `OutpatientFlowTest::firstMasterChain` before PR-B.

Read `app/Http/Controllers/Outpatient/OutpatientPrintController.php`, `resources/views/prints/encounter.blade.php`, `PRINTABLE_FLAGS` plus both Cetak handlers in `resources/js/pages/pendaftaran/rawat-jalan.tsx`, and `tests/Feature/Outpatient/OutpatientPrintAndRecapTest.php` before PR-C.

PR-A runs `pstack/skills/how/SKILL.md` on the allocator boundary before the first commit.

PR-C runs `pstack/skills/interrogate/SKILL.md` on storage of the two marks before the first commit.

Each owner keeps `decisions.tsv` per `pstack/skills/show-me-your-work/SKILL.md`. Leave it uncommitted.

Workbook source is `/Users/danielhappyg/Downloads/daftar_poliklinik_rs_tipe_b (1).xlsx`. Word source is `/Users/danielhappyg/Downloads/General Consent Form (1).docx`. Copy them into the seeder and Blade. Do not parse Office files at runtime.
