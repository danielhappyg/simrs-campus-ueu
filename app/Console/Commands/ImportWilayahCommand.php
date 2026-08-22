<?php

namespace App\Console\Commands;

use App\Models\WilayahDistrict;
use App\Models\WilayahProvince;
use App\Models\WilayahRegency;
use App\Models\WilayahVillage;
use App\Services\Wilayah\WilayahRepository;
use App\Support\Database\SchemaQualifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

class ImportWilayahCommand extends Command
{
    protected $signature = 'wilayah:import
        {--path= : Override path to ibnux JSON root (contains provinsi.json)}
        {--fresh : Truncate wilayah tables before import}';

    protected $description = 'Import ibnux/data-indonesia JSON into wilayah_* tables';

    public function handle(WilayahRepository $repository): int
    {
        $path = $this->option('path') ?: $repository->dataPath();

        if (! File::isFile($path.'/provinsi.json')) {
            $this->error("Missing {$path}/provinsi.json — run scripts/fetch-ibnux-wilayah.sh first.");

            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            $this->warn('Truncating wilayah tables…');
            WilayahVillage::query()->delete();
            WilayahDistrict::query()->delete();
            WilayahRegency::query()->delete();
            WilayahProvince::query()->delete();
        }

        $provinces = $this->readJsonList($path.'/provinsi.json');
        $this->info('Provinces in source: '.count($provinces));

        $now = now()->toDateTimeString();
        $provinceRows = [];
        $regencyRows = [];
        $districtRows = [];
        $villageRows = [];

        foreach ($provinces as $province) {
            $provinceCode = (string) ($province['id'] ?? '');
            $provinceName = trim((string) ($province['nama'] ?? ''));
            if ($provinceCode === '' || $provinceName === '') {
                continue;
            }

            $provinceRows[] = [
                'code' => $provinceCode,
                'name' => $provinceName,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $regencyFile = $path.'/kabupaten/'.$provinceCode.'.json';
            if (! File::isFile($regencyFile)) {
                continue;
            }

            foreach ($this->readJsonList($regencyFile) as $regency) {
                $regencyCode = (string) ($regency['id'] ?? '');
                $regencyName = trim((string) ($regency['nama'] ?? ''));
                if ($regencyCode === '' || $regencyName === '') {
                    continue;
                }

                $regencyRows[] = [
                    'code' => $regencyCode,
                    'province_code' => $provinceCode,
                    'name' => $regencyName,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                $districtFile = $path.'/kecamatan/'.$regencyCode.'.json';
                if (! File::isFile($districtFile)) {
                    continue;
                }

                foreach ($this->readJsonList($districtFile) as $district) {
                    $districtCode = (string) ($district['id'] ?? '');
                    $districtName = trim((string) ($district['nama'] ?? ''));
                    if ($districtCode === '' || $districtName === '') {
                        continue;
                    }

                    $districtRows[] = [
                        'code' => $districtCode,
                        'regency_code' => $regencyCode,
                        'name' => $districtName,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    $villageFile = $path.'/kelurahan/'.$districtCode.'.json';
                    if (! File::isFile($villageFile)) {
                        continue;
                    }

                    foreach ($this->readJsonList($villageFile) as $village) {
                        $villageCode = (string) ($village['id'] ?? '');
                        $villageName = trim((string) ($village['nama'] ?? ''));
                        if ($villageCode === '' || $villageName === '') {
                            continue;
                        }

                        $villageRows[] = [
                            'code' => $villageCode,
                            'district_code' => $districtCode,
                            'name' => $villageName,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }
            }

            $this->line("  scanned province {$provinceCode} {$provinceName}");
        }

        $this->upsertChunks('wilayah_provinces', $provinceRows, ['name', 'updated_at']);
        $this->upsertChunks('wilayah_regencies', $regencyRows, ['province_code', 'name', 'updated_at']);
        $this->upsertChunks('wilayah_districts', $districtRows, ['regency_code', 'name', 'updated_at']);
        $this->upsertChunks('wilayah_villages', $villageRows, ['district_code', 'name', 'updated_at']);

        $this->info('DB counts: provinces='.WilayahProvince::query()->count()
            .' regencies='.WilayahRegency::query()->count()
            .' districts='.WilayahDistrict::query()->count()
            .' villages='.WilayahVillage::query()->count());

        return self::SUCCESS;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $update
     */
    private function upsertChunks(string $table, array $rows, array $update): void
    {
        $this->line("Upserting {$table}: ".count($rows).' rows');

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table(SchemaQualifier::table($table))->upsert($chunk, ['code'], $update);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readJsonList(string $file): array
    {
        try {
            $raw = File::get($file);
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            $this->warn("Skip unreadable {$file}: {$e->getMessage()}");

            return [];
        }

        if (! is_array($decoded)) {
            return [];
        }

        /** @var list<array<string, mixed>> $decoded */
        return $decoded;
    }
}
