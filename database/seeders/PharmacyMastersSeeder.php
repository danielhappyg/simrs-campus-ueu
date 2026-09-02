<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Pharmacy\PharmacyMasterService;
use Illuminate\Database\Seeder;
use RuntimeException;

class PharmacyMastersSeeder extends Seeder
{
    public const ACTOR_EMAIL = 'pharmacy.inventory.demo@example.invalid';

    public function run(): void
    {
        if (config('simulation.mode') !== 'SIMULATION' || config('simulation.synthetic_only') !== true) {
            throw new RuntimeException('Managed pharmacy masters require synthetic-only simulation.');
        }
        $actor = User::query()->where('email', self::ACTOR_EMAIL)->first();
        if (! $actor instanceof User || $actor->is_system_administrator || $actor->status !== 'DISABLED' || $actor->roleSlugs() !== [RoleCapabilityMatrix::ROLE_PHARMACY_INVENTORY_CONTROLLER]) {
            throw new RuntimeException('Pharmacy master seeding requires the exact disabled inventory controller.');
        }
        $service = app(PharmacyMasterService::class);
        foreach ([['medicine_code' => 'MED-PARACETAMOL-500', 'generic_name' => 'Paracetamol', 'brand_name' => null, 'strength_text' => '500 mg', 'dosage_form' => 'TABLET', 'base_unit' => 'TABLET', 'route_choices' => ['ORAL'], 'acquisition_value' => 350, 'teaching_sale_value' => 700], ['medicine_code' => 'MED-AMOXICILLIN-500', 'generic_name' => 'Amoxicillin', 'brand_name' => null, 'strength_text' => '500 mg', 'dosage_form' => 'KAPSUL', 'base_unit' => 'KAPSUL', 'route_choices' => ['ORAL'], 'acquisition_value' => 900, 'teaching_sale_value' => 1800], ['medicine_code' => 'MED-ORS-SACHET', 'generic_name' => 'Oralit', 'brand_name' => null, 'strength_text' => '1 sachet', 'dosage_form' => 'SERBUK', 'base_unit' => 'SACHET', 'route_choices' => ['ORAL'], 'acquisition_value' => 750, 'teaching_sale_value' => 1500]] as $i => $data) {
            $service->createMedicine($actor, $data, sprintf('seed-pharmacy-medicine-%02d', $i + 1));
        }
        foreach ([['depot_code' => 'DEPO_RJ', 'display_name' => 'Depo Rawat Jalan', 'eligible_care_settings' => ['OUTPATIENT']], ['depot_code' => 'DEPO_IGD', 'display_name' => 'Depo IGD', 'eligible_care_settings' => ['EMERGENCY']], ['depot_code' => 'DEPO_RI', 'display_name' => 'Depo Rawat Inap', 'eligible_care_settings' => ['INPATIENT']]] as $i => $data) {
            $service->createDepot($actor, $data, sprintf('seed-pharmacy-depot-%02d', $i + 1));
        }
    }
}
