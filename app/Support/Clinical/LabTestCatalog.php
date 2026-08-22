<?php

namespace App\Support\Clinical;

final class LabTestCatalog
{
    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_column(self::all(), 'code');
    }

    /**
     * @return array{code: string, label: string}|null
     */
    public static function find(string $code): ?array
    {
        foreach (self::all() as $test) {
            if ($test['code'] === $code) {
                return $test;
            }
        }

        return null;
    }

    /**
     * @return list<array{code: string, label: string}>
     */
    public static function all(): array
    {
        return [
            ['code' => 'HB', 'label' => 'Hemoglobin'],
            ['code' => 'GDS', 'label' => 'Gula Darah Sewaktu'],
            ['code' => 'UR', 'label' => 'Urinalisis'],
            ['code' => 'LFT', 'label' => 'Fungsi Hati (SGOT/SGPT)'],
            ['code' => 'RFT', 'label' => 'Fungsi Ginjal (Ureum/Kreatinin)'],
        ];
    }
}
