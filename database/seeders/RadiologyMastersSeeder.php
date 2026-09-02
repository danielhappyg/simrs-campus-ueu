<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Radiology\RadiologyMasterService;
use Illuminate\Database\Seeder;
use RuntimeException;

class RadiologyMastersSeeder extends Seeder
{
    public const ACTOR_EMAIL = 'admin.demo@example.invalid';

    public function run(): void
    {
        if (config('simulation.mode') !== 'SIMULATION' || config('simulation.synthetic_only') !== true) {
            throw new RuntimeException('Managed radiology masters require the synthetic simulation boundary.');
        }

        $actor = User::query()->where('email', self::ACTOR_EMAIL)->first();
        if (! $actor instanceof User
            || $actor->is_system_administrator
            || $actor->status !== 'DISABLED'
            || $actor->roleSlugs() !== [RoleCapabilityMatrix::ROLE_ADMIN]) {
            throw new RuntimeException('Managed radiology master seeding requires the exact disabled demo administrator.');
        }

        $service = app(RadiologyMasterService::class);
        foreach (self::catalogue() as $index => $examination) {
            $service->create(
                actor: $actor,
                code: $examination['code'],
                name: $examination['name'],
                preparation: $examination['preparation'],
                key: sprintf('seed-radiology-master-%02d', $index + 1),
            );
        }
    }

    /**
     * @return list<array{code: string, name: string, preparation: string}>
     */
    public static function catalogue(): array
    {
        return [
            [
                'code' => 'RAD-THORAX-PA',
                'name' => 'Radiografi Thoraks PA',
                'preparation' => 'Ikuti instruksi petugas radiologi dan lepaskan benda logam pada area pemeriksaan.',
            ],
            [
                'code' => 'RAD-EXTREMITY',
                'name' => 'Radiografi Ekstremitas',
                'preparation' => 'Tidak ada persiapan khusus; ikuti instruksi petugas radiologi.',
            ],
            [
                'code' => 'USG-ABDOMEN',
                'name' => 'Ultrasonografi Abdomen',
                'preparation' => 'Konfirmasi persiapan pemeriksaan dengan unit radiologi sesuai kebutuhan klinis.',
            ],
        ];
    }
}
