<?php

namespace Tests\Feature\Finance;

use App\Models\FinanceCashDepositHandoff;
use App\Models\FinanceCashierCollectionBatch;
use App\Models\FinanceCashierCollectionEvent;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Finance\FinanceAppendOnlyGuard;
use App\Support\Finance\FinanceAuditUnavailable;
use App\Support\Finance\FinanceCashierCollectionProjection;
use App\Support\Finance\FinanceCashierCollectionService;
use App\Support\Finance\FinanceDenied;
use App\Support\Finance\FinanceMutationScope;
use App\Support\Operations\SyntheticRecoverySnapshot;
use Database\Seeders\RbacSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

final class FinanceCashierCollectionCoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_exact_cashier_and_independent_supervisor_complete_append_only_batch_handoff(): void
    {
        $cashier = $this->actor(RoleCapabilityMatrix::ROLE_CASHIER);
        $supervisor = $this->actor(RoleCapabilityMatrix::ROLE_CASHIER_SUPERVISOR);
        $service = app(FinanceCashierCollectionService::class);
        $projection = app(FinanceCashierCollectionProjection::class);

        $opened = $service->open($cashier, 'collection-open-core-0001');
        if (! $opened->record instanceof FinanceCashierCollectionBatch) {
            $this->fail('Opening a collection batch must return its batch record.');
        }
        $batch = $opened->record;
        $this->assertDatabaseCount('finance_cashier_collection_active_slots', 1);
        $open = $projection->batch($batch->public_id, $cashier);
        $this->assertSame('OPEN', $open['state']);

        $closed = $service->requestClose($batch->public_id, $cashier, 1, $open['state_fingerprint'], 'collection-close-core-0001');
        if (! $closed->record instanceof FinanceCashierCollectionEvent) {
            $this->fail('Closing a collection batch must return its close event.');
        }
        $closeEvent = $closed->record;
        $this->assertSame(FinanceCashierCollectionEvent::CLOSE_REQUESTED, $closeEvent->event_type);
        $this->assertSame(1, $closeEvent->variance_amount);
        $this->assertDatabaseCount('finance_cashier_collection_active_slots', 0);
        $closeReplay = $service->requestClose($batch->public_id, $cashier, 1, $open['state_fingerprint'], 'collection-close-core-0001');
        $this->assertTrue($closeReplay->replayed);
        $this->assertSame($closeEvent->public_id, $closeReplay->record->getAttribute('public_id'));

        $mismatch = $projection->batch($batch->public_id, $cashier);
        $this->assertSame('RECOUNT_REQUIRED', $mismatch['state']);
        $recount = $service->recount($batch->public_id, $cashier, 0, $mismatch['state_fingerprint'], 'collection-recount-core-0001', 'Kas fisik dihitung ulang dan sesuai.');
        if (! $recount->record instanceof FinanceCashierCollectionEvent) {
            $this->fail('Recounting a collection batch must return its recount event.');
        }
        $this->assertSame(0, $recount->record->variance_amount);

        $awaiting = $projection->batch($batch->public_id, $supervisor);
        $this->assertSame('AWAITING_SUPERVISOR', $awaiting['state']);
        $verified = $service->verify($batch->public_id, $supervisor, $awaiting['state_fingerprint'], 'collection-verify-core-0001');
        if (! $verified->record instanceof FinanceCashierCollectionEvent) {
            $this->fail('Verifying a collection batch must return its verification event.');
        }
        $verifiedEvent = $verified->record;
        $this->assertSame(FinanceCashierCollectionEvent::CLOSE_VERIFIED, $verifiedEvent->event_type);

        $ready = $projection->batch($batch->public_id, $cashier);
        $handoff = $service->createHandoff($batch->public_id, $cashier, $ready['state_fingerprint'], 'collection-handoff-core-0001');
        if (! $handoff->record instanceof FinanceCashDepositHandoff) {
            $this->fail('Creating a deposit handoff must return its handoff record.');
        }
        $handoffRecord = $handoff->record;
        $this->assertSame($handoffRecord->content_digest, $projection->handoffReceipt($handoffRecord->public_id, $cashier)['content_digest']);
        $this->assertSame('HANDED_OFF', $projection->batch($batch->public_id, $cashier)['state']);
        $this->assertDatabaseCount('finance_cashier_collection_events', 3);
        $this->assertDatabaseCount('finance_cash_deposit_handoffs', 1);
        $this->assertDatabaseCount('finance_cashier_collection_operation_receipts', 5);

        $recovery = new ReflectionMethod(SyntheticRecoverySnapshot::class, 'financeCashierCollectionMismatchCount');
        $snapshot = app(SyntheticRecoverySnapshot::class);
        $this->assertSame(0, $recovery->invoke($snapshot));
        FinanceAppendOnlyGuard::runSyntheticReset(function () use ($recovery, $snapshot, $verifiedEvent, $handoffRecord, $cashier): void {
            FinanceMutationScope::run(function () use ($recovery, $snapshot, $verifiedEvent, $handoffRecord, $cashier): void {
                $receipt = DB::table('finance_cashier_collection_operation_receipts')
                    ->where('operation', FinanceCashierCollectionService::OPERATION_VERIFY)->first();
                DB::table('finance_cashier_collection_operation_receipts')->where('id', $receipt->id)->delete();
                $this->assertGreaterThan(0, $recovery->invoke($snapshot));
                DB::table('finance_cashier_collection_operation_receipts')->insert((array) $receipt);

                DB::table('finance_cashier_collection_events')->where('id', $verifiedEvent->id)->update(['actor_user_id' => $cashier->id]);
                $this->assertGreaterThan(0, $recovery->invoke($snapshot));
                DB::table('finance_cashier_collection_events')->where('id', $verifiedEvent->id)->update(['actor_user_id' => $verifiedEvent->actor_user_id]);

                $tamperedGross = $verifiedEvent->gross_amount + 1;
                DB::table('finance_cashier_collection_events')->where('id', $verifiedEvent->id)->update([
                    'gross_amount' => $tamperedGross,
                    'expected_net_amount' => $tamperedGross - $verifiedEvent->completed_refund_amount,
                    'counted_amount' => $tamperedGross - $verifiedEvent->completed_refund_amount,
                ]);
                $this->assertGreaterThan(0, $recovery->invoke($snapshot));
                DB::table('finance_cashier_collection_events')->where('id', $verifiedEvent->id)->update([
                    'gross_amount' => $verifiedEvent->gross_amount,
                    'expected_net_amount' => $verifiedEvent->expected_net_amount,
                    'counted_amount' => $verifiedEvent->counted_amount,
                ]);

                $originalHandoff = DB::table('finance_cash_deposit_handoffs')->where('id', $handoffRecord->id)->first();
                DB::table('finance_cash_deposit_handoffs')->where('id', $handoffRecord->id)->update([
                    'supervisor_name_snapshot' => 'Substituted', 'content_digest' => str_repeat('f', 64),
                ]);
                $this->assertGreaterThan(0, $recovery->invoke($snapshot));
                DB::table('finance_cash_deposit_handoffs')->where('id', $handoffRecord->id)->update((array) $originalHandoff);

                $openAudit = AuditEvent::query()->where('metadata->operation', FinanceCashierCollectionService::OPERATION_OPEN)->sole();
                $handoffAudit = AuditEvent::query()->where('metadata->operation', FinanceCashierCollectionService::OPERATION_HANDOFF)->sole();
                DB::table('audit_events')->where('id', $openAudit->id)->update(['metadata' => json_encode($handoffAudit->metadata, JSON_THROW_ON_ERROR)]);
                $this->assertGreaterThan(0, $recovery->invoke($snapshot));
                DB::table('audit_events')->where('id', $openAudit->id)->update(['metadata' => json_encode($openAudit->metadata, JSON_THROW_ON_ERROR)]);
            });
        });
    }

    public function test_roles_ownership_idempotency_and_zero_variance_fail_closed(): void
    {
        $cashier = $this->actor(RoleCapabilityMatrix::ROLE_CASHIER);
        $otherCashier = $this->actor(RoleCapabilityMatrix::ROLE_CASHIER);
        $supervisor = $this->actor(RoleCapabilityMatrix::ROLE_CASHIER_SUPERVISOR);
        $nurse = $this->actor(RoleCapabilityMatrix::ROLE_NURSE);
        $service = app(FinanceCashierCollectionService::class);
        $projection = app(FinanceCashierCollectionProjection::class);

        $opened = $service->open($cashier, 'collection-open-denial-0001');
        $replay = $service->open($cashier, 'collection-open-denial-0001');
        $this->assertTrue($replay->replayed);
        $this->assertSame($opened->record->getAttribute('public_id'), $replay->record->getAttribute('public_id'));
        $this->expectException(AuthorizationException::class);
        $service->open($nurse, 'collection-open-denial-0002');
    }

    public function test_nonzero_variance_refuses_supervisor_verification(): void
    {
        $cashier = $this->actor(RoleCapabilityMatrix::ROLE_CASHIER);
        $supervisor = $this->actor(RoleCapabilityMatrix::ROLE_CASHIER_SUPERVISOR);
        $service = app(FinanceCashierCollectionService::class);
        $projection = app(FinanceCashierCollectionProjection::class);
        $batch = $service->open($cashier, 'collection-open-variance-0001')->record;
        if (! $batch instanceof FinanceCashierCollectionBatch) {
            $this->fail('Opening a collection batch must return its batch record.');
        }
        $open = $projection->batch($batch->public_id, $cashier);
        $service->requestClose($batch->public_id, $cashier, 100, $open['state_fingerprint'], 'collection-close-variance-0001');
        $frozen = $projection->batch($batch->public_id, $supervisor);

        try {
            $service->verify($batch->public_id, $supervisor, $frozen['state_fingerprint'], 'collection-verify-variance-0001');
            $this->fail('Expected non-zero variance refusal.');
        } catch (FinanceDenied $denied) {
            $this->assertSame('batch_variance_nonzero', $denied->reason);
        }
        $this->assertDatabaseCount('finance_cash_deposit_handoffs', 0);
    }

    public function test_ownership_same_actor_stale_state_and_idempotency_conflict_fail_closed(): void
    {
        $cashier = $this->actor(RoleCapabilityMatrix::ROLE_CASHIER);
        $otherCashier = $this->actor(RoleCapabilityMatrix::ROLE_CASHIER);
        $service = app(FinanceCashierCollectionService::class);
        $projection = app(FinanceCashierCollectionProjection::class);
        $batch = $service->open($cashier, 'collection-open-controls-0001')->record;
        if (! $batch instanceof FinanceCashierCollectionBatch) {
            $this->fail('Opening a collection batch must return its batch record.');
        }

        $this->expectException(ModelNotFoundException::class);
        try {
            $projection->batch($batch->public_id, $otherCashier);
        } finally {
            $open = $projection->batch($batch->public_id, $cashier);
            try {
                $service->requestClose($batch->public_id, $cashier, 0, str_repeat('0', 64), 'collection-close-stale-0001');
                $this->fail('Expected stale state refusal.');
            } catch (FinanceDenied $denied) {
                $this->assertSame('stale_collection_batch', $denied->reason);
            }
            $service->requestClose($batch->public_id, $cashier, 0, $open['state_fingerprint'], 'collection-close-controls-0001');
            try {
                $service->requestClose($batch->public_id, $cashier, 1, $open['state_fingerprint'], 'collection-close-controls-0001');
                $this->fail('Expected idempotency conflict.');
            } catch (FinanceDenied $denied) {
                $this->assertSame('idempotency_key_conflict', $denied->reason);
            }

            $cashier->roles()->sync([Role::query()->where('slug', RoleCapabilityMatrix::ROLE_CASHIER_SUPERVISOR)->sole()->id]);
            $frozen = $projection->batch($batch->public_id, $cashier->fresh());
            try {
                $service->verify($batch->public_id, $cashier->fresh(), $frozen['state_fingerprint'], 'collection-verify-same-actor-0001');
                $this->fail('Expected same actor refusal.');
            } catch (FinanceDenied $denied) {
                $this->assertSame('same_actor_separation', $denied->reason);
            }
        }
    }

    public function test_append_only_tamper_and_missing_success_audit_roll_back(): void
    {
        $cashier = $this->actor(RoleCapabilityMatrix::ROLE_CASHIER);
        $batch = app(FinanceCashierCollectionService::class)->open($cashier, 'collection-open-guard-0001')->record;
        if (! $batch instanceof FinanceCashierCollectionBatch) {
            $this->fail('Opening a collection batch must return its batch record.');
        }
        try {
            DB::transaction(fn () => FinanceMutationScope::run(
                fn () => DB::table('finance_cashier_collection_batches')->where('id', $batch->id)->update(['batch_number' => 'TAMPER']),
            ));
            $this->fail('Expected append-only trigger refusal.');
        } catch (QueryException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }

        $second = $this->actor(RoleCapabilityMatrix::ROLE_CASHIER);
        $this->mock(AuditRecorder::class, function ($mock): void {
            $mock->shouldReceive('record')->once()->andReturnNull();
        });
        try {
            app(FinanceCashierCollectionService::class)->open($second, 'collection-open-audit-fail-0001');
            $this->fail('Expected required audit rollback.');
        } catch (FinanceAuditUnavailable) {
            $this->assertDatabaseMissing('finance_cashier_collection_active_slots', ['cashier_user_id' => $second->id]);
            $this->assertDatabaseMissing('finance_cashier_collection_batches', ['cashier_user_id' => $second->id]);
        }
    }

    public function test_batch_replay_refuses_substituted_success_audit(): void
    {
        $cashier = $this->actor(RoleCapabilityMatrix::ROLE_CASHIER);
        $service = app(FinanceCashierCollectionService::class);
        $batch = $service->open($cashier, 'collection-open-replay-audit-0001')->record;
        $this->assertInstanceOf(FinanceCashierCollectionBatch::class, $batch);
        $audit = AuditEvent::query()->where('resource_id', $batch->public_id)
            ->where('metadata->operation', FinanceCashierCollectionService::OPERATION_OPEN)->sole();
        $metadata = $audit->metadata;
        $metadata['batch_content_digest'] = str_repeat('f', 64);
        DB::table('audit_events')->where('id', $audit->id)->update([
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
        ]);

        try {
            $service->open($cashier, 'collection-open-replay-audit-0001');
            $this->fail('Expected replay to reject a substituted success audit.');
        } catch (FinanceDenied $denied) {
            $this->assertSame('batch_integrity_failure', $denied->reason);
        }
    }

    private function actor(string $role): User
    {
        $actor = User::factory()->create();
        $actor->roles()->sync([Role::query()->where('slug', $role)->sole()->id]);

        return $actor->fresh();
    }
}
