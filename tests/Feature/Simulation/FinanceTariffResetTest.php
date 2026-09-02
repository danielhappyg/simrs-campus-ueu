<?php

namespace Tests\Feature\Simulation;

use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Finance\FinanceTariffMasterService;
use App\Support\Simulation\SyntheticResetService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class FinanceTariffResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_reset_removes_the_complete_tariff_graph_and_preserves_audit_evidence(): void
    {
        $actor = User::factory()->create();
        $actor->roles()->sync([
            Role::query()->where('slug', RoleCapabilityMatrix::ROLE_FINANCE_STEWARD)->sole()->id,
        ]);
        $actor = $actor->fresh();
        $service = app(FinanceTariffMasterService::class);

        $group = $service->createGroup($actor, 'reset-group', 'Group Reset', 'Membuat fixture reset.', 'reset-group-create-v1')->record;
        $component = $service->createComponent(
            $actor,
            (string) $group->public_id,
            'reset-component',
            'Komponen Reset',
            'Komponen sintetis untuk reset.',
            null,
            'Membuat fixture komponen reset.',
            'reset-component-create-v1',
        )->record;
        $catalogue = $service->createCatalogue(
            $actor,
            'reset-catalogue',
            'Katalog Reset',
            'Membuat fixture katalog reset.',
            'reset-catalogue-create-v1',
        )->record;
        $service->createTariffItem(
            $actor,
            (string) $catalogue->public_id,
            (string) $component->public_id,
            'reset-tariff',
            'Tarif Reset',
            'OUTPATIENT',
            'GENERAL_SERVICE',
            null,
            null,
            1000,
            now()->toDateString(),
            'Membuat fixture tarif reset.',
            'reset-tariff-create-v1',
        );

        $auditIds = AuditEvent::query()
            ->where('action', 'finance.tariff.mutate')
            ->orderBy('id')
            ->pluck('id')
            ->all();
        $this->assertCount(4, $auditIds);

        app(SyntheticResetService::class)->reset([
            'actor' => $actor,
            'reason' => 'finance_tariff_reset_test',
        ]);

        foreach ([
            'finance_tariff_operation_receipts',
            'finance_tariff_item_versions',
            'finance_tariff_items',
            'finance_tariff_catalogue_versions',
            'finance_tariff_catalogues',
            'finance_cost_component_versions',
            'finance_cost_components',
            'finance_cost_component_group_versions',
            'finance_cost_component_groups',
            'finance_tariff_code_reservations',
        ] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table.' must be empty after reset.');
        }
        $this->assertSame(
            $auditIds,
            AuditEvent::query()->whereIn('id', $auditIds)->orderBy('id')->pluck('id')->all(),
        );
        $this->assertDatabaseHas('audit_events', [
            'action' => 'teaching.reset.completed',
            'resource_type' => 'simulation',
            'reason' => 'finance_tariff_reset_test',
        ]);
    }
}
