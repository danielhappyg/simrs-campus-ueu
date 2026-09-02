<?php

namespace Tests\Feature\Pharmacy;

use App\Models\Encounter;
use App\Models\PharmacyDepot;
use App\Models\PharmacyStockLot;
use App\Models\PharmacyStockMovement;
use App\Models\Role;
use App\Models\User;
use App\Models\WarehouseCustodyLot;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Pharmacy\PharmacyMasterService;
use App\Support\Pharmacy\PharmacyMutationScope;
use App\Support\Pharmacy\PharmacyStockService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class WarehouseCustodyPharmacyModelIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (in_array(DB::connection()->getDriverName(), ['pgsql', 'mysql'], true)
            && ! Schema::hasTable('warehouse_suppliers')) {
            $this->markTestSkipped(
                'Exact-engine warehouse integration tests remain deferred until the governed identity/routine harness enables the migration.',
            );
        }

        $this->seed(RbacSeeder::class);
    }

    public function test_warehouse_custody_model_surface_preserves_legacy_opening_stock_defaults(): void
    {
        $inventory = User::factory()->create();
        $inventory->roles()->sync([
            Role::query()->where('slug', RoleCapabilityMatrix::ROLE_PHARMACY_INVENTORY_CONTROLLER)->sole()->id,
        ]);

        $masters = app(PharmacyMasterService::class);
        $medicine = $masters->createMedicine($inventory, [
            'medicine_code' => 'MED-CUSTODY-MODEL',
            'generic_name' => 'Obat Integrasi Gudang',
            'brand_name' => null,
            'strength_text' => '500 mg',
            'dosage_form' => 'TABLET',
            'base_unit' => 'TABLET',
            'route_choices' => ['ORAL'],
            'acquisition_value' => 500,
            'teaching_sale_value' => 1000,
        ], 'warehouse-custody-model-medicine')->record;
        $depot = $masters->createDepot($inventory, [
            'depot_code' => 'DEPO-CUSTODY-MODEL',
            'display_name' => 'Depo Integrasi Gudang',
            'eligible_care_settings' => [Encounter::CARE_SETTING_OUTPATIENT],
        ], 'warehouse-custody-model-depot')->record->fresh();

        $this->assertSame(PharmacyDepot::DISPENSING_DEPOT, $depot->location_kind);
        $this->assertSame(PharmacyDepot::DISPENSING_DEPOT, $depot->versions()->sole()->location_kind);
        $this->assertTrue($depot->acceptsPrescriptionFor(Encounter::CARE_SETTING_OUTPATIENT));
        $this->assertTrue(PharmacyDepot::query()->prescriptionDestinations()->whereKey($depot->id)->exists());

        $centralWarehouse = $masters->createDepot($inventory, [
            'depot_code' => 'GUDANG-CUSTODY-MODEL',
            'display_name' => 'Gudang Farmasi Integrasi',
            'eligible_care_settings' => [Encounter::CARE_SETTING_OUTPATIENT],
        ], 'warehouse-custody-model-central')->record->fresh();
        PharmacyMutationScope::run(function () use ($centralWarehouse): void {
            $centralWarehouse->location_kind = PharmacyDepot::CENTRAL_WAREHOUSE;
            $centralWarehouse->save();
        });
        $centralWarehouse->refresh();

        $this->assertFalse($centralWarehouse->acceptsPrescriptionFor(Encounter::CARE_SETTING_OUTPATIENT));
        $this->assertFalse(PharmacyDepot::query()->prescriptionDestinations()->whereKey($centralWarehouse->id)->exists());

        $lot = app(PharmacyStockService::class)->openLot($inventory, $medicine->public_id, $depot->public_id, [
            'lot_code' => 'LOT-CUSTODY-MODEL-01',
            'received_at' => now()->subMinute(),
            'expiry_date' => now()->addMonth()->toDateString(),
            'opening_quantity' => 11,
            'source_reference' => 'SYNTHETIC-CUSTODY-MODEL',
        ], 'warehouse-custody-model-lot')->record->fresh();
        $opening = $lot->movements()->sole();

        $this->assertNull($lot->warehouse_custody_lot_id);
        $this->assertNull($lot->warehouse_source_type);
        $this->assertNull($lot->warehouse_source_public_id);
        $this->assertSame(0, $lot->transit_quantity);
        $this->assertInstanceOf(WarehouseCustodyLot::class, $lot->warehouseCustodyLot()->getRelated());
        $this->assertSame(0, $opening->transit_delta);
        $this->assertSame(0, $opening->transit_balance_after);
        $this->assertNull($opening->custody_chain_public_id);
        $this->assertNull($opening->pair_public_id);
        $this->assertNull($opening->pair_leg);
        $this->assertSame(PharmacyStockMovement::OPENING, $opening->movement_type);
        $this->assertSame('TRANSFER_ITEM', PharmacyStockLot::WAREHOUSE_SOURCE_TRANSFER_ITEM);
        $this->assertSame('WAREHOUSE_TRANSIT_IN', PharmacyStockMovement::WAREHOUSE_TRANSIT_IN);
        $this->assertSame('DESTINATION_IN', PharmacyStockMovement::PAIR_LEG_DESTINATION_IN);
    }
}
