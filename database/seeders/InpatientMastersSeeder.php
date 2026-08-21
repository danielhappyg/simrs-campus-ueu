<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class InpatientMastersSeeder extends Seeder
{
    public function run(): void
    {
        // Teaching bangsal catalogue is passed as nested JSON props; no DB tables required.
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
