<?php

namespace App\Services\Wilayah;

use App\Models\WilayahDistrict;
use App\Models\WilayahProvince;
use App\Models\WilayahRegency;
use App\Models\WilayahVillage;
use Illuminate\Support\Facades\File;

class WilayahRepository
{
    /**
     * @return list<array{value: string, label: string}>
     */
    public function provinces(): array
    {
        return WilayahProvince::query()
            ->orderBy('name')
            ->get(['code', 'name'])
            ->map(fn (WilayahProvince $row): array => [
                'value' => $row->code,
                'label' => $row->name,
            ])
            ->all();
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function regencies(string $provinceCode): array
    {
        return WilayahRegency::query()
            ->where('province_code', $provinceCode)
            ->orderBy('name')
            ->get(['code', 'name'])
            ->map(fn (WilayahRegency $row): array => [
                'value' => $row->code,
                'label' => $row->name,
            ])
            ->all();
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function districts(string $regencyCode): array
    {
        return WilayahDistrict::query()
            ->where('regency_code', $regencyCode)
            ->orderBy('name')
            ->get(['code', 'name'])
            ->map(fn (WilayahDistrict $row): array => [
                'value' => $row->code,
                'label' => $row->name,
            ])
            ->all();
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function villages(string $districtCode): array
    {
        return WilayahVillage::query()
            ->where('district_code', $districtCode)
            ->orderBy('name')
            ->get(['code', 'name'])
            ->map(fn (WilayahVillage $row): array => [
                'value' => $row->code,
                'label' => $row->name,
            ])
            ->all();
    }

    public function provinceName(?string $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }

        return WilayahProvince::query()->where('code', $code)->value('name');
    }

    public function regencyName(?string $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }

        return WilayahRegency::query()->where('code', $code)->value('name');
    }

    public function districtName(?string $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }

        return WilayahDistrict::query()->where('code', $code)->value('name');
    }

    public function villageName(?string $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }

        return WilayahVillage::query()->where('code', $code)->value('name');
    }

    /**
     * Resolve BPS codes to display names for patient persistence.
     *
     * @param  array{
     *     province_code?: string|null,
     *     city_code?: string|null,
     *     district_code?: string|null,
     *     village_code?: string|null,
     * }  $codes
     * @return array{
     *     province_code: string|null,
     *     city_code: string|null,
     *     district_code: string|null,
     *     village_code: string|null,
     *     province: string|null,
     *     city: string|null,
     *     district: string|null,
     *     village: string|null,
     * }
     */
    public function resolvePatientWilayah(array $codes): array
    {
        $provinceCode = $codes['province_code'] ?? null;
        $cityCode = $codes['city_code'] ?? null;
        $districtCode = $codes['district_code'] ?? null;
        $villageCode = $codes['village_code'] ?? null;

        return [
            'province_code' => $provinceCode ?: null,
            'city_code' => $cityCode ?: null,
            'district_code' => $districtCode ?: null,
            'village_code' => $villageCode ?: null,
            'province' => $this->provinceName($provinceCode),
            'city' => $this->regencyName($cityCode),
            'district' => $this->districtName($districtCode),
            'village' => $this->villageName($villageCode),
        ];
    }

    public function dataPath(): string
    {
        return resource_path('data/wilayah/ibnux');
    }

    public function hasLocalData(): bool
    {
        return File::isFile($this->dataPath().'/provinsi.json');
    }
}
