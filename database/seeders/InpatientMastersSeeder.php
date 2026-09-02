<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\Inpatient\InpatientMasterService;
use Illuminate\Database\Seeder;
use RuntimeException;

class InpatientMastersSeeder extends Seeder
{
    public function run(): void
    {
        if (config('simulation.mode') !== 'SIMULATION' || config('simulation.synthetic_only') !== true) {
            throw new RuntimeException('Managed inpatient masters require the synthetic simulation boundary.');
        }

        $actor = User::query()->where('email', config('simulation.rebuild_admin_email'))->first();
        if (! $actor instanceof User || ! $actor->is_system_administrator) {
            throw new RuntimeException('Managed inpatient master seeding requires the attributable rebuild administrator.');
        }

        $service = app(InpatientMasterService::class);
        foreach (self::wardsCatalogue() as $wardIndex => $blueprint) {
            $ward = $service->createWard(
                actor: $actor,
                code: 'RI-'.mb_strtoupper($blueprint['name']),
                displayName: $blueprint['name'],
                reasonCode: InpatientMasterService::REASON_INITIAL_SETUP,
                key: sprintf('seed-inpatient-ward-%02d', $wardIndex + 1),
                correlation: null,
            )->master;

            foreach ($blueprint['beds'] as $bedIndex => $bedCode) {
                $service->createBed(
                    actor: $actor,
                    wardPublicId: $ward->public_id,
                    code: $bedCode,
                    displayName: 'Tempat Tidur '.$bedCode,
                    roomLabel: 'Ruang '.$blueprint['name'],
                    serviceClass: $blueprint['class'],
                    reasonCode: InpatientMasterService::REASON_INITIAL_SETUP,
                    key: sprintf('seed-inpatient-bed-%02d-%02d', $wardIndex + 1, $bedIndex + 1),
                    correlation: null,
                );
            }
        }
    }

    /**
     * @return list<array{name: string, class: string, beds: list<string>}>
     */
    public static function wardsCatalogue(): array
    {
        return [
            [
                'name' => 'Melati',
                'class' => 'Kelas 1',
                'beds' => ['A-01', 'A-02'],
            ],
            [
                'name' => 'Mawar',
                'class' => 'Kelas 2',
                'beds' => ['B-01', 'B-02'],
            ],
            [
                'name' => 'Anggrek',
                'class' => 'Kelas 3',
                'beds' => ['C-01', 'C-02'],
            ],
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function wardFilterOptions(): array
    {
        return array_map(
            fn (array $ward): array => [
                'value' => $ward['name'],
                'label' => $ward['name'].' · '.$ward['class'],
            ],
            self::wardsCatalogue(),
        );
    }
}
