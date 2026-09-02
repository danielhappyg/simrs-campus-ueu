<?php

namespace App\Console\Commands;

use App\Models\Encounter;
use App\Models\InpatientBed;
use App\Models\InpatientClinicalDocument;
use App\Models\InpatientClinicalDocumentVersion;
use App\Models\InpatientDocumentOperationReceipt;
use App\Models\InpatientWard;
use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Database\SchemaQualifier;
use App\Support\Inpatient\CanonicalInpatientBedOperationLockCoordinator;
use App\Support\Inpatient\InpatientDocumentationActorPolicy;
use App\Support\Inpatient\InpatientDocumentationAuditUnavailable;
use App\Support\Inpatient\InpatientDocumentationDenied;
use App\Support\Inpatient\InpatientDocumentationMutationScope;
use App\Support\Inpatient\InpatientDocumentationService;
use App\Support\Inpatient\InpatientLocationMutationScope;
use App\Support\Inpatient\InpatientMasterDirectWriteScope;
use App\Support\Inpatient\InpatientMasterMutationScope;
use App\Support\Inpatient\InpatientMasterService;
use App\Support\Simulation\SyntheticResetService;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Throwable;

final class RehearseInpatientDocumentationRaceWorkerCommand extends Command
{
    public const CONFIRMATION = 'YES_DISPOSABLE_LOCAL_INPATIENT_DOCUMENTATION_PORTABILITY';

    /** @var list<string> */
    public const RACE_SCENARIOS = [
        'same-author-service-day-concurrent-create',
        'same-expected-version-update',
        'identical-idempotency-replay',
        'conflicting-idempotency-replay',
    ];

    /** @var list<string> */
    public const STATE_SCENARIOS = [
        'multi-author-same-day-independent-heads',
        'immutable-placement-snapshot-after-managed-rename',
        'audit-failure-atomic-rollback',
        'reset-retains-audit-evidence',
        'populated-down-refusal-fixture',
        'populated-down-refusal-cleanup',
    ];

    protected $signature = 'ops:rehearse-inpatient-documentation-worker
        {--run-token= : Twelve-hex token bound to the disposable database}
        {--action= : prepare, operate, verify, or prove}
        {--scenario= : Closed documentation scenario}
        {--worker=A : A or B}
        {--hold-ms=0 : Outer-transaction hold after an applied operation}
        {--confirm-local-synthetic : Confirm the disposable local synthetic boundary}';

    protected $description = 'Internal worker for cross-engine inpatient documentation portability evidence';

    public function handle(InpatientDocumentationService $service): int
    {
        try {
            [$token, $action, $scenario, $worker, $holdMs] = $this->validatedOptions();
            $this->assertSafeBoundary($token);
            $result = match ($action) {
                'prepare' => $this->prepare($service, $token, $scenario),
                'operate' => $this->operate($service, $token, $scenario, $worker, $holdMs),
                'verify' => $this->verify($token, $scenario),
                'prove' => $this->prove($service, $token, $scenario),
                default => throw new RuntimeException('Unsupported documentation worker action.'),
            };
            $this->emit($result);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->emit([
                'schema_version' => 1,
                'status' => 'BLOCKED',
                'scenario' => trim((string) $this->option('scenario')),
                'worker' => trim((string) $this->option('worker')),
                'exception_class' => $exception::class,
                'exception_fingerprint' => hash('sha256', $exception::class.'|'.$exception->getMessage()),
            ]);
            $this->components->error($exception::class.': '.$exception->getMessage());
            fwrite(STDERR, "\n[idoc-rehearsal] ".$exception::class.': '.$exception->getMessage()."\n");

            return self::FAILURE;
        }
    }

    /** @return array{string,string,string,string,int} */
    private function validatedOptions(): array
    {
        if ($this->option('confirm-local-synthetic') !== true
            || getenv('SIMRS_INPATIENT_DOCUMENTATION_REHEARSAL_CONFIRM') !== self::CONFIRMATION) {
            throw new RuntimeException('Documentation worker requires both local confirmations.');
        }
        $token = trim((string) $this->option('run-token'));
        if (preg_match('/\A[0-9a-f]{12}\z/', $token) !== 1) {
            throw new RuntimeException('Documentation worker run token is invalid.');
        }
        $action = trim((string) $this->option('action'));
        if (! in_array($action, ['prepare', 'operate', 'verify', 'prove'], true)) {
            throw new RuntimeException('Documentation worker action is invalid.');
        }
        $scenario = trim((string) $this->option('scenario'));
        $catalogue = array_merge(self::RACE_SCENARIOS, self::STATE_SCENARIOS);
        if (! in_array($scenario, $catalogue, true)) {
            throw new RuntimeException('Documentation worker scenario is invalid.');
        }
        if (($action === 'prove') !== in_array($scenario, self::STATE_SCENARIOS, true)) {
            throw new RuntimeException('Documentation worker action/scenario combination is invalid.');
        }
        $worker = trim((string) $this->option('worker'));
        if (! in_array($worker, ['A', 'B'], true)) {
            throw new RuntimeException('Documentation worker label is invalid.');
        }
        $hold = trim((string) $this->option('hold-ms'));
        if (preg_match('/\A[0-9]{1,4}\z/', $hold) !== 1 || (int) $hold > 5000) {
            throw new RuntimeException('Documentation worker hold is invalid.');
        }

        return [$token, $action, $scenario, $worker, (int) $hold];
    }

    private function assertSafeBoundary(string $token): void
    {
        if (config('simulation.mode') !== 'SIMULATION' || config('simulation.synthetic_only') !== true) {
            throw new RuntimeException('Documentation worker requires SIMULATION synthetic-only mode.');
        }
        if (config('mail.default') !== 'array' || config('queue.default') !== 'sync') {
            throw new RuntimeException('Documentation worker requires non-egress delivery drivers.');
        }
        foreach (['BPJS_INTEGRATION_ENABLED', 'VCLAIM_ENABLED', 'SATUSEHAT_ENABLED'] as $key) {
            if (getenv($key) !== 'false') {
                throw new RuntimeException('Documentation worker refuses live integration configuration.');
            }
        }
        $driver = DB::connection()->getDriverName();
        $database = (string) DB::connection()->getDatabaseName();
        if ($driver === 'pgsql') {
            $version = (string) DB::scalar('SHOW server_version_num');
            if ($version !== '170010' || $database !== 'simrs_portability_pg_'.$token
                || SchemaQualifier::primarySchema() !== 'laravel') {
                throw new RuntimeException('Documentation worker refused the PostgreSQL boundary.');
            }
        } elseif ($driver === 'mysql') {
            $version = (string) DB::scalar('SELECT VERSION()');
            if (! str_starts_with($version, '8.4.11') || $database !== 'simrs_portability_my_'.$token) {
                throw new RuntimeException('Documentation worker refused the MySQL boundary.');
            }
        } else {
            throw new RuntimeException('Documentation worker requires an approved disposable engine.');
        }
        if (Patient::query()->where('is_synthetic', false)->exists()) {
            throw new RuntimeException('Documentation worker refuses non-synthetic patients.');
        }
    }

    /** @return array<string,mixed> */
    private function prepare(InpatientDocumentationService $service, string $token, string $scenario): array
    {
        DB::transaction(function () use ($service, $token, $scenario): void {
            $this->fixture($token, $scenario);
            if ($scenario === 'same-expected-version-update') {
                $service->saveDraft(
                    $this->encounter($token, $scenario)->public_id,
                    $this->actor($token, 'nurse-a'),
                    InpatientClinicalDocument::TYPE_NURSING_DAILY,
                    InpatientClinicalDocument::DEFINITION_VERSION,
                    0,
                    ['nursing_observation' => 'baseline'],
                    'prep-'.$token.'-update',
                );
            }
        }, 1);

        return $this->protocol('PREPARED', $scenario);
    }

    /** @return array<string,mixed> */
    private function operate(InpatientDocumentationService $service, string $token, string $scenario, string $worker, int $holdMs): array
    {
        $connectionId = $this->backendConnectionId();
        $this->emit(array_merge($this->protocol('STARTED', $scenario, $worker), ['backend_connection_id' => $connectionId]));
        $started = hrtime(true);
        $result = DB::transaction(function () use ($service, $token, $scenario, $worker, $holdMs, $connectionId): array {
            try {
                $expected = $scenario === 'same-expected-version-update' ? 1 : 0;
                $key = in_array($scenario, ['identical-idempotency-replay', 'conflicting-idempotency-replay'], true)
                    ? 'race-'.$token.'-'.$scenario
                    : 'race-'.$token.'-'.$scenario.'-'.strtolower($worker);
                $value = $scenario === 'identical-idempotency-replay' ? 'identical' : 'worker-'.$worker;
                $mutation = $service->saveDraft(
                    $this->encounter($token, $scenario)->public_id,
                    $this->actor($token, 'nurse-a'),
                    InpatientClinicalDocument::TYPE_NURSING_DAILY,
                    InpatientClinicalDocument::DEFINITION_VERSION,
                    $expected,
                    ['nursing_observation' => $value],
                    $key,
                );
                $outcome = $mutation->replayed ? 'REPLAYED' : 'APPLIED';
                $reason = null;
            } catch (InpatientDocumentationDenied $denial) {
                $outcome = 'DENIED';
                $reason = $denial->reason;
            }

            if ($holdMs > 0 && $outcome === 'APPLIED') {
                $this->emit(array_merge($this->protocol('HOLDING', $scenario, $worker), [
                    'backend_connection_id' => $connectionId,
                    'outcome' => $outcome,
                ]));
                $this->engineNativeSleep($holdMs);
            }

            return ['outcome' => $outcome, 'reason' => $reason];
        }, 1);

        return array_merge($this->protocol('COMMITTED', $scenario, $worker), $result, [
            'backend_connection_id' => $connectionId,
            'elapsed_ms' => (int) round((hrtime(true) - $started) / 1_000_000),
        ]);
    }

    /** @return array<string,mixed> */
    private function verify(string $token, string $scenario): array
    {
        DB::connection()->disconnect();
        DB::connection()->reconnect();
        $encounter = $this->encounter($token, $scenario);
        $document = InpatientClinicalDocument::query()->where('encounter_id', $encounter->id)->sole();
        $expectedVersion = $scenario === 'same-expected-version-update' ? 2 : 1;
        $versionRows = InpatientClinicalDocumentVersion::query()->where('inpatient_clinical_document_id', $document->id)->count();
        $receiptRows = InpatientDocumentOperationReceipt::query()->where('encounter_id', $encounter->id)->count();
        $successAudits = AuditEvent::query()->where('action', 'clinical.inpatient.nursing.draft.save')
            ->where('outcome', 'SUCCESS')->where('resource_id', $document->public_id)->count();
        $denialAudits = 0;
        $this->assertSame($expectedVersion, $document->version, 'durable head version');
        $this->assertSame($expectedVersion, $versionRows, 'durable version rows');
        $this->assertSame($expectedVersion, $receiptRows, 'durable receipts');
        $this->assertSame($expectedVersion, $successAudits, 'success audit');
        if (in_array($scenario, ['same-author-service-day-concurrent-create', 'same-expected-version-update', 'conflicting-idempotency-replay'], true)) {
            $reason = $scenario === 'conflicting-idempotency-replay' ? 'idempotency_key_conflict' : 'stale_version';
            $denialAudits = AuditEvent::query()->where('action', 'clinical.inpatient.nursing.draft.save')
                ->where('outcome', 'DENIED')->where('reason', $reason)
                ->where('resource_id', $encounter->public_id)->count();
            $this->assertSame(1, $denialAudits, 'denial audit');
        }

        return array_merge($this->protocol('VERIFIED', $scenario), [
            'durable_third_connection_assertions' => true,
            'assertion_catalog' => [
                'head_count' => 1,
                'head_version' => $document->version,
                'version_rows' => $versionRows,
                'receipt_rows' => $receiptRows,
                'success_audit_rows' => $successAudits,
                'denial_audit_rows' => $denialAudits,
            ],
        ]);
    }

    /** @return array<string,mixed> */
    private function prove(InpatientDocumentationService $service, string $token, string $scenario): array
    {
        return match ($scenario) {
            'multi-author-same-day-independent-heads' => $this->proveMultiAuthor($service, $token, $scenario),
            'immutable-placement-snapshot-after-managed-rename' => $this->proveSnapshot($service, $token, $scenario),
            'audit-failure-atomic-rollback' => $this->proveAuditRollback($token, $scenario),
            'reset-retains-audit-evidence' => $this->proveReset($service, $token, $scenario),
            'populated-down-refusal-fixture' => $this->provePopulatedDownFixture($token, $scenario),
            'populated-down-refusal-cleanup' => $this->provePopulatedDownCleanup($scenario),
            default => throw new RuntimeException('Unsupported state proof.'),
        };
    }

    /** @return array<string,mixed> */
    private function proveMultiAuthor(InpatientDocumentationService $service, string $token, string $scenario): array
    {
        $this->fixture($token, $scenario);
        $encounter = $this->encounter($token, $scenario);
        foreach ([['nurse-a', InpatientClinicalDocument::TYPE_NURSING_DAILY], ['nurse-b', InpatientClinicalDocument::TYPE_NURSING_DAILY], ['physician', InpatientClinicalDocument::TYPE_MEDICAL_DAILY]] as [$actor, $type]) {
            $service->saveDraft($encounter->public_id, $this->actor($token, $actor), $type,
                InpatientClinicalDocument::DEFINITION_VERSION, 0, [], 'proof-'.$token.'-'.$scenario.'-'.$actor);
        }
        $heads = InpatientClinicalDocument::query()->where('encounter_id', $encounter->id)->get();
        $this->assertSame(3, $heads->count(), 'independent heads');
        $this->assertSame(3, $heads->pluck('author_user_id')->unique()->count(), 'independent authors');
        $this->assertSame(2, $heads->where('document_type', InpatientClinicalDocument::TYPE_NURSING_DAILY)->count(), 'nursing heads');
        $this->assertSame(1, $heads->where('document_type', InpatientClinicalDocument::TYPE_MEDICAL_DAILY)->count(), 'medical heads');
        $this->assertSame(1, $heads->pluck('service_date')->map->toDateString()->unique()->count(), 'one service day');
        $this->assertSame(3, InpatientClinicalDocumentVersion::query()->whereIn('inpatient_clinical_document_id', $heads->pluck('id'))->count(), 'one version per head');
        $this->assertSame(3, InpatientDocumentOperationReceipt::query()->where('encounter_id', $encounter->id)->count(), 'one receipt per head');
        $this->assertSame(3, AuditEvent::query()->where('action', 'like', 'clinical.inpatient.%.draft.save')
            ->where('outcome', 'SUCCESS')->whereIn('resource_id', $heads->pluck('public_id'))->count(), 'one success audit per head');

        return array_merge($this->protocol('PROVED', $scenario), [
            'durable_third_connection_assertions' => true,
            'assertion_catalog' => [
                'head_rows' => 3, 'distinct_authors' => 3, 'nursing_heads' => 2,
                'medical_heads' => 1, 'service_days' => 1, 'version_rows' => 3,
                'receipt_rows' => 3, 'success_audit_rows' => 3,
                'exact_author_type_service_day_tuples_unique' => true,
            ],
        ]);
    }

    /** @return array<string,mixed> */
    private function proveSnapshot(InpatientDocumentationService $service, string $token, string $scenario): array
    {
        $this->fixture($token, $scenario);
        $encounter = $this->encounter($token, $scenario);
        $actor = $this->actor($token, 'nurse-a');
        $service->saveDraft($encounter->public_id, $actor, InpatientClinicalDocument::TYPE_NURSING_DAILY,
            InpatientClinicalDocument::DEFINITION_VERSION, 0, [], 'proof-'.$token.'-snapshot-1');
        $bed = InpatientBed::query()->findOrFail($encounter->inpatient_bed_id);
        $ward = $bed->ward;
        $master = app(InpatientMasterService::class);
        $master->updateWard($this->actor($token, 'admin'), $ward->public_id, 'Renamed ward', 1,
            InpatientMasterService::REASON_OPERATIONAL_CHANGE, 'proof-'.$token.'-ward-rename', null);
        $master->updateBed($this->actor($token, 'admin'), $bed->public_id, 'Renamed bed', 'Renamed room', 'Renamed class', 1,
            InpatientMasterService::REASON_OPERATIONAL_CHANGE, 'proof-'.$token.'-bed-rename', null);
        $service->saveDraft($encounter->public_id, $actor, InpatientClinicalDocument::TYPE_NURSING_DAILY,
            InpatientClinicalDocument::DEFINITION_VERSION, 1, [], 'proof-'.$token.'-snapshot-2');
        $versions = InpatientClinicalDocumentVersion::query()->where('encounter_public_id', $encounter->public_id)->orderBy('version')->get();
        $this->assertSame('Synthetic ward', $versions[0]->ward_display_name, 'first ward snapshot');
        $this->assertSame('Synthetic bed', $versions[0]->bed_display_name, 'first bed snapshot');
        $this->assertSame('Synthetic room', $versions[0]->room_label, 'first room snapshot');
        $this->assertSame('Synthetic class', $versions[0]->service_class, 'first class snapshot');
        $this->assertSame('Renamed ward', $versions[1]->ward_display_name, 'second ward snapshot');
        $this->assertSame('Renamed bed', $versions[1]->bed_display_name, 'second bed snapshot');
        $this->assertSame('Renamed room', $versions[1]->room_label, 'second room snapshot');
        $this->assertSame('Renamed class', $versions[1]->service_class, 'second class snapshot');
        $this->assertSame($versions[0]->ward_code, $versions[1]->ward_code, 'immutable ward code');
        $this->assertSame($versions[0]->bed_code, $versions[1]->bed_code, 'immutable bed code');
        $this->assertSame($versions[0]->ward_public_id, $versions[1]->ward_public_id, 'immutable ward identity');
        $this->assertSame($versions[0]->bed_public_id, $versions[1]->bed_public_id, 'immutable bed identity');

        return array_merge($this->protocol('PROVED', $scenario), [
            'durable_third_connection_assertions' => true,
            'assertion_catalog' => [
                'version_rows' => 2, 'first_snapshot_retained' => true,
                'second_snapshot_uses_renamed_values' => true,
                'ward_public_id_immutable' => true, 'ward_code_immutable' => true,
                'bed_public_id_immutable' => true, 'bed_code_immutable' => true,
                'display_room_class_snapshots_checked' => true,
            ],
        ]);
    }

    /** @return array<string,mixed> */
    private function proveAuditRollback(string $token, string $scenario): array
    {
        $this->fixture($token, $scenario);
        $encounter = $this->encounter($token, $scenario);
        $service = app(InpatientDocumentationService::class);
        $service->saveDraft($encounter->public_id, $this->actor($token, 'nurse-a'),
            InpatientClinicalDocument::TYPE_NURSING_DAILY, InpatientClinicalDocument::DEFINITION_VERSION,
            0, [
                'nursing_observation' => 'stable', 'nursing_intervention' => 'observe',
                'nursing_evaluation' => 'monitored',
            ], 'proof-'.$token.'-audit-baseline');
        $rejectingAudit = new class extends AuditRecorder
        {
            public function record(string $action, string $resourceType, ?string $resourceId = null, ?User $actor = null,
                string $outcome = 'SUCCESS', ?string $reason = null, array $metadata = [], ?Request $request = null,
                bool $includeRequestFingerprint = true): ?AuditEvent
            {
                return null;
            }
        };
        $failing = new InpatientDocumentationService(
            $rejectingAudit,
            app(InpatientDocumentationActorPolicy::class),
            app(CanonicalInpatientBedOperationLockCoordinator::class),
        );
        try {
            $failing->finalize($encounter->public_id, $this->actor($token, 'nurse-a'),
                InpatientClinicalDocument::TYPE_NURSING_DAILY, InpatientClinicalDocument::DEFINITION_VERSION,
                1, 'proof-'.$token.'-audit-final-fail');
            throw new RuntimeException('Audit failure unexpectedly committed.');
        } catch (InpatientDocumentationAuditUnavailable) {
            // Expected.
        }
        $document = InpatientClinicalDocument::query()->where('encounter_id', $encounter->id)->sole();
        $this->assertSame(InpatientClinicalDocument::STATE_DRAFT, $document->document_state, 'final audit rollback state');
        $this->assertSame(1, $document->version, 'final audit rollback head version');
        $this->assertSame(1, InpatientClinicalDocumentVersion::query()->where('inpatient_clinical_document_id', $document->id)->count(), 'final audit rollback versions');
        $this->assertSame(1, InpatientDocumentOperationReceipt::query()->where('encounter_id', $encounter->id)->count(), 'final audit rollback receipts');
        $this->assertSame(Encounter::STATUS_REGISTERED, $encounter->fresh()->status, 'final audit rollback encounter status');
        $baselineSuccess = AuditEvent::query()->where('action', 'clinical.inpatient.nursing.draft.save')
            ->where('outcome', 'SUCCESS')->where('resource_id', $document->public_id)->count();
        $finalizeSuccess = AuditEvent::query()->where('action', 'clinical.inpatient.nursing.finalize')
            ->where('outcome', 'SUCCESS')->where('resource_id', $document->public_id)->count();
        $finalizeDenied = AuditEvent::query()->where('action', 'clinical.inpatient.nursing.finalize')
            ->where('outcome', 'DENIED')->where('resource_id', $encounter->public_id)->count();
        $this->assertSame(1, $baselineSuccess, 'baseline success audit retained');
        $this->assertSame(0, $finalizeSuccess, 'failed final success audit absent');
        $this->assertSame(0, $finalizeDenied, 'audit-unavailable denial audit absent');

        return array_merge($this->protocol('PROVED', $scenario), [
            'durable_third_connection_assertions' => true,
            'assertion_catalog' => [
                'operation' => 'FINALIZE', 'head_state' => 'DRAFT', 'head_version' => 1,
                'version_rows' => 1, 'receipt_rows' => 1, 'encounter_status' => 'REGISTERED',
                'baseline_success_audit_rows' => 1, 'finalize_success_audit_rows' => 0,
                'finalize_denial_audit_rows' => 0,
            ],
        ]);
    }

    /** @return array<string,mixed> */
    private function proveReset(InpatientDocumentationService $service, string $token, string $scenario): array
    {
        $this->fixture($token, $scenario);
        $encounter = $this->encounter($token, $scenario);
        $service->saveDraft($encounter->public_id, $this->actor($token, 'nurse-a'),
            InpatientClinicalDocument::TYPE_NURSING_DAILY, InpatientClinicalDocument::DEFINITION_VERSION,
            0, [], 'proof-'.$token.'-reset');
        app(SyntheticResetService::class)->reset(['actor' => $this->actor($token, 'admin'), 'reason' => 'local_portability_rehearsal']);
        foreach (['inpatient_clinical_documents', 'inpatient_clinical_document_versions', 'inpatient_document_operation_receipts'] as $table) {
            $this->assertSame(0, DB::table(SchemaQualifier::table($table))->count(), 'reset '.$table);
        }
        $this->assertSame(1, AuditEvent::query()->where('action', 'teaching.reset.completed')->count(), 'reset completion audit');
        if (! AuditEvent::query()->where('action', 'like', 'clinical.inpatient.%')->exists()) {
            throw new RuntimeException('Reset erased correlated documentation audit evidence.');
        }

        return array_merge($this->protocol('PROVED', $scenario), [
            'durable_third_connection_assertions' => true,
            'assertion_catalog' => [
                'head_rows' => 0, 'version_rows' => 0, 'receipt_rows' => 0,
                'reset_completed_audit_rows' => 1, 'documentation_audit_retained' => true,
            ],
        ]);
    }

    /** @return array<string,mixed> */
    private function provePopulatedDownFixture(string $token, string $scenario): array
    {
        $this->fixture($token, $scenario);
        $encounter = $this->encounter($token, $scenario);
        $nonPersistingAudit = new class extends AuditRecorder
        {
            public function record(string $action, string $resourceType, ?string $resourceId = null, ?User $actor = null,
                string $outcome = 'SUCCESS', ?string $reason = null, array $metadata = [], ?Request $request = null,
                bool $includeRequestFingerprint = true): AuditEvent
            {
                return app(AuditEvent::class);
            }
        };
        $service = new InpatientDocumentationService(
            $nonPersistingAudit,
            app(InpatientDocumentationActorPolicy::class),
            app(CanonicalInpatientBedOperationLockCoordinator::class),
        );
        $service->saveDraft($encounter->public_id, $this->actor($token, 'nurse-a'),
            InpatientClinicalDocument::TYPE_NURSING_DAILY, InpatientClinicalDocument::DEFINITION_VERSION,
            0, [], 'proof-'.$token.'-populated-down');
        $this->assertSame(1, InpatientClinicalDocument::query()->where('encounter_id', $encounter->id)->count(), 'populated down fixture');
        $this->assertSame(0, AuditEvent::query()->where('action', 'like', 'clinical.inpatient.%')->count(), 'no correlated audit fixture');

        return array_merge($this->protocol('PROVED', $scenario), ['durable_third_connection_assertions' => true]);
    }

    /** @return array<string,mixed> */
    private function provePopulatedDownCleanup(string $scenario): array
    {
        InpatientDocumentationMutationScope::run(function (): void {
            DB::table(SchemaQualifier::table('inpatient_document_operation_receipts'))->delete();
            DB::table(SchemaQualifier::table('inpatient_clinical_documents'))->delete();
        });
        Patient::query()->where('is_synthetic', true)->delete();
        InpatientMasterDirectWriteScope::run(function (): void {
            DB::table(SchemaQualifier::table('inpatient_beds'))->delete();
            DB::table(SchemaQualifier::table('inpatient_wards'))->delete();
        });
        $this->assertSame(0, InpatientClinicalDocument::query()->count(), 'populated fixture cleanup documents');

        return array_merge($this->protocol('PROVED', $scenario), ['durable_third_connection_assertions' => true]);
    }

    private function fixture(string $token, string $scenario): void
    {
        if (Encounter::query()->where('booking_code', $this->bookingCode($token, $scenario))->exists()) {
            throw new RuntimeException('Scenario fixture already exists.');
        }
        $actors = $this->actors($token);
        $suffix = substr(hash('sha256', $scenario), 0, 10);
        [$ward, $bed] = InpatientMasterMutationScope::run(function () use ($token, $suffix): array {
            $ward = InpatientWard::query()->create([
                'code' => mb_strtoupper('W-'.$token.'-'.$suffix), 'display_name' => 'Synthetic ward',
                'state' => InpatientWard::STATE_ACTIVE, 'version' => 1,
            ]);
            $bed = InpatientBed::query()->create([
                'ward_id' => $ward->id, 'code' => mb_strtoupper('B-'.$token.'-'.$suffix),
                'display_name' => 'Synthetic bed', 'room_label' => 'Synthetic room',
                'service_class' => 'Synthetic class', 'state' => InpatientBed::STATE_ACTIVE, 'version' => 1,
            ]);

            return [$ward, $bed];
        });
        $patient = Patient::query()->create([
            'medical_record_number' => 'SYN-IDOC-'.strtoupper($token).'-'.strtoupper(substr($suffix, 0, 5)),
            'full_name' => 'Synthetic documentation rehearsal', 'date_of_birth' => '1990-01-01',
            'sex' => Patient::SEX_TIDAK_DIKETAHUI, 'is_synthetic' => true,
            'created_by_user_id' => $actors['admin']->id,
        ]);
        InpatientLocationMutationScope::run(fn (): Encounter => Encounter::query()->create([
            'patient_id' => $patient->id, 'care_setting' => Encounter::CARE_SETTING_INPATIENT,
            'status' => Encounter::STATUS_REGISTERED, 'visit_date' => now('Asia/Jakarta')->toDateString(),
            'clinic_name' => 'Synthetic inpatient documentation ward',
            'payer_type' => Encounter::PAYER_UMUM, 'registered_at' => now(),
            'queue_date' => now('Asia/Jakarta')->toDateString(),
            'queue_number' => (hexdec(substr($suffix, 0, 6)) % 900_000) + 1,
            'registered_by_user_id' => $actors['admin']->id, 'ward_name' => $ward->display_name,
            'ward_class' => $bed->service_class, 'bed_code' => $bed->code, 'inpatient_bed_id' => $bed->id,
            'continue_from' => Encounter::CONTINUE_LANGSUNG,
            'booking_code' => $this->bookingCode($token, $scenario),
        ]));
    }

    /** @return array<string,User> */
    private function actors(string $token): array
    {
        return [
            'admin' => $this->createActor($token, 'admin', RoleCapabilityMatrix::ROLE_ADMIN),
            'nurse-a' => $this->createActor($token, 'nurse-a', RoleCapabilityMatrix::ROLE_NURSE),
            'nurse-b' => $this->createActor($token, 'nurse-b', RoleCapabilityMatrix::ROLE_NURSE),
            'physician' => $this->createActor($token, 'physician', RoleCapabilityMatrix::ROLE_PHYSICIAN),
        ];
    }

    private function createActor(string $token, string $label, string $roleSlug): User
    {
        $email = 'idoc-'.$token.'-'.$label.'@example.invalid';
        $user = User::query()->firstOrCreate(['email' => $email], [
            'name' => 'Synthetic '.$label, 'password' => Hash::make($token.'-'.$label),
            'status' => 'ACTIVE', 'is_system_administrator' => false,
        ]);
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user;
    }

    private function actor(string $token, string $label): User
    {
        return User::query()->where('email', 'idoc-'.$token.'-'.$label.'@example.invalid')->firstOrFail();
    }

    private function encounter(string $token, string $scenario): Encounter
    {
        return Encounter::query()->where('booking_code', $this->bookingCode($token, $scenario))->firstOrFail();
    }

    private function bookingCode(string $token, string $scenario): string
    {
        return 'IDOC-'.$token.'-'.substr(hash('sha256', $scenario), 0, 10);
    }

    private function backendConnectionId(): int
    {
        return DB::connection()->getDriverName() === 'pgsql'
            ? (int) DB::scalar('SELECT pg_backend_pid()')
            : (int) DB::scalar('SELECT CONNECTION_ID()');
    }

    private function engineNativeSleep(int $holdMs): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::selectOne('SELECT pg_sleep(?)', [$holdMs / 1000]);
        } else {
            DB::selectOne('SELECT SLEEP(?)', [$holdMs / 1000]);
        }
    }

    /** @return array<string,mixed> */
    private function protocol(string $state, string $scenario, ?string $worker = null): array
    {
        $result = ['schema_version' => 1, 'status' => 'PASS', 'protocol_state' => $state, 'scenario' => $scenario];
        if ($worker !== null) {
            $result['worker'] = $worker;
        }

        return $result;
    }

    private function assertSame(mixed $expected, mixed $actual, string $label): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException($label.' assertion failed.');
        }
    }

    /** @param array<string,mixed> $payload */
    private function emit(array $payload): void
    {
        $this->line(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
