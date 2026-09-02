<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Laboratory\LaboratoryMasterService;
use Illuminate\Database\Seeder;
use RuntimeException;

class LaboratoryMastersSeeder extends Seeder
{
    public const ACTOR_EMAIL = 'admin.demo@example.invalid';

    public function run(): void
    {
        if (config('simulation.mode') !== 'SIMULATION' || config('simulation.synthetic_only') !== true) {
            throw new RuntimeException('Managed laboratory masters require the synthetic simulation boundary.');
        }
        $actor = User::query()->where('email', self::ACTOR_EMAIL)->first();
        if (! $actor instanceof User || $actor->is_system_administrator || $actor->status !== 'DISABLED' || $actor->roleSlugs() !== [RoleCapabilityMatrix::ROLE_ADMIN]) {
            throw new RuntimeException('Managed laboratory master seeding requires the exact disabled demo administrator.');
        }
        $service = app(LaboratoryMasterService::class);
        foreach (self::catalogue() as $index => $examination) {
            $service->create($actor, $examination['code'], $examination['name'], $examination['specimen_type'], $examination['instruction'], $examination['components'], sprintf('seed-laboratory-master-%02d', $index + 1));
        }
    }

    /** @return list<array<string,mixed>> */
    public static function catalogue(): array
    {
        return [
            ['code' => 'LAB-HEMATOLOGY-CBC', 'name' => 'Darah Lengkap', 'specimen_type' => 'Darah EDTA', 'instruction' => 'Koleksi darah vena pada tabung EDTA sesuai prosedur unit.', 'components' => [
                ['code' => 'HGB', 'display_name' => 'Hemoglobin', 'value_kind' => 'NUMERIC', 'unit_text' => 'g/dL', 'reference_text' => 'Gunakan rentang rujukan unit laboratorium.', 'critical_allowed' => true],
                ['code' => 'WBC', 'display_name' => 'Leukosit', 'value_kind' => 'NUMERIC', 'unit_text' => '10^3/uL', 'reference_text' => 'Gunakan rentang rujukan unit laboratorium.', 'critical_allowed' => true],
                ['code' => 'PLT', 'display_name' => 'Trombosit', 'value_kind' => 'NUMERIC', 'unit_text' => '10^3/uL', 'reference_text' => 'Gunakan rentang rujukan unit laboratorium.', 'critical_allowed' => true],
            ]],
            ['code' => 'LAB-CHEM-GLUCOSE', 'name' => 'Glukosa Darah', 'specimen_type' => 'Serum atau plasma', 'instruction' => 'Konfirmasi status puasa sesuai instruksi klinis sebelum koleksi.', 'components' => [
                ['code' => 'GLU', 'display_name' => 'Glukosa', 'value_kind' => 'NUMERIC', 'unit_text' => 'mg/dL', 'reference_text' => 'Interpretasi mengikuti konteks pemeriksaan dan rentang unit.', 'critical_allowed' => true],
            ]],
            ['code' => 'LAB-URINALYSIS', 'name' => 'Urinalisis Rutin', 'specimen_type' => 'Urine sewaktu', 'instruction' => 'Gunakan wadah bersih berlabel dan kirimkan segera ke unit laboratorium.', 'components' => [
                ['code' => 'COLOR', 'display_name' => 'Warna', 'value_kind' => 'QUALITATIVE', 'unit_text' => null, 'reference_text' => 'Deskripsi visual.', 'critical_allowed' => false],
                ['code' => 'PROTEIN', 'display_name' => 'Protein', 'value_kind' => 'QUALITATIVE', 'unit_text' => null, 'reference_text' => 'Laporkan sesuai hasil pemeriksaan.', 'critical_allowed' => false],
                ['code' => 'SEDIMENT', 'display_name' => 'Sedimen', 'value_kind' => 'TEXT', 'unit_text' => null, 'reference_text' => 'Deskripsi manual.', 'critical_allowed' => true],
            ]],
        ];
    }
}
