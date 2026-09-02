<?php

namespace Tests\Unit\Audit;

use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Finance\FinanceTariffDenied;
use App\Support\Finance\FinanceTariffMasterService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class FinanceTariffAuditIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_real_create_revise_replay_and_denial_events_pass_the_closed_registry(): void
    {
        $actor = User::factory()->create();
        $actor->roles()->sync([
            Role::query()->where('slug', RoleCapabilityMatrix::ROLE_FINANCE_STEWARD)->sole()->id,
        ]);
        $actor = $actor->fresh();
        $service = app(FinanceTariffMasterService::class);

        $created = $service->createGroup(
            $actor,
            'audit-group',
            'Group Audit Tarif',
            'Membuat bukti audit integrasi.',
            'audit-group-create-v1',
        );
        $originalVersion = (int) $created->record->version;
        $originalDigest = (string) $created->record->current_content_digest;

        $revised = $service->reviseGroup(
            $actor,
            (string) $created->record->public_id,
            'Group Audit Tarif Revisi',
            $originalVersion,
            $originalDigest,
            'Memeriksa audit revisi master.',
            'audit-group-revise-v2',
        );
        $replay = $service->reviseGroup(
            $actor,
            (string) $created->record->public_id,
            'Group Audit Tarif Revisi',
            $originalVersion,
            $originalDigest,
            'Memeriksa audit revisi master.',
            'audit-group-revise-v2',
        );
        $this->assertTrue($replay->replayed);

        try {
            $service->reviseGroup(
                $actor,
                (string) $created->record->public_id,
                'Revisi Stale',
                $originalVersion,
                $originalDigest,
                'Membuktikan penolakan stale.',
                'audit-group-stale-v1',
            );
            $this->fail('Expected stale tariff master denial.');
        } catch (FinanceTariffDenied $exception) {
            $this->assertSame('stale_version', $exception->reason);
        }

        $events = AuditEvent::query()
            ->where('action', 'finance.tariff.mutate')
            ->where('resource_type', 'finance_tariff_record')
            ->where('resource_id', $created->record->public_id)
            ->orderBy('recorded_at')
            ->get();
        $this->assertCount(4, $events);
        $this->assertSame(['SUCCESS', 'SUCCESS', 'SUCCESS', 'DENIED'], $events->pluck('outcome')->all());
        $this->assertSame([false, false, true], $events->where('outcome', 'SUCCESS')->pluck('metadata.replayed')->values()->all());
        $this->assertSame('FINANCE_COST_COMPONENT_GROUP_REVISE', $events->last()->metadata['operation']);
        $this->assertSame('COST_COMPONENT_GROUP', $events->last()->metadata['entity_type']);
        $this->assertSame('stale_version', $events->last()->reason);
        $this->assertSame(2, $revised->record->version);
    }
}
