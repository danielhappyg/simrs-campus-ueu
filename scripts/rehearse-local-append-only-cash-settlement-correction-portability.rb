#!/usr/bin/env ruby
# frozen_string_literal: true

require 'base64'
require 'digest'
require 'json'
require 'open3'
require 'securerandom'
require 'tempfile'
require 'time'

require_relative 'rehearse-local-exact-cash-settlement-portability'

# Disposable exact-engine verification for the append-only full-cash correction
# lifecycle. No external database configuration or hosted service is accepted.
class LocalAppendOnlyCashSettlementCorrectionPortabilityRehearsal < LocalExactCashSettlementPortabilityRehearsal
  CONFIRMATION = 'YES_DISPOSABLE_LOCAL_CASH_SETTLEMENT_CORRECTION'
  CONFIRMATION_ENV = 'SIMRS_CASH_SETTLEMENT_CORRECTION_REHEARSAL_CONFIRM'
  SCRIPT_PATH = 'scripts/rehearse-local-append-only-cash-settlement-correction-portability.rb'
  CONTRACT_PATH = 'tests/Documentation/LocalAppendOnlyCashSettlementCorrectionPortabilityHarnessContractTest.rb'
  MIGRATION_PATH = 'database/migrations/2026_09_02_000800_create_append_only_cash_settlement_correction_tables.php'
  EVIDENCE_TEMPLATE_PATH = 'docs/operations/T1_LOCAL_APPEND_ONLY_CASH_SETTLEMENT_CORRECTION_EVIDENCE_TEMPLATE_2026-09-02.md'
  EVIDENCE_KIND = 'SIMRS_LOCAL_APPEND_ONLY_CASH_SETTLEMENT_CORRECTION_PORTABILITY'
  EXECUTION_STATE = 'READY_NOT_RUN'

  SCENARIOS = %w[
    fresh-migration empty-down-reapply migration-failure-guard-reinstall
    failed-install-preserves-lifetime-uniqueness
    exact-runtime-grants exact-role-denials database-check-and-append-only-refusal
    request-reject request-approve-refund-complete-replacement
    idempotent-replay changed-payload-conflict real-wait-single-durable-case
    double-review-refusal double-refund-refusal
    audit-failure-atomic-rollback predecessor-refund-net-cash-snapshot
    refund-receipt-integrity recovery-relevant-column-presence
    retained-evidence-rollback-refusal third-connection-net-cash-readback
    bounded-reset strict-cleanup
  ].freeze

  RUNTIME_READ_TABLES = %w[
    users roles permissions role_user permission_role patients encounters
    finance_bills finance_bill_versions finance_cash_settlements
    finance_settlement_operation_receipts finance_settlement_correction_cases
    finance_settlement_correction_events finance_settlement_correction_operation_receipts audit_events
  ].freeze
  RUNTIME_INSERT_TABLES = %w[
    finance_settlement_correction_cases finance_settlement_correction_events
    finance_settlement_correction_operation_receipts audit_events
  ].freeze
  RUNTIME_LOCK_TABLES = %w[
    encounters finance_bills finance_bill_versions finance_cash_settlements
    finance_settlement_correction_cases finance_settlement_correction_events
  ].freeze
  RUNTIME_TABLE_GRANTS = RUNTIME_READ_TABLES.to_h do |table|
    grants = ['SELECT']
    grants << 'INSERT' if RUNTIME_INSERT_TABLES.include?(table)
    grants << 'UPDATE' if RUNTIME_LOCK_TABLES.include?(table)
    [table, grants.join(', ')]
  end.freeze

  SOURCE_PATHS = %w[
    scripts/rehearse-local-append-only-cash-settlement-correction-portability.rb
    scripts/rehearse-local-exact-cash-settlement-portability.rb
    tests/Documentation/LocalAppendOnlyCashSettlementCorrectionPortabilityHarnessContractTest.rb
    tests/Documentation/AppendOnlyCashSettlementCorrectionV1LocalEngineeringAuthorizationTest.rb
    docs/new-simrs-rebuild/phase-1/APPEND_ONLY_CASH_SETTLEMENT_CORRECTION_V1_LOCAL_ENGINEERING_AUTHORIZATION_2026-09-02.md
    docs/operations/T1_LOCAL_APPEND_ONLY_CASH_SETTLEMENT_CORRECTION_EVIDENCE_TEMPLATE_2026-09-02.md
    database/migrations/2026_09_02_000700_create_exact_cash_settlement_and_receipt_tables.php
    database/migrations/2026_09_02_000800_create_append_only_cash_settlement_correction_tables.php
    tests/Feature/Finance/FinanceCashSettlementCoreTest.php
    tests/Feature/Authorization/CashierSupervisorAccessTest.php
    tests/Feature/Database/CashSettlementCorrectionMigrationTest.php
    app/Models/FinanceCashSettlement.php
    app/Models/FinanceSettlementCorrectionCase.php
    app/Models/FinanceSettlementCorrectionEvent.php
    app/Models/FinanceSettlementCorrectionOperationReceipt.php
    app/Support/Finance/FinanceCashSettlementFingerprint.php
    app/Support/Finance/FinanceCashSettlementNetPolicy.php
    app/Support/Finance/FinanceCashSettlementService.php
    app/Support/Finance/FinanceCashSettlementProjection.php
    app/Support/Finance/FinanceCashSettlementCorrectionActorPolicy.php
    app/Support/Finance/FinanceCashSettlementCorrectionFingerprint.php
    app/Support/Finance/FinanceCashSettlementCorrectionService.php
    app/Support/Finance/FinanceCashSettlementCorrectionProjection.php
    app/Support/Finance/FinanceAppendOnlyGuard.php
    app/Support/Finance/FinanceSqlWriteGuard.php
  ].freeze

  FEATURE_SCENARIO_TESTS = {
    'request-reject' => ['tests/Feature/Finance/FinanceCashSettlementCoreTest.php', 'test_correction_worklist_visibility_dual_control_terminal_states_and_stale_guards'],
    'request-approve-refund-complete-replacement' => ['tests/Feature/Finance/FinanceCashSettlementCoreTest.php', 'test_append_only_full_refund_restores_exact_replacement_eligibility_and_replays'],
    'idempotent-replay' => ['tests/Feature/Finance/FinanceCashSettlementCoreTest.php', 'test_append_only_full_refund_restores_exact_replacement_eligibility_and_replays'],
    'changed-payload-conflict' => ['tests/Feature/Finance/FinanceCashSettlementCoreTest.php', 'test_append_only_full_refund_restores_exact_replacement_eligibility_and_replays'],
    'double-review-refusal' => ['tests/Feature/Finance/FinanceCashSettlementCoreTest.php', 'test_append_only_full_refund_restores_exact_replacement_eligibility_and_replays'],
    'double-refund-refusal' => ['tests/Feature/Finance/FinanceCashSettlementCoreTest.php', 'test_append_only_full_refund_restores_exact_replacement_eligibility_and_replays'],
    'exact-role-denials' => ['tests/Feature/Authorization/CashierSupervisorAccessTest.php', 'test_admin_system_admin_and_mixed_role_accounts_fail_closed'],
    'audit-failure-atomic-rollback' => ['tests/Feature/Finance/FinanceCashSettlementCoreTest.php', 'test_correction_audit_failure_rolls_back_case_and_technical_receipt'],
    'predecessor-refund-net-cash-snapshot' => ['tests/Feature/Finance/FinanceCashSettlementCoreTest.php', 'test_refund_of_predecessor_after_later_payment_uses_net_cash_for_additional_replacement'],
    'refund-receipt-integrity' => ['tests/Feature/Finance/FinanceCashSettlementCoreTest.php', 'test_append_only_full_refund_restores_exact_replacement_eligibility_and_replays'],
    'database-check-and-append-only-refusal' => ['tests/Feature/Finance/FinanceCashSettlementCoreTest.php', 'test_append_only_full_refund_restores_exact_replacement_eligibility_and_replays'],
    'recovery-relevant-column-presence' => ['tests/Feature/Database/CashSettlementCorrectionMigrationTest.php', 'test_correction_schema_and_guards_are_installed'],
  }.freeze
  SQLITE_FEATURE_TESTS = FEATURE_SCENARIO_TESTS.values.uniq.freeze

  WORKER_SOURCE = <<~'PHP'
    <?php
    declare(strict_types=1);

    use App\Models\Encounter;
    use App\Models\FinanceBill;
    use App\Models\FinanceBillVersion;
    use App\Models\FinanceCashSettlement;
    use App\Models\FinanceSettlementCorrectionCase;
    use App\Models\FinanceSettlementCorrectionEvent;
    use App\Models\FinanceSettlementCorrectionOperationReceipt;
    use App\Models\FinanceSettlementOperationReceipt;
    use App\Models\Patient;
    use App\Models\Role;
    use App\Models\User;
    use App\Support\Audit\AuditEvent;
    use App\Support\Audit\AuditRecorder;
    use App\Support\Authorization\RoleCapabilityMatrix;
    use App\Support\Database\SchemaQualifier;
    use App\Support\Finance\FinanceAppendOnlyGuard;
    use App\Support\Finance\FinanceCashSettlementCorrectionProjection;
    use App\Support\Finance\FinanceCashSettlementCorrectionService;
    use App\Support\Finance\FinanceCashSettlementFingerprint;
    use App\Support\Finance\FinanceCashSettlementService;
    use App\Support\Finance\FinanceDenied;
    use App\Support\Finance\FinanceEvidenceFingerprint;
    use App\Support\Finance\FinanceMutationScope;
    use App\Support\Finance\FinanceSchemaMutationScope;
    use App\Support\Operations\SyntheticRecoverySnapshot;
    use App\Support\Simulation\SyntheticResetService;
    use Illuminate\Contracts\Console\Kernel;
    use Illuminate\Database\QueryException;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;
    use Illuminate\Support\Str;

    require (string) getenv('SIMRS_REHEARSAL_ROOT').'/vendor/autoload.php';
    $app = require (string) getenv('SIMRS_REHEARSAL_ROOT').'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    function must(bool $condition, string $label): void
    {
        if (!$condition) throw new RuntimeException('assertion failed: '.$label);
    }

    function protocol(string $state, array $extra = []): void
    {
        echo json_encode(array_merge([
            'schema_version' => 1, 'status' => 'PASS', 'protocol_state' => $state,
            'scenario' => (string) getenv('SIMRS_CORRECTION_SCENARIO'),
            'worker' => (string) getenv('SIMRS_CORRECTION_WORKER'),
        ], $extra), JSON_THROW_ON_ERROR).PHP_EOL;
        flush();
    }

    function fixture(): array
    {
        $raw = base64_decode((string) getenv('SIMRS_CORRECTION_FIXTURE'), true);
        $decoded = $raw === false ? [] : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : [];
    }

    function backendConnectionId(): int
    {
        return DB::connection()->getDriverName() === 'pgsql'
            ? (int) data_get(DB::connection()->selectOne('SELECT pg_backend_pid() AS id', [], false), 'id')
            : (int) data_get(DB::connection()->selectOne('SELECT CONNECTION_ID() AS id', [], false), 'id');
    }

    function actor(string $role): User
    {
        $actor = User::factory()->create();
        $actor->roles()->sync([Role::query()->where('slug', $role)->sole()->id]);
        return $actor->fresh();
    }

    function user(string $publicId): User
    {
        return User::query()->where('public_id', $publicId)->sole();
    }

    function prepareFixture(): array
    {
        $cashier = actor(RoleCapabilityMatrix::ROLE_CASHIER);
        $patient = Patient::factory()->create(['is_synthetic' => true]);
        $encounter = Encounter::factory()->create([
            'patient_id' => $patient->id,
            'care_setting' => Encounter::CARE_SETTING_OUTPATIENT,
            'status' => Encounter::STATUS_IN_EXAMINATION,
            'clinic_name' => 'Poli Koreksi Kas',
        ]);
        $sourceDigest = hash('sha256', 'correction-portability-source');
        $now = now();
        $bill = new FinanceBill([
            'bill_number' => 'TAG-KOR-'.$now->format('YmdHis').'-'.Str::lower(Str::random(6)),
            'encounter_id' => $encounter->id, 'patient_id' => $patient->id,
            'care_setting' => $encounter->care_setting, 'state' => FinanceBill::ISSUED_CURRENT,
            'current_version' => 1, 'current_source_event_count' => 1,
            'current_source_set_digest' => $sourceDigest,
            'current_issued_source_set_digest' => $sourceDigest,
            'current_content_digest' => str_repeat('0', 64),
        ]);
        $bill->public_id = (string) Str::ulid();
        $bill->current_content_digest = app(FinanceEvidenceFingerprint::class)->billContent($bill);

        FinanceMutationScope::run(function () use ($bill): void { $bill->save(); });
        $version = new FinanceBillVersion([
            'bill_id' => $bill->id, 'previous_version_id' => null,
            'issued_by_user_id' => $cashier->id, 'encounter_id' => $encounter->id,
            'patient_id' => $patient->id, 'version' => 1,
            'encounter_public_id_snapshot' => $encounter->public_id,
            'encounter_number_snapshot' => $encounter->public_id,
            'patient_public_id_snapshot' => $patient->public_id,
            'patient_name_snapshot' => $patient->full_name,
            'medical_record_number_snapshot' => $patient->medical_record_number,
            'care_setting' => $encounter->care_setting, 'payer_snapshot' => 'UMUM',
            'service_location_snapshot' => 'Poli Koreksi Kas',
            'coverage_profile' => FinanceBillVersion::COVERAGE_PHARMACY_V1,
            'source_set_digest' => $sourceDigest, 'source_event_count' => 1,
            'source_cutoff_at' => $now, 'gross_amount' => 9000,
            'reversal_amount' => 0, 'net_amount' => 9000,
            'issue_reason' => 'Bukti sintetis untuk uji koreksi kas.',
            'content_digest' => str_repeat('0', 64), 'issued_at' => $now, 'created_at' => $now,
        ]);
        $version->public_id = (string) Str::ulid();
        $version->content_digest = app(FinanceEvidenceFingerprint::class)->version($version, collect());
        FinanceMutationScope::run(function () use ($version): void { $version->save(); });

        $settlement = new FinanceCashSettlement([
            'receipt_number' => 'KWT-KOR-'.$now->format('YmdHis').'-'.Str::lower(Str::random(6)),
            'bill_id' => $bill->id, 'bill_version_id' => $version->id,
            'encounter_id' => $encounter->id, 'patient_id' => $patient->id,
            'cashier_user_id' => $cashier->id, 'cashier_name_snapshot' => $cashier->name,
            'bill_public_id_snapshot' => $bill->public_id, 'bill_number_snapshot' => $bill->bill_number,
            'bill_version_public_id_snapshot' => $version->public_id, 'bill_version_snapshot' => 1,
            'encounter_public_id_snapshot' => $encounter->public_id,
            'patient_public_id_snapshot' => $patient->public_id,
            'patient_name_snapshot' => $patient->full_name,
            'medical_record_number_snapshot' => $patient->medical_record_number,
            'care_setting' => $encounter->care_setting, 'coverage_profile_snapshot' => $version->coverage_profile,
            'coverage_label_snapshot' => 'Cakupan sintetis koreksi kas',
            'coverage_exclusion_snapshot' => 'Tidak mencakup integrasi atau layanan lain.',
            'payment_method' => FinanceCashSettlement::PAYMENT_CASH,
            'state' => FinanceCashSettlement::SETTLED, 'amount' => 9000,
            'prior_net_collected_amount_snapshot' => 0,
            'source_set_digest' => $sourceDigest,
            'bill_version_content_digest' => $version->content_digest,
            'content_digest' => str_repeat('0', 64), 'settled_at' => $now, 'created_at' => $now,
        ]);
        $settlement->public_id = (string) Str::ulid();
        $settlement->content_digest = app(FinanceCashSettlementFingerprint::class)->settlement($settlement);
        FinanceMutationScope::run(function () use ($settlement): void { $settlement->save(); });
        FinanceMutationScope::run(function () use ($settlement, $cashier): void {
            FinanceSettlementOperationReceipt::query()->create([
                'actor_user_id' => $cashier->id,
                'operation' => FinanceCashSettlementService::OPERATION_SETTLE,
                'idempotency_key' => 'correction-portability-original-settlement',
                'payload_digest' => hash('sha256', 'correction-portability-original-payload'),
                'settlement_public_id' => $settlement->public_id,
                'bill_version_public_id' => $settlement->bill_version_public_id_snapshot,
                'amount' => $settlement->amount, 'source_set_digest' => $settlement->source_set_digest,
                'bill_version_content_digest' => $settlement->bill_version_content_digest,
                'settlement_content_digest' => $settlement->content_digest,
                'result_digest' => app(FinanceCashSettlementFingerprint::class)->result($settlement),
                'request_correlation_id' => null, 'completed_at' => now(),
            ]);
        });
        $audit = app(AuditRecorder::class)->record(
            'finance.workflow.mutate', 'finance_record', $bill->public_id, $cashier, 'SUCCESS',
            metadata: [
                'operation' => FinanceCashSettlementService::OPERATION_SETTLE,
                'state' => FinanceBill::ISSUED_CURRENT, 'version' => 1,
                'settlement_public_id' => $settlement->public_id,
                'settlement_content_digest' => $settlement->content_digest,
                'settlement_result_digest' => app(FinanceCashSettlementFingerprint::class)->result($settlement),
            ],
        );
        must($audit !== null, 'original settlement audit');
        return [
            'cashier' => $cashier->public_id, 'encounter' => $encounter->public_id,
            'bill' => $bill->public_id, 'version' => $version->public_id,
            'settlement' => $settlement->public_id, 'settlement_digest' => $settlement->content_digest,
            'idempotency_key' => 'correction-portability-request-0001', 'amount' => 9000,
        ];
    }

    function requestRace(array $f): array
    {
        $cashier = user($f['cashier']);
        if ((string) getenv('SIMRS_CORRECTION_WORKER') === 'A') {
            return DB::transaction(function () use ($f, $cashier): array {
                $encounter = Encounter::query()->where('public_id', $f['encounter'])->lockForUpdate()->sole();
                protocol('HOLDING', ['backend_connection_id' => backendConnectionId(), 'lock_order_trace' => ['encounters'], 'locked_encounter_id' => $encounter->id]);
                usleep(((int) getenv('SIMRS_CORRECTION_HOLD_MS')) * 1000);
                $result = app(FinanceCashSettlementCorrectionService::class)->request(
                    $f['settlement'], $cashier, FinanceSettlementCorrectionCase::WRONG_BILL,
                    'Pelunasan sintetis perlu koreksi penuh.', $f['settlement_digest'], $f['idempotency_key'],
                );
                return ['outcome' => $result->replayed ? 'REPLAYED' : 'APPLIED', 'case' => $result->record->public_id];
            });
        }
        $result = app(FinanceCashSettlementCorrectionService::class)->request(
            $f['settlement'], $cashier, FinanceSettlementCorrectionCase::WRONG_BILL,
            'Pelunasan sintetis perlu koreksi penuh.', $f['settlement_digest'], $f['idempotency_key'],
        );
        return ['outcome' => $result->replayed ? 'REPLAYED' : 'APPLIED', 'case' => $result->record->public_id];
    }

    function completeAndReplace(array $f): array
    {
        $cashier = user($f['cashier']);
        $supervisor = actor(RoleCapabilityMatrix::ROLE_CASHIER_SUPERVISOR);
        $case = FinanceSettlementCorrectionCase::query()->sole();
        $service = app(FinanceCashSettlementCorrectionService::class);
        $projection = app(FinanceCashSettlementCorrectionProjection::class);
        $pending = $projection->case($case->public_id, $supervisor);
        $service->review(
            $case->public_id, $supervisor, FinanceSettlementCorrectionEvent::REFUND_APPROVED,
            'Pengembalian penuh diperiksa dalam rehearsal exact engine.', $pending['fingerprint'],
            'correction-portability-review-0001',
        );
        $approved = $projection->case($case->public_id, $supervisor);
        $doubleReview = false;
        try {
            $service->review(
                $case->public_id, $supervisor, FinanceSettlementCorrectionEvent::REFUND_APPROVED,
                'Tinjauan kedua harus ditolak.', $approved['fingerprint'],
                'correction-portability-review-duplicate',
            );
        } catch (FinanceDenied $exception) {
            $doubleReview = $exception->reason === 'review_already_completed';
        }
        must($doubleReview, 'double review refusal');

        $service->completeRefund(
            $case->public_id, $supervisor, $approved['fingerprint'],
            'correction-portability-complete-0001',
        );
        $completed = $projection->case($case->public_id, $supervisor);
        $doubleRefund = false;
        try {
            $service->completeRefund(
                $case->public_id, $supervisor, $completed['fingerprint'],
                'correction-portability-complete-duplicate',
            );
        } catch (FinanceDenied $exception) {
            $doubleRefund = $exception->reason === 'refund_not_approved';
        }
        must($doubleRefund, 'double refund refusal');
        $refundReceipt = $projection->refundReceipt($case->public_id, $cashier);
        must($refundReceipt['amount'] === $f['amount'], 'refund receipt exact amount');

        $original = FinanceCashSettlement::query()->where('public_id', $f['settlement'])->sole();
        $replacement = $original->replicate();
        $replacement->public_id = (string) Str::ulid();
        $replacement->receipt_number = 'KWT-KOR-GANTI-'.now()->format('YmdHis').'-'.Str::lower(Str::random(6));
        $replacement->prior_net_collected_amount_snapshot = 0;
        $replacement->settled_at = now();
        $replacement->created_at = now();
        $replacement->content_digest = str_repeat('0', 64);
        $replacement->content_digest = app(FinanceCashSettlementFingerprint::class)->settlement($replacement);
        FinanceMutationScope::run(function () use ($replacement, $cashier): void {
            $replacement->save();
            FinanceSettlementOperationReceipt::query()->create([
                'actor_user_id' => $cashier->id,
                'operation' => FinanceCashSettlementService::OPERATION_SETTLE,
                'idempotency_key' => 'correction-portability-replacement-0001',
                'payload_digest' => hash('sha256', 'correction-portability-replacement-payload'),
                'settlement_public_id' => $replacement->public_id,
                'bill_version_public_id' => $replacement->bill_version_public_id_snapshot,
                'amount' => $replacement->amount,
                'source_set_digest' => $replacement->source_set_digest,
                'bill_version_content_digest' => $replacement->bill_version_content_digest,
                'settlement_content_digest' => $replacement->content_digest,
                'result_digest' => app(FinanceCashSettlementFingerprint::class)->result($replacement),
                'request_correlation_id' => null,
                'completed_at' => now(),
            ]);
        });
        $audit = app(AuditRecorder::class)->record(
            'finance.workflow.mutate', 'finance_record', $replacement->bill_public_id_snapshot,
            $cashier, 'SUCCESS', metadata: [
                'operation' => FinanceCashSettlementService::OPERATION_SETTLE,
                'state' => FinanceBill::ISSUED_CURRENT,
                'version' => $replacement->bill_version_snapshot,
                'settlement_public_id' => $replacement->public_id,
                'settlement_content_digest' => $replacement->content_digest,
                'settlement_result_digest' => app(FinanceCashSettlementFingerprint::class)->result($replacement),
            ],
        );
        must($audit !== null, 'replacement audit');

        return [
            'outcome' => 'COMPLETED_AND_REPLACED', 'double_review_refused' => true,
            'double_refund_refused' => true, 'replacement_public_id' => $replacement->public_id,
            'replacement_content_digest' => $replacement->content_digest,
        ];
    }

    function verifyNetCash(array $f): array
    {
        $cashier = user($f['cashier']);
        $case = FinanceSettlementCorrectionCase::query()->sole();
        $settlements = FinanceCashSettlement::query()->orderBy('id')->get();
        $events = FinanceSettlementCorrectionEvent::query()->orderBy('sequence')->get();
        $operationReceipts = FinanceSettlementCorrectionOperationReceipt::query()->get();
        must($settlements->count() === 2, 'original plus one replacement settlement');
        must($events->count() === 2, 'approval plus completion events');
        must($operationReceipts->count() === 3, 'request review and completion receipts');
        $refundAmount = (int) $events->where('event_type', FinanceSettlementCorrectionEvent::REFUND_COMPLETED)->sum('amount');
        $rawCollected = (int) $settlements->sum('amount');
        $netCollected = $rawCollected - $refundAmount;
        $replacement = $settlements->last();
        must($rawCollected === 18000, 'raw collected amount');
        must($refundAmount === 9000, 'completed refund amount');
        must($netCollected === 9000, 'net cash readback');
        must($replacement->prior_net_collected_amount_snapshot === 0, 'replacement prior net snapshot');
        must(hash_equals($replacement->content_digest, app(FinanceCashSettlementFingerprint::class)->settlement($replacement)), 'replacement digest');
        $receipt = app(FinanceCashSettlementCorrectionProjection::class)->refundReceipt($case->public_id, $cashier);
        must($receipt['state'] === FinanceSettlementCorrectionEvent::REFUND_COMPLETED, 'refund receipt terminal state');
        must(DB::table(SchemaQualifier::table('audit_events'))->where('metadata->operation', FinanceCashSettlementCorrectionService::OPERATION_REQUEST)->where('outcome', 'SUCCESS')->count() === 1, 'one request success audit');
        return [
            'outcome' => 'NET_CASH_RECONCILED', 'independent_connection' => true,
            'settlements' => 2, 'events' => 2, 'correction_operation_receipts' => 3,
            'raw_collected_amount' => $rawCollected, 'completed_refund_amount' => $refundAmount,
            'net_collected_amount' => $netCollected, 'replacement_prior_net_snapshot' => 0,
            'receipt_integrity' => true,
        ];
    }

    function recoveryAuditProof(): array
    {
        $snapshot = app(SyntheticRecoverySnapshot::class);
        $method = (new ReflectionClass(SyntheticRecoverySnapshot::class))
            ->getMethod('financeCashSettlementAuditMismatchCount');
        must((int) $method->invoke($snapshot) === 0, 'healthy same-version replacement audit recovery');
        $settlements = FinanceCashSettlement::query()->orderBy('id')->get();
        must($settlements->count() === 2, 'two same-version retained settlements for recovery');
        $probes = 0;
        foreach ($settlements as $settlement) {
            $audit = AuditEvent::query()
                ->where('action', 'finance.workflow.mutate')
                ->where('outcome', 'SUCCESS')
                ->where('metadata->operation', FinanceCashSettlementService::OPERATION_SETTLE)
                ->where('metadata->settlement_public_id', $settlement->public_id)->sole();
            must(($audit->metadata['settlement_content_digest'] ?? null) === $settlement->content_digest, 'uniquely bound settlement audit digest');
            DB::beginTransaction();
            try {
                DB::table(SchemaQualifier::table('audit_events'))->where('id', $audit->id)->delete();
                must((int) $method->invoke($snapshot) === 1, 'deleted audit cannot be masked by sibling settlement');
                $probes++;
            } finally {
                DB::rollBack();
            }
            DB::beginTransaction();
            try {
                $metadata = $audit->metadata;
                $metadata['settlement_content_digest'] = str_repeat('f', 64);
                DB::table(SchemaQualifier::table('audit_events'))->where('id', $audit->id)
                    ->update(['metadata' => json_encode($metadata, JSON_THROW_ON_ERROR)]);
                must((int) $method->invoke($snapshot) === 1, 'tampered audit cannot be masked by sibling settlement');
                $probes++;
            } finally {
                DB::rollBack();
            }
        }
        must((int) $method->invoke($snapshot) === 0, 'audit recovery restored after rollback probes');
        return [
            'outcome' => 'RECOVERY_AUDIT_RECONCILED', 'healthy_audit_mismatches' => 0,
            'same_version_settlements' => 2, 'independent_delete_or_tamper_probes' => $probes,
            'sibling_audit_cannot_mask_missing_or_tampered_binding' => true,
        ];
    }

    function schemaGuards(): array
    {
        $driver = DB::connection()->getDriverName();
        $required = ['fscc_values_ck', 'fsce_transition_ck', 'fscor_result_ck', 'fcs_prior_net_snapshot_ck'];
        if ($driver === 'pgsql') {
            $checks = collect(DB::select("SELECT constraint_name FROM information_schema.table_constraints WHERE table_schema='laravel' AND constraint_type='CHECK'"))->pluck('constraint_name')->all();
            $triggers = collect(DB::select("SELECT event_object_table, trigger_name FROM information_schema.triggers WHERE trigger_schema='laravel'"));
            $indexes = collect(DB::select("SELECT indexname FROM pg_indexes WHERE schemaname='laravel' AND tablename='finance_settlement_correction_operation_receipts'"))->pluck('indexname')->all();
            $operationReceiptTriggers = collect(DB::select("SELECT t.tgname AS trigger_name FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid JOIN pg_namespace n ON n.oid=c.relnamespace WHERE NOT t.tgisinternal AND n.nspname='laravel' AND c.relname='finance_settlement_correction_operation_receipts'"))->pluck('trigger_name')->all();
        } else {
            $checks = collect(DB::select('SELECT CONSTRAINT_NAME AS constraint_name FROM information_schema.table_constraints WHERE table_schema=DATABASE() AND constraint_type=\'CHECK\''))->pluck('constraint_name')->all();
            $triggers = collect(DB::select('SELECT EVENT_OBJECT_TABLE AS event_object_table, TRIGGER_NAME AS trigger_name FROM information_schema.triggers WHERE trigger_schema=DATABASE()'));
            $indexes = collect(DB::select("SELECT DISTINCT INDEX_NAME AS indexname FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='finance_settlement_correction_operation_receipts'"))->pluck('indexname')->all();
            $operationReceiptTriggers = $triggers
                ->filter(fn ($row) => data_get($row, 'event_object_table') === 'finance_settlement_correction_operation_receipts')
                ->pluck('trigger_name')->all();
        }
        foreach ($required as $name) must(in_array($name, $checks, true), 'required check '.$name);
        foreach (['finance_settlement_correction_cases', 'finance_settlement_correction_events', 'finance_settlement_correction_operation_receipts'] as $table) {
            $count = $triggers->filter(fn ($row) => data_get($row, 'event_object_table') === $table)->count();
            must($count >= ($driver === 'pgsql' ? 1 : 2), 'append-only triggers '.$table);
        }
        $expectedTriggers = $driver === 'pgsql'
            ? ['fscor_immutable', 'fscor_truncate_guard']
            : ['fscor_immutable_update', 'fscor_immutable_delete'];
        foreach ($expectedTriggers as $name) must(in_array($name, $operationReceiptTriggers, true), 'short operation receipt trigger '.$name);
        must(in_array('fscor_public_id_uq', $indexes, true), 'short operation receipt public-id index');
        must(Schema::hasColumn(SchemaQualifier::table('finance_cash_settlements'), 'prior_net_collected_amount_snapshot'), 'recovery-relevant snapshot column');
        return [
            'outcome' => 'GUARDED', 'checks' => count($required), 'correction_tables' => 3,
            'operation_receipt_short_trigger_inventory' => $expectedTriggers,
            'operation_receipt_public_id_index' => 'fscor_public_id_uq', 'snapshot_column' => true,
        ];
    }

    function uniqueBarrierNames(): array
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            return collect(DB::select("SELECT indexname FROM pg_indexes WHERE schemaname='laravel' AND tablename='finance_cash_settlements'"))->pluck('indexname')->all();
        }
        return collect(DB::select("SELECT DISTINCT INDEX_NAME AS indexname FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='finance_cash_settlements'"))->pluck('indexname')->all();
    }

    function failureSafety(): array
    {
        $migration = require (string) getenv('SIMRS_REHEARSAL_ROOT').'/database/migrations/2026_09_02_000800_create_append_only_cash_settlement_correction_tables.php';
        $migration->down();
        FinanceSchemaMutationScope::run(fn () => Schema::create(
            SchemaQualifier::table('finance_settlement_correction_events'),
            function ($table): void { $table->id(); },
        ));
        $failed = false;
        try { $migration->up(); } catch (Throwable) { $failed = true; }
        must($failed, 'forced installation failure');
        $indexes = uniqueBarrierNames();
        must(in_array('fcs_bill_version_uq', $indexes, true), 'bill-version unique preserved');
        must(in_array('fcs_bill_version_number_uq', $indexes, true), 'bill-version-number unique preserved');
        $driver = DB::connection()->getDriverName();
        $triggerCount = $driver === 'pgsql'
            ? DB::table('information_schema.triggers')->where('trigger_schema', 'laravel')->where('event_object_table', 'finance_cash_settlements')->count()
            : DB::table('information_schema.triggers')->whereRaw('trigger_schema=DATABASE()')->where('event_object_table', 'finance_cash_settlements')->count();
        must($triggerCount >= ($driver === 'pgsql' ? 1 : 2), 'guards reinstalled after failed up');

        FinanceSchemaMutationScope::run(function () use ($driver): void {
            FinanceAppendOnlyGuard::remove();
            try {
                Schema::dropIfExists(SchemaQualifier::table('finance_settlement_correction_operation_receipts'));
                Schema::dropIfExists(SchemaQualifier::table('finance_settlement_correction_events'));
                Schema::dropIfExists(SchemaQualifier::table('finance_settlement_correction_cases'));
                $table = SchemaQualifier::table('finance_cash_settlements');
                if ($driver === 'pgsql') DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS fcs_prior_net_snapshot_ck");
                if ($driver === 'mysql') {
                    $checkExists = DB::table('information_schema.table_constraints')
                        ->whereRaw('constraint_schema=DATABASE()')
                        ->where('table_name', 'finance_cash_settlements')
                        ->where('constraint_name', 'fcs_prior_net_snapshot_ck')->exists();
                    if ($checkExists) DB::statement("ALTER TABLE {$table} DROP CHECK fcs_prior_net_snapshot_ck");
                }
                Schema::table($table, function ($blueprint): void {
                    $blueprint->dropIndex('fcs_bill_version_idx');
                    $blueprint->dropIndex('fcs_bill_version_number_idx');
                    $blueprint->dropColumn('prior_net_collected_amount_snapshot');
                });
            } finally { FinanceAppendOnlyGuard::install(); }
        });
        $migration->up();
        return ['outcome' => 'FAIL_CLOSED', 'forced_failure' => true, 'old_unique_barriers_preserved' => 2, 'guard_reinstalled' => true, 'reapply' => true];
    }

    function privilegeGuards(array $f): array
    {
        $cashier = user($f['cashier']);
        $replay = app(FinanceCashSettlementCorrectionService::class)->request(
            $f['settlement'], $cashier, FinanceSettlementCorrectionCase::WRONG_BILL,
            'Pelunasan sintetis perlu koreksi penuh.', $f['settlement_digest'], $f['idempotency_key'],
        );
        must($replay->replayed, 'runtime same-key replay');
        $case = FinanceSettlementCorrectionCase::query()->sole();
        $denials = 0;
        foreach (['finance_settlement_correction_cases', 'finance_settlement_correction_operation_receipts'] as $table) {
            try {
                FinanceMutationScope::run(fn () => DB::table(SchemaQualifier::table($table))->delete());
            } catch (QueryException) { $denials++; }
        }
        must($denials === 2, 'runtime delete privilege denials');
        try {
            FinanceMutationScope::run(fn () => DB::table(SchemaQualifier::table('finance_settlement_correction_cases'))->where('id', $case->id)->update(['amount' => 1]));
            throw new RuntimeException('database append-only update unexpectedly succeeded');
        } catch (QueryException) { $denials++; }
        must($denials === 3, 'runtime append-only update denial');
        $bypassDenied = false;
        try {
            DB::transaction(function () use ($case): void {
                if (DB::connection()->getDriverName() === 'pgsql') {
                    DB::statement("SET LOCAL simrs.synthetic_reset = '1'");
                } else {
                    DB::statement('SET @simrs_synthetic_reset = 1');
                }
                FinanceMutationScope::run(fn () => DB::table(SchemaQualifier::table('finance_settlement_correction_cases'))
                    ->where('id', $case->id)->update(['amount' => 1]));
                throw new RuntimeException('unsafe_user_settable_bypass_probe_rolled_back');
            });
        } catch (QueryException) {
            $bypassDenied = true;
        } finally {
            if (DB::connection()->getDriverName() === 'mysql') DB::statement('SET @simrs_synthetic_reset = 0');
        }
        must($bypassDenied, 'runtime cannot activate synthetic reset bypass');
        return [
            'outcome' => 'LEAST_PRIVILEGE', 'replay' => true,
            'delete_privilege_denials' => 2, 'database_guard_update_denials' => 1,
            'session_bypass_escalation_denied' => true,
        ];
    }

    function resetAndVerify(array $f): array
    {
        $auditBefore = DB::table(SchemaQualifier::table('audit_events'))->count();
        app(SyntheticResetService::class)->reset([
            'actor' => user($f['cashier']),
            'reason' => 'append_only_cash_settlement_correction_portability',
        ]);
        foreach ([
            'finance_settlement_correction_operation_receipts',
            'finance_settlement_correction_events',
            'finance_settlement_correction_cases',
            'finance_settlement_operation_receipts',
            'finance_cash_settlements',
            'finance_bill_versions',
            'finance_bills',
        ] as $table) {
            must(DB::table(SchemaQualifier::table($table))->count() === 0, 'bounded reset '.$table);
        }
        $auditAfter = DB::table(SchemaQualifier::table('audit_events'))->count();
        must($auditAfter >= $auditBefore + 2, 'reset audit boundary');
        return [
            'outcome' => 'BOUNDED_RESET', 'synthetic_finance_rows_removed' => true,
            'audit_evidence_preserved' => true, 'installer_owner_bypass_proven' => true,
            'reset_audit_events_added' => $auditAfter - $auditBefore,
        ];
    }

    $action = (string) getenv('SIMRS_CORRECTION_ACTION');
    $f = fixture();
    protocol('STARTED', ['backend_connection_id' => backendConnectionId()]);
    try {
        $result = match ($action) {
            'prepare' => ['outcome' => 'APPLIED', 'fixture' => prepareFixture()],
            'request_race' => requestRace($f),
            'complete_replace' => completeAndReplace($f),
            'verify_net' => verifyNetCash($f),
            'recovery_audit' => recoveryAuditProof(),
            'schema_guards' => schemaGuards(),
            'failure_safety' => failureSafety(),
            'privilege_guards' => privilegeGuards($f),
            'reset' => resetAndVerify($f),
            default => throw new RuntimeException('unknown correction rehearsal action'),
        };
        protocol('COMMITTED', $result);
    } catch (Throwable $exception) {
        $query = $exception instanceof QueryException ? $exception : null;
        $errorInfo = $query?->errorInfo ?? [];
        echo json_encode([
            'schema_version' => 1, 'status' => 'BLOCKED', 'protocol_state' => 'FAILED',
            'scenario' => (string) getenv('SIMRS_CORRECTION_SCENARIO'),
            'worker' => (string) getenv('SIMRS_CORRECTION_WORKER'),
            'exception_class' => get_class($exception),
            'exception_fingerprint' => hash('sha256', get_class($exception)."\0".$exception->getMessage()),
            'diagnostic_message' => ($exception instanceof LogicException || $exception instanceof RuntimeException || $exception instanceof ErrorException)
                ? mb_substr($exception->getMessage(), 0, 300) : null,
            'failure_stage' => $action, 'sql_state' => (string) ($errorInfo[0] ?? ''),
            'driver_code' => (string) ($errorInfo[1] ?? ''),
            'query_sha256' => $query ? hash('sha256', $query->getSql()) : null,
        ], JSON_THROW_ON_ERROR).PHP_EOL;
        exit(1);
    }
  PHP

  def assert_contract!
    unless @operator_environment[CONFIRMATION_ENV] == CONFIRMATION
      raise CommandFailed, "Set #{CONFIRMATION_ENV}=#{CONFIRMATION} to authorize the disposable local rehearsal."
    end
    rejected = FORBIDDEN_ENVIRONMENT.select { |name| !@operator_environment.fetch(name, '').to_s.strip.empty? }
    raise CommandFailed, "Correction rehearsal refuses inherited overrides: #{rejected.join(', ')}." unless rejected.empty?
    LocalPortabilityFullSuiteRehearsal.instance_method(:assert_contract!).bind(self).call
    SOURCE_PATHS.each { |path| safe_source_path(path) }
    raise CommandFailed, 'Correction scenario catalogue drifted.' unless SCENARIOS.length == 22 && SCENARIOS.uniq.length == 22
    raise CommandFailed, 'Embedded correction worker source is unexpectedly small.' unless WORKER_SOURCE.bytesize > 12_000
  end

  def application_environment
    LocalPortabilityFullSuiteRehearsal.instance_method(:application_environment).bind(self).call.merge(
      CONFIRMATION_ENV => CONFIRMATION,
      'BPJS_INTEGRATION_ENABLED' => 'false', 'VCLAIM_ENABLED' => 'false',
      'SATUSEHAT_ENABLED' => 'false', 'LIS_INTEGRATION_ENABLED' => 'false',
      'PACS_INTEGRATION_ENABLED' => 'false', 'COLUMNS' => '300'
    )
  end

  def current_correction_bindings
    files = SOURCE_PATHS.to_h { |path| [path, Digest::SHA256.file(safe_source_path(path)).hexdigest] }
    aggregate = Digest::SHA256.hexdigest(JSON.generate(files.sort.to_h))
    {
      'files' => files, 'aggregate_sha256' => aggregate, 'application_source_sha256' => aggregate,
      'worker_source_sha256' => Digest::SHA256.hexdigest(WORKER_SOURCE),
      'scenario_catalog_sha256' => Digest::SHA256.hexdigest(JSON.generate(SCENARIOS)),
      'runtime_grant_catalog_sha256' => Digest::SHA256.hexdigest(JSON.generate(RUNTIME_TABLE_GRANTS)),
      'sqlite_gate_catalog_sha256' => Digest::SHA256.hexdigest(JSON.generate(SQLITE_FEATURE_TESTS)),
    }
  end

  def run_sqlite_gate!
    assert_contract!
    results = SQLITE_FEATURE_TESTS.map do |path, method|
      source = File.read(safe_source_path(path), encoding: Encoding::UTF_8)
      raise CommandFailed, "SQLite-bound method missing: #{path}##{method}." unless source.include?("function #{method}")
      stdout, stderr, status = Open3.capture3(
        @runner.process_environment(sqlite_application_environment), @php_binary, File.join(ROOT, 'artisan'),
        'test', path, "--filter=#{method}", unsetenv_others: true
      )
      raise CommandFailed, "SQLite gate failed: #{(stdout + stderr).lines.last(12).join}" unless status.success?
      { 'path' => path, 'method' => method, 'output_sha256' => Digest::SHA256.hexdigest(stdout + stderr) }
    end
    { 'status' => 'PASS', 'engine' => 'sqlite', 'test_count' => results.length,
      'catalog_sha256' => Digest::SHA256.hexdigest(JSON.generate(SQLITE_FEATURE_TESTS)),
      'result_catalog_sha256' => Digest::SHA256.hexdigest(JSON.generate(results)), 'tests' => results }
  end

  def worker_environment(action:, scenario:, worker:, fixture:, hold_ms:, connection_environment: nil, startup_barrier: nil)
    (connection_environment || @runtime_application_environment || application_environment).merge(
      'SIMRS_REHEARSAL_ROOT' => ROOT, 'SIMRS_CORRECTION_ACTION' => action,
      'SIMRS_CORRECTION_SCENARIO' => scenario, 'SIMRS_CORRECTION_WORKER' => worker,
      'SIMRS_CORRECTION_HOLD_MS' => hold_ms.to_s,
      'SIMRS_CORRECTION_FIXTURE' => Base64.strict_encode64(JSON.generate(fixture || {}))
    )
  end

  def create_worker_file!
    @worker_tempfile = Tempfile.new(['simrs-cash-settlement-correction-portability-', '.php'])
    @worker_tempfile.binmode
    @worker_tempfile.write(WORKER_SOURCE)
    @worker_tempfile.flush
    @worker_tempfile.chmod(0o600)
    @worker_tempfile.close
  end

  def bind_feature_evidence!(bindings)
    bindings.to_h do |label, (path, method)|
      source = File.read(safe_source_path(path), encoding: Encoding::UTF_8)
      raise CommandFailed, "Correction feature method missing: #{path}##{method}." unless source.include?("function #{method}")
      cache_key = [path, method]
      proof = (@filtered_feature_evidence_cache ||= {})[cache_key] ||= begin
        recreate_application_schema_outside_guard!
        recorded_artisan!('test', path, "--filter=#{method}")
        { 'status' => 'PASS', 'proof_kind' => 'FILTERED_EXACT_ENGINE_FEATURE_TEST', 'path' => path, 'method' => method }
      end
      [label, proof.merge('scenario_binding' => label)]
    end
  end

  def start_correction_worker!(action:, scenario:, fixture:, worker:, hold_ms:, connection_environment: nil)
    @command_catalog << ['cash-settlement-correction-worker', action, "--scenario=#{scenario}", "--worker=#{worker}"]
    stdin, stdout, stderr, wait_thread = Open3.popen3(
      @runner.process_environment(worker_environment(action: action, scenario: scenario, worker: worker,
        fixture: fixture, hold_ms: hold_ms, connection_environment: connection_environment)),
      @php_binary, @worker_tempfile.path, unsetenv_others: true
    )
    stdin.close
    process = Worker.new(stdout: stdout, stderr: stderr, wait_thread: wait_thread,
      stderr_reader: Thread.new { stderr.read }, scenario: scenario, label: worker)
    @workers << process
    process
  end

  def run_request_race!(fixture)
    scenario = 'real-wait-single-durable-case'
    first = start_correction_worker!(action: 'request_race', scenario: scenario, fixture: fixture,
      worker: 'A', hold_ms: HOLD_MS, connection_environment: @runtime_application_environment)
    first_started = await_protocol!(first, 'STARTED')
    holding = await_protocol!(first, 'HOLDING')
    raise CommandFailed, 'Correction lock trace drifted.' unless holding.fetch('lock_order_trace') == ['encounters']
    second = start_correction_worker!(action: 'request_race', scenario: scenario, fixture: fixture,
      worker: 'B', hold_ms: 0, connection_environment: @runtime_application_environment)
    second_started = await_protocol!(second, 'STARTED')
    raise CommandFailed, 'Correction race did not expose a real database wait.' unless observe_real_database_wait!(Integer(second_started.fetch('backend_connection_id')))
    finals = [await_final!(first), await_final!(second)]
    outcomes = finals.map { |document| document.fetch('outcome') }.sort
    raise CommandFailed, "Correction race outcomes drifted: #{outcomes.join(',')}." unless outcomes == %w[APPLIED REPLAYED]
    { 'status' => 'PASS', 'proof_kind' => 'OBSERVED_DATABASE_WAIT', 'outcomes' => outcomes,
      'real_database_wait_observed' => true, 'independent_application_processes' => 2,
      'first_backend_connection_id_sha256' => Digest::SHA256.hexdigest(first_started.fetch('backend_connection_id').to_s),
      'second_backend_connection_id_sha256' => Digest::SHA256.hexdigest(second_started.fetch('backend_connection_id').to_s),
      'observed_lock_order' => ['encounters'], 'deadlock_observed' => false }
  ensure
    terminate_workers!
  end

  def provision_postgres_runtime_identities!
    runtime = "simrs_runtime_#{@run_token}"
    reset = "simrs_reset_#{@run_token}"
    tables = @runner.run!(postgres_psql_arguments(@postgres_database) + ['--tuples-only', '--no-align', '--command', "SELECT tablename FROM pg_tables WHERE schemaname='laravel' ORDER BY tablename"], env: postgres_tool_environment).lines.map(&:strip).reject(&:empty?)
    missing = RUNTIME_TABLE_GRANTS.keys - tables
    raise CommandFailed, "PostgreSQL correction grant table missing: #{missing.join(',')}." unless missing.empty?
    sequences = @runner.run!(postgres_psql_arguments(@postgres_database) + ['--tuples-only', '--no-align', '--command', "SELECT sequencename FROM pg_sequences WHERE schemaname='laravel' ORDER BY sequencename"], env: postgres_tool_environment).lines.map(&:strip).reject(&:empty?)
    statements = [%(CREATE ROLE "#{runtime}" LOGIN), %(CREATE ROLE "#{reset}" LOGIN), %(GRANT CONNECT ON DATABASE "#{@postgres_database}" TO "#{runtime}", "#{reset}"), %(GRANT USAGE ON SCHEMA "laravel" TO "#{runtime}", "#{reset}")]
    RUNTIME_TABLE_GRANTS.each { |table, grants| statements << %(GRANT #{grants} ON TABLE "laravel"."#{table}" TO "#{runtime}") }
    tables.each { |table| statements << %(GRANT #{table == 'audit_events' ? 'SELECT, INSERT' : 'SELECT, INSERT, UPDATE, DELETE'} ON TABLE "laravel"."#{table}" TO "#{reset}") }
    sequences.each do |sequence|
      statements << %(GRANT USAGE, SELECT ON SEQUENCE "laravel"."#{sequence}" TO "#{runtime}") if RUNTIME_INSERT_TABLES.any? { |table| sequence == "#{table}_id_seq" }
      statements << %(GRANT USAGE, SELECT, UPDATE ON SEQUENCE "laravel"."#{sequence}" TO "#{reset}")
    end
    @runner.run!(postgres_psql_arguments(@postgres_database) + ['--set', 'ON_ERROR_STOP=1', '--command', statements.join(";\n")+';'], env: postgres_tool_environment)
    RUNTIME_TABLE_GRANTS.each do |table, expected|
      actual = @runner.run!(postgres_psql_arguments(@postgres_database) + ['--tuples-only', '--no-align', '--command', "SELECT string_agg(privilege_type, ',' ORDER BY privilege_type) FROM information_schema.role_table_grants WHERE grantee='#{runtime}' AND table_schema='laravel' AND table_name='#{table}'"], env: postgres_tool_environment).strip
      raise CommandFailed, "PostgreSQL correction grant drifted for #{table}." unless actual == expected.split(', ').sort.join(',')
    end
    @runtime_application_environment = application_environment.merge('DB_USERNAME' => runtime, 'DB_PASSWORD' => '')
    @reset_application_environment = application_environment.merge('DB_USERNAME' => reset, 'DB_PASSWORD' => '')
  end

  def provision_mysql_runtime_identities!
    runtime = "simrs_runtime_#{@run_token}"
    reset = "simrs_reset_#{@run_token}"
    runtime_password = SecureRandom.hex(24)
    reset_password = SecureRandom.hex(24)
    tables = @runner.run!(mysql_root_arguments + ['--batch', '--skip-column-names'], stdin_data: "SELECT TABLE_NAME FROM information_schema.tables WHERE table_schema='#{@mysql_database}' ORDER BY TABLE_NAME;").lines.map(&:strip).reject(&:empty?)
    missing = RUNTIME_TABLE_GRANTS.keys - tables
    raise CommandFailed, "MySQL correction grant table missing: #{missing.join(',')}." unless missing.empty?
    statements = ["CREATE USER '#{runtime}'@'127.0.0.1' IDENTIFIED BY '#{runtime_password}'", "CREATE USER '#{reset}'@'127.0.0.1' IDENTIFIED BY '#{reset_password}'"]
    RUNTIME_TABLE_GRANTS.each { |table, grants| statements << "GRANT #{grants} ON `#{@mysql_database}`.`#{table}` TO '#{runtime}'@'127.0.0.1'" }
    tables.each { |table| statements << "GRANT #{table == 'audit_events' ? 'SELECT, INSERT' : 'SELECT, INSERT, UPDATE, DELETE'} ON `#{@mysql_database}`.`#{table}` TO '#{reset}'@'127.0.0.1'" }
    statements << 'FLUSH PRIVILEGES'
    @runner.run!(mysql_root_arguments, stdin_data: statements.join(";\n")+';')
    RUNTIME_TABLE_GRANTS.each do |table, expected|
      sql = "SELECT GROUP_CONCAT(PRIVILEGE_TYPE ORDER BY PRIVILEGE_TYPE SEPARATOR ',') FROM information_schema.TABLE_PRIVILEGES WHERE GRANTEE=CONCAT(CHAR(39),'#{runtime}',CHAR(39),'@',CHAR(39),'127.0.0.1',CHAR(39)) AND TABLE_SCHEMA='#{@mysql_database}' AND TABLE_NAME='#{table}';"
      actual = @runner.run!(mysql_root_arguments + ['--batch', '--skip-column-names'], stdin_data: sql).strip
      raise CommandFailed, "MySQL correction grant drifted for #{table}." unless actual == expected.split(', ').sort.join(',')
    end
    grants = @runner.run!(mysql_root_arguments + ['--batch', '--skip-column-names'], stdin_data: "SHOW GRANTS FOR '#{runtime}'@'127.0.0.1';").upcase
    raise CommandFailed, 'MySQL correction runtime received schema wildcard grant.' if grants.include?("ON `#{@mysql_database.upcase}`.*")
    @runtime_application_environment = application_environment.merge('DB_USERNAME' => runtime, 'DB_PASSWORD' => runtime_password)
    @reset_application_environment = application_environment.merge('DB_USERNAME' => reset, 'DB_PASSWORD' => reset_password)
  end

  def expect_correction_rollback_refusal!
    arguments = ['migrate:rollback', '--path='+MIGRATION_PATH, '--force', '--no-interaction']
    @command_catalog << ['artisan', *arguments]
    stdout, stderr, status = Open3.capture3(@runner.process_environment(application_environment), @php_binary, File.join(ROOT, 'artisan'), *arguments, unsetenv_others: true)
    raise CommandFailed, 'Retained correction evidence unexpectedly allowed rollback.' if status.success?
    combined = (stdout + stderr).gsub(/\s+/, '').downcase
    expected = %w[refusingtodiscardsettlementcorrectiontableswhilecorrelatedauditevidenceremains refusingtodiscardpopulatedsettlementcorrectionevidence refusingtodiscardsettlementcalculationsnapshots]
    raise CommandFailed, 'Correction rollback failed for an unexpected reason.' unless expected.any? { |fragment| combined.include?(fragment) }
    @result_catalog << [['artisan', *arguments], 'EXPECTED_REFUSAL']
    'PASS'
  end

  def write_correction_evidence!(bindings:, engine_binding:, migration_duration_ms:, scenarios:, sqlite_gate:)
    assert_evidence_directory!
    path = File.join(EVIDENCE_DIRECTORY, "#{Time.now.utc.strftime('%Y%m%dT%H%M%SZ')}-#{@engine}-cash-settlement-correction-#{SecureRandom.hex(6)}.json")
    evidence = {
      'schema_version' => 1, 'kind' => EVIDENCE_KIND, 'status' => 'PASS',
      'recorded_at_utc' => Time.now.utc.iso8601,
      'claim' => 'LOCAL_DISPOSABLE_APPEND_ONLY_CASH_SETTLEMENT_CORRECTION_ONLY',
      'hosted_readiness_claim' => false, 'deployment_claim' => false,
      'owner_acceptance_claim' => false, 'g0_claim' => false, 'g3_claim' => false,
      'source_bindings' => bindings.merge(
        'command_catalog_sha256' => Digest::SHA256.hexdigest(JSON.generate(@command_catalog)),
        'result_catalog_sha256' => Digest::SHA256.hexdigest(JSON.generate(@result_catalog))
      ),
      'command_catalog' => @command_catalog, 'protocol_result_catalog' => @protocol_catalog,
      'boundary' => { 'application_mode' => 'SIMULATION', 'synthetic_only' => true,
        'live_integrations_enabled' => false, 'disposable_local_engine' => true,
        'append_only_full_cash_correction_only' => true, 'external_database_configuration_accepted' => false },
      'engine' => engine_binding, 'sqlite_gate' => sqlite_gate,
      'migration' => { 'fresh_apply' => 'PASS', 'duration_ms_observed' => migration_duration_ms },
      'scenarios' => scenarios,
      'cleanup' => { 'database_removed' => true, 'temporary_server_removed' => true,
        'temporary_user_state_removed' => true, 'temporary_worker_removed' => true,
        'strict_cleanup_verified' => true },
      'open_boundaries' => [
        'G0 and G3 remain OPEN; this is not owner acceptance, UAT, hosted migration, deployment, or production readiness.',
        'No partial refund/payment, split tender, card, treasury, journal, claim, BPJS, SATUSEHAT, ERP, or real patient data is exercised.',
      ],
    }
    sanitize_evidence!(evidence)
    File.write(path, JSON.pretty_generate(evidence)+"\n", mode: 'wx', perm: 0o600)
    File.chmod(0o600, path)
    path
  end

  def run!
    assert_contract!
    bindings = current_correction_bindings
    sqlite_gate = run_sqlite_gate!
    engine_binding = prepare_engine!
    @run_token = database_run_token
    create_worker_file!
    started = @clock.call
    recorded_artisan!('migrate:fresh', '--force', '--no-interaction')
    migration_duration_ms = elapsed_ms(started)
    recorded_artisan!('db:seed', '--class=Database\\Seeders\\RbacSeeder', '--force', '--no-interaction')
    recorded_artisan!('migrate:rollback', '--path='+MIGRATION_PATH, '--force', '--no-interaction')
    recorded_artisan!('migrate', '--path='+MIGRATION_PATH, '--force', '--no-interaction')
    failure = run_worker_command!(action: 'failure_safety', scenario: 'failed-install-preserves-lifetime-uniqueness', worker: 'MIGRATION_FAILURE', connection_environment: application_environment)
    schema = run_worker_command!(action: 'schema_guards', scenario: 'database-check-and-append-only-refusal', worker: 'SCHEMA', connection_environment: application_environment)
    feature = bind_feature_evidence!(FEATURE_SCENARIO_TESTS)
    recreate_application_schema_outside_guard!
    recorded_artisan!('migrate:fresh', '--force', '--no-interaction')
    recorded_artisan!('db:seed', '--class=Database\\Seeders\\RbacSeeder', '--force', '--no-interaction')
    prepared = run_worker_command!(action: 'prepare', scenario: 'fresh-migration', worker: 'PREPARE', connection_environment: application_environment)
    fixture = prepared.fetch('fixture')
    provision_runtime_identities!
    race = run_request_race!(fixture)
    privilege = run_worker_command!(action: 'privilege_guards', scenario: 'exact-runtime-grants', worker: 'RUNTIME', fixture: fixture)
    terminal = run_worker_command!(action: 'complete_replace', scenario: 'request-approve-refund-complete-replacement', worker: 'TERMINAL', fixture: fixture, connection_environment: application_environment)
    durable = run_worker_command!(action: 'verify_net', scenario: 'third-connection-net-cash-readback', worker: 'THIRD_CONNECTION', fixture: fixture, connection_environment: application_environment)
    recovery_audit = run_worker_command!(action: 'recovery_audit', scenario: 'recovery-relevant-column-presence', worker: 'RECOVERY_AUDIT', fixture: fixture, connection_environment: application_environment)
    rollback = expect_correction_rollback_refusal!
    reset = run_worker_command!(action: 'reset', scenario: 'bounded-reset', worker: 'INSTALLER_OWNER_RESET', fixture: fixture, connection_environment: application_environment)
    scenarios = {
      'fresh-migration' => { 'status' => 'PASS', 'proof_kind' => 'FRESH_EXACT_ENGINE_MIGRATION' },
      'empty-down-reapply' => { 'status' => 'PASS', 'proof_kind' => 'EMPTY_MIGRATION_DOWN_REAPPLY' },
      'migration-failure-guard-reinstall' => { 'status' => 'PASS', 'proof_kind' => 'FINALLY_GUARD_REINSTALL_AFTER_FORCED_FAILURE', 'worker' => failure },
      'failed-install-preserves-lifetime-uniqueness' => { 'status' => 'PASS', 'proof_kind' => 'FORCED_PRE_READINESS_FAILURE_WITH_BOTH_UNIQUES', 'worker' => failure },
      'exact-runtime-grants' => { 'status' => 'PASS', 'proof_kind' => 'READ_BACK_LEAST_PRIVILEGE_RUNTIME', 'worker' => privilege },
      'exact-role-denials' => feature.fetch('exact-role-denials'),
      'database-check-and-append-only-refusal' => { 'status' => 'PASS', 'proof_kind' => 'CONSTRAINT_TRIGGER_CATALOG_AND_REFUSAL', 'worker' => schema, 'feature' => feature.fetch('database-check-and-append-only-refusal') },
      'request-reject' => feature.fetch('request-reject'),
      'request-approve-refund-complete-replacement' => feature.fetch('request-approve-refund-complete-replacement').merge('exact_worker' => terminal),
      'idempotent-replay' => feature.fetch('idempotent-replay'),
      'changed-payload-conflict' => feature.fetch('changed-payload-conflict'),
      'real-wait-single-durable-case' => race,
      'double-review-refusal' => feature.fetch('double-review-refusal').merge('exact_worker' => terminal),
      'double-refund-refusal' => feature.fetch('double-refund-refusal').merge('exact_worker' => terminal),
      'audit-failure-atomic-rollback' => feature.fetch('audit-failure-atomic-rollback'),
      'predecessor-refund-net-cash-snapshot' => feature.fetch('predecessor-refund-net-cash-snapshot'),
      'refund-receipt-integrity' => feature.fetch('refund-receipt-integrity'),
      'recovery-relevant-column-presence' => { 'status' => 'PASS', 'proof_kind' => 'EXACT_SCHEMA_COLUMN_RECOVERY_AUDIT_AND_FILTERED_TEST', 'worker' => schema, 'recovery_audit' => recovery_audit, 'feature' => feature.fetch('recovery-relevant-column-presence') },
      'retained-evidence-rollback-refusal' => { 'status' => rollback, 'proof_kind' => 'POPULATED_MIGRATION_DOWN_REFUSED' },
      'third-connection-net-cash-readback' => { 'status' => 'PASS', 'proof_kind' => 'INDEPENDENT_THIRD_CONNECTION_NET_CASH_READBACK', 'worker' => durable },
      'bounded-reset' => { 'status' => 'PASS', 'proof_kind' => 'SYNTHETIC_RESET_WITH_PRESERVED_AUDIT', 'worker' => reset },
      'strict-cleanup' => { 'status' => 'PASS', 'proof_kind' => 'ENGINE_OWNED_STRICT_CLEANUP_PENDING_BINDING_RECHECK' },
    }
    raise CommandFailed, 'Correction scenario evidence is incomplete.' unless scenarios.keys == SCENARIOS && scenarios.values.all? { |result| result['status'] == 'PASS' }
    assert_unchanged_binding!('Correction execution bindings', bindings, current_correction_bindings)
    cleanup!(strict: true)
    evidence_path = write_correction_evidence!(bindings: bindings, engine_binding: engine_binding,
      migration_duration_ms: migration_duration_ms, scenarios: scenarios, sqlite_gate: sqlite_gate)
    { 'status' => 'PASS', 'claim' => 'LOCAL_DISPOSABLE_APPEND_ONLY_CASH_SETTLEMENT_CORRECTION_ONLY',
      'engine' => @engine, 'scenario_count' => scenarios.length,
      'application_source_sha256' => bindings.fetch('application_source_sha256'),
      'sqlite_gate_status' => sqlite_gate.fetch('status'), 'evidence_path' => evidence_path,
      'evidence_sha256' => Digest::SHA256.file(evidence_path).hexdigest }
  ensure
    terminate_workers!
    remove_worker_file!
    cleanup!
  end
end

if $PROGRAM_NAME == __FILE__
  begin
    unless ARGV.length == 1 && LocalAppendOnlyCashSettlementCorrectionPortabilityRehearsal::ENGINES.include?(ARGV.first)
      warn "usage: #{File.basename(__FILE__)} <postgresql17|mysql8411>"
      exit 64
    end
    puts JSON.generate(LocalAppendOnlyCashSettlementCorrectionPortabilityRehearsal.new(engine: ARGV.first).run!)
  rescue LocalAppendOnlyCashSettlementCorrectionPortabilityRehearsal::CommandFailed => e
    warn "cash settlement correction portability rehearsal failed: #{e.message}"
    exit 1
  end
end
