<?php

namespace App\Support;

final class SimrsModuleCategories
{
    /**
     * Vendor-inspired top-level module categories (slug => Indonesian label).
     *
     * @var array<string, string>
     */
    public const CATEGORIES = [
        'pendaftaran' => 'Pendaftaran',
        'pemeriksaan' => 'Pemeriksaan',
        'rm' => 'RM',
        'klaim' => 'Klaim',
        'laporan' => 'Laporan',
        'bpjs' => 'BPJS',
        'apotek' => 'Apotek',
        'gf' => 'GF',
        'kasir' => 'Kasir',
        'manajemen-data' => 'Manajemen Data',
        'iot' => 'IoT',
        'farmasi-ibs' => 'Farmasi IBS',
        'help' => 'Help',
    ];

    /**
     * @return list<string>
     */
    public static function slugs(): array
    {
        return array_keys(self::CATEGORIES);
    }

    public static function isValid(string $slug): bool
    {
        return array_key_exists($slug, self::CATEGORIES);
    }

    public static function label(string $slug): ?string
    {
        return self::CATEGORIES[$slug] ?? null;
    }
}
