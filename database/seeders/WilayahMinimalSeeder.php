<?php

namespace Database\Seeders;

use App\Models\WilayahDistrict;
use App\Models\WilayahProvince;
use App\Models\WilayahRegency;
use App\Models\WilayahVillage;
use Illuminate\Database\Seeder;

/**
 * Small deterministic wilayah graph for tests and census when full ibnux import is absent.
 */
class WilayahMinimalSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $provinces = [
            ['code' => '31', 'name' => 'DKI Jakarta'],
            ['code' => '32', 'name' => 'Jawa Barat'],
            ['code' => '34', 'name' => 'DI Yogyakarta'],
            ['code' => '51', 'name' => 'Bali'],
        ];

        foreach ($provinces as $row) {
            WilayahProvince::query()->updateOrCreate(
                ['code' => $row['code']],
                ['name' => $row['name'], 'created_at' => $now, 'updated_at' => $now],
            );
        }

        $regencies = [
            ['code' => '3173', 'province_code' => '31', 'name' => 'Kota Jakarta Barat'],
            ['code' => '3174', 'province_code' => '31', 'name' => 'Kota Jakarta Selatan'],
            ['code' => '3275', 'province_code' => '32', 'name' => 'Kota Bekasi'],
            ['code' => '3276', 'province_code' => '32', 'name' => 'Kota Depok'],
            ['code' => '3471', 'province_code' => '34', 'name' => 'Kota Yogyakarta'],
            ['code' => '5171', 'province_code' => '51', 'name' => 'Kota Denpasar'],
        ];

        foreach ($regencies as $row) {
            WilayahRegency::query()->updateOrCreate(
                ['code' => $row['code']],
                [
                    'province_code' => $row['province_code'],
                    'name' => $row['name'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }

        $districts = [
            ['code' => '317301', 'regency_code' => '3173', 'name' => 'Kebon Jeruk'],
            ['code' => '317302', 'regency_code' => '3173', 'name' => 'Palmerah'],
            ['code' => '317401', 'regency_code' => '3174', 'name' => 'Kebayoran Baru'],
            ['code' => '327501', 'regency_code' => '3275', 'name' => 'Bekasi Barat'],
            ['code' => '327601', 'regency_code' => '3276', 'name' => 'Beji'],
            ['code' => '347101', 'regency_code' => '3471', 'name' => 'Gondokusuman'],
            ['code' => '517101', 'regency_code' => '5171', 'name' => 'Denpasar Selatan'],
        ];

        foreach ($districts as $row) {
            WilayahDistrict::query()->updateOrCreate(
                ['code' => $row['code']],
                [
                    'regency_code' => $row['regency_code'],
                    'name' => $row['name'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }

        $villages = [
            ['code' => '3173011001', 'district_code' => '317301', 'name' => 'Kedoya Utara'],
            ['code' => '3173011002', 'district_code' => '317301', 'name' => 'Sukabumi Utara'],
            ['code' => '3173021001', 'district_code' => '317302', 'name' => 'Slipi'],
            ['code' => '3174011001', 'district_code' => '317401', 'name' => 'Senayan'],
            ['code' => '3275011001', 'district_code' => '327501', 'name' => 'Jakasampurna'],
            ['code' => '3276011001', 'district_code' => '327601', 'name' => 'Beji'],
            ['code' => '3471011001', 'district_code' => '347101', 'name' => 'Terban'],
            ['code' => '5171011001', 'district_code' => '517101', 'name' => 'Sanur'],
        ];

        foreach ($villages as $row) {
            WilayahVillage::query()->updateOrCreate(
                ['code' => $row['code']],
                [
                    'district_code' => $row['district_code'],
                    'name' => $row['name'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }
    }
}
