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
     * Dedicated operational routes for Phase 3 outpatient slice.
     *
     * @var array<string, string>
     */
    public const DEDICATED_HREFS = [
        'pendaftaran' => '/modul/pendaftaran',
        'pemeriksaan' => '/modul/pemeriksaan',
        'rm' => '/modul/rm',
        'klaim' => '/modul/klaim',
        'laporan' => '/modul/laporan',
        'bpjs' => '/modul/bpjs',
        'apotek' => '/modul/apotek',
        'gf' => '/modul/gf',
        'kasir' => '/modul/kasir',
        'manajemen-data' => '/modul/manajemen-data',
        'iot' => '/modul/iot',
        'farmasi-ibs' => '/modul/farmasi-ibs',
        'help' => '/modul/help',
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
        return isset(self::CATEGORIES[$slug]) ? ScreenVocabulary::label(self::CATEGORIES[$slug]) : null;
    }

    public static function href(string $slug): string
    {
        return self::DEDICATED_HREFS[$slug] ?? '/modul/'.$slug;
    }
}
