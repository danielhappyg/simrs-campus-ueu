<?php

namespace Tests\Feature\Finance;

use App\Models\FinanceCashDepositHandoff;
use App\Models\FinanceCashierCollectionBatch;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Finance\FinanceCashierCollectionProjection;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class FinanceCashierCollectionHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_cashier_and_supervisor_complete_the_http_close_recount_verify_and_handoff_journey(): void
    {
        $cashier = $this->actor(RoleCapabilityMatrix::ROLE_CASHIER, 'Kasir Pendidikan Pagi');
        $supervisor = $this->actor(RoleCapabilityMatrix::ROLE_CASHIER_SUPERVISOR, 'Supervisor Kasir Pendidikan');

        $this->actingAs($cashier)->get(route('finance.cashier-collections.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('kasir/batch-penerimaan-kas/index')
                ->where('definition_version', 'APPEND_ONLY_CASHIER_COLLECTION_BATCH_V1')
                ->where('actor_role', RoleCapabilityMatrix::ROLE_CASHIER)
                ->where('open_batch.allowed', true)
                ->has('batches', 0));

        $this->actingAs($cashier)->post(route('finance.cashier-collections.open'), [
            'confirm_open' => true,
            'idempotency_key' => 'http-collection-open-0001',
        ])->assertRedirect();

        $batch = FinanceCashierCollectionBatch::query()->sole();
        $batchPublicId = (string) $batch->getAttribute('public_id');
        $projection = app(FinanceCashierCollectionProjection::class);
        $open = $projection->batch($batchPublicId, $cashier);

        $this->actingAs($cashier)->get(route('finance.cashier-collections.show', ['batch' => $batchPublicId]))
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('kasir/batch-penerimaan-kas/show')
                ->where('batch.state', 'OPEN')
                ->where('batch.state_fingerprint', $open['state_fingerprint'])
                ->where('actions.request_close.allowed', true)
                ->where('actions.verify.allowed', false));

        $this->actingAs($cashier)->post(route('finance.cashier-collections.close', ['batch' => $batchPublicId]), [
            'counted_amount' => 1,
            'expected_state_fingerprint' => $open['state_fingerprint'],
            'confirm_close' => true,
            'idempotency_key' => 'http-collection-close-0001',
        ])->assertRedirect();

        $mismatch = $projection->batch($batchPublicId, $cashier);
        $this->assertSame('RECOUNT_REQUIRED', $mismatch['state']);
        $this->actingAs($cashier)->post(route('finance.cashier-collections.recount', ['batch' => $batchPublicId]), [
            'counted_amount' => 0,
            'explanation' => 'Kas fisik dihitung ulang dan telah sesuai.',
            'expected_state_fingerprint' => $mismatch['state_fingerprint'],
            'confirm_recount' => true,
            'idempotency_key' => 'http-collection-recount-0001',
        ])->assertRedirect();

        $awaiting = $projection->batch($batchPublicId, $supervisor);
        $this->actingAs($supervisor)->get(route('finance.cashier-collections.show', ['batch' => $batchPublicId]))
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('actor_role', RoleCapabilityMatrix::ROLE_CASHIER_SUPERVISOR)
                ->where('batch.state', 'AWAITING_SUPERVISOR')
                ->where('actions.verify.allowed', true)
                ->where('actions.request_close.allowed', false));

        $this->actingAs($supervisor)->post(route('finance.cashier-collections.verify', ['batch' => $batchPublicId]), [
            'expected_state_fingerprint' => $awaiting['state_fingerprint'],
            'confirm_action' => true,
            'idempotency_key' => 'http-collection-verify-0001',
        ])->assertRedirect();

        $verified = $projection->batch($batchPublicId, $cashier);
        $this->actingAs($cashier)->post(route('finance.cashier-collections.handoff', ['batch' => $batchPublicId]), [
            'expected_state_fingerprint' => $verified['state_fingerprint'],
            'confirm_action' => true,
            'idempotency_key' => 'http-collection-handoff-0001',
        ])->assertRedirect();

        $handoff = FinanceCashDepositHandoff::query()->sole();
        $handoffPublicId = (string) $handoff->getAttribute('public_id');
        $handoffDigest = (string) $handoff->getAttribute('content_digest');
        $this->actingAs($cashier)->get(route('finance.cashier-collections.handoff-receipt', ['handoff' => $handoffPublicId]))
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('kasir/batch-penerimaan-kas/bukti-penyerahan')
                ->where('receipt.batch_public_id', $batchPublicId)
                ->where('receipt.cashier_name', 'Kasir Pendidikan Pagi')
                ->where('receipt.supervisor_name', 'Supervisor Kasir Pendidikan')
                ->where('receipt.variance_amount', 0)
                ->where('receipt.content_digest', $handoffDigest));
    }

    public function test_authorization_precedes_lookup_and_cashier_ownership_is_not_disclosed(): void
    {
        $cashier = $this->actor(RoleCapabilityMatrix::ROLE_CASHIER);
        $otherCashier = $this->actor(RoleCapabilityMatrix::ROLE_CASHIER);
        $supervisor = $this->actor(RoleCapabilityMatrix::ROLE_CASHIER_SUPERVISOR);
        $nurse = $this->actor(RoleCapabilityMatrix::ROLE_NURSE);

        $this->actingAs($cashier)->post(route('finance.cashier-collections.open'), [
            'confirm_open' => true,
            'idempotency_key' => 'http-collection-owned-0001',
        ])->assertRedirect();
        $batch = FinanceCashierCollectionBatch::query()->sole();
        $batchPublicId = (string) $batch->getAttribute('public_id');

        $this->actingAs($nurse)
            ->get('/kasir/batch-penerimaan-kas/01K00000000000000000000999')
            ->assertForbidden();
        $this->actingAs($otherCashier)
            ->get(route('finance.cashier-collections.show', ['batch' => $batchPublicId]))
            ->assertNotFound();
        $this->actingAs($supervisor)->post(route('finance.cashier-collections.open'), [
            'confirm_open' => true,
            'idempotency_key' => 'http-collection-supervisor-0001',
        ])->assertForbidden();

        $open = app(FinanceCashierCollectionProjection::class)->batch($batchPublicId, $cashier);
        $this->actingAs($cashier)->from(route('finance.cashier-collections.show', ['batch' => $batchPublicId]))
            ->post(route('finance.cashier-collections.close', ['batch' => $batchPublicId]), [
                'counted_amount' => 0,
                'expected_state_fingerprint' => str_repeat('0', 64),
                'confirm_close' => true,
                'idempotency_key' => 'http-collection-stale-0001',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('collection');
        $this->assertSame('OPEN', $open['state']);
        $this->assertDatabaseCount('finance_cashier_collection_events', 0);
    }

    private function actor(string $role, ?string $name = null): User
    {
        $actor = User::factory()->create($name ? ['name' => $name] : []);
        $actor->roles()->sync([Role::query()->where('slug', $role)->sole()->id]);

        return $actor->fresh();
    }
}
