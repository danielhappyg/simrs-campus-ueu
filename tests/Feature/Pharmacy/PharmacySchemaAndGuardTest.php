<?php

namespace Tests\Feature\Pharmacy;

use App\Models\PharmacyMedicine;
use App\Models\PharmacyMedicineVersion;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Pharmacy\PharmacyMasterService;
use App\Support\Pharmacy\PharmacyMutationScope;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

final class PharmacySchemaAndGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_application_sql_guard_refuses_ungoverned_pharmacy_write(): void
    {
        $medicine = $this->medicine();

        try {
            DB::table('pharmacy_medicines')->where('id', $medicine->id)->update(['generic_name' => 'Bypass']);
            $this->fail('Expected the pharmacy SQL write guard to refuse the write.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('Write-capable SQL against pharmacy tables is prohibited', $exception->getMessage());
        }

        $this->assertSame('Obat Guard', $medicine->fresh()->generic_name);
    }

    public function test_append_only_evidence_refuses_update_and_delete_even_inside_mutation_scope(): void
    {
        $version = $this->medicine()->versions()->sole();

        foreach (['update', 'delete'] as $operation) {
            try {
                PharmacyMutationScope::run(function () use ($version, $operation): void {
                    $query = DB::table((new PharmacyMedicineVersion)->getTable())->where('id', $version->id);
                    $operation === 'update'
                        ? $query->update(['generic_name' => 'Tampered'])
                        : $query->delete();
                });
                $this->fail('Expected append-only database refusal.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('pharmacy append-only evidence is immutable', $exception->getMessage());
            }
        }

        $this->assertDatabaseHas('pharmacy_medicine_versions', [
            'id' => $version->id,
            'generic_name' => 'Obat Guard',
        ]);
    }

    private function medicine(): PharmacyMedicine
    {
        $inventory = User::factory()->create();
        $inventory->roles()->sync([
            Role::query()->where('slug', RoleCapabilityMatrix::ROLE_PHARMACY_INVENTORY_CONTROLLER)->sole()->id,
        ]);

        return app(PharmacyMasterService::class)->createMedicine($inventory->fresh(), [
            'medicine_code' => 'MED-GUARD',
            'generic_name' => 'Obat Guard',
            'brand_name' => null,
            'strength_text' => '500 mg',
            'dosage_form' => 'TABLET',
            'base_unit' => 'TABLET',
            'route_choices' => ['ORAL'],
            'acquisition_value' => 400,
            'teaching_sale_value' => 1000,
        ], 'pharmacy-guard-medicine')->record;
    }
}
