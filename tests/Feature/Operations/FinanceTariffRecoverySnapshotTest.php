<?php

namespace Tests\Feature\Operations;

use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Finance\FinanceTariffMasterService;
use App\Support\Operations\SyntheticRecoverySnapshot;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

final class FinanceTariffRecoverySnapshotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_tariff_recovery_integrity_helpers_accept_an_empty_consistent_master(): void
    {
        $snapshot = new SyntheticRecoverySnapshot;
        $reflection = new ReflectionClass($snapshot);

        foreach ([
            'financeTariffVersionChainMismatchCount',
            'financeTariffHeadMismatchCount',
            'financeTariffEffectivePeriodMismatchCount',
            'financeTariffUpstreamMismatchCount',
            'financeTariffCodeReservationMismatchCount',
            'financeTariffReceiptResultMismatchCount',
        ] as $methodName) {
            $this->assertSame(0, $reflection->getMethod($methodName)->invoke($snapshot), $methodName);
        }
    }

    public function test_snapshot_registers_counts_digests_orphans_and_tariff_integrity_checks(): void
    {
        $source = file_get_contents(app_path('Support/Operations/SyntheticRecoverySnapshot.php'));
        $this->assertIsString($source);

        foreach ([
            'finance_cost_component_groups', 'finance_cost_component_group_versions',
            'finance_cost_components', 'finance_cost_component_versions',
            'finance_tariff_catalogues', 'finance_tariff_catalogue_versions',
            'finance_tariff_items', 'finance_tariff_item_versions',
            'finance_tariff_code_reservations', 'finance_tariff_operation_receipts',
        ] as $table) {
            $this->assertStringContainsString("'{$table}' =>", $source);
            $this->assertStringContainsString("'{$table}_sha256' =>", $source);
        }

        foreach ([
            'finance_tariff_group_version_without_group',
            'finance_tariff_component_without_group',
            'finance_tariff_component_version_without_component',
            'finance_tariff_catalogue_version_without_catalogue',
            'finance_tariff_item_without_catalogue',
            'finance_tariff_item_without_component',
            'finance_tariff_item_version_without_item',
            'finance_tariff_receipt_without_actor',
            'finance_tariff_version_chain_mismatches',
            'finance_tariff_head_mismatches',
            'finance_tariff_effective_period_mismatches',
            'finance_tariff_upstream_mismatches',
            'finance_tariff_code_reservation_mismatches',
            'finance_tariff_receipt_result_mismatches',
        ] as $contract) {
            $this->assertStringContainsString("'{$contract}' =>", $source);
        }
    }

    public function test_integrity_helpers_reconcile_real_latest_authored_and_future_tariff_versions(): void
    {
        $actor = User::factory()->create();
        $actor->roles()->sync([
            Role::query()->where('slug', RoleCapabilityMatrix::ROLE_FINANCE_STEWARD)->sole()->id,
        ]);
        $actor = $actor->fresh();
        $service = app(FinanceTariffMasterService::class);

        $group = $service->createGroup($actor, 'recovery-group', 'Group Pemulihan', 'Membuat fixture pemulihan.', 'recovery-group-create')->record;
        $group = $service->reviseGroup(
            $actor,
            (string) $group->public_id,
            'Group Pemulihan Revisi',
            (int) $group->version,
            (string) $group->current_content_digest,
            'Menguji versi group terbaru.',
            'recovery-group-revise',
        )->record;
        $component = $service->createComponent(
            $actor,
            (string) $group->public_id,
            'recovery-component',
            'Komponen Pemulihan',
            'Komponen untuk bukti pemulihan.',
            null,
            'Membuat komponen pemulihan.',
            'recovery-component-create',
        )->record;
        $component = $service->reviseComponent(
            $actor,
            (string) $component->public_id,
            'Komponen Pemulihan Revisi',
            'Deskripsi versi kedua.',
            null,
            (int) $component->version,
            (string) $component->current_content_digest,
            'Menguji versi komponen terbaru.',
            'recovery-component-revise',
        )->record;
        $catalogue = $service->createCatalogue($actor, 'recovery-catalogue', 'Katalog Pemulihan', 'Membuat katalog pemulihan.', 'recovery-catalogue-create')->record;
        $catalogue = $service->reviseCatalogue(
            $actor,
            (string) $catalogue->public_id,
            'Katalog Pemulihan Revisi',
            (int) $catalogue->version,
            (string) $catalogue->current_content_digest,
            'Menguji versi katalog terbaru.',
            'recovery-catalogue-revise',
        )->record;
        $item = $service->createTariffItem(
            $actor,
            (string) $catalogue->public_id,
            (string) $component->public_id,
            'recovery-tariff',
            'Tarif Pemulihan',
            'OUTPATIENT',
            'GENERAL_SERVICE',
            null,
            null,
            1000,
            now()->toDateString(),
            'Membuat tarif pemulihan.',
            'recovery-tariff-create',
        )->record;
        $service->appendTariffItemVersion(
            $actor,
            (string) $item->public_id,
            'Tarif Pemulihan Versi Dua',
            'OUTPATIENT',
            'GENERAL_SERVICE',
            null,
            null,
            1500,
            now()->addDay()->toDateString(),
            (int) $item->version,
            (string) $item->current_content_digest,
            'Menambahkan versi tarif masa depan.',
            'recovery-tariff-append',
        );

        $snapshot = new SyntheticRecoverySnapshot;
        $reflection = new ReflectionClass($snapshot);
        foreach ([
            'financeTariffVersionChainMismatchCount',
            'financeTariffHeadMismatchCount',
            'financeTariffEffectivePeriodMismatchCount',
            'financeTariffUpstreamMismatchCount',
            'financeTariffCodeReservationMismatchCount',
            'financeTariffReceiptResultMismatchCount',
        ] as $methodName) {
            $this->assertSame(0, $reflection->getMethod($methodName)->invoke($snapshot), $methodName);
        }
    }
}
