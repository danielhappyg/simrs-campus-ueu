<?php

namespace App\Support;

use App\Models\Encounter;
use App\Models\Patient;

/**
 * Canonical teaching codes and Indonesian labels for registration metadata.
 * Desk, cetak, and rekap must use these maps so stored codes stay stable.
 */
final class TeachingVocabulary
{
    /**
     * @var array<string, string>
     */
    public const SEX = [
        Patient::SEX_LAKI_LAKI => 'Laki-laki',
        Patient::SEX_PEREMPUAN => 'Perempuan',
        Patient::SEX_TIDAK_DIKETAHUI => 'Tidak diketahui',
    ];

    /**
     * @var array<string, string>
     */
    public const MARITAL = [
        Patient::MARITAL_BELUM_KAWIN => 'Belum kawin',
        Patient::MARITAL_KAWIN => 'Kawin',
        Patient::MARITAL_CERAI_HIDUP => 'Cerai hidup',
        Patient::MARITAL_CERAI_MATI => 'Cerai mati',
    ];

    /**
     * @var array<string, string>
     */
    public const RELIGION = [
        'ISLAM' => 'Islam',
        'KRISTEN' => 'Kristen',
        'KATOLIK' => 'Katolik',
        'HINDU' => 'Hindu',
        'BUDDHA' => 'Buddha',
        'KONGHUCU' => 'Konghucu',
        'LAINNYA' => 'Lainnya',
    ];

    /**
     * @var array<string, string>
     */
    public const EDUCATION = [
        'TIDAK_SEKOLAH' => 'Tidak sekolah',
        'SD' => 'SD',
        'SMP' => 'SMP',
        'SMA' => 'SMA',
        'D3' => 'D3',
        'S1' => 'S1',
        'S2' => 'S2',
        'S3' => 'S3',
        'LAINNYA' => 'Lainnya',
    ];

    /**
     * @var array<string, string>
     */
    public const OCCUPATION = [
        'PELAJAR' => 'Pelajar',
        'MAHASISWA' => 'Mahasiswa',
        'PNS' => 'PNS',
        'SWASTA' => 'Karyawan swasta',
        'WIRASWASTA' => 'Wiraswasta',
        'IRT' => 'Ibu rumah tangga',
        'PENSIUNAN' => 'Pensiunan',
        'LAINNYA' => 'Lainnya',
    ];

    /**
     * @var array<string, string>
     */
    public const ETHNICITY = [
        'JAWA' => 'Jawa',
        'SUNDA' => 'Sunda',
        'BETAWI' => 'Betawi',
        'BATAK' => 'Batak',
        'MINANG' => 'Minang',
        'BUGIS' => 'Bugis',
        'LAINNYA' => 'Lainnya',
    ];

    /**
     * @var array<string, string>
     */
    public const LANGUAGE = [
        'INDONESIA' => 'Indonesia',
        'JAWA' => 'Jawa',
        'SUNDA' => 'Sunda',
        'INGGRIS' => 'Inggris',
        'LAINNYA' => 'Lainnya',
    ];

    /**
     * @var array<string, string>
     */
    public const PAYER = [
        Encounter::PAYER_UMUM => 'Umum',
        Encounter::PAYER_BPJS => 'BPJS',
        Encounter::PAYER_LAINNYA => 'Lainnya',
    ];

    /**
     * @var array<string, string>
     */
    public const ADMISSION = [
        Encounter::ADMISSION_DATANG_SENDIRI => 'Datang sendiri',
        Encounter::ADMISSION_RUJUKAN => 'Rujukan',
        Encounter::ADMISSION_IGD => 'Dari IGD',
    ];

    /**
     * @var array<string, string>
     */
    public const CASE_TYPE = [
        Encounter::CASE_NON_BEDAH => 'Non-bedah',
        Encounter::CASE_BEDAH => 'Bedah',
    ];

    /**
     * @var array<string, string>
     */
    public const ACCIDENT = [
        Encounter::ACCIDENT_NONE => 'Bukan kecelakaan',
        Encounter::ACCIDENT_YES => 'Kecelakaan',
    ];

    /**
     * @var array<string, string>
     */
    public const CONTINUE_FROM = [
        Encounter::CONTINUE_LANGSUNG => 'Langsung',
        Encounter::CONTINUE_DARI_IGD => 'Dari IGD',
        Encounter::CONTINUE_DARI_RJ => 'Dari Rawat Jalan',
    ];

    /**
     * @var array<string, string>
     */
    public const CARE_SETTING = [
        Encounter::CARE_SETTING_OUTPATIENT => 'Rawat jalan',
        Encounter::CARE_SETTING_EMERGENCY => 'IGD',
        Encounter::CARE_SETTING_INPATIENT => 'Rawat inap',
    ];

    /**
     * @var array<string, string>
     */
    public const ORIGIN = [
        'ONLINE' => 'Online / booking',
        'WALK_IN' => 'Walk-in',
    ];

    /**
     * @param  array<string, string>  $map
     * @return list<array{value: string, label: string}>
     */
    public static function options(array $map): array
    {
        $options = [];

        foreach ($map as $value => $label) {
            $options[] = ['value' => $value, 'label' => $label];
        }

        return $options;
    }

    /**
     * @param  array<string, string>  $map
     */
    public static function label(array $map, ?string $code, string $empty = '—'): string
    {
        if ($code === null || $code === '') {
            return $empty;
        }

        return $map[$code] ?? $code;
    }

    /**
     * @return array<string, string>
     */
    public static function printLabels(Encounter $encounter): array
    {
        $patient = $encounter->patient;
        $wilayahParts = array_values(array_filter([
            $patient?->province,
            $patient?->city,
            $patient?->district,
            $patient?->village,
        ], fn (?string $part): bool => filled($part)));
        $codeParts = array_values(array_filter([
            $patient?->province_code,
            $patient?->city_code,
            $patient?->district_code,
            $patient?->village_code,
        ], fn (?string $part): bool => filled($part)));

        $origin = filled($encounter->booking_code) ? 'ONLINE' : 'WALK_IN';

        return [
            'sex' => self::label(self::SEX, $patient?->sex),
            'marital' => self::label(self::MARITAL, $patient?->marital_status),
            'religion' => self::label(self::RELIGION, $patient?->religion),
            'education' => self::label(self::EDUCATION, $patient?->education),
            'occupation' => self::label(self::OCCUPATION, $patient?->occupation),
            'ethnicity' => self::label(self::ETHNICITY, $patient?->ethnicity),
            'language' => self::label(self::LANGUAGE, $patient?->language),
            'payer' => self::label(self::PAYER, $encounter->payer_type),
            'admission' => self::label(self::ADMISSION, $encounter->admission_mode),
            'care_setting' => self::label(self::CARE_SETTING, $encounter->care_setting),
            'case_type' => self::label(self::CASE_TYPE, $encounter->case_type),
            'accident' => self::label(self::ACCIDENT, $encounter->accident_type),
            'origin' => self::label(self::ORIGIN, $origin),
            'wilayah' => $wilayahParts === [] ? '—' : implode(' · ', $wilayahParts),
            'wilayah_codes' => $codeParts === [] ? '—' : implode(' / ', $codeParts),
        ];
    }
}
