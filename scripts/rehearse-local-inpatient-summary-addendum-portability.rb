#!/usr/bin/env ruby
# frozen_string_literal: true

require 'digest'
require 'json'
require 'securerandom'
require 'tempfile'
require 'time'

require_relative 'rehearse-local-inpatient-rm-portability'

# Exact disposable PostgreSQL 17 / MySQL 8.4 proof for the post-closure
# inpatient discharge-summary addendum and RMIK review node.
class LocalInpatientSummaryAddendumPortabilityRehearsal < LocalInpatientRmPortabilityRehearsal
  CONFIRMATION = 'YES_DISPOSABLE_LOCAL_INPATIENT_SUMMARY_ADDENDUM_PORTABILITY'
  CONFIRMATION_ENV = 'SIMRS_INPATIENT_SUMMARY_ADDENDUM_REHEARSAL_CONFIRM'
  SCRIPT_PATH = 'scripts/rehearse-local-inpatient-summary-addendum-portability.rb'
  CONTRACT_PATH = 'tests/Documentation/LocalInpatientSummaryAddendumPortabilityHarnessContractTest.rb'
  MIGRATION_PATH = 'database/migrations/2026_08_31_001000_create_inpatient_summary_addendum_tables.php'
  EVIDENCE_PATH = 'docs/operations/T1_LOCAL_INPATIENT_SUMMARY_ADDENDUM_EVIDENCE_2026-08-31.md'
  EVIDENCE_KIND = 'SIMRS_LOCAL_INPATIENT_SUMMARY_ADDENDUM_PORTABILITY'
  SCENARIOS = %w[
    fresh-migration
    empty-down-reapply
    request-different-physician-approval
    draft-final-addendum
    rmik-draft-signed-off
    closed-baseline-immutability
    exact-idempotent-replay
    changed-payload-key-conflict
    append-only-and-head-guard-refusal
    least-privilege-runtime
    bounded-reset-audit-preservation
    retained-evidence-rollback-refusal
    invariant-verification
  ].freeze
  IMMUTABLE_HISTORY_TABLES = %w[
    inpatient_summary_correction_request_versions
    inpatient_summary_addendum_versions
    inpatient_summary_addendum_reviews
    inpatient_summary_addendum_review_items
    inpatient_summary_addendum_operation_receipts
  ].freeze
  HEAD_TABLES = %w[
    inpatient_summary_correction_requests
    inpatient_summary_addenda
  ].freeze
  RUNTIME_READ_TABLES = %w[
    users
    roles
    permissions
    role_user
    permission_role
    patients
    encounters
    inpatient_discharges
    inpatient_discharge_summaries
    inpatient_discharge_summary_versions
    inpatient_discharge_coding_sources
    inpatient_discharge_coding_source_versions
    inpatient_rm_codings
    inpatient_rm_coding_versions
    inpatient_rm_coding_assignments
    inpatient_rm_completeness_reviews
    inpatient_clinical_documents
    inpatient_location_events
    lab_service_requests
  ].freeze
  RUNTIME_APPEND_TABLES = (IMMUTABLE_HISTORY_TABLES + ['audit_events']).freeze
  RUNTIME_TABLE_GRANTS = (
    RUNTIME_READ_TABLES.to_h { |table| [table, 'SELECT'] }
      .merge(HEAD_TABLES.to_h { |table| [table, 'SELECT, INSERT, UPDATE'] })
      .merge(RUNTIME_APPEND_TABLES.to_h { |table| [table, 'SELECT, INSERT'] })
  ).freeze
  SOURCE_PATHS = %w[
    scripts/rehearse-local-inpatient-summary-addendum-portability.rb
    scripts/rehearse-local-inpatient-rm-portability.rb
    scripts/rehearse-local-inpatient-discharge-coding-source-portability.rb
    scripts/rehearse-local-portability-full-suite.rb
    tests/Documentation/LocalInpatientSummaryAddendumPortabilityHarnessContractTest.rb
    database/migrations/2026_08_31_001000_create_inpatient_summary_addendum_tables.php
    app/Support/Inpatient/InpatientSummaryAddendumService.php
    app/Support/Inpatient/InpatientSummaryAddendumActorPolicy.php
    app/Support/Inpatient/InpatientSummaryAddendumDenied.php
    app/Support/Inpatient/InpatientSummaryAddendumAuditUnavailable.php
    app/Support/Inpatient/InpatientSummaryAddendumMutationScope.php
    app/Support/Inpatient/InpatientSummaryAddendumSchemaMutationScope.php
    app/Support/Inpatient/InpatientSummaryAddendumProjection.php
    app/Support/Inpatient/InpatientSummaryAddendumResult.php
    app/Models/InpatientSummaryCorrectionRequest.php
    app/Models/InpatientSummaryCorrectionRequestVersion.php
    app/Models/InpatientSummaryAddendum.php
    app/Models/InpatientSummaryAddendumVersion.php
    app/Models/InpatientSummaryAddendumReview.php
    app/Models/InpatientSummaryAddendumReviewItem.php
    app/Models/InpatientSummaryAddendumOperationReceipt.php
    app/Support/Inpatient/InpatientRmService.php
    app/Support/Simulation/SyntheticResetService.php
    app/Support/Audit/AuditEventSchemaRegistry.php
  ].freeze

  parent_worker = LocalInpatientRmPortabilityRehearsal::WORKER_SOURCE
  dispatch_marker = "\n$action = (string) getenv('SIMRS_RMIK_ACTION');"
  raise 'Cannot isolate shared inpatient RMIK fixture worker.' unless parent_worker.include?(dispatch_marker)

  shared_worker = parent_worker.split(dispatch_marker, 2).first
  shared_worker = shared_worker.sub(
    "use App\\Models\\InpatientRmOperationReceipt;\n",
    <<~'PHP'
      use App\Models\InpatientRmOperationReceipt;
      use App\Models\InpatientSummaryCorrectionRequest;
      use App\Models\InpatientSummaryCorrectionRequestVersion;
      use App\Models\InpatientSummaryAddendum;
      use App\Models\InpatientSummaryAddendumVersion;
      use App\Models\InpatientSummaryAddendumReview;
      use App\Models\InpatientSummaryAddendumReviewItem;
      use App\Models\InpatientSummaryAddendumOperationReceipt;
    PHP
  ).sub(
    "use App\\Support\\Inpatient\\InpatientRmService;\n",
    <<~'PHP'
      use App\Support\Inpatient\InpatientRmService;
      use App\Support\Inpatient\InpatientSummaryAddendumDenied;
      use App\Support\Inpatient\InpatientSummaryAddendumService;
    PHP
  )

  WORKER_SOURCE = shared_worker + <<~'PHP'

    function closeForSummaryAddendum(string $encounterPublicId, User $rmik, array $binding, string $token, string $suffix): array
    {
        $assignments = rmikAssignments($binding);
        $coding = rmikSaveCoding($encounterPublicId, $rmik, 0, $binding, $assignments, 'addendum-coding-'.$suffix.'-'.$token);
        $snapshot = app(InpatientRmService::class)->snapshot(Encounter::query()->where('public_id', $encounterPublicId)->sole());
        $review = rmikSaveReview($encounterPublicId, $rmik, 0, $snapshot, 'addendum-baseline-review-'.$suffix.'-'.$token);
        $signoff = rmikSignoff($encounterPublicId, $rmik, 1, $snapshot, 'addendum-baseline-signoff-'.$suffix.'-'.$token);
        $encounter = Encounter::query()->where('public_id', $encounterPublicId)->sole();
        must($encounter->status === Encounter::STATUS_CLOSED, 'addendum fixture encounter Closed');
        must($signoff->review->review_state === InpatientRmCompletenessReview::STATE_SIGNED_OFF, 'addendum fixture baseline Signed Off');
        return [
            'coding_public_id' => $coding->coding->public_id,
            'baseline_review_public_id' => $signoff->review->public_id,
            'baseline_fingerprint' => $signoff->review->source_fingerprint,
        ];
    }

    function prepareSummaryAddendumFixture(string $token): array
    {
        $fixture = prepareRmikFixture($token);
        $rmik = userByPublicId($fixture['rmik']);
        foreach (['main', 'replay'] as $name) {
            $encounterKey = $name === 'main' ? 'main_encounter' : 'replay_encounter';
            $fixture[$name.'_closed_binding'] = closeForSummaryAddendum(
                $fixture[$encounterKey], $rmik, $fixture[$name.'_binding'], $token, $name,
            );
        }
        $approver = User::query()->create([
            'name' => 'Dokter Penyetuju Addendum',
            'email' => 'approver.'.$token.'@example.invalid',
            'password' => bin2hex(random_bytes(24)),
            'status' => 'ACTIVE',
        ]);
        $approver->roles()->sync([Role::query()->where('slug', RoleCapabilityMatrix::ROLE_PHYSICIAN)->sole()->id]);
        $fixture['approver'] = $approver->public_id;
        return $fixture;
    }

    function addendumFields(string $suffix): array
    {
        return [
            'admission_reason' => null,
            'significant_findings' => 'Koreksi temuan '.$suffix,
            'care_and_treatment_summary' => null,
            'condition_at_discharge' => null,
            'follow_up_plan' => 'Tindak lanjut '.$suffix,
        ];
    }

    function baselineTuple(Encounter $encounter): array
    {
        $encounter->load(['inpatientDischarge', 'inpatientDischargeSummary', 'inpatientDischargeCodingSource', 'inpatientRmCoding']);
        return [
            'encounter_status' => $encounter->status,
            'bed_id' => $encounter->inpatient_bed_id,
            'bed_code' => $encounter->bed_code,
            'discharge_public_id' => $encounter->inpatientDischarge?->public_id,
            'summary_public_id' => $encounter->inpatientDischargeSummary?->public_id,
            'summary_version' => $encounter->inpatientDischargeSummary?->version,
            'source_public_id' => $encounter->inpatientDischargeCodingSource?->public_id,
            'source_version' => $encounter->inpatientDischargeCodingSource?->version,
            'coding_public_id' => $encounter->inpatientRmCoding?->public_id,
            'coding_version' => $encounter->inpatientRmCoding?->version,
            'location_count' => $encounter->inpatientLocationEvents()->count(),
        ];
    }

    function runSummaryAddendumSequential(array $fixture, string $token): array
    {
        $service = app(InpatientSummaryAddendumService::class);
        $requester = userByPublicId($fixture['physician']);
        $approver = userByPublicId($fixture['approver']);
        $rmik = userByPublicId($fixture['rmik']);
        $encounter = Encounter::query()->where('public_id', $fixture['main_encounter'])->sole();
        $baseline = baselineTuple($encounter);

        $submitted = $service->submit(
            $encounter, $requester, InpatientSummaryCorrectionRequest::REASON_CLINICAL_CORRECTION,
            'Koreksi ringkasan setelah penutupan', 'summary-addendum-submit-'.$token,
        );
        must($submitted->request->request_state === InpatientSummaryCorrectionRequest::STATE_SUBMITTED, 'request Submitted');
        $submitReplay = $service->submit(
            $encounter, $requester, InpatientSummaryCorrectionRequest::REASON_CLINICAL_CORRECTION,
            'Koreksi ringkasan setelah penutupan', 'SUMMARY-ADDENDUM-SUBMIT-'.$token,
        );
        must($submitReplay->replayed, 'request exact replay');

        $selfDecisionDenied = false;
        try {
            $service->decide($submitted->request, $requester, InpatientSummaryCorrectionRequest::STATE_APPROVED, null, 1, 'summary-addendum-self-decide-'.$token);
        } catch (InpatientSummaryAddendumDenied $denial) {
            $selfDecisionDenied = $denial->reason === 'self_decision_forbidden';
        }
        must($selfDecisionDenied, 'requester cannot approve own request');
        $approved = $service->decide(
            $submitted->request->fresh(), $approver, InpatientSummaryCorrectionRequest::STATE_APPROVED,
            'Disetujui dokter berbeda', 1, 'summary-addendum-approve-'.$token,
        );
        must($approved->request->request_state === InpatientSummaryCorrectionRequest::STATE_APPROVED, 'different physician Approved');
        must($approved->request->decided_by_user_id === $approver->id, 'approval attribution differs from requester');

        $draft = $service->saveDraft($approved->request, $requester, addendumFields('utama'), 0, 'summary-addendum-draft-'.$token);
        must($draft->addendum?->addendum_state === InpatientSummaryAddendum::STATE_DRAFT, 'addendum Draft');
        $draftReplay = $service->saveDraft($approved->request, $requester, addendumFields('utama'), 0, 'SUMMARY-ADDENDUM-DRAFT-'.$token);
        must($draftReplay->replayed, 'addendum Draft exact replay');
        $conflict = false;
        try {
            $service->saveDraft($approved->request, $requester, addendumFields('berubah'), 0, 'summary-addendum-draft-'.$token);
        } catch (InpatientSummaryAddendumDenied $denial) {
            $conflict = $denial->reason === 'idempotency_key_conflict';
        }
        must($conflict, 'changed payload same key conflict');

        $final = $service->finalize($approved->request->fresh(), $requester, 1, 'summary-addendum-final-'.$token);
        must($final->addendum?->addendum_state === InpatientSummaryAddendum::STATE_FINAL, 'addendum Final');
        must($final->addendum?->versions()->count() === 2, 'Draft and Final append-only versions');
        $snapshot = $service->snapshot($approved->request->fresh());
        must($snapshot['blockers'] === [], 'addendum review snapshot zero blockers');
        $review = $service->saveReview($approved->request->fresh(), $rmik, 0, $snapshot['source_fingerprint'], 'summary-addendum-review-'.$token);
        must($review->review?->review_state === InpatientSummaryAddendumReview::STATE_DRAFT, 'RMIK addendum review Draft');
        $signed = $service->signoff($approved->request->fresh(), $rmik, 1, $snapshot['source_fingerprint'], 'summary-addendum-signoff-'.$token);
        must($signed->review?->review_state === InpatientSummaryAddendumReview::STATE_SIGNED_OFF, 'RMIK addendum review SIGNED_OFF');
        must($signed->request->request_state === InpatientSummaryCorrectionRequest::STATE_CONSUMED, 'approved request consumed once');

        $after = baselineTuple($encounter->fresh());
        must($baseline === $after, 'CLOSED and baseline clinical tuple immutable');
        must($after['encounter_status'] === Encounter::STATUS_CLOSED, 'encounter remains CLOSED');
        must(InpatientSummaryAddendumOperationReceipt::query()->where('correction_request_id', $signed->request->id)->count() === 6, 'one receipt per six operations');
        must(AuditEvent::query()->where('action', 'like', 'clinical.inpatient.summary-addendum.%')->exists(), 'clinical addendum audit retained');
        must(AuditEvent::query()->where('action', 'like', 'rmik.inpatient.summary-addendum.%')->exists(), 'RMIK addendum audit retained');

        return [
            'request_different_physician_approval' => true,
            'draft_final_addendum' => true,
            'rmik_draft_signed_off' => true,
            'closed_baseline_immutability' => true,
            'exact_idempotent_replay' => true,
            'changed_payload_key_conflict' => true,
            'request_public_id' => $signed->request->public_id,
            'addendum_public_id' => $signed->addendum?->public_id,
            'signed_review_public_id' => $signed->review?->public_id,
        ];
    }

    function runSummaryAddendumGuards(): array
    {
        $refused = [];
        foreach (['inpatient_summary_correction_request_versions', 'inpatient_summary_addendum_versions', 'inpatient_summary_addendum_reviews', 'inpatient_summary_addendum_review_items', 'inpatient_summary_addendum_operation_receipts'] as $tableName) {
            $table = SchemaQualifier::table($tableName);
            $id = DB::table($table)->value('id');
            foreach (['update', 'delete'] as $operation) {
                try {
                    $operation === 'update' ? DB::table($table)->where('id', $id)->update(['id' => $id]) : DB::table($table)->where('id', $id)->delete();
                } catch (Throwable) {
                    $refused[] = $tableName.'_'.$operation;
                }
            }
            if (DB::connection()->getDriverName() === 'pgsql') {
                try { DB::statement('TRUNCATE TABLE '.DB::connection()->getQueryGrammar()->wrapTable($table)); }
                catch (Throwable) { $refused[] = $tableName.'_truncate'; }
            }
        }
        $request = InpatientSummaryCorrectionRequest::query()->where('request_state', InpatientSummaryCorrectionRequest::STATE_CONSUMED)->sole();
        try { DB::table(SchemaQualifier::table('inpatient_summary_correction_requests'))->where('id', $request->id)->update(['version' => $request->version + 1]); }
        catch (Throwable) { $refused[] = 'request_head_invalid_transition'; }
        $addendum = InpatientSummaryAddendum::query()->where('addendum_state', InpatientSummaryAddendum::STATE_FINAL)->sole();
        try { DB::table(SchemaQualifier::table('inpatient_summary_addenda'))->where('id', $addendum->id)->update(['significant_findings' => 'mutation']); }
        catch (Throwable) { $refused[] = 'final_addendum_head_mutation'; }
        $expected = 2 + (5 * (DB::connection()->getDriverName() === 'pgsql' ? 3 : 2));
        must(count($refused) === $expected, 'append-only and head guards refused every mutation');
        return ['sql_refusal_count' => count($refused), 'sql_refusals' => $refused];
    }

    function summaryAddendumMutationIsDenied(callable $mutation): bool
    {
        DB::beginTransaction();
        try {
            $mutation();
            DB::rollBack();
            return false;
        } catch (Throwable) {
            if (DB::connection()->transactionLevel() > 0) {
                DB::rollBack();
            }
            return true;
        }
    }

    function runSummaryAddendumPrivilegeGuards(): array
    {
        $encounterId = DB::table(SchemaQualifier::table('encounters'))->value('id');
        $auditId = DB::table(SchemaQualifier::table('audit_events'))->value('id');
        $requestId = DB::table(SchemaQualifier::table('inpatient_summary_correction_requests'))->value('id');
        must($encounterId !== null && $auditId !== null && $requestId !== null, 'privilege guard fixtures exist');
        $checks = [
            'unrelated_encounter_update' => fn () => DB::table(SchemaQualifier::table('encounters'))->where('id', $encounterId)->update(['id' => $encounterId]),
            'unrelated_encounter_delete' => fn () => DB::table(SchemaQualifier::table('encounters'))->where('id', $encounterId)->delete(),
            'audit_update' => fn () => DB::table(SchemaQualifier::table('audit_events'))->where('id', $auditId)->update(['id' => $auditId]),
            'audit_delete' => fn () => DB::table(SchemaQualifier::table('audit_events'))->where('id', $auditId)->delete(),
            'node_head_delete' => fn () => DB::table(SchemaQualifier::table('inpatient_summary_correction_requests'))->where('id', $requestId)->delete(),
        ];
        $refused = [];
        foreach ($checks as $label => $mutation) {
            if (summaryAddendumMutationIsDenied($mutation)) {
                $refused[] = $label;
            }
        }
        must(count($refused) === count($checks), 'runtime privilege map refused unrelated, audit, and node-head destructive writes');
        return ['forbidden_write_refusal_count' => count($refused), 'forbidden_write_refusals' => $refused];
    }

    function verifySummaryAddendumInvariants(): array
    {
        $request = InpatientSummaryCorrectionRequest::query()->where('request_state', InpatientSummaryCorrectionRequest::STATE_CONSUMED)->sole();
        $addendum = $request->addendum()->sole();
        $signed = $request->reviews()->where('review_state', InpatientSummaryAddendumReview::STATE_SIGNED_OFF)->sole();
        must($request->active_slot === null && $request->version === 3, 'request terminal version invariant');
        must($addendum->addendum_state === InpatientSummaryAddendum::STATE_FINAL && $addendum->version === 2, 'addendum terminal invariant');
        must($signed->blocker_count === 0 && $signed->items()->count() === 7, 'signed review exact seven-item zero-blocker invariant');
        must($request->encounter?->status === Encounter::STATUS_CLOSED, 'invariant encounter remains CLOSED');
        return ['consumed_requests' => 1, 'final_addenda' => 1, 'signed_off_reviews' => 1, 'encounter_closed' => true];
    }

    function resetSummaryAddendumFixture(array $fixture): array
    {
        app(SyntheticResetService::class)->reset([
            'actor' => userByPublicId($fixture['admin']),
            'reason' => 'bounded_inpatient_summary_addendum_portability_reset',
        ]);
        foreach (['inpatient_summary_correction_requests', 'inpatient_summary_correction_request_versions', 'inpatient_summary_addenda', 'inpatient_summary_addendum_versions', 'inpatient_summary_addendum_reviews', 'inpatient_summary_addendum_review_items', 'inpatient_summary_addendum_operation_receipts'] as $table) {
            must(DB::table(SchemaQualifier::table($table))->count() === 0, $table.' reset');
        }
        must(AuditEvent::query()->where('action', 'like', 'clinical.inpatient.summary-addendum.%')->exists(), 'clinical addendum audit preserved');
        must(AuditEvent::query()->where('action', 'like', 'rmik.inpatient.summary-addendum.%')->exists(), 'RMIK addendum audit preserved');
        must(AuditEvent::query()->where('action', 'teaching.reset.completed')->exists(), 'reset audit preserved');
        return ['rows_removed' => true, 'addendum_audit_preserved' => true, 'reset_audit_preserved' => true];
    }

    $action = (string) getenv('SIMRS_ADDENDUM_ACTION');
    $scenario = (string) getenv('SIMRS_ADDENDUM_SCENARIO');
    $worker = (string) getenv('SIMRS_ADDENDUM_WORKER');
    $token = (string) getenv('SIMRS_ADDENDUM_RUN_TOKEN');
    try {
        $root = realpath((string) getenv('SIMRS_REHEARSAL_ROOT'));
        if ($root === false || ! is_file($root.'/artisan')) { throw new RuntimeException('repository root refused'); }
        require $root.'/vendor/autoload.php';
        $app = require $root.'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        must(config('simulation.mode') === 'SIMULATION' && config('simulation.synthetic_only') === true, 'synthetic simulation boundary');
        must(InpatientSummaryAddendum::DEFINITION_VERSION === 'INPATIENT_POST_CLOSURE_SUMMARY_ADDENDUM_V1', 'exact addendum definition');
        must(InpatientSummaryAddendumReview::DEFINITION_VERSION === 'INPATIENT_SUMMARY_ADDENDUM_REVIEW_V1', 'exact review definition');
        must(InpatientSummaryAddendumOperationReceipt::SUBMIT === 'REQUEST_SUBMIT', 'exact submit operation');
        must(InpatientSummaryAddendumOperationReceipt::SIGNOFF === 'REVIEW_SIGNOFF', 'exact signoff operation');
        foreach (['BPJS_INTEGRATION_ENABLED', 'VCLAIM_ENABLED', 'SATUSEHAT_ENABLED'] as $flag) { must(getenv($flag) === 'false', $flag.' disabled'); }
        protocol('STARTED', ['backend_connection_id' => backendConnectionId()]);
        $fixture = fixture();
        $result = match ($action) {
            'prepare' => ['fixture' => prepareSummaryAddendumFixture($token)],
            'sequential' => runSummaryAddendumSequential($fixture, $token),
            'guards' => runSummaryAddendumGuards(),
            'privilege-guards' => runSummaryAddendumPrivilegeGuards(),
            'verify' => verifySummaryAddendumInvariants(),
            'reset' => resetSummaryAddendumFixture($fixture),
            default => throw new RuntimeException('unsupported summary addendum action'),
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
            'query_head' => $exception instanceof \Illuminate\Database\QueryException ? substr($exception->getSql(), 0, 240) : '',
            'failure_stage' => 'inpatient-summary-addendum-worker',
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

  def assert_contract!
    unless @operator_environment[CONFIRMATION_ENV] == CONFIRMATION
      raise CommandFailed, "Set #{CONFIRMATION_ENV}=#{CONFIRMATION} to authorize the disposable local rehearsal."
    end
    rejected = FORBIDDEN_ENVIRONMENT.select { |name| !@operator_environment.fetch(name, '').to_s.strip.empty? }
    raise CommandFailed, "Summary addendum rehearsal refuses inherited overrides: #{rejected.join(', ')}." unless rejected.empty?
    LocalPortabilityFullSuiteRehearsal.instance_method(:assert_contract!).bind(self).call
    SOURCE_PATHS.each { |path| safe_source_path(path) }
    raise CommandFailed, 'Summary addendum scenario catalogue drifted.' unless SCENARIOS.length == 13 && SCENARIOS.uniq.length == 13
    raise CommandFailed, 'Embedded summary addendum worker source is unexpectedly small.' unless WORKER_SOURCE.bytesize > 55_000
  end

  def application_environment
    LocalPortabilityFullSuiteRehearsal.instance_method(:application_environment).bind(self).call.merge(
      CONFIRMATION_ENV => CONFIRMATION,
      'BPJS_INTEGRATION_ENABLED' => 'false', 'VCLAIM_ENABLED' => 'false', 'SATUSEHAT_ENABLED' => 'false'
    )
  end

  def current_addendum_bindings
    files = SOURCE_PATHS.to_h { |path| [path, Digest::SHA256.file(safe_source_path(path)).hexdigest] }
    {
      'files' => files,
      'aggregate_sha256' => Digest::SHA256.hexdigest(JSON.generate(files.sort.to_h)),
      'worker_source_sha256' => Digest::SHA256.hexdigest(WORKER_SOURCE),
      'scenario_catalog_sha256' => Digest::SHA256.hexdigest(JSON.generate(SCENARIOS)),
    }
  end

  def run!
    assert_contract!
    bindings = current_addendum_bindings
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
    provision_runtime_identities!
    sequential = run_worker_command!(action: 'sequential', scenario: 'request-different-physician-approval', worker: 'SEQUENTIAL', fixture: fixture)
    guards = run_worker_command!(action: 'guards', scenario: 'append-only-and-head-guard-refusal', worker: 'GUARDS', fixture: fixture)
    owner_guards = run_worker_command!(action: 'guards', scenario: 'append-only-and-head-guard-refusal', worker: 'OWNER_GUARDS', fixture: fixture, connection_environment: application_environment)
    privilege_guards = run_worker_command!(action: 'privilege-guards', scenario: 'least-privilege-runtime', worker: 'PRIVILEGE_GUARDS', fixture: fixture)
    invariants = run_worker_command!(action: 'verify', scenario: 'invariant-verification', worker: 'VERIFY', fixture: fixture)
    reset = run_worker_command!(action: 'reset', scenario: 'bounded-reset-audit-preservation', worker: 'RESET', fixture: fixture, connection_environment: @reset_application_environment)
    refusal = expect_addendum_rollback_refusal!('correlated audit remains')
    scenarios = {
      'fresh-migration' => { 'status' => 'PASS', 'fresh_apply' => true },
      'empty-down-reapply' => { 'status' => 'PASS', 'empty_down' => true, 'reapply' => true },
      'request-different-physician-approval' => slice_result(sequential, %w[request_different_physician_approval]),
      'draft-final-addendum' => slice_result(sequential, %w[draft_final_addendum addendum_public_id]),
      'rmik-draft-signed-off' => slice_result(sequential, %w[rmik_draft_signed_off signed_review_public_id]),
      'closed-baseline-immutability' => slice_result(sequential, %w[closed_baseline_immutability]),
      'exact-idempotent-replay' => slice_result(sequential, %w[exact_idempotent_replay]),
      'changed-payload-key-conflict' => slice_result(sequential, %w[changed_payload_key_conflict]),
      'append-only-and-head-guard-refusal' => { 'status' => 'PASS', 'runtime' => guards, 'owner' => owner_guards },
      'least-privilege-runtime' => {
        'status' => 'PASS',
        'read_prerequisites' => 'SELECT_ONLY',
        'node_heads' => 'SELECT_INSERT_UPDATE_NO_DELETE',
        'immutable_history_and_audit' => 'SELECT_INSERT_ONLY',
        'forbidden_writes' => privilege_guards,
        'reset_identity_separate' => true,
      },
      'bounded-reset-audit-preservation' => slice_result(reset, %w[rows_removed addendum_audit_preserved reset_audit_preserved]),
      'retained-evidence-rollback-refusal' => { 'status' => 'PASS', 'refusal' => refusal },
      'invariant-verification' => slice_result(invariants, %w[consumed_requests final_addenda signed_off_reviews encounter_closed]),
    }
    raise CommandFailed, 'Summary addendum scenario result catalogue drifted.' unless scenarios.keys == SCENARIOS
    assert_unchanged_binding!('Summary addendum execution bindings', bindings, current_addendum_bindings)
    cleanup!(strict: true)
    remove_worker_file!
    evidence_path = write_addendum_evidence!(
      bindings: bindings,
      engine_binding: engine_binding,
      migration_duration_ms: migration_duration_ms,
      scenarios: scenarios
    )
    { 'status' => 'PASS', 'claim' => 'LOCAL_DISPOSABLE_INPATIENT_SUMMARY_ADDENDUM_ONLY', 'engine' => @engine, 'scenario_count' => scenarios.length, 'evidence_path' => evidence_path }
  ensure
    terminate_workers!
    remove_worker_file!
    cleanup!
  end

  private

  def create_worker_file!
    @worker_tempfile = Tempfile.new(['simrs-inpatient-summary-addendum-', '.php'])
    @worker_tempfile.binmode
    @worker_tempfile.write(WORKER_SOURCE)
    @worker_tempfile.flush
    @worker_tempfile.chmod(0o600)
    @worker_tempfile.close
  end

  def worker_environment(action:, scenario:, worker:, fixture:, hold_ms:, connection_environment: nil)
    (connection_environment || @runtime_application_environment || application_environment).merge(
      'SIMRS_REHEARSAL_ROOT' => ROOT,
      'SIMRS_ADDENDUM_ACTION' => action, 'SIMRS_ADDENDUM_SCENARIO' => scenario,
      'SIMRS_ADDENDUM_WORKER' => worker, 'SIMRS_ADDENDUM_RUN_TOKEN' => @run_token,
      'SIMRS_DISCHARGE_SCENARIO' => scenario, 'SIMRS_DISCHARGE_WORKER' => worker,
      'SIMRS_ADDENDUM_FIXTURE' => Base64.strict_encode64(JSON.generate(fixture || {})),
      'SIMRS_RMIK_FIXTURE' => Base64.strict_encode64(JSON.generate(fixture || {})),
      'SIMRS_DISCHARGE_FIXTURE' => Base64.strict_encode64(JSON.generate(fixture || {}))
    )
  end

  def provision_postgres_runtime_identities!
    runtime = "simrs_runtime_#{@run_token}"
    reset = "simrs_reset_#{@run_token}"
    raise CommandFailed, 'Generated PostgreSQL identities failed closed pattern.' unless runtime.match?(IDENTITY_PATTERN) && reset.match?(IDENTITY_PATTERN)
    tables = @runner.run!(postgres_psql_arguments(@postgres_database) + ['--tuples-only', '--no-align', '--command', "SELECT tablename FROM pg_tables WHERE schemaname='laravel' ORDER BY tablename"], env: postgres_tool_environment).lines.map(&:strip).reject(&:empty?)
    sequences = @runner.run!(postgres_psql_arguments(@postgres_database) + ['--tuples-only', '--no-align', '--command', "SELECT sequencename FROM pg_sequences WHERE schemaname='laravel' ORDER BY sequencename"], env: postgres_tool_environment).lines.map(&:strip).reject(&:empty?)
    missing = RUNTIME_TABLE_GRANTS.keys - tables
    raise CommandFailed, "PostgreSQL runtime grant table missing: #{missing.join(', ')}." unless missing.empty?
    statements = [%(CREATE ROLE "#{runtime}" LOGIN), %(CREATE ROLE "#{reset}" LOGIN), %(GRANT CONNECT ON DATABASE "#{@postgres_database}" TO "#{runtime}", "#{reset}"), %(GRANT USAGE ON SCHEMA "laravel" TO "#{runtime}", "#{reset}")]
    RUNTIME_TABLE_GRANTS.each do |table, grants|
      statements << %(GRANT #{grants} ON TABLE "laravel"."#{table}" TO "#{runtime}")
    end
    tables.each do |table|
      reset_grants = table == 'audit_events' ? 'SELECT, INSERT' : 'SELECT, INSERT, UPDATE, DELETE'
      statements << %(GRANT #{reset_grants} ON TABLE "laravel"."#{table}" TO "#{reset}")
    end
    runtime_sequence_tables = RUNTIME_TABLE_GRANTS.select { |_table, grants| grants.include?('INSERT') }.keys
    sequences.each do |sequence|
      if runtime_sequence_tables.any? { |table| sequence == "#{table}_id_seq" }
        statements << %(GRANT USAGE, SELECT ON SEQUENCE "laravel"."#{sequence}" TO "#{runtime}")
      end
      statements << %(GRANT USAGE, SELECT, UPDATE ON SEQUENCE "laravel"."#{sequence}" TO "#{reset}")
    end
    @runner.run!(postgres_psql_arguments(@postgres_database) + ['--set', 'ON_ERROR_STOP=1', '--command', statements.join(";\n")+';'], env: postgres_tool_environment)
    RUNTIME_TABLE_GRANTS.each do |table, expected_grants|
      privileges = @runner.run!(postgres_psql_arguments(@postgres_database) + ['--tuples-only', '--no-align', '--command', "SELECT string_agg(privilege_type, ',' ORDER BY privilege_type) FROM information_schema.role_table_grants WHERE grantee='#{runtime}' AND table_schema='laravel' AND table_name='#{table}'"], env: postgres_tool_environment).strip
      expected = expected_grants.split(', ').sort.join(',')
      raise CommandFailed, "PostgreSQL runtime grant drifted for #{table}." unless privileges == expected
    end
    unexpected = @runner.run!(postgres_psql_arguments(@postgres_database) + ['--tuples-only', '--no-align', '--command', "SELECT table_name FROM information_schema.role_table_grants WHERE grantee='#{runtime}' AND table_schema='laravel' AND table_name NOT IN (#{RUNTIME_TABLE_GRANTS.keys.map { |table| "'#{table}'" }.join(',')}) ORDER BY table_name"], env: postgres_tool_environment).lines.map(&:strip).reject(&:empty?)
    raise CommandFailed, "PostgreSQL runtime has grants outside the closed map: #{unexpected.join(', ')}." unless unexpected.empty?
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
    missing = RUNTIME_TABLE_GRANTS.keys - tables
    raise CommandFailed, "MySQL runtime grant table missing: #{missing.join(', ')}." unless missing.empty?
    statements = ["CREATE USER '#{runtime}'@'127.0.0.1' IDENTIFIED BY '#{runtime_password}'", "CREATE USER '#{reset}'@'127.0.0.1' IDENTIFIED BY '#{reset_password}'"]
    RUNTIME_TABLE_GRANTS.each do |table, grants|
      statements << "GRANT #{grants} ON `#{@mysql_database}`.`#{table}` TO '#{runtime}'@'127.0.0.1'"
    end
    tables.each do |table|
      reset_grants = table == 'audit_events' ? 'SELECT, INSERT' : 'SELECT, INSERT, UPDATE, DELETE'
      statements << "GRANT #{reset_grants} ON `#{@mysql_database}`.`#{table}` TO '#{reset}'@'127.0.0.1'"
    end
    statements << 'FLUSH PRIVILEGES'
    @runner.run!(mysql_root_arguments, stdin_data: statements.join(";\n")+';')
    grants = @runner.run!(mysql_root_arguments + ['--batch', '--skip-column-names'], stdin_data: "SHOW GRANTS FOR '#{runtime}'@'127.0.0.1';").upcase
    raise CommandFailed, 'Reduced MySQL runtime retained forbidden DDL grants.' unless %w[DROP ALTER TRIGGER CREATE].none? { |privilege| grants.match?(/\b#{privilege}\b/) }
    RUNTIME_TABLE_GRANTS.each do |table, expected_grants|
      expected = "GRANT #{expected_grants} ON `#{@mysql_database.upcase}`.`#{table.upcase}`"
      raise CommandFailed, "MySQL runtime grant drifted for #{table}." unless grants.include?(expected)
    end
    raise CommandFailed, 'MySQL runtime received a schema wildcard grant.' if grants.include?("ON `#{@mysql_database.upcase}`.*")
    @runtime_application_environment = application_environment.merge('DB_USERNAME' => runtime, 'DB_PASSWORD' => runtime_password)
    @reset_application_environment = application_environment.merge('DB_USERNAME' => reset, 'DB_PASSWORD' => reset_password)
  end

  def expect_addendum_rollback_refusal!(expected)
    arguments = ['migrate:rollback', '--path='+MIGRATION_PATH, '--force', '--no-interaction']
    @command_catalog << ['artisan', *arguments]
    stdout, stderr, status = Open3.capture3(@runner.process_environment(application_environment), @php_binary, File.join(ROOT, 'artisan'), *arguments, unsetenv_others: true)
    raise CommandFailed, 'Retained summary addendum evidence unexpectedly rolled back.' if status.success?
    combined = stdout + stderr
    raise CommandFailed, 'Summary addendum rollback failed for an unexpected reason.' unless combined.gsub(/\s+/, '').include?(expected.gsub(/\s+/, ''))
    @result_catalog << [['artisan', *arguments], 'EXPECTED_REFUSAL']
    'PASS'
  end

  def write_addendum_evidence!(bindings:, engine_binding:, migration_duration_ms:, scenarios:)
    assert_evidence_directory!
    path = File.join(EVIDENCE_DIRECTORY, "#{Time.now.utc.strftime('%Y%m%dT%H%M%SZ')}-#{@engine}-inpatient-summary-addendum-#{SecureRandom.hex(6)}.json")
    evidence = {
      'schema_version' => 1, 'kind' => EVIDENCE_KIND, 'status' => 'PASS', 'recorded_at_utc' => Time.now.utc.iso8601,
      'claim' => 'LOCAL_DISPOSABLE_INPATIENT_SUMMARY_ADDENDUM_ONLY', 'hosted_readiness_claim' => false,
      'deployment_claim' => false, 'owner_acceptance_claim' => false,
      'source_bindings' => bindings.merge('command_catalog_sha256' => Digest::SHA256.hexdigest(JSON.generate(@command_catalog)), 'result_catalog_sha256' => Digest::SHA256.hexdigest(JSON.generate(@result_catalog))),
      'command_catalog' => @command_catalog, 'protocol_result_catalog' => @protocol_catalog,
      'boundary' => { 'application_mode' => 'SIMULATION', 'synthetic_only' => true, 'live_integrations_enabled' => false, 'disposable_local_engine' => true, 'external_database_configuration_accepted' => false },
      'engine' => engine_binding, 'migration' => { 'fresh_apply' => 'PASS', 'duration_ms_observed' => migration_duration_ms },
      'scenarios' => scenarios,
      'cleanup' => { 'database_removed' => true, 'temporary_server_removed' => true, 'temporary_user_state_removed' => true, 'temporary_worker_removed' => true },
      'open_boundaries' => [
        'Local disposable-engine evidence only; no deployment, hosted migration, UAT, owner acceptance, or production-readiness claim.',
        'This harness proves sequential replay and conflict handling only; simultaneous-worker race behavior is not claimed by this artifact.',
      ],
    }
    sanitize_evidence!(evidence)
    File.write(path, JSON.pretty_generate(evidence)+"\n", mode: 'wx', perm: 0o600)
    File.chmod(0o600, path)
    path
  end
end

if $PROGRAM_NAME == __FILE__
  begin
    unless ARGV.length == 1 && LocalInpatientSummaryAddendumPortabilityRehearsal::ENGINES.include?(ARGV.first)
      warn "usage: #{File.basename(__FILE__)} <postgresql17|mysql8411>"
      exit 64
    end
    puts JSON.generate(LocalInpatientSummaryAddendumPortabilityRehearsal.new(engine: ARGV.first).run!)
  rescue LocalInpatientSummaryAddendumPortabilityRehearsal::CommandFailed => e
    warn "inpatient summary addendum portability rehearsal failed: #{e.message}"
    exit 1
  end
end
