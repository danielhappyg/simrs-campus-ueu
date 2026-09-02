#!/usr/bin/env ruby
# frozen_string_literal: true

require 'base64'
require 'digest'
require 'json'
require 'open3'
require 'securerandom'
require 'tempfile'
require 'time'

require_relative 'rehearse-local-inpatient-discharge-coding-source-portability'

# Exact disposable PostgreSQL 17/MySQL 8.4 proof for the inpatient RMIK
# manual-coding, completeness-review, and atomic episode-closure node.
class LocalInpatientRmPortabilityRehearsal < LocalInpatientDischargeCodingSourcePortabilityRehearsal
  CONFIRMATION = 'YES_DISPOSABLE_LOCAL_INPATIENT_RM_PORTABILITY'
  CONFIRMATION_ENV = 'SIMRS_INPATIENT_RM_REHEARSAL_CONFIRM'
  SCRIPT_PATH = 'scripts/rehearse-local-inpatient-rm-portability.rb'
  CONTRACT_PATH = 'tests/Documentation/LocalInpatientRmPortabilityHarnessContractTest.rb'
  MIGRATION_PATH = 'database/migrations/2026_08_31_000900_create_inpatient_rm_closure_tables.php'
  FEATURE_PATH = 'tests/Feature/Inpatient/InpatientRmClosureTest.php'
  EVIDENCE_KIND = 'SIMRS_LOCAL_INPATIENT_RMIK_CLOSURE_PORTABILITY'
  HOLD_MS = 3_500
  WAIT_TIMEOUT_SECONDS = 24
  IMMUTABLE_HISTORY_TABLES = %w[
    inpatient_rm_coding_versions
    inpatient_rm_coding_assignments
    inpatient_rm_completeness_reviews
    inpatient_rm_completeness_items
    inpatient_rm_operation_receipts
  ].freeze
  RACE_SCENARIOS = %w[
    concurrent-coding-race
    concurrent-signoff-race
  ].freeze
  SCENARIOS = %w[
    fresh-migration
    empty-down-reapply
    retained-business-row-down-refusal
    source-bound-draft-coding
    normalized-assignment-rows
    current-zero-blocker-review
    atomic-final-signoff-and-closure
    exact-idempotent-replay
    changed-payload-key-conflict
    stale-review-coding-source-denials
    missing-assignment-blocker-denial
    corrupt-receipt-binding-denial
    concurrent-coding-race
    concurrent-signoff-race
    audit-failure-atomic-rollback
    receipt-failure-atomic-rollback
    append-only-engine-refusal
    least-privilege-runtime
    bounded-synthetic-reset
    retained-audit-down-refusal
    invariant-verification
  ].freeze

  SOURCE_PATHS = %w[
    scripts/rehearse-local-inpatient-rm-portability.rb
    scripts/rehearse-local-inpatient-discharge-coding-source-portability.rb
    scripts/rehearse-local-portability-full-suite.rb
    tests/Documentation/LocalInpatientRmPortabilityHarnessContractTest.rb
    tests/Feature/Inpatient/InpatientRmClosureTest.php
    database/migrations/2026_08_31_000900_create_inpatient_rm_closure_tables.php
    app/Support/Inpatient/InpatientRmService.php
    app/Support/Inpatient/InpatientRmActorPolicy.php
    app/Support/Inpatient/InpatientRmDenied.php
    app/Support/Inpatient/InpatientRmAuditUnavailable.php
    app/Support/Inpatient/InpatientRmResult.php
    app/Support/Inpatient/InpatientRmMutationScope.php
    app/Support/Inpatient/InpatientRmSchemaMutationScope.php
    app/Support/Inpatient/InpatientDocumentationSqlWriteGuard.php
    app/Models/InpatientRmCoding.php
    app/Models/InpatientRmCodingVersion.php
    app/Models/InpatientRmCodingAssignment.php
    app/Models/InpatientRmCompletenessReview.php
    app/Models/InpatientRmCompletenessItem.php
    app/Models/InpatientRmOperationReceipt.php
    app/Support/Inpatient/InpatientDischargeService.php
    app/Support/Inpatient/InpatientDischargeCodingSourceService.php
    app/Support/Inpatient/InpatientDischargeCodingSourceEvidenceDigest.php
    app/Support/Inpatient/InpatientDischargeSummaryEvidenceDigest.php
    app/Support/Simulation/SyntheticResetService.php
    app/Support/Audit/AuditRecorder.php
    app/Support/Audit/AuditEventSchemaRegistry.php
    app/Http/Controllers/Inpatient/InpatientRmController.php
    resources/js/pages/rm/rawat-inap/types.ts
    resources/js/pages/rm/rawat-inap/show.tsx
    routes/web.php
  ].freeze

  FORBIDDEN_ENVIRONMENT = LocalInpatientDischargeCodingSourcePortabilityRehearsal::FORBIDDEN_ENVIRONMENT
  IDENTITY_PATTERN = LocalInpatientDischargeCodingSourcePortabilityRehearsal::IDENTITY_PATTERN

  parent_worker = LocalInpatientDischargeCodingSourcePortabilityRehearsal::WORKER_SOURCE
  dispatch_marker = "\n$action = (string) getenv('SIMRS_DISCHARGE_ACTION');"
  raise 'Cannot isolate shared inpatient fixture worker.' unless parent_worker.include?(dispatch_marker)
  shared_worker = parent_worker.split(dispatch_marker, 2).first
  shared_worker = shared_worker.sub(
    "use App\\Models\\InpatientWard;\n",
    <<~'PHP'
      use App\Models\InpatientWard;
      use App\Models\InpatientRmCoding;
      use App\Models\InpatientRmCodingAssignment;
      use App\Models\InpatientRmCodingVersion;
      use App\Models\InpatientRmCompletenessReview;
      use App\Models\InpatientRmCompletenessItem;
      use App\Models\InpatientRmOperationReceipt;
      use App\Models\LabServiceRequest;
    PHP
  ).sub(
    "use App\\Support\\Inpatient\\InpatientMasterService;\n",
    <<~'PHP'
      use App\Support\Inpatient\InpatientMasterService;
      use App\Support\Inpatient\InpatientRmActorPolicy;
      use App\Support\Inpatient\InpatientRmAuditUnavailable;
      use App\Support\Inpatient\InpatientRmDenied;
      use App\Support\Inpatient\InpatientRmMutationScope;
      use App\Support\Inpatient\InpatientRmSchemaMutationScope;
      use App\Support\Inpatient\InpatientRmService;
      use App\Support\Inpatient\InpatientDischargeCodingSourceEvidenceDigest;
      use App\Support\Inpatient\InpatientDischargeSummaryEvidenceDigest;
    PHP
  )

  WORKER_SOURCE = shared_worker + <<~'PHP'

    function rmikReady(string $encounterPublicId, User $physician, string $token, string $suffix): array
    {
        $encounter = Encounter::query()->where('public_id', $encounterPublicId)->firstOrFail();
        $bed = InpatientBed::query()->findOrFail($encounter->inpatient_bed_id);
        app(InpatientDischargeSummaryService::class)->finalize(
            $encounterPublicId, $physician, InpatientDischargeSummary::DEFINITION_VERSION,
            1, 'rmik-summary-final-'.$suffix.'-'.$token, null,
        );
        saveDraft($encounterPublicId, $physician, 0, completeFields('rmik-'.$suffix), 'rmik-source-draft-'.$suffix.'-'.$token);
        finalizeSource($encounterPublicId, $physician, 1, 'rmik-source-final-'.$suffix.'-'.$token);
        app(InpatientDischargeService::class)->execute(
            $encounterPublicId, $physician, 2, 1, $bed->public_id,
            'rmik-discharge-'.$suffix.'-'.$token, null,
        );
        $encounter = Encounter::query()->where('public_id', $encounterPublicId)->firstOrFail();
        $discharge = $encounter->inpatientDischarge()->firstOrFail();
        must($encounter->status === Encounter::STATUS_READY_FOR_RM, 'fixture reached READY_FOR_RM');
        return [
            'source_version_public_id' => $discharge->discharge_coding_source_version_public_id,
            'source_content_digest' => $discharge->discharge_coding_source_content_digest,
            'source_provenance_digest' => $discharge->discharge_coding_source_provenance_digest,
        ];
    }

    function prepareRmikFixture(string $token): array
    {
        $fixture = prepareFixture($token);
        $rmik = User::query()->create([
            'name' => 'Petugas RMIK Portabilitas',
            'email' => 'rmik.'.$token.'@example.invalid',
            'password' => bin2hex(random_bytes(24)),
            'status' => 'ACTIVE',
        ]);
        $rmik->roles()->sync([Role::query()->where('slug', RoleCapabilityMatrix::ROLE_RMIK)->sole()->id]);
        $fixture['rmik'] = $rmik->public_id;
        $physician = userByPublicId($fixture['physician']);
        $targets = [
            'main' => 'main_encounter',
            'replay' => 'replay_encounter',
            'coding_race' => 'final_race_encounter',
            'blocker' => 'source_first_encounter',
            'signoff_race' => 'transfer_first_encounter',
            'audit_failure' => 'audit_failure_encounter',
            'receipt_failure' => 'receipt_failure_encounter',
        ];
        foreach ($targets as $name => $key) {
            $fixture[$name.'_binding'] = rmikReady($fixture[$key], $physician, $token, $name);
        }
        return $fixture;
    }

    function rmikAssignments(array $binding): array
    {
        $sourceVersion = InpatientDischargeCodingSourceVersion::query()
            ->where('public_id', $binding['source_version_public_id'])->sole();
        $rows = [[
            'source_statement_kind' => InpatientRmCodingAssignment::KIND_PRINCIPAL,
            'source_statement_index' => 0,
            'source_statement_text_hash' => hash('sha256', $sourceVersion->principal_diagnosis_statement),
            'code' => 'a09',
            'description' => 'Gastroenteritis manual',
        ]];
        foreach ($sourceVersion->secondary_diagnosis_statements as $index => $statement) {
            $rows[] = [
                'source_statement_kind' => InpatientRmCodingAssignment::KIND_SECONDARY,
                'source_statement_index' => $index,
                'source_statement_text_hash' => hash('sha256', $statement),
                'code' => 'e86',
                'description' => 'Diagnosis sekunder manual',
            ];
        }
        if ($sourceVersion->procedure_attestation !== InpatientDischargeCodingSource::ATTESTATION_NONE) {
            foreach ($sourceVersion->performed_procedure_statements as $index => $statement) {
                $rows[] = [
                    'source_statement_kind' => InpatientRmCodingAssignment::KIND_PROCEDURE,
                    'source_statement_index' => $index,
                    'source_statement_text_hash' => hash('sha256', $statement),
                    'code' => '99.99',
                    'description' => 'Prosedur manual',
                ];
            }
        }
        return $rows;
    }

    function rmikSaveCoding(string $encounter, User $rmik, int $version, array $binding, array $assignments, string $key): mixed
    {
        return app(InpatientRmService::class)->saveCodingDraft(
            $encounter, $rmik, $version,
            $binding['source_version_public_id'], $binding['source_content_digest'],
            $binding['source_provenance_digest'], $assignments, $key, null,
        );
    }

    function rmikSaveReview(string $encounter, User $rmik, int $version, array $snapshot, string $key): mixed
    {
        return app(InpatientRmService::class)->saveReview(
            $encounter, $rmik, $version, $snapshot['source_fingerprint'],
            $snapshot['coding_version'], $snapshot['coding_digest'], $key, null,
        );
    }

    function rmikSignoff(string $encounter, User $rmik, int $version, array $snapshot, string $key): mixed
    {
        return app(InpatientRmService::class)->signoff(
            $encounter, $rmik, $version, $snapshot['source_fingerprint'],
            $snapshot['coding_version'], $snapshot['coding_digest'], $key, null,
        );
    }

    function seedRetainedProbe(array $fixture): array
    {
        $encounter = Encounter::query()->where('public_id', $fixture['cancelled_encounter'])->sole();
        $rmik = userByPublicId($fixture['rmik']);
        $coding = InpatientRmMutationScope::run(fn () => InpatientRmCoding::query()->create([
            'encounter_id' => $encounter->id, 'created_by_user_id' => $rmik->id,
            'definition_version' => InpatientRmCoding::DEFINITION_VERSION,
            'profile' => InpatientRmCoding::PROFILE, 'version' => 1,
            'coding_state' => InpatientRmCoding::STATE_DRAFT,
            'current_is_complete' => false, 'current_content_digest' => hash('sha256', 'retained-probe'),
        ]));
        return ['probe_public_id' => $coding->public_id];
    }

    function clearRetainedProbe(array $fixture): array
    {
        $encounter = Encounter::query()->where('public_id', $fixture['replay_encounter'])->sole();
        InpatientRmMutationScope::run(fn () => DB::table(SchemaQualifier::table('inpatient_rm_codings'))
            ->where('encounter_id', $encounter->id)->delete());
        return ['probe_removed' => true];
    }

    function runRmikSequential(array $fixture, string $token): array
    {
        $rmik = userByPublicId($fixture['rmik']);
        $physician = userByPublicId($fixture['physician']);
        $encounter = $fixture['main_encounter'];
        $binding = $fixture['main_binding'];
        $assignments = rmikAssignments($binding);

        $unauthorized = false;
        try {
            rmikSaveCoding($encounter, $physician, 0, $binding, $assignments, 'rmik-unauthorized-'.$token);
        } catch (AuthorizationException) {
            $unauthorized = true;
        }
        must($unauthorized, 'non-RMIK coding denied');

        $missing = false;
        try {
            rmikSaveCoding($encounter, $rmik, 0, $binding, array_slice($assignments, 0, 1), 'rmik-missing-'.$token);
        } catch (InpatientRmDenied $denial) {
            $missing = $denial->reason === 'assignment_coverage_invalid';
        }
        must($missing, 'missing assignment denied');

        $staleSource = false;
        try {
            $wrong = $binding;
            $wrong['source_content_digest'] = hash('sha256', 'stale-source');
            rmikSaveCoding($encounter, $rmik, 0, $wrong, $assignments, 'rmik-stale-source-'.$token);
        } catch (InpatientRmDenied $denial) {
            $staleSource = $denial->reason === 'source_binding_stale';
        }
        must($staleSource, 'stale source denied');

        $draft = rmikSaveCoding($encounter, $rmik, 0, $binding, $assignments, 'rmik-main-draft-'.$token);
        must(! $draft->replayed, 'Draft applied');
        must($draft->coding->coding_state === InpatientRmCoding::STATE_DRAFT, 'coding head Draft');
        must($draft->codingVersion->coding_state === InpatientRmCoding::STATE_DRAFT, 'coding version Draft');
        must($draft->codingVersion->assignments->count() === count($assignments), 'normalized assignment row count');
        must($draft->codingVersion->assignments->first()->normalized_code === 'A09', 'code normalized uppercase');
        must($draft->codingVersion->assignments->pluck('ordinal')->all() === range(1, count($assignments)), 'normalized ordinals closed');
        must($draft->codingVersion->content_digest === $draft->coding->current_content_digest, 'Draft content digest bound');

        $draftReplay = rmikSaveCoding($encounter, $rmik, 0, $binding, $assignments, 'RMIK-MAIN-DRAFT-'.$token);
        must($draftReplay->replayed, 'Draft exact replay');
        must($draftReplay->codingVersion->public_id === $draft->codingVersion->public_id, 'Draft replay exact version');

        $changed = false;
        try {
            $changedAssignments = $assignments;
            $changedAssignments[0]['description'] = 'Changed display';
            rmikSaveCoding($encounter, $rmik, 0, $binding, $changedAssignments, 'rmik-main-draft-'.$token);
        } catch (InpatientRmDenied $denial) {
            $changed = $denial->reason === 'idempotency_key_conflict';
        }
        must($changed, 'changed payload key conflict');

        $staleCoding = false;
        try {
            rmikSaveCoding($encounter, $rmik, 0, $binding, $assignments, 'rmik-stale-coding-'.$token);
        } catch (InpatientRmDenied $denial) {
            $staleCoding = $denial->reason === 'stale_coding_version';
        }
        must($staleCoding, 'stale coding version denied');

        $snapshot = app(InpatientRmService::class)->snapshot(Encounter::query()->where('public_id', $encounter)->sole());
        must($snapshot['blockers'] === [], 'current snapshot zero blockers');
        must($snapshot['coding_digest'] === $draft->codingVersion->content_digest, 'snapshot exposes content digest');
        $review = rmikSaveReview($encounter, $rmik, 0, $snapshot, 'rmik-main-review-'.$token);
        must($review->review->review_state === InpatientRmCompletenessReview::STATE_DRAFT, 'current Draft review');
        must($review->review->items->count() === 7 && $review->review->blocker_count === 0, 'seven-item zero-blocker review');

        $staleReview = false;
        try {
            rmikSaveReview($encounter, $rmik, 0, $snapshot, 'rmik-stale-review-'.$token);
        } catch (InpatientRmDenied $denial) {
            $staleReview = $denial->reason === 'stale_review_version';
        }
        must($staleReview, 'stale review denied');

        $signoff = rmikSignoff($encounter, $rmik, 1, $snapshot, 'rmik-main-signoff-'.$token);
        $closed = Encounter::query()->where('public_id', $encounter)->sole();
        $finalCoding = InpatientRmCoding::query()->where('encounter_id', $closed->id)->sole();
        $finalVersion = $finalCoding->versions()->where('coding_state', InpatientRmCoding::STATE_FINAL)->sole();
        must($closed->status === Encounter::STATUS_CLOSED, 'READY_FOR_RM atomically closed');
        must($finalCoding->coding_state === InpatientRmCoding::STATE_FINAL, 'coding head Final');
        must($signoff->review->review_state === InpatientRmCompletenessReview::STATE_SIGNED_OFF, 'review Signed Off');
        must($signoff->review->inpatient_rm_coding_version_id === $finalVersion->id, 'signoff review binds exact Final coding version');
        must($finalVersion->content_digest === $draft->codingVersion->content_digest, 'Draft to Final content digest stable');
        must($finalVersion->version === $draft->codingVersion->version + 1, 'Final append increments version');
        must($finalVersion->assignments->count() === $draft->codingVersion->assignments->count(), 'Final assignment copy complete');

        $signoffReplay = rmikSignoff($encounter, $rmik, 1, $snapshot, 'RMIK-MAIN-SIGNOFF-'.$token);
        must($signoffReplay->replayed, 'signoff exact replay');
        must($signoffReplay->review->public_id === $signoff->review->public_id, 'signoff replay exact review');
        $changedSignoff = false;
        try {
            rmikSignoff($encounter, $rmik, 2, $snapshot, 'rmik-main-signoff-'.$token);
        } catch (InpatientRmDenied $denial) {
            $changedSignoff = $denial->reason === 'idempotency_key_conflict';
        }
        must($changedSignoff, 'changed signoff payload key conflict');

        $codingReceipt = InpatientRmOperationReceipt::query()->where('operation', InpatientRmOperationReceipt::OPERATION_CODING_SAVE)->where('encounter_id', $closed->id)->sole();
        $reviewReceipt = InpatientRmOperationReceipt::query()->where('operation', InpatientRmOperationReceipt::OPERATION_REVIEW_SAVE)->where('encounter_id', $closed->id)->sole();
        $signoffReceipt = InpatientRmOperationReceipt::query()->where('operation', InpatientRmOperationReceipt::OPERATION_SIGNOFF)->where('encounter_id', $closed->id)->sole();
        must($codingReceipt->inpatient_rm_completeness_review_id === null, 'coding receipt review FK null');
        must($reviewReceipt->inpatient_rm_completeness_review_id === $review->review->id, 'review receipt exact review FK');
        must($signoffReceipt->inpatient_rm_completeness_review_id === $signoff->review->id, 'signoff receipt exact review FK');
        must($signoffReceipt->coding_version === $finalVersion->version, 'signoff receipt Final coding version');
        must($signoffReceipt->coding_digest === $finalVersion->content_digest, 'signoff receipt stable content digest');
        must($signoffReceipt->payload_digest !== $signoffReceipt->coding_digest, 'operation payload digest distinct from content digest');

        $blockerEncounter = Encounter::query()->where('public_id', $fixture['source_first_encounter'])->sole();
        $blockerAssignments = rmikAssignments($fixture['blocker_binding']);
        rmikSaveCoding($fixture['source_first_encounter'], $rmik, 0, $fixture['blocker_binding'], $blockerAssignments, 'rmik-blocker-coding-'.$token);
        LabServiceRequest::query()->create([
            'encounter_id' => $blockerEncounter->id, 'requested_by_user_id' => $physician->id,
            'test_code' => 'HB', 'test_label' => 'Hemoglobin',
            'clinical_question' => 'Blocker rehearsal', 'status' => LabServiceRequest::STATUS_ACTIVE,
            'requested_at' => now(),
        ]);
        $blockerSnapshot = app(InpatientRmService::class)->snapshot($blockerEncounter->fresh());
        must($blockerSnapshot['blockers'] === ['NO_ACTIVE_LAB_ORDERS'], 'active lab is exact blocker');
        rmikSaveReview($fixture['source_first_encounter'], $rmik, 0, $blockerSnapshot, 'rmik-blocker-review-'.$token);
        $blockerDenied = false;
        try {
            rmikSignoff($fixture['source_first_encounter'], $rmik, 1, $blockerSnapshot, 'rmik-blocker-signoff-'.$token);
        } catch (InpatientRmDenied $denial) {
            $blockerDenied = in_array($denial->reason, ['review_not_current_complete', 'checklist_incomplete'], true);
        }
        must($blockerDenied, 'blocker prevents review/signoff progression');

        foreach (['coding_race' => 'final_race_encounter', 'signoff_race' => 'transfer_first_encounter'] as $name => $fixtureKey) {
            $raceAssignments = rmikAssignments($fixture[$name.'_binding']);
            $raceDraft = rmikSaveCoding($fixture[$fixtureKey], $rmik, 0, $fixture[$name.'_binding'], $raceAssignments, 'rmik-race-setup-'.$name.'-'.$token);
            if ($name === 'signoff_race') {
                $raceSnapshot = app(InpatientRmService::class)->snapshot(Encounter::query()->where('public_id', $fixture[$fixtureKey])->sole());
                rmikSaveReview($fixture[$fixtureKey], $rmik, 0, $raceSnapshot, 'rmik-race-review-'.$token);
                $fixture['signoff_race_snapshot'] = $raceSnapshot;
            }
            $fixture[$name.'_coding_version'] = $raceDraft->codingVersion->version;
        }

        return [
            'source_bound_draft' => true,
            'normalized_assignment_rows' => true,
            'current_zero_blocker_review' => true,
            'atomic_final_signoff_and_closure' => true,
            'exact_idempotent_replay' => true,
            'changed_payload_key_conflict' => true,
            'stale_review_coding_source_denials' => true,
            'missing_assignment_blocker_denial' => true,
            'draft_content_digest' => $draft->codingVersion->content_digest,
            'final_content_digest' => $finalVersion->content_digest,
            'final_coding_version' => $finalVersion->version,
            'main_coding_public_id' => $finalCoding->public_id,
            'fixture_updates' => $fixture,
        ];
    }

    function installRmikReceiptFailureTrigger(): void
    {
        InpatientRmSchemaMutationScope::run(function (): void {
            $table = DB::connection()->getQueryGrammar()->wrapTable(SchemaQualifier::table('inpatient_rm_operation_receipts'));
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement("CREATE OR REPLACE FUNCTION laravel.fail_irmor_insert() RETURNS trigger LANGUAGE plpgsql AS \$f\$ BEGIN RAISE EXCEPTION 'receipt insertion unavailable'; END; \$f\$");
                DB::statement("CREATE TRIGGER irmor_fail_insert_trg BEFORE INSERT ON {$table} FOR EACH ROW EXECUTE FUNCTION laravel.fail_irmor_insert()");
            } else {
                DB::statement("CREATE TRIGGER irmor_fail_insert_trg BEFORE INSERT ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='receipt insertion unavailable'");
            }
        });
    }

    function dropRmikReceiptFailureTrigger(): void
    {
        InpatientRmSchemaMutationScope::run(function (): void {
            $table = DB::connection()->getQueryGrammar()->wrapTable(SchemaQualifier::table('inpatient_rm_operation_receipts'));
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement("DROP TRIGGER IF EXISTS irmor_fail_insert_trg ON {$table}");
                DB::statement('DROP FUNCTION IF EXISTS laravel.fail_irmor_insert()');
            } else {
                DB::statement('DROP TRIGGER IF EXISTS irmor_fail_insert_trg');
            }
        });
    }

    function runRmikAtomicFailures(array $fixture, string $token): array
    {
        $rmik = userByPublicId($fixture['rmik']);
        $successAuditRowsBefore = AuditEvent::query()
            ->where('action', 'rmik.inpatient.coding.draft.save')->where('outcome', 'SUCCESS')->count();
        $nullAudit = new class extends AuditRecorder {
            public function record(string $action, string $resourceType, ?string $resourceId = null, ?User $actor = null, string $outcome = 'SUCCESS', ?string $reason = null, array $metadata = [], ?Request $request = null, bool $includeRequestFingerprint = true): ?AuditEvent { return null; }
        };
        $service = new InpatientRmService(
            $nullAudit, app(InpatientRmActorPolicy::class),
            app(InpatientDischargeCodingSourceEvidenceDigest::class),
            app(InpatientDischargeSummaryEvidenceDigest::class),
        );
        $auditFailed = false;
        try {
            $binding = $fixture['audit_failure_binding'];
            $service->saveCodingDraft($fixture['audit_failure_encounter'], $rmik, 0, $binding['source_version_public_id'], $binding['source_content_digest'], $binding['source_provenance_digest'], rmikAssignments($binding), 'rmik-audit-failure-'.$token, null);
        } catch (InpatientRmAuditUnavailable) {
            $auditFailed = true;
        }
        must($auditFailed, 'audit failure surfaced');

        installRmikReceiptFailureTrigger();
        $receiptFailed = false;
        try {
            $binding = $fixture['receipt_failure_binding'];
            rmikSaveCoding($fixture['receipt_failure_encounter'], $rmik, 0, $binding, rmikAssignments($binding), 'rmik-receipt-failure-'.$token);
        } catch (Throwable) {
            $receiptFailed = true;
        } finally {
            dropRmikReceiptFailureTrigger();
        }
        must($receiptFailed, 'receipt failure surfaced');
        $failedEncounterIds = Encounter::query()->whereIn('public_id', [$fixture['audit_failure_encounter'], $fixture['receipt_failure_encounter']])->pluck('id');
        must(InpatientRmCoding::query()->whereIn('encounter_id', $failedEncounterIds)->doesntExist(), 'failure coding rows rolled back');
        must(InpatientRmOperationReceipt::query()->whereIn('encounter_id', $failedEncounterIds)->doesntExist(), 'failure receipts rolled back');
        must(AuditEvent::query()->where('action', 'rmik.inpatient.coding.draft.save')->where('outcome', 'SUCCESS')->count() === $successAuditRowsBefore, 'failure success audits rolled back');
        return ['audit_failure_atomic_rollback' => true, 'receipt_failure_atomic_rollback' => true, 'failure_business_rows' => 0];
    }

    function insertCorruptRmikReceipt(array $fixture, string $token): array
    {
        $rmik = userByPublicId($fixture['rmik']);
        $binding = $fixture['replay_binding'];
        $assignments = rmikAssignments($binding);
        $encounter = Encounter::query()->where('public_id', $fixture['replay_encounter'])->sole();
        $mainVersion = InpatientRmCodingVersion::query()->firstOrFail();
        $sourceVersion = InpatientDischargeCodingSourceVersion::query()->where('public_id', $binding['source_version_public_id'])->sole();
        $payload = hash('sha256', App\Support\CanonicalJson::encode([
            'encounter_public_id' => $fixture['replay_encounter'], 'expected_version' => 0,
            'source_version_public_id' => $binding['source_version_public_id'],
            'source_content_digest' => $binding['source_content_digest'],
            'source_provenance_digest' => $binding['source_provenance_digest'],
            'assignments' => $assignments,
        ]));
        InpatientRmMutationScope::run(fn () => InpatientRmOperationReceipt::query()->create([
            'encounter_id' => $encounter->id, 'actor_user_id' => $rmik->id,
            'inpatient_discharge_coding_source_version_id' => $sourceVersion->id,
            'inpatient_rm_coding_version_id' => $mainVersion->id,
            'inpatient_rm_completeness_review_id' => null,
            'operation' => InpatientRmOperationReceipt::OPERATION_CODING_SAVE,
            'idempotency_key' => 'rmik-corrupt-'.$token, 'payload_digest' => $payload,
            'source_version_public_id' => $binding['source_version_public_id'],
            'source_content_digest' => $binding['source_content_digest'],
            'source_provenance_digest' => $binding['source_provenance_digest'],
            'coding_public_id' => $mainVersion->coding->public_id,
            'coding_version' => $mainVersion->version, 'coding_digest' => $mainVersion->content_digest,
            'result_type' => 'CODING', 'result_public_id' => (string) Str::ulid(), 'result_version' => 99,
            'request_correlation_id' => null, 'completed_at' => now(),
        ]));
        $denied = false;
        try {
            rmikSaveCoding($fixture['replay_encounter'], $rmik, 0, $binding, $assignments, 'rmik-corrupt-'.$token);
        } catch (InpatientRmDenied $denial) {
            $denied = $denial->reason === 'receipt_binding_invalid';
        }
        must($denied, 'corrupt receipt binding denied');
        return ['corrupt_receipt_binding_denial' => true];
    }

    function runRmikAppendOnly(array $fixture): array
    {
        $tables = [
            'versions' => 'inpatient_rm_coding_versions', 'assignments' => 'inpatient_rm_coding_assignments',
            'reviews' => 'inpatient_rm_completeness_reviews', 'items' => 'inpatient_rm_completeness_items',
            'receipts' => 'inpatient_rm_operation_receipts',
        ];
        $refused = [];
        foreach ($tables as $label => $name) {
            $table = SchemaQualifier::table($name);
            $id = DB::table($table)->value('id');
            foreach (['update', 'delete'] as $operation) {
                try {
                    $operation === 'update'
                        ? DB::table($table)->where('id', $id)->update(['id' => $id])
                        : DB::table($table)->where('id', $id)->delete();
                } catch (Throwable) {
                    $refused[] = $label.'_'.$operation;
                }
            }
            if (DB::connection()->getDriverName() === 'pgsql') {
                try { DB::statement('TRUNCATE TABLE '.DB::connection()->getQueryGrammar()->wrapTable($table)); }
                catch (Throwable) { $refused[] = $label.'_truncate'; }
            }
        }
        $expected = count($tables) * (DB::connection()->getDriverName() === 'pgsql' ? 3 : 2);
        must(count($refused) === $expected, 'all immutable SQL mutations refused');
        return ['sql_refusal_count' => count($refused), 'sql_refusals' => $refused];
    }

    function runRmikRace(array $fixture, string $scenario, string $worker, string $token, int $holdMs): array
    {
        $rmik = userByPublicId($fixture['rmik']);
        $operation = function () use ($fixture, $scenario, $worker, $token, $rmik): array {
            try {
                if ($scenario === 'concurrent-coding-race') {
                    $binding = $fixture['coding_race_binding'];
                    $result = rmikSaveCoding($fixture['final_race_encounter'], $rmik, 1, $binding, rmikAssignments($binding), 'rmik-coding-race-'.$token);
                    return ['outcome' => $result->replayed ? 'REPLAYED' : 'APPLIED', 'version' => $result->codingVersion->version];
                }
                $snapshot = $fixture['signoff_race_snapshot'];
                $result = rmikSignoff($fixture['transfer_first_encounter'], $rmik, 1, $snapshot, 'rmik-signoff-race-'.$token);
                return ['outcome' => $result->replayed ? 'REPLAYED' : 'APPLIED', 'version' => $result->review->version];
            } catch (InpatientRmDenied $denial) {
                return ['outcome' => 'DENIED', 'reason' => $denial->reason];
            }
        };
        if ($holdMs > 0) {
            return DB::transaction(function () use ($operation, $holdMs): array {
                $result = $operation();
                protocol('HOLDING');
                usleep($holdMs * 1000);
                return $result;
            });
        }
        return $operation();
    }

    function verifyRmikRace(array $fixture, string $scenario): array
    {
        $key = $scenario === 'concurrent-coding-race' ? 'final_race_encounter' : 'transfer_first_encounter';
        $encounter = Encounter::query()->where('public_id', $fixture[$key])->sole();
        if ($scenario === 'concurrent-coding-race') {
            $coding = InpatientRmCoding::query()->where('encounter_id', $encounter->id)->sole();
            must($coding->version === 2 && $coding->coding_state === InpatientRmCoding::STATE_DRAFT, 'one competing coding append');
            return ['one_coding_append' => true, 'coding_version' => 2];
        }
        $coding = InpatientRmCoding::query()->where('encounter_id', $encounter->id)->sole();
        $signed = InpatientRmCompletenessReview::query()->where('encounter_id', $encounter->id)->where('review_state', InpatientRmCompletenessReview::STATE_SIGNED_OFF)->sole();
        must($encounter->status === Encounter::STATUS_CLOSED && $coding->coding_state === InpatientRmCoding::STATE_FINAL, 'one atomic terminal signoff');
        return ['one_atomic_signoff' => true, 'signed_review_version' => $signed->version];
    }

    function verifyRmikInvariants(): array
    {
        $duplicateHeads = InpatientRmCoding::query()->select('encounter_id')->groupBy('encounter_id')->havingRaw('COUNT(*) > 1')->count();
        $orphanAssignments = InpatientRmCodingAssignment::query()->whereDoesntHave('codingVersion')->count();
        $signed = InpatientRmCompletenessReview::query()->where('review_state', InpatientRmCompletenessReview::STATE_SIGNED_OFF)->count();
        must($duplicateHeads === 0 && $orphanAssignments === 0 && $signed >= 2, 'durable RMIK invariants');
        return ['duplicate_coding_heads' => 0, 'orphan_assignments' => 0, 'signed_off_reviews' => $signed, 'durable_third_connection_assertions' => true];
    }

    function resetRmikFixture(array $fixture): array
    {
        app(SyntheticResetService::class)->reset([
            'actor' => userByPublicId($fixture['admin']),
            'reason' => 'bounded_inpatient_rmik_portability_reset',
        ]);
        foreach (['inpatient_rm_codings', 'inpatient_rm_coding_versions', 'inpatient_rm_coding_assignments', 'inpatient_rm_completeness_reviews', 'inpatient_rm_completeness_items', 'inpatient_rm_operation_receipts'] as $table) {
            must(DB::table(SchemaQualifier::table($table))->count() === 0, $table.' reset');
        }
        must(AuditEvent::query()->where('action', 'like', 'rmik.inpatient.%')->exists(), 'RMIK audit retained');
        must(AuditEvent::query()->where('action', 'teaching.reset.completed')->exists(), 'reset audit retained');
        return ['bounded_synthetic_reset' => true, 'rmik_rows_removed' => true, 'rmik_audit_retained' => true];
    }

    $action = (string) getenv('SIMRS_RMIK_ACTION');
    $scenario = (string) getenv('SIMRS_RMIK_SCENARIO');
    $worker = (string) getenv('SIMRS_RMIK_WORKER');
    $token = (string) getenv('SIMRS_RMIK_RUN_TOKEN');
    $holdMs = (int) getenv('SIMRS_RMIK_HOLD_MS');
    try {
        $root = realpath((string) getenv('SIMRS_REHEARSAL_ROOT'));
        if ($root === false || ! is_file($root.'/artisan')) { throw new RuntimeException('repository root refused'); }
        require $root.'/vendor/autoload.php';
        $app = require $root.'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        must(config('simulation.mode') === 'SIMULATION' && config('simulation.synthetic_only') === true, 'synthetic simulation boundary');
        must(InpatientRmCoding::DEFINITION_VERSION === 'INPATIENT_RM_MANUAL_CODING_V1', 'exact coding definition');
        must(InpatientRmCompletenessReview::DEFINITION_VERSION === 'INPATIENT_RM_COMPLETENESS_V1', 'exact review definition');
        must(InpatientRmCoding::STATE_DRAFT === 'DRAFT' && InpatientRmCoding::STATE_FINAL === 'FINAL', 'exact coding states');
        must(InpatientRmOperationReceipt::OPERATION_CODING_SAVE === 'INPATIENT_RM_CODING_DRAFT_SAVE', 'exact coding operation');
        must(InpatientRmOperationReceipt::OPERATION_REVIEW_SAVE === 'INPATIENT_RM_COMPLETENESS_REVIEW_SAVE', 'exact review operation');
        must(InpatientRmOperationReceipt::OPERATION_SIGNOFF === 'INPATIENT_RM_EPISODE_SIGNOFF', 'exact signoff operation');
        foreach (['BPJS_INTEGRATION_ENABLED', 'VCLAIM_ENABLED', 'SATUSEHAT_ENABLED'] as $flag) { must(getenv($flag) === 'false', $flag.' disabled'); }
        protocol('STARTED', ['backend_connection_id' => backendConnectionId()]);
        $fixture = fixture();
        $result = match ($action) {
            'prepare' => ['fixture' => prepareRmikFixture($token)],
            'seed_probe' => seedRetainedProbe($fixture),
            'clear_probe' => clearRetainedProbe($fixture),
            'sequential' => runRmikSequential($fixture, $token),
            'atomic_failures' => runRmikAtomicFailures($fixture, $token),
            'corrupt_binding' => insertCorruptRmikReceipt($fixture, $token),
            'append_only' => runRmikAppendOnly($fixture),
            'owner_append_only' => runRmikAppendOnly($fixture),
            'race' => runRmikRace($fixture, $scenario, $worker, $token, $holdMs),
            'verify_race' => verifyRmikRace($fixture, $scenario),
            'verify' => verifyRmikInvariants(),
            'reset' => resetRmikFixture($fixture),
            default => throw new RuntimeException('unsupported RMIK action'),
        };
        protocol('COMMITTED', $action === 'prepare' ? $result : ['result' => $result] + $result);
    } catch (Throwable $exception) {
        echo json_encode([
            'schema_version' => 1, 'status' => 'BLOCKED', 'protocol_state' => 'FAILED',
            'scenario' => $scenario, 'worker' => $worker,
            'exception_class' => get_class($exception),
            'exception_fingerprint' => hash('sha256', get_class($exception)."\0".$exception->getMessage()),
            'sql_state' => $exception instanceof \Illuminate\Database\QueryException ? (string) ($exception->errorInfo[0] ?? '') : '',
            'driver_code' => $exception instanceof \Illuminate\Database\QueryException ? (string) ($exception->errorInfo[1] ?? '') : '',
            'query_head' => $exception instanceof \Illuminate\Database\QueryException
                ? mb_substr((string) preg_replace('/\s+/', ' ', $exception->getSql()), 0, 240)
                : mb_substr((string) preg_replace('/\s+/', ' ', $exception->getMessage()), 0, 240),
            'failure_stage' => 'rmik-worker',
        ], JSON_THROW_ON_ERROR).PHP_EOL;
        exit(1);
    }
  PHP

  def initialize(engine:, environment: ENV.to_h, runner: Runner.new, monotonic_clock: nil)
    @operator_environment = environment.dup
    LocalPortabilityFullSuiteRehearsal.instance_method(:initialize).bind(self).call(
      engine: engine,
      environment: environment.merge('SIMRS_PORTABILITY_REHEARSAL_CONFIRM' => LocalPortabilityFullSuiteRehearsal::CONFIRMATION),
      runner: runner,
      monotonic_clock: monotonic_clock
    )
    @workers = []
    @command_catalog = []
    @result_catalog = []
    @protocol_catalog = []
    @worker_tempfile = nil
    @runtime_application_environment = nil
    @reset_application_environment = nil
  end

  def run!
    assert_contract!
    bindings = current_rmik_bindings
    engine_binding = prepare_engine!
    @run_token = database_run_token
    create_worker_file!
    started = @clock.call
    recorded_artisan!('migrate:fresh', '--force', '--no-interaction')
    migration_duration_ms = elapsed_ms(started)
    recorded_artisan!('db:seed', '--class=Database\\Seeders\\RbacSeeder', '--force', '--no-interaction')
    recorded_artisan!('migrate:rollback', '--path='+MIGRATION_PATH, '--force', '--no-interaction')
    recorded_artisan!('migrate', '--path='+MIGRATION_PATH, '--force', '--no-interaction')
    prepared = run_worker_command!(action: 'prepare', scenario: 'fresh-migration', worker: 'PREPARE')
    fixture = prepared.fetch('fixture')
    run_worker_command!(action: 'seed_probe', scenario: 'retained-business-row-down-refusal', worker: 'PROBE', fixture: fixture)
    row_refusal = expect_rmik_rollback_refusal!('retained business evidence exists')
    failures = run_worker_command!(action: 'atomic_failures', scenario: 'audit-failure-atomic-rollback', worker: 'FAILURES', fixture: fixture)
    provision_runtime_identities!
    sequential = run_worker_command!(action: 'sequential', scenario: 'source-bound-draft-coding', worker: 'SEQUENTIAL', fixture: fixture)
    fixture = sequential.fetch('fixture_updates')
    corrupt = run_worker_command!(action: 'corrupt_binding', scenario: 'corrupt-receipt-binding-denial', worker: 'CORRUPT', fixture: fixture)
    append_only = run_worker_command!(action: 'append_only', scenario: 'append-only-engine-refusal', worker: 'APPEND_ONLY', fixture: fixture)
    owner_append_only = run_worker_command!(action: 'owner_append_only', scenario: 'append-only-engine-refusal', worker: 'OWNER_APPEND_ONLY', fixture: fixture, connection_environment: application_environment)
    races = RACE_SCENARIOS.to_h { |scenario| [scenario, run_rmik_race!(fixture, scenario)] }
    invariants = run_worker_command!(action: 'verify', scenario: 'invariant-verification', worker: 'VERIFY', fixture: fixture)
    reset = run_worker_command!(action: 'reset', scenario: 'bounded-synthetic-reset', worker: 'RESET', fixture: fixture, connection_environment: @reset_application_environment)
    audit_refusal = expect_rmik_rollback_refusal!('correlated audit evidence remains')
    scenarios = {
      'fresh-migration' => { 'status' => 'PASS', 'fresh_apply' => true },
      'empty-down-reapply' => { 'status' => 'PASS', 'empty_down' => true, 'reapply' => true },
      'retained-business-row-down-refusal' => { 'status' => 'PASS', 'refusal' => row_refusal },
      'source-bound-draft-coding' => slice_result(sequential, %w[source_bound_draft draft_content_digest]),
      'normalized-assignment-rows' => slice_result(sequential, %w[normalized_assignment_rows]),
      'current-zero-blocker-review' => slice_result(sequential, %w[current_zero_blocker_review]),
      'atomic-final-signoff-and-closure' => slice_result(sequential, %w[atomic_final_signoff_and_closure draft_content_digest final_content_digest final_coding_version]),
      'exact-idempotent-replay' => slice_result(sequential, %w[exact_idempotent_replay]),
      'changed-payload-key-conflict' => slice_result(sequential, %w[changed_payload_key_conflict]),
      'stale-review-coding-source-denials' => slice_result(sequential, %w[stale_review_coding_source_denials]),
      'missing-assignment-blocker-denial' => slice_result(sequential, %w[missing_assignment_blocker_denial]),
      'corrupt-receipt-binding-denial' => slice_result(corrupt, %w[corrupt_receipt_binding_denial]),
      'concurrent-coding-race' => races.fetch('concurrent-coding-race'),
      'concurrent-signoff-race' => races.fetch('concurrent-signoff-race'),
      'audit-failure-atomic-rollback' => slice_result(failures, %w[audit_failure_atomic_rollback failure_business_rows]),
      'receipt-failure-atomic-rollback' => slice_result(failures, %w[receipt_failure_atomic_rollback failure_business_rows]),
      'append-only-engine-refusal' => {
        'status' => 'PASS',
        'runtime_sql_refusal_count' => append_only.fetch('sql_refusal_count'),
        'runtime_sql_refusals' => append_only.fetch('sql_refusals'),
        'owner_trigger_refusal_count' => owner_append_only.fetch('sql_refusal_count'),
        'owner_trigger_refusals' => owner_append_only.fetch('sql_refusals'),
      },
      'least-privilege-runtime' => { 'status' => 'PASS', 'immutable_history_grants' => 'SELECT_INSERT_ONLY', 'reset_identity_separate' => true },
      'bounded-synthetic-reset' => slice_result(reset, %w[bounded_synthetic_reset rmik_rows_removed rmik_audit_retained]),
      'retained-audit-down-refusal' => { 'status' => 'PASS', 'refusal' => audit_refusal },
      'invariant-verification' => slice_result(invariants, %w[duplicate_coding_heads orphan_assignments signed_off_reviews durable_third_connection_assertions]),
    }
    raise CommandFailed, 'Inpatient RMIK scenario catalogue drifted.' unless scenarios.keys == SCENARIOS
    assert_unchanged_binding!('Inpatient RMIK execution bindings', bindings, current_rmik_bindings)
    cleanup!(strict: true)
    remove_worker_file!
    evidence_path = write_rmik_evidence!(bindings: bindings, engine_binding: engine_binding, migration_duration_ms: migration_duration_ms, scenarios: scenarios)
    { 'status' => 'PASS', 'claim' => 'LOCAL_DISPOSABLE_INPATIENT_RMIK_CLOSURE_ONLY', 'engine' => @engine, 'scenario_count' => scenarios.length, 'evidence_path' => evidence_path }
  ensure
    terminate_workers!
    remove_worker_file!
    cleanup!
  end

  def assert_contract!
    unless @operator_environment[CONFIRMATION_ENV] == CONFIRMATION
      raise CommandFailed, "Set #{CONFIRMATION_ENV}=#{CONFIRMATION} to authorize the disposable local rehearsal."
    end
    rejected = FORBIDDEN_ENVIRONMENT.select { |name| !@operator_environment.fetch(name, '').to_s.strip.empty? }
    raise CommandFailed, "Inpatient RMIK rehearsal refuses inherited overrides: #{rejected.join(', ')}." unless rejected.empty?
    LocalPortabilityFullSuiteRehearsal.instance_method(:assert_contract!).bind(self).call
    SOURCE_PATHS.each { |path| safe_source_path(path) }
    raise CommandFailed, 'Inpatient RMIK catalogue must contain exactly twenty-one unique scenarios.' unless SCENARIOS.length == 21 && SCENARIOS.uniq.length == 21
    raise CommandFailed, 'Embedded inpatient RMIK worker source is unexpectedly small.' unless WORKER_SOURCE.bytesize > 45_000
  end

  def application_environment
    LocalPortabilityFullSuiteRehearsal.instance_method(:application_environment).bind(self).call.merge(
      CONFIRMATION_ENV => CONFIRMATION,
      'BPJS_INTEGRATION_ENABLED' => 'false', 'VCLAIM_ENABLED' => 'false', 'SATUSEHAT_ENABLED' => 'false'
    )
  end

  def current_rmik_bindings
    files = SOURCE_PATHS.to_h { |path| [path, Digest::SHA256.file(safe_source_path(path)).hexdigest] }
    {
      'files' => files,
      'aggregate_sha256' => Digest::SHA256.hexdigest(JSON.generate(files.sort.to_h)),
      'worker_source_sha256' => Digest::SHA256.hexdigest(WORKER_SOURCE),
      'scenario_catalog_sha256' => Digest::SHA256.hexdigest(JSON.generate(SCENARIOS)),
    }
  end

  private

  def create_worker_file!
    @worker_tempfile = Tempfile.new(['simrs-inpatient-rmik-', '.php'])
    @worker_tempfile.binmode
    @worker_tempfile.write(WORKER_SOURCE)
    @worker_tempfile.flush
    @worker_tempfile.chmod(0o600)
    @worker_tempfile.close
  end

  def worker_environment(action:, scenario:, worker:, fixture:, hold_ms:, connection_environment: nil)
    (connection_environment || @runtime_application_environment || application_environment).merge(
      'SIMRS_REHEARSAL_ROOT' => ROOT,
      'SIMRS_RMIK_ACTION' => action, 'SIMRS_RMIK_SCENARIO' => scenario,
      'SIMRS_RMIK_WORKER' => worker, 'SIMRS_RMIK_RUN_TOKEN' => @run_token,
      'SIMRS_RMIK_HOLD_MS' => hold_ms.to_s,
      'SIMRS_RMIK_FIXTURE' => Base64.strict_encode64(JSON.generate(fixture || {})),
      'SIMRS_DISCHARGE_SCENARIO' => scenario, 'SIMRS_DISCHARGE_WORKER' => worker,
      'SIMRS_DISCHARGE_FIXTURE' => Base64.strict_encode64(JSON.generate(fixture || {}))
    )
  end

  def database_run_token
    database = @engine == 'postgresql17' ? @postgres_database : @mysql_database
    database.to_s[/[0-9a-f]{12}\z/] || raise(CommandFailed, 'Disposable database lacks a run-token binding.')
  end

  def expect_rmik_rollback_refusal!(expected)
    arguments = ['migrate:rollback', '--path='+MIGRATION_PATH, '--force', '--no-interaction']
    @command_catalog << ['artisan', *arguments]
    stdout, stderr, status = Open3.capture3(@runner.process_environment(application_environment), @php_binary, File.join(ROOT, 'artisan'), *arguments, unsetenv_others: true)
    raise CommandFailed, 'Populated inpatient RMIK migration unexpectedly rolled back.' if status.success?
    combined = stdout + stderr
    unless combined.gsub(/\s+/, '').include?(expected.gsub(/\s+/, ''))
      head = combined.lines.first(20).join
      tail = combined.lines.last(20).join
      raise CommandFailed, "Rollback failed for an unexpected reason: #{@runner.sanitize(head)} | #{@runner.sanitize(tail)}"
    end
    @result_catalog << [['artisan', *arguments], 'EXPECTED_REFUSAL']
    'PASS'
  end

  def provision_postgres_runtime_identities!
    runtime = "simrs_runtime_#{@run_token}"
    reset = "simrs_reset_#{@run_token}"
    raise CommandFailed, 'Generated PostgreSQL identities failed closed pattern.' unless runtime.match?(IDENTITY_PATTERN) && reset.match?(IDENTITY_PATTERN)
    tables = @runner.run!(postgres_psql_arguments(@postgres_database) + ['--tuples-only', '--no-align', '--command', "SELECT tablename FROM pg_tables WHERE schemaname='laravel' ORDER BY tablename"], env: postgres_tool_environment).lines.map(&:strip).reject(&:empty?)
    sequences = @runner.run!(postgres_psql_arguments(@postgres_database) + ['--tuples-only', '--no-align', '--command', "SELECT sequencename FROM pg_sequences WHERE schemaname='laravel' ORDER BY sequencename"], env: postgres_tool_environment).lines.map(&:strip).reject(&:empty?)
    statements = [%(CREATE ROLE "#{runtime}" LOGIN), %(CREATE ROLE "#{reset}" LOGIN), %(GRANT CONNECT ON DATABASE "#{@postgres_database}" TO "#{runtime}", "#{reset}"), %(GRANT USAGE ON SCHEMA "laravel" TO "#{runtime}", "#{reset}")]
    tables.each do |table|
      grants = IMMUTABLE_HISTORY_TABLES.include?(table) ? 'SELECT, INSERT' : 'SELECT, INSERT, UPDATE, DELETE'
      statements << %(GRANT #{grants} ON TABLE "laravel"."#{table}" TO "#{runtime}")
      statements << %(GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE "laravel"."#{table}" TO "#{reset}")
    end
    sequences.each { |sequence| statements << %(GRANT USAGE, SELECT, UPDATE ON SEQUENCE "laravel"."#{sequence}" TO "#{runtime}", "#{reset}") }
    @runner.run!(postgres_psql_arguments(@postgres_database) + ['--set', 'ON_ERROR_STOP=1', '--command', statements.join(";\n")+';'], env: postgres_tool_environment)
    IMMUTABLE_HISTORY_TABLES.each do |table|
      privileges = @runner.run!(postgres_psql_arguments(@postgres_database) + ['--tuples-only', '--no-align', '--command', "SELECT string_agg(privilege_type, ',' ORDER BY privilege_type) FROM information_schema.role_table_grants WHERE grantee='#{runtime}' AND table_schema='laravel' AND table_name='#{table}'"], env: postgres_tool_environment).strip
      raise CommandFailed, "PostgreSQL immutable grant drifted for #{table}." unless privileges == 'INSERT,SELECT'
    end
    @runtime_application_environment = application_environment.merge('DB_USERNAME' => runtime, 'DB_PASSWORD' => '')
    @reset_application_environment = application_environment.merge('DB_USERNAME' => reset, 'DB_PASSWORD' => '')
  end

  def provision_mysql_runtime_identities!
    runtime = "simrs_runtime_#{@run_token}"
    reset = "simrs_reset_#{@run_token}"
    raise CommandFailed, 'Generated MySQL identities failed closed pattern.' unless runtime.match?(IDENTITY_PATTERN) && reset.match?(IDENTITY_PATTERN)
    runtime_password = SecureRandom.hex(24)
    reset_password = SecureRandom.hex(24)
    tables = @runner.run!(mysql_root_arguments + ['--batch', '--skip-column-names'], stdin_data: "SELECT TABLE_NAME FROM information_schema.tables WHERE table_schema='#{@mysql_database}' ORDER BY TABLE_NAME;").lines.map(&:strip).reject(&:empty?)
    statements = ["CREATE USER '#{runtime}'@'127.0.0.1' IDENTIFIED BY '#{runtime_password}'", "CREATE USER '#{reset}'@'127.0.0.1' IDENTIFIED BY '#{reset_password}'"]
    tables.each do |table|
      grants = IMMUTABLE_HISTORY_TABLES.include?(table) ? 'SELECT, INSERT' : 'SELECT, INSERT, UPDATE, DELETE'
      statements << "GRANT #{grants} ON `#{@mysql_database}`.`#{table}` TO '#{runtime}'@'127.0.0.1'"
      statements << "GRANT SELECT, INSERT, UPDATE, DELETE ON `#{@mysql_database}`.`#{table}` TO '#{reset}'@'127.0.0.1'"
    end
    statements << 'FLUSH PRIVILEGES'
    @runner.run!(mysql_root_arguments, stdin_data: statements.join(";\n")+';')
    grants = @runner.run!(mysql_root_arguments + ['--batch', '--skip-column-names'], stdin_data: "SHOW GRANTS FOR '#{runtime}'@'127.0.0.1';").upcase
    raise CommandFailed, 'Reduced MySQL runtime retained forbidden DDL grants.' unless %w[DROP ALTER TRIGGER CREATE].none? { |privilege| grants.match?(/\b#{privilege}\b/) }
    IMMUTABLE_HISTORY_TABLES.each do |table|
      expected = "GRANT SELECT, INSERT ON `#{@mysql_database.upcase}`.`#{table.upcase}`"
      raise CommandFailed, "MySQL immutable grant drifted for #{table}." unless grants.include?(expected)
    end
    @runtime_application_environment = application_environment.merge('DB_USERNAME' => runtime, 'DB_PASSWORD' => runtime_password)
    @reset_application_environment = application_environment.merge('DB_USERNAME' => reset, 'DB_PASSWORD' => reset_password)
  end

  def run_rmik_race!(fixture, scenario)
    first = start_race_worker!(fixture: fixture, scenario: scenario, worker: 'A', hold_ms: HOLD_MS)
    await_protocol!(first, 'STARTED')
    await_protocol!(first, 'HOLDING')
    second = start_race_worker!(fixture: fixture, scenario: scenario, worker: 'B', hold_ms: 0)
    second_started = await_protocol!(second, 'STARTED')
    wait_observed = observe_real_database_wait!(Integer(second_started.fetch('backend_connection_id')))
    outcomes = [await_final!(first).fetch('outcome'), await_final!(second).fetch('outcome')].sort
    raise CommandFailed, "Unexpected RMIK same-key race outcomes for #{scenario}." unless outcomes == %w[APPLIED REPLAYED]
    verified = run_worker_command!(action: 'verify_race', scenario: scenario, worker: 'VERIFY', fixture: fixture)
    { 'status' => 'PASS', 'independent_application_processes' => 2, 'real_database_wait_observed' => wait_observed, 'durable_third_connection_assertions' => true, 'outcomes' => outcomes, 'durable_assertions' => verified.fetch('result') }
  ensure
    terminate_workers!
  end

  def write_rmik_evidence!(bindings:, engine_binding:, migration_duration_ms:, scenarios:)
    assert_evidence_directory!
    path = File.join(EVIDENCE_DIRECTORY, "#{Time.now.utc.strftime('%Y%m%dT%H%M%SZ')}-#{@engine}-inpatient-rmik-#{SecureRandom.hex(6)}.json")
    evidence = {
      'schema_version' => 1, 'kind' => EVIDENCE_KIND, 'status' => 'PASS', 'recorded_at_utc' => Time.now.utc.iso8601,
      'claim' => 'LOCAL_DISPOSABLE_INPATIENT_RMIK_CLOSURE_ONLY', 'hosted_readiness_claim' => false,
      'deployment_claim' => false, 'owner_acceptance_claim' => false,
      'source_bindings' => bindings.merge('command_catalog_sha256' => Digest::SHA256.hexdigest(JSON.generate(@command_catalog)), 'result_catalog_sha256' => Digest::SHA256.hexdigest(JSON.generate(@result_catalog))),
      'command_catalog' => @command_catalog, 'protocol_result_catalog' => @protocol_catalog,
      'boundary' => { 'application_mode' => 'SIMULATION', 'synthetic_only' => true, 'live_integrations_enabled' => false, 'disposable_local_engine' => true, 'external_database_configuration_accepted' => false },
      'engine' => engine_binding, 'migration' => { 'fresh_apply' => 'PASS', 'duration_ms_observed' => migration_duration_ms },
      'scenarios' => scenarios,
      'cleanup' => { 'database_removed' => true, 'temporary_server_removed' => true, 'temporary_user_state_removed' => true, 'temporary_worker_removed' => true },
      'open_boundaries' => ['Local disposable-engine evidence only; no deployment, hosted migration, UAT, owner acceptance, G0/G3 closure, or production-readiness claim.'],
    }
    sanitize_evidence!(evidence)
    File.write(path, JSON.pretty_generate(evidence)+"\n", mode: 'wx', perm: 0o600)
    File.chmod(0o600, path)
    path
  end
end

if $PROGRAM_NAME == __FILE__
  begin
    unless ARGV.length == 1 && LocalInpatientRmPortabilityRehearsal::ENGINES.include?(ARGV.first)
      warn "usage: #{File.basename(__FILE__)} <postgresql17|mysql8411>"
      exit 64
    end
    puts JSON.generate(LocalInpatientRmPortabilityRehearsal.new(engine: ARGV.first).run!)
  rescue LocalInpatientRmPortabilityRehearsal::CommandFailed => e
    warn "inpatient RMIK portability rehearsal failed: #{e.message}"
    exit 1
  end
end
